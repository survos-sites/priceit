<?php

declare(strict_types=1);
namespace App\Controller;

use App\Entity\Cemetery;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class StoneController extends AbstractController
{
    #[Route('/stones/capture', name: 'stone_capture', methods: ['GET'])]
    public function capture(EntityManagerInterface $em, Request $request): Response
    {
        if ($request->getHost() === 'iit.wip') {
            return $this->redirect('https://stone.iit.wip/sites/stone/app'.($request->getQueryString() ? '?'.$request->getQueryString() : ''));
        }
        return $this->render('stone/capture.html.twig', [
            'cemeteries' => $em->getRepository(Cemetery::class)->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/stones/cemeteries', name: 'stone_cemetery_create', methods: ['POST'])]
    public function createCemetery(Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('cemetery_create', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Reload and try again.');
        }
        $name = trim($request->request->getString('name'));
        if ($name === '' || mb_strlen($name) > 200) {
            return new Response('Enter a cemetery name (up to 200 characters).', 422);
        }
        $cemetery = new Cemetery($name);
        $em->persist($cemetery);
        $em->flush();
        return $this->redirectToRoute('stone_capture', ['cemetery' => $cemetery->getId()], 303);
    }
}
