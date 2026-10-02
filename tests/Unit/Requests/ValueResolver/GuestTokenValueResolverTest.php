<?php

declare(strict_types=1);

namespace App\Tests\Unit\Requests\ValueResolver;

use App\Requests\ValueResolver\GuestTokenValueResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\Uid\Uuid;

final class GuestTokenValueResolverTest extends TestCase
{
    public function test_returns_valid_cookie_token_without_issuing(): void
    {
        $token = Uuid::v4()->toRfc4122();
        $request = new Request(cookies: [GuestTokenValueResolver::COOKIE => $token]);

        self::assertSame([$token], $this->resolve($request));
        self::assertFalse($request->attributes->has(GuestTokenValueResolver::NEW_TOKEN_ATTRIBUTE));
    }

    public function test_issues_a_new_token_when_cookie_is_missing(): void
    {
        $request = new Request();

        $values = $this->resolve($request);

        self::assertCount(1, $values);
        self::assertTrue(Uuid::isValid($values[0]));
        self::assertSame($values[0], $request->attributes->get(GuestTokenValueResolver::NEW_TOKEN_ATTRIBUTE));
    }

    public function test_reissues_a_token_when_cookie_is_not_a_uuid(): void
    {
        $request = new Request(cookies: [GuestTokenValueResolver::COOKIE => 'not-a-uuid']);

        $values = $this->resolve($request);

        self::assertCount(1, $values);
        self::assertNotSame('not-a-uuid', $values[0]);
        self::assertTrue(Uuid::isValid($values[0]));
        self::assertSame($values[0], $request->attributes->get(GuestTokenValueResolver::NEW_TOKEN_ATTRIBUTE));
    }

    public function test_returns_the_same_new_token_when_resolved_twice(): void
    {
        $request = new Request();

        self::assertSame($this->resolve($request), $this->resolve($request));
    }

    public function test_ignores_non_string_arguments(): void
    {
        $resolver = new GuestTokenValueResolver();

        $values = $resolver->resolve(new Request(), new ArgumentMetadata('guestToken', 'int', false, false, null));

        self::assertSame([], [...$values]);
    }

    /**
     * @return list<string>
     */
    private function resolve(Request $request): array
    {
        $resolver = new GuestTokenValueResolver();

        return array_values([...$resolver->resolve($request, new ArgumentMetadata('guestToken', 'string', false, false, null))]);
    }
}
