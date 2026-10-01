<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Allowed module dependencies (everything may use Shared):
 *
 *   Accounts → (nothing)
 *   Events   → Accounts
 *   Requests → Events, Accounts
 *   Billing  → Accounts
 *   Tips     → Requests, Events, Accounts
 */
final class ModuleBoundariesTest extends ArchitectureRules
{
    public function test_shared_depends_on_no_module(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Shared'))
            ->shouldNot()->dependOn()
            ->classes(
                Selector::inNamespace('App\Accounts'),
                Selector::inNamespace('App\Events'),
                Selector::inNamespace('App\Requests'),
                Selector::inNamespace('App\Billing'),
                Selector::inNamespace('App\Tips'),
            )
            ->because('Shared holds building blocks only; every module may use it.');
    }

    public function test_accounts_depends_only_on_shared(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Accounts'))
            ->shouldNot()->dependOn()
            ->classes(
                Selector::inNamespace('App\Events'),
                Selector::inNamespace('App\Requests'),
                Selector::inNamespace('App\Billing'),
                Selector::inNamespace('App\Tips'),
            )
            ->because('Accounts is the base module the others build on.');
    }

    public function test_events_does_not_depend_on_requests_billing_or_tips(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Events'))
            ->shouldNot()->dependOn()
            ->classes(
                Selector::inNamespace('App\Requests'),
                Selector::inNamespace('App\Billing'),
                Selector::inNamespace('App\Tips'),
            )
            ->because('Events may only use Accounts.');
    }

    public function test_requests_does_not_depend_on_billing_or_tips(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Requests'))
            ->shouldNot()->dependOn()
            ->classes(
                Selector::inNamespace('App\Billing'),
                Selector::inNamespace('App\Tips'),
            )
            ->because('Requests may only use Events and Accounts.');
    }

    public function test_billing_does_not_depend_on_events_requests_or_tips(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Billing'))
            ->shouldNot()->dependOn()
            ->classes(
                Selector::inNamespace('App\Events'),
                Selector::inNamespace('App\Requests'),
                Selector::inNamespace('App\Tips'),
            )
            ->because('Billing may only use Accounts.');
    }

    public function test_tips_does_not_depend_on_billing(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Tips'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('App\Billing'))
            ->because('Tips may only use Requests, Events and Accounts.');
    }
}
