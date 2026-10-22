# Spreedly Composer Server SDK for PHP

## Table of Contents

- [Purpose](#purpose)
- [Prerequisites](#prerequisites)
- [Related](#related)
- [Installation](#installation)
- [Usage](#usage)
- [API](#api)
- [Development](#development)
- [License](#license)
- [Support](#support)

## Purpose

[Spreedly Composer](https://developer.spreedly.com) enables developers to decouple security logic from their application code. This has several benefits including:

- The ability to toggle and compare security services like fraud engines, MFA, KYC, and bot prevention.
- Faster responses to new attacks. When threats evolve and new vulnerabilities are identified, your application's security logic can be updated without changing a single line of code.
- The ability to put in placeholders for future security improvements while focusing on product development.
- A way to visualize all application security logic in one place.

The Composer Server SDK for PHP makes integration with the Composer API easy and is maintained by the Spreedly team.

## Prerequisites

- PHP 8.2 or higher, with the `curl` and `json` extensions
- You will need to obtain an API key for your application from the Composer developer center.

## Related

Check out the [Client SDK](https://npmjs.com/package/@dodgeball/trust-sdk-client) for how to integrate Composer into your frontend applications.

[Composer examples](https://github.com/spreedly/composer-examples) has a runnable example server for this SDK in `server/php`, and a web client that calls it.

## Installation

The package isn't on Packagist yet. Use `composer` to install the Composer SDK from GitHub:

```sh
composer config repositories.composer-sdk-server vcs https://github.com/spreedly/composer-sdk-server-php
composer require spreedly/composer-sdk-server:dev-main
```

## Usage

This example protects an order endpoint end to end. Your API calls a checkpoint before placing the order. When the workflow needs more input from the user, such as MFA, your frontend gathers it with the Client SDK and calls your API again with the verification ID. See [Identifiers](#identifiers) for what each ID is and [useVerification](#useverification) for how the second call works.

```php
<?php
// In your API

require 'vendor/autoload.php';

use Spreedly\ComposerSdk\Composer;
use Spreedly\ComposerSdk\CheckpointOptions;
use Spreedly\ComposerSdk\CheckpointEvent;

// $composer = new Dodgeball($_ENV['DODGEBALL_SECRET_KEY']); // The prior name is still supported.
$composer = new Composer($_ENV['SPREEDLY_SECRET_KEY']);

// Client IP for the risk engine. Callers must not choose this value.
// Default (TRUSTED_PROXY_COUNT=0): socket address, ignore X-Forwarded-For.
// Positive count: that many hops from the right of X-Forwarded-For; the hop must be an IP.
// Set TRUSTED_PROXY_COUNT only if your app runs behind trusted reverse proxies.
function getIp(): string
{
    $raw = $_ENV['TRUSTED_PROXY_COUNT'] ?? getenv('TRUSTED_PROXY_COUNT');
    if ($raw === false || $raw === null || $raw === '') {
        $raw = '0';
    }
    $hops = filter_var($raw, FILTER_VALIDATE_INT);
    if ($hops === false || $hops < 0) {
        throw new RuntimeException('Cannot derive client IP: TRUSTED_PROXY_COUNT is not a valid hop count');
    }

    $header = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    $parts = array_map('trim', explode(',', $header));
    if ($hops === 0) {
        $candidate = $_SERVER['REMOTE_ADDR'] ?? '';
    } elseif ($hops <= count($parts)) {
        $candidate = $parts[count($parts) - $hops];
    } else {
        $candidate = '';
    }

    if ($candidate === '' || filter_var($candidate, FILTER_VALIDATE_IP) === false) {
        throw new RuntimeException('Cannot derive client IP: no valid IP for this TRUSTED_PROXY_COUNT');
    }
    return $candidate;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_SERVER['REQUEST_URI'] === '/api/orders') {
    $requestBody = json_decode(file_get_contents('php://input'), true);

    $checkpointResponse = $composer->checkpoint(new CheckpointOptions(
        checkpointName: 'PLACE_ORDER',
        event: new CheckpointEvent(
            ip: getIp(),
            data: [
                'order' => $requestBody['order']
            ]
        ),
        sourceToken: $_SERVER['HTTP_X_DODGEBALL_SOURCE_TOKEN'] ?? null,
        sessionId: session_id(),
        userId: $_SESSION['userId'] ?? null,
        useVerificationId: $_SERVER['HTTP_X_DODGEBALL_VERIFICATION_ID'] ?? null
    ));

    if ($composer->isAllowed($checkpointResponse)) {
        $placedOrder = createOrder($requestBody['order']);
        http_response_code(200);
        echo json_encode(['order' => $placedOrder]);
    } elseif ($composer->isDenied($checkpointResponse)) {
        // Return the verification so the frontend can show the reason
        http_response_code(403);
        echo json_encode(['verification' => $checkpointResponse->verification]);
    } elseif ($composer->isRunning($checkpointResponse)) {
        // The workflow needs more input from the user (such as MFA or KYC)
        http_response_code(202);
        echo json_encode(['verification' => $checkpointResponse->verification]);
    } else {
        // No verdict: decide whether to return the error, proceed, retry, or reject. See Errors.
        http_response_code(500);
        echo json_encode(['message' => $checkpointResponse->errors]);
    }
}
```

```js
// In your frontend, with the Client SDK
const placeOrder = async (order, previousVerificationId = null) => {
  const sourceToken = await dodgeball.getSourceToken();

  const endpointResponse = await axios.post("/api/orders", { order }, {
    headers: {
      "x-dodgeball-source-token": sourceToken,
      "x-dodgeball-verification-id": previousVerificationId
    },
    // Let the 202 and 403 responses through instead of throwing
    validateStatus: (status) => [200, 202, 403].includes(status)
  });

  if (endpointResponse.status === 200) {
    setIsOrderPlaced(true);
    return;
  }

  dodgeball.handleVerification(endpointResponse.data.verification, {
    onVerified: async (verification) => {
      // The user completed the extra step: call your API again with the verification ID
      await placeOrder(order, verification.id);
    },
    onApproved: async () => {
      setIsOrderPlaced(true);
    },
    onDenied: async (verification) => {
      setIsOrderDenied(true);
    },
    onError: async (error) => {
      setError(error);
      setIsPlacingOrder(false);
    }
  });
}
```

## API

### Configuration

---

The package requires a secret API key as the first argument to the constructor.

```php
$composer = new Composer("secret-api-key...");
```

Optionally, you can pass a `ComposerConfig` as the second argument:

```php
use Spreedly\ComposerSdk\ComposerConfig;
use Spreedly\ComposerSdk\ComposerApiVersion;
use Spreedly\ComposerSdk\ComposerLogLevel;

$composer = new Composer("secret-api-key...", new ComposerConfig(
    apiVersion: ComposerApiVersion::V1,
    apiUrl: "https://api.dodgeballhq.com",
    logLevel: ComposerLogLevel::ERROR
));
```

| Option | Default | Description |
| --- | --- | --- |
| `apiVersion` | `v1` | The Composer API version to use. `ComposerApiVersion::V1` is the only version. |
| `apiUrl` | `https://api.dodgeballhq.com` | The base URL of the Spreedly Composer API. Useful for sending requests to different environments such as `https://api.sandbox.dodgeballhq.com`. |
| `logLevel` | `INFO` | `TRACE`, `INFO`, `ERROR` or `NONE`. The SDK logs errors, plus redacted request and response payloads at `TRACE`, so `INFO` and `ERROR` currently log the same thing. The level is shared by every `Composer` instance in the process. See [Safe logging](#safe-logging). |
| `requestTimeoutMs` | `30000` | How long a single HTTP request may take, in milliseconds. Applies to every call the SDK makes. A value of `0` or less is logged and replaced with the default. See [Timeouts](#timeouts). |
| `isEnabled` | `true` | When `false`, the SDK makes no API calls: `checkpoint` returns an approved response and `event` returns without sending anything. |

### Safe logging

---

The SDK logs through PHP's `error_log()`. At the default `INFO` level it writes no request or response payloads: a successful checkpoint logs nothing, and `ERROR` reports request failures. Payload logging is opt-in through `TRACE`, and even there the fields that describe your end users or carry provider credentials (`ip`, `data`, `stepData` and `nextSteps`) are replaced with `[REDACTED]`, so a verbose log shows the shape of a request or response without carrying PII. Turn `TRACE` on only in environments where you are comfortable with that extra detail.

### Identifiers

---

`checkpoint` and `event` both take these. Provide a `sessionId`, a `sourceToken`, or both.

| Parameter | Required | Description |
| --- | --- | --- |
| `sourceToken` | Either this or `sessionId` | A token representing the device making the request. Obtained from the [Client SDK](https://npmjs.com/package/@dodgeball/trust-sdk-client). |
| `sessionId` | Either this or `sourceToken` | The current session ID of the request. |
| `userId` | No | The ID of the user in your database, once you know it (for example, after registration). |
| `useVerificationId` | No | `checkpoint` only. The ID of a verification the user already completed. See [useVerification](#useverification). |

### Call a Checkpoint

---

Checkpoints represent key moments of risk in an application and are at the core of how Composer works. A checkpoint can represent any activity deemed to be a risk. Some common examples include: login, placing an order, redeeming a coupon, posting a review, changing bank account information, making a donation, transferring funds, creating a listing.

```php
use Spreedly\ComposerSdk\CheckpointOptions;
use Spreedly\ComposerSdk\CheckpointEvent;

$checkpointResponse = $composer->checkpoint(new CheckpointOptions(
    checkpointName: "CHECKPOINT_NAME",
    event: new CheckpointEvent(
        ip: "127.0.0.1",
        data: [
            'transaction' => ['amount' => 100, 'currency' => 'USD'],
            'paymentMethod' => ['token' => 'ghi789']
        ]
    ),
    sessionId: "session_def456"
));
```

Along with the [identifiers](#identifiers), `CheckpointOptions` takes:

| Parameter | Required | Description |
| --- | --- | --- |
| `checkpointName` | Yes | The name of the checkpoint to call. |
| `event` | Yes | A `CheckpointEvent`. |
| `event->ip` | No | The IP address of the device where the request originated. Recommended whenever an end user is present; leave it out for checkpoints with no user, such as renewals. |
| `event->data` | No | Data your checkpoint's workflow uses. Defaults to `[]`. |
| `options` | No | A `CheckpointResponseOptions` with `sync` (defaults to `false`) and `timeout` (how long the SDK keeps polling an unresolved verification, in milliseconds; defaults to 10 minutes). See [Timeouts](#timeouts). |

`data` is sent with `json_encode`, which can't tell an empty PHP array from an empty object: `[]` is sent as a JSON list. An array whose keys are `0, 1, 2…` is also sent as a list. Where your workflow expects an object inside `data`, use a `stdClass` for that value, such as `new \stdClass()` or `(object) []` for an empty object. This applies to event data too.

### Timeouts

---

Two settings control timing and neither one caps the other. `requestTimeoutMs` limits how long a single HTTP request may take, and applies to every call the SDK makes. The `timeout` on `CheckpointResponseOptions` limits how long the SDK keeps polling for an unresolved verification, and defaults to 10 minutes. The SDK always polls at least once.

```php
use Spreedly\ComposerSdk\CheckpointResponseOptions;

$composer = new Composer("secret-api-key...", new ComposerConfig(
    requestTimeoutMs: 5000 // No single HTTP call may hang longer than 5 seconds
));

$checkpointResponse = $composer->checkpoint(new CheckpointOptions(
    checkpointName: "CHECKPOINT_NAME",
    event: new CheckpointEvent(ip: "127.0.0.1"),
    sessionId: "session_def456",
    options: new CheckpointResponseOptions(
        timeout: 10000 // Stop polling after 10 seconds and return whatever we have
    )
));
```

This example uses the default `sync: false`, which we recommend. The first request returns right away with a pending verification, and the SDK polls until it resolves, so each request is short and `requestTimeoutMs` only guards against a hung connection. With `sync: true`, the first request waits on the server until the workflow finishes, so `requestTimeoutMs` must be longer than your slowest workflow. Otherwise a slow but healthy checkpoint comes back as a failure.

When the polling budget runs out, the verification is simply still pending, which is not an error: `isRunning` returns `true`, and you can resume later by passing the verification ID to `useVerificationId`. The exception is when the last poll before the budget ran out failed: you get that failure instead, with `success` `false`, and `hasError` returns `true`.

### Checkpoint Responses

---

Calling a checkpoint creates a verification in Composer. Its `status` and `outcome` determine how your application should proceed. Use the [utility methods](#utility-methods) rather than reading these fields directly.

```php
class ComposerCheckpointResponse {
    public bool $success;
    public array $errors; // ComposerApiError[]
    public string $version;
    public ?ComposerVerification $verification;
    public ?bool $isTimeout;
}
```

#### Response fields

| Property | Description |
| --- | --- |
| `success` | `false` when no verification result could be produced. See [Errors](#errors). A verification that failed while running still has `success: true`. |
| `errors` | `ComposerApiError` objects with a `message` and a `code` (`0` when the API doesn't send one). Usually empty when `success` is `true`. |
| `version` | The version of the Composer API that was used to make the request. |
| `verification` | The verification. Can be `null` when `success` is `false`. |
| `verification->id` | The ID of the verification that was created. |
| `verification->status` | `COMPLETE`, `PENDING`, `BLOCKED` or `FAILED`. |
| `verification->outcome` | `APPROVED`, `DENIED`, `PENDING`, `ERROR`, `USAGE_LIMIT` or `API_LIMIT`. |
| `verification->stepData` | Data from the workflow's steps, decoded into PHP arrays. An empty object in the response arrives as `[]`. |
| `verification->nextSteps` | Steps the Client SDK runs next, such as MFA. Return the verification to your frontend unchanged so the Client SDK can start them right away. |
| `isTimeout` | `true` when polling stopped after 3 failed polls in a row. See `isTimeout` below. |

#### Statuses and outcomes

| `status` | `outcome` | Utility method | Meaning |
| --- | --- | --- | --- |
| `COMPLETE` | `APPROVED` | `isAllowed` | Let the request proceed. |
| `COMPLETE` | `DENIED` | `isDenied` | Deny the request. Return the verification to the frontend to show the reason. |
| `BLOCKED` | `PENDING` | `isRunning` | The workflow is waiting for input from the user, such as MFA or KYC. See [useVerification](#useverification). |
| `PENDING` | `PENDING` | `isRunning` | Still processing when the polling budget ran out. Resume later with `useVerificationId`. |
| `COMPLETE` | `PENDING` | `isUndecided` | The workflow finished without reaching a verdict. Your application decides how to proceed. |
| `FAILED` | `ERROR` | `hasError` | The workflow failed while running, for example a third-party service was unavailable. |
| `FAILED` | `USAGE_LIMIT` | `hasError` | Your account reached its usage limit. |
| `FAILED` | `API_LIMIT` | `hasError` | Your account is sending too many requests right now, or is deactivated. |

### Utility Methods

---

Each method takes a checkpoint response and returns a boolean.

| Method | Returns `true` when |
| --- | --- |
| `$composer->isAllowed($checkpointResponse)` | The request is allowed to proceed. |
| `$composer->isDenied($checkpointResponse)` | The request is denied. |
| `$composer->isRunning($checkpointResponse)` | No verdict yet: the workflow is still processing or waiting on the user. |
| `$composer->isUndecided($checkpointResponse)` | The workflow finished without a verdict. |
| `$composer->hasError($checkpointResponse)` | `success` is `false`, or the workflow failed while running. |
| `$composer->isTimeout($checkpointResponse)` | Polling stopped after 3 failed polls in a row. Running out of the polling `timeout` is not a timeout in this sense; see [Timeouts](#timeouts). |

### Errors

---

Only invalid input throws. Everything else comes back as a response, so a checkpoint never throws because of the network or the API.

| What happened | Throws? | What you get | Utility method |
| --- | --- | --- | --- |
| Empty secret key, or an invalid `apiVersion` or `logLevel` | Yes, from the constructor: `ComposerMissingConfigException` or `ComposerInvalidConfigException` | | |
| Empty `checkpointName`, or neither `sessionId` nor `sourceToken` | Yes, from `checkpoint`: `ComposerMissingParameterException` | | |
| The API rejected the request, for example there is no published workflow for the checkpoint name, or a usage or API limit was reached | No | `success: false`, the API's message in `errors` | `hasError` |
| Network error, request timeout, or a response the SDK can't read | No | `success: false`, a `500` in `errors` | `hasError` |
| 3 polls failed in a row | No | `success: false`, a `503` in `errors` | `hasError`, `isTimeout` |
| The workflow failed while running | No | `success: true`, status `FAILED`, outcome `ERROR` | `hasError` |
| Invalid input to `event` | Never | Nothing; the SDK logs it (see [Track an Event](#track-an-event)) | |

Blank, whitespace-only, `"null"` and `"undefined"` strings count as empty. When `success` is `false` there is no verdict at all. Don't treat it as a denial; decide whether to retry, proceed, or reject.

### useVerification

---

Sometimes the workflow needs more input from the user before it can reach a verdict. For example, if the user must complete MFA, the checkpoint response has `status` `BLOCKED` and `outcome` `PENDING`, and `isRunning` returns `true`. Return the verification to your frontend and pass it to the Client SDK's `handleVerification()` method, which gathers the input from the user. When the user is done, the frontend calls your API again with the verification ID, and your API passes it to `useVerificationId`. The checkpoint then uses that verification instead of creating a new one, so the user isn't asked twice. The [Usage](#usage) example shows the full flow.

**Important Note:** To prevent replay attacks, each verification ID can only be passed to `useVerificationId` once.

### Track an Event

---

You can track additional information about a user's journey by submitting tracking events from your server. This information will be added to the user's profile and is made available to checkpoints.

```php
use Spreedly\ComposerSdk\TrackOptions;
use Spreedly\ComposerSdk\TrackEvent;

$composer->event(new TrackOptions(
    event: new TrackEvent(
        type: "ADD_TO_CART",
        data: [
            'product' => ['id' => 'sku_123', 'quantity' => 1]
        ]
    ),
    sessionId: "session_def456"
));
```

Along with the [identifiers](#identifiers), `TrackOptions` takes:

| Parameter | Required | Description |
| --- | --- | --- |
| `event` | Yes | A `TrackEvent`. |
| `event->type` | Yes | A name for where the user is in their journey. Can be any non-blank string you choose. |
| `event->data` | No | Data to track. Defaults to `[]`. |
| `event->eventTime` | No | When the event happened, in milliseconds since the epoch. Defaults to now. |

`event` doesn't throw for invalid input and returns nothing, so tracking can't break your request. Problems are logged at `ERROR` instead:

- If `event->type` is empty, or neither `sessionId` nor `sourceToken` is provided, the event is not sent and the SDK logs `Composer event was not sent: <reason>`.
- If the API rejects the event, the SDK logs `Composer event was not accepted by the API (HTTP <status>).`
- If the request fails (network error or timeout), the SDK logs `There was an error making a request.`

## Development

### Versioning

The SDK version is declared in `src/Utilities.php` (`SDK_VERSION`, sent in the `Composer-SDK-Version` header). Packagist takes the release version from the git tag, so tag the release with the same version.

### Testing

The project uses PHPUnit for testing.

To run tests:

```sh
composer test
```

One test verifies the 30-second default request timeout, so it takes that long to run and is excluded from `composer test`. To run it:

```sh
composer test-slow
```

To run tests with coverage (requires xdebug or pcov):

```sh
composer test-coverage
```

To run tests without coverage warnings:

```sh
vendor/bin/phpunit --no-coverage
```

### Building

To install dependencies:

```sh
composer install
```

To regenerate the autoloader:

```sh
composer dump-autoload
```

### Testing with the examples

To try local SDK changes end to end, clone [Composer examples](https://github.com/spreedly/composer-examples) next to this repository, point the `server/php` example at your checkout in its `composer.json`, and run it as the examples README describes:

```json
"repositories": [{ "type": "path", "url": "../../../composer-sdk-server-php" }],
"require": { "spreedly/composer-sdk-server": "@dev" }
```

Composer symlinks your checkout into `vendor/`, so changes apply on the next request.

## License

Apache License 2.0 -- see [LICENSE](LICENSE)

## Support

For support, please contact:

- Email: support@spreedly.com
- Documentation: https://developer.spreedly.com
