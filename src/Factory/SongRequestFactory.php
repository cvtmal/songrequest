<?php

declare(strict_types=1);

namespace App\Factory;

use App\Requests\Entity\SongRequest;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<SongRequest>
 */
final class SongRequestFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return SongRequest::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'event' => EventFactory::new(),
            'title' => self::faker()->sentence(3),
            'artist' => self::faker()->name(),
        ];
    }
}
