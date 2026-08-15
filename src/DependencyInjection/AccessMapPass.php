<?php

declare(strict_types=1);

namespace Zestly\DevLoginBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\RequestMatcher\PathRequestMatcher;
use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;

/**
 * Makes the dev login endpoints publicly accessible without the host app editing its
 * production security.yaml.
 *
 * The obvious implementation — prepending an `access_control` entry from prependExtension()
 * — cannot work: `security.access_control` is declared cannotBeOverwritten(), so Symfony
 * rejects the config outright if a second section touches it. Appending under `when@dev:`
 * fails differently and more quietly: access rules are first-match-wins and nearly every
 * application ends its list with a `^/` catch-all, so an appended rule is never reached.
 *
 * So the rule is injected one layer lower, into the built `security.access_map` service,
 * where ordering is just the order of method calls and can be controlled precisely. The
 * entry is unshifted to the front, which is the only position that survives a catch-all.
 */
final class AccessMapPass implements CompilerPassInterface
{
    private const ACCESS_MAP_ID = 'security.access_map';
    private const MATCHER_ID = 'zestly_dev_login.request_matcher';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('zestly_dev_login.path_prefix')) {
            return; // Bundle disabled for this environment.
        }

        if (!$container->hasDefinition(self::ACCESS_MAP_ID)) {
            return; // No security bundle, or no firewall — nothing to relax.
        }

        $prefix = $container->getParameter('zestly_dev_login.path_prefix');

        if (!\is_string($prefix)) {
            return;
        }

        $container->setDefinition(
            self::MATCHER_ID,
            new Definition(PathRequestMatcher::class, ['^'.preg_quote($prefix, '#')]),
        );

        $accessMap = $container->getDefinition(self::ACCESS_MAP_ID);
        $calls = $accessMap->getMethodCalls();

        array_unshift($calls, [
            'add',
            [new Reference(self::MATCHER_ID), [AuthenticatedVoter::PUBLIC_ACCESS], null],
        ]);

        $accessMap->setMethodCalls($calls);
    }
}
