<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\BrevoBundle\Event\BrevoMailEvent;

/**
 * One Brevo webhook call: delivered, bounced, opened, unsubscribed…
 *
 * The confirmation email is the only door into priceit (login is refused until the
 * address is confirmed), so "did it arrive?" needs an answer that isn't "check Brevo".
 */
#[ORM\Entity]
#[ORM\Index(fields: ['email'], name: 'mail_event_email_idx')]
#[ORM\Index(fields: ['occurredAt'], name: 'mail_event_occurred_idx')]
final class MailEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\Column(length: 255)]
    public readonly string $email;

    /** Symfony's normalized name: received, delivered, bounce, dropped, open, click, unsubscribe, spam… */
    #[ORM\Column(length: 32)]
    public readonly string $name;

    /** Brevo's own event name, e.g. hard_bounce vs soft_bounce, which $name folds together. */
    #[ORM\Column(length: 32, nullable: true)]
    public readonly ?string $brevoEvent;

    #[ORM\Column(length: 255)]
    public readonly string $messageId;

    #[ORM\Column]
    public readonly bool $suppression;

    #[ORM\Column(length: 255, nullable: true)]
    public readonly ?string $reason;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public readonly \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public readonly \DateTimeImmutable $receivedAt;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    public readonly array $payload;

    public function __construct(BrevoMailEvent $event)
    {
        $remote = $event->remoteEvent;
        $this->email = $event->email;
        $this->name = $event->name;
        $this->messageId = $event->messageId;
        $this->suppression = $event->isSuppression;
        $this->payload = $remote->getPayload();
        $this->brevoEvent = $this->payload['event'] ?? null;
        $reason = $this->payload['reason'] ?? null;
        $this->reason = is_string($reason) && $reason !== '' ? mb_substr($reason, 0, 255) : null;
        $this->occurredAt = \DateTimeImmutable::createFromInterface($remote->getDate());
        $this->receivedAt = new \DateTimeImmutable();
    }
}
