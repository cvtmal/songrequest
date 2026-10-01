<?php

declare(strict_types=1);

namespace App\Tests\Application\Shared;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The static reference screens under /_design (config/routes/design.yaml)
 * render in every state, so a broken template or CSS class rename shows up here.
 */
final class DesignReferenceTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function screens(): iterable
    {
        yield 'gallery' => ['/_design', 'Guest and DJ, side by side'];
        yield 'guest form' => ['/_design/guest', 'What should I play next?'];
        yield 'guest error' => ['/_design/guest/error', 'Add a song title first.'];
        yield 'guest limit reached' => ['/_design/guest/limit-reached', 'That\'s your 3 for tonight'];
        yield 'guest sent' => ['/_design/guest/sent', 'Request sent'];
        yield 'guest duplicate' => ['/_design/guest/duplicate', 'Added your +1'];
        yield 'guest tipped' => ['/_design/guest/tipped', 'Sent and tipped'];
        yield 'guest closed' => ['/_design/guest/closed', 'Requests are closed for now'];
        yield 'dj queue' => ['/_design/dj-queue', 'Queue · 5'];
        yield 'dj done' => ['/_design/dj-queue/done', 'Midnight City'];
        yield 'dj paused' => ['/_design/dj-queue/paused', 'Paused'];
        yield 'dj muted' => ['/_design/dj-queue/muted', '1 guest muted'];
        yield 'dj empty' => ['/_design/dj-queue/empty', 'Nothing here yet.'];
        yield 'sign up' => ['/_design/auth', 'Take requests tonight'];
        yield 'log in' => ['/_design/auth/login', 'Welcome back'];
        yield 'events' => ['/_design/events', 'Coming up'];
        yield 'event settings' => ['/_design/event-settings', 'Your permanent QR code'];
        yield 'event settings paused' => ['/_design/event-settings/paused', 'Paused'];
        yield 'plan free' => ['/_design/plan', 'Subscribe with TWINT or card'];
        yield 'plan yearly' => ['/_design/plan/free-yearly', 'CHF 90'];
        yield 'plan pro' => ['/_design/plan/pro', 'Manage subscription'];
        yield 'get paid setup' => ['/_design/get-paid', 'Enter your bank details'];
        yield 'get paid active' => ['/_design/get-paid/active', 'Payouts active'];
    }

    #[DataProvider('screens')]
    public function test_screen_renders(string $url, string $expectedText): void
    {
        $client = self::createClient();

        $client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $expectedText);
    }

    public function test_unknown_state_is_not_found(): void
    {
        $client = self::createClient();

        $client->request('GET', '/_design/guest/nope');

        self::assertResponseStatusCodeSame(404);
    }
}
