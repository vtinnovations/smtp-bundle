<?php

/*
 * SMTP Konfigurator
 *
 * Package: vtinnovations/smtp-bundle
 * Copyright: VT Innovations Team
 * Licence: LGPL-3.0-or-later
 * Website: https://github.com/vtinnovations/smtp-bundle
 */

declare(strict_types=1);

namespace Vtinnovations\SmtpBundle\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Vtinnovations\SmtpBundle\Config\DeploymentProfile;
use Vtinnovations\SmtpBundle\Service\PushedUpdateHandler;
use Vtinnovations\SmtpBundle\Service\PushOutcome;

/**
 * The public entry point the issuer pushes state changes to.
 *
 * Kept as thin as it can be. It enforces the shape of the request — method, media type, size — and
 * hands everything else over. It holds no key material, no endpoint, no checksum logic, no domain
 * policy and no persistence, and it cannot name a file: nothing here takes a path, a filename or
 * anything else from the request that could steer where bytes land.
 *
 * Anonymous by necessity and authenticated by signature. There is no session with the issuer to
 * check and no browser involved, so CSRF has nothing to protect here — which is exactly why the
 * signature has to carry the whole weight.
 */
class RemoteStateController
{
    public function __construct(private readonly PushedUpdateHandler $handler)
    {
    }

    // Every method is routed, not only POST: a GET has to answer 405 rather than 404, so an operator
    // can confirm the endpoint exists without being able to drive it.
    #[Route(
        '/rest/api/v1/'.DeploymentProfile::PROJECT_SLUG.'-license-updater',
        name: 'vtinnovations_smtp_remote_state',
        defaults: ['_scope' => 'frontend', '_token_check' => false],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        if (!$request->isMethod('POST')) {
            return new JsonResponse(['status' => 'method_not_allowed'], 405, ['Allow' => 'POST']);
        }

        if (!str_starts_with(strtolower((string) $request->headers->get('Content-Type', '')), 'application/json')) {
            return new JsonResponse(['status' => 'unsupported_media_type'], 415);
        }

        $body = (string) $request->getContent();

        // Capped before anything parses it. A record and its envelope; anything larger is not one.
        if (\strlen($body) > DeploymentProfile::MAX_INBOUND_BYTES) {
            return new JsonResponse(['status' => 'payload_too_large'], 413);
        }

        $outcome = $this->handler->handle(
            $request->getMethod(),
            $request->getPathInfo(),
            [
                'request_id' => (string) $request->headers->get('X-VT-Request-ID', ''),
                'timestamp'  => (string) $request->headers->get('X-VT-Timestamp', ''),
                'nonce'      => (string) $request->headers->get('X-VT-Nonce', ''),
                'key_id'     => (string) $request->headers->get('X-VT-Key-ID', ''),
                'signature'  => (string) $request->headers->get('X-VT-Signature', ''),
            ],
            $body,
        );

        if (PushOutcome::UNAUTHORIZED === $outcome->status) {
            // One shape for every refusal, so nothing can be learned from which one came back.
            return new JsonResponse(['status' => 'unauthorized'], 401);
        }

        $payload = ['status' => $outcome->status, 'request_id' => $outcome->requestId];

        if (PushOutcome::ERROR !== $outcome->status) {
            $payload['license_version'] = $outcome->version;
        }

        return new JsonResponse($payload, $outcome->httpStatus);
    }
}
