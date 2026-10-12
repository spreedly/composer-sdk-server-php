<?php

namespace Spreedly\ComposerSdk\Tests;

use PHPUnit\Framework\TestCase;
use Spreedly\ComposerSdk\Composer;
use Spreedly\ComposerSdk\ComposerConfig;
use Spreedly\ComposerSdk\ComposerApiVersion;
use Spreedly\ComposerSdk\CheckpointOptions;
use Spreedly\ComposerSdk\CheckpointEvent;
use Spreedly\ComposerSdk\CheckpointResponseOptions;
use Spreedly\ComposerSdk\TrackOptions;
use Spreedly\ComposerSdk\TrackEvent;
use Spreedly\ComposerSdk\VerificationStatus;
use Spreedly\ComposerSdk\VerificationOutcome;

/**
 * Integration tests using a real HTTP server
 * These tests start a PHP built-in server to handle HTTP requests
 */
class IntegrationTest extends TestCase
{
    private static ?int $serverPid = null;
    private static string $serverUrl = 'http://127.0.0.1:18089';

    public static function setUpBeforeClass(): void
    {
        // Start PHP built-in server in background
        $docRoot = __DIR__ . '/fixtures';
        if (!is_dir($docRoot)) {
            mkdir($docRoot, 0777, true);
        }
        
        // Create a simple server script
        $serverScript = $docRoot . '/server.php';
        file_put_contents($serverScript, '<?php
$method = $_SERVER["REQUEST_METHOD"];
$path = $_SERVER["REQUEST_URI"];
$body = file_get_contents("php://input");

header("Content-Type: application/json");

// Simulated state for polling tests
$stateFile = __DIR__ . "/server_state.json";
if (!file_exists($stateFile)) {
    file_put_contents($stateFile, json_encode(["pollCount" => 0]));
}
$state = json_decode(file_get_contents($stateFile), true);

// Handle checkpoint
if ($path === "/v1/checkpoint") {
    $data = json_decode($body, true);
    
    // Detect scenario from sessionId or event data
    $scenario = null;
    if (isset($_SERVER["HTTP_X_TEST_SCENARIO"])) {
        $scenario = $_SERVER["HTTP_X_TEST_SCENARIO"];
    } elseif (isset($data["event"]["data"]["scenario"])) {
        $scenario = $data["event"]["data"]["scenario"];
    } elseif (isset($data["sessionId"])) {
        // Parse scenario from session ID
        if (strpos($data["sessionId"], "polling") !== false) {
            $scenario = "polling";
        } elseif (strpos($data["sessionId"], "error") !== false) {
            $scenario = "error";
        } elseif (strpos($data["sessionId"], "denied") !== false) {
            $scenario = "denied";
        } elseif (strpos($data["sessionId"], "null-status") !== false) {
            $scenario = "null_status";
        } elseif (strpos($data["sessionId"], "max-failures") !== false) {
            $scenario = "max_failures";
        }
    }
    
    // Check if this is a polling test scenario
    if ($scenario !== null) {
        switch ($scenario) {
            case "polling":
                // Return PENDING initially
                $state["pollCount"] = 0;
                file_put_contents($stateFile, json_encode($state));
                echo json_encode([
                    "success" => true,
                    "version" => "v1",
                    "verification" => [
                        "id" => "test-poll-verify-id",
                        "status" => "PENDING",
                        "outcome" => "PENDING",
                        "stepData" => []
                    ]
                ]);
                exit;
            
            case "error":
                http_response_code(400);
                echo json_encode([
                    "success" => false,
                    "errors" => [
                        ["code" => 400, "message" => "Bad Request"]
                    ]
                ]);
                exit;
            
            case "invalid_json":
                echo "invalid json";
                exit;
            
            case "max_failures":
                // Return PENDING, then fail on subsequent polls
                $state["failCount"] = 0;
                file_put_contents($stateFile, json_encode($state));
                echo json_encode([
                    "success" => true,
                    "version" => "v1",
                    "verification" => [
                        "id" => "test-fail-verify-id",
                        "status" => "PENDING",
                        "outcome" => "PENDING",
                        "stepData" => []
                    ]
                ]);
                exit;
            
            case "null_status":
                // Return success but no verification
                $state["nullStatusCount"] = 0;
                file_put_contents($stateFile, json_encode($state));
                echo json_encode([
                    "success" => true,
                    "version" => "v1",
                    "verification" => [
                        "id" => "test-null-status-verify-id",
                        "status" => "PENDING",
                        "outcome" => "PENDING",
                        "stepData" => []
                    ]
                ]);
                exit;
            
            case "denied":
                // Return immediate DENIED
                echo json_encode([
                    "success" => true,
                    "version" => "v1",
                    "verification" => [
                        "id" => "test-denied-verify-id",
                        "status" => "COMPLETE",
                        "outcome" => "DENIED",
                        "stepData" => []
                    ]
                ]);
                exit;

            case "blocked_next_steps":
                echo json_encode([
                    "success" => true,
                    "version" => "v1",
                    "verification" => [
                        "id" => "test-blocked-verify-id",
                        "status" => "BLOCKED",
                        "outcome" => "PENDING",
                        "stepData" => [],
                        "nextSteps" => [["id" => "step-1", "verificationStepId" => "vs-1", "name" => "MFA", "url" => "https://example.com/mfa.js", "config" => ["key" => "value"]]]
                    ]
                ]);
                exit;

            case "no_ip":
                // Approve only when the request leaves out event.ip
                echo json_encode([
                    "success" => true,
                    "version" => "v1",
                    "verification" => [
                        "id" => "test-no-ip-verify-id",
                        "status" => "COMPLETE",
                        "outcome" => array_key_exists("ip", $data["event"]) ? "DENIED" : "APPROVED",
                        "stepData" => []
                    ]
                ]);
                exit;

            case "invalid_json_poll":
                echo json_encode([
                    "success" => true,
                    "version" => "v1",
                    "verification" => [
                        "id" => "test-invalid-json-poll-verify-id",
                        "status" => "PENDING",
                        "outcome" => "PENDING",
                        "stepData" => []
                    ]
                ]);
                exit;

            case "stall_poll":
                echo json_encode([
                    "success" => true,
                    "version" => "v1",
                    "verification" => [
                        "id" => "test-stall-poll-verify-id",
                        "status" => "PENDING",
                        "outcome" => "PENDING",
                        "stepData" => []
                    ]
                ]);
                exit;
        }
    }
    
    // Default: immediate approval
    echo json_encode([
        "success" => true,
        "version" => "v1",
        "verification" => [
            "id" => "test-verify-id",
            "status" => "COMPLETE",
            "outcome" => "APPROVED",
            "stepData" => []
        ]
    ]);
    exit;
}

// Handle verification polling
if (preg_match("#/v1/verification/(.+)#", $path, $matches)) {
    $verifyId = $matches[1];
    
    if ($verifyId === "test-poll-verify-id") {
        // Polling scenario: return PENDING first time, then COMPLETE
        $state["pollCount"]++;
        file_put_contents($stateFile, json_encode($state));
        
        if ($state["pollCount"] < 2) {
            echo json_encode([
                "success" => true,
                "version" => "v1",
                "verification" => [
                    "id" => $verifyId,
                    "status" => "PENDING",
                    "outcome" => "PENDING",
                    "stepData" => []
                ]
            ]);
        } else {
            echo json_encode([
                "success" => true,
                "version" => "v1",
                "verification" => [
                    "id" => $verifyId,
                    "status" => "COMPLETE",
                    "outcome" => "APPROVED",
                    "stepData" => []
                ]
            ]);
        }
        exit;
    }
    
    if ($verifyId === "test-fail-verify-id") {
        // Max failures scenario: return errors
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "errors" => [
                ["code" => 500, "message" => "Internal Server Error"]
            ]
        ]);
        exit;
    }

    if ($verifyId === "test-invalid-json-poll-verify-id") {
        echo "invalid json";
        exit;
    }

    if ($verifyId === "test-stall-poll-verify-id") {
        sleep(PHP_INT_MAX);
    }
    
    if ($verifyId === "test-null-status-verify-id") {
        // Null status scenario: return success but no verification first time
        $state["nullStatusCount"]++;
        file_put_contents($stateFile, json_encode($state));
        
        if ($state["nullStatusCount"] < 2) {
            echo json_encode([
                "success" => true,
                "version" => "v1"
                // No verification
            ]);
        } else {
            echo json_encode([
                "success" => true,
                "version" => "v1",
                "verification" => [
                    "id" => $verifyId,
                    "status" => "COMPLETE",
                    "outcome" => "APPROVED",
                    "stepData" => []
                ]
            ]);
        }
        exit;
    }
    
    // Default: immediate COMPLETE
    echo json_encode([
        "success" => true,
        "version" => "v1",
        "verification" => [
            "id" => $verifyId,
            "status" => "COMPLETE",
            "outcome" => "APPROVED",
            "stepData" => []
        ]
    ]);
    exit;
}

// Handle track
if ($path === "/v1/track") {
    $state["trackCount"] = ($state["trackCount"] ?? 0) + 1;
    file_put_contents($stateFile, json_encode($state));
    echo json_encode(["success" => true]);
    exit;
}

// 404 for unknown paths
http_response_code(404);
echo json_encode(["success" => false, "errors" => [["code" => 404, "message" => "Not Found"]]]);
');
        
        // Start server
        $cmd = sprintf(
            'php -S 127.0.0.1:18089 -t %s %s > /dev/null 2>&1 & echo $!',
            escapeshellarg($docRoot),
            escapeshellarg($serverScript)
        );
        
        $pid = trim(shell_exec($cmd));
        self::$serverPid = (int)$pid;
        
        // Wait for server to start
        usleep(500000); // 0.5 seconds
    }

    public static function tearDownAfterClass(): void
    {
        // Stop server
        if (self::$serverPid && function_exists('posix_kill')) {
            posix_kill(self::$serverPid, 15);
            // Clean up
            $docRoot = __DIR__ . '/fixtures';
            if (file_exists($docRoot . '/server.php')) {
                unlink($docRoot . '/server.php');
            }
            if (file_exists($docRoot . '/server_state.json')) {
                unlink($docRoot . '/server_state.json');
            }
            if (is_dir($docRoot)) {
                rmdir($docRoot);
            }
        }
    }

    public function testCheckpointWithRealHttp(): void
    {
        $config = new ComposerConfig(
            apiUrl: self::$serverUrl,
            apiVersion: ComposerApiVersion::V1,
            isEnabled: true
        );
        
        $composer = new Composer('test-key', $config);
        
        $options = new CheckpointOptions(
            checkpointName: 'TEST_CHECKPOINT',
            event: new CheckpointEvent(
                ip: '127.0.0.1',
                data: []
            ),
            sessionId: 'session123'
        );
        
        $response = $composer->checkpoint($options);
        
        $this->assertTrue($response->success);
        $this->assertEquals(VerificationStatus::COMPLETE, $response->verification->status);
        $this->assertEquals(VerificationOutcome::APPROVED, $response->verification->outcome);
    }

    public function testEventWithRealHttp(): void
    {
        $config = new ComposerConfig(
            apiUrl: self::$serverUrl,
            apiVersion: ComposerApiVersion::V1,
            isEnabled: true
        );
        
        $composer = new Composer('test-key', $config);
        
        $options = new TrackOptions(
            event: new TrackEvent(
                type: 'TEST_EVENT',
                data: ['key' => 'value']
            ),
            sessionId: 'session123'
        );
        
        // Should not throw - event() returns void
        $composer->event($options);
        
        // If we got here without exception, the test passes
        $this->assertTrue(true);
    }

    public function testCheckpointWithSyncTrue(): void
    {
        $config = new ComposerConfig(
            apiUrl: self::$serverUrl,
            apiVersion: ComposerApiVersion::V1,
            isEnabled: true
        );
        
        $composer = new Composer('test-key', $config);
        
        $options = new CheckpointOptions(
            checkpointName: 'TEST_CHECKPOINT',
            event: new CheckpointEvent(
                ip: '127.0.0.1',
                data: []
            ),
            sessionId: 'session123',
            options: new CheckpointResponseOptions(
                sync: true,
                timeout: 5000
            )
        );
        
        $response = $composer->checkpoint($options);
        
        $this->assertTrue($response->success);
    }

    public function testCheckpointWithCustomTimeout(): void
    {
        $config = new ComposerConfig(
            apiUrl: self::$serverUrl,
            apiVersion: ComposerApiVersion::V1,
            isEnabled: true
        );
        
        $composer = new Composer('test-key', $config);
        
        $options = new CheckpointOptions(
            checkpointName: 'TEST_CHECKPOINT',
            event: new CheckpointEvent(
                ip: '127.0.0.1',
                data: []
            ),
            sessionId: 'session123',
            options: new CheckpointResponseOptions(
                timeout: 30000
            )
        );
        
        $response = $composer->checkpoint($options);
        
        $this->assertTrue($response->success);
    }

    public function testCheckpointWithZeroTimeout(): void
    {
        $config = new ComposerConfig(
            apiUrl: self::$serverUrl,
            apiVersion: ComposerApiVersion::V1,
            isEnabled: true
        );
        
        $composer = new Composer('test-key', $config);
        
        $options = new CheckpointOptions(
            checkpointName: 'TEST_CHECKPOINT',
            event: new CheckpointEvent(
                ip: '127.0.0.1',
                data: []
            ),
            sessionId: 'session123',
            options: new CheckpointResponseOptions(
                timeout: 0  // Should use default
            )
        );
        
        $response = $composer->checkpoint($options);
        
        $this->assertTrue($response->success);
    }

    public function testCheckpointWithNegativeTimeout(): void
    {
        $config = new ComposerConfig(
            apiUrl: self::$serverUrl,
            apiVersion: ComposerApiVersion::V1,
            isEnabled: true
        );
        
        $composer = new Composer('test-key', $config);
        
        $options = new CheckpointOptions(
            checkpointName: 'TEST_CHECKPOINT',
            event: new CheckpointEvent(
                ip: '127.0.0.1',
                data: []
            ),
            sessionId: 'session123',
            options: new CheckpointResponseOptions(
                timeout: -100  // Should use default
            )
        );
        
        $response = $composer->checkpoint($options);
        
        $this->assertTrue($response->success);
    }

    public function testCheckpointWithPolling(): void
    {
        $config = new ComposerConfig(
            apiUrl: self::$serverUrl,
            apiVersion: ComposerApiVersion::V1,
            isEnabled: true
        );
        
        $composer = new Composer('test-key', $config);
        
        // Reset state for polling
        $stateFile = __DIR__ . '/fixtures/server_state.json';
        file_put_contents($stateFile, json_encode(['pollCount' => 0]));
        
        $options = new CheckpointOptions(
            checkpointName: 'TEST_CHECKPOINT',
            event: new CheckpointEvent(
                ip: '127.0.0.1',
                data: ['scenario' => 'polling']
            ),
            sessionId: 'session-polling-test',
            options: new CheckpointResponseOptions(
                sync: true,
                timeout: 5000
            )
        );
        
        // This should trigger polling because server returns PENDING initially
        $response = $composer->checkpoint($options);
        
        $this->assertTrue($response->success);
        $this->assertEquals(VerificationStatus::COMPLETE, $response->verification->status);
        $this->assertEquals(VerificationOutcome::APPROVED, $response->verification->outcome);
    }

    public function testCheckpointWithDeniedOutcome(): void
    {
        $config = new ComposerConfig(
            apiUrl: self::$serverUrl,
            apiVersion: ComposerApiVersion::V1,
            isEnabled: true
        );
        
        $composer = new Composer('test-key', $config);
        
        $options = new CheckpointOptions(
            checkpointName: 'TEST_CHECKPOINT',
            event: new CheckpointEvent(
                ip: '127.0.0.1',
                data: ['scenario' => 'denied']
            ),
            sessionId: 'session-denied-test'
        );
        
        $response = $composer->checkpoint($options);
        
        $this->assertTrue($response->success);
        $this->assertEquals(VerificationStatus::COMPLETE, $response->verification->status);
        $this->assertEquals(VerificationOutcome::DENIED, $response->verification->outcome);
        $this->assertTrue($composer->isDenied($response));
        $this->assertFalse($composer->isAllowed($response));
    }

    public function testCheckpointWithErrorHandling(): void
    {
        $config = new ComposerConfig(
            apiUrl: self::$serverUrl,
            apiVersion: ComposerApiVersion::V1,
            isEnabled: true
        );
        
        $composer = new Composer('test-key', $config);
        
        $options = new CheckpointOptions(
            checkpointName: 'TEST_CHECKPOINT',
            event: new CheckpointEvent(
                ip: '127.0.0.1',
                data: ['scenario' => 'error']
            ),
            sessionId: 'session-error-test'
        );
        
        $response = $composer->checkpoint($options);
        
        $this->assertFalse($response->success);
        $this->assertNotEmpty($response->errors);
        $this->assertTrue($composer->hasError($response));
    }

    public function testCheckpointWithSyncFalse(): void
    {
        $config = new ComposerConfig(
            apiUrl: self::$serverUrl,
            apiVersion: ComposerApiVersion::V1,
            isEnabled: true
        );
        
        $composer = new Composer('test-key', $config);
        
        $options = new CheckpointOptions(
            checkpointName: 'TEST_CHECKPOINT',
            event: new CheckpointEvent(
                ip: '127.0.0.1',
                data: []
            ),
            sessionId: 'session123',
            options: new CheckpointResponseOptions(
                sync: false  // Should not poll
            )
        );
        
        $response = $composer->checkpoint($options);
        
        $this->assertTrue($response->success);
    }

    public function testCheckpointWithShortTimeout(): void
    {
        $config = new ComposerConfig(
            apiUrl: self::$serverUrl,
            apiVersion: ComposerApiVersion::V1,
            isEnabled: true
        );
        
        $composer = new Composer('test-key', $config);
        
        // Reset state for polling scenario
        $stateFile = __DIR__ . '/fixtures/server_state.json';
        file_put_contents($stateFile, json_encode(['pollCount' => 0]));
        
        $options = new CheckpointOptions(
            checkpointName: 'TEST_CHECKPOINT',
            event: new CheckpointEvent(
                ip: '127.0.0.1',
                data: ['scenario' => 'polling']
            ),
            sessionId: 'session-timeout-test',
            options: new CheckpointResponseOptions(
                sync: true,
                timeout: 50  // Very short timeout, might not complete polling
            )
        );
        
        $response = $composer->checkpoint($options);
        
        // Should return some response even with short timeout
        $this->assertInstanceOf(\Spreedly\ComposerSdk\ComposerCheckpointResponse::class, $response);
    }

    public function testCheckpointReturnsErrorWhenBudgetEndsAfterInvalidJsonPoll(): void
    {
        $composer = new Composer('test-key', new ComposerConfig(
            apiUrl: self::$serverUrl,
            apiVersion: ComposerApiVersion::V1,
            logLevel: 'NONE'
        ));

        // The single poll returns invalid JSON, then the budget runs out before the failure cap.
        $response = $composer->checkpoint(new CheckpointOptions(
            checkpointName: 'TEST_CHECKPOINT',
            event: new CheckpointEvent(
                ip: '127.0.0.1',
                data: ['scenario' => 'invalid_json_poll']
            ),
            sessionId: 'session-invalid-json-poll',
            options: new CheckpointResponseOptions(timeout: 50)
        ));

        $this->assertFalse($response->success);
        $this->assertSame(500, $response->errors[0]->code);
        $this->assertSame('Unknown evaluation error', $response->errors[0]->message);
    }

    public function testCheckpointReturnsNextStepsForBlockedVerification(): void
    {
        $composer = new Composer('test-key', new ComposerConfig(
            apiUrl: self::$serverUrl,
            apiVersion: ComposerApiVersion::V1
        ));

        $response = $composer->checkpoint(new CheckpointOptions(
            checkpointName: 'TEST_CHECKPOINT',
            event: new CheckpointEvent('127.0.0.1', ['scenario' => 'blocked_next_steps']),
            sessionId: 'session123'
        ));

        $this->assertTrue($composer->isRunning($response));
        $this->assertSame('step-1', $response->verification->nextSteps[0]['id']);
        $this->assertSame('MFA', json_decode(json_encode($response->verification), true)['nextSteps'][0]['name']);
    }

    public function testCheckpointOmitsEventIpAndDefaultsApiVersion(): void
    {
        $composer = new Composer('test-key', new ComposerConfig(apiUrl: self::$serverUrl));

        foreach ([new CheckpointEvent(data: ['scenario' => 'no_ip']), new CheckpointEvent('', ['scenario' => 'no_ip'])] as $event) {
            $response = $composer->checkpoint(new CheckpointOptions(
                checkpointName: 'TEST_CHECKPOINT',
                event: $event,
                sessionId: 'session123'
            ));

            $this->assertTrue($composer->isAllowed($response));
        }
    }

    public function testCheckpointDisabledSdk(): void
    {
        $config = new ComposerConfig(
            apiUrl: self::$serverUrl,
            apiVersion: ComposerApiVersion::V1,
            isEnabled: false  // SDK disabled
        );
        
        $composer = new Composer('test-key', $config);
        
        $options = new CheckpointOptions(
            checkpointName: 'TEST_CHECKPOINT',
            event: new CheckpointEvent(
                ip: '127.0.0.1',
                data: []
            ),
            sessionId: 'session123'
        );
        
        $response = $composer->checkpoint($options);
        
        $this->assertTrue($response->success);
        $this->assertEquals('COMPOSER_IS_DISABLED', $response->verification->id);
        $this->assertEquals(VerificationStatus::COMPLETE, $response->verification->status);
        $this->assertEquals(VerificationOutcome::APPROVED, $response->verification->outcome);
    }

    public function testEventWithDisabledSdk(): void
    {
        $config = new ComposerConfig(
            apiUrl: self::$serverUrl,
            apiVersion: ComposerApiVersion::V1,
            isEnabled: false
        );
        
        $composer = new Composer('test-key', $config);
        
        $options = new TrackOptions(
            event: new TrackEvent(
                type: 'TEST_EVENT',
                data: []
            ),
            sessionId: 'session123'
        );
        
        $stateFile = __DIR__ . '/fixtures/server_state.json';
        file_put_contents($stateFile, json_encode(['pollCount' => 0, 'trackCount' => 0]));

        $composer->event($options);

        $state = json_decode(file_get_contents($stateFile), true);
        $this->assertSame(0, $state['trackCount']);
    }

    public function testCheckpointHelperMethods(): void
    {
        $config = new ComposerConfig(
            apiUrl: self::$serverUrl,
            apiVersion: ComposerApiVersion::V1,
            isEnabled: true
        );
        
        $composer = new Composer('test-key', $config);
        
        // Test with PENDING response
        $pendingResponse = new \Spreedly\ComposerSdk\ComposerCheckpointResponse(
            success: true,
            errors: [],
            version: ComposerApiVersion::V1,
            verification: new \Spreedly\ComposerSdk\ComposerVerification(
                id: 'test123',
                status: VerificationStatus::PENDING,
                outcome: VerificationOutcome::PENDING,
                stepData: []
            )
        );
        
        $this->assertTrue($composer->isRunning($pendingResponse));
        $this->assertFalse($composer->isUndecided($pendingResponse)); // isUndecided requires COMPLETE status
        $this->assertFalse($composer->isAllowed($pendingResponse));
        $this->assertFalse($composer->isDenied($pendingResponse));
        $this->assertFalse($composer->hasError($pendingResponse));
        $this->assertFalse($composer->isTimeout($pendingResponse));
    }

    public function testUtilitiesMakeRequestDirectly(): void
    {
        // Direct test of makeRequest
        $result = \Spreedly\ComposerSdk\Utilities::makeRequest(
            url: self::$serverUrl . '/v1/checkpoint',
            method: 'POST',
            headers: ['Content-Type' => 'application/json'],
            body: [
                'event' => [
                    'type' => 'TEST',
                    'ip' => '127.0.0.1',
                    'data' => []
                ]
            ]
        );
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('statusCode', $result);
        $this->assertArrayHasKey('body', $result);
        $this->assertEquals(200, $result['statusCode']);
    }

    public function testUtilitiesMakeRequestGet(): void
    {
        // Test GET request
        $result = \Spreedly\ComposerSdk\Utilities::makeRequest(
            url: self::$serverUrl . '/v1/track',
            method: 'GET',
            headers: ['Content-Type' => 'application/json']
        );
        
        $this->assertIsArray($result);
    }

    public function testCheckpointPollingTimeoutIndependentFromRequestTimeout(): void
    {
        $config = new ComposerConfig(
            apiUrl: self::$serverUrl,
            apiVersion: ComposerApiVersion::V1,
            isEnabled: true,
            requestTimeoutMs: 500
        );

        $composer = new Composer('test-key', $config);

        $start = microtime(true);

        $response = $composer->checkpoint(new CheckpointOptions(
            checkpointName: 'TEST_CHECKPOINT',
            event: new CheckpointEvent(
                ip: '127.0.0.1',
                data: ['scenario' => 'stall_poll']
            ),
            sessionId: 'session-stall-poll-test',
            options: new CheckpointResponseOptions(
                sync: true,
                timeout: 10000
            )
        ));

        $elapsedMs = (microtime(true) - $start) * 1000;

        $this->assertFalse($response->success);
        $this->assertTrue($composer->isTimeout($response));
        $this->assertLessThan(8000, $elapsedMs);
    }
}
