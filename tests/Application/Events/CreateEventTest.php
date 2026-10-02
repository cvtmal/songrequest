<?php

declare(strict_types=1);

namespace App\Tests\Application\Events;

use App\Factory\AccountFactory;
use App\Factory\UserFactory;
use App\Tests\Application\InteractsWithGuests;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class CreateEventTest extends WebTestCase
{
    use InteractsWithGuests;

    public function test_anonymous_visitor_is_redirected_to_login(): void
    {
        $client = self::createClient();

        $client->request('GET', '/dj/events/new');

        self::assertResponseRedirects('/login');
    }

    public function test_creates_event_and_redirects_to_its_qr_page(): void
    {
        $client = self::createClient();
        $account = AccountFactory::createOne();
        $client->loginUser(UserFactory::createOne(['account' => $account]));

        $client->request('GET', '/dj/events/new');
        $client->submitForm('Event erstellen', ['event[name]' => '  Plaza Club ']);

        self::assertResponseStatusCodeSame(303);
        self::assertMatchesRegularExpression('#^/dj/events/[0-9a-f-]{36}/qr$#', (string) $client->getResponse()->headers->get('Location'));
        self::assertSame(1, $this->countRows('events'));
        $row = $this->connection()->fetchAssociative('SELECT name, status, account_id FROM events');
        self::assertSame(['name' => 'Plaza Club', 'status' => 'open', 'account_id' => $account->getId()], $row);

        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Plaza Club');
    }

    public function test_blank_name_rerenders_with_422(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne());

        $client->request('GET', '/dj/events/new');
        $client->submitForm('Event erstellen', ['event[name]' => '   ']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-error', 'Gib dem Event einen Namen.');
        self::assertSame(0, $this->countRows('events'));
    }

    public function test_too_long_name_rerenders_with_422_and_keeps_input(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne());
        $name = str_repeat('a', 121);

        $client->request('GET', '/dj/events/new');
        $crawler = $client->submitForm('Event erstellen', ['event[name]' => $name]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-error', 'höchstens 120 Zeichen');
        self::assertSame($name, $crawler->filter('#e-name')->attr('value'));
        self::assertSame(0, $this->countRows('events'));
    }
}
