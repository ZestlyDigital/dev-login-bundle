<?php

declare(strict_types=1);

namespace Zestly\DevLoginBundle\Identity;

use Symfony\Component\HttpFoundation\Request;

/**
 * Supplies the list of identities offered by the discovery endpoint and the console command.
 *
 * Implement this to serve the list from somewhere real — your fixtures, a repository, a
 * per-host lookup — instead of hardcoding it in configuration:
 *
 *     #[AsAlias(IdentityProviderInterface::class)]
 *     final class FixtureIdentityProvider implements IdentityProviderInterface { ... }
 *
 * The $request argument is what makes this useful in a multi-tenant application: the same
 * deployment can advertise a different set of identities per subdomain, so an agent driving
 * tenant-a.localhost is never shown accounts that only exist on tenant-b.localhost. Note that
 * this shapes the *menu* only — see Identity for why that is not a security boundary.
 */
interface IdentityProviderInterface
{
    /**
     * @return list<Identity>
     */
    public function getIdentities(?Request $request = null): array;
}
