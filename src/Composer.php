<?php

namespace Spreedly\ComposerSdk;

/**
 * Main class for Composer SDK functionality
 */
class Composer
{
    private string $secretKey;
    private ComposerConfig $config;

    /**
     * Constructor validates and sets up the Composer instance.
     * - Validates secret key and config
     * - Merges default config
     * - Validates API version and log level
     * - Sets logger filter level
     *
     * @param string $secretKey
     * @param ComposerConfig|null $config
     * @throws ComposerMissingConfigException
     * @throws ComposerInvalidConfigException
     */
    public function __construct(string $secretKey, ?ComposerConfig $config = null)
    {
        // Validate secret key
        if (empty($secretKey)) {
            throw new ComposerMissingConfigException('secretApiKey', $secretKey);
        }
        $this->secretKey = $secretKey;

        // Merge provided config with defaults
        $defaultConfig = Constants::getDefaultConfig();
        $this->config = new ComposerConfig(
            apiVersion: $config?->apiVersion ?? $defaultConfig->apiVersion,
            apiUrl: $config?->apiUrl ?? $defaultConfig->apiUrl,
            logLevel: $config?->logLevel ?? $defaultConfig->logLevel,
            isEnabled: $config?->isEnabled ?? $defaultConfig->isEnabled,
            requestTimeoutMs: $config?->requestTimeoutMs ?? $defaultConfig->requestTimeoutMs
        );

        // Validate API version
        if ($this->config->apiVersion !== ComposerApiVersion::V1) {
            throw new ComposerInvalidConfigException(
                'config.apiVersion',
                $this->config->apiVersion,
                [ComposerApiVersion::V1]
            );
        }

        // Validate log level
        $validLogLevels = [
            ComposerLogLevel::TRACE,
            ComposerLogLevel::INFO,
            ComposerLogLevel::ERROR,
            ComposerLogLevel::NONE,
        ];

        $logLevel = $this->config->logLevel ?? ComposerLogLevel::INFO;
        if (!in_array($logLevel, $validLogLevels)) {
            throw new ComposerInvalidConfigException(
                'config.logLevel',
                $logLevel,
                $validLogLevels
            );
        }

        // Set logger filter level
        Logger::$filterLevel = Logger::logLevelToSeverity($logLevel);

        // Resolved after the logger is configured so the message below respects logLevel.
        if ($this->config->requestTimeoutMs !== null && $this->config->requestTimeoutMs <= 0) {
            Logger::error(sprintf(
                'config.requestTimeoutMs must be greater than 0. Ignoring %d and using the %dms default.',
                $this->config->requestTimeoutMs,
                Constants::DEFAULT_REQUEST_TIMEOUT_MS
            ))->log();
            $this->config->requestTimeoutMs = Constants::DEFAULT_REQUEST_TIMEOUT_MS;
        }
    }

    /**
     * Helper to create a standard error response object
     *
     * @param int $code
     * @param string $message
     * @return ComposerCheckpointResponse
     */
    public function createErrorResponse(int $code, string $message): ComposerCheckpointResponse
    {
        return new ComposerCheckpointResponse(
            success: false,
            errors: [new ComposerApiError($code, $message)],
            version: ComposerApiVersion::V1,
            verification: new ComposerVerification(
                id: '',
                status: VerificationStatus::FAILED,
                outcome: VerificationOutcome::ERROR
            )
        );
    }

    /**
     * Sends a tracking event to the Composer API
     *
     * @param TrackOptions $options
     * @return void
     */
    public function event(TrackOptions $options): void
    {
        if ($options->event === null) {
            $this->skipEvent('no event was provided');
            return;
        }

        if (Utilities::isNullOrEmpty($options->event->type)) {
            $this->skipEvent('event.type is missing');
            return;
        }

        if (Utilities::isNullOrEmpty($options->sessionId) && Utilities::isNullOrEmpty($options->sourceToken)) {
            $this->skipEvent('Must provide either a sessionId or sourceToken');
            return;
        }

        // If SDK is disabled, don't send the event
        if ($this->config->isEnabled === false) {
            return;
        }

        // Ensure event has a timestamp
        if ($options->event->eventTime === null) {
            $options->event->eventTime = intval(microtime(true) * 1000);
        }

        // Send event to API
        $response = Utilities::makeRequest(
            url: Utilities::constructApiUrl($this->config->apiUrl, $this->config->apiVersion) . 'track',
            method: 'POST',
            headers: Utilities::constructApiHeaders(
                token: $this->secretKey,
                verificationId: '',
                sourceToken: $options->sourceToken ?? '',
                customerId: $options->userId ?? '',
                sessionId: $options->sessionId ?? ''
            ),
            body: [
                'type' => $options->event->type,
                'data' => $options->event->data,
                'eventTime' => $options->event->eventTime,
            ],
            requestTimeoutMs: $this->config->requestTimeoutMs
        );

        // makeRequest already logged a request that never got a response.
        $requestFailed = $response['requestFailed'] ?? false;
        if (!$requestFailed && ($response['statusCode'] < 200 || $response['statusCode'] > 299)) {
            Logger::error(sprintf('Composer event was not accepted by the API (HTTP %d).', $response['statusCode']))->log();
        }
    }

    /**
     * Submits a checkpoint to Composer and polls for verification if needed
     * Handles timeouts, polling, and error conditions
     *
     * @param CheckpointOptions $options
     * @return ComposerCheckpointResponse
     * @throws ComposerMissingParameterException
     */
    public function checkpoint(CheckpointOptions $options): ComposerCheckpointResponse
    {
        // Validate required parameters are present
        if (Utilities::isNullOrEmpty($options->checkpointName)) {
            throw new ComposerMissingParameterException('checkpointName');
        }

        if ($options->event === null) {
            throw new ComposerMissingParameterException('event');
        }

        if (Utilities::isNullOrEmpty($options->sessionId) && Utilities::isNullOrEmpty($options->sourceToken)) {
            throw new ComposerMissingParameterException('Must provide either a sessionId or sourceToken');
        }

        // If SDK is disabled, return a default approved response for development
        if ($this->config->isEnabled === false) {
            return new ComposerCheckpointResponse(
                success: true,
                errors: [],
                version: ComposerApiVersion::V1,
                verification: new ComposerVerification(
                    id: 'COMPOSER_IS_DISABLED',
                    status: VerificationStatus::COMPLETE,
                    outcome: VerificationOutcome::APPROVED,
                    stepData: []
                )
            );
        }

        $checkpointTimeout = (!isset($options->options->timeout) || $options->options->timeout <= 0)
            ? Constants::BASE_CHECKPOINT_MAX_TIMEOUT_MS
            : $options->options->timeout;
        
        $currentPollingWait = Constants::BASE_CHECKPOINT_POLLING_WAIT_MS;
        $hasNeverPolled = true;

        // Prepare options for API call
        $requestOptions = [
            'sync' => $options->options->sync ?? false,
        ];

        $numFailures = 0;
        $checkpointStartTime = intval(microtime(true) * 1000);

        $event = [
            'type' => $options->checkpointName,
            'data' => $options->event->data,
        ];
        if ($options->event->ip !== null && $options->event->ip !== '') {
            $event['ip'] = $options->event->ip;
        }

        // Submit checkpoint to API
        $rawResponse = Utilities::makeRequest(
            url: Utilities::constructApiUrl($this->config->apiUrl, $this->config->apiVersion) . 'checkpoint',
            method: 'POST',
            headers: Utilities::constructApiHeaders(
                token: $this->secretKey,
                verificationId: $options->useVerificationId ?? '',
                sourceToken: $options->sourceToken ?? '',
                customerId: $options->userId ?? '',
                sessionId: $options->sessionId ?? ''
            ),
            body: [
                'event' => $event,
                'options' => $requestOptions,
            ],
            requestTimeoutMs: $this->config->requestTimeoutMs
        );

        $response = $this->parseCheckpointResponse($rawResponse['body']);

        // Handle error or unsuccessful response
        if ($response === null) {
            return $this->createErrorResponse(500, 'Unknown evaluation error');
        } elseif (!$response->success) {
            return $response;
        }

        // Check if verification is resolved or needs polling
        $isResolved = $response->verification !== null 
            && $response->verification->status !== VerificationStatus::PENDING;
        $verificationId = $response->verification?->id;
        
        if (Logger::isTraceEnabled()) {
            Logger::trace('Initial checkpoint response: ' . json_encode(Utilities::redactSensitiveFields($response)))->log();
        }

        // Poll for verification result if not resolved
        // Exponential backoff with capped retries
        while (
            !$isResolved
            && ((intval(microtime(true) * 1000) - $checkpointStartTime < $checkpointTimeout) || $hasNeverPolled)
            && $numFailures < Constants::MAX_FAILURE_COUNT
        ) {
            Utilities::sleep($currentPollingWait);
            $currentPollingWait = min($currentPollingWait * 2, Constants::MAX_POLLING_WAIT_MS);
            $hasNeverPolled = false;

            $rawResponse = Utilities::makeRequest(
                url: Utilities::constructApiUrl($this->config->apiUrl, $this->config->apiVersion) . 'verification/' . $verificationId,
                method: 'GET',
                headers: Utilities::constructApiHeaders(
                    token: $this->secretKey,
                    verificationId: $options->useVerificationId ?? '',
                    sourceToken: $options->sourceToken ?? '',
                    customerId: $options->userId ?? '',
                    sessionId: $options->sessionId ?? ''
                ),
                requestTimeoutMs: $this->config->requestTimeoutMs
            );

            $response = $this->parseCheckpointResponse($rawResponse['body']);

            if ($response !== null && $response->success) {
                $status = $response->verification?->status;
                if ($status === null) {
                    $numFailures++;
                } else {
                    $isResolved = $status !== VerificationStatus::PENDING;
                    $numFailures = 0; // reset failure count on successful response
                }
            } else {
                $numFailures++;
            }
        }

        // If retries exceeded, return timeout error response
        if ($numFailures >= Constants::MAX_FAILURE_COUNT) {
            Logger::error('Service Unavailable: Maximum retry count exceeded')->log();
            return new ComposerCheckpointResponse(
                success: false,
                errors: [new ComposerApiError(503, 'Service Unavailable: Maximum retry count exceeded')],
                version: ComposerApiVersion::V1,
                isTimeout: true
            );
        }

        // The polling budget can run out right after a non-JSON poll.
        if ($response === null) {
            return $this->createErrorResponse(500, 'Unknown evaluation error');
        }

        // Return the final response
        if (Logger::isTraceEnabled()) {
            Logger::trace('Returning response: ' . json_encode(Utilities::redactSensitiveFields($response)))->log();
        }
        return $response;
    }

    /**
     * Parse a checkpoint response from JSON
     *
     * @param string $json
     * @return ComposerCheckpointResponse|null
     */
    private function parseCheckpointResponse(string $json): ?ComposerCheckpointResponse
    {
        $data = json_decode($json, true);
        if ($data === null) {
            // The body is deliberately not logged
            Logger::error('The Composer API returned a response that is not valid JSON.')->log();
            return null;
        }

        $verification = null;
        if (isset($data['verification'])) {
            $verification = new ComposerVerification(
                id: $data['verification']['id'] ?? '',
                status: $data['verification']['status'] ?? '',
                outcome: $data['verification']['outcome'] ?? '',
                stepData: $data['verification']['stepData'] ?? [],
                nextSteps: $data['verification']['nextSteps'] ?? []
            );
        }

        $errors = [];
        if (isset($data['errors']) && is_array($data['errors'])) {
            foreach ($data['errors'] as $error) {
                $errors[] = new ComposerApiError(
                    code: $error['code'] ?? 0,
                    message: $error['message'] ?? ''
                );
            }
        }

        return new ComposerCheckpointResponse(
            success: $data['success'] ?? false,
            errors: $errors,
            version: $data['version'] ?? ComposerApiVersion::V1,
            verification: $verification,
            isTimeout: $data['isTimeout'] ?? null
        );
    }

    /**
     * Returns true if the checkpoint is still running (pending or blocked) with no verdict yet
     *
     * @param ComposerCheckpointResponse $checkpointResponse
     * @return bool
     */
    public function isRunning(ComposerCheckpointResponse $checkpointResponse): bool
    {
        if ($checkpointResponse->success) {
            $status = $checkpointResponse->verification?->status;
            return ($status === VerificationStatus::PENDING || $status === VerificationStatus::BLOCKED)
                && $checkpointResponse->verification->outcome === VerificationOutcome::PENDING;
        }
        return false;
    }

    /**
     * Returns true if the checkpoint was approved
     *
     * @param ComposerCheckpointResponse $checkpointResponse
     * @return bool
     */
    public function isAllowed(ComposerCheckpointResponse $checkpointResponse): bool
    {
        return $checkpointResponse->success
            && $checkpointResponse->verification?->status === VerificationStatus::COMPLETE
            && $checkpointResponse->verification?->outcome === VerificationOutcome::APPROVED;
    }

    /**
     * Returns true if the checkpoint was denied
     *
     * @param ComposerCheckpointResponse $checkpointResponse
     * @return bool
     */
    public function isDenied(ComposerCheckpointResponse $checkpointResponse): bool
    {
        if ($checkpointResponse->success) {
            return $checkpointResponse->verification?->outcome === VerificationOutcome::DENIED;
        }
        return false;
    }

    /**
     * Returns true if the checkpoint is complete but outcome is still pending
     *
     * @param ComposerCheckpointResponse $checkpointResponse
     * @return bool
     */
    public function isUndecided(ComposerCheckpointResponse $checkpointResponse): bool
    {
        return $checkpointResponse->success
            && $checkpointResponse->verification?->status === VerificationStatus::COMPLETE
            && $checkpointResponse->verification?->outcome === VerificationOutcome::PENDING;
    }

    /**
     * Returns true if the checkpoint response indicates an error
     *
     * @param ComposerCheckpointResponse $checkpointResponse
     * @return bool
     */
    public function hasError(ComposerCheckpointResponse $checkpointResponse): bool
    {
        return !$checkpointResponse->success
            || $checkpointResponse->verification?->outcome === VerificationOutcome::ERROR;
    }

    /**
     * Returns true if the checkpoint response timed out
     *
     * @param ComposerCheckpointResponse $checkpointResponse
     * @return bool
     */
    public function isTimeout(ComposerCheckpointResponse $checkpointResponse): bool
    {
        return !$checkpointResponse->success && ($checkpointResponse->isTimeout === true);
    }

    private function skipEvent(string $reason): void
    {
        Logger::error('Composer event was not sent: ' . $reason)->log();
    }
}

// For backward compatibility, create an alias
class Dodgeball extends Composer {}
