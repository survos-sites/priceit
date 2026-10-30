<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Item;
use Survos\WorkflowExtrasBundle\Attribute\AsWorkflow;
use Survos\WorkflowExtrasBundle\Attribute\Place;
use Survos\WorkflowExtrasBundle\Attribute\Transition;
use Survos\WorkflowMessengerBundle\Attribute\Transition as AsyncTransition;

/**
 * The life of a garage-sale item: photographed, described, priced, tagged.
 *
 * The cascade is declared here rather than dispatched by hand. PLACE_NEW
 * carries `next: [TRANSITION_SUGGEST]`, so an item that arrives from the phone
 * asks for its own suggestion — capture no longer has to remember to ask, and
 * the graph is the record of what happens next rather than a line buried in an
 * upload handler.
 */
#[AsWorkflow(name: self::WORKFLOW_NAME, supports: [Item::class], description: 'The life of a garage-sale item: photographed, described, priced, tagged, listed')]
class ItemFlow
{
    public const WORKFLOW_NAME = 'item';

    private const LIST_DESCRIPTION = 'Create the listing on eBay and publish it. Deliberately NOT re-enterable, unlike tag. A second `tag` reprints a sticker; a second `list` would put a second live listing on eBay for the same object, and eBay would either refuse it (the SKU already has an offer) or, worse, accept it. Withdrawing and relisting is a different action than repeating this one.';

    // ─────────────── Places ───────────────

    #[Place(
        initial: true,
        description: 'Photos (and maybe a spoken note) uploaded from the phone. Nothing has looked at them yet.',
        next: [self::TRANSITION_SUGGEST],
    )]
    public const PLACE_NEW = 'new';

    #[Place(
        description: 'AI has proposed a title, a description and a price. Waiting on a human to agree.',
    )]
    public const PLACE_SUGGESTED = 'suggested';

    #[Place(
        description: 'A person confirmed the price. Ready to have a label printed and to go on a table.',
    )]
    public const PLACE_PRICED = 'priced';

    #[Place(
        description: 'A price tag came out of the Zebra for this one.',
    )]
    public const PLACE_TAGGED = 'tagged';

    #[Place(
        description: 'Live on eBay. The offer id is recorded on the item, so the listing can be withdrawn later.',
    )]
    public const PLACE_LISTED = 'listed';

    #[Place(
        description: 'The suggestion could not be made — usually an unreadable photo or a missing API key. Fixable, then retry by hand.',
        // Deliberately NO `next`. WorkflowListener drives `next` from the `entered`
        // event, so next: [TRANSITION_SUGGEST] here means "on failing, immediately
        // try again" -- and a failure that is not transient then loops forever. A
        // null API key produced 3850 queued messages in five minutes that way.
        //
        // The word "retry" in the description is a human retry: `suggest` still
        // accepts `failed` as a from-place, so the admin button and the CLI both
        // work. What is gone is the automatic re-entry.
    )]
    public const PLACE_FAILED = 'failed';

    // ─────────────── Transitions ───────────────

    // Async because the phone is waiting on the upload response, often on a
    // bad connection, while a vision call takes seconds.
    #[AsyncTransition(
        from: self::PLACE_NEW,
        to: self::PLACE_SUGGESTED,
        description: 'Read the photos, propose a title, a description and a garage-sale price.',
        label: 'Suggest',
        async: true,
        transport: 'item_suggest',
    )]
    #[AsyncTransition(
        from: self::PLACE_FAILED,
        to: self::PLACE_SUGGESTED,
        description: 'Read the photos, propose a title, a description and a garage-sale price.',
        label: 'Suggest',
        async: true,
        transport: 'item_suggest',
    )]
    public const TRANSITION_SUGGEST = 'suggest';

    #[Transition(
        from: self::PLACE_NEW,
        to: self::PLACE_FAILED,
        description: 'Record that the suggestion could not be made, so it shows up as needing attention rather than sitting silently at "new".',
        label: 'Suggestion failed',
    )]
    #[Transition(
        from: self::PLACE_FAILED,
        to: self::PLACE_FAILED,
        description: 'Record that the suggestion could not be made, so it shows up as needing attention rather than sitting silently at "new".',
        label: 'Suggestion failed',
    )]
    public const TRANSITION_SUGGEST_FAILED = 'suggest_failed';

    // From new as well: a human can price something outright without ever
    // asking the model.
    #[Transition(from: self::PLACE_NEW, to: self::PLACE_PRICED, label: 'Price', description: 'A person confirms the price. Re-enterable, because changing your mind about a price is normal.')]
    #[Transition(from: self::PLACE_SUGGESTED, to: self::PLACE_PRICED, label: 'Price', description: 'A person confirms the price. Re-enterable, because changing your mind about a price is normal.')]
    #[Transition(from: self::PLACE_FAILED, to: self::PLACE_PRICED, label: 'Price', description: 'A person confirms the price. Re-enterable, because changing your mind about a price is normal.')]
    #[Transition(from: self::PLACE_PRICED, to: self::PLACE_PRICED, label: 'Price', description: 'A person confirms the price. Re-enterable, because changing your mind about a price is normal.')]
    public const TRANSITION_PRICE = 'price';

    #[Transition(from: self::PLACE_PRICED, to: self::PLACE_TAGGED, label: 'Tag', description: 'A label was printed. Re-enterable — labels get lost, spilled on, and reprinted.')]
    #[Transition(from: self::PLACE_TAGGED, to: self::PLACE_TAGGED, label: 'Tag', description: 'A label was printed. Re-enterable — labels get lost, spilled on, and reprinted.')]
    public const TRANSITION_TAG = 'tag';

    // A printed label is not a prerequisite: something going to eBay is being
    // shipped, not put on a table, so `priced` is enough.
    // Three HTTP calls to eBay, so not on the request. Same reasoning as suggest.
    #[AsyncTransition(
        from: self::PLACE_PRICED,
        to: self::PLACE_LISTED,
        description: self::LIST_DESCRIPTION,
        label: 'List on eBay',
        async: true,
        transport: 'item_list',
    )]
    #[AsyncTransition(
        from: self::PLACE_TAGGED,
        to: self::PLACE_LISTED,
        description: self::LIST_DESCRIPTION,
        label: 'List on eBay',
        async: true,
        transport: 'item_list',
    )]
    public const TRANSITION_LIST = 'list';
}
