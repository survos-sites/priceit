<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Item;
use Survos\StateBundle\Attribute\Place;
use Survos\StateBundle\Attribute\Transition;
use Survos\StateBundle\Attribute\Workflow;

/**
 * The life of a garage-sale item: photographed, described, priced, tagged.
 *
 * The cascade is declared here rather than dispatched by hand. PLACE_NEW
 * carries `next: [TRANSITION_SUGGEST]`, so an item that arrives from the phone
 * asks for its own suggestion — capture no longer has to remember to ask, and
 * the graph is the record of what happens next rather than a line buried in an
 * upload handler.
 */
#[Workflow(supports: [Item::class], name: self::WORKFLOW_NAME)]
class ItemFlow
{
    public const WORKFLOW_NAME = 'item';

    // ─────────────── Places ───────────────

    #[Place(
        initial: true,
        info: 'Photos (and maybe a spoken note) uploaded from the phone. Nothing has looked at them yet.',
        next: [self::TRANSITION_SUGGEST],
    )]
    public const PLACE_NEW = 'new';

    #[Place(
        info: 'AI has proposed a title, a description and a price. Waiting on a human to agree.',
    )]
    public const PLACE_SUGGESTED = 'suggested';

    #[Place(
        info: 'A person confirmed the price. Ready to have a label printed and to go on a table.',
    )]
    public const PLACE_PRICED = 'priced';

    #[Place(
        info: 'A price tag came out of the Zebra for this one.',
    )]
    public const PLACE_TAGGED = 'tagged';

    #[Place(
        info: 'The suggestion could not be made — usually an unreadable photo or a missing API key. Fixable, then retry.',
        next: [self::TRANSITION_SUGGEST],
    )]
    public const PLACE_FAILED = 'failed';

    // ─────────────── Transitions ───────────────

    #[Transition(
        from: [self::PLACE_NEW, self::PLACE_FAILED],
        to: self::PLACE_SUGGESTED,
        info: 'Suggest',
        description: 'Read the photos, propose a title, a description and a garage-sale price.',
        // Async because the phone is waiting on the upload response, often on a
        // bad connection, while a vision call takes seconds.
        async: true,
    )]
    public const TRANSITION_SUGGEST = 'suggest';

    #[Transition(
        from: [self::PLACE_NEW, self::PLACE_FAILED],
        to: self::PLACE_FAILED,
        info: 'Suggestion failed',
        description: 'Record that the suggestion could not be made, so it shows up as needing attention rather than sitting silently at "new".',
    )]
    public const TRANSITION_SUGGEST_FAILED = 'suggest_failed';

    #[Transition(
        // From new as well: a human can price something outright without ever
        // asking the model.
        from: [self::PLACE_NEW, self::PLACE_SUGGESTED, self::PLACE_FAILED, self::PLACE_PRICED],
        to: self::PLACE_PRICED,
        info: 'Price',
        description: 'A person confirms the price. Re-enterable, because changing your mind about a price is normal.',
    )]
    public const TRANSITION_PRICE = 'price';

    #[Transition(
        from: [self::PLACE_PRICED, self::PLACE_TAGGED],
        to: self::PLACE_TAGGED,
        info: 'Tag',
        description: 'A label was printed. Re-enterable — labels get lost, spilled on, and reprinted.',
    )]
    public const TRANSITION_TAG = 'tag';
}
