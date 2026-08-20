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

namespace Vtinnovations\SmtpBundle\Config;

/**
 * The fixed facts about how this bundle identifies itself and where it is allowed to talk.
 *
 * Three identifiers, not interchangeable:
 *
 *   PRODUCT_ID   the catalogue alias, sent as `product_id` — what the remote side looks the product
 *                up by, so it must match exactly.
 *   PROJECT_SLUG the alias without its `vt-` prefix, sent as `project_slug` and signed into every
 *                issued record, so a record issued for another product cannot be dropped in here.
 *   PROJECT      the human-readable title, signed as `project`.
 *
 * The two outbound hosts are assembled from fragments rather than written as one literal. That is
 * not a secret — anyone can watch the traffic — it only removes the single grep that finds every
 * place worth patching, and gives the release build one obvious thing to transform further.
 */
final class DeploymentProfile
{
    public const PROJECT      = 'SMTP Konfigurator';
    public const PROJECT_SLUG = 'smtp';
    public const PRODUCT_ID   = 'vt-smtp';

    /** The document layout this build understands, end to end. */
    public const SCHEMA_VERSION = 2;

    /** A record plus its envelope; anything larger is not one. */
    public const MAX_INBOUND_BYTES = 262144;

    /** Same ceiling on the way back in, so an oversized answer is dropped instead of parsed. */
    public const MAX_RESPONSE_BYTES = 262144;

    public const CONNECT_TIMEOUT_SECONDS = 5;
    public const TOTAL_TIMEOUT_SECONDS   = 8;

    /** Signals are fire-and-forget; they must never hold up a response. */
    public const SIGNAL_TIMEOUT_SECONDS = 2;

    /** Wide enough for ordinary clock drift, narrow enough that a captured request stops working. */
    public const MAX_CLOCK_SKEW_SECONDS = 300;

    /** How stale a confirmed record may get before the backend quietly re-asks. */
    public const RECHECK_INTERVAL_SECONDS = 86400;

    /** Kept comfortably longer than any retry window, short enough that the journal stays small. */
    public const REPLAY_RETENTION_SECONDS = 86400;

    /** @var list<string> */
    private const REMOTE_HOST_PARTS = ['www', '.', 'v-t', '.one'];

    /** Workflows A and B: activation and administrator refresh. */
    public static function exchangeEndpoint(): string
    {
        return self::origin().'/api/v1/verify';
    }

    /** Both invocation signal shapes. */
    public static function signalEndpoint(): string
    {
        return self::origin().'/rest/api/v1/log-envoke';
    }

    /**
     * Workflow C: the path the remote side pushes to.
     *
     * Also written out as a constant expression on the route attribute, because an attribute cannot
     * call a method. The two must stay in step — {@see \Vtinnovations\SmtpBundle\Tests} asserts it.
     */
    public static function updaterPath(): string
    {
        return '/rest/api/v1/'.self::PROJECT_SLUG.'-license-updater';
    }

    /** The one host this bundle is ever allowed to reach. */
    public static function remoteHost(): string
    {
        return implode('', self::REMOTE_HOST_PARTS);
    }

    private static function origin(): string
    {
        return 'https://'.self::remoteHost();
    }
}
