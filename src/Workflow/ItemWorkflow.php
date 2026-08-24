<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Item;
use App\Service\PricingSuggestionService;
use App\Workflow\ItemFlow as WF;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Workflow\Attribute\AsTransitionListener;
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
}
