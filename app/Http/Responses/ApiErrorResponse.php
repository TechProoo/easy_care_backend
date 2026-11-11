<?php

namespace App\Http\Responses;

use App\Exceptions\WriteConflict;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ApiErrorResponse
{
    public function __invoke(Response $response, Throwable $exception, Request $request): Response
    {
        if (! $request->is('api', 'api/*') || $response->getStatusCode() < 400) {
            return $response;
        }

        $status = $response->getStatusCode();
        [$code, $message] = match ($status) {
            400 => ['bad_request', 'The request could not be understood.'],
            401 => ['unauthenticated', 'Please sign in to continue.'],
            403 => ['forbidden', 'You do not have permission to perform this action.'],
            404 => ['not_found', 'The requested resource was not found.'],
            405 => ['method_not_allowed', 'This HTTP method is not supported for this resource.'],
            409 => ['conflict', 'The request conflicts with the current record state.'],
            413 => ['payload_too_large', 'The upload is larger than the server allows.'],
            415 => ['unsupported_media_type', 'The request content type is not supported.'],
            419 => ['session_expired', 'Your session has expired. Please sign in again.'],
            422 => ['validation_failed', 'The submitted data is invalid.'],
            429 => ['too_many_requests', 'Too many requests. Please try again later.'],
            503 => ['service_unavailable', 'The service is temporarily unavailable.'],
            default => $status >= 500
                ? ['server_error', 'An unexpected error occurred.']
                : ['request_failed', 'The request could not be completed.'],
        };

        if ($exception instanceof WriteConflict) {
            $code = $exception->errorCode;
            $message = $exception->getMessage();
        }

        $payload = ['message' => $message, 'code' => $code];

        if ($exception instanceof ValidationException) {
            $payload['errors'] = $exception->errors();
        }

        $headers = $response->headers->all();
        unset($headers['content-type'], $headers['content-length']);
        $headers['Cache-Control'] = 'no-store, private';

        return new JsonResponse($payload, $status, $headers);
    }
}
