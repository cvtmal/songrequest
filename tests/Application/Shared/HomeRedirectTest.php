<?php

declare(strict_types=1);

namespace App\Tests\Application\Shared;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomeRedirectTest extends WebTestCase
{
    public function test_root_redirects_to_dj_area_and_then_to_login(): void
    {
        $client = self::createClient();

        $client->request('GET', '/');

        self::assertResponseRedirects('/dj', 302);

        $client->followRedirect();

        self::assertResponseRedirects('/login');
    }
}
