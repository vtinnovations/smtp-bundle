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
use Contao\System;
use Vtinnovations\SmtpBundle\Exception\NotEntitledException;
use Vtinnovations\SmtpBundle\Service\Entitlement;
use Vtinnovations\SmtpBundle\Service\EntitlementReader;
use Vtinnovations\SmtpBundle\Service\ModuleEntrySignal;
use Vtinnovations\SmtpBundle\Service\ProvisioningService;
use Vtinnovations\SmtpBundle\Service\SmtpConfigHandler;

/**
 * The protected backend module.
 *
 * Registered as a BE_MOD callback, which Contao instantiates with `new ClassName()`, so services
 * are pulled from the container by hand rather than injected.
 *
 * Licence management is not here — it lives in Contao → Settings, as its own section, so several
 * V-T.ONE packages can be administered in one place. What is here is the first of several gates:
 * this screen refuses to render its form, the handler behind it refuses to act, and the mailer and
 * the cache clear refuse independently of both.
 */
class SmtpConfigModule
{
    public function generate(): string
    {
        System::loadLanguageFile('vtinnovations_smtp');

        $user = BackendUser::getInstance();

        if (!$user->isAdmin) {
            return $this->error($this->trans('access_denied'));
        }

        $container = System::getContainer();

        /** @var EntitlementReader $entitlement */
        $entitlement = $container->get(EntitlementReader::class);
        /** @var ProvisioningService $provisioning */
        $provisioning = $container->get(ProvisioningService::class);
        /** @var ModuleEntrySignal $entrySignal */
        $entrySignal = $container->get(ModuleEntrySignal::class);
        /** @var SmtpConfigHandler $handler */
        $handler = $container->get(SmtpConfigHandler::class);

        // The quiet re-check, so a renewal or a tier change lands without anyone re-entering
        // anything. Silent either way: a failed re-check leaves the existing record alone.
        $provisioning->refreshIfStale();
        $entitlement->reset();

        // First entry into this module in this backend session. Raised here — from the module's own
        // entry path, after Contao has authenticated the user — and not from entitlement evaluation,
        // which happens on plenty of occasions that are not a person opening this screen.
        $entrySignal->onModuleEntry();

        $state = $entitlement->current();

        if (!$state->granted) {
            return $this->renderUnavailable($state);
        }

        $requestToken = $handler->getRequestTokenValue();
        $message      = '';
        $isConfigured = $handler->isConfigured();
        $formData     = $handler->getCurrentConfig();

        // Contao validates REQUEST_TOKEN before this code runs.
        if ('POST' === ($_SERVER['REQUEST_METHOD'] ?? '')
            && 'vtinnovations_smtp' === ($_POST['FORM_SUBMIT'] ?? '')
        ) {
            $formData = $this->extractPost();

            try {
                $result  = $handler->handle($formData);
                $message = $result->success ? $this->confirm($result->message) : $this->error($result->message);

                if ($result->success) {
                    $isConfigured = true;
                    $formData     = $handler->getCurrentConfig();
                }
            } catch (NotEntitledException) {
                // The state changed between rendering and submitting — a push, an expiry, a
                // revocation. Fall back to the same screen an unentitled visit would have seen.
                return $this->renderUnavailable($entitlement->current());
            }
        }

        return $this->renderForm($requestToken, $message, $formData, $isConfigured, $this->planLabel($state));
    }

    /**
     * Shown when the module cannot be used. Coarse, and it points at Settings rather than explaining
     * which check failed.
     */
    private function renderUnavailable(Entitlement $state): string
    {
        $key = match ($state->reason) {
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

        $back     = $this->e($this->trans('back'));
        $headline = $this->e($this->trans('headline'));
        $notice   = $this->error($this->transDomain($key));
        $where    = $this->e($this->trans('license_where'));

        return <<<HTML
            <div id="tl_buttons">
                <a href="contao" class="header_back" title="{$back}">{$back}</a>
            </div>

            <h2 class="sub_headline">{$headline}</h2>

            <div class="tl_formbody_edit">
                {$notice}
                <p class="tl_info">{$where}</p>
            </div>
            HTML;
    }

    /** Short "Free" / "Pro" badge text. */
    private function planLabel(Entitlement $state): string
    {
        return $this->transDomain($state->isFree() ? 'plan.free' : 'plan.paid');
    }

    /**
     * @return array{host: string, port: int, encryption: string, username: string, password: string, from_email: string, test_recipient: string}
     */
    private function extractPost(): array
    {
        return [
            'host'           => trim($_POST['host'] ?? ''),
            'port'           => max(1, min(65535, (int) ($_POST['port'] ?? 587))),
            'encryption'     => $_POST['encryption'] ?? 'tls',
            'username'       => trim($_POST['username'] ?? ''),
            'password'       => $_POST['password'] ?? '',
            'from_email'     => trim($_POST['from_email'] ?? ''),
            'test_recipient' => trim($_POST['test_recipient'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderForm(string $csrf, string $message, array $data, bool $isConfigured, string $planLabel): string
    {
        $host          = $this->e((string) ($data['host'] ?? ''));
        $port          = (int) ($data['port'] ?? 587);
        $username      = $this->e((string) ($data['username'] ?? ''));
        $fromEmail     = $this->e((string) ($data['from_email'] ?? ''));
        $testRecipient = $this->e((string) ($data['test_recipient'] ?? ''));
        $currentEnc    = (string) ($data['encryption'] ?? 'tls');

        $encMap = [
            'none' => $this->trans('encryption_none'),
            'tls'  => $this->trans('encryption_starttls'),
            'ssl'  => $this->trans('encryption_ssl'),
        ];

        $encOptions = '';

        foreach ($encMap as $val => $label) {
            $sel = $currentEnc === $val ? ' selected' : '';
            $encOptions .= '<option value="'.$this->e($val).'"'.$sel.'>'.$this->e($label).'</option>';
        }

        $requestTokenEscaped = $this->e($csrf);

        $activeLabel = $this->trans('active');
        $statusBadge = $isConfigured
            ? '<span style="color:#5cb85c;font-weight:bold">&#10003; '.$this->e($activeLabel).'</span>'
            : '<span style="color:#d9534f">&#10007; '.$this->e($this->trans('not_configured')).'</span>';
        $planBadge = '' !== $planLabel
            ? ' &nbsp;<span style="color:#666;font-weight:normal">('.$this->e($planLabel).')</span>'
            : '';

        $back               = $this->e($this->trans('back'));
        $headline           = $this->e($this->trans('headline'));
        $serverSection      = $this->e($this->trans('server_section'));
        $smtpHostLabel      = $this->e($this->trans('smtp_host_label'));
        $portLabel          = $this->e($this->trans('port_label'));
        $encryptionLabel    = $this->e($this->trans('encryption_label'));
        $usernameLabel      = $this->e($this->trans('username_label'));
        $passwordLabel      = $this->e($this->trans('password_label'));
        $passwordHelp       = $this->e($this->trans('password_help'));
        $emailSection       = $this->e($this->trans('email_section'));
        $fromEmailLabel     = $this->e($this->trans('from_email_label'));
        $testRecipientLabel = $this->e($this->trans('test_recipient_label'));
        $testRecipientHelp  = $this->e($this->trans('test_recipient_help'));
        $saveBtn            = $this->e($this->trans('save_btn'));

        return <<<HTML
            <div id="tl_buttons">
                <a href="contao" class="header_back" title="{$back}">{$back}</a>
            </div>

            <h2 class="sub_headline">{$headline} &nbsp;{$statusBadge}{$planBadge}</h2>

            <div class="tl_formbody_edit">

                {$message}

                <form method="post" id="smtp_config_form" data-turbo="false">
                    <input type="hidden" name="REQUEST_TOKEN" value="{$requestTokenEscaped}">
                    <input type="hidden" name="FORM_SUBMIT" value="vtinnovations_smtp">

                    <fieldset class="tl_tbox block">
                        <legend onclick="AjaxRequest.toggleFieldset(this,'smtp_server','')">{$serverSection}</legend>
                        <div id="smtp_server">

                            <div class="widget w50">
                                <h3><label for="host">{$smtpHostLabel} <span class="mandatory">*</span></label></h3>
                                <input type="text" id="host" name="host" value="{$host}" class="tl_text" required placeholder="mail.example.com">
                            </div>

                            <div class="widget w50 w50x">
                                <h3><label for="port">{$portLabel}</label></h3>
                                <input type="number" id="port" name="port" value="{$port}" class="tl_text" min="1" max="65535">
                            </div>

                            <div class="widget w50">
                                <h3><label for="encryption">{$encryptionLabel}</label></h3>
                                <select id="encryption" name="encryption" class="tl_select">{$encOptions}</select>
                            </div>

                            <div class="widget w50 w50x">
                                <h3><label for="username">{$usernameLabel}</label></h3>
                                <input type="text" id="username" name="username" value="{$username}" class="tl_text" autocomplete="off">
                            </div>

                            <div class="widget w50">
                                <h3><label for="password">{$passwordLabel}</label></h3>
                                <input type="password" id="password" name="password" value="" class="tl_text" autocomplete="new-password">
                            </div>

                            <div class="widget w50 w50x">
                                <p class="tl_help tl_tip" style="margin-top:28px">{$passwordHelp}</p>
                            </div>

                        </div>
                    </fieldset>

                    <fieldset class="tl_tbox block">
                        <legend onclick="AjaxRequest.toggleFieldset(this,'smtp_mail','')">{$emailSection}</legend>
                        <div id="smtp_mail">

                            <div class="widget w50">
                                <h3><label for="from_email">{$fromEmailLabel} <span class="mandatory">*</span></label></h3>
                                <input type="email" id="from_email" name="from_email" value="{$fromEmail}" class="tl_text" required>
                            </div>

                            <div class="widget w50 w50x">
                                <h3><label for="test_recipient">{$testRecipientLabel} <span class="mandatory">*</span></label></h3>
                                <input type="email" id="test_recipient" name="test_recipient" value="{$testRecipient}" class="tl_text" required>
                                <p class="tl_help tl_tip">{$testRecipientHelp}</p>
                            </div>

                        </div>
                    </fieldset>

                    <div class="tl_formbody_submit">
                        <div class="tl_submit_container">
                            <button type="submit" class="tl_submit" accesskey="s">{$saveBtn}</button>
                        </div>
                    </div>

                </form>
            </div>
            HTML;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    private function error(string $msg): string
    {
        return '<p class="tl_error">'.$this->e($msg).'</p>';
    }

    private function confirm(string $msg): string
    {
        return '<p class="tl_confirm">'.$this->e($msg).'</p>';
    }

    /** Contao language file, for the module's own labels. */
    private function trans(string $key): string
    {
        return (string) ($GLOBALS['TL_LANG']['vtinnovations_smtp'][$key] ?? $key);
    }

    /** Symfony translation domain, shared with the settings section so the wording matches. */
    private function transDomain(string $key): string
    {
        return System::getContainer()->get('translator')->trans($key, [], 'vtinnovations_smtp');
    }
}
