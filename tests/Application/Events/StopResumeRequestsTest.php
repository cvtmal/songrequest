<?php

declare(strict_types=1);

namespace App\Tests\Application\Events;

use App\Events\Entity\Event;
use App\Factory\AccountFactory;
use App\Factory\EventFactory;
use App\Factory\UserFactory;
use App\Tests\Application\InteractsWithGuests;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class StopResumeRequestsTest extends WebTestCase
{
    use InteractsWithGuests;

    private const STATELESS_TOKEN = 'csrf-token';

    public function test_stop_pauses_guest_submissions(): void
    {
        [$client, $event] = $this->djWithEvent();

        $this->pressInQueue($client, $event, 'Wünsche stoppen');

        self::assertResponseRedirects($this->queuePath($event), 303);
        self::assertSame(Event::STATUS_STOPPED, $this->eventStatus($event));

        // /r/ has security: false, so the DJ session does not matter there.
        $client->request('GET', '/r/'.$event->getSlug());
        self::assertSelectorTextContains('.closed-title', 'Wünsche sind gerade geschlossen');

        $client->request('POST', '/r/'.$event->getSlug(), ['song_request' => ['title' => 'X']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->countRows('requests'));
    }

    public function test_resume_reopens_guest_submissions(): void
    {
        [$client, $event] = $this->djWithEvent();
        $this->pressInQueue($client, $event, 'Wünsche stoppen');

        $this->pressInQueue($client, $event, 'Wünsche fortsetzen');

        self::assertResponseRedirects($this->queuePath($event), 303);
        self::assertSame(Event::STATUS_OPEN, $this->eventStatus($event));

        $this->asGuest($client);
        $this->submitRequest($client, $event->getSlug(), 'Dreams');
        self::assertResponseStatusCodeSame(303);
        self::assertSame(1, $this->countRows('requests'));
    }

    public function test_stopping_twice_is_harmless(): void
    {
        [$client, $event] = $this->djWithEvent();
        $this->pressInQueue($client, $event, 'Wünsche stoppen');

        $this->post($client, $event, 'stop', self::STATELESS_TOKEN);

        self::assertResponseRedirects($this->queuePath($event), 303);
        self::assertSame(Event::STATUS_STOPPED, $this->eventStatus($event));
    }

    public function test_stop_does_not_reopen_a_closed_event(): void
    {
        [$client, $event] = $this->djWithEvent();
        $this->connection()->executeStatement(
            "UPDATE events SET status = 'closed', closed_at = CURRENT_TIMESTAMP WHERE id = :id",
            ['id' => $event->getId()],
        );

        $this->post($client, $event, 'stop', self::STATELESS_TOKEN);
        self::assertResponseStatusCodeSame(303);
        $this->post($client, $event, 'resume', self::STATELESS_TOKEN);
        self::assertResponseStatusCodeSame(303);

        self::assertSame(Event::STATUS_CLOSED, $this->eventStatus($event));
    }

    public function test_stop_requires_csrf_token(): void
    {
        [$client, $event] = $this->djWithEvent();

        $this->post($client, $event, 'stop', null);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(Event::STATUS_OPEN, $this->eventStatus($event));
    }

    public function test_stopping_another_accounts_event_returns_404(): void
    {
        [$client] = $this->djWithEvent();
        $foreign = EventFactory::createOne();

        $this->post($client, $foreign, 'stop', self::STATELESS_TOKEN);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(Event::STATUS_OPEN, $this->eventStatus($foreign));
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
     * GETs the queue first, so the POST carries a same-origin Referer for stateless CSRF.
     */
    private function pressInQueue(KernelBrowser $client, Event $event, string $button): void
    {
        $crawler = $client->request('GET', $this->queuePath($event));
        $client->submit($crawler->selectButton($button)->form());
    }

    private function post(KernelBrowser $client, Event $event, string $action, ?string $token): void
    {
        $client->request(
            'POST',
            \sprintf('/dj/events/%s/%s', $event->getId(), $action),
            null === $token ? [] : ['_token' => $token],
            server: ['HTTP_REFERER' => 'http://localhost/dj'],
        );
    }

    private function queuePath(Event $event): string
    {
        return '/dj/events/'.$event->getId().'/queue';
    }

    private function eventStatus(Event $event): string
    {
        $status = $this->connection()->fetchOne('SELECT status FROM events WHERE id = :id', ['id' => $event->getId()]);
        self::assertIsString($status);

        return $status;
    }
}
