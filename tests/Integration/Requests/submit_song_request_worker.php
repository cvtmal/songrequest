<?php

declare(strict_types=1);

/*
 * One concurrent submitter for SubmitSongRequestConcurrencyTest.
 *
 * Usage: php submit_song_request_worker.php <eventId> <token> <title> <artist> <ip> <startAt>
 * An empty artist or ip means null. startAt is a unix time (float) shared by every worker,
 * so the dispatches overlap. Prints "accepted" or the short class name of the domain exception.
 */

use App\Kernel;
use App\Requests\Command\SubmitSongRequest;
use App\Shared\Exception\DomainException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

require dirname(__DIR__, 3).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__, 3).'/.env');

[, $eventId, $token, $title, $artist, $ip, $startAt] = $_SERVER['argv'];

// Same environment and debug flag as the PHPUnit kernel, so the compiled test container is reused.
$kernel = new Kernel('test', (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
assert($container instanceof ContainerInterface);
$bus = $container->get(MessageBusInterface::class);
assert($bus instanceof MessageBusInterface);

if (microtime(true) < (float) $startAt) {
    time_sleep_until((float) $startAt);
}

try {
    $bus->dispatch(new SubmitSongRequest($eventId, $token, $title, '' === $artist ? null : $artist, null, '' === $ip ? null : $ip));
    echo 'accepted';
} catch (HandlerFailedException $e) {
    $wrapped = $e->getWrappedExceptions()[array_key_first($e->getWrappedExceptions())];
    if (!$wrapped instanceof DomainException) {
        echo 'error: '.$wrapped->getMessage();
        exit(1);
    }

    echo (new ReflectionClass($wrapped))->getShortName();
} catch (Throwable $e) {
    echo 'error: '.$e->getMessage();
    exit(1);
}
