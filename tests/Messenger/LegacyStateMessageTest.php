<?php

declare(strict_types=1);

namespace App\Tests\Messenger;

use App\Messenger\LegacyStateMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

final class LegacyStateMessageTest extends TestCase
{
    private function legacy(string $transition = 'suggest'): string
    {
        $message = (object) ['id' => '9', 'className' => 'App\Entity\Item', 'workflow' => 'item', 'transitionName' => $transition, 'context' => []];
        $serialized = serialize(new Envelope($message));
        $class = 'Survos\StateBundle\Message\TransitionMessage';
        $serialized = str_replace('O:8:"stdClass"', 'O:'.strlen($class).':"'.$class.'"', $serialized);
        return addslashes($serialized);
    }

    public function testRecognizesOriginalAndWrappedFailures(): void
    {
        $body = $this->legacy();
        self::assertSame(['itemId' => '9', 'transition' => 'suggest'], LegacyStateMessage::inspect($body));
        self::assertSame('9', LegacyStateMessage::inspect(base64_encode($body))['itemId']);
        $serializer = new PhpSerializer();
        for ($i = 0; $i < 3; ++$i) {
            $body = $serializer->encode(MessageDecodingFailedException::wrap(['body' => $body, 'headers' => []], 'Old class missing'))['body'];
            self::assertSame('9', LegacyStateMessage::inspect($body)['itemId']);
        }
    }

    public function testArchivePreservesPayloadAndOnlyMovesUnclaimedLegacyFailures(): void
    {
        $db = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY, body TEXT, headers TEXT, queue_name TEXT, delivered_at TEXT)');
        $body = $this->legacy();
        foreach ([1 => ['failed', null, $body], 2 => ['failed', '2026-11-02', $body], 3 => ['item.suggest', null, $body], 4 => ['failed', null, 'unrelated']] as $id => [$queue, $claimed, $payload]) {
            $db->insert('messenger_messages', ['id' => $id, 'body' => $payload, 'headers' => '{}', 'queue_name' => $queue, 'delivered_at' => $claimed]);
        }
        $command = new \App\Command\ArchiveLegacyQueueCommand($db);
        $io = new \Symfony\Component\Console\Style\SymfonyStyle(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\BufferedOutput());
        $command($io);
        self::assertSame('failed', $db->fetchOne('SELECT queue_name FROM messenger_messages WHERE id = 1'));
        $command($io, apply: true);
        self::assertSame('legacy_state_archive', $db->fetchOne('SELECT queue_name FROM messenger_messages WHERE id = 1'));
        self::assertSame($body, $db->fetchOne('SELECT body FROM messenger_messages WHERE id = 1'));
        self::assertSame(['failed', 'item.suggest', 'failed'], $db->fetchFirstColumn('SELECT queue_name FROM messenger_messages WHERE id > 1 ORDER BY id'));
        self::assertSame(4, (int) $db->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
    }

    public function testLeavesUnrelatedOrInvalidMessagesAlone(): void
    {
        self::assertNull(LegacyStateMessage::inspect('not serialized'));
        self::assertNull(LegacyStateMessage::inspect($this->legacy('tag')));
        self::assertNull(LegacyStateMessage::inspect((new PhpSerializer())->encode(new Envelope((object) ['id' => '9']))['body']));
    }
}
