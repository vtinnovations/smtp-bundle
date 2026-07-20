<?php

declare(strict_types=1);

namespace Vtinnovations\SmtpBundle\Controller\Backend;

use Contao\BackendUser;
use Contao\System;
use Vtinnovations\SmtpBundle\Security\LicenseManager;
use Vtinnovations\SmtpBundle\Service\SmtpConfigHandler;

/**
 * Registered as BE_MOD callback. Contao instantiates via new ClassName(),
 * so services are fetched from container manually.
 */
class SmtpConfigModule
{
    public function generate(): string
    {
        $user = BackendUser::getInstance();

        if (!$user->isAdmin) {
            return '<p class="tl_error">Zugriff verweigert. Nur Administratoren.</p>';
        }

        /** @var LicenseManager $licenseManager */
        $licenseManager = System::getContainer()->get(LicenseManager::class);

        $host = $_SERVER['HTTP_HOST'] ?? '';

        // Background 24-hour re-check
        if ($licenseManager->isCacheStale()) {
            $licenseManager->refresh($host);
        }

        // Handle license form submission
        $licenseNotice = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['FORM_SUBMIT'] ?? '') === 'vtinnovations_smtp_license') {
            $key = trim($_POST['license_key'] ?? '');
            if ($licenseManager->activate($key, $host)) {
                $licenseNotice = $this->confirm('Lizenz erfolgreich aktiviert.');
            } else {
                $licenseNotice = $this->error($licenseManager->lastMessage() ?: 'Ungültiger Lizenzschlüssel.');
            }
        }

        // Show license gate when unlicensed
        if (!$licenseManager->isLicensed()) {
            return $this->renderLicenseGate($licenseNotice, $licenseManager->getLicenseKey());
        }

        /** @var SmtpConfigHandler $handler */
        $handler = System::getContainer()->get(SmtpConfigHandler::class);

        $message = '';
        $isConfigured = $handler->isConfigured();

        // Pre-populate from existing MAILER_DSN on GET; override with POST data on submit
        $formData = $handler->getCurrentConfig();

        // Contao already validates REQUEST_TOKEN before this code runs.
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['FORM_SUBMIT']) && $_POST['FORM_SUBMIT'] === 'vtinnovations_smtp') {
            $formData = $this->extractPost();
            $result = $handler->handle($formData);
            $message = $result->success
                ? $this->confirm($result->message)
                : $this->error($result->message);

            if ($result->success) {
                $isConfigured = true;
                $formData = $handler->getCurrentConfig(); // reload parsed values after save
            }
        }

        $requestToken = $handler->getRequestTokenValue();

        return $this->renderForm($requestToken, $message, $formData, $isConfigured);
    }

    private function renderLicenseGate(string $notice, string $currentKey): string
    {
        $e = static fn(string $v): string => htmlspecialchars($v, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        $csrfTokenManager = System::getContainer()->get('contao.csrf.token_manager');
        $csrfTokenName = System::getContainer()->getParameter('contao.csrf_token_name');
        $csrf = $e($csrfTokenManager->getToken($csrfTokenName)->getValue());
        $keyVal = $e($currentKey);

        return <<<HTML
            <div id="tl_buttons">
                <a href="contao" class="header_back" title="Zurück">Zurück</a>
            </div>

            <h2 class="sub_headline">SMTP-Konfiguration &nbsp;<span style="color:#d9534f">&#10007; Keine Lizenz</span></h2>

            <div class="tl_formbody_edit">
                {$notice}

                <div class="tl_tbox">
                    <p style="margin-bottom:1em">
                        Dieses Plugin benötigt eine gültige Lizenz von
                        <a href="https://v-t.one" target="_blank" rel="noopener">v-t.one</a>.
                    </p>
                </div>

                <form method="post" data-turbo="false">
                    <input type="hidden" name="REQUEST_TOKEN" value="{$csrf}">
                    <input type="hidden" name="FORM_SUBMIT" value="vtinnovations_smtp_license">

                    <fieldset class="tl_tbox block">
                        <legend>Lizenz aktivieren</legend>
                        <div class="widget w100">
                            <h3><label for="license_key">Lizenzschlüssel</label></h3>
                            <input type="text" id="license_key" name="license_key" value="{$keyVal}"
                                   class="tl_text" placeholder="XXXXX-XXXXX-XXXXX-XXXXX" autocomplete="off">
                            <p class="tl_help tl_tip">
                                Lizenzschlüssel von <a href="https://v-t.one" target="_blank" rel="noopener">v-t.one</a> eingeben.
                                Der Schlüssel wird mit dieser Domain verknüpft.
                            </p>
                        </div>
                    </fieldset>

                    <div class="tl_formbody_submit">
                        <div class="tl_submit_container">
                            <button type="submit" class="tl_submit">Lizenz aktivieren</button>
                        </div>
                    </div>
                </form>
            </div>
            HTML;
    }

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

    private function renderForm(string $csrf, string $message, array $data, bool $isConfigured): string
    {
        $e = static fn(string $v): string => htmlspecialchars($v, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        $host          = $e($data['host'] ?? '');
        $port          = (int) ($data['port'] ?? 587);
        $username      = $e($data['username'] ?? '');
        $fromEmail     = $e($data['from_email'] ?? '');
        $testRecipient = $e($data['test_recipient'] ?? '');
        $currentEnc    = $data['encryption'] ?? 'tls';

        $encOptions = '';
        foreach (['none' => 'Keine', 'tls' => 'STARTTLS (Port 587)', 'ssl' => 'SSL/TLS (Port 465)'] as $val => $label) {
            $sel = $currentEnc === $val ? ' selected' : '';
            $encOptions .= "<option value=\"{$val}\"{$sel}>{$label}</option>";
        }

        $requestTokenEscaped = $e($csrf);

        $statusBadge = $isConfigured
            ? '<span style="color:#5cb85c;font-weight:bold">&#10003; Aktiv</span>'
            : '<span style="color:#d9534f">&#10007; Nicht konfiguriert</span>';

        return <<<HTML
            <div id="tl_buttons">
                <a href="contao" class="header_back" title="Zurück">Zurück</a>
            </div>

            <h2 class="sub_headline">SMTP-Konfiguration &nbsp;{$statusBadge}</h2>

            <div class="tl_formbody_edit">
                {$message}

                <form method="post" id="smtp_config_form">
                    <input type="hidden" name="REQUEST_TOKEN" value="{$requestTokenEscaped}">
                    <input type="hidden" name="FORM_SUBMIT" value="vtinnovations_smtp">

                    <fieldset class="tl_tbox block">
                        <legend onclick="AjaxRequest.toggleFieldset(this,'smtp_server','')">Server</legend>
                        <div id="smtp_server">

                            <div class="widget w50">
                                <h3><label for="host">SMTP-Host <span class="mandatory">*</span></label></h3>
                                <input type="text" id="host" name="host" value="{$host}" class="tl_text" required placeholder="mail.example.com">
                            </div>

                            <div class="widget w50 w50x">
                                <h3><label for="port">Port</label></h3>
                                <input type="number" id="port" name="port" value="{$port}" class="tl_text" min="1" max="65535">
                            </div>

                            <div class="widget w50">
                                <h3><label for="encryption">Verschlüsselung</label></h3>
                                <select id="encryption" name="encryption" class="tl_select">{$encOptions}</select>
                            </div>

                            <div class="widget w50 w50x">
                                <h3><label for="username">Benutzername</label></h3>
                                <input type="text" id="username" name="username" value="{$username}" class="tl_text" autocomplete="off">
                            </div>

                            <div class="widget w50">
                                <h3><label for="password">Passwort</label></h3>
                                <input type="password" id="password" name="password" value="" class="tl_text" autocomplete="new-password">
                            </div>

                            <div class="widget w50 w50x">
                                <p class="tl_help tl_tip" style="margin-top:28px">Leer lassen, um das bestehende Passwort beizubehalten.</p>
                            </div>

                        </div>
                    </fieldset>

                    <fieldset class="tl_tbox block">
                        <legend onclick="AjaxRequest.toggleFieldset(this,'smtp_mail','')">E-Mail</legend>
                        <div id="smtp_mail">

                            <div class="widget w50">
                                <h3><label for="from_email">Absender-E-Mail <span class="mandatory">*</span></label></h3>
                                <input type="email" id="from_email" name="from_email" value="{$fromEmail}" class="tl_text" required>
                            </div>

                            <div class="widget w50 w50x">
                                <h3><label for="test_recipient">Test-Empfänger <span class="mandatory">*</span></label></h3>
                                <input type="email" id="test_recipient" name="test_recipient" value="{$testRecipient}" class="tl_text" required>
                                <p class="tl_help tl_tip">Vor dem Speichern wird eine Test-Mail an diese Adresse gesendet.</p>
                            </div>

                        </div>
                    </fieldset>

                    <div class="tl_formbody_submit">
                        <div class="tl_submit_container">
                            <button type="submit" class="tl_submit" accesskey="s">Testen &amp; Speichern</button>
                        </div>
                    </div>

                </form>
            </div>
            HTML;
    }

    private function error(string $msg): string
    {
        return '<p class="tl_error">' . htmlspecialchars($msg, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    }

    private function confirm(string $msg): string
    {
        return '<p class="tl_confirm">' . htmlspecialchars($msg, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    }
}
