<?php

declare(strict_types=1);

namespace App\Factory;

use App\Accounts\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<User>
 */
final class UserFactory extends PersistentObjectFactory
{
    public function __construct(private readonly PasswordHasherFactoryInterface $hasherFactory)
    {
        parent::__construct();
    }

    public static function class(): string
    {
        return User::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'account' => AccountFactory::new(),
            'email' => self::faker()->unique()->safeEmail(),
            // Dev/test only; Foundry is never installed in production.
            'password' => 'password',
        ];
    }

    protected function initialize(): static
    {
        return $this->beforeInstantiate(function (array $attributes): array {
            // An explicit passwordHash wins over the plain password.
            $attributes['passwordHash'] ??= $this->hasherFactory->getPasswordHasher(User::class)->hash((string) $attributes['password']);
            unset($attributes['password']);

            return $attributes;
        });
    }
}
