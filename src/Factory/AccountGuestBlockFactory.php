<?php

declare(strict_types=1);

namespace App\Factory;

use App\Requests\Entity\AccountGuestBlock;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<AccountGuestBlock>
 */
final class AccountGuestBlockFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return AccountGuestBlock::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'account' => AccountFactory::new(),
            'guest' => GuestFactory::new(),
        ];
    }
}
