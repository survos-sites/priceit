<?php

declare(strict_types=1);
namespace App\Controller;

use App\Service\CollectionSites;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LandingController extends AbstractController
{
    #[Route('/', name: 'app_landing')]
    #[Route('/profiles', name: 'app_profiles')]
    public function index(Request $request, CollectionSites $sites): Response
    {
        return $this->render('profiles.html.twig', ['sites' => $sites->all($request)]);
    }
}
