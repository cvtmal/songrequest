<?php

declare(strict_types=1);

namespace App\Tests\Application\Requests;

use App\Events\Entity\Event;
use App\Factory\AccountFactory;
use App\Factory\EventFactory;
use App\Factory\UserFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Application\InteractsWithGuests;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class DjQueueTest extends WebTestCase
{
    use InteractsWithGuests;

    public function test_anonymous_visitor_is_redirected_to_login(): void
    {
        $client = self::createClient();

        $client->request('GET', '/dj/events/'.EntityId::generate().'/queue');

        self::assertResponseRedirects('/login');
    }

    public function test_other_accounts_queue_returns_404(): void
    {
        [$client] = $this->djWithEvent();
        $foreign = EventFactory::createOne();

        $client->request('GET', '/dj/events/'.$foreign->getId().'/queue');

        self::assertResponseStatusCodeSame(404);
    }

    public function test_queue_lists_open_requests_by_votes_then_first_request(): void
    {
        [$client, $event] = $this->djWithEvent();
        $this->guestRequests($client, $event, 'Get Lucky', 'Daft Punk', 'Sam');
        $this->guestRequests($client, $event, 'Padam Padam', 'Kylie Minogue');
        $this->guestRequests($client, $event, 'Dreams', 'Fleetwood Mac', 'Lea');
        $this->guestRequests($client, $event, 'Dreams', 'Fleetwood Mac', 'Nico');
        $this->sql("UPDATE requests SET created_at = CURRENT_TIMESTAMP - interval '3 minutes' WHERE title = 'Get Lucky'");
        // 30 s of slack: created_at is rounded to the second, so exactly 5 minutes could render as 4.
        $this->sql("UPDATE requests SET created_at = CURRENT_TIMESTAMP - interval '5 minutes 30 seconds' WHERE title = 'Dreams'");

        $crawler = $client->request('GET', $this->queuePath($event));

        self::assertResponseIsSuccessful();
        self::assertSame(['Dreams', 'Get Lucky', 'Padam Padam'], $crawler->filter('.queue-item .queue-title')->each(static fn ($node) => $node->text()));
        $dreams = $crawler->filter('.queue-item')->first();
        self::assertSame('2', $dreams->filter('.queue-votes span')->text());
        self::assertSame('Fleetwood Mac', $dreams->filter('.list-row-sub')->text());
        self::assertSame('Lea, Nico', $dreams->filter('.queue-nicknames')->text());
        self::assertSame('vor 5 Min.', $dreams->filter('.queue-age')->text());
    }

    public function test_empty_queue_shows_hint(): void
    {
        [$client, $event] = $this->djWithEvent();

        $client->request('GET', $this->queuePath($event));

        self::assertSelectorTextContains('.empty', 'Noch keine Wünsche.');
    }

    public function test_tabs_show_counts_and_done_tab_lists_handled_requests(): void
    {
        [$client, $event] = $this->djWithEvent();
        $this->guestRequests($client, $event, 'Dreams');
        $this->guestRequests($client, $event, 'Macarena');
        $this->sql("UPDATE requests SET status = 'played', handled_at = CURRENT_TIMESTAMP WHERE title = 'Macarena'");

        $crawler = $client->request('GET', $this->queuePath($event));

        self::assertSame(['Queue · 1', 'Erledigt · 1'], $crawler->filter('.seg-opt')->each(static fn ($node) => $node->text()));

        $crawler = $client->request('GET', $this->queuePath($event).'?tab=done');

        self::assertSame(['Macarena'], $crawler->filter('.queue-item .queue-title')->each(static fn ($node) => $node->text()));
        self::assertSelectorTextContains('.queue-item .tag', 'Gespielt');
        self::assertCount(1, $crawler->selectButton('Zurück in die Queue'));
    }

    public function test_frame_request_returns_only_the_frame(): void
    {
        [$client, $event] = $this->djWithEvent();
        $this->guestRequests($client, $event, 'Dreams');

        $client->request('GET', $this->queuePath($event), server: ['HTTP_TURBO_FRAME' => 'queue']);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-frame id="queue"', $body);
        self::assertStringNotContainsString('<html', $body);
        // Turbo rejects a frame response whose src references its own URL and renders it empty.
        self::assertStringNotContainsString(' src=', $body);
        self::assertStringContainsString('class="queue-item"', $body);
    }

    public function test_full_page_frame_polls_with_morph(): void
    {
        [$client, $event] = $this->djWithEvent();

        $crawler = $client->request('GET', $this->queuePath($event));

        $frame = $crawler->filter('turbo-frame#queue');
        self::assertCount(1, $frame);
        self::assertSame('morph', $frame->attr('refresh'));
        self::assertNotNull($frame->attr('complete'));
        self::assertSame('queue-poll', $frame->attr('data-controller'));
        self::assertSame('5000', $frame->attr('data-queue-poll-interval-value'));
        self::assertStringEndsWith('/queue', (string) $frame->attr('src'));
    }

    public function test_header_shows_stop_for_open_resume_for_stopped_and_nothing_for_closed(): void
    {
        [$client, $event] = $this->djWithEvent();

        $crawler = $client->request('GET', $this->queuePath($event));
        self::assertCount(1, $crawler->selectButton('Wünsche stoppen'));

        $this->sql("UPDATE events SET status = 'stopped' WHERE id = '{$event->getId()}'");
        $crawler = $client->request('GET', $this->queuePath($event));
        self::assertCount(1, $crawler->selectButton('Wünsche fortsetzen'));

        $this->sql("UPDATE events SET status = 'closed', closed_at = CURRENT_TIMESTAMP WHERE id = '{$event->getId()}'");
        $crawler = $client->request('GET', $this->queuePath($event));
        self::assertCount(0, $crawler->filter('form[action$="/stop"]'));
        self::assertCount(0, $crawler->filter('form[action$="/resume"]'));
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

    private function guestRequests(KernelBrowser $client, Event $event, string $title, ?string $artist = null, ?string $nickname = null): void
    {
        $this->asGuest($client);
        $this->submitRequest($client, $event->getSlug(), $title, $artist, $nickname);
        self::assertResponseStatusCodeSame(303);
    }

    private function queuePath(Event $event): string
    {
        return '/dj/events/'.$event->getId().'/queue';
    }

    /**
     * The next request may share the test's entity manager, so it must not hold stale rows.
     */
    private function sql(string $statement): void
    {
        $this->connection()->executeStatement($statement);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();
    }
}
