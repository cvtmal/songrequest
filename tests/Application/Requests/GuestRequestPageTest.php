<?php

declare(strict_types=1);

namespace App\Tests\Application\Requests;

use App\Events\Entity\Event;
use App\Factory\AccountGuestBlockFactory;
use App\Factory\EventFactory;
use App\Factory\GuestFactory;
use App\Factory\RequestVoteFactory;
use App\Factory\SongRequestFactory;
use App\Factory\UserFactory;
use App\Tests\Application\InteractsWithGuests;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class GuestRequestPageTest extends WebTestCase
{
    use InteractsWithGuests;

    // Must be outside private_ranges (trusted proxies), which include the TEST-NET ranges.
    private const IP = '84.75.12.34';

    public function test_form_shows_dj_and_event_name(): void
    {
        $client = $this->client();
        $event = EventFactory::createOne();

        $client->request('GET', '/r/'.$event->getSlug());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.page-kicker', $event->getAccount()->getStageName());
        self::assertSelectorTextContains('.page-kicker', $event->getName());
        self::assertSelectorTextContains('h1', 'Was soll ich als Nächstes spielen?');
        self::assertSelectorTextContains('.hint', 'Bis zu 3 Wünsche heute Abend');
    }

    public function test_first_visit_issues_guest_token_cookie(): void
    {
        $client = $this->client();
        $event = EventFactory::createOne();

        $client->request('GET', '/r/'.$event->getSlug());

        $cookie = $this->issuedGuestToken($client);
        self::assertNotNull($cookie);
        self::assertTrue(Uuid::isValid((string) $cookie->getValue()));
        self::assertTrue($cookie->isSecure());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('lax', $cookie->getSameSite());
        self::assertGreaterThanOrEqual(86399, $cookie->getMaxAge());
        self::assertLessThanOrEqual(86400, $cookie->getMaxAge());
    }

    public function test_existing_guest_token_is_kept(): void
    {
        $client = $this->client();
        $event = EventFactory::createOne();
        $this->asGuest($client);

        $client->request('GET', '/r/'.$event->getSlug());

        self::assertResponseIsSuccessful();
        self::assertNull($this->issuedGuestToken($client));
    }

    public function test_tampered_guest_token_is_replaced_and_submit_succeeds(): void
    {
        $client = $this->client();
        $event = EventFactory::createOne();
        $this->asGuest($client, 'not-a-uuid');

        $client->request('GET', '/r/'.$event->getSlug());
        $issued = $this->issuedGuestToken($client);
        self::assertNotNull($issued);
        self::assertTrue(Uuid::isValid((string) $issued->getValue()));

        $this->submitRequest($client, $event->getSlug(), 'Mr Brightside');

        self::assertResponseStatusCodeSame(303);
        self::assertSame($issued->getValue(), $this->connection()->fetchOne('SELECT token FROM guests'));
    }

    public function test_guest_page_sets_no_session_cookie(): void
    {
        $client = $this->client();
        $event = EventFactory::createOne();

        $this->submitRequest($client, $event->getSlug(), 'Mr Brightside');

        self::assertResponseStatusCodeSame(303);
        self::assertSame([], $this->responseCookiesOtherThanGuestToken($client));
        self::assertSame(['guest_token'], array_map(static fn ($cookie) => $cookie->getName(), $client->getCookieJar()->all()));

        // Even a logged-in DJ gets no session touched on the guest firewall (security: false).
        $client->loginUser(UserFactory::createOne(['account' => $event->getAccount()]));
        $client->request('GET', '/r/'.$event->getSlug());

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->responseCookiesOtherThanGuestToken($client));
    }

    public function test_successful_submit_redirects_to_signed_sent_page(): void
    {
        $client = $this->client();
        $event = EventFactory::createOne();
        $stageName = $event->getAccount()->getStageName();
        $this->asGuest($client);

        $this->submitRequest($client, $event->getSlug(), 'Mr Brightside', 'The Killers', 'Anna');

        self::assertResponseStatusCodeSame(303);
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/r/'.$event->getSlug().'/sent?', $location);
        self::assertStringContainsString('_hash=', $location);

        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Wunsch gesendet!');
        self::assertSelectorTextContains('.lede', '„Mr Brightside“ ist in der Queue von '.$stageName);
        self::assertSelectorTextContains('a[href="/r/'.$event->getSlug().'"]', 'Noch einen Wunsch senden');
        self::assertSame(
            [['nickname' => 'Anna', 'ip_address' => self::IP]],
            $this->connection()->fetchAllAssociative('SELECT nickname, ip_address FROM request_votes'),
        );
        self::assertSame('The Killers', $this->connection()->fetchOne('SELECT artist FROM requests'));
    }

    public function test_blank_artist_and_nickname_are_stored_as_null(): void
    {
        $client = $this->client();
        $event = EventFactory::createOne();
        $this->asGuest($client);

        $this->submitRequest($client, $event->getSlug(), 'Mr Brightside');

        self::assertResponseStatusCodeSame(303);
        self::assertNull($this->connection()->fetchOne('SELECT artist FROM requests'));
        self::assertNull($this->connection()->fetchOne('SELECT nickname FROM request_votes'));
    }

    public function test_sent_page_with_tampered_title_hides_it(): void
    {
        $client = $this->client();
        $event = EventFactory::createOne();
        $this->asGuest($client);
        $this->submitRequest($client, $event->getSlug(), 'Mr Brightside');
        $location = (string) $client->getResponse()->headers->get('Location');

        $tampered = (string) preg_replace('/title=[^&]*/', 'title=Hacked', $location);
        self::assertNotSame($location, $tampered);

        $client->request('GET', $tampered);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Wunsch gesendet!');
        self::assertSelectorTextContains('.lede', 'Dein Wunsch ist in der Queue');
        self::assertStringNotContainsString('Hacked', (string) $client->getResponse()->getContent());
    }

    public function test_sent_page_without_signature_hides_title(): void
    {
        $client = $this->client();
        $event = EventFactory::createOne();

        $client->request('GET', '/r/'.$event->getSlug().'/sent?title=Hacked');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Hacked', (string) $client->getResponse()->getContent());
    }

    public function test_blank_title_re_renders_with_422_and_keeps_values(): void
    {
        $client = $this->client();
        $event = EventFactory::createOne();
        $this->asGuest($client);

        $this->submitRequest($client, $event->getSlug(), '   ', 'The Killers');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#g-title-error', 'Gib zuerst einen Songtitel ein.');
        self::assertSelectorExists('#g-title[aria-invalid="true"]');
        self::assertInputValueSame('song_request[artist]', 'The Killers');
        self::assertSame(0, $this->countRows('requests'));
    }

    public function test_too_long_title_re_renders_with_422(): void
    {
        $client = $this->client();
        $event = EventFactory::createOne();
        $this->asGuest($client);

        $this->submitRequest($client, $event->getSlug(), str_repeat('a', 201));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#g-title-error', 'höchstens 200 Zeichen');
    }

    public function test_cross_site_post_is_rejected(): void
    {
        $client = $this->client();
        $event = EventFactory::createOne();
        $this->asGuest($client);
        $crawler = $client->request('GET', '/r/'.$event->getSlug());
        $token = $crawler->filter('input[name="song_request[_token]"]')->attr('value');

        // Both headers explicitly: BrowserKit would otherwise send a same-origin Referer from its history.
        $client->request(
            'POST',
            '/r/'.$event->getSlug(),
            ['song_request' => ['title' => 'X', '_token' => $token]],
            [],
            ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_REFERER' => 'https://evil.example/'],
        );

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->countRows('requests'));
    }

    public function test_second_submit_within_cooldown_shows_minutes_left(): void
    {
        $client = $this->client();
        $event = EventFactory::createOne();
        $this->asGuest($client);
        $this->submitRequest($client, $event->getSlug(), 'Mr Brightside');

        $this->submitRequest($client, $event->getSlug(), 'Somebody Told Me');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[role="alert"]', 'Dein nächster Wunsch geht in 5 Minuten');
        self::assertInputValueSame('song_request[title]', 'Somebody Told Me');
        self::assertSame(1, $this->countRows('request_votes'));
    }

    public function test_guest_at_cap_sees_limit_message(): void
    {
        $client = $this->client();
        $event = EventFactory::createOne();
        $guest = GuestFactory::createOne();
        RequestVoteFactory::createMany(3, ['request' => SongRequestFactory::new(['event' => $event]), 'guest' => $guest]);
        $this->backdateVotes(10);
        $this->asGuest($client, $guest->getToken());

        $this->submitRequest($client, $event->getSlug(), 'Mr Brightside');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[role="alert"]', 'Das waren deine 3 Wünsche für heute Abend.');
    }

    public function test_ip_limit_shows_network_message(): void
    {
        $client = $this->client();
        $event = EventFactory::createOne();
        RequestVoteFactory::createMany(30, [
            'request' => SongRequestFactory::createOne(['event' => $event]),
            'ipAddress' => self::IP,
        ]);
        $this->asGuest($client);

        $this->submitRequest($client, $event->getSlug(), 'Mr Brightside');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[role="alert"]', 'zu viele Wünsche aus diesem Netzwerk');
    }

    public function test_duplicate_shows_success_and_adds_a_vote(): void
    {
        $client = $this->client();
        $event = EventFactory::createOne();
        $this->asGuest($client);
        $this->submitRequest($client, $event->getSlug(), 'Mr Brightside');
        $client->getCookieJar()->clear();
        $this->asGuest($client);

        $this->submitRequest($client, $event->getSlug(), 'mr brightside');

        self::assertResponseStatusCodeSame(303);
        $client->followRedirect();
        self::assertSelectorTextContains('.lede', '„mr brightside“');
        self::assertSame(1, $this->countRows('requests'));
        self::assertSame(2, (int) $this->connection()->fetchOne('SELECT votes FROM requests'));
    }

    public function test_muted_guest_sees_success_but_nothing_is_stored(): void
    {
        $client = $this->client();
        $event = EventFactory::createOne();
        $guest = GuestFactory::createOne();
        AccountGuestBlockFactory::createOne(['account' => $event->getAccount(), 'guest' => $guest]);
        $this->asGuest($client, $guest->getToken());

        $this->submitRequest($client, $event->getSlug(), 'Mr Brightside');

        self::assertResponseStatusCodeSame(303);
        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Wunsch gesendet!');
        self::assertSame(0, $this->countRows('requests'));
        self::assertSame(0, $this->countRows('request_votes'));
    }

    public function test_closed_event_shows_message_and_no_form(): void
    {
        $client = $this->client();
        $event = EventFactory::createOne();
        $this->changeStatus($event, Event::STATUS_CLOSED);

        $client->request('GET', '/r/'.$event->getSlug());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.closed-title', 'Wünsche sind gerade geschlossen');
        self::assertSelectorNotExists('form');
    }

    public function test_stopped_event_shows_message_and_no_form(): void
    {
        $client = $this->client();
        $event = EventFactory::createOne();
        $this->changeStatus($event, Event::STATUS_STOPPED);

        $client->request('GET', '/r/'.$event->getSlug());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.closed-title', 'Wünsche sind gerade geschlossen');
        self::assertSelectorNotExists('form');
    }

    public function test_forced_post_to_closed_event_is_rejected(): void
    {
        $client = $this->client();
        $event = EventFactory::createOne();
        $this->changeStatus($event, Event::STATUS_CLOSED);

        $client->request('POST', '/r/'.$event->getSlug(), ['song_request' => ['title' => 'X']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.closed-title', 'Wünsche sind gerade geschlossen');
        self::assertSame(0, $this->countRows('requests'));
    }

    public function test_unknown_slug_is_not_found(): void
    {
        $client = $this->client();

        $client->request('GET', '/r/nope');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/r/nope/sent');
        self::assertResponseStatusCodeSame(404);
    }

    public function test_guest_page_loads_no_js_framework_or_google_fonts(): void
    {
        $client = $this->client();
        $event = EventFactory::createOne();

        $client->request('GET', '/r/'.$event->getSlug());

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('fonts.googleapis.com', (string) $client->getResponse()->getContent());
        self::assertSelectorNotExists('link[rel="modulepreload"][href*="turbo"]');
        self::assertSelectorNotExists('link[rel="modulepreload"][href*="stimulus"]');
        self::assertSelectorNotExists('link[rel="modulepreload"][href*="controllers"]');
        self::assertSelectorExists('html[lang="de"]');
    }

    private function client(): KernelBrowser
    {
        $client = self::createClient();
        $client->setServerParameter('HTTP_X_FORWARDED_FOR', self::IP);

        return $client;
    }

    private function issuedGuestToken(KernelBrowser $client): ?Cookie
    {
        foreach ($client->getResponse()->headers->getCookies() as $cookie) {
            if ('guest_token' === $cookie->getName()) {
                return $cookie;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function responseCookiesOtherThanGuestToken(KernelBrowser $client): array
    {
        $names = array_map(static fn (Cookie $cookie) => $cookie->getName(), $client->getResponse()->headers->getCookies());

        return array_values(array_diff($names, ['guest_token']));
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

    private function backdateVotes(int $minutes): void
    {
        $this->connection()->executeStatement(
            'UPDATE request_votes SET created_at = created_at - make_interval(mins => :minutes)',
            ['minutes' => $minutes],
        );
    }
}
