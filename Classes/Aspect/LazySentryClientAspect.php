<?php

declare(strict_types=1);

namespace Medienreaktor\Sentry\Aspect;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;

/**
 * Initializes the SentryClient before its Sentry client is read.
 *
 * Since Flow 9.2, the object manager hands out singletons as native lazy proxies on PHP 8.4,
 * which only build the real object once one of its properties is accessed.
 * SentryClient::getSentryClient() reads nothing but the static Sentry hub, so on an untouched
 * proxy it returns null although Sentry is configured: `./flow sentry:test` then reports a
 * broken configuration. Error reporting itself is not affected, because handleException()
 * reads the DSN first and thereby builds the object.
 *
 * @Flow\Aspect
 */
class LazySentryClientAspect
{
    /**
     * @Flow\Before("method(PunktDe\Sentry\Flow\SentryClient->getSentryClient())")
     */
    public function initializeSentryClient(JoinPointInterface $joinPoint): void
    {
        if (PHP_VERSION_ID < 80400) {
            return;
        }

        $sentryClient = $joinPoint->getProxy();
        $reflection = new \ReflectionClass($sentryClient);
        if ($reflection->isUninitializedLazyObject($sentryClient)) {
            $reflection->initializeLazyObject($sentryClient);
        }
    }
}
