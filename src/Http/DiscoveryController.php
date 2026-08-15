<?php

declare(strict_types=1);

namespace Zestly\DevLoginBundle\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Zestly\DevLoginBundle\Identity\Identity;
use Zestly\DevLoginBundle\Identity\IdentityProviderInterface;
use Zestly\DevLoginBundle\Security\AccessGuard;

/**
 * Lists the identities available on this host, as JSON.
 *
 * This is the endpoint that makes the bundle useful to a coding agent rather than only to a
 * person. A human already knows which fixture accounts exist; an agent starting a fresh
 * session does not, and the alternative is reading the app's fixture files and guessing. One
 * request returns the menu and a ready-to-follow URL for each entry, so logging in as the
 * right role costs a navigation instead of an interrogation.
 *
 * Every URL is absolute and built from the current request, which keeps it correct under
 * multi-tenant subdomain routing — ask tenant-a.localhost and you get tenant-a URLs back.
 */
final readonly class DiscoveryController
{
    public function __construct(
        private IdentityProviderInterface $identityProvider,
        private AccessGuard $guard,
        private string $pathPrefix,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $this->guard->assertRequest($request);

        $base = rtrim($request->getSchemeAndHttpHost(), '/').$this->pathPrefix;

        $identities = array_map(
            fn (Identity $identity): array => $identity->jsonSerialize() + [
                'login_url' => $base.'/'.rawurlencode($identity->identifier),
            ],
            $this->identityProvider->getIdentities($request),
        );

        return new JsonResponse([
            'host' => $request->getHost(),
            'token_required' => $this->guard->isSecretRequired(),
            'usage' => [
                'login' => $base.'/{identifier}',
                'query_parameters' => [
                    'target' => 'Absolute path to land on after login. Defaults to the configured default_target.',
                    'token' => $this->guard->isSecretRequired()
                        ? 'Required. Must match zestly_dev_login.secret.'
                        : 'Not required on this host.',
                ],
                'json' => 'Send "Accept: application/json" to the login URL to receive the resulting identity instead of a redirect.',
                'note' => 'Any identifier your application\'s user provider accepts will work. The list below is a convenience, not a whitelist.',
            ],
            'identities' => $identities,
        ]);
    }
}
