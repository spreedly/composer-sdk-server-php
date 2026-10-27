# Changelog

## 1.0.0 (2026-10-27)

First release as `spreedly/composer-sdk-server`. If you're upgrading from `dodgeball/dodgeball-sdk-server`, read **Changed** and **Removed**.

### Added
- `requestTimeoutMs` config option: a timeout for each HTTP request, default 30000 ms. A value of `0` or less is logged and replaced with the default.
- `logLevel` config option (`TRACE`, `INFO`, `ERROR`, `NONE`; default `INFO`). The SDK logs through `error_log()`, and the level is shared by every `Composer` instance in the process. At `TRACE`, `ip`, `data`, `stepData` and `nextSteps` are redacted.
- `USAGE_LIMIT` and `API_LIMIT` outcomes. Both come back with `success: false`, so `hasError()` is `true`.
- `verification->stepData` and `verification->nextSteps` on the response, decoded into PHP arrays.
- `event()` logs an error when an event isn't sent or the API doesn't accept it.

### Changed
- The package is now `spreedly/composer-sdk-server`, the namespace is `Spreedly\ComposerSdk`, and the main class is `Composer`. `Dodgeball`, `DodgeballApiVersion`, `DodgeballConfig` and `DodgeballLogLevel` still work in the new namespace.
- The SDK throws `ComposerMissingConfigException`, `ComposerInvalidConfigException` and `ComposerMissingParameterException`. Catch those names; the SDK never throws the `Dodgeball*Exception` classes.
- Renamed with no alias: `DodgeballMissingParameterError` is `ComposerMissingParameterException`, `DodgeballVerification` is `ComposerVerification`, and `DodgeballCheckpointResponse` is `ComposerCheckpointResponse`. Update any `catch` blocks and type hints.
- Requires PHP 8.2 or later and `ext-curl`.
- `checkpoint()` and `event()` take `CheckpointOptions` and `TrackOptions` objects instead of arrays, and the constructor takes an optional `ComposerConfig` instead of an options array. `ComposerConfig` takes `apiVersion` first, so reorder positional `DodgeballConfig` calls. `apiVersion` defaults to `v1`.
- `data` on `CheckpointEvent` and `TrackEvent` is a PHP array instead of a `stdClass`. Use `(object)` for nested values that must be sent as JSON objects.
- `ip` on `CheckpointEvent` is optional.
- The helper methods moved from the response to the client: `$response->isAllowed()` is now `$composer->isAllowed($response)`, and likewise for `isDenied`, `isRunning`, `isUndecided`, `hasError` and `isTimeout`.
- `sync` defaults to `false` (was `true`). Pass `sync: true` to keep the old behavior, and set `requestTimeoutMs` longer than your slowest workflow if you do.
- `timeout` is now only how long the SDK polls, and defaults to 10 minutes. It's no longer sent to the API, and leaving it out no longer means polling with no time limit.
- The first checkpoint request is no longer limited to 1 second or retried up to 3 times. Every request uses `requestTimeoutMs`.
- A request that fails or times out returns `success: false` with a `500` in `errors`, and `isTimeout()` is `false`. Only 3 consecutive failed polls return a `503` with `isTimeout()` `true`. API error responses are returned as the API sent them, without retries.
- `verification` can be `null` on a failure response, and `isTimeout` is `null` when unset. Use the helper methods, or check `verification` before reading it.
- `status` and `outcome` are passed through as the API sent them.
- `isRunning` requires the outcome to be `PENDING` as well as the status to be `PENDING` or `BLOCKED`.
- `hasError` is `true` when `success` is `false` or the outcome is `ERROR`.
- The constructor throws `ComposerMissingConfigException` for an empty secret key (was `DodgeballMissingParameterError`) and `ComposerInvalidConfigException` for an unsupported `apiVersion` or `logLevel`.
- `checkpoint()` throws `ComposerMissingParameterException` when `checkpointName` is empty, or when both `sessionId` and `sourceToken` are. Blank strings, `"null"` and `"undefined"` count as empty.
- `event()` doesn't throw for invalid input or failed requests. It logs the problem and sends nothing.
- Only `isEnabled: false` disables the SDK. When disabled, `event()` sends nothing, and the placeholder verification ID is `COMPOSER_IS_DISABLED` (was `DODGEBALL_IS_DISABLED`).
- The default `eventTime` is in milliseconds (was seconds). Pass milliseconds if you set it yourself.

### Removed
- `webhook` from `CheckpointResponseOptions`. Passing it is now an error; remove it. `checkpoint()` polls a pending verification as before.
- `setHandlerStack()`, along with the `guzzlehttp/guzzle` and `ramsey/uuid` dependencies.
- The `CheckpointParams` and `EventParams` arrays. Use `CheckpointOptions` and `TrackOptions`.
