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

namespace Vtinnovations\SmtpBundle\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Vtinnovations\SmtpBundle\Config\DeploymentProfile;
use Vtinnovations\SmtpBundle\Http\SignalDispatcher;

/**
 * Announces, once, that someone opened the protected backend module in this session.
 *
 * Bound to entering the module, not to evaluating entitlement. Entitlement is read on all sorts of
 * occasions — a command, a queued job, a second service asking the same question — and none of
 * those are a person opening the screen.
 *
 * "Once" means once per authenticated backend session, not once per process. A process-static flag
 * would fire again on every worker, and a stored flag would never fire again at all. The claim
 * lives in the session and therefore resets naturally on logout, expiry or a new login.
 *
 * Parallel tabs race for the same claim and only one wins: PHP's session handler holds an exclusive
 * lock on the session for the duration of a request, so two requests in the same session cannot
 * both read it as unclaimed.
 *
 * The claim records the project slug and nothing else. No key, no host, no session identifier, no
 * payload — a marker that leaks what it is marking is not much of an improvement on logging it.
 */
final class ModuleEntrySignal
{
    private const SESSION_KEY = '_vtinnovations_smtp_entry';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly EntitlementReader $reader,
        private readonly SignalDispatcher $dispatcher,
    ) {
    }

    public function onModuleEntry(): void
    {
        $session = $this->session();

        if (null === $session) {
            return;
        }

        $claimed = $session->get(self::SESSION_KEY, []);
        $claimed = \is_array($claimed) ? $claimed : [];

        if (\in_array(DeploymentProfile::PROJECT_SLUG, $claimed, true)) {
            return;
        }

        // Only a cryptographically authenticated record may supply this. An expired or wrong-host
        // record is still an authentic one, so the key is available even when entitlement is
        // withheld — but a tampered or absent record yields nothing, and then nothing is sent and
        // nothing is claimed.
        $key = $this->reader->authenticatedKey();

        if (null === $key) {
            return;
        }

        $host = $this->reader->matchedHost();

        if ('' === $host) {
            return;
        }

        // Claimed before delivery, deliberately. A timeout must not turn into a second attempt
        // later in the same session.
        $claimed[] = DeploymentProfile::PROJECT_SLUG;
        $session->set(self::SESSION_KEY, array_values(array_unique($claimed)));

        $this->dispatcher->queueModuleEntry($host, $key);
    }

    private function session(): ?SessionInterface
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request || !$request->hasSession()) {
            return null;
        }

        return $request->getSession();
    }
}
