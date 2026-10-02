<?php

declare(strict_types=1);

namespace App\Tests\Application\Events;

use App\Factory\AccountFactory;
use App\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class DjHomeTest extends WebTestCase
{
    public function test_anonymous_visitor_is_redirected_to_login(): void
    {
        $client = self::createClient();

        $client->request('GET', '/dj');

        self::assertResponseRedirects('/login');
    }

    public function test_dj_sees_stage_name_and_logout_button(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['account' => AccountFactory::new(['stageName' => 'DJ Mira'])]));

        $client->request('GET', '/dj');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Hallo DJ Mira');
        self::assertCount(1, $client->getCrawler()->selectButton('Abmelden'));
    }
}
