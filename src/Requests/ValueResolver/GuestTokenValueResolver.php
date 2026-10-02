<?php

declare(strict_types=1);

namespace App\Requests\ValueResolver;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsTargetedValueResolver;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\Uid\Uuid;

/**
 * Hands a guest controller the anonymous guest token (AS-1).
 *
 * Targeted, so a token is only created on pages that ask for it, never on DJ pages.
 * A cookie that is not a UUID is replaced, so a tampered value never reaches the
 * GUID column `guests.token`. GuestTokenCookieListener writes the new token to the response.
 */
#[AsTargetedValueResolver]
final class GuestTokenValueResolver implements ValueResolverInterface
{
    public const COOKIE = 'guest_token';
    public const NEW_TOKEN_ATTRIBUTE = '_guest_token_new';

    /**
     * @return iterable<string>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if ('string' !== $argument->getType()) {
            return [];
        }

        $issued = $request->attributes->get(self::NEW_TOKEN_ATTRIBUTE);
        if (\is_string($issued)) {
            return [$issued];
        }

        $cookie = $request->cookies->get(self::COOKIE);
        if (\is_string($cookie) && Uuid::isValid($cookie)) {
            return [$cookie];
        }

        $token = Uuid::v4()->toRfc4122();
        $request->attributes->set(self::NEW_TOKEN_ATTRIBUTE, $token);

        return [$token];
    }
}
