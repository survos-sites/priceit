<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\BookLookup;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class BookCaptureController extends AbstractController
{
    #[Route('/sites/book/app', name: 'book_capture', methods: ['GET'])]
    #[Route('/app', name: 'book_tunnel_capture', host: 'book--iit.survos.org', priority: 110, methods: ['GET'])]
    public function capture(): Response
    {
        return $this->render('book/capture.html.twig');
    }

    #[Route('/books/isbn/{isbn}', name: 'book_lookup', methods: ['GET'])]
    public function lookup(string $isbn, BookLookup $books): JsonResponse
    {
        try { return $this->json($books->lookup($isbn)); }
        catch (\InvalidArgumentException $e) { return $this->json(['error' => $e->getMessage()], 422); }
        catch (\Symfony\Contracts\HttpClient\Exception\ExceptionInterface) {
            return $this->json(['error' => 'Book lookup is unavailable. The ISBN remains saved; retry lookup later.'], 502);
        }
    }
}
