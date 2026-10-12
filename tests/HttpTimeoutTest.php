<?php

namespace Spreedly\ComposerSdk\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Spreedly\ComposerSdk\Constants;
use Spreedly\ComposerSdk\Utilities;

/**
 * HTTP timeout behavior for Utilities::makeRequest.
 */
class HttpTimeoutTest extends TestCase
{
    private static ?int $serverPid = null;
    private static string $serverUrl = 'http://127.0.0.1:18090';

    public static function setUpBeforeClass(): void
    {
        $docRoot = __DIR__ . '/fixtures/http-timeout';
        if (!is_dir($docRoot)) {
            mkdir($docRoot, 0777, true);
        }

        $serverScript = $docRoot . '/server.php';
        file_put_contents($serverScript, '<?php
$path = $_SERVER["REQUEST_URI"];

if ($path === "/v1/stall") {
    sleep(PHP_INT_MAX);
}

http_response_code(404);
echo json_encode(["success" => false]);
');

        $cmd = sprintf(
            'php -S 127.0.0.1:18090 -t %s %s > /dev/null 2>&1 & echo $!',
            escapeshellarg($docRoot),
            escapeshellarg($serverScript)
        );

        $pid = trim((string) shell_exec($cmd));
        self::$serverPid = (int) $pid;
        usleep(500000);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverPid && function_exists('posix_kill')) {
            posix_kill(self::$serverPid, 15);
            $docRoot = __DIR__ . '/fixtures/http-timeout';
            if (file_exists($docRoot . '/server.php')) {
                unlink($docRoot . '/server.php');
            }
            if (is_dir($docRoot)) {
                rmdir($docRoot);
            }
        }
    }

    public function testDefaultRequestTimeoutMsConstant(): void
    {
        $this->assertSame(30000, Constants::DEFAULT_REQUEST_TIMEOUT_MS);
        $this->assertSame(30000, Constants::getDefaultConfig()->requestTimeoutMs);
    }

    public function testMakeRequestWithShortRequestTimeoutOverride(): void
    {
        $start = microtime(true);

        $result = Utilities::makeRequest(
            url: self::$serverUrl . '/v1/stall',
            method: 'GET',
            headers: ['Content-Type' => 'application/json'],
            body: null,
            requestTimeoutMs: 500
        );

        $elapsedMs = (microtime(true) - $start) * 1000;

        $this->assertEquals(500, $result['statusCode']);
        $this->assertLessThan(3000, $elapsedMs);
    }

    /**
     * Runs for the full 30s default, since a class constant cannot be shortened at runtime.
     * Excluded from `composer test`; run it with `composer test-slow`.
     */
    #[Group('slow')]
    #[DataProvider('unusableRequestTimeoutProvider')]
    public function testUnusableRequestTimeoutFallsBackToDefault(?int $requestTimeoutMs): void
    {
        $expectedMs = Constants::DEFAULT_REQUEST_TIMEOUT_MS;
        $start = microtime(true);

        $result = Utilities::makeRequest(
            url: self::$serverUrl . '/v1/stall',
            method: 'GET',
            headers: ['Content-Type' => 'application/json'],
            body: null,
            requestTimeoutMs: $requestTimeoutMs
        );

        $elapsedMs = (microtime(true) - $start) * 1000;

        $this->assertEquals(500, $result['statusCode']);
        $this->assertGreaterThanOrEqual($expectedMs * 0.8, $elapsedMs);
        $this->assertLessThan($expectedMs * 1.5, $elapsedMs);
    }

    /**
     * @return array<string, array{0: int|null}>
     */
    public static function unusableRequestTimeoutProvider(): array
    {
        return [
            'no timeout argument' => [null],
            'zero, which cURL treats as no timeout' => [0],
        ];
    }
}
