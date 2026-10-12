<?php

namespace Spreedly\ComposerSdk;

// API Version Enum
class ComposerApiVersion
{
    public const V1 = 'v1';
}

// Backward compatibility alias
class DodgeballApiVersion extends ComposerApiVersion {}

// Verification Status Enum
class VerificationStatus
{
    // In Process on the server
    public const PENDING = 'PENDING';
    
    // Waiting on some action, for example MFA
    public const BLOCKED = 'BLOCKED';
    
    // Workflow evaluated successfully
    public const COMPLETE = 'COMPLETE';
    
    // Workflow execution failure
    public const FAILED = 'FAILED';
}

// Verification Outcome Enum
class VerificationOutcome
{
    public const APPROVED = 'APPROVED';
    public const DENIED = 'DENIED';
    public const PENDING = 'PENDING';
    public const ERROR = 'ERROR';
    public const USAGE_LIMIT = 'USAGE_LIMIT';
    public const API_LIMIT = 'API_LIMIT';
}

/**
 * Configuration options for initializing the Composer SDK.
 */
class ComposerConfig
{
    /** @var string|null Optional version of the Composer API to use */
    public ?string $apiVersion = null;
    
    /** @var string|null Optional URL to the Composer API */
    public ?string $apiUrl = null;
    
    /** @var string|null Optional log level for the SDK */
    public ?string $logLevel = null;
    
    /** @var bool|null Optional flag to enable or disable the SDK */
    public ?bool $isEnabled = null;

    /** @var int|null Per-request HTTP timeout in milliseconds (connect + overall transfer) */
    public ?int $requestTimeoutMs = null;

    public function __construct(
        ?string $apiVersion = null,
        ?string $apiUrl = null,
        ?string $logLevel = null,
        ?bool $isEnabled = null,
        ?int $requestTimeoutMs = null
    ) {
        $this->apiVersion = $apiVersion;
        $this->apiUrl = $apiUrl;
        $this->logLevel = $logLevel;
        $this->isEnabled = $isEnabled;
        $this->requestTimeoutMs = $requestTimeoutMs;
    }
}

// Backward compatibility alias
class DodgeballConfig extends ComposerConfig {}

/**
 * Represents the event data sent to a checkpoint.
 */
class CheckpointEvent
{
    /** @var string|null Optional IP address associated with the event */
    public ?string $ip;

    /** @var array<string, mixed> Additional data relevant to the checkpoint event */
    public array $data;

    public function __construct(?string $ip = null, array $data = [])
    {
        $this->ip = $ip;
        $this->data = $data;
    }
}

/**
 * Represents a tracking event to be sent to Spreedly.
 */
class TrackEvent
{
    /** @var string The name of the event */
    public string $type;
    
    /** @var array<string, mixed> Any arbitrary data to track */
    public array $data;
    
    /** @var int|null Optional timestamp in milliseconds since Epoch */
    public ?int $eventTime = null;

    public function __construct(string $type, array $data = [], ?int $eventTime = null)
    {
        $this->type = $type;
        $this->data = $data;
        $this->eventTime = $eventTime;
    }
}

/**
 * Options for sending a tracking event.
 */
class TrackOptions
{
    /** @var TrackEvent The event data to be tracked */
    public TrackEvent $event;
    
    /** @var string|null Optional session identifier */
    public ?string $sessionId = null;
    
    /** @var string|null Optional user identifier */
    public ?string $userId = null;
    
    /** @var string|null Optional source token */
    public ?string $sourceToken = null;

    public function __construct(
        TrackEvent $event,
        ?string $sessionId = null,
        ?string $userId = null,
        ?string $sourceToken = null
    ) {
        $this->event = $event;
        $this->sessionId = $sessionId;
        $this->userId = $userId;
        $this->sourceToken = $sourceToken;
    }
}

/**
 * Options for customizing checkpoint response behavior.
 */
class CheckpointResponseOptions
{
    /** @var bool|null If true, wait for response before returning */
    public ?bool $sync = null;
    
    /** @var int|null Optional timeout in milliseconds */
    public ?int $timeout = null;

    public function __construct(?bool $sync = null, ?int $timeout = null)
    {
        $this->sync = $sync;
        $this->timeout = $timeout;
    }
}

/**
 * Options for submitting a checkpoint.
 */
class CheckpointOptions
{
    /** @var string The name of the checkpoint to evaluate */
    public string $checkpointName;
    
    /** @var CheckpointEvent The event data associated with the checkpoint */
    public CheckpointEvent $event;
    
    /** @var string|null Optional source token */
    public ?string $sourceToken = null;
    
    /** @var string|null Optional session identifier */
    public ?string $sessionId = null;
    
    /** @var string|null Optional user identifier */
    public ?string $userId = null;
    
    /** @var string|null Optional verification ID */
    public ?string $useVerificationId = null;
    
    /** @var CheckpointResponseOptions|null Optional settings for customizing response behavior */
    public ?CheckpointResponseOptions $options = null;

    public function __construct(
        string $checkpointName,
        CheckpointEvent $event,
        ?string $sourceToken = null,
        ?string $sessionId = null,
        ?string $userId = null,
        ?string $useVerificationId = null,
        ?CheckpointResponseOptions $options = null
    ) {
        $this->checkpointName = $checkpointName;
        $this->event = $event;
        $this->sourceToken = $sourceToken;
        $this->sessionId = $sessionId;
        $this->userId = $userId;
        $this->useVerificationId = $useVerificationId;
        $this->options = $options;
    }
}

/**
 * Represents the verification result for a checkpoint.
 */
class ComposerVerification
{
    /** @var string The unique identifier for the verification */
    public string $id;
    
    /** @var string The current status of the verification */
    public string $status;
    
    /** @var string The outcome of the verification */
    public string $outcome;
    
    /** @var array<string, mixed> Additional step data */
    public array $stepData = [];

    /** @var array<int, mixed> Steps the Client SDK runs next, such as MFA */
    public array $nextSteps = [];

    public function __construct(string $id, string $status, string $outcome, array $stepData = [], array $nextSteps = [])
    {
        $this->id = $id;
        $this->status = $status;
        $this->outcome = $outcome;
        $this->stepData = $stepData;
        $this->nextSteps = $nextSteps;
    }
}

/**
 * Represents an error returned by the Composer API.
 */
class ComposerApiError
{
    /** @var int The error code */
    public int $code;
    
    /** @var string A human-readable error message */
    public string $message;

    public function __construct(int $code, string $message)
    {
        $this->code = $code;
        $this->message = $message;
    }
}

/**
 * The response returned from a Composer checkpoint evaluation.
 */
class ComposerCheckpointResponse
{
    /** @var bool Indicates whether the checkpoint evaluation was successful */
    public bool $success;
    
    /** @var ComposerApiError[] Array of any errors that occurred */
    public array $errors;
    
    /** @var string The version of the Composer API */
    public string $version;
    
    /** @var ComposerVerification|null The verification result */
    public ?ComposerVerification $verification = null;
    
    /** @var bool|null Indicates whether the checkpoint evaluation timed out */
    public ?bool $isTimeout = null;

    public function __construct(
        bool $success,
        array $errors,
        string $version,
        ?ComposerVerification $verification = null,
        ?bool $isTimeout = null
    ) {
        $this->success = $success;
        $this->errors = $errors;
        $this->version = $version;
        $this->verification = $verification;
        $this->isTimeout = $isTimeout;
    }
}

// Custom Exceptions

/**
 * Exception thrown when required configuration is missing.
 */
class ComposerMissingConfigException extends \Exception
{
    public function __construct(string $configName, $value)
    {
        $message = sprintf(
            "Composer SDK Error\nMissing configuration: %s\nProvided Value: %s",
            $configName,
            json_encode($value)
        );
        parent::__construct($message);
    }
}

/**
 * Exception thrown when configuration is invalid.
 */
class ComposerInvalidConfigException extends \Exception
{
    public function __construct(string $configName, $value, array $allowedValues)
    {
        $message = sprintf(
            "Composer SDK Error\nInvalid configuration: %s\nProvided value: %s\nAllowed values: %s",
            $configName,
            json_encode($value),
            implode(', ', $allowedValues)
        );
        parent::__construct($message);
    }
}

/**
 * Exception thrown when a required parameter is missing.
 */
class ComposerMissingParameterException extends \Exception
{
    public function __construct(string $parameter)
    {
        $message = sprintf(
            "Composer SDK Error\nMissing parameter: %s",
            $parameter
        );
        parent::__construct($message);
    }
}

// Backward compatibility aliases
class DodgeballMissingConfigException extends ComposerMissingConfigException {}
class DodgeballInvalidConfigException extends ComposerInvalidConfigException {}
class DodgeballMissingParameterException extends ComposerMissingParameterException {}
