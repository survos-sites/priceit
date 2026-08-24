<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\ItemStatus;
use App\Message\SuggestItemPricing;
use App\Repository\ItemRepository;
use App\Service\PricingSuggestionService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
final readonly class SuggestItemPricingHandler
{
    public function __construct(
        private ItemRepository $items,
        private PricingSuggestionService $pricing,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SuggestItemPricing $message): void
    {
        $item = $this->items->find($message->itemId);
        if ($item === null) {
            // Deleted between capture and consumption. Retrying cannot help.
            throw new UnrecoverableMessageHandlingException('No item '.$message->itemId);
        }

        // Someone may have priced it by hand while this sat in the queue —
        // don't overwrite a human's judgement with a guess.
        if ($item->getStatus() !== ItemStatus::Captured) {
            $this->logger->info('priceit: skipping suggestion, item already moved on', [
                'item' => $item->getId(),
                'status' => $item->getStatus()->value,
            ]);

            return;
        }

        if (!$this->pricing->isConfigured()) {
            // No key is a configuration problem, not a transient one: three
            // retries would just fill the failure transport with the same news.
            throw new UnrecoverableMessageHandlingException('ANTHROPIC_API_KEY is not set.');
        }

        $result = $this->pricing->suggest($item);

        if (!$result['ok']) {
            // Let it retry — a timeout or a 529 is worth a second attempt, and
            // the retry strategy gives up after three.
            throw new \RuntimeException(sprintf('Suggestion failed for item %d: %s', $item->getId(), $result['message']));
        }

        $this->logger->info('priceit: suggested', ['item' => $item->getId(), 'result' => $result['message']]);
    }
}
