---
paths:
  - "src/**/*.php"
  - "tests/**/*.php"
---

# PHP architecture

## Every file

- Starts with `declare(strict_types=1);` (PHP-CS-Fixer adds it).
- One class per file, namespace mirrors the path under `src/` (`App\`) or `tests/` (`App\Tests\`).

## Modules and layers

Code lives in `src/<Module>/<Layer>/`. Modules: `Accounts`, `Events`, `Requests`, `Billing`, `Tips`, and `Shared` for cross-cutting building blocks.

| Layer | Holds |
|---|---|
| `Controller` | HTTP entry points |
| `Command` | Command DTOs dispatched on the bus |
| `CommandHandler` | One handler per command |
| `Entity` | Doctrine entities |
| `Repository` | Doctrine repositories (also the read side) |
| `Exception` | Business errors of the module |
| `Form` | Form types and form data classes |
| `ValueResolver` | Controller argument resolvers |
| `EventListener` | Kernel and Doctrine event listeners |
| `Console` | Console commands (`#[AsCommand]`, invokable) |

Do not invent new layer names without updating `tests/Architecture/` — the rules match these folder names.

## Class modifiers (checked by `ClassModifiersTest`)

| Kind | final | readonly | Other |
|---|---|---|---|
| Controller | yes | — | extends `AbstractController` |
| Command | yes | yes | |
| CommandHandler | yes | — | invokable, implements `CommandHandlerInterface` |
| Exception | yes (abstract bases excepted) | — | extends `App\Shared\Exception\DomainException` |
| Entity | **no** | **no** | Doctrine proxies and hydrates them |
| Repository | — | — | extends `ServiceEntityRepository` |
| ValueResolver | yes | — | |
| EventListener | yes | — | |
| Console | yes | — | invokable, `#[AsCommand]` |

Anything else (services, value objects, form types) is `final` by default.

## Layer isolation (checked by `LayerIsolationTest`)

- Controllers never reference or instantiate handlers; they dispatch commands.
- Handlers never reference controllers.
- Commands never reference entities or repositories — they carry scalars and IDs.
- Entities never reference controllers, handlers or commands.
- Console commands never reference handlers; they dispatch commands.

## Infrastructure access (checked by `InfrastructureAccessTest`)

- Controllers never use `EntityManagerInterface`.
- Console commands never use `EntityManagerInterface`.
- Module code never references `App\Tests`, `App\DataFixtures`, `App\Story` or Foundry.

## Module dependencies (checked by `ModuleBoundariesTest`)

| Module | May depend on |
|---|---|
| Shared | — |
| Accounts | Shared |
| Events | Accounts, Shared |
| Requests | Events, Accounts, Shared |
| Billing | Accounts, Shared |
| Tips | Requests, Events, Accounts, Shared |

If a feature seems to need a forbidden direction, put an interface in the lower module (or `Shared`) and implement it in the higher one. Changing the matrix is a deliberate decision: update the table here, in `CLAUDE.md` and in the test together.

## Exceptions

```php
final class EventClosed extends DomainException
{
    public static function forEvent(string $eventId): self
    {
        return new self(sprintf('Event "%s" no longer accepts requests.', $eventId));
    }
}
```

Throw with a named constructor; keep message text inside the exception class.
