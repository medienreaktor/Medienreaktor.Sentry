<?php

declare(strict_types=1);

namespace Medienreaktor\Sentry\Transport;

use GuzzleHttp\Client;
use Psr\Log\NullLogger;
use Sentry\Dsn;
use Sentry\Event;
use Sentry\Options;
use Sentry\SentrySdk;
use Sentry\Serializer\PayloadSerializer;
use Sentry\Serializer\PayloadSerializerInterface;
use Sentry\Transport\RateLimiter;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

/**
 * Asynchronous Guzzle-based transport for Sentry events.
 *
 * This transport sends events to Sentry in a fire-and-forget manner using Guzzle's async capabilities,
 * minimizing the impact on application performance by not waiting for server responses.
 */
class GuzzleAsyncTransport implements TransportInterface
{
    private ?Options $options = null;
    private ?PayloadSerializerInterface $payloadSerializer = null;
    private ?NullLogger $logger = null;
    private ?RateLimiter $rateLimiter = null;

    public function __construct()
    {
    }

    /**
     * Lazily initializes the transport by retrieving options from the Sentry SDK.
     *
     * @throws \RuntimeException If Sentry options cannot be retrieved
     */
    private function ensureInitialized(): void
    {
        if ($this->options !== null) {
            return;
        }

        $hub = SentrySdk::getCurrentHub();
        $client = $hub->getClient();

        if ($client !== null) {
            $this->options = $client->getOptions();
        }

        if ($this->options === null) {
            throw new \RuntimeException('Could not get Sentry options');
        }

        $this->payloadSerializer = new PayloadSerializer($this->options);
        $this->logger = new NullLogger();
        $this->rateLimiter = new RateLimiter($this->logger);
    }

    /**
     * Sends an event to Sentry asynchronously.
     *
     * @param Event $event The event to send
     * @return Result The result of the send operation
     */
    public function send(Event $event): Result
    {
        $this->ensureInitialized();

        $eventDescription = sprintf(
            '%s%s [%s]',
            $event->getLevel() !== null ? $event->getLevel() . ' ' : '',
            (string) $event->getType(),
            (string) $event->getId()
        );

        $dsn = $this->options->getDsn();
        if ($dsn === null) {
            $this->logger->info(sprintf('Skipping %s, because no DSN is set.', $eventDescription), ['event' => $event]);
            return new Result(ResultStatus::skipped(), $event);
        }

        $targetDescription = sprintf(
            '%s [project:%s]',
            $dsn->getHost(),
            $dsn->getProjectId()
        );

        $this->logger->info(sprintf('Sending %s to %s (async).', $eventDescription, $targetDescription), ['event' => $event]);

        $eventType = $event->getType();
        if ($this->rateLimiter->isRateLimited((string) $eventType)) {
            $this->logger->warning(
                sprintf('Rate limit exceeded for sending requests of type "%s".', (string) $eventType),
                ['event' => $event]
            );
            return new Result(ResultStatus::rateLimit());
        }

        if ($event->getSdkMetadata('profile') !== null) {
            if ($this->rateLimiter->isRateLimited(RateLimiter::DATA_CATEGORY_PROFILE)) {
                $event->setSdkMetadata('profile', null);
                $this->logger->warning(
                    'Rate limit exceeded for sending requests of type "profile". The profile has been dropped.',
                    ['event' => $event]
                );
            }
        }

        $this->sendAsync($dsn, $this->payloadSerializer->serialize($event), $eventDescription, $targetDescription);

        return new Result(ResultStatus::success(), $event);
    }

    /**
     * Sends the serialized event payload asynchronously via HTTP.
     *
     * Uses Guzzle's postAsync with wait(false) to trigger the request without blocking.
     * Response handling and errors are processed asynchronously and logged accordingly.
     *
     * @param Dsn $dsn The Sentry DSN containing endpoint information
     * @param string $payload The serialized event payload
     * @param string $eventDescription Human-readable event description for logging
     * @param string $targetDescription Human-readable target description for logging
     */
    private function sendAsync(Dsn $dsn, string $payload, string $eventDescription, string $targetDescription): void
    {
        try {
            $url = $dsn->getEnvelopeApiEndpointUrl();
            $auth = sprintf(
                'Sentry sentry_version=7, sentry_key=%s',
                $dsn->getPublicKey()
            );

            $client = new Client([
                'timeout' => 2,
                'connect_timeout' => 1,
            ]);

            $promise = $client->postAsync($url, [
                'headers' => [
                    'Content-Type' => 'application/x-sentry-envelope',
                    'X-Sentry-Auth' => $auth,
                ],
                'body' => $payload,
            ]);

            $promise->then(
                function ($response) use ($eventDescription, $targetDescription) {
                    $resultStatus = ResultStatus::createFromHttpStatusCode($response->getStatusCode());
                    $this->logger->info(
                        sprintf('Sent %s to %s (async). Result: "%s" (status: %s).',
                            $eventDescription,
                            $targetDescription,
                            strtolower((string) $resultStatus),
                            $response->getStatusCode()
                        )
                    );

                    if (method_exists($response, 'getHeaders')) {
                        $this->rateLimiter->handleResponse($response);
                    }
                },
                function ($exception) use ($eventDescription, $targetDescription) {
                    $this->logger->error(
                        sprintf('Failed to send %s to %s (async). Reason: "%s".',
                            $eventDescription,
                            $targetDescription,
                            $exception->getMessage()
                        ),
                        ['exception' => $exception]
                    );
                }
            );

            try {
                $promise->wait(false);
            } catch (\Throwable $e) {
                // Ignore errors in fire-and-forget mode
            }

        } catch (\Throwable $exception) {
            $this->logger->error(
                sprintf('Failed to initiate async send for %s to %s. Reason: "%s".',
                    $eventDescription,
                    $targetDescription,
                    $exception->getMessage()
                ),
                ['exception' => $exception]
            );
        }
    }

    /**
     * Closes the transport.
     *
     * @param int|null $timeout Optional timeout in seconds
     * @return Result Always returns success for async transport
     */
    public function close(?int $timeout = null): Result
    {
        return new Result(ResultStatus::success());
    }
}
