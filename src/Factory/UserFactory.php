<?php

declare(strict_types=1);

namespace App\Factory;

use App\Accounts\Entity\User;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<User>
 */
final class UserFactory extends PersistentObjectFactory
{
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
            // Placeholder until login lands; not a usable hash.
            'passwordHash' => '!',
        ];
    }
}
