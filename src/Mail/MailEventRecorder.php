<?php

declare(strict_types=1);

namespace App\Mail;

use App\Entity\MailEvent;
use Doctrine\ORM\EntityManagerInterface;
use Survos\BrevoBundle\Event\BrevoMailEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Keeps every Brevo webhook call, so admin/mail can answer "did that email arrive?". */
final class MailEventRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[AsEventListener]
    public function onBrevoMail(BrevoMailEvent $event): void
    {
        $this->em->persist(new MailEvent($event));
        $this->em->flush();
    }
}
