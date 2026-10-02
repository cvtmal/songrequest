<?php

declare(strict_types=1);

namespace App\Tests\Application;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Uid\Uuid;

/**
 * Drives the guest request page (/r/{slug}) as an anonymous guest.
 */
trait InteractsWithGuests
{
    /**
     * Puts a guest_token cookie in the client's jar and returns the token.
     */
    protected function asGuest(KernelBrowser $client, ?string $token = null): string
    {
        $token ??= Uuid::v4()->toRfc4122();
        // Not secure: BrowserKit does not send a hand-made secure cookie over http.
        $client->getCookieJar()->set(new Cookie('guest_token', $token));

        return $token;
    }

    /**
     * GETs the page first, so the POST carries a same-origin Referer for stateless CSRF.
     */
    protected function submitRequest(
        KernelBrowser $client,
        string $slug,
        string $title,
        ?string $artist = null,
        ?string $nickname = null,
    ): void {
        $client->request('GET', '/r/'.$slug);
        $client->submitForm('Wunsch senden', [
            'song_request[title]' => $title,
            'song_request[artist]' => $artist ?? '',
            'song_request[nickname]' => $nickname ?? '',
        ]);
    }

    protected function countRows(string $table): int
    {
        return (int) $this->connection()->fetchOne(\sprintf('SELECT count(*) FROM %s', $table));
    }

    protected function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
