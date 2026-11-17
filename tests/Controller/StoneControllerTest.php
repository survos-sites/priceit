<?php

declare(strict_types=1);
namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StoneControllerTest extends WebTestCase
{
    public function testProfileLandingQrAndProtectedEmbeddedApp(): void
    {
        putenv('DATABASE_URL=sqlite:///:memory:');
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        putenv('PRICEIT_PUBLIC_URL=https://m4-iit.survos.org');
        $_ENV['PRICEIT_PUBLIC_URL'] = $_SERVER['PRICEIT_PUBLIC_URL'] = 'https://m4-iit.survos.org';
        $client = self::createClient(); $client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        $crawler = $client->request('GET', '/', server: ['HTTP_HOST' => 'iit.wip']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'What are you collecting?');
        self::assertCount(5, $crawler->filter('img.qr'));
        self::assertSelectorExists('a[href="https://stone--iit.survos.org/app"]');
        self::assertSelectorExists('a[href="https://stone.iit.wip/"]');
        foreach (['stone', 'medical', 'garage-sale', 'resale'] as $profile) {
            $client->request('GET', '/', server: ['HTTP_HOST' => $profile.'.iit.wip']);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('iframe[src="/sites/'.$profile.'/app?embedded=1"]');
            $client->request('GET', '/', server: ['HTTP_HOST' => $profile.'--iit.survos.org']);
            self::assertResponseIsSuccessful();
        }
        $client->request('GET', '/app', server: ['HTTP_HOST' => 'stone--iit.survos.org']);
        self::assertResponseRedirects('http://stone--iit.survos.org/login');
        $user = (new User())->setEmail('stone-test@example.test')->setPassword('test')->setIsVerified(true);
        $em->persist($user); $em->flush();
        $client->getCookieJar()->clear();
        $client->loginUser($user);
        $client->request('GET', '/sites/stone/app', server: ['HTTP_HOST' => 'stone.iit.wip']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'One stone. Every detail.');
        self::assertSelectorTextContains('.stone-site', 'Cemetery: <auto>');
        self::assertSelectorCount(3, '.stone-tabs button');
        self::assertSelectorExists('.stone-controls input[data-stone-capture-target="advance"]');
        self::assertSelectorExists('[data-stone-capture-target="distance"]');
        $client->request('POST', '/stones/upload');
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/stones/cemeteries', ['name' => 'No token']);
        self::assertResponseStatusCodeSame(403);
    }
}
