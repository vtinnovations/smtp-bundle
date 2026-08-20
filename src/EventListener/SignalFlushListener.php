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

namespace Vtinnovations\SmtpBundle\EventListener;

use Contao\CoreBundle\Routing\ScopeMatcher;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Vtinnovations\SmtpBundle\Config\HostInventory;
use Vtinnovations\SmtpBundle\Http\SignalDispatcher;
use Vtinnovations\SmtpBundle\Service\EntitlementReader;

/**
 * Sends the queued signals after the response has already gone to the browser.
 *
 * On terminate for a reason: a signal is bookkeeping, and bookkeeping must never be able to slow a
 * page down, let alone fail one. If the endpoint is unreachable the visitor never finds out.
 *
 * The per-invocation event is raised here rather than on every request the site serves. "Relevant
 * invocation" means an invocation of *this* bundle — a backend request, or a push to the endpoint —
 * not every frontend hit on a busy site, which would be a signal about the site's traffic rather
 * than about the product being used.
 */
#[AsEventListener(event: TerminateEvent::class)]
final class SignalFlushListener
{
    public function __construct(
        private readonly SignalDispatcher $dispatcher,
        private readonly EntitlementReader $reader,
        private readonly HostInventory $hosts,
        private readonly ?ScopeMatcher $scopeMatcher = null,
    ) {
    }

    public function __invoke(TerminateEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if ($this->isRelevant($event->getRequest())) {
            $this->dispatcher->queueInvocation($this->host());
        }

        if ($this->dispatcher->hasQueued()) {
            $this->dispatcher->flush();
        }
    }

    private function isRelevant(Request $request): bool
    {
        if ('vtinnovations_smtp_remote_state' === $request->attributes->get('_route')) {
            return true;
        }

        return null !== $this->scopeMatcher && $this->scopeMatcher->isBackendRequest($request);
    }

    /**
     * The deterministic matched host, falling back to the configured one.
     *
     * Never the raw request host: what gets reported has to be an identity this installation is
     * configured to have, not one a header claimed for it.
     */
    private function host(): string
    {
        $matched = $this->reader->matchedHost();

        return '' !== $matched ? $matched : (string) $this->hosts->verificationHost();
    }
}
