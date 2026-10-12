<?php

namespace Spreedly\ComposerSdk\Tests;

use PHPUnit\Framework\TestCase;
use Spreedly\ComposerSdk\CheckpointEvent;
use Spreedly\ComposerSdk\CheckpointOptions;
use Spreedly\ComposerSdk\Composer;
use Spreedly\ComposerSdk\ComposerApiVersion;
use Spreedly\ComposerSdk\ComposerConfig;
use Spreedly\ComposerSdk\ComposerLogLevel;
use Spreedly\ComposerSdk\Constants;
use Spreedly\ComposerSdk\Logger;
use Spreedly\ComposerSdk\Severity;
use Spreedly\ComposerSdk\TrackEvent;
use Spreedly\ComposerSdk\TrackOptions;
use Spreedly\ComposerSdk\Utilities;
use Spreedly\ComposerSdk\VerificationStatus;

/**
 * Regression coverage for the safe logging default: an integrator who passes no log
 * level must never see request bodies, response bodies or event IPs in their logs.
 */
class LoggingDefaultsTest extends TestCase
{
    private const EVENT_IP = '203.0.113.7';
    private const EVENT_EMAIL = 'shopper@example.com';

    private static ?int $serverPid = null;
    private static string $serverUrl = 'http://127.0.0.1:18091';

    private string $logFile;
    private string $originalErrorLog;

    public static function setUpBeforeClass(): void
    {
        $docRoot = __DIR__ . '/fixtures-logging';
        if (!is_dir($docRoot)) {
            mkdir($docRoot, 0777, true);
        }

        // Every request answers with a resolved verification carrying end-user data.
        $serverScript = $docRoot . '/server.php';
        file_put_contents($serverScript, '<?php
$payload = json_decode(file_get_contents("php://input"), true);
if (is_array($payload) && ($payload["type"] ?? "") === "REJECT_EVENT") {
    http_response_code(400);
    header("Content-Type: application/json");
    echo json_encode(["success" => false, "errors" => [["message" => "Invalid eventTime submitted."]]]);
    return;
}
if (is_array($payload) && ($payload["event"]["type"] ?? "") === "HTML_CHECKPOINT") {
    http_response_code(502);
    header("Content-Type: text/html");
    echo "<html>Bad Gateway</html>";
    return;
}
header("Content-Type: application/json");
echo json_encode([
    "success" => true,
    "errors" => [],
    "version" => "v1",
    "verification" => [
        "id" => "verification123",
        "status" => "COMPLETE",
        "outcome" => "APPROVED",
        "stepData" => ["email" => "' . self::EVENT_EMAIL . '"],
    ],
]);
');

        $cmd = sprintf(
            'php -S 127.0.0.1:18091 -t %s %s > /dev/null 2>&1 & echo $!',
            escapeshellarg($docRoot),
            escapeshellarg($serverScript)
        );

        self::$serverPid = (int) trim(shell_exec($cmd));
        usleep(500000); // 0.5 seconds for the server to come up
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverPid) {
            // SIGTERM is defined by ext-pcntl, which is not enabled in every PHP build.
            posix_kill(self::$serverPid, defined('SIGTERM') ? SIGTERM : 15);
        }

        $docRoot = __DIR__ . '/fixtures-logging';
        if (file_exists($docRoot . '/server.php')) {
            unlink($docRoot . '/server.php');
        }
        if (is_dir($docRoot)) {
            rmdir($docRoot);
        }
    }

    protected function setUp(): void
    {
        $this->logFile = tempnam(sys_get_temp_dir(), 'composer-logging');
        $this->originalErrorLog = (string) ini_get('error_log');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->originalErrorLog);
        unlink($this->logFile);
    }

    public function testDefaultLogLevelIsInfo(): void
    {
        $this->assertEquals(ComposerLogLevel::INFO, Constants::getDefaultConfig()->logLevel);
    }

    public function testDefaultLogLevelEmitsNothingForACheckpoint(): void
    {
        $output = $this->runCheckpoint(null);

        $this->assertEquals(Severity::INFO, Logger::$filterLevel);
        $this->assertSame('', $output);
    }

    public function testTraceLogLevelLogsRedactedPayloads(): void
    {
        $output = $this->runCheckpoint(ComposerLogLevel::TRACE);

        $this->assertStringContainsString(VerificationStatus::COMPLETE, $output);
        $this->assertStringContainsString('[REDACTED]', $output);
        $this->assertStringNotContainsString(self::EVENT_IP, $output);
        $this->assertStringNotContainsString(self::EVENT_EMAIL, $output);
    }

    public function testRedactSensitiveFieldsMasksEndUserFields(): void
    {
        $redacted = Utilities::redactSensitiveFields([
            'event' => ['type' => 'CHECKOUT', 'ip' => self::EVENT_IP, 'data' => ['email' => self::EVENT_EMAIL]],
            'verification' => ['id' => 'verification123', 'stepData' => ['email' => self::EVENT_EMAIL]],
        ]);

        $this->assertSame('CHECKOUT', $redacted['event']['type']);
        $this->assertSame('[REDACTED]', $redacted['event']['ip']);
        $this->assertSame('[REDACTED]', $redacted['event']['data']);
        $this->assertSame('verification123', $redacted['verification']['id']);
        $this->assertSame('[REDACTED]', $redacted['verification']['stepData']);
    }

    public function testRedactSensitiveFieldsMasksNextSteps(): void
    {
        $redacted = Utilities::redactSensitiveFields([
            'verification' => [
                'id' => 'verification123',
                'status' => 'BLOCKED',
                'nextSteps' => [
                    [
                        'name' => 'STRIPE_IDENTITY',
                        'config' => ['clientSecret' => 'vs_secret_value'],
                        'content' => ['text' => 'console.log("bundle");'],
                    ],
                ],
            ],
        ]);

        $this->assertSame('BLOCKED', $redacted['verification']['status']);
        $this->assertSame('[REDACTED]', $redacted['verification']['nextSteps']);
        $this->assertStringNotContainsString('vs_secret_value', (string) json_encode($redacted));
    }

    public function testIsTraceEnabledTracksTheFilterLevel(): void
    {
        $original = Logger::$filterLevel;

        try {
            Logger::$filterLevel = Severity::INFO;
            $this->assertFalse(Logger::isTraceEnabled());

            Logger::$filterLevel = Severity::TRACE;
            $this->assertTrue(Logger::isTraceEnabled());
        } finally {
            Logger::$filterLevel = $original;
        }
    }

    public function testRejectedEventLogsTheStatus(): void
    {
        $this->assertStringContainsString(
            'Composer event was not accepted by the API (HTTP 400).',
            $this->runEvent('REJECT_EVENT')
        );
    }

    public function testAcceptedEventLogsNothing(): void
    {
        $this->assertSame('', $this->runEvent('TEST_EVENT'));
    }

    public function testCheckpointWithANewlineInTheSecretKeyReturns500WithoutLeaking(): void
    {
        $composer = new Composer("sk_test_FAKE\n123", new ComposerConfig(
            apiVersion: ComposerApiVersion::V1,
            apiUrl: 'http://127.0.0.1:1',
            logLevel: ComposerLogLevel::ERROR
        ));

        $response = $composer->checkpoint(new CheckpointOptions(
            checkpointName: 'TEST',
            event: new CheckpointEvent(ip: self::EVENT_IP),
            sessionId: 'session123'
        ));
        $log = (string) file_get_contents($this->logFile);

        $this->assertFalse($response->success);
        $this->assertSame(500, $response->errors[0]->code);
        $this->assertSame('Internal Server Error', $response->errors[0]->message);
        $this->assertStringContainsString(
            "A request header has a character that can't be sent, such as a newline.",
            $log
        );
        $this->assertStringNotContainsString('sk_test_FAKE', $log);
        $this->assertStringNotContainsString('There was an error making a request.', $log);
    }

    public function testNetworkFailureIsNotReportedAsAnApiRejection(): void
    {
        // Nothing listens on port 1, so no HTTP response is ever received.
        $log = $this->runEvent('TEST_EVENT', 'http://127.0.0.1:1');

        $this->assertSame(1, substr_count($log, 'There was an error making a request.'));
        $this->assertStringNotContainsString('not accepted by the API', $log);
    }

    public function testNonJsonCheckpointResponseLogsWithoutTheBody(): void
    {
        $composer = new Composer('test-secret-key', new ComposerConfig(
            apiVersion: ComposerApiVersion::V1,
            apiUrl: self::$serverUrl
        ));

        $response = $composer->checkpoint(new CheckpointOptions(
            checkpointName: 'HTML_CHECKPOINT',
            event: new CheckpointEvent(ip: self::EVENT_IP),
            sessionId: 'session123'
        ));
        $log = (string) file_get_contents($this->logFile);

        $this->assertFalse($response->success);
        $this->assertSame(500, $response->errors[0]->code);
        $this->assertStringContainsString('The Composer API returned a response that is not valid JSON.', $log);
        $this->assertStringNotContainsString('Bad Gateway', $log);
    }

    private function runEvent(string $eventType, ?string $apiUrl = null): string
    {
        $composer = new Composer('test-secret-key', new ComposerConfig(
            apiVersion: ComposerApiVersion::V1,
            apiUrl: $apiUrl ?? self::$serverUrl
        ));

        $composer->event(new TrackOptions(
            event: new TrackEvent($eventType, []),
            sessionId: 'session123'
        ));

        return (string) file_get_contents($this->logFile);
    }

    private function runCheckpoint(?string $logLevel): string
    {
        $composer = new Composer('test-secret-key', new ComposerConfig(
            apiVersion: ComposerApiVersion::V1,
            apiUrl: self::$serverUrl,
            logLevel: $logLevel
        ));

        $composer->checkpoint(new CheckpointOptions(
            checkpointName: 'CHECKOUT',
            event: new CheckpointEvent(ip: self::EVENT_IP, data: ['email' => self::EVENT_EMAIL]),
            sessionId: 'session123'
        ));

        return (string) file_get_contents($this->logFile);
    }
}
