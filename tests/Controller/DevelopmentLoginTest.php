<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DevelopmentLoginTest extends WebTestCase
{
    public function testShortcutIsNotExposedOutsideDevelopment(): void
    {
        $client = self::createClient();
        $client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.dev-user-picker');
        $client->request('POST', '/login/dev/1');
        self::assertResponseStatusCodeSame(404);
    }
}
