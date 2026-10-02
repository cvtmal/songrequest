<?php

declare(strict_types=1);

namespace App\Tests\Application\Requests;

use App\Events\Entity\Event;
use App\Factory\AccountFactory;
use App\Factory\EventFactory;
use App\Factory\UserFactory;
use App\Tests\Application\InteractsWithGuests;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class QueueActionsTest extends WebTestCase
{
    use InteractsWithGuests;

    private const STATELESS_TOKEN = 'csrf-token';

    public function test_played_moves_the_request_to_done(): void
    {
        [$client, $event] = $this->djWithEvent();
        $this->guestRequests($client, $event, 'Dreams');
        $id = $this->requestId('Dreams');

        $this->submitRowForm($client, $event, $id, '/played');

        self::assertResponseRedirects($this->queuePath($event), 303);
        $row = $this->requestRow($id);
        self::assertSame('played', $row['status']);
        self::assertNotNull($row['handled_at']);

        $crawler = $client->followRedirect();
        self::assertCount(0, $crawler->filter(\sprintf('[data-request-id="%s"]', $id)));
        self::assertSelectorTextContains('.seg-opt[href$="tab=done"]', 'Erledigt · 1');
    }

    public function test_skip_moves_the_request_to_done(): void
    {
        [$client, $event] = $this->djWithEvent();
        $this->guestRequests($client, $event, 'Dreams');
        $id = $this->requestId('Dreams');

        $this->submitRowForm($client, $event, $id, '/skipped');

        self::assertResponseRedirects($this->queuePath($event), 303);
        self::assertSame('skipped', $this->requestRow($id)['status']);
    }

    public function test_undo_returns_the_request_to_the_queue(): void
    {
        [$client, $event] = $this->djWithEvent();
        $this->guestRequests($client, $event, 'Dreams');
        $id = $this->requestId('Dreams');
        $this->submitRowForm($client, $event, $id, '/played');

        $this->submitRowForm($client, $event, $id, '/reopen', '?tab=done');

        self::assertResponseRedirects($this->queuePath($event).'?tab=done', 303);
        self::assertSame('new', $this->requestRow($id)['status']);
        $crawler = $client->request('GET', $this->queuePath($event));
        self::assertCount(1, $crawler->filter(\sprintf('[data-request-id="%s"]', $id)));
    }

    public function test_undo_is_a_no_op_when_the_song_was_requested_again(): void
    {
        [$client, $event] = $this->djWithEvent();
        $this->guestRequests($client, $event, 'Dreams');
        $id = $this->requestId('Dreams');
        $this->submitRowForm($client, $event, $id, '/played');
        $this->guestRequests($client, $event, 'Dreams');

        $this->post($client, $this->actionPath($event, $id, 'reopen'), self::STATELESS_TOKEN);

        self::assertResponseStatusCodeSame(303);
        self::assertSame(1, (int) $this->connection()->fetchOne("SELECT count(*) FROM requests WHERE status = 'new'"));
        self::assertSame('played', $this->requestRow($id)['status']);
    }

    public function test_actions_require_a_csrf_token(): void
    {
        [$client, $event] = $this->djWithEvent();
        $this->guestRequests($client, $event, 'Dreams');
        $id = $this->requestId('Dreams');

        foreach (['played', 'skipped', 'reopen'] as $action) {
            $this->post($client, $this->actionPath($event, $id, $action), null);

            self::assertResponseStatusCodeSame(403, $action);
            self::assertSame('new', $this->requestRow($id)['status'], $action);
        }
    }

    public function test_actions_on_another_accounts_request_return_404(): void
    {
        [$client, $event] = $this->djWithEvent();
        $foreign = EventFactory::createOne();
        $this->guestRequests($client, $foreign, 'Dreams');
        $foreignId = $this->requestId('Dreams');

        $this->post($client, $this->actionPath($foreign, $foreignId, 'played'), self::STATELESS_TOKEN);
        self::assertResponseStatusCodeSame(404);

        $this->post($client, $this->actionPath($event, $foreignId, 'played'), self::STATELESS_TOKEN);
        self::assertResponseStatusCodeSame(404);

        self::assertSame('new', $this->requestRow($foreignId)['status']);
    }

    public function test_actions_work_after_the_event_is_closed(): void
    {
        [$client, $event] = $this->djWithEvent();
        $this->guestRequests($client, $event, 'Dreams');
        $id = $this->requestId('Dreams');
        $this->connection()->executeStatement(
            "UPDATE events SET status = 'closed', closed_at = CURRENT_TIMESTAMP WHERE id = :id",
            ['id' => $event->getId()],
        );

        $this->post($client, $this->actionPath($event, $id, 'played'), self::STATELESS_TOKEN);

        self::assertResponseStatusCodeSame(303);
        self::assertSame('played', $this->requestRow($id)['status']);
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

    private function guestRequests(KernelBrowser $client, Event $event, string $title): void
    {
        $this->asGuest($client);
        $this->submitRequest($client, $event->getSlug(), $title);
        self::assertResponseStatusCodeSame(303);
    }

    /**
     * GETs the queue first, so the POST carries a same-origin Referer for stateless CSRF.
     */
    private function submitRowForm(KernelBrowser $client, Event $event, string $requestId, string $actionSuffix, string $query = ''): void
    {
        $crawler = $client->request('GET', $this->queuePath($event).$query);
        $form = $crawler->filter(\sprintf('[data-request-id="%s"] form[action$="%s"]', $requestId, $actionSuffix))->form();
        $client->submit($form);
    }

    private function post(KernelBrowser $client, string $path, ?string $token): void
    {
        $client->request(
            'POST',
            $path,
            null === $token ? [] : ['_token' => $token],
            server: ['HTTP_REFERER' => 'http://localhost/dj'],
        );
    }

    private function queuePath(Event $event): string
    {
        return '/dj/events/'.$event->getId().'/queue';
    }

    private function actionPath(Event $event, string $requestId, string $action): string
    {
        return \sprintf('/dj/events/%s/requests/%s/%s', $event->getId(), $requestId, $action);
    }

    private function requestId(string $title): string
    {
        $id = $this->connection()->fetchOne(
            'SELECT id FROM requests WHERE title = :title ORDER BY created_at DESC, id DESC LIMIT 1',
            ['title' => $title],
        );
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestRow(string $id): array
    {
        $row = $this->connection()->fetchAssociative('SELECT status, handled_at FROM requests WHERE id = :id', ['id' => $id]);
        self::assertIsArray($row);

        return $row;
    }
}
