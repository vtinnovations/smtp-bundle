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

namespace Vtinnovations\SmtpBundle\Controller\Backend;

use Contao\BackendUser;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Contracts\Translation\TranslatorInterface;
use Vtinnovations\SmtpBundle\Config\HostInventory;
use Vtinnovations\SmtpBundle\Service\ProvisioningOutcome;
use Vtinnovations\SmtpBundle\Service\ProvisioningService;

/**
 * The three things the settings section can ask for: activate a key, fetch a fresh copy of the one
 * already installed, or discard it.
 *
 * Two independent gates before anything happens. The screen these buttons live on is admin-only, so
 * this must be too — a plain authenticated backend user must not be able to activate or discard the
 * instance state by posting here directly. And the POST has to carry a valid Contao request token,
 * which is checked here at the feature boundary as well as by the blanket listener, because a
 * state-changing action is worth an explicit, auditable check of its own.
 *
 * Whatever happens, the answer is a redirect back to Settings with a flash message: the section
 * there re-renders from freshly evaluated state, so there is exactly one place that decides what
 * this installation's status reads as.
 */
#[Route(
    '/vtone-packages/smtp/{action}',
    name: 'vtinnovations_smtp_instance_action',
    defaults: ['_scope' => 'backend'],
    requirements: ['action' => 'activate|refresh|remove'],
    methods: ['POST'],
)]
final class ProvisioningActionController
{
    public function __construct(
        private readonly ProvisioningService $provisioning,
        private readonly HostInventory $hosts,
        private readonly TranslatorInterface $translator,
        // Contao's own cookie-based manager. The generic Symfony CSRF interface autowires to the
        // session-based `security.csrf.token_manager`, which the blanket RequestTokenListener
        // never checks against.
        private readonly ContaoCsrfTokenManager $csrfTokenManager,
        private readonly string $csrfTokenName,
        private readonly UrlGeneratorInterface $router,
        private readonly Security $security,
        private readonly ContaoFramework $framework,
    ) {
    }

    public function __invoke(Request $request, string $action): Response
    {
        $this->framework->initialize();

        $user = $this->security->getUser();

        if (!$user instanceof BackendUser || !$user->isAdmin) {
            return new Response($this->trans('panel.forbidden'), Response::HTTP_FORBIDDEN);
        }

        $token = new CsrfToken($this->csrfTokenName, (string) $request->request->get('REQUEST_TOKEN', ''));

        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return new Response($this->trans('panel.invalid_token'), Response::HTTP_FORBIDDEN);
        }

        [$ok, $message] = $this->perform($action, (string) $request->request->get('license_key', ''));

        if ($request->hasSession()) {
            $request->getSession()->getFlashBag()->add($ok ? 'contao.BE.confirm' : 'contao.BE.error', $message);
        }

        return new RedirectResponse($this->router->generate('contao_backend', ['do' => 'settings']));
    }

    /** @return array{bool, string} */
    private function perform(string $action, string $key): array
    {
        // Removal is not an exchange and cannot fail: an operator with backend access to this
        // installation is already trusted to turn the package off.
        if ('remove' === $action) {
            $this->provisioning->remove();

            return [true, $this->trans('panel.msg_removed')];
        }

        $outcome = 'refresh' === $action
            ? $this->provisioning->refresh()
            : $this->provisioning->activate($key);

        if (!$outcome->succeeded()) {
            return [false, $this->outcomeMessage($outcome)];
        }

        return [true, $this->trans('refresh' === $action ? 'panel.msg_refreshed' : 'panel.msg_activated')];
    }

    /**
     * Coarse on purpose, and chosen locally: nothing the remote side wrote is ever rendered in the
     * backend. An admin needs to know what to do next; spelling out which check failed would mostly
     * help someone probing the validation.
     */
    private function outcomeMessage(ProvisioningOutcome $outcome): string
    {
        return $this->trans(match ($outcome->code) {
            ProvisioningOutcome::NO_KEY               => 'panel.msg_no_key',
            ProvisioningOutcome::NO_CONFIGURED_DOMAIN => 'status.no_configured_domain',
            ProvisioningOutcome::UNAVAILABLE          => 'error.unavailable',
            ProvisioningOutcome::ROLLBACK_REFUSED     => 'error.rollback',
            ProvisioningOutcome::STORE_FAILED         => 'error.store_failed',
            default                                   => 'error.refused',
        }, ['%domains%' => implode(', ', $this->hosts->configuredHosts())]);
    }

    /** @param array<string, string> $parameters */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'vtinnovations_smtp');
    }
}
