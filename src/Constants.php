<?php

namespace Spreedly\ComposerSdk;

class Constants
{
    // Default starting wait time between polling attempts before the backoff is applied
    public const BASE_CHECKPOINT_POLLING_WAIT_MS = 100;

    // Maximum amount of time between polling attempts
    public const MAX_POLLING_WAIT_MS = 2000;

    // 10 minutes in milliseconds
    public const BASE_CHECKPOINT_MAX_TIMEOUT_MS = 10 * 60 * 1000;

    // Maximum number of failures before timing out
    public const MAX_FAILURE_COUNT = 3;

    // Default per-request HTTP timeout (connect + overall transfer), 30 seconds in milliseconds
    public const DEFAULT_REQUEST_TIMEOUT_MS = 30 * 1000;

    /**
     * Get the default configuration
     *
     * @return ComposerConfig
     */
    public static function getDefaultConfig(): ComposerConfig
    {
        return new ComposerConfig(
            apiVersion: ComposerApiVersion::V1,
            apiUrl: 'https://api.dodgeballhq.com/',
            logLevel: ComposerLogLevel::INFO,
            isEnabled: true,
            requestTimeoutMs: self::DEFAULT_REQUEST_TIMEOUT_MS
        );
    }
}
