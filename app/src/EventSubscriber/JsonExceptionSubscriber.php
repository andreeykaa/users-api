<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Throwable;

class JsonExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 100],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        // Handle only API errors
        if (!str_starts_with($request->getPathInfo(), '/v1/api')) {
            return;
        }

        $exception = $event->getThrowable();
        $statusCode = $this->getStatusCode($exception, $request);
        $message = $this->getSafeMessage($statusCode);

        $response = new JsonResponse([
            'error' => $message,
            'status' => $statusCode,
        ], $statusCode);

        $event->setResponse($response);
    }

    private function getStatusCode(Throwable $exception, Request $request): int
    {
        // No valid authentication token
        if ($exception instanceof AuthenticationException) {
            return Response::HTTP_UNAUTHORIZED;
        }

        // Access is denied.
        // If there is no Authorization header, treat it as unauthenticated.
        if ($exception instanceof AccessDeniedException) {
            return $request->headers->has('Authorization')
                ? Response::HTTP_FORBIDDEN
                : Response::HTTP_UNAUTHORIZED;
        }

        // Symfony HTTP exceptions already contain the correct status code
        if ($exception instanceof HttpExceptionInterface) {
            return $exception->getStatusCode();
        }

        // Any other unexpected error
        return Response::HTTP_INTERNAL_SERVER_ERROR;
    }

    private function getSafeMessage(int $statusCode): string
    {
        if ($statusCode >= 500) {
            return 'Internal server error';
        }

        return Response::$statusTexts[$statusCode] ?? 'Error';
    }
}
