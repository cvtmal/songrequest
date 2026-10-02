<?php

declare(strict_types=1);

namespace App\Story;

use App\Factory\AccountFactory;
use App\Factory\EventFactory;
use App\Factory\UserFactory;
use App\Requests\Command\SubmitSongRequest;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\AsFixture;
use Zenstruck\Foundry\Story;

#[AsFixture(name: 'main')]
final class AppStory extends Story
{
    /**
     * One vote per nickname, each from its own guest; null is a guest without a nickname.
     */
    private const VOTES = [
        ['Dreams', 'Fleetwood Mac', ['Lea', 'Nico', null]],
        ['Get Lucky', 'Daft Punk', ['Sam', null]],
        ['Mr. Brightside', 'The Killers', ['Mia']],
        ['Padam Padam', 'Kylie Minogue', [null]],
    ];

    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function build(): void
    {
        $account = AccountFactory::createOne(['stageName' => 'DJ Demo']);
        $event = EventFactory::createOne(['account' => $account, 'name' => 'Demo Night', 'slug' => 'demo']);

        $this->addState('account', $account);
        $this->addState('user', UserFactory::createOne(['account' => $account, 'email' => 'dj@example.com']));
        $this->addState('event', $event);

        // Through the real handler, so requests.votes, request_votes and guests stay consistent.
        // Fresh tokens and no IP keep the cooldown, cap and IP limits out of the way.
        foreach (self::VOTES as [$title, $artist, $nicknames]) {
            foreach ($nicknames as $nickname) {
                $this->bus->dispatch(new SubmitSongRequest($event->getId(), Uuid::v4()->toRfc4122(), $title, $artist, $nickname, null));
            }
        }
    }
}
