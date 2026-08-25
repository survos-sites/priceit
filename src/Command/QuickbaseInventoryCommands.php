<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Item;
use App\Repository\ItemRepository;
use App\Service\QuickbaseInventoryPublisher;
use App\Workflow\ItemFlow;
use Survos\QuickbaseBundle\Exception\QuickbaseApiException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

final readonly class QuickbaseInventoryCommands
{
    public function __construct(
        private ItemRepository $items,
        private QuickbaseInventoryPublisher $publisher,
    ) {
    }

    #[AsCommand('item:quickbase:inventory', 'Preview or publish PriceIt items to Lions Inventory')]
    public function inventory(
        SymfonyStyle $io,
        #[Argument('Comma-separated Item IDs; omit for recent tagged, unpublished items')] ?string $ids = null,
        #[Option('Maximum items when IDs are omitted')] int $limit = 3,
        #[Option('Write to Quickbase; without this option the command is a dry run')] bool $send = false,
    ): int {
        if ($limit < 1) {
            $io->error('--limit must be at least 1.');

            return Command::INVALID;
        }

        try {
            $items = null === $ids
                ? $this->items->findBy(
                    ['marking' => ItemFlow::PLACE_TAGGED, 'quickbaseInventoryRecordId' => null],
                    ['id' => 'DESC'],
                    $limit,
                )
                : $this->explicitItems($ids);
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        if ([] === $items) {
            $io->warning('No matching Items found. Pass explicit IDs to preview items before they are tagged.');

            return Command::SUCCESS;
        }

        foreach ($items as $item) {
            $io->section(sprintf('Item %d · %s', $item->getId(), $item->getTitle() ?? '(untitled)'));
            $io->writeln(json_encode($this->publisher->payload($item), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            if (!$send) {
                continue;
            }

            try {
                $this->publisher->publish($item);
            } catch (QuickbaseApiException|\UnexpectedValueException $exception) {
                $io->error($exception->getMessage());

                return Command::FAILURE;
            }

            $io->success(sprintf('Published as Quickbase Inventory record %d.', $item->getQuickbaseInventoryRecordId()));
        }

        if (!$send) {
            $io->note('Dry run only. Re-run with --send to write these records to Quickbase.');
        }

        return Command::SUCCESS;
    }

    /** @return list<Item> */
    private function explicitItems(string $ids): array
    {
        $items = [];
        foreach (array_filter(array_map('trim', explode(',', $ids))) as $id) {
            if (!ctype_digit($id) || (int) $id < 1) {
                throw new \InvalidArgumentException(sprintf('Invalid Item ID "%s".', $id));
            }

            $item = $this->items->find((int) $id);
            if (!$item instanceof Item) {
                throw new \InvalidArgumentException(sprintf('Item %d was not found.', (int) $id));
            }
            $items[] = $item;
        }

        if ([] === $items) {
            throw new \InvalidArgumentException('Pass at least one Item ID.');
        }

        return $items;
    }
}
