<?php

declare(strict_types=1);

namespace App\Service;

use Anthropic\Client;
use Anthropic\Messages\Base64ImageSource;
use Anthropic\Messages\ImageBlockParam;
use Anthropic\Messages\JSONOutputFormat;
use Anthropic\Messages\OutputConfig;
use Anthropic\Messages\TextBlockParam;
use App\Entity\Item;
use App\Entity\MediaKind;
use App\Profile\CaptureProfile;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Looks at an item's photos and proposes a title, a description and a price.
 *
 * Structured output rather than "reply with JSON": the response is validated
 * against the schema by the API, so a garage-sale item can't come back with a
 * price of "about five bucks" and blow up the label renderer.
 */
final class PricingSuggestionService
{
    /**
     * Deliberately small. Every field ends up on a 2.25×1.25in label or in a
     * sale listing, so the schema does the truncating rather than the printer.
     */
    private const BASE_SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['title', 'description', 'category', 'equipmentType', 'confidence'],
        'properties' => [
            'title' => [
                'type' => 'string',
                'maxLength' => 80,
                'description' => 'What the thing is, under 45 characters so it fits a price tag. Plain and specific, and lead with the maker or pattern when there is one: "Pyrex Butterprint bowl set", not "Vintage kitchenware collection".',
            ],
            'description' => [
                'type' => 'string',
                'maxLength' => 240,
                'description' => 'One or two short sentences with a bit of charm, under 120 characters — it has to fit on a price tag. If the item is collectible, say what makes it so (maker, era, pattern). Mention condition if it is visible.',
            ],
            'category' => [
                'type' => 'string',
                'maxLength' => 100,
                'description' => 'Broad reusable inventory category such as Mobility Aid, Medical Equipment, Bathroom Safety, Home Modification, or Diagnostic Equipment.',
            ],
            'equipmentType' => [
                'type' => 'string',
                'maxLength' => 150,
                'description' => 'Specific reusable equipment type such as Manual Wheelchair, Folding Walker, Hospital Bed, or Shower Chair.',
            ],
            'confidence' => [
                'type' => 'string',
                'enum' => ['high', 'medium', 'low'],
                'description' => 'low when the photo is ambiguous or the item could be worth wildly different amounts.',
            ],
        ],
    ];

    /**
     * Asked for only when the profile wants a price. A loan-closet item has no asking price,
     * and prompting for one anyway invites the model to invent a number that would then be
     * printed on a label for something being lent out free of charge.
     */
    private const PRICE_PROPERTY = [
        'type' => 'number',
        // Structured outputs reject minimum/maximum on number, so the range is stated here
        // and clamped after the fact instead.
        'description' => 'Friday/Saturday asking price in US dollars, between 0.50 and 1000, as a round number a volunteer can make change for. Fair fundraiser pricing: Sunday is half off, so do not underprice.',
    ];

    /**
     * Resale asks a different question, so it gets a different field description.
     * Sharing one prompt between the two is what made the old single "auction"
     * profile undersell anything worth listing online.
     */
    private const RESALE_PRICE_PROPERTY = [
        'type' => 'number',
        'description' => 'Suggested online asking price in US dollars, between 0.50 and 1000, based on what this actually sells for on eBay or Mercado Libre — not what it would fetch on a folding table.',
    ];

    /**
     * A fundraiser, not a driveway clear-out. The first version of this prompt said "things
     * sell because they are cheap", which is right for a garage sale and wrong for a sale whose
     * point is the money raised: it priced collectibles like mugs. Sunday is half off or make an
     * offer, so an unsold item gets a second, cheaper chance and Friday's price can be fair.
     */
    private const SYSTEM_AUCTION = <<<'TXT'
        You are pricing donated items for a weekend fundraising sale run by a local
        Democratic Party committee. You see photos of one item and sometimes a transcript
        of a volunteer talking about it.

        The price you give is the Friday and Saturday asking price. On Sunday everything is
        half off or make-an-offer, so there is no need to price low to be sure something
        sells: anything left gets a second, cheaper chance. The point of the sale is to
        raise money. Price the way an experienced charity or estate-sale organizer would:
        a price a buyer recognizes as fair, not a bargain-bin price and not full retail. A
        donation plainly worth $40 should not go out the door at $5.

        Look hard for things worth more than they first appear. Before pricing, check the
        photos for maker's marks, signatures, labels, pattern names, model numbers,
        hallmarks such as sterling or 14k, edition information, and signs of age. Commonly
        missed: vintage Pyrex and Fire-King, cast iron (Griswold, Wagner), art and studio
        pottery, mid-century furniture and lighting, political and campaign memorabilia,
        vinyl records, first editions, boxed toys and games, brand-name tools, cameras,
        musical instruments, and jewelry. Price a collectible at what a knowledgeable buyer
        would pay at a good estate sale, typically 40-60% of what it sells for online, and
        name what makes it collectible in the title or description so buyers see why.

        Ordinary household goods are still ordinary; a plain mug is still a dollar or two.
        Use round prices a volunteer can make change for: whole dollars, or $0.50 under $2.

        Say what you actually see. If the photo is too dark or too cluttered to identify the
        item, or a mark is unreadable, say so in the description and set confidence to low
        rather than inventing a plausible object. When something might be valuable but you
        cannot confirm it from the photos, set confidence to low so a volunteer checks it
        before it goes on the table.
        TXT;

    private const SYSTEM_RESALE = <<<'TXT'
        You are writing listings for items going onto eBay or Mercado Libre, where buyers
        search for a specific thing and compare it against other listings of the same thing.

        Price against what the item actually sells for online. This is NOT garage-sale
        pricing: a collectible, a brand-name tool, or a piece of vintage glassware can be
        worth many times what it would fetch on a folding table, and underpricing costs the
        seller real money. If you cannot tell what something is well enough to price it
        online, say so and set confidence to low rather than guessing low.

        Write the title the way a buyer would search: maker, model, era, material, size.
        Describe condition plainly, including flaws you can see. An undisclosed chip becomes
        a return, which costs the seller more than the honest sentence would have.
        TXT;

    private const SYSTEM_MEDICAL = <<<'TXT'
        You are cataloguing medical and mobility equipment as it arrives at a Lions Club loan
        closet. The closet lends this equipment to people at no charge. You see photos of one
        item and sometimes a transcript of a volunteer describing it.

        Nothing here is for sale. Do not estimate a price, a value, or what it might be worth.

        Identify the item well enough that a volunteer can find the right one on a shelf, and
        that someone deciding whether it suits a person's needs can trust what you wrote. Give
        the manufacturer and model when they are legible, and any size, weight capacity, or
        adjustment range that is actually marked on the item.

        Report only what is visible. If a photo is too dark or cluttered to identify the item,
        or a label is unreadable, say so and set confidence to low. Someone may rely on this
        description to decide whether a piece of equipment is safe for a person to use, so an
        invented detail is worse than an absent one.
        TXT;

    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Target('media.storage')]
        private readonly FilesystemOperator $media,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(ANTHROPIC_API_KEY)%')]
        private readonly string $apiKey,
        #[Autowire('%env(PRICEIT_AI_MODEL)%')]
        private readonly string $model,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /** @return array<string, mixed> */
    private static function schemaFor(CaptureProfile $profile): array
    {
        $schema = self::BASE_SCHEMA;

        if ($profile->wantsPrice()) {
            $schema['properties']['priceUsd'] = CaptureProfile::Resale === $profile
                ? self::RESALE_PRICE_PROPERTY
                : self::PRICE_PROPERTY;
            $schema['required'][] = 'priceUsd';
        }

        return $schema;
    }

    private static function systemFor(CaptureProfile $profile): string
    {
        return match ($profile) {
            CaptureProfile::GarageSale => self::SYSTEM_AUCTION,
            CaptureProfile::Resale => self::SYSTEM_RESALE,
            CaptureProfile::MedicalEquipment => self::SYSTEM_MEDICAL,
        };
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function suggest(Item $item): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'ANTHROPIC_API_KEY is not set.'];
        }

        $images = $this->imageBlocks($item);
        if ($images === []) {
            return ['ok' => false, 'message' => 'This item has no readable photo to look at.'];
        }

        $profile = $item->getProfile();

        $content = $images;
        $content[] = TextBlockParam::with(text: $this->prompt($item));

        try {
            // Opus 5 thinks by default, and thinking tokens count against maxTokens. At the
            // old 1024 the model had almost no room to look for a maker's mark before
            // answering. Spotting that a bowl is Fire-King is exactly the step that needs it,
            // and the call runs on the worker, so the extra seconds cost nobody at the table.
            $message = (new Client(apiKey: $this->apiKey))->messages->create(
                model: $this->model,
                maxTokens: 16000,
                system: self::systemFor($profile),
                outputConfig: OutputConfig::with(
                    effort: 'xhigh',
                    format: JSONOutputFormat::with(schema: self::schemaFor($profile)),
                ),
                messages: [['role' => 'user', 'content' => $content]],
            );
        } catch (\Throwable $e) {
            $this->logger->error('priceit: suggestion failed', ['item' => $item->getId(), 'error' => $e->getMessage()]);

            return ['ok' => false, 'message' => 'AI call failed: '.$e->getMessage()];
        }

        // A refusal is a 200 with no usable content — check before reading it.
        if ($message->stopReason === 'refusal') {
            return ['ok' => false, 'message' => 'The model declined to describe this photo.'];
        }

        $json = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $json .= $block->text;
            }
        }

        try {
            $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ['ok' => false, 'message' => 'Could not read the model response: '.$e->getMessage()];
        }

        $item->setTitle($data['title'] ?? null);
        $item->setDescription($data['description'] ?? null);
        $item->setCategory($data['category'] ?? null);
        $item->setEquipmentType($data['equipmentType'] ?? null);
        if ($profile->wantsPrice() && isset($data['priceUsd'])) {
            // The schema can't bound a number, so bound it here. The ceiling was 500 and
            // quietly turned an $800 collectible into a $500 one; a fundraiser would rather
            // a volunteer see the real number and argue with it.
            $price = min(1000.0, max(0.5, (float) $data['priceUsd']));
            $item->setPrice(number_format($price, 2, '.', ''));
        }
        // The marking is the workflow's business, not this service's — it is
        // called from inside the `suggest` transition, which moves the item.
        $this->em->flush();

        if (!$profile->wantsPrice()) {
            return [
                'ok' => true,
                'message' => sprintf(
                    '%s (%s confidence)',
                    $data['title'] ?? '?',
                    $data['confidence'] ?? '?',
                ),
            ];
        }

        return [
            'ok' => true,
            'message' => sprintf(
                '%s — $%s (%s confidence)',
                $data['title'] ?? '?',
                $item->getPrice() ?? '?',
                $data['confidence'] ?? 'unknown'
            ),
        ];
    }

    /**
     * @return ImageBlockParam[]
     */
    private function imageBlocks(Item $item): array
    {
        $blocks = [];

        foreach ($item->getMedia() as $media) {
            if ($media->getKind() !== MediaKind::Photo) {
                continue;
            }

            $mediaType = $this->mediaTypeFor($media->getMimeType() ?? '');
            if ($mediaType === null) {
                $this->logger->warning('priceit: unsupported image type', ['mime' => $media->getMimeType()]);
                continue;
            }

            // Read through flysystem rather than the filesystem: in production
            // the photo lives in object storage and has no local path at all.
            $filename = $media->getFilename();
            if ($filename === null) {
                continue;
            }

            try {
                $bytes = $this->media->read($filename);
            } catch (\Throwable $e) {
                $this->logger->warning('priceit: could not read photo', ['file' => $filename, 'error' => $e->getMessage()]);
                continue;
            }

            $blocks[] = ImageBlockParam::with(
                source: Base64ImageSource::with(
                    data: base64_encode($bytes),
                    mediaType: $mediaType,
                ),
            );

            // Three angles is plenty to identify a thing, and each photo is a
            // meaningful slice of the request budget.
            if (\count($blocks) >= 3) {
                break;
            }
        }

        return $blocks;
    }

    private function mediaTypeFor(string $mime): ?string
    {
        return match ($mime) {
            'image/jpeg', 'image/jpg' => 'image/jpeg',
            'image/png' => 'image/png',
            'image/gif' => 'image/gif',
            'image/webp' => 'image/webp',
            default => null,
        };
    }

    private function prompt(Item $item): string
    {
        $profile = $item->getProfile();
        $speaker = $profile->wantsPrice() ? 'seller' : 'volunteer';

        $prompt = $profile->promptGuidance();

        $transcript = trim((string) $item->getTranscript());
        if ('' !== $transcript) {
            $prompt .= sprintf("\n\nThe %s said: \"%s\"", $speaker, $transcript);
        }

        return $prompt;
    }
}
