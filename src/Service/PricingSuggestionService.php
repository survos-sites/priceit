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
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Vich\UploaderBundle\Storage\StorageInterface;

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
    private const SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['title', 'description', 'priceUsd', 'confidence'],
        'properties' => [
            'title' => [
                'type' => 'string',
                'maxLength' => 60,
                'description' => 'What the thing is, under 32 characters so it fits a price tag. Plain and specific: "Pyrex bowl set", not "Vintage kitchenware collection".',
            ],
            'description' => [
                'type' => 'string',
                'maxLength' => 200,
                'description' => 'One or two short sentences with a bit of charm, under 100 characters — it has to fit on a price tag. Mention condition if it is visible.',
            ],
            'priceUsd' => [
                'type' => 'number',
                // Structured outputs reject minimum/maximum on number, so the
                // range is stated here and clamped after the fact instead.
                'description' => 'Suggested garage-sale asking price in US dollars, between 0.50 and 500. Garage-sale pricing, not eBay pricing.',
            ],
            'confidence' => [
                'type' => 'string',
                'enum' => ['high', 'medium', 'low'],
                'description' => 'low when the photo is ambiguous or the item could be worth wildly different amounts.',
            ],
        ],
    ];

    private const SYSTEM = <<<'TXT'
        You are pricing items for a neighbourhood garage sale. You see photos of one item
        and sometimes a transcript of the seller talking about it.

        Price the way an experienced garage-sale host would: things sell because they are
        cheap and someone wants them today, not because a comparable sold on eBay last year.
        Most household items belong between $1 and $20. Reserve higher prices for things
        that are visibly furniture, tools, electronics that plainly work, or something
        collectible enough to be obvious from the photo.

        Say what you actually see. If the photo is too dark or too cluttered to identify
        the item, say so in the description and set confidence to low rather than inventing
        a plausible object.
        TXT;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StorageInterface $storage,
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

        $content = $images;
        $content[] = TextBlockParam::with(text: $this->prompt($item));

        try {
            $message = (new Client(apiKey: $this->apiKey))->messages->create(
                model: $this->model,
                maxTokens: 1024,
                system: self::SYSTEM,
                outputConfig: OutputConfig::with(
                    format: JSONOutputFormat::with(schema: self::SCHEMA),
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
        if (isset($data['priceUsd'])) {
            // The schema can't bound a number, so bound it here — a label with
            // four figures on it is worse than one that is merely wrong.
            $price = min(500.0, max(0.5, (float) $data['priceUsd']));
            $item->setPrice(number_format($price, 2, '.', ''));
        }
        // The marking is the workflow's business, not this service's — it is
        // called from inside the `suggest` transition, which moves the item.
        $this->em->flush();

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

            $path = $this->storage->resolvePath($media, 'file');
            if ($path === null || !is_readable($path)) {
                continue;
            }

            $mediaType = $this->mediaTypeFor($media->getMimeType() ?? '');
            if ($mediaType === null) {
                $this->logger->warning('priceit: unsupported image type', ['mime' => $media->getMimeType()]);
                continue;
            }

            $blocks[] = ImageBlockParam::with(
                source: Base64ImageSource::with(
                    data: base64_encode((string) file_get_contents($path)),
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
        $transcript = trim((string) $item->getTranscript());

        return $transcript !== ''
            ? "Here is the item. The seller said: \"{$transcript}\"\n\nTitle it, describe it, and price it for the sale."
            : 'Here is the item. Title it, describe it, and price it for the sale.';
    }
}
