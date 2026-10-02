<?php

declare(strict_types=1);

namespace App\Tests\Application\Events;

use App\Events\Entity\Event;
use App\Factory\AccountFactory;
use App\Factory\EventFactory;
use App\Factory\UserFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Application\InteractsWithGuests;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class CloseEventTest extends WebTestCase
{
    use InteractsWithGuests;

    private const STATELESS_TOKEN = 'csrf-token';

    public function test_close_sets_event_closed_and_redirects_to_list(): void
    {
        [$client, $event] = $this->djWithEvent();

        $this->closeFromList($client, $event);

        self::assertResponseRedirects('/dj', 303);
        $row = $this->eventRow($event);
        self::assertSame('closed', $row['status']);
        self::assertNotNull($row['closed_at']);

        $crawler = $client->followRedirect();
        $closedSection = $crawler->filter('section.list')->last();
        self::assertStringContainsString('Beendet', $closedSection->filter('.section-label')->text());
        self::assertCount(1, $closedSection->filter(\sprintf('[data-event-id="%s"]', $event->getId())));
    }

    public function test_closed_event_guest_page_shows_closed_and_rejects_post(): void
    {
        [$client, $event] = $this->djWithEvent();
        $this->closeFromList($client, $event);

        // /r/ has security: false, so the DJ session does not matter there.
        $crawler = $client->request('GET', '/r/'.$event->getSlug());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.closed-title', 'Wünsche sind gerade geschlossen');
        self::assertCount(0, $crawler->filter('form'));

        $client->request('POST', '/r/'.$event->getSlug(), ['song_request' => ['title' => 'X']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->countRows('requests'));
    }

    public function test_closing_twice_is_harmless(): void
    {
        [$client, $event] = $this->djWithEvent();
        $this->closeFromList($client, $event);
        $closedAt = $this->eventRow($event)['closed_at'];

        $this->postClose($client, $event->getId(), self::STATELESS_TOKEN);

        self::assertResponseRedirects('/dj', 303);
        self::assertSame($closedAt, $this->eventRow($event)['closed_at']);
    }

    public function test_close_without_csrf_token_is_forbidden(): void
    {
        [$client, $event] = $this->djWithEvent();

        $this->postClose($client, $event->getId(), null);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(Event::STATUS_OPEN, $this->eventRow($event)['status']);
    }

    public function test_closing_another_accounts_event_returns_404(): void
    {
        [$client] = $this->djWithEvent();
        $foreign = EventFactory::createOne();

        $this->postClose($client, $foreign->getId(), self::STATELESS_TOKEN);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(Event::STATUS_OPEN, $this->eventRow($foreign)['status']);
    }

    public function test_unknown_id_returns_404(): void
    {
        [$client] = $this->djWithEvent();

        $this->postClose($client, EntityId::generate(), self::STATELESS_TOKEN);

        self::assertResponseStatusCodeSame(404);
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
     * GETs the list first, so the POST carries a same-origin Referer for stateless CSRF.
     */
    private function closeFromList(KernelBrowser $client, Event $event): void
    {
        $crawler = $client->request('GET', '/dj');
        $form = $crawler->filter(\sprintf('[data-event-id="%s"]', $event->getId()))->selectButton('Beenden')->form();
        $client->submit($form);
    }

    private function postClose(KernelBrowser $client, string $id, ?string $token): void
    {
        $client->request(
            'POST',
            "/dj/events/{$id}/close",
            null === $token ? [] : ['_token' => $token],
            server: ['HTTP_REFERER' => 'http://localhost/dj'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function eventRow(Event $event): array
    {
        $row = $this->connection()->fetchAssociative('SELECT status, closed_at FROM events WHERE id = :id', ['id' => $event->getId()]);
        self::assertIsArray($row);

        return $row;
    }
}
