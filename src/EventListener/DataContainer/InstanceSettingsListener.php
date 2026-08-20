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

namespace Vtinnovations\SmtpBundle\EventListener\DataContainer;

use Contao\Config;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\DataContainer;
use Contao\Date;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Vtinnovations\SmtpBundle\Config\HostInventory;
use Vtinnovations\SmtpBundle\Config\RuntimeState;
use Vtinnovations\SmtpBundle\Service\Entitlement;
use Vtinnovations\SmtpBundle\Service\EntitlementReader;

/**
 * The instance-wide settings section: one glance at where this installation stands, and the three
 * things an administrator can do about it.
 *
 * Registered as the `tl_settings` `vtinnovations_smtp_licence` `input_field_callback` — see
 * `contao/dca/tl_settings.php` — rather than as a set of ordinary DCA fields. Three separate
 * fields could only ever be as expressive as the widgets Contao happens to offer, and the state
 * worth showing here is a paragraph, not a string: which package, which host out of which signed
 * set, how many that licence allows, since when, until when, last confirmed when, at which
 * revision. Rendering it directly is what makes that line possible, and it puts this package's
 * section in the same visual language as the sibling V-T.ONE sections on this same screen.
 *
 * Everything is server-rendered from the state resolved on this request, and nothing here is
 * persisted: the key lives in the package's own private state, never in localconfig, and the
 * status is derived live so it cannot go stale or be edited into saying something else.
 *
 * The three controls are plain submit buttons that use HTML's own `formaction` to re-point the
 * surrounding settings form at this package's action route. A nested `<form>` would be invalid
 * HTML here and is silently dropped by every browser, which would leave the buttons submitting
 * Contao's settings form instead of the action they name — hence no nested form and no JavaScript.
 */
final class InstanceSettingsListener
{
    public function __construct(
        private readonly EntitlementReader $reader,
        private readonly RuntimeState $runtimeState,
        private readonly HostInventory $hosts,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $router,
        // Contao's own cookie-based manager. The generic Symfony CSRF interface autowires to the
        // session-based `security.csrf.token_manager`, which the blanket RequestTokenListener
        // never checks against.
        private readonly ContaoCsrfTokenManager $csrfTokenManager,
        private readonly string $csrfTokenName,
    ) {
    }

    /**
     * The whole section body. Contao passes the data container and an extra label fragment; neither
     * is needed, but the signature has to tolerate them.
     */
    public function render(?DataContainer $dc = null, string $xlabel = ''): string
    {
        $entitlement = $this->reader->current();

        // Contao's own RequestTokenListener validates every backend POST against this one canonical
        // token name — a package-specific token id would never validate.
        $token = $this->csrfTokenManager->getToken($this->csrfTokenName)->getValue();

        return '<div class="widget vtinnovations-smtp-licence" style="max-width:640px">'
            .'<h3>' . $this->e($GLOBALS['TL_LANG']['tl_settings']['vtinnovations_smtp_licence'][0] ?? 'SMTP Konfigurator') . '</h3>'
            .'<div style="padding:12px 15px;border:1px solid var(--content-border);border-radius:4px;background:var(--content-bg)">'
            .$this->renderStatus($entitlement)
            .$this->renderControls($entitlement, $token)
            .'</div></div>';
    }

    private function renderStatus(Entitlement $entitlement): string
    {
        if (!$entitlement->granted) {
            return $this->renderHeadline('var(--red)', $this->trans('panel.headline_unlicensed'))
                .$this->renderNote($this->trans($this->withheldKey($entitlement->reason), [
                    '%domains%' => implode(', ', $this->hosts->configuredHosts()),
                ]));
        }

        return $this->renderHeadline('var(--green)', $this->trans('panel.headline_active'))
            .$this->renderNote($this->detailLine($entitlement));
    }

    private function renderHeadline(string $colour, string $label): string
    {
        return \sprintf(
            '<div style="font-size:15px;font-weight:bold;color:%s;margin-bottom:4px">%s</div>',
            $colour,
            $this->e($label),
        );
    }

    private function renderNote(string $text): string
    {
        return '<div class="tl_gray" style="font-size:12px;line-height:1.7">'.$this->e($text).'</div>';
    }

    /**
     * The five facts every V-T.ONE section on this screen shows and no more: which licence, which
     * package, since when, until when, last confirmed when. The bound host, the signed host set,
     * the allowance and the revision were dropped — record internals nobody acts on from here.
     *
     * The key appears masked at both ends only, which is enough to tell one stored licence from
     * another; the full key stays the one piece of this state a screenshot must not carry.
     */
    private function detailLine(Entitlement $entitlement): string
    {
        $parts = [
            $this->trans('panel.key').' '.$this->maskedKey($this->runtimeState->key()),
            $this->trans('panel.package').' '.strtoupper($entitlement->package),
            $this->trans('panel.starts').' '.$this->moment($entitlement->startsAt),
            $this->trans('panel.expires').' '.(null === $entitlement->expiresAt ? $this->trans('panel.unlimited') : $this->moment($entitlement->expiresAt)),
            // From the package's own bookkeeping, not the record: "last confirmed" is a fact about
            // this installation's last exchange, and the signed document cannot know it.
            $this->trans('panel.checked').' '.$this->moment($this->runtimeState->confirmedAt()),
        ];

        return implode(' · ', $parts);
    }

    /**
     * Four leading and four trailing characters around a fixed-width mask. A key too short to keep
     * both ends recognisable is masked whole: half of a short key is not a hint, it is the key.
     */
    private function maskedKey(string $key): string
    {
        $key = trim($key);
        $mask = str_repeat('•', 8);

        if ('' === $key) {
            return '—';
        }

        if (mb_strlen($key) <= 8) {
            return $mask;
        }

        return mb_substr($key, 0, 4).$mask.mb_substr($key, -4);
    }

    private function moment(?int $timestamp): string
    {
        if (null === $timestamp || $timestamp <= 0) {
            return '—';
        }

        return Date::parse((string) Config::get('datimFormat'), $timestamp);
    }

    /**
     * The key field and the actions. Refresh and remove only appear once there is something to act
     * on — offering them against no state at all would only invite a support ticket.
     */
    private function renderControls(Entitlement $entitlement, string $token): string
    {
        $html = '<label for="vtinnovations_smtp_license_key" style="display:block;margin:12px 0 4px"><strong>'
            .$this->e($this->trans('panel.key_label')).'</strong></label>'
            .'<input type="text" name="license_key" id="vtinnovations_smtp_license_key"'
            .' autocomplete="off" spellcheck="false" maxlength="255" value=""'
            .' style="width:100%;padding:6px;box-sizing:border-box"'
            .' placeholder="XXXXX-XXXXX-XXXXX-XXXXX">'
            .'<p class="tl_help" style="margin:4px 0 0;font-size:12px">'.$this->e($this->trans('panel.key_help')).'</p>';

        $html .= '<div class="vtinnovations-smtp-licence-actions" style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">'
            .$this->renderButton('activate', $this->trans('panel.activate_button'), $token);

        if ($entitlement->granted) {
            $html .= $this->renderButton('refresh', $this->trans('panel.refresh_button'), $token)
                .$this->renderButton('remove', $this->trans('panel.remove_button'), $token, $this->trans('panel.remove_confirm'));
        }

        return $html.'</div>';
    }

    private function renderButton(string $action, string $label, string $token, ?string $confirm = null): string
    {
        // The token rides on the button itself as well as on the surrounding settings form, so the
        // POST carries a valid one either way.
        return \sprintf(
            '<button type="submit" class="tl_submit" name="REQUEST_TOKEN" value="%s" formmethod="post" formaction="%s"%s>%s</button>',
            $this->e($token),
            $this->e($this->router->generate('vtinnovations_smtp_instance_action', ['action' => $action])),
            null !== $confirm ? ' onclick="return confirm('.$this->e((string) json_encode($confirm, JSON_THROW_ON_ERROR)).')"' : '',
            $this->e($label),
        );
    }

    /**
     * Coarse on purpose. An admin needs to know what to do next; naming the exact check that failed
     * would mostly help someone probing the validation.
     *
     * The exceptions are the states an admin genuinely cannot diagnose from a generic message: a
     * build that ships no verification key, and an installation with no configured hostname to
     * activate against.
     */
    private function withheldKey(string $reason): string
    {
        return match ($reason) {
            Entitlement::KEY_STORE_EMPTY      => 'status.no_key_material',
            Entitlement::NO_CONFIGURED_DOMAIN => 'status.no_configured_domain',
            Entitlement::REFRESH_REQUIRED     => 'status.refresh_required',
            Entitlement::MODEL_INCOMPATIBLE   => 'status.model_incompatible',
            Entitlement::REVOKED              => 'status.revoked',
            Entitlement::DOMAIN_MISMATCH      => 'status.wrong_domain',
            Entitlement::SEAL_BROKEN,
            Entitlement::BAD_SIGNATURE,
            Entitlement::MALFORMED,
            Entitlement::SCHEMA_UNSUPPORTED   => 'status.unverifiable',
            default                           => 'status.inactive',
        };
    }

    /** @param array<string, string> $parameters */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'vtinnovations_smtp');
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
