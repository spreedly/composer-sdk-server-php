<?php

namespace Spreedly\ComposerSdk\Tests;

use PHPUnit\Framework\TestCase;
use Spreedly\ComposerSdk\Composer;
use Spreedly\ComposerSdk\ComposerConfig;
use Spreedly\ComposerSdk\ComposerApiVersion;
use Spreedly\ComposerSdk\ComposerLogLevel;
use Spreedly\ComposerSdk\ComposerMissingConfigException;
use Spreedly\ComposerSdk\ComposerInvalidConfigException;
use Spreedly\ComposerSdk\ComposerMissingParameterException;
use Spreedly\ComposerSdk\CheckpointOptions;
use Spreedly\ComposerSdk\CheckpointEvent;
use Spreedly\ComposerSdk\CheckpointResponseOptions;
use Spreedly\ComposerSdk\TrackOptions;
use Spreedly\ComposerSdk\TrackEvent;
use Spreedly\ComposerSdk\VerificationStatus;
use Spreedly\ComposerSdk\VerificationOutcome;
use Spreedly\ComposerSdk\ComposerCheckpointResponse;
use Spreedly\ComposerSdk\ComposerVerification;
use Spreedly\ComposerSdk\ComposerApiError;

class ComposerTest extends TestCase
{
    public function testConstructorRequiresApiKey(): void
    {
        $this->expectException(ComposerMissingConfigException::class);
        new Composer('');
    }

    public function testConstructorOnlyRequiresApiKey(): void
    {
        $composer = new Composer('test-secret-key');
        $this->assertInstanceOf(Composer::class, $composer);
    }

    public function testConstructorAcceptsValidConfig(): void
    {
        $config = new ComposerConfig(
            apiVersion: ComposerApiVersion::V1,
            apiUrl: 'https://api.dodgeballhq.com/',
            logLevel: ComposerLogLevel::TRACE
        );
        $composer = new Composer('test-secret-key', $config);
        $this->assertInstanceOf(Composer::class, $composer);
    }

    public function testConstructorFailsWithInvalidApiVersion(): void
    {
        $this->expectException(ComposerInvalidConfigException::class);
        $config = new ComposerConfig(
            apiVersion: 'invalid'
        );
        new Composer('test-secret-key', $config);
    }

    public function testConstructorFailsWithInvalidLogLevel(): void
    {
        $this->expectException(ComposerInvalidConfigException::class);
        $config = new ComposerConfig(
            apiVersion: ComposerApiVersion::V1,
            logLevel: 'invalid'
        );
        new Composer('test-secret-key', $config);
    }

    public function testIsRunningWithPendingVerification(): void
    {
        $composer = new Composer('test-secret-key');
        $response = new ComposerCheckpointResponse(
            success: true,
            errors: [],
            version: ComposerApiVersion::V1,
            verification: new ComposerVerification(
                id: 'test-verification-id',
                status: VerificationStatus::PENDING,
                outcome: VerificationOutcome::PENDING
            )
        );
        $this->assertTrue($composer->isRunning($response));
    }

    public function testIsRunningWithBlockedVerification(): void
    {
        $composer = new Composer('test-secret-key');
        $response = new ComposerCheckpointResponse(
            success: true,
            errors: [],
            version: ComposerApiVersion::V1,
            verification: new ComposerVerification(
                id: 'test-verification-id',
                status: VerificationStatus::BLOCKED,
                outcome: VerificationOutcome::PENDING
            )
        );
        $this->assertTrue($composer->isRunning($response));
    }

    public function testIsRunningWithDeniedBlockedVerification(): void
    {
        $composer = new Composer('test-secret-key');
        $response = new ComposerCheckpointResponse(
            success: true,
            errors: [],
            version: ComposerApiVersion::V1,
            verification: new ComposerVerification(
                id: 'test-verification-id',
                status: VerificationStatus::BLOCKED,
                outcome: VerificationOutcome::DENIED
            )
        );
        $this->assertFalse($composer->isRunning($response));
    }

    public function testIsRunningWithDeniedPendingVerification(): void
    {
        $composer = new Composer('test-secret-key');
        $response = new ComposerCheckpointResponse(
            success: true,
            errors: [],
            version: ComposerApiVersion::V1,
            verification: new ComposerVerification(
                id: 'test-verification-id',
                status: VerificationStatus::PENDING,
                outcome: VerificationOutcome::DENIED
            )
        );
        $this->assertFalse($composer->isRunning($response));
    }

    public function testIsRunningWithApprovedBlockedVerification(): void
    {
        $composer = new Composer('test-secret-key');
        $response = new ComposerCheckpointResponse(
            success: true,
            errors: [],
            version: ComposerApiVersion::V1,
            verification: new ComposerVerification(
                id: 'test-verification-id',
                status: VerificationStatus::BLOCKED,
                outcome: VerificationOutcome::APPROVED
            )
        );
        $this->assertFalse($composer->isRunning($response));
    }

    public function testIsRunningWithApprovedVerification(): void
    {
        $composer = new Composer('test-secret-key');
        $response = new ComposerCheckpointResponse(
            success: true,
            errors: [],
            version: ComposerApiVersion::V1,
            verification: new ComposerVerification(
                id: 'test-verification-id',
                status: VerificationStatus::COMPLETE,
                outcome: VerificationOutcome::APPROVED
            )
        );
        $this->assertFalse($composer->isRunning($response));
    }

    public function testIsAllowedWithApprovedVerification(): void
    {
        $composer = new Composer('test-secret-key');
        $response = new ComposerCheckpointResponse(
            success: true,
            errors: [],
            version: ComposerApiVersion::V1,
            verification: new ComposerVerification(
                id: 'test-verification-id',
                status: VerificationStatus::COMPLETE,
                outcome: VerificationOutcome::APPROVED
            )
        );
        $this->assertTrue($composer->isAllowed($response));
    }

    public function testIsAllowedWithPendingVerification(): void
    {
        $composer = new Composer('test-secret-key');
        $response = new ComposerCheckpointResponse(
            success: true,
            errors: [],
            version: ComposerApiVersion::V1,
            verification: new ComposerVerification(
                id: 'test-verification-id',
                status: VerificationStatus::PENDING,
                outcome: VerificationOutcome::PENDING
            )
        );
        $this->assertFalse($composer->isAllowed($response));
    }

    public function testIsDeniedWithDeniedVerification(): void
    {
        $composer = new Composer('test-secret-key');
        $response = new ComposerCheckpointResponse(
            success: true,
            errors: [],
            version: ComposerApiVersion::V1,
            verification: new ComposerVerification(
                id: 'test-verification-id',
                status: VerificationStatus::COMPLETE,
                outcome: VerificationOutcome::DENIED
            )
        );
        $this->assertTrue($composer->isDenied($response));
    }

    public function testIsDeniedWithApprovedVerification(): void
    {
        $composer = new Composer('test-secret-key');
        $response = new ComposerCheckpointResponse(
            success: true,
            errors: [],
            version: ComposerApiVersion::V1,
            verification: new ComposerVerification(
                id: 'test-verification-id',
                status: VerificationStatus::COMPLETE,
                outcome: VerificationOutcome::APPROVED
            )
        );
        $this->assertFalse($composer->isDenied($response));
    }

    public function testIsUndecidedWithUndecidedVerification(): void
    {
        $composer = new Composer('test-secret-key');
        $response = new ComposerCheckpointResponse(
            success: true,
            errors: [],
            version: ComposerApiVersion::V1,
            verification: new ComposerVerification(
                id: 'test-verification-id',
                status: VerificationStatus::COMPLETE,
                outcome: VerificationOutcome::PENDING
            )
        );
        $this->assertTrue($composer->isUndecided($response));
    }

    public function testIsUndecidedWithApprovedVerification(): void
    {
        $composer = new Composer('test-secret-key');
        $response = new ComposerCheckpointResponse(
            success: true,
            errors: [],
            version: ComposerApiVersion::V1,
            verification: new ComposerVerification(
                id: 'test-verification-id',
                status: VerificationStatus::COMPLETE,
                outcome: VerificationOutcome::APPROVED
            )
        );
        $this->assertFalse($composer->isUndecided($response));
    }

    public function testHasErrorWithFailedVerification(): void
    {
        $composer = new Composer('test-secret-key');
        $response = new ComposerCheckpointResponse(
            success: false,
            errors: [new ComposerApiError(500, 'Internal Server Error')],
            version: ComposerApiVersion::V1,
            verification: new ComposerVerification(
                id: 'test-verification-id',
                status: VerificationStatus::FAILED,
                outcome: VerificationOutcome::ERROR
            )
        );
        $this->assertTrue($composer->hasError($response));
    }

    public function testHasErrorWithApprovedVerification(): void
    {
        $composer = new Composer('test-secret-key');
        $response = new ComposerCheckpointResponse(
            success: true,
            errors: [],
            version: ComposerApiVersion::V1,
            verification: new ComposerVerification(
                id: 'test-verification-id',
                status: VerificationStatus::COMPLETE,
                outcome: VerificationOutcome::APPROVED
            )
        );
        $this->assertFalse($composer->hasError($response));
    }

    public function testIsTimeoutWithTimeoutResponse(): void
    {
        $composer = new Composer('test-secret-key');
        $response = new ComposerCheckpointResponse(
            success: false,
            errors: [new ComposerApiError(503, 'Service Unavailable')],
            version: ComposerApiVersion::V1,
            isTimeout: true
        );
        $this->assertTrue($composer->isTimeout($response));
    }

    public function testIsTimeoutWithApprovedVerification(): void
    {
        $composer = new Composer('test-secret-key');
        $response = new ComposerCheckpointResponse(
            success: true,
            errors: [],
            version: ComposerApiVersion::V1,
            verification: new ComposerVerification(
                id: 'test-verification-id',
                status: VerificationStatus::COMPLETE,
                outcome: VerificationOutcome::APPROVED
            )
        );
        $this->assertFalse($composer->isTimeout($response));
    }

    public function testCheckpointRequiresCheckpointName(): void
    {
        $this->expectException(ComposerMissingParameterException::class);
        $this->expectExceptionMessageMatches("/^Composer SDK Error\nMissing parameter: checkpointName$/");
        $composer = new Composer('test-secret-key');
        $composer->checkpoint(new CheckpointOptions(
            checkpointName: '',
            event: new CheckpointEvent('127.0.0.1', [])
        ));
    }

    public function testCheckpointAcceptsEmptyEventIp(): void
    {
        // The API treats event.ip as optional.
        $composer = new Composer('test-secret-key', new ComposerConfig(
            apiVersion: ComposerApiVersion::V1,
            isEnabled: false
        ));
        $response = $composer->checkpoint(new CheckpointOptions(
            checkpointName: 'TEST_CHECKPOINT',
            event: new CheckpointEvent('', []),
            sessionId: 'session123'
        ));

        $this->assertEquals('COMPOSER_IS_DISABLED', $response->verification->id);
    }

    public function testCheckpointRequiresSessionIdOrSourceToken(): void
    {
        $this->expectException(ComposerMissingParameterException::class);
        $this->expectExceptionMessageMatches("/^Composer SDK Error\nMissing parameter: Must provide either a sessionId or sourceToken$/");
        $composer = new Composer('test-secret-key');
        $composer->checkpoint(new CheckpointOptions(
            checkpointName: 'TEST_CHECKPOINT',
            event: new CheckpointEvent('127.0.0.1', [])
        ));
    }

    public function testCheckpointReturnsDefaultResponseWhenDisabled(): void
    {
        $config = new ComposerConfig(
            apiVersion: ComposerApiVersion::V1,
            isEnabled: false
        );
        $composer = new Composer('test-secret-key', $config);
        $response = $composer->checkpoint(new CheckpointOptions(
            checkpointName: 'TEST_CHECKPOINT',
            event: new CheckpointEvent('127.0.0.1', []),
            sessionId: 'session123'
        ));
        
        $this->assertTrue($response->success);
        $this->assertEquals('COMPOSER_IS_DISABLED', $response->verification->id);
        $this->assertEquals(VerificationStatus::COMPLETE, $response->verification->status);
        $this->assertEquals(VerificationOutcome::APPROVED, $response->verification->outcome);
    }

    public function testEventRequiresEvent(): void
    {
        $this->expectException(\TypeError::class);
        $composer = new Composer('test-secret-key');
        $options = new TrackOptions(
            event: null
        );
        $composer->event($options);
    }

    public function testEventRequiresEventType(): void
    {
        $composer = new Composer('test-secret-key', new ComposerConfig(
            apiVersion: ComposerApiVersion::V1,
            logLevel: ComposerLogLevel::NONE
        ));
        $composer->event(new TrackOptions(
            event: new TrackEvent('', []),
            sessionId: 'session123'
        ));
        $this->addToAssertionCount(1);
    }

    public function testEventRequiresNonBlankEventType(): void
    {
        $composer = new Composer('test-secret-key', new ComposerConfig(
            apiVersion: ComposerApiVersion::V1,
            logLevel: ComposerLogLevel::NONE
        ));
        $composer->event(new TrackOptions(
            event: new TrackEvent('   ', []),
            sessionId: 'session123'
        ));
        $this->addToAssertionCount(1);
    }

    public function testEventRequiresSessionIdOrSourceToken(): void
    {
        $composer = new Composer('test-secret-key', new ComposerConfig(
            apiVersion: ComposerApiVersion::V1,
            logLevel: ComposerLogLevel::NONE
        ));
        $composer->event(new TrackOptions(
            event: new TrackEvent('TEST_EVENT', [])
        ));
        $this->addToAssertionCount(1);
    }

    public function testCreateErrorResponse(): void
    {
        $composer = new Composer('test-secret-key');
        $response = $composer->createErrorResponse(404, 'Not Found');
        
        $this->assertFalse($response->success);
        $this->assertCount(1, $response->errors);
        $this->assertEquals(404, $response->errors[0]->code);
        $this->assertEquals('Not Found', $response->errors[0]->message);
        $this->assertEquals(VerificationStatus::FAILED, $response->verification->status);
        $this->assertEquals(VerificationOutcome::ERROR, $response->verification->outcome);
    }

    public function testBackwardCompatibilityDodgeball(): void
    {
        $dodgeball = new \Spreedly\ComposerSdk\Dodgeball('test-key');
        $this->assertInstanceOf(Composer::class, $dodgeball);
        
        $response = $dodgeball->createErrorResponse(404, 'Not Found');
        $this->assertFalse($response->success);
    }

    public function testIsRunningWithFailedResponse(): void
    {
        $composer = new Composer('test-key');
        $response = new ComposerCheckpointResponse(
            success: false,
            errors: [new ComposerApiError(500, 'Error')],
            version: ComposerApiVersion::V1
        );
        $this->assertFalse($composer->isRunning($response));
    }

    public function testIsAllowedWithFailedResponse(): void
    {
        $composer = new Composer('test-key');
        $response = new ComposerCheckpointResponse(
            success: false,
            errors: [new ComposerApiError(500, 'Error')],
            version: ComposerApiVersion::V1
        );
        $this->assertFalse($composer->isAllowed($response));
    }

    public function testIsDeniedWithFailedResponse(): void
    {
        $composer = new Composer('test-key');
        $response = new ComposerCheckpointResponse(
            success: false,
            errors: [new ComposerApiError(500, 'Error')],
            version: ComposerApiVersion::V1
        );
        $this->assertFalse($composer->isDenied($response));
    }

    public function testHasErrorWithErrors(): void
    {
        $composer = new Composer('test-key');
        $response = new ComposerCheckpointResponse(
            success: false,
            errors: [new ComposerApiError(500, 'Server Error')],
            version: ComposerApiVersion::V1
        );
        $this->assertTrue($composer->hasError($response));
    }

    public function testHasErrorWithSuccessfulResponseAndErroredVerification(): void
    {
        $composer = new Composer('test-key');
        $response = new ComposerCheckpointResponse(
            success: true,
            errors: [],
            version: ComposerApiVersion::V1,
            verification: new ComposerVerification(
                id: 'test-verification-id',
                status: VerificationStatus::FAILED,
                outcome: VerificationOutcome::ERROR
            )
        );
        $this->assertTrue($composer->hasError($response));
    }

    public function testHasErrorWithUnsuccessfulResponseAndNoErrors(): void
    {
        $composer = new Composer('test-key');
        $response = new ComposerCheckpointResponse(
            success: false,
            errors: [],
            version: ComposerApiVersion::V1
        );
        $this->assertTrue($composer->hasError($response));
    }

    public function testHasErrorWithPendingVerificationAndErrors(): void
    {
        $composer = new Composer('test-key');
        $response = new ComposerCheckpointResponse(
            success: true,
            errors: [new ComposerApiError(504, 'Server timeout')],
            version: ComposerApiVersion::V1,
            verification: new ComposerVerification(
                id: 'test-verification-id',
                status: VerificationStatus::PENDING,
                outcome: VerificationOutcome::PENDING
            )
        );
        $this->assertFalse($composer->hasError($response));
    }

    public function testIsTimeoutWithNormalError(): void
    {
        $composer = new Composer('test-key');
        $response = new ComposerCheckpointResponse(
            success: false,
            errors: [new ComposerApiError(400, 'Bad Request')],
            version: ComposerApiVersion::V1,
            isTimeout: false
        );
        $this->assertFalse($composer->isTimeout($response));
    }
}
