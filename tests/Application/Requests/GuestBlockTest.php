<?php

declare(strict_types=1);

namespace App\Tests\Application\Requests;

use App\Events\Entity\Event;
use App\Factory\AccountFactory;
use App\Factory\AccountGuestBlockFactory;
use App\Factory\EventFactory;
use App\Factory\UserFactory;
use App\Tests\Application\InteractsWithGuests;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class GuestBlockTest extends WebTestCase
{
    use InteractsWithGuests;

    private const STATELESS_TOKEN = 'csrf-token';

    public function test_block_hides_the_guests_request_from_the_queue(): void
    {
        [$client, $event] = $this->djWithEvent();
        $token = $this->guestRequests($client, $event, 'Macarena', 'Lea');

        $this->blockFromRow($client, $event, $this->requestId('Macarena'));

        self::assertResponseRedirects($this->queuePath($event), 303);
        self::assertSame([$this->guestId($token)], $this->connection()->fetchFirstColumn('SELECT guest_id FROM account_guest_blocks'));

        $crawler = $client->followRedirect();
        self::assertStringNotContainsString('Macarena', $crawler->filter('turbo-frame#queue')->text());
        self::assertSelectorTextContains('turbo-frame#queue .hint', '1 Gast stummgeschaltet');
    }

    public function test_muted_guests_later_requests_do_not_appear(): void
    {
        [$client, $event] = $this->djWithEvent();
        $token = $this->guestRequests($client, $event, 'Macarena', 'Lea');
        $this->blockFromRow($client, $event, $this->requestId('Macarena'));

        $this->asGuest($client, $token);
        $this->submitRequest($client, $event->getSlug(), 'Dreams');

        self::assertResponseStatusCodeSame(303);
        self::assertStringContainsString('/r/'.$event->getSlug().'/sent?', (string) $client->getResponse()->headers->get('Location'));
        self::assertSame(0, (int) $this->connection()->fetchOne("SELECT count(*) FROM requests WHERE title = 'Dreams'"));
        $crawler = $client->request('GET', $this->queuePath($event));
        self::assertStringNotContainsString('Dreams', $crawler->filter('turbo-frame#queue')->text());
    }

    public function test_block_keeps_co_voted_request_with_remaining_votes(): void
    {
        [$client, $event] = $this->djWithEvent();
        $this->guestRequests($client, $event, 'Dreams', 'Lea');
        $this->guestRequests($client, $event, 'Dreams', 'Nico');
        $id = $this->requestId('Dreams');

        $this->blockFromRow($client, $event, $id);

        $crawler = $client->followRedirect();
        $row = $crawler->filter(\sprintf('[data-request-id="%s"]', $id));
        self::assertCount(1, $row);
        self::assertSame('1', $row->filter('.queue-votes span')->text());
        self::assertSame('Nico', $row->filter('.queue-nicknames')->text());
    }

    public function test_block_list_shows_nickname_and_last_request(): void
    {
        [$client, $event] = $this->djWithEvent();
        $this->guestRequests($client, $event, 'Macarena', 'Lea');
        $this->blockFromRow($client, $event, $this->requestId('Macarena'));

        $client->request('GET', '/dj/blocks');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-guest-id] .list-row-title', 'Lea');
        self::assertSelectorTextContains('[data-guest-id] .list-row-sub', 'Zuletzt: «Macarena»');
    }

    public function test_unblock_restores_the_request(): void
    {
        [$client, $event] = $this->djWithEvent();
        $this->guestRequests($client, $event, 'Macarena', 'Lea');
        $this->blockFromRow($client, $event, $this->requestId('Macarena'));
        $crawler = $client->request('GET', '/dj/blocks');

        $client->submit($crawler->selectButton('Aufheben')->form());

        self::assertResponseRedirects('/dj/blocks', 303);
        $crawler = $client->followRedirect();
        self::assertCount(0, $crawler->filter('[data-guest-id]'));
        self::assertSelectorTextContains('.empty', 'Du hast niemanden stummgeschaltet.');
        self::assertSame(0, $this->countRows('account_guest_blocks'));

        $crawler = $client->request('GET', $this->queuePath($event));
        self::assertStringContainsString('Macarena', $crawler->filter('turbo-frame#queue')->text());
    }

    public function test_block_requires_csrf_token(): void
    {
        [$client, $event] = $this->djWithEvent();
        $token = $this->guestRequests($client, $event, 'Macarena', 'Lea');

        $this->postBlock($client, $event, $this->guestId($token), null);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->countRows('account_guest_blocks'));
    }

    public function test_blocking_another_accounts_guest_returns_404(): void
    {
        [$client, $event] = $this->djWithEvent();
        $token = $this->guestRequests($client, EventFactory::createOne(), 'Macarena', 'Lea');

        $this->postBlock($client, $event, $this->guestId($token), self::STATELESS_TOKEN);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->countRows('account_guest_blocks'));
    }

    public function test_block_list_shows_only_own_blocks(): void
    {
        [$client, $event] = $this->djWithEvent();
        $token = $this->guestRequests($client, $event, 'Macarena', 'Lea');
        $this->blockFromRow($client, $event, $this->requestId('Macarena'));
        AccountGuestBlockFactory::createOne();

        $crawler = $client->request('GET', '/dj/blocks');

        self::assertSame(
            [$this->guestId($token)],
            $crawler->filter('[data-guest-id]')->each(static fn ($node) => $node->attr('data-guest-id')),
        );
    }

    public function test_anonymous_visitor_is_redirected_to_login(): void
    {
        $client = self::createClient();

        $client->request('GET', '/dj/blocks');

        self::assertResponseRedirects('/login');
    }

    /**
     * @return array{KernelBrowser, Event}
     */
    private function djWithEvent(): array
    {
        $client = self::createClient();
        $account = AccountFactory::createOne();
        $client->loginUser(UserFactory::createOne(['account' => $account]));

        return [$client, EventFactory::createOne(['account' => $account, 'name' => 'Plaza Club'])];
    }

    /**
     * @return string the guest's token
     */
    private function guestRequests(KernelBrowser $client, Event $event, string $title, string $nickname): string
    {
        $token = $this->asGuest($client);
        $this->submitRequest($client, $event->getSlug(), $title, nickname: $nickname);
        self::assertResponseStatusCodeSame(303);

        return $token;
    }

    /**
     * GETs the queue first, so the POST carries a same-origin Referer for stateless CSRF.
     */
    private function blockFromRow(KernelBrowser $client, Event $event, string $requestId): void
    {
        $crawler = $client->request('GET', $this->queuePath($event));
        $client->submit($crawler->filter(\sprintf('[data-request-id="%s"] form[action$="/block"]', $requestId))->form());
    }

    private function postBlock(KernelBrowser $client, Event $event, string $guestId, ?string $token): void
    {
        $client->request(
            'POST',
            \sprintf('/dj/events/%s/guests/%s/block', $event->getId(), $guestId),
            null === $token ? [] : ['_token' => $token],
            server: ['HTTP_REFERER' => 'http://localhost/dj'],
        );
    }

    private function queuePath(Event $event): string
    {
        return '/dj/events/'.$event->getId().'/queue';
    }

    private function requestId(string $title): string
    {
        $id = $this->connection()->fetchOne('SELECT id FROM requests WHERE title = :title', ['title' => $title]);
        self::assertIsString($id);

        return $id;
    }

    private function guestId(string $token): string
    {
        $id = $this->connection()->fetchOne('SELECT id FROM guests WHERE token = :token', ['token' => $token]);
        self::assertIsString($id);

        return $id;
    }
}
