<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Asserts that a statement is rejected by a named database constraint.
 *
 * DBAL has no exception class for check violations on PostgreSQL, so those are matched
 * by SQLSTATE 23514 (check_violation) on the generic DriverException.
 */
trait ConstraintAssertions
{
    private static function assertUniqueViolation(string $constraint, \Closure $statement): void
    {
        try {
            $statement();
        } catch (UniqueConstraintViolationException $e) {
            self::assertStringContainsString(\sprintf('"%s"', $constraint), $e->getMessage());

            return;
        }

        self::fail(\sprintf('Expected unique constraint "%s" to reject the statement, but it succeeded.', $constraint));
    }

    private static function assertCheckViolation(string $constraint, \Closure $statement): void
    {
        try {
            $statement();
        } catch (DriverException $e) {
            self::assertSame('23514', $e->getSQLState(), $e->getMessage());
            self::assertStringContainsString(\sprintf('"%s"', $constraint), $e->getMessage());

            return;
        }

        self::fail(\sprintf('Expected check constraint "%s" to reject the statement, but it succeeded.', $constraint));
    }
}
