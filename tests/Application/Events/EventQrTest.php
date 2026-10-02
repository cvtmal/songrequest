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
final class EventQrTest extends WebTestCase
{
    use InteractsWithGuests;

    public function test_qr_page_shows_image_name_url_and_download_links(): void
    {
        [$client, $event] = $this->djWithEvent();

        $crawler = $client->request('GET', "/dj/events/{$event->getId()}/qr");

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('data:image/svg+xml', (string) $crawler->filter('img.qr-full')->attr('src'));
        self::assertSelectorTextContains('h1', 'Plaza Club');
        self::assertSame('http://localhost/r/'.$event->getSlug(), $crawler->filter('.qr-url')->text());
        self::assertStringEndsWith('/qr.png', (string) $crawler->selectLink('PNG herunterladen')->attr('href'));
        self::assertStringEndsWith('/qr.svg', (string) $crawler->selectLink('SVG herunterladen')->attr('href'));
        self::assertCount(1, $crawler->selectButton('Vollbild'));
        self::assertCount(1, $crawler->selectButton('Drucken'));
    }

    public function test_qr_url_follows_the_tunnel_host_and_forwarded_proto(): void
    {
        [$client, $event] = $this->djWithEvent();

        // How cloudflared reaches nginx: it keeps the public Host and adds the proto.
        $crawler = $client->request('GET', "/dj/events/{$event->getId()}/qr", server: [
            'REMOTE_ADDR' => '172.18.0.5',
            'HTTP_HOST' => 'abc.trycloudflare.com',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('https://abc.trycloudflare.com/r/'.$event->getSlug(), $crawler->filter('.qr-url')->text());
    }

    public function test_png_download_is_a_valid_png_attachment(): void
    {
        [$client, $event] = $this->djWithEvent();

        $client->request('GET', "/dj/events/{$event->getId()}/qr.png");

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertStringContainsString(
            'attachment; filename=qr-'.$event->getSlug().'.png',
            (string) $client->getResponse()->headers->get('Content-Disposition'),
        );
        $body = (string) $client->getResponse()->getContent();
        self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $body);
        $size = getimagesizefromstring($body);
        self::assertIsArray($size);
        self::assertSame($size[0], $size[1]);
        self::assertGreaterThanOrEqual(1024, $size[0]);
    }

    public function test_svg_download_is_a_valid_svg_attachment(): void
    {
        [$client, $event] = $this->djWithEvent();

        $client->request('GET', "/dj/events/{$event->getId()}/qr.svg");

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('image/svg+xml', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString(
            'qr-'.$event->getSlug().'.svg',
            (string) $client->getResponse()->headers->get('Content-Disposition'),
        );
        $svg = simplexml_load_string((string) $client->getResponse()->getContent());
        self::assertNotFalse($svg);
        self::assertSame('svg', $svg->getName());
    }

    public function test_closed_event_qr_page_still_works_and_says_closed(): void
    {
        [$client, $event] = $this->djWithEvent();
        $this->connection()->executeStatement(
            "UPDATE events SET status = 'closed', closed_at = CURRENT_TIMESTAMP WHERE id = :id",
            ['id' => $event->getId()],
        );
        // The first request shares the test's entity manager, which still holds the open event.
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();

        $client->request('GET', "/dj/events/{$event->getId()}/qr");

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.qr-closed', 'Dieses Event ist beendet');
    }

    public function test_other_accounts_event_returns_404_on_all_qr_routes(): void
    {
        [$client] = $this->djWithEvent();
        $foreign = EventFactory::createOne();

        foreach (['qr', 'qr.png', 'qr.svg'] as $path) {
            $client->request('GET', "/dj/events/{$foreign->getId()}/{$path}");
            self::assertResponseStatusCodeSame(404, $path);
        }
    }

    public function test_anonymous_visitor_is_redirected_to_login(): void
    {
        $client = self::createClient();
        $event = EventFactory::createOne();

        $client->request('GET', "/dj/events/{$event->getId()}/qr.png");

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
}
