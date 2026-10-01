<?php

declare(strict_types=1);

namespace App\Factory;

use App\Events\Entity\Event;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Event>
 */
final class EventFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Event::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'account' => AccountFactory::new(),
            'name' => self::faker()->words(3, true),
            'slug' => self::faker()->unique()->slug(2),
        ];
    }
}
