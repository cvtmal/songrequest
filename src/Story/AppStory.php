<?php

declare(strict_types=1);

namespace App\Story;

use App\Factory\AccountFactory;
use App\Factory\EventFactory;
use App\Factory\UserFactory;
use Zenstruck\Foundry\Attribute\AsFixture;
use Zenstruck\Foundry\Story;

#[AsFixture(name: 'main')]
final class AppStory extends Story
{
    public function build(): void
    {
        $account = AccountFactory::createOne(['stageName' => 'DJ Demo']);

        $this->addState('account', $account);
        $this->addState('user', UserFactory::createOne(['account' => $account, 'email' => 'dj@example.com']));
        $this->addState('event', EventFactory::createOne(['account' => $account, 'name' => 'Demo Night', 'slug' => 'demo']));
    }
}
