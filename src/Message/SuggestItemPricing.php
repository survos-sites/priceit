<?php

declare(strict_types=1);

namespace App\Message;

/**
 * "Someone captured item N — go look at it."
 *
 * Carries the id, not the Item: by the time this is consumed the entity may
 * have been edited, and a stale copy serialised into a queue row would quietly
 * overwrite that. The handler re-reads it.
 */
final readonly class SuggestItemPricing
{
    public function __construct(
        public int $itemId,
    ) {
    }
}
