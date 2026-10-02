<?php

declare(strict_types=1);

namespace App\Tests\Unit\Requests\EventListener;

use App\Requests\EventListener\GuestTokenCookieListener;
use App\Requests\ValueResolver\GuestTokenValueResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class GuestTokenCookieListenerTest extends TestCase
{
    private const TOKEN = '0b9f6a1e-6c1d-4f43-9a51-2f8c7a3d5e10';

    public function test_sets_cookie_with_as1_flags_when_a_token_was_issued(): void
    {
        $event = $this->responseEvent(self::TOKEN);

        (new GuestTokenCookieListener())($event);

        $cookies = $event->getResponse()->headers->getCookies();
        self::assertCount(1, $cookies);
        $cookie = $cookies[0];
        self::assertSame(GuestTokenValueResolver::COOKIE, $cookie->getName());
        self::assertSame(self::TOKEN, $cookie->getValue());
        self::assertTrue($cookie->isSecure());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('lax', $cookie->getSameSite());
        self::assertSame('/', $cookie->getPath());
        self::assertGreaterThanOrEqual(86399, $cookie->getMaxAge());
        self::assertLessThanOrEqual(86400, $cookie->getMaxAge());
    }

    public function test_sets_no_cookie_without_a_new_token(): void
    {
        $event = $this->responseEvent(null);

        (new GuestTokenCookieListener())($event);

        self::assertSame([], $event->getResponse()->headers->getCookies());
    }

    public function test_sets_no_cookie_on_sub_requests(): void
    {
        $event = $this->responseEvent(self::TOKEN, HttpKernelInterface::SUB_REQUEST);

        (new GuestTokenCookieListener())($event);

        self::assertSame([], $event->getResponse()->headers->getCookies());
    }

    private function responseEvent(?string $token, int $type = HttpKernelInterface::MAIN_REQUEST): ResponseEvent
    {
        $request = new Request();
        if (null !== $token) {
            $request->attributes->set(GuestTokenValueResolver::NEW_TOKEN_ATTRIBUTE, $token);
        }

        return new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, $type, new Response());
    }
}
