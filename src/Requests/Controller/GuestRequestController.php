<?php

declare(strict_types=1);

namespace App\Requests\Controller;

use App\Events\Entity\Event;
use App\Events\Repository\EventRepository;
use App\Requests\Command\SubmitSongRequest;
use App\Requests\Exception\EventClosed;
use App\Requests\Exception\EventNotFound;
use App\Requests\Exception\GuestRequestLimitReached;
use App\Requests\Exception\IpRequestLimitReached;
use App\Requests\Exception\RequestCooldownActive;
use App\Requests\Exception\RequestsStopped;
use App\Requests\Form\SongRequestData;
use App\Requests\Form\SongRequestType;
use App\Requests\ValueResolver\GuestTokenValueResolver;
use App\Shared\Exception\DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The page a guest lands on after scanning the QR code (GR-1 to GR-5). Works without JS.
 */
final class GuestRequestController extends AbstractController
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly MessageBusInterface $bus,
        private readonly UriSigner $uriSigner,
        private readonly TranslatorInterface $translator,
        #[Autowire(param: 'requests.max_per_guest')]
        private readonly int $maxPerGuest,
    ) {
    }

    #[Route('/r/{slug}', name: 'guest_request', methods: ['GET', 'POST'])]
    public function request(
        Request $request,
        string $slug,
        #[ValueResolver(GuestTokenValueResolver::class)] string $guestToken,
    ): Response {
        $event = $this->findEvent($slug);
        $dj = $event->getAccount()->getStageName();

        if (Event::STATUS_OPEN !== $event->getStatus()) {
            return $this->closed($event, $dj, $request->isMethod('POST'));
        }

        $data = new SongRequestData();
        $form = $this->createForm(SongRequestType::class, $data);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderForm($form, $event, $dj);
        }

        try {
            $this->bus->dispatch(new SubmitSongRequest(
                $event->getId(),
                $guestToken,
                (string) $data->title,
                $data->artist,
                $data->nickname,
                $request->getClientIp(),
            ));
        } catch (HandlerFailedException $e) {
            // Keyed by handler name, not by position.
            $domainException = array_values($e->getWrappedExceptions(DomainException::class, true))[0] ?? null;
            if (null === $domainException) {
                throw $e;
            }

            return $this->rejection($domainException, $form, $event, $dj);
        }

        // Absolute, because checkRequest() hashes the scheme and host along with the path.
        $url = $this->generateUrl(
            'guest_request_sent',
            ['slug' => $slug, 'title' => $data->title],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return $this->redirect($this->uriSigner->sign($url), Response::HTTP_SEE_OTHER);
    }

    #[Route('/r/{slug}/sent', name: 'guest_request_sent', methods: ['GET'])]
    public function sent(Request $request, string $slug): Response
    {
        $event = $this->findEvent($slug);
        // Only a signed link may put a title on the DJ's page.
        $title = $this->uriSigner->checkRequest($request) ? $request->query->getString('title') : '';

        return $this->render('requests/guest/sent.html.twig', [
            'event' => $event,
            'dj' => $event->getAccount()->getStageName(),
            'title' => '' === $title ? null : $title,
        ]);
    }

    private function findEvent(string $slug): Event
    {
        return $this->events->findOneBySlug($slug) ?? throw $this->createNotFoundException();
    }

    private function rejection(DomainException $e, FormInterface $form, Event $event, string $dj): Response
    {
        $message = match (true) {
            // The event closed between the read above and the handler's lock.
            $e instanceof EventClosed, $e instanceof RequestsStopped => null,
            $e instanceof EventNotFound => throw $this->createNotFoundException(previous: $e),
            $e instanceof GuestRequestLimitReached => $this->translator->trans('guest.error.limit', ['max' => $this->maxPerGuest]),
            $e instanceof RequestCooldownActive => $this->translator->trans(
                'guest.error.cooldown',
                ['minutes' => (int) ceil($e->getSecondsLeft() / 60)],
            ),
            $e instanceof IpRequestLimitReached => $this->translator->trans('guest.error.ip_limit'),
            default => throw $e,
        };

        if (null === $message) {
            return $this->closed($event, $dj, true);
        }

        $form->addError(new FormError($message));

        return $this->renderForm($form, $event, $dj, new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    private function renderForm(FormInterface $form, Event $event, string $dj, ?Response $response = null): Response
    {
        return $this->render('requests/guest/form.html.twig', [
            'form' => $form,
            'event' => $event,
            'dj' => $dj,
            'max_per_guest' => $this->maxPerGuest,
        ], $response);
    }

    private function closed(Event $event, string $dj, bool $rejectedPost): Response
    {
        return $this->render('requests/guest/closed.html.twig', [
            'event' => $event,
            'dj' => $dj,
        ], new Response(status: $rejectedPost ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
