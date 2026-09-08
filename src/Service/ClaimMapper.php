<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Turns ssai's claims into the fields a marketplace listing needs.
 *
 * Deliberately no AI here. ssai has already looked at the image, read the OCR and
 * recorded what it found as claims with a confidence and a basis — "Printed
 * caption at top", "Address appears to…". A second model call in priceit would
 * cost money to produce a worse answer, because it would see the photo without any
 * of that.
 *
 * Claims attach to IMAGES, not items, so an item's claims are the union of its
 * images'. For a postcard that matters: the front carries the place and the
 * scene, the back carries the postmark and the message.
 */
final readonly class ClaimMapper
{
    /**
     * US states, so a free-text place can be split into country/state/city.
     *
     * @var array<string, string>
     */
    private const US_STATES = [
        'alabama' => 'AL', 'alaska' => 'AK', 'arizona' => 'AZ', 'arkansas' => 'AR',
        'california' => 'CA', 'colorado' => 'CO', 'connecticut' => 'CT', 'delaware' => 'DE',
        'florida' => 'FL', 'georgia' => 'GA', 'hawaii' => 'HI', 'idaho' => 'ID',
        'illinois' => 'IL', 'indiana' => 'IN', 'iowa' => 'IA', 'kansas' => 'KS',
        'kentucky' => 'KY', 'louisiana' => 'LA', 'maine' => 'ME', 'maryland' => 'MD',
        'massachusetts' => 'MA', 'michigan' => 'MI', 'minnesota' => 'MN', 'mississippi' => 'MS',
        'missouri' => 'MO', 'montana' => 'MT', 'nebraska' => 'NE', 'nevada' => 'NV',
        'new hampshire' => 'NH', 'new jersey' => 'NJ', 'new mexico' => 'NM', 'new york' => 'NY',
        'north carolina' => 'NC', 'north dakota' => 'ND', 'ohio' => 'OH', 'oklahoma' => 'OK',
        'oregon' => 'OR', 'pennsylvania' => 'PA', 'rhode island' => 'RI', 'south carolina' => 'SC',
        'south dakota' => 'SD', 'tennessee' => 'TN', 'texas' => 'TX', 'utah' => 'UT',
        'vermont' => 'VT', 'virginia' => 'VA', 'washington' => 'WA', 'west virginia' => 'WV',
        'wisconsin' => 'WI', 'wyoming' => 'WY', 'district of columbia' => 'DC',
    ];

    /**
     * Ways a place says "Washington DC" without saying "District of Columbia".
     *
     * Checked BEFORE the state table, because "Washington, D.C." otherwise walks
     * right, fails to recognise "D.C.", and matches "Washington" as the state —
     * putting a DC postcard in front of Seattle buyers. Real value from
     * marac-0005.
     *
     * @var list<string>
     */
    private const DC_ALIASES = ['d.c.', 'dc', 'd c', 'washington d.c.', 'washington dc'];

    /**
     * Collapse an item's claims into listing fields.
     *
     * @param list<array<string, mixed>> $claims
     *
     * @return array{
     *     title: ?string, description: ?string, date: ?string, whenMade: ?string,
     *     tags: list<string>, place: ?string, landmark: ?string, city: ?string, state: ?string,
     *     stateCode: ?string, country: ?string, ocr: ?string, type: ?string,
     *     estimatedValue: ?string, valueConfidence: ?string,
     *     values: array<string, array{low: string, high: string, currency: string, confidence: float, basis: ?string}>
     * }
     */
    /**
     * Map an item's own metadata, which is the preferred source.
     *
     * Same shape as map(), so a caller does not care which produced it.
     *
     * @param array<string, mixed> $metadata Item.metadata from ssai
     *
     * @return array<string, mixed>
     */
    public function mapMetadata(array $metadata): array
    {
        // metadata holds bare values under the same predicate names, so reuse the
        // claim path by wrapping each one. dcterms:spatial is a LIST here — the
        // depicted place first, then anywhere else the AI recognised, typically the
        // addressee's town. For a listing the first is what is being sold; Betty
        // Rice's Indianapolis is not a tag anyone shops by.
        $claims = [];
        foreach ($metadata as $predicate => $value) {
            foreach (is_array($value) && array_is_list($value) ? $value : [$value] as $single) {
                if (is_scalar($single) || is_array($single)) {
                    $claims[] = ['predicate' => $predicate, 'value' => $single, 'confidence' => '1'];
                }
            }
        }

        $mapped = $this->map($claims);

        // ssai names these differently from the image-level claims.
        $mapped['ocr'] ??= is_string($metadata['ssai:primaryImageText'] ?? null)
            ? $metadata['ssai:primaryImageText']
            : null;
        $mapped['synthesisNotes'] = is_string($metadata['ssai:itemSynthesisNotes'] ?? null)
            ? $metadata['ssai:itemSynthesisNotes']
            : null;
        $mapped['people'] = array_values(array_filter(
            (array) ($metadata['foaf:Person'] ?? []),
            static fn (mixed $p): bool => is_string($p) && '' !== $p,
        ));

        return $mapped;
    }

    public function map(array $claims): array
    {
        $byPredicate = [];
        foreach ($claims as $claim) {
            $predicate = (string) ($claim['predicate'] ?? '');
            if ('' === $predicate) {
                continue;
            }
            $byPredicate[$predicate][] = $claim;
        }

        $place = $this->placeFrom($byPredicate['dcterms:spatial'] ?? []);
        $date = $this->firstValue($byPredicate['dcterms:date'] ?? []);

        return [
            'title' => $this->firstValue($byPredicate['dcterms:title'] ?? []),
            // abstract is a fuller sentence than description where both exist.
            'description' => $this->firstValue($byPredicate['dcterms:abstract'] ?? [])
                ?? $this->firstValue($byPredicate['dcterms:description'] ?? []),
            'date' => $date,
            'whenMade' => $this->etsyWhenMade($date),
            'tags' => $this->tagsFrom($byPredicate, $place),
            'place' => $place['place'],
            'landmark' => $place['landmark'],
            'city' => $place['city'],
            'state' => $place['state'],
            'stateCode' => $place['stateCode'],
            'country' => $place['country'],
            'ocr' => $this->longestValue($byPredicate['ai:ocrText'] ?? []),
            'type' => $this->firstValue($byPredicate['dcterms:type'] ?? []),
            // Not produced by ssai yet. Read anyway, so that when the pipeline
            // starts emitting it there is nothing to change here.
            'estimatedValue' => $this->firstValue($byPredicate['schema:price'] ?? []),
            'valueConfidence' => $this->confidenceOf($byPredicate['schema:price'] ?? []),
            'values' => $this->valuesFrom($byPredicate['schema:value'] ?? []),
            'synthesisNotes' => null,
            'people' => [],
        ];
    }

    /**
     * Split a free-text place into city / state / country.
     *
     * The values are consistently place-shaped and parse from the RIGHT, because
     * the largest unit is last: "Parrot Jungle, Miami, Florida". A US state name
     * anywhere in the string settles the country, which is the tag that matters
     * most for browsing — postcards are shopped by state on eBay.
     *
     * "Yellowstone National Park" yields no state, and that is the honest answer
     * rather than guessing Wyoming.
     *
     * @param list<array<string, mixed>> $claims
     *
     * @return array{place: ?string, landmark: ?string, city: ?string, state: ?string, stateCode: ?string, country: ?string}
     */
    private function placeFrom(array $claims): array
    {
        $raw = null;
        foreach ($claims as $claim) {
            $value = $claim['value'] ?? null;
            // spatial values arrive as {name, basis}, not a bare string.
            $name = is_array($value) ? ($value['name'] ?? null) : $value;
            if (is_string($name) && '' !== trim($name)) {
                $raw = trim($name);

                break;
            }
        }

        if (null === $raw) {
            return ['place' => null, 'landmark' => null, 'city' => null, 'state' => null, 'stateCode' => null, 'country' => null];
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $p): bool => '' !== $p));
        $state = $stateCode = $city = $landmark = null;

        // Walk from the right: the largest unit is last, so the last recognisable
        // state wins. The part immediately BEFORE it is the city — "Parrot Jungle,
        // Miami, Florida" is an attraction in Miami, not a city called Parrot
        // Jungle, and taking the first part would have got that backwards.
        // DC first: it is spelled in ways the state table does not hold, and one of
        // those spellings collides with a real state.
        foreach ($parts as $index => $part) {
            $key = mb_strtolower(trim($part));

            if (in_array($key, self::DC_ALIASES, true)) {
                return [
                    'place' => $raw,
                    'landmark' => $index >= 1 ? $parts[0] : null,
                    'city' => 'Washington',
                    'state' => 'District of Columbia',
                    'stateCode' => 'DC',
                    'country' => 'United States',
                ];
            }
        }

        foreach (array_reverse($parts, true) as $index => $part) {
            $key = mb_strtolower($part);

            if (isset(self::US_STATES[$key])) {
                $state = $part;
                $stateCode = self::US_STATES[$key];
                $city = $parts[$index - 1] ?? null;
                $landmark = $index >= 2 ? $parts[0] : null;

                break;
            }

            // "West Yellowstone Montana" — no comma, state is the tail.
            foreach (self::US_STATES as $name => $code) {
                if (str_ends_with($key, ' ' . $name)) {
                    $state = ucwords($name);
                    $stateCode = $code;
                    $city = trim(mb_substr($part, 0, -\strlen($name) - 1));

                    break 2;
                }
            }
        }

        // No state recognised: the whole thing is a place name and nothing more
        // can honestly be said about it.
        if (null === $state && [] !== $parts) {
            $city = null;
        }

        return [
            'place' => $raw,
            'landmark' => $landmark,
            'city' => $city,
            'state' => $state,
            'stateCode' => $stateCode,
            'country' => null !== $state ? 'United States' : null,
        ];
    }

    /**
     * Tags for the listing, most specific first.
     *
     * Place beats subject: someone shopping postcards searches "Miami Florida"
     * far more often than "scenic". Etsy caps tags at 13 and 20 characters each,
     * so the cheap generic ones are the ones that get cut.
     *
     * @param array<string, list<array<string, mixed>>>                                  $byPredicate
     * @param array{place: ?string, landmark: ?string, city: ?string, state: ?string, stateCode: ?string, country: ?string} $place
     *
     * @return list<string>
     */
    private function tagsFrom(array $byPredicate, array $place): array
    {
        $tags = [];

        foreach ([$place['landmark'], $place['city'], $place['state'], $place['country']] as $candidate) {
            if (is_string($candidate) && '' !== $candidate) {
                $tags[] = $candidate;
            }
        }

        foreach ($byPredicate['dcterms:subject'] ?? [] as $claim) {
            $value = $claim['value'] ?? null;
            $name = is_array($value) ? ($value['name'] ?? null) : $value;
            if (is_string($name) && '' !== trim($name)) {
                $tags[] = trim($name);
            }
        }

        $seen = [];
        $unique = [];
        foreach ($tags as $tag) {
            $key = mb_strtolower($tag);
            if (isset($seen[$key]) || mb_strlen($tag) > 20) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $tag;
        }

        return \array_slice($unique, 0, 13);
    }

    /**
     * "ca. 1908" → Etsy's when_made bucket.
     *
     * Better than the before_2007 default we currently send, which is true of
     * everything old and useful to nobody browsing vintage.
     */
    public function etsyWhenMade(?string $date): ?string
    {
        if (null === $date || !preg_match('/(1[6-9]\d{2}|20[0-2]\d)/', $date, $m)) {
            return null;
        }

        $year = (int) $m[1];

        return match (true) {
            $year < 1700 => 'before_1700',
            $year < 1800 => '1700s',
            $year < 1900 => '1800s',
            $year < 1910 => '1900s',
            $year < 1920 => '1910s',
            $year < 1930 => '1920s',
            $year < 1940 => '1930s',
            $year < 1950 => '1940s',
            $year < 1960 => '1950s',
            $year < 1970 => '1960s',
            $year < 1980 => '1970s',
            $year < 1990 => '1980s',
            $year < 2000 => '1990s',
            $year < 2007 => '2000_2006',
            default => 'before_2007',
        };
    }

    /** @param list<array<string, mixed>> $claims */
    private function firstValue(array $claims): ?string
    {
        foreach ($claims as $claim) {
            $value = $claim['value'] ?? null;
            $name = is_array($value) ? ($value['name'] ?? null) : $value;
            if (is_string($name) && '' !== trim($name)) {
                return trim($name);
            }
        }

        return null;
    }

    /**
     * The longest of several values.
     *
     * OCR is claimed per image, and a postcard's back carries far more text than
     * its front — taking the first would usually take the emptier one.
     *
     * @param list<array<string, mixed>> $claims
     */
    private function longestValue(array $claims): ?string
    {
        $best = null;
        foreach ($claims as $claim) {
            $value = $claim['value'] ?? null;
            if (is_string($value) && mb_strlen($value) > mb_strlen((string) $best)) {
                $best = $value;
            }
        }

        return $best;
    }

        /**
     * Situational value estimates, keyed by situation.
     *
     * Keyed rather than a list because every consumer wants one specific situation --
     * a resale listing has no use for the garage-sale figure, and scanning a list for
     * it at each call site is how the wrong one eventually gets used.
     *
     * ssai has already validated these on the way in; this only has to survive an
     * older item whose metadata predates the field, or a hand-edited one.
     *
     * @param list<array<string, mixed>> $claims
     *
     * @return array<string, array{low: string, high: string, currency: string, confidence: float, basis: ?string}>
     */
    private function valuesFrom(array $claims): array
    {
        $values = [];

        foreach ($claims as $claim) {
            $value = $claim['value'] ?? null;
            if (!is_array($value)) {
                continue;
            }

            $situation = $value['situation'] ?? null;
            $low = $value['low'] ?? null;
            $high = $value['high'] ?? null;
            $currency = $value['currency'] ?? null;
            $confidence = $value['confidence'] ?? null;

            if (!is_string($situation) || !is_string($low) || !is_string($high)
                || !is_string($currency) || !is_numeric($confidence)) {
                continue;
            }

            $values[$situation] = [
                'low' => $low,
                'high' => $high,
                'currency' => $currency,
                'confidence' => (float) $confidence,
                'basis' => is_string($value['basis'] ?? null) ? $value['basis'] : null,
            ];
        }

        return $values;
    }

    /** @param list<array<string, mixed>> $claims */
    private function confidenceOf(array $claims): ?string
    {
        foreach ($claims as $claim) {
            if (isset($claim['confidence'])) {
                return (string) $claim['confidence'];
            }
        }

        return null;
    }
}
