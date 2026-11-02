<?php

declare(strict_types=1);

namespace App\Command;

use App\Messenger\LegacyStateMessage;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:queue:archive-legacy', 'Preserve obsolete StateBundle item suggestion failures outside the retry queue')]
final readonly class ArchiveLegacyQueueCommand
{
    public function __construct(private Connection $db) {}

    public function __invoke(SymfonyStyle $io, #[Option('Move matching failed rows to legacy_state_archive; otherwise preview only')] bool $apply = false): int
    {
        $rows = [];
        $moved = 0;
        foreach ($this->db->iterateAssociative("SELECT id, body, headers FROM messenger_messages WHERE queue_name = 'failed' ORDER BY id") as $row) {
            $legacy = LegacyStateMessage::inspect($row['body']);
            if ($legacy === null) {
                continue;
            }
            $rows[] = [$row['id'], $legacy['itemId'], $legacy['transition']];
            if ($apply) {
                // Preserve payload, headers and timestamps verbatim. Compare the inspected row
                // to avoid moving a failure that a concurrent worker has replaced or claimed.
                $moved += $this->db->executeStatement(
                    "UPDATE messenger_messages SET queue_name = 'legacy_state_archive' WHERE id = ? AND queue_name = 'failed' AND delivered_at IS NULL AND body = ? AND headers = ?",
                    [$row['id'], $row['body'], $row['headers']],
                );
            }
        }
        $io->table(['Message', 'Item', 'Transition'], $rows);
        $io->success($apply ? sprintf('Archived %d failures. No items or messages deleted; no AI jobs replayed.', $moved) : sprintf('Found %d legacy failures. Use --apply to archive them.', count($rows)));

        return Command::SUCCESS;
    }
}
