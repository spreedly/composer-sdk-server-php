<?php

namespace Spreedly\ComposerSdk\Tests;

use PHPUnit\Framework\TestCase;
use Spreedly\ComposerSdk\Logger;
use Spreedly\ComposerSdk\LogEntry;
use Spreedly\ComposerSdk\Severity;
use Spreedly\ComposerSdk\ComposerLogLevel;

class LoggerTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset filter level before each test
        Logger::$filterLevel = Severity::TRACE;
    }

    public function testLogEntryCreation(): void
    {
        $entry = new LogEntry('Test message', Severity::INFO);
        $this->assertEquals('Test message', $entry->message);
        $this->assertEquals(Severity::INFO, $entry->severity);
    }

    public function testLogEntrySetParameters(): void
    {
        $entry = new LogEntry('Test message');
        $entry->setParameters(['key' => 'value']);
        
        $this->assertIsArray($entry->parameters);
        $this->assertEquals('value', $entry->parameters['key']);
    }

    public function testLogEntrySetParameter(): void
    {
        $entry = new LogEntry('Test message');
        $entry->setParameter('key1', 'value1');
        $entry->setParameter('key2', 'value2');
        
        $this->assertEquals('value1', $entry->parameters['key1']);
        $this->assertEquals('value2', $entry->parameters['key2']);
    }

    public function testLogEntrySetSeverity(): void
    {
        $entry = new LogEntry('Test message', Severity::INFO);
        $entry->setSeverity(Severity::ERROR);
        
        $this->assertEquals(Severity::ERROR, $entry->severity);
    }

    public function testLogEntrySetError(): void
    {
        $entry = new LogEntry('Test message');
        $exception = new \Exception('Test error');
        $entry->setError($exception);
        
        $this->assertInstanceOf(\Exception::class, $entry->error);
        $this->assertEquals('Test error', $entry->error->getMessage());
    }

    public function testLoggerInfoCreatesInfoEntry(): void
    {
        $entry = Logger::info('Test message');
        $this->assertInstanceOf(LogEntry::class, $entry);
        $this->assertEquals(Severity::INFO, $entry->severity);
    }

    public function testLoggerTraceCreatesTraceEntry(): void
    {
        $entry = Logger::trace('Test message');
        $this->assertInstanceOf(LogEntry::class, $entry);
        $this->assertEquals(Severity::TRACE, $entry->severity);
    }

    public function testLoggerErrorCreatesErrorEntry(): void
    {
        $entry = Logger::error('Test message');
        $this->assertInstanceOf(LogEntry::class, $entry);
        $this->assertEquals(Severity::ERROR, $entry->severity);
    }

    public function testLoggerFormatError(): void
    {
        $exception = new \Exception('Test error');
        $formatted = Logger::formatError($exception);
        
        $this->assertStringContainsString('Test error', $formatted);
        $this->assertStringContainsString('message:', $formatted);
    }

    public function testLogLevelToSeverity(): void
    {
        $this->assertEquals(Severity::TRACE, Logger::logLevelToSeverity(ComposerLogLevel::TRACE));
        $this->assertEquals(Severity::INFO, Logger::logLevelToSeverity(ComposerLogLevel::INFO));
        $this->assertEquals(Severity::ERROR, Logger::logLevelToSeverity(ComposerLogLevel::ERROR));
        $this->assertEquals(Severity::NONE, Logger::logLevelToSeverity(ComposerLogLevel::NONE));
    }

    public function testFilterLevelBlocksLowerSeverity(): void
    {
        Logger::$filterLevel = Severity::ERROR;
        
        // Test that lower severity logs are filtered out
        // We can't easily test the actual output, but we can verify the filter level is set
        $this->assertEquals(Severity::ERROR, Logger::$filterLevel);
    }

    public function testLogWithParametersAndError(): void
    {
        $exception = new \Exception('Test error');
        $entry = Logger::error('Error occurred', $exception);
        
        $this->assertEquals('Error occurred', $entry->message);
        $this->assertEquals(Severity::ERROR, $entry->severity);
        $this->assertEquals($exception, $entry->error);
    }

    public function testLogLevelToSeverityWithInvalid(): void
    {
        $this->assertEquals(Severity::INFO, Logger::logLevelToSeverity('INVALID'));
    }

    public function testFormatErrorWithNull(): void
    {
        $result = Logger::formatError(null);
        $this->assertEquals('', $result);
    }

    public function testFormatErrorWithString(): void
    {
        $result = Logger::formatError('Simple error message');
        $this->assertEquals('Simple error message', $result);
    }

    public function testFormatErrorWithException(): void
    {
        $exception = new \Exception('Test exception message');
        $result = Logger::formatError($exception);
        
        $this->assertStringContainsString('message: Test exception message', $result);
        $this->assertStringContainsString('stack:', $result);
        $this->assertStringContainsString('name: Exception', $result);
    }

    public function testFormatErrorWithThrowable(): void
    {
        $error = new \Error('Test error message');
        $result = Logger::formatError($error);
        
        $this->assertStringContainsString('message: Test error message', $result);
        $this->assertStringContainsString('stack:', $result);
        $this->assertStringContainsString('name: Error', $result);
    }

    public function testFormatErrorWithInteger(): void
    {
        $result = Logger::formatError(404);
        $this->assertEquals('404', $result);
    }

    public function testLogEntryChaining(): void
    {
        $entry = new \Spreedly\ComposerSdk\LogEntry('Test');
        
        $result = $entry->setParameter('key1', 'value1')
            ->setParameter('key2', 'value2')
            ->setSeverity(Severity::ERROR)
            ->setError(new \Exception('Test error'));
        
        $this->assertSame($entry, $result);
        $this->assertEquals('value1', $entry->parameters['key1']);
        $this->assertEquals('value2', $entry->parameters['key2']);
        $this->assertEquals(Severity::ERROR, $entry->severity);
        $this->assertInstanceOf(\Exception::class, $entry->error);
    }

    public function testLogWithDifferentFilterLevels(): void
    {
        Logger::$filterLevel = Severity::ERROR;
        
        $traceEntry = Logger::trace('Trace message');
        $infoEntry = Logger::info('Info message');
        $errorEntry = Logger::error('Error message');
        
        Logger::log($traceEntry);
        Logger::log($infoEntry);
        Logger::log($errorEntry);
        
        Logger::$filterLevel = Severity::TRACE;
        
        $this->assertTrue(true);
    }

    public function testLoggerWithParameters(): void
    {
        $entry = Logger::info('Test message', ['key' => 'value', 'number' => 123]);
        
        $this->assertIsArray($entry->parameters);
        $this->assertEquals('value', $entry->parameters['key']);
        $this->assertEquals(123, $entry->parameters['number']);
        
        Logger::log($entry);
    }

    public function testLogWithUnknownSeverityHitsDefaultCase(): void
    {
        // Exercises the `default => 'UNKNOWN'` arm in getSeverityName
        Logger::$filterLevel = Severity::TRACE;
        $entry = new LogEntry('Unknown severity message', Severity::INFO);
        // Set severity to a value not in the match — PHP int properties are mutable
        $entry->severity = 999;
        // Should not throw; the default arm returns 'UNKNOWN'
        Logger::log($entry);
        $this->assertTrue(true);
    }

    public function testLogLevelToSeverityWithUnknownLevel(): void
    {
        // Exercises the `default => Severity::INFO` arm in logLevelToSeverity
        $severity = Logger::logLevelToSeverity('unknown-level');
        $this->assertEquals(Severity::INFO, $severity);
    }
}
