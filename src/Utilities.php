<?php

namespace Spreedly\ComposerSdk;

class Utilities
{
    private const SDK_VERSION = '1.0.0';

    /**
     * Fields that carry end-user information and are masked before anything is logged.
     * nextSteps is included because a blocked checkpoint returns integration config
     * (including provider client secrets) alongside the full client integration bundle.
     */
    private const SENSITIVE_FIELDS = ['ip', 'data', 'stepData', 'nextSteps'];

    private const REDACTED = '[REDACTED]';

    /**
     * Return a copy of a payload with end-user fields masked, so verbose logs show the
     * shape of a request or response without exposing PII
     *
     * @param mixed $payload
     * @return mixed
     */
    public static function redactSensitiveFields(mixed $payload): mixed
    {
        if (is_object($payload)) {
            $payload = json_decode(json_encode($payload), true);
        }

        if (!is_array($payload)) {
            return $payload;
        }

        $redacted = [];
        foreach ($payload as $key => $value) {
            if (in_array($key, self::SENSITIVE_FIELDS, true)) {
                $redacted[$key] = self::REDACTED;
            } else {
                $redacted[$key] = self::redactSensitiveFields($value);
            }
        }

        return $redacted;
    }

    /**
     * Make an HTTP request
     *
     * @param string $url
     * @param string $method
     * @param array $headers
     * @param array|null $body
     * @param int|null $requestTimeoutMs Per-request HTTP timeout in milliseconds (connect + overall transfer)
     * @return array
     */
    public static function makeRequest(
        string $url,
        string $method,
        array $headers,
        ?array $body = null,
        ?int $requestTimeoutMs = null
    ): array {
        try {
            foreach ($headers as $value) {
                // curl interpolates "$key: $value"; CR/LF in a value injects headers.
                if (is_string($value) && strpbrk($value, "\r\n\0") !== false) {
                    Logger::error("A request header has a character that can't be sent, such as a newline.")->log();
                    return [
                        'statusCode' => 500,
                        'requestFailed' => true,
                        'body' => json_encode([
                            'success' => false,
                            'errors' => [['code' => 500, 'message' => 'Internal Server Error']]
                        ])
                    ];
                }
            }

            if ($body !== null && Logger::isTraceEnabled()) {
                Logger::trace('Request body: ' . json_encode(self::redactSensitiveFields($body)))->log();
            }

            // cURL treats a zero timeout as no timeout at all, which is the hang this guards against.
            if ($requestTimeoutMs === null || $requestTimeoutMs <= 0) {
                $requestTimeoutMs = Constants::DEFAULT_REQUEST_TIMEOUT_MS;
            }

            $ch = curl_init();
            
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, $requestTimeoutMs);
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, $requestTimeoutMs);
            
            // Set headers
            $headerArray = [];
            foreach ($headers as $key => $value) {
                $headerArray[] = "$key: $value";
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headerArray);
            
            // Set body if present
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
            
            $response = curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            if ($error) {
                Logger::error('There was an error making a request.', $error)->log();
                return [
                    'statusCode' => 500,
                    'requestFailed' => true,
                    'body' => json_encode([
                        'success' => false,
                        'errors' => [['code' => 500, 'message' => 'Internal Server Error']]
                    ])
                ];
            }
            
            return [
                'statusCode' => $statusCode,
                'body' => $response
            ];
        } catch (\Exception $e) {
            Logger::error('There was an error making a request.', $e)->log();
            return [
                'statusCode' => 500,
                'requestFailed' => true,
                'body' => json_encode([
                    'success' => false,
                    'errors' => [['code' => 500, 'message' => 'Internal Server Error']]
                ])
            ];
        }
    }

    /**
     * Construct an API URL with version appended
     *
     * @param string $url
     * @param string $version
     * @return string
     */
    public static function constructApiUrl(string $url, string $version): string
    {
        // Ensure that the url ends with a '/'
        if (substr($url, -1) !== '/') {
            $url .= '/';
        }

        return $url . $version . '/';
    }

    /**
     * Construct API request headers
     *
     * @param string $token
     * @param string $verificationId
     * @param string $sourceToken
     * @param string $customerId
     * @param string $sessionId
     * @return array
     */
    public static function constructApiHeaders(
        string $token,
        string $verificationId = '',
        string $sourceToken = '',
        string $customerId = '',
        string $sessionId = ''
    ): array {
        $headers = [
            'Dodgeball-Secret-Key' => $token,
            'Composer-SDK-Version' => self::SDK_VERSION,
            'Composer-SDK-Source' => 'php-sdk',
            'Content-Type' => 'application/json',
        ];

        if (!self::isNullOrEmpty($verificationId)) {
            $headers['Dodgeball-Verification-Id'] = $verificationId;
        }

        if (!self::isNullOrEmpty($sourceToken)) {
            $headers['Dodgeball-Source-Token'] = $sourceToken;
        }

        if (!self::isNullOrEmpty($customerId)) {
            $headers['Dodgeball-Customer-Id'] = $customerId;
        }

        if (!self::isNullOrEmpty($sessionId)) {
            $headers['Dodgeball-Session-Id'] = $sessionId;
        }

        return $headers;
    }

    /**
     * Sleep for a specified number of milliseconds
     *
     * @param int $milliseconds
     * @return void
     */
    public static function sleep(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }

    /**
     * Check if a value is null or empty
     *
     * @param mixed $value
     * @return bool
     */
    public static function isNullOrEmpty($value): bool
    {
        return $value === null
            || $value === ''
            || (is_string($value) && trim($value) === '')
            || (is_string($value) && strtolower(trim($value)) === 'null')
            || (is_string($value) && strtolower(trim($value)) === 'undefined');
    }
}
