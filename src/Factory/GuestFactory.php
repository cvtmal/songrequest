<?php

declare(strict_types=1);

namespace App\Factory;

use App\Requests\Entity\Guest;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Guest>
 */
final class GuestFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Guest::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            // A cookie value, not an entity ID, so it does not come from EntityId.
            'token' => Uuid::v4()->toRfc4122(),
        ];
    }
}
