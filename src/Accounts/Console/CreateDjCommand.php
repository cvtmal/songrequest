<?php

declare(strict_types=1);

namespace App\Accounts\Console;

use App\Accounts\Command\CreateDjAccount;
use App\Accounts\Exception\EmailAlreadyRegistered;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(name: 'app:create-dj', description: 'Create a DJ account (until signup lands in #10)')]
final class CreateDjCommand
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Login email')] string $email,
        #[Argument('Name shown to guests', name: 'stage-name')] string $stageName,
    ): int {
        $violations = $this->validator->validate($email, [new NotBlank(), new Email(), new Length(max: 180)]);
        $violations->addAll($this->validator->validate($stageName, [new NotBlank(), new Length(max: 100)]));
        if (\count($violations) > 0) {
            return $this->fail($io, $violations);
        }

        $password = (string) $io->askHidden('Password (at least 10 characters)');
        if ($password !== (string) $io->askHidden('Repeat the password')) {
            $io->error('The passwords do not match.');

            return Command::FAILURE;
        }
        $violations = $this->validator->validate($password, [new Length(min: 10)]);
        if (\count($violations) > 0) {
            return $this->fail($io, $violations);
        }

        try {
            $this->bus->dispatch(new CreateDjAccount($email, $stageName, $password));
        } catch (HandlerFailedException $e) {
            $domainException = array_values($e->getWrappedExceptions(EmailAlreadyRegistered::class, true))[0] ?? null;
            if (null === $domainException) {
                throw $e;
            }
            $io->error($domainException->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Created DJ "%s" <%s>.', trim($stageName), mb_strtolower(trim($email))));

        return Command::SUCCESS;
    }

    private function fail(SymfonyStyle $io, ConstraintViolationListInterface $violations): int
    {
        $messages = [];
        foreach ($violations as $violation) {
            $messages[] = (string) $violation->getMessage();
        }
        $io->error($messages);

        return Command::FAILURE;
    }
}
