# Medienreaktor.Sentry

Non-blocking asynchronous Sentry transport for Neos Flow applications.

## Overview

This package extends [PunktDe.Sentry.Flow](https://github.com/punktDe/sentry-flow) with a high-performance, asynchronous transport layer that prevents Sentry logging from impacting application response times. By using Guzzle's async capabilities in a fire-and-forget manner, error reporting happens in the background without blocking the main request.

## Why this package?

When logging multiple errors to Sentry in a single request, the default synchronous transport can significantly slow down your application. Each event waits for a response from the Sentry server before continuing. This package solves that problem by:

- **Non-blocking requests**: Events are sent asynchronously without waiting for server responses
- **Fire-and-forget**: Your application continues immediately after triggering the send
- **Maintained compatibility**: Preserves all Sentry features including rate limiting and error handling

## Requirements

- Neos Flow 8.0 - 9.0
- PunktDe.Sentry.Flow ^4.0 or ^5.0
- Guzzle HTTP client (typically already available in Flow/Neos)

## Installation
```bash
composer require medienreaktor/sentry
```

## Configuration

Just configure the environment variables for the package.
The SENTRY_DSN is needed.
That's it! All other PunktDe.Sentry.Flow settings work as usual.

## How it works

The `GuzzleAsyncTransport` implements Sentry's `TransportInterface` and:

1. Serializes events using Sentry's standard payload serializer
2. Sends HTTP requests via Guzzle's `postAsync()` method
3. Triggers the request with `wait(false)` to ensure it's sent without blocking
4. Handles responses and errors asynchronously in background callbacks

## Technical Details

- Uses lazy initialization to retrieve Sentry options from the SDK
- Preserves all standard Sentry features (rate limiting, profiling, etc.)
- Logs successes and failures for debugging purposes
- Gracefully handles errors in fire-and-forget mode

## License

This package is open source software. For license information, please see the LICENSE file.

## Credits

Built by [Medienreaktor](https://medienreaktor.de) to optimize Sentry integration.

Based on [PunktDe.Sentry.Flow](https://github.com/punktDe/sentry-flow) by punkt.de.
