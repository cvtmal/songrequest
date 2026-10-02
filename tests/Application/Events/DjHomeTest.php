<?php

declare(strict_types=1);

namespace App\Tests\Application\Events;

use App\Events\Entity\Event;
use App\Factory\AccountFactory;
use App\Factory\EventFactory;
use App\Factory\UserFactory;
use App\Tests\Application\InteractsWithGuests;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class DjHomeTest extends WebTestCase
{
    use InteractsWithGuests;

    public function test_anonymous_visitor_is_redirected_to_login(): void
    {
        $client = self::createClient();

        $client->request('GET', '/dj');

        self::assertResponseRedirects('/login');
    }

    public function test_dj_sees_stage_name_new_event_link_and_logout_button(): void
    {
        $client = $this->loggedInClient();

        $client->request('GET', '/dj');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Hallo DJ Mira');
        self::assertCount(1, $client->getCrawler()->selectLink('Neues Event'));
        self::assertCount(1, $client->getCrawler()->selectButton('Abmelden'));
    }

    public function test_empty_list_shows_hint(): void
    {
        $client = $this->loggedInClient();

        $client->request('GET', '/dj');

        self::assertSelectorTextContains('.empty', 'Noch keine Events');
    }

    public function test_lists_own_events_with_active_before_closed(): void
    {
        $client = self::createClient();
        $account = AccountFactory::createOne(['stageName' => 'DJ Mira']);
        $client->loginUser(UserFactory::createOne(['account' => $account]));
        $open = EventFactory::createOne(['account' => $account, 'name' => 'Plaza Club']);
        $closed = EventFactory::createOne(['account' => $account, 'name' => 'Rote Fabrik']);
        EventFactory::createOne(['name' => 'Fremdes Event']);
        $this->changeStatus($closed, Event::STATUS_CLOSED);

        $crawler = $client->request('GET', '/dj');

        self::assertResponseIsSuccessful();
        $sections = $crawler->filter('section.list');
        self::assertCount(2, $sections);
        self::assertStringContainsString('Aktiv', $sections->eq(0)->filter('.section-label')->text());
        self::assertStringContainsString('Beendet', $sections->eq(1)->filter('.section-label')->text());

        $openRow = $sections->eq(0)->filter(\sprintf('[data-event-id="%s"]', $open->getId()));
        self::assertStringContainsString('Plaza Club', $openRow->text());
        self::assertCount(1, $openRow->selectButton('Beenden'));

        $closedRow = $sections->eq(1)->filter(\sprintf('[data-event-id="%s"]', $closed->getId()));
        self::assertStringContainsString('Rote Fabrik', $closedRow->text());
        self::assertCount(0, $closedRow->selectButton('Beenden'));

        self::assertStringNotContainsString('Fremdes Event', $crawler->filter('main')->text());
    }

    public function test_close_button_asks_for_confirmation(): void
    {
        $client = self::createClient();
        $account = AccountFactory::createOne(['stageName' => 'DJ Mira']);
        $client->loginUser(UserFactory::createOne(['account' => $account]));
        $event = EventFactory::createOne(['account' => $account, 'name' => 'Plaza Club']);

        $crawler = $client->request('GET', '/dj');

        $form = $crawler->filter(\sprintf('[data-event-id="%s"] form', $event->getId()));
        self::assertStringContainsString('Plaza Club', (string) $form->attr('data-turbo-confirm'));
    }

    public function test_event_rows_link_to_their_queue(): void
    {
        $client = self::createClient();
        $account = AccountFactory::createOne(['stageName' => 'DJ Mira']);
        $client->loginUser(UserFactory::createOne(['account' => $account]));
        $open = EventFactory::createOne(['account' => $account, 'name' => 'Plaza Club']);
        $closed = EventFactory::createOne(['account' => $account, 'name' => 'Rote Fabrik']);
        $this->changeStatus($closed, Event::STATUS_CLOSED);

        $crawler = $client->request('GET', '/dj');

        foreach ([$open, $closed] as $event) {
            $row = $crawler->filter(\sprintf('[data-event-id="%s"]', $event->getId()));
            self::assertCount(1, $row->filter(\sprintf('a[href="/dj/events/%s/queue"]', $event->getId())), $event->getName());
        }
    }

    public function test_links_to_block_list(): void
    {
        $client = $this->loggedInClient();

        $crawler = $client->request('GET', '/dj');

        self::assertCount(1, $crawler->filter('a[href="/dj/blocks"]'));
    }

    private function loggedInClient(): KernelBrowser
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['account' => AccountFactory::new(['stageName' => 'DJ Mira'])]));

        return $client;
    }

    private function changeStatus(Event $event, string $status): void
    {
        $this->connection()->executeStatement(
            "UPDATE events SET status = :status, closed_at = CASE WHEN :status = 'closed' THEN CURRENT_TIMESTAMP END WHERE id = :id",
            ['status' => $status, 'id' => $event->getId()],
        );
        // The first request shares the test's entity manager, which still holds the open event.
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();
    }
}
