<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Item;
use App\Service\DepotPrintClient;
use App\Service\EbayListingPublisher;
use App\Service\PricingSuggestionService;
use App\Service\QuickbaseInventoryPublisher;
use App\Workflow\ItemFlow as WF;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Workflow\Attribute\AsCompletedListener;
use Symfony\Component\Workflow\Attribute\AsTransitionListener;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * What actually happens when an item moves through ItemFlow.
 *
 * The listener does the work; the graph decides when. Nothing here knows that a
 * capture caused it — an item reaching `new` from any direction gets the same
 * treatment, which is why a retry from `failed` needed no extra code.
 */
final readonly class ItemWorkflow
{
    public function __construct(
        private PricingSuggestionService $pricing,
        private DepotPrintClient $depot,
        private EbayListingPublisher $ebay,
        private QuickbaseInventoryPublisher $inventory,
        #[Autowire('%env(PRICEIT_PUBLIC_URL)%')]
        private string $publicUrl,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        #[Target(WF::WORKFLOW_NAME)]
        private WorkflowInterface $itemWorkflow,
    ) {
    }

    #[AsTransitionListener(WF::WORKFLOW_NAME, WF::TRANSITION_SUGGEST)]
    public function onSuggest(TransitionEvent $event): void
    {
        $item = $event->getSubject();
        \assert($item instanceof Item);

        $result = $this->pricing->suggest($item);

        if ($result['ok']) {
            $this->em->flush();

            return;
        }

        // Route the failure into the graph rather than throwing. A retry then
        // means applying `suggest` again from `failed`, which the flow already
        // allows — and the item shows up as needing attention instead of
        // sitting at `new` looking untouched.
        $this->logger->warning('priceit: suggestion failed', [
            'item' => $item->getId(),
            'reason' => $result['message'],
        ]);

        if ($this->itemWorkflow->can($item, WF::TRANSITION_SUGGEST_FAILED)) {
            $this->itemWorkflow->apply($item, WF::TRANSITION_SUGGEST_FAILED);
        }

        $this->em->flush();

        // Stop the transition completing, so the item does not land in `suggested` with
        // nothing suggested. A transition listener cannot block -- only a guard can, and a
        // guard cannot know in advance whether the model will answer -- so aborting means
        // throwing. Unrecoverable on purpose: the item is already recorded at `failed` and
        // shows as needing attention, and an unreadable photo or a missing key will not fix
        // itself on a retry a second later. Re-applying `suggest` from `failed` is the retry.
        throw new UnrecoverableMessageHandlingException($result['message']);
    }

    /**
     * "Print it now" from the capture screen.
     *
     * Printing unattended means accepting the AI's price, so the item is moved
     * through `price` on the way to `tag` rather than jumping the queue — a
     * label in someone's hand and a marking of "awaiting approval" would be a
     * lie about what happened.
     */
    #[AsCompletedListener(WF::WORKFLOW_NAME, WF::TRANSITION_SUGGEST)]
    public function onSuggested(CompletedEvent $event): void
    {
        $item = $event->getSubject();
        \assert($item instanceof Item);

        if (!$item->printRequested) {
            return;
        }

        if ($this->itemWorkflow->can($item, WF::TRANSITION_PRICE)) {
            $this->itemWorkflow->apply($item, WF::TRANSITION_PRICE);
        }
        if ($this->itemWorkflow->can($item, WF::TRANSITION_TAG)) {
            $this->itemWorkflow->apply($item, WF::TRANSITION_TAG);
        }

        $this->em->flush();
    }

    /**
     * The label actually comes out here, so every route to a printed label —
     * the capture checkbox, the admin button, the CLI — goes through the same
     * transition and leaves the same marking behind.
     */
    #[AsTransitionListener(WF::WORKFLOW_NAME, WF::TRANSITION_TAG)]
    public function onTag(TransitionEvent $event): void
    {
        $item = $event->getSubject();
        \assert($item instanceof Item);

        if ($item->getProfile()->assignsAssetNumber()) {
            // Minted before the label is built, so the number on the sticker exists before it
            // is printed. Once assigned it never changes, so a reprint reproduces the same
            // label rather than issuing the item a second identity.
            $item->assignAssetNumber();
            $this->em->flush();
        }

        // Built from config, not the router: this runs in a worker with no request, so there
        // is no host to infer. A loan-closet code is keyed by the asset number printed beside
        // it; an auction item has no durable key of its own, so it falls back to the id.
        $base = rtrim($this->publicUrl, '/');
        $qr = null !== $item->getAssetNumber()
            ? $base.'/e/'.$item->getAssetNumber()
            : $base.'/admin/items/'.$item->getId();

        $result = $this->depot->printLabel($item, $qr);

        if (!$result['ok']) {
            $this->logger->warning('priceit: label did not print', [
                'item' => $item->getId(),
                'reason' => $result['message'],
            ]);

            // Throwing, so the marking is never flushed and the item does not claim to be
            // tagged when no label exists. Recoverable, unlike a failed suggestion: printing
            // fails when the laptop holding the printer has slept or dropped off wifi, which
            // is exactly the sort of thing that fixes itself, so messenger's backoff is the
            // retry. Applying `tag` again by hand also still works -- it is re-enterable.
            throw new \RuntimeException($result['message']);
        }
    }

    /**
     * A tagged loan-closet item is dispatched to Quickbase.
     *
     * After tagging rather than before: the asset number is the natural key on both sides, and
     * publishing a record whose label never came out would put a row in the closet for
     * something no volunteer can identify on a shelf.
     *
     * A failure here does not block the transition. The label is printed and physically on the
     * item by this point; refusing to record that would be a lie, and the export is
     * re-runnable by its own key.
     */
    #[AsCompletedListener(WF::WORKFLOW_NAME, WF::TRANSITION_TAG)]
    public function onTagged(CompletedEvent $event): void
    {
        $item = $event->getSubject();
        \assert($item instanceof Item);

        if (!$item->getProfile()->publishesToQuickbase()) {
            return;
        }

        if (!$this->inventory->isAvailable()) {
            $this->logger->warning('priceit: Quickbase is not configured, item not published', [
                'item' => $item->getId(),
            ]);

            return;
        }

        try {
            $this->inventory->publish($item);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->error('priceit: could not publish to the loan closet', [
                'item' => $item->getId(),
                'assetNumber' => $item->getAssetNumber(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The listing goes live here, so every route to eBay — the admin button, a CLI
     * run — goes through the same transition and leaves the same marking behind.
     *
     * Unlike the Quickbase export, a failure DOES block. Nothing physical has
     * happened by this point, so letting the item settle at `listed` with no
     * listing would be a plain lie, and the offer id it needs to withdraw later
     * would not exist.
     */
    #[AsTransitionListener(WF::WORKFLOW_NAME, WF::TRANSITION_LIST)]
    public function onList(TransitionEvent $event): void
    {
        $item = $event->getSubject();
        \assert($item instanceof Item);

        if (!$this->ebay->isAvailable()) {
            throw new UnrecoverableMessageHandlingException(
                'eBay is not configured; set EBAY_CONNECTION and the survos_marketplace connection.',
            );
        }

        $blockers = $this->ebay->blockers($item);
        if ([] !== $blockers) {
            // Unrecoverable: a missing price or an unreachable photo will not fix
            // itself on a retry a second later. Someone has to go and change it.
            throw new UnrecoverableMessageHandlingException(implode(' ', $blockers));
        }

        try {
            $this->ebay->publish($item);
        } catch (\Throwable $e) {
            $this->logger->error('priceit: could not list on eBay', [
                'item' => $item->getId(),
                'sku' => $item->getSku(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $this->em->flush();
    }
}
