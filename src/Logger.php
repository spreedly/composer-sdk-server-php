<?php

namespace Spreedly\ComposerSdk;

// Log Level Enum
class ComposerLogLevel
{
    public const TRACE = 'TRACE';
    public const INFO = 'INFO';
    public const ERROR = 'ERROR';
    public const NONE = 'NONE';
}

// Backward compatibility alias
class DodgeballLogLevel extends ComposerLogLevel {}

// Severity Enum
class Severity
{
    public const TRACE = 0;
    public const INFO = 1;
    public const ERROR = 2;
    public const NONE = 3;
}

/**
 * Represents a single log entry.
 */
class LogEntry
{
    public int $severity = Severity::INFO;
    public string $message = '';
    public mixed $error = null;
    public ?array $parameters = null;
    public \DateTime $date;

    public function __construct(string $message, int $severity = Severity::INFO, $error = null)
    {
        $this->message = $message;
        $this->severity = $severity;
        $this->error = $error;
        $this->date = new \DateTime();
    }

    /**
     * Set multiple parameters at once
     *
     * @param array|null $parameters
     * @return LogEntry
     */
    public function setParameters(?array $parameters): LogEntry
    {
        $this->parameters = $parameters;
        return $this;
    }

    /**
     * Set a single parameter
     *
     * @param string $key
     * @param mixed $value
     * @return LogEntry
     */
    public function setParameter(string $key, $value): LogEntry
    {
        if ($this->parameters === null) {
            $this->parameters = [];
        }
        $this->parameters[$key] = $value;
        return $this;
    }

    /**
     * Set the severity level
     *
     * @param int $severity
     * @return LogEntry
     */
    public function setSeverity(int $severity): LogEntry
    {
        $this->severity = $severity;
        return $this;
    }

    /**
     * Set the error
     *
     * @param mixed $error
     * @return LogEntry
     */
    public function setError($error): LogEntry
    {
        $this->error = $error;
        return $this;
    }

    /**
     * Log this entry
     *
     * @return void
     */
    public function log(): void
    {
        Logger::log($this);
    }
}

/**
 * Static logger class for SDK logging.
 */
class Logger
{
    public static int $filterLevel = Severity::INFO;

    /**
     * Report whether a trace entry would be written. Callers check this before
     * assembling an expensive trace message, because the message is built before
     * log() gets a chance to discard it.
     *
     * @return bool
     */
    public static function isTraceEnabled(): bool
    {
        return Severity::TRACE >= self::$filterLevel;
    }

    /**
     * Create an info log entry
     *
     * @param string $message
     * @param array|null $parameters
     * @return LogEntry
     */
    public static function info(string $message, ?array $parameters = null): LogEntry
    {
        return (new LogEntry($message, Severity::INFO))->setParameters($parameters);
    }

    /**
     * Create a trace log entry
     *
     * @param string $message
     * @param array|null $parameters
     * @return LogEntry
     */
    public static function trace(string $message, ?array $parameters = null): LogEntry
    {
        return (new LogEntry($message, Severity::TRACE))->setParameters($parameters);
    }

    /**
     * Create an error log entry
     *
     * @param string $message
     * @param mixed $error
     * @return LogEntry
     */
    public static function error(string $message, $error = null): LogEntry
    {
        return (new LogEntry($message, Severity::ERROR))->setError($error);
    }

    /**
     * Log an entry if it meets the filter level
     *
     * @param LogEntry $entry
     * @return void
     */
    public static function log(LogEntry $entry): void
    {
        if ($entry->severity >= self::$filterLevel) {
            $timestamp = $entry->date->format('Y-m-d H:i:s');
            $severityName = self::getSeverityName($entry->severity);
            $message = sprintf('[%s] [%s] %s', $timestamp, $severityName, $entry->message);
            
            if ($entry->parameters !== null) {
                $message .= ' ' . json_encode($entry->parameters);
            }
            
            if ($entry->error !== null) {
                $message .= "\n" . self::formatError($entry->error);
            }
            
            error_log($message);
        }
    }

    /**
     * Format an error for logging
     *
     * @param mixed $error
     * @return string
     */
    public static function formatError($error): string
    {
        if ($error === null) {
            return '';
        }

        if ($error instanceof \Exception || $error instanceof \Throwable) {
            return sprintf(
                "message: %s\nstack: %s\nname: %s",
                $error->getMessage(),
                $error->getTraceAsString(),
                get_class($error)
            );
        }

        return (string) $error;
    }

    /**
     * Get the name of a severity level
     *
     * @param int $severity
     * @return string
     */
    private static function getSeverityName(int $severity): string
    {
        return match ($severity) {
            Severity::TRACE => 'TRACE',
            Severity::INFO => 'INFO',
            Severity::ERROR => 'ERROR',
            Severity::NONE => 'NONE',
            default => 'UNKNOWN',
        };
    }

    /**
     * Convert log level string to severity int
     *
     * @param string $logLevel
     * @return int
     */
    public static function logLevelToSeverity(string $logLevel): int
    {
        return match ($logLevel) {
            ComposerLogLevel::TRACE => Severity::TRACE,
            ComposerLogLevel::INFO => Severity::INFO,
            ComposerLogLevel::ERROR => Severity::ERROR,
            ComposerLogLevel::NONE => Severity::NONE,
            default => Severity::INFO,
        };
    }
}
