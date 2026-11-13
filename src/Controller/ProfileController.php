<?php

declare(strict_types=1);
namespace App\Controller;

use App\Entity\Cemetery;
use App\Profile\CaptureProfile;
use App\Service\CollectionSites;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Attribute\Route;

final class ProfileController extends AbstractController
{
    #[Route('/', name: 'profile_tunnel_home', host: '{profile}--iit.survos.org', requirements: ['profile' => 'stone|medical|garage-sale|resale'], priority: 100, methods: ['GET'])]
    #[Route('/', name: 'stone_home', host: 'stone.iit.wip', defaults: ['profile' => 'stone'], priority: 100, methods: ['GET'])]
    #[Route('/', name: 'profile_home', host: '{profile}.iit.wip', requirements: ['profile' => 'medical|garage-sale|resale'], priority: 90, methods: ['GET'])]
    #[Route('/sites/{profile}', name: 'profile_landing', requirements: ['profile' => 'stone|medical|garage-sale|resale'], methods: ['GET'])]
    public function home(string $profile, Request $request, CollectionSites $sites): Response
    {
        return $this->render('site.html.twig', ['site' => $sites->all($request)[$profile]]);
    }

    #[Route('/app', name: 'profile_tunnel_app', host: '{profile}--iit.survos.org', requirements: ['profile' => 'stone|medical|garage-sale|resale'], priority: 100, methods: ['GET'])]
    #[Route('/sites/{profile}/app', name: 'profile_app', requirements: ['profile' => 'stone|medical|garage-sale|resale'], methods: ['GET'])]
    public function app(string $profile, Request $request, EntityManagerInterface $em, #[Autowire(service: 'profiler')] ?Profiler $profiler = null): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        if ($profile === 'stone' || $request->query->getBoolean('embedded')) $profiler?->disable();
        if ($profile === 'stone') {
            return $this->render('stone/capture.html.twig', ['cemeteries' => $em->getRepository(Cemetery::class)->findBy([], ['name' => 'ASC'])]);
        }
        $captureProfile = match ($profile) {
            'medical' => CaptureProfile::MedicalEquipment,
            'resale' => CaptureProfile::Resale,
            default => CaptureProfile::GarageSale,
        };
        return $this->render('item/capture.html.twig', [
            'inventory' => $captureProfile === CaptureProfile::MedicalEquipment,
            'profiles' => [$captureProfile], 'selectedProfile' => $captureProfile->value,
        ]);
    }
}
