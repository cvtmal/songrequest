<?php

declare(strict_types=1);

namespace App\Factory;

use App\Requests\Entity\RequestVote;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<RequestVote>
 */
final class RequestVoteFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return RequestVote::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'request' => SongRequestFactory::new(),
            'guest' => GuestFactory::new(),
            'nickname' => null,
        ];
    }
}
