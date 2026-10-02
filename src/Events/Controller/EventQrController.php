<?php

declare(strict_types=1);

namespace App\Events\Controller;

use App\Accounts\Entity\User;
use App\Events\Entity\Event;
use App\Events\Repository\EventRepository;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\Result\ResultInterface;
use Endroid\QrCode\Writer\SvgWriter;
use Endroid\QrCode\Writer\WriterInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The event's QR code: a full-screen, printable page plus PNG and SVG downloads (EV-3).
 * A closed event keeps its QR code, read-only.
 */
final class EventQrController extends AbstractController
{
    public function __construct(
        private readonly EventRepository $events,
    ) {
    }

    #[Route('/dj/events/{id}/qr', name: 'event_qr', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function page(string $id, #[CurrentUser] User $user): Response
    {
        $event = $this->findEvent($id, $user);

        return $this->render('events/dj/qr.html.twig', [
            'event' => $event,
            'guest_url' => $this->guestUrl($event),
            'data_uri' => $this->qr($event, new SvgWriter(), 600)->getDataUri(),
        ]);
    }

    #[Route('/dj/events/{id}/qr.png', name: 'event_qr_png', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function png(string $id, #[CurrentUser] User $user): Response
    {
        $event = $this->findEvent($id, $user);

        return $this->download($this->qr($event, new PngWriter(), 1024), 'qr-'.$event->getSlug().'.png');
    }

    #[Route('/dj/events/{id}/qr.svg', name: 'event_qr_svg', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function svg(string $id, #[CurrentUser] User $user): Response
    {
        $event = $this->findEvent($id, $user);

        return $this->download($this->qr($event, new SvgWriter(), 1024), 'qr-'.$event->getSlug().'.svg');
    }

    private function findEvent(string $id, User $user): Event
    {
        return $this->events->findOneForAccount($id, $user->getAccount()) ?? throw $this->createNotFoundException();
    }

    private function guestUrl(Event $event): string
    {
        // Built from the request, so it carries the public host behind the tunnel.
        // DEFAULT_URI is http://localhost, which phones cannot reach.
        return $this->generateUrl('guest_request', ['slug' => $event->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * @param array<string, mixed> $writerOptions
     */
    private function qr(Event $event, WriterInterface $writer, int $size, array $writerOptions = []): ResultInterface
    {
        return (new Builder(
            writer: $writer,
            writerOptions: $writerOptions,
            data: $this->guestUrl($event),
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $size,
            margin: 32,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        ))->build();
    }

    private function download(ResultInterface $result, string $filename): Response
    {
        return new Response($result->getString(), Response::HTTP_OK, [
            'Content-Type' => $result->getMimeType(),
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
        ]);
    }
}
