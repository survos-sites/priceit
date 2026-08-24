<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ItemRepository;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The label commands, kept apart from the builder and the transport.
 *
 * PriceLabelService builds ZPL and depends on nothing; DepotPrintClient sends it
 * and depends on the builder. Hanging the CLI off either one puts them in a
 * cycle, so the console entry points live here instead.
 */
final class LabelConsoleService
{
    public function __construct(
        private readonly ItemRepository $items,
        private readonly PriceLabelService $labels,
        private readonly DepotPrintClient $depot,
        private readonly PricingSuggestionService $pricing,
    ) {
    }

    #[AsCommand('label:preview', 'write an item label as ZPL to stdout without sending it anywhere')]
    public function preview(SymfonyStyle $io, #[Argument('item id')] int $id): int
    {
        $item = $this->items->find($id);
        if ($item === null) {
            $io->error('No item '.$id);

            return Command::FAILURE;
        }

        $io->writeln($this->labels->buildZpl($item, 'https://priceit.wip/admin/items/'.$id));

        return Command::SUCCESS;
    }

    #[AsCommand('label:print', 'build an item label and push it to depot')]
    public function print(
        SymfonyStyle $io,
        #[Argument('item id')] int $id,
        #[Option('how many copies')] int $copies = 1,
    ): int {
        $item = $this->items->find($id);
        if ($item === null) {
            $io->error('No item '.$id);

            return Command::FAILURE;
        }

        $result = $this->depot->printLabel($item, null, $copies);
        $result['ok'] ? $io->success($result['message']) : $io->error($result['message']);

        return $result['ok'] ? Command::SUCCESS : Command::FAILURE;
    }

    #[AsCommand('item:suggest', 'ask the model for a title, description and price')]
    public function suggest(SymfonyStyle $io, #[Argument('item id')] int $id): int
    {
        $item = $this->items->find($id);
        if ($item === null) {
            $io->error('No item '.$id);

            return Command::FAILURE;
        }

        $result = $this->pricing->suggest($item);
        $result['ok'] ? $io->success($result['message']) : $io->error($result['message']);

        return $result['ok'] ? Command::SUCCESS : Command::FAILURE;
    }
}
