<?php

declare(strict_types=1);

namespace App\Factory;

use App\Accounts\Entity\Account;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Account>
 */
final class AccountFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Account::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'stageName' => 'DJ '.self::faker()->firstName(),
        ];
    }
}
