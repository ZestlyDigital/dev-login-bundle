<?php

declare(strict_types=1);

namespace Zestly\DevLoginBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Wires the login controller to the application's user provider.
 *
 * Symfony registers each configured provider as `security.user.provider.concrete.<name>`.
 * Most applications define exactly one, so auto-detection covers the common case with no
 * configuration at all; anything else has to be named explicitly, because guessing which of
 * several providers a developer meant is precisely the kind of helpfulness that produces a
 * confusing bug later.
 */
final class UserProviderPass implements CompilerPassInterface
{
    private const CONTROLLER_ID = 'zestly_dev_login.controller.login';
    private const PROVIDER_PREFIX = 'security.user.provider.concrete.';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::CONTROLLER_ID)) {
            return; // Bundle disabled for this environment — nothing to wire.
        }

        $container->getDefinition(self::CONTROLLER_ID)
            ->setArgument('$userProvider', new Reference($this->resolveProviderId($container)));
    }

    private function resolveProviderId(ContainerBuilder $container): string
    {
        $configured = $container->hasParameter('zestly_dev_login.user_provider')
            ? $container->getParameter('zestly_dev_login.user_provider')
            : null;

        if (\is_string($configured) && '' !== $configured) {
            if (!$container->has($configured)) {
                throw new InvalidConfigurationException(\sprintf(
                    'zestly_dev_login.user_provider is set to "%s", but no such service exists. '.
                    'Configured providers are registered as "%s<name>".',
                    $configured,
                    self::PROVIDER_PREFIX,
                ));
            }

            return $configured;
        }

        $candidates = array_values(array_filter(
            $container->getServiceIds(),
            static fn (string $id): bool => str_starts_with($id, self::PROVIDER_PREFIX),
        ));

        return match (\count($candidates)) {
            1 => $candidates[0],
            0 => throw new InvalidConfigurationException(
                'zestly/dev-login-bundle could not find a security user provider. '.
                'Define one under security.providers, or set zestly_dev_login.user_provider to a service id.'
            ),
            default => throw new InvalidConfigurationException(\sprintf(
                'zestly/dev-login-bundle found %d user providers (%s) and will not guess between them. '.
                'Set zestly_dev_login.user_provider to the one your dev logins should use.',
                \count($candidates),
                implode(', ', $candidates),
            )),
        };
    }
}
