<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\MailEvent;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Survos\BrevoBundle\Command\BrevoService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * What Brevo reported back to priceit by webhook, plus a test send to yourself.
 * Brevo's own views (account, lists, logs, blocklist) come from survos/brevo-bundle's menu.
 */
#[Route('/admin/mail', name: 'admin_mail_')]
final class MailController extends AbstractController
{
    public function __construct(
        private readonly BrevoService $brevo,
        #[Autowire('%env(MAIL_FROM)%')] private readonly string $mailFrom,
        #[Autowire('%env(MAIL_FROM_NAME)%')] private readonly string $mailFromName,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(EntityManagerInterface $em): Response
    {
        return $this->render('admin/mail/index.html.twig', [
            'error' => $this->brevo->isConfigured() ? null : 'BREVO_API_KEY is not set.',
            'events' => $em->getRepository(MailEvent::class)->findBy([], ['receivedAt' => 'DESC'], 50),
            'webhookUrl' => $this->generateUrl('_webhook_controller', ['type' => 'brevo'], 0),
        ]);
    }
    /** Sends to the signed-in admin only, so this can't be used to mail anyone else. */
    #[Route('/test', name: 'test', methods: ['POST'])]
    #[IsCsrfTokenValid('mail-test')]
    public function test(#[CurrentUser] User $user, MailerInterface $mailer): Response
    {
        $mailer->send((new Email())
            ->from(new Address($this->mailFrom, $this->mailFromName))
            ->to((string) $user->getEmail())
            ->subject('PriceIt mail test')
            ->text(sprintf("Sent from %s at %s.\n\nIf this arrived, the Brevo API transport works. Its delivery events should appear on the Mail page.", $this->generateUrl('admin_mail_index', [], 0), date('c'))));
        $this->addFlash('success', sprintf('Test email queued to %s. Delivery events arrive by webhook.', $user->getEmail()));

        return $this->redirectToRoute('admin_mail_index');
    }
}
