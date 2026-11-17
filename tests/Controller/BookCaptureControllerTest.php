<?php

declare(strict_types=1);
namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BookCaptureControllerTest extends WebTestCase
{
    public function testProfileAndLookupRequireLoginAndRenderAfterLogin(): void
    {
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        putenv('DATABASE_URL=sqlite:///:memory:');
        $_ENV['PRICEIT_PUBLIC_URL'] = $_SERVER['PRICEIT_PUBLIC_URL'] = 'https://m4-iit.survos.org';
        putenv('PRICEIT_PUBLIC_URL=https://m4-iit.survos.org');
        $client = self::createClient(); $client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        $client->request('GET', '/sites/book/app');
        self::assertResponseRedirects();
        $client->request('GET', '/books/isbn/9780140328721');
        self::assertResponseRedirects();
        $user = (new User())->setEmail('book-test@example.test')->setPassword('test')->setIsVerified(true);
        $em->persist($user); $em->flush(); $client->loginUser($user);
        $client->request('GET', '/sites/book/app');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Scan. Beep. Next book.');
        self::assertSelectorExists('[data-controller="book-capture"]');
        $client->request('GET', '/books/isbn/9780140328722');
        self::assertResponseStatusCodeSame(422);
        $client->request('GET', '/sites/book');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('iframe[src="/sites/book/app?embedded=1"]');
    }
}
