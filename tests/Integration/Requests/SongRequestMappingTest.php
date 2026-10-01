<?php

declare(strict_types=1);

namespace App\Tests\Integration\Requests;

use App\Factory\RequestVoteFactory;
use App\Factory\SongRequestFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class SongRequestMappingTest extends KernelTestCase
{
    public function test_persisting_reads_back_normalized_columns(): void
    {
        $request = SongRequestFactory::createOne(['title' => '  Mr.  Brightside ', 'artist' => ' ']);

        self::assertSame('mr. brightside', $request->getTitleNormalized());
        self::assertNull($request->getArtistNormalized());
        self::assertSame(1, $request->getVotes());
        self::assertSame('new', $request->getStatus());
    }

    public function test_vote_copies_tenant_columns_from_request(): void
    {
        $vote = RequestVoteFactory::createOne();

        self::assertSame($vote->getRequest()->getEvent(), $vote->getEvent());
        self::assertSame($vote->getRequest()->getAccount(), $vote->getAccount());

        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertSame(
            ['event_id' => $vote->getRequest()->getEvent()->getId(), 'account_id' => $vote->getRequest()->getAccount()->getId()],
            $connection->fetchAssociative('SELECT event_id, account_id FROM request_votes WHERE id = ?', [$vote->getId()]),
        );
    }
}
