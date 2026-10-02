<?php

declare(strict_types=1);

namespace App\Requests\EventListener;

use App\Requests\ValueResolver\GuestTokenValueResolver;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Writes the guest token issued by GuestTokenValueResolver to the response.
 */
#[AsEventListener(KernelEvents::RESPONSE)]
final class GuestTokenCookieListener
{
    private const LIFETIME_SECONDS = 86400;

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $token = $event->getRequest()->attributes->get(GuestTokenValueResolver::NEW_TOKEN_ATTRIBUTE);
        if (!\is_string($token)) {
            return;
        }

        $event->getResponse()->headers->setCookie(Cookie::create(
            GuestTokenValueResolver::COOKIE,
            $token,
            time() + self::LIFETIME_SECONDS,
            '/',
            null,
            // Always Secure, not "auto": the tunnel terminates TLS before nginx, and AS-1
            // requires the flag even where the request PHP sees is plain http.
            true,
            true,
            false,
            Cookie::SAMESITE_LAX,
        ));
    }
}
