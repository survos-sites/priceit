<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Item;
use App\Service\DepotPrintClient;
use App\Service\PricingSuggestionService;
use App\Workflow\ItemFlow as WF;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Workflow\Attribute\AsCompletedListener;
use Symfony\Component\Workflow\Attribute\AsTransitionListener;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\DependencyInjection\Attribute\Target;
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

        // Stop the transition completing, so the item does not land in
        // `suggested` with nothing suggested.
        $event->setBlocked($result['message']);
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

        // Built from config, not the router: this runs in a worker with no
        // request, so there is no host to infer.
        $qr = rtrim($this->publicUrl, '/').'/admin/items/'.$item->getId();

        $result = $this->depot->printLabel($item, $qr);

        if (!$result['ok']) {
            $this->logger->warning('priceit: label did not print', [
                'item' => $item->getId(),
                'reason' => $result['message'],
            ]);
            // Blocked, so the item does not claim to be tagged when no label
            // exists. Applying `tag` again is the retry.
            $event->setBlocked($result['message']);
        }
    }
}
