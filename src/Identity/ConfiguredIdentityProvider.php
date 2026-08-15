<?php

declare(strict_types=1);

namespace Zestly\DevLoginBundle\Identity;

use Symfony\Component\HttpFoundation\Request;

/**
 * Default provider: serves the identities declared under `zestly_dev_login.identities`.
 *
 * Deliberately dumb. Anything smarter — reading fixtures, querying a repository, varying by
 * host — belongs in your own IdentityProviderInterface implementation, where it can use your
 * domain types instead of this bundle inventing a lowest-common-denominator abstraction.
 */
final readonly class ConfiguredIdentityProvider implements IdentityProviderInterface
{
    /** @var list<Identity> */
    private array $resolved;

    /**
     * @param list<array{identifier: string, label?: string|null, description?: string|null, roles?: list<string>}> $identities
     */
    public function __construct(array $identities = [])
    {
        $this->resolved = array_map(
            static fn (array $i): Identity => new Identity(
                identifier: $i['identifier'],
                label: $i['label'] ?? null,
                description: $i['description'] ?? null,
                roles: $i['roles'] ?? [],
            ),
            $identities,
        );
    }

    public function getIdentities(?Request $request = null): array
    {
        return $this->resolved;
    }
}
