---
paths:
  - "src/**/Controller/**/*.php"
  - "src/**/Command/**/*.php"
  - "src/**/CommandHandler/**/*.php"
  - "src/**/Console/**/*.php"
  - "config/packages/messenger.yaml"
---

# Commands and handlers

There is exactly one bus, `command.bus` (default bus, wrapped in a Doctrine transaction). There is no query bus.

## Writes

1. The controller (or console command) validates input, then builds a command and dispatches it.
2. The handler loads entities, applies business rules, persists.
3. The transaction middleware flushes and commits; a thrown exception rolls everything back.

## Commands (`<Module>/Command`)

- `final readonly class` with public constructor-promoted properties.
- Only scalars, IDs (strings) and small value objects — no entities, no services. They must serialise for the `async` transport.
- No validator attributes. Input validation belongs to the form data class; business rules belong to the handler.

## Handlers (`<Module>/CommandHandler`)

- `final class`, implements `App\Shared\Messenger\CommandHandlerInterface`, single `__invoke(TheCommand $command)`.
- Implementing the marker is what registers the handler on `command.bus` (`_instanceof` in `config/services.yaml`). Do not add `#[AsMessageHandler]`.
- Return `void`, or the new entity's ID string when the caller needs it (read it from `HandledStamp`).
- Throw a `DomainException` subclass for any business-rule violation.

## Controllers and console commands

- Never call `persist()` / `flush()` and never inject `EntityManagerInterface`.
- Never call a handler directly; dispatch through `MessageBusInterface`.
- Read data straight from repositories — that is the read side.

## Async messages

Route a message to `async` in `config/packages/messenger.yaml` only when the caller does not need the result (mail, webhooks follow-ups). The `worker` service consumes it; failures land in the `failed` transport after 3 retries.
