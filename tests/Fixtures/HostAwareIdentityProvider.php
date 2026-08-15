<?php

declare(strict_types=1);

namespace Zestly\DevLoginBundle\Tests\Fixtures;

use Symfony\Component\HttpFoundation\Request;
use Zestly\DevLoginBundle\Identity\Identity;
use Zestly\DevLoginBundle\Identity\IdentityProviderInterface;

/**
 * Stands in for a real application's provider — in particular a multi-tenant one, where the
 * advertised list has to depend on the host being asked.
 */
final class HostAwareIdentityProvider implements IdentityProviderInterface
{
    public function getIdentities(?Request $request = null): array
    {
        $host = $request?->getHost() ?? 'unknown';

        return [
            new Identity(
                identifier: 'admin@example.com',
                label: 'Admin on '.$host,
                roles: ['ROLE_ADMIN'],
            ),
        ];
    }
}
