<?php

namespace Spreedly\ComposerSdk\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Spreedly\ComposerSdk\Logger;
use Spreedly\ComposerSdk\Severity;
use Spreedly\ComposerSdk\Utilities;

class UtilitiesTest extends TestCase
{
    public function testConstructApiUrl(): void
    {
        $url1 = Utilities::constructApiUrl('https://api.dodgeballhq.com/', 'v1');
        $url2 = Utilities::constructApiUrl('https://api.dodgeballhq.com', 'v1');

        $this->assertEquals('https://api.dodgeballhq.com/v1/', $url1);
        $this->assertEquals('https://api.dodgeballhq.com/v1/', $url2);
    }

    public function testConstructApiHeadersWithOnlyToken(): void
    {
        $headers = Utilities::constructApiHeaders('test-secret-key');
        
        $this->assertArrayHasKey('Dodgeball-Secret-Key', $headers);
        $this->assertEquals('test-secret-key', $headers['Dodgeball-Secret-Key']);
        $this->assertArrayHasKey('Composer-SDK-Version', $headers);
        $this->assertArrayHasKey('Composer-SDK-Source', $headers);
        $this->assertEquals('php-sdk', $headers['Composer-SDK-Source']);
    }

    public function testConstructApiHeadersWithVerificationId(): void
    {
        $headers = Utilities::constructApiHeaders('test-secret-key', 'test-verification-id');
        
        $this->assertArrayHasKey('Dodgeball-Verification-Id', $headers);
        $this->assertEquals('test-verification-id', $headers['Dodgeball-Verification-Id']);
    }

    public function testConstructApiHeadersWithSourceToken(): void
    {
        $headers = Utilities::constructApiHeaders('test-secret-key', '', 'test-source-token');
        
        $this->assertArrayHasKey('Dodgeball-Source-Token', $headers);
        $this->assertEquals('test-source-token', $headers['Dodgeball-Source-Token']);
    }

    public function testConstructApiHeadersWithCustomerId(): void
    {
        $headers = Utilities::constructApiHeaders('test-secret-key', '', '', 'test-customer-id');
        
        $this->assertArrayHasKey('Dodgeball-Customer-Id', $headers);
        $this->assertEquals('test-customer-id', $headers['Dodgeball-Customer-Id']);
    }

    public function testConstructApiHeadersWithSessionId(): void
    {
        $headers = Utilities::constructApiHeaders('test-secret-key', '', '', '', 'test-session-id');
        
        $this->assertArrayHasKey('Dodgeball-Session-Id', $headers);
        $this->assertEquals('test-session-id', $headers['Dodgeball-Session-Id']);
    }

    public function testIsNullOrEmpty(): void
    {
        $this->assertTrue(Utilities::isNullOrEmpty(null));
        $this->assertTrue(Utilities::isNullOrEmpty(''));
        $this->assertTrue(Utilities::isNullOrEmpty('  '));
        $this->assertTrue(Utilities::isNullOrEmpty('null'));
        $this->assertTrue(Utilities::isNullOrEmpty('NULL'));
        $this->assertTrue(Utilities::isNullOrEmpty('undefined'));
        $this->assertTrue(Utilities::isNullOrEmpty('UNDEFINED'));
        
        $this->assertFalse(Utilities::isNullOrEmpty('test'));
        $this->assertFalse(Utilities::isNullOrEmpty('0'));
        $this->assertFalse(Utilities::isNullOrEmpty(0));
        $this->assertFalse(Utilities::isNullOrEmpty(false));
    }

    public function testSleep(): void
    {
        $start = microtime(true);
        Utilities::sleep(50);
        $end = microtime(true);
        $elapsed = ($end - $start) * 1000;
        $this->assertGreaterThanOrEqual(40, $elapsed);
    }

    #[DataProvider('unsendableHeaderValues')]
    public function testMakeRequestRejectsUnsendableHeaderValuesWithoutLeakingThem(string $value): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'composer-header');
        $originalErrorLog = (string) ini_get('error_log');
        $originalFilter = Logger::$filterLevel;
        ini_set('error_log', $logFile);
        Logger::$filterLevel = Severity::ERROR;

        try {
            $result = Utilities::makeRequest(
                url: 'http://127.0.0.1:1/v1/checkpoint',
                method: 'POST',
                headers: ['Dodgeball-Secret-Key' => $value],
                body: ['key' => 'value']
            );
            $logged = (string) file_get_contents($logFile);
        } finally {
            Logger::$filterLevel = $originalFilter;
            ini_set('error_log', $originalErrorLog);
            unlink($logFile);
        }

        $body = json_decode($result['body'], true);
        $this->assertSame(500, $result['statusCode']);
        $this->assertTrue($result['requestFailed']);
        $this->assertFalse($body['success']);
        $this->assertSame(500, $body['errors'][0]['code']);
        $this->assertStringContainsString(
            "A request header has a character that can't be sent, such as a newline.",
            $logged
        );
        $this->assertStringNotContainsString('sk_test_FAKE', $logged);
        $this->assertStringNotContainsString('X-Injected', $logged);
        $this->assertStringNotContainsString('There was an error making a request.', $logged);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsendableHeaderValues(): array
    {
        return [
            'LF in the middle' => ["sk_test_FAKE\n123"],
            'CR in the middle' => ["sk_test_FAKE\r123"],
            'injected header' => ["abc\r\nX-Injected: yes"],
            'leading CRLF' => ["\r\nX-Injected: yes"],
            'NUL' => ["user\0id"],
        ];
    }

    public function testMakeRequestWithInvalidUrl(): void
    {
        $result = Utilities::makeRequest(
            url: 'http://invalid-url-that-does-not-exist-12345.com',
            method: 'POST',
            headers: ['Content-Type' => 'application/json'],
            body: ['test' => 'data']
        );
        
        $this->assertEquals(500, $result['statusCode']);
        $body = json_decode($result['body'], true);
        $this->assertFalse($body['success']);
        $this->assertArrayHasKey('errors', $body);
    }

    public function testMakeRequestErrorHandling(): void
    {
        $result = Utilities::makeRequest(
            url: '',
            method: 'GET',
            headers: []
        );
        
        $this->assertEquals(500, $result['statusCode']);
    }

    public function testConstructApiUrlEdgeCases(): void
    {
        $url1 = Utilities::constructApiUrl('https://api.example.com/', 'v1');
        $this->assertEquals('https://api.example.com/v1/', $url1);
        
        $url2 = Utilities::constructApiUrl('https://api.example.com', 'v1');
        $this->assertEquals('https://api.example.com/v1/', $url2);
        
        $url3 = Utilities::constructApiUrl('https://api.example.com//', 'v2');
        $this->assertEquals('https://api.example.com//v2/', $url3);
    }

    public function testConstructApiHeadersAllParameters(): void
    {
        $headers = Utilities::constructApiHeaders(
            token: 'secret123',
            verificationId: 'verify456',
            sourceToken: 'source789',
            customerId: 'customer101',
            sessionId: 'session202'
        );
        
        $this->assertArrayHasKey('Dodgeball-Secret-Key', $headers);
        $this->assertEquals('secret123', $headers['Dodgeball-Secret-Key']);
        
        $this->assertArrayHasKey('Dodgeball-Verification-Id', $headers);
        $this->assertEquals('verify456', $headers['Dodgeball-Verification-Id']);
        
        $this->assertArrayHasKey('Dodgeball-Source-Token', $headers);
        $this->assertEquals('source789', $headers['Dodgeball-Source-Token']);
        
        $this->assertArrayHasKey('Dodgeball-Customer-Id', $headers);
        $this->assertEquals('customer101', $headers['Dodgeball-Customer-Id']);
        
        $this->assertArrayHasKey('Dodgeball-Session-Id', $headers);
        $this->assertEquals('session202', $headers['Dodgeball-Session-Id']);
        
        $this->assertArrayHasKey('Composer-SDK-Version', $headers);
        $this->assertArrayHasKey('Composer-SDK-Source', $headers);
        $this->assertEquals('php-sdk', $headers['Composer-SDK-Source']);
    }

    public function testIsNullOrEmptyWithVariousInputs(): void
    {
        $this->assertTrue(Utilities::isNullOrEmpty(null));
        $this->assertTrue(Utilities::isNullOrEmpty(''));
        $this->assertTrue(Utilities::isNullOrEmpty('   '));
        $this->assertTrue(Utilities::isNullOrEmpty('null'));
        $this->assertTrue(Utilities::isNullOrEmpty('NULL'));
        $this->assertTrue(Utilities::isNullOrEmpty('Null'));
        $this->assertTrue(Utilities::isNullOrEmpty('undefined'));
        $this->assertTrue(Utilities::isNullOrEmpty('UNDEFINED'));
        $this->assertTrue(Utilities::isNullOrEmpty('Undefined'));
        
        $this->assertFalse(Utilities::isNullOrEmpty('test'));
        $this->assertFalse(Utilities::isNullOrEmpty('0'));
        $this->assertFalse(Utilities::isNullOrEmpty('false'));
        $this->assertFalse(Utilities::isNullOrEmpty(0));
        $this->assertFalse(Utilities::isNullOrEmpty(false));
    }
}
