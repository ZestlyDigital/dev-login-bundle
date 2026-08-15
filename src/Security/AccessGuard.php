<?php

declare(strict_types=1);

namespace Zestly\DevLoginBundle\Security;

use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Runtime gates 3 and 4 of the safety model (see ZestlyDevLoginBundle).
 *
 * The container gate already means this class cannot be instantiated outside a permitted
 * environment. It re-checks anyway, because "cannot happen" and "has not happened yet" are
 * different claims, and a dev-built container that somehow travels is exactly the scenario
 * where the cheap redundant check earns its keep.
 *
 * Failures raise 404 rather than 403. A 403 confirms the endpoint exists; a 404 tells a
 * scanner nothing. The exception message stays descriptive because it is only ever rendered
 * in a debug environment, where a developer wondering why their login bounced is the entire
 * audience.
 */
final readonly class AccessGuard
{
    /**
     * @param list<string> $allowedIps  IPs/CIDRs; an empty list disables the network check
     * @param list<string> $allowedEnvs kernel environments in which this may run
     */
    public function __construct(
        private array $allowedIps,
        private ?string $secret,
        private string $environment,
        private bool $debug,
        private array $allowedEnvs,
        private bool $requireDebug,
    ) {
    }

    /**
     * Environment-only check, for contexts with no HTTP request (the console command).
     */
    public function assertEnvironment(): void
    {
        if (!\in_array($this->environment, $this->allowedEnvs, true)) {
            throw new NotFoundHttpException(\sprintf(
                'zestly/dev-login-bundle is not available in the "%s" environment (allowed: %s).',
                $this->environment,
                implode(', ', $this->allowedEnvs) ?: 'none',
            ));
        }

        if ($this->requireDebug && !$this->debug) {
            throw new NotFoundHttpException(
                'zestly/dev-login-bundle requires kernel.debug. Set zestly_dev_login.require_debug to false if your dev environment intentionally runs without it.'
            );
        }
    }

    /**
     * Full check for an incoming HTTP request: environment, client network, shared secret.
     */
    public function assertRequest(Request $request): void
    {
        $this->assertEnvironment();

        $clientIp = $request->getClientIp();

        if ([] !== $this->allowedIps && (null === $clientIp || !IpUtils::checkIp($clientIp, $this->allowedIps))) {
            throw new NotFoundHttpException(\sprintf(
                'Client IP %s is not permitted to use dev login (allowed: %s).',
                $clientIp ?? 'unknown',
                implode(', ', $this->allowedIps),
            ));
        }

        if (null !== $this->secret && !hash_equals($this->secret, (string) $request->query->get('token'))) {
            throw new NotFoundHttpException('Missing or invalid ?token= for dev login.');
        }
    }

    public function isSecretRequired(): bool
    {
        return null !== $this->secret;
    }
}
