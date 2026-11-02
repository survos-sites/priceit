<?php

namespace App\Controller;

use App\Repository\UserRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    public function __construct(
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    #[Route(path: '/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils, UserRepository $users): Response
    {
        // get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();

        // last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
            'dev_users' => $this->environment === 'dev'
                ? $users->findBy(['isVerified' => true], ['id' => 'ASC'], 5)
                : [],
        ]);
    }

    #[Route('/login/dev/{id}', name: 'app_dev_login', requirements: ['id' => '\d+'], methods: ['POST'], env: 'dev')]
    public function devLogin(int $id, Request $request, UserRepository $users, Security $security): Response
    {
        // Keep the guard as well as the route restriction: debug mode alone must never enable this.
        if ($this->environment !== 'dev') {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('dev_login_'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Reload the login page and try again.');
        }
        $user = $users->find($id);
        if ($user === null || !$user->isVerified()) {
            throw $this->createNotFoundException();
        }

        $security->login($user, 'form_login', 'main');
        $request->getSession()->remove('_security.main.target_path');

        return $this->redirectToRoute('inventory_index', status: Response::HTTP_SEE_OTHER);
    }

    #[Route(path: '/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}
