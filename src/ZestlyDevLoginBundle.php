<?php

declare(strict_types=1);

namespace Zestly\DevLoginBundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Zestly\DevLoginBundle\Command\DevLoginCommand;
use Zestly\DevLoginBundle\DependencyInjection\AccessMapPass;
use Zestly\DevLoginBundle\DependencyInjection\UserProviderPass;
use Zestly\DevLoginBundle\Http\DiscoveryController;
use Zestly\DevLoginBundle\Http\LoginController;
use Zestly\DevLoginBundle\Identity\ConfiguredIdentityProvider;
use Zestly\DevLoginBundle\Identity\IdentityProviderInterface;
use Zestly\DevLoginBundle\Security\AccessGuard;

/**
 * Password-free login for local development.
 *
 * Safety model — four independent gates, each sufficient on its own:
 *
 *   1. Container    — outside `allowed_envs` this extension registers NOTHING. No services,
 *                     no controllers, no command. There is no code path to reach.
 *   2. Routing      — routes are imported by the host app from config/routes/dev/, so the
 *                     URLs do not exist in a production router at all.
 *   3. Network      — AccessGuard rejects any request from outside `allowed_ips`
 *                     (loopback + RFC1918 by default).
 *   4. Runtime      — AccessGuard re-checks the environment on every single request and
 *                     command, in case a dev-built container is ever shipped elsewhere.
 *
 * Gate 1 fails *silently* by design: a bundle that is registered in prod but disabled must
 * degrade to a no-op, never to an exception, because throwing here would take a live site
 * down. The one exception is an explicit `enabled: true` in a disallowed environment, which
 * is unambiguous misconfiguration rather than an accident — that throws at compile time, so
 * it breaks the deploy's cache warmup rather than the running application.
 */
final class ZestlyDevLoginBundle extends AbstractBundle
{
    /**
     * Loopback and RFC1918/RFC4193 private ranges. Covers bare-metal dev servers, Docker
     * bridge networks and VM host-only adapters without exposing anything routable.
     */
    public const DEFAULT_ALLOWED_IPS = [
        '127.0.0.1',
        '::1',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        'fc00::/7',
    ];
    protected string $extensionAlias = 'zestly_dev_login';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->booleanNode('enabled')
            ->defaultNull()
            ->info('Leave unset to enable automatically in allowed_envs. Setting this to true outside those environments is a hard error.')
            ->end()
            ->arrayNode('allowed_envs')
            ->scalarPrototype()->end()
            ->defaultValue(['dev'])
            ->info('Kernel environments in which this bundle may register itself.')
            ->end()
            ->booleanNode('require_debug')
            ->defaultTrue()
            ->info('Also require kernel.debug. Disable only if you run a non-debug dev environment.')
            ->end()
            ->scalarNode('path_prefix')
            ->defaultValue('/_dev/login')
            ->info('URL prefix for the login and discovery endpoints.')
            ->end()
            ->scalarNode('firewall')
            ->defaultValue('main')
            ->info('Name of the firewall to authenticate against.')
            ->end()
            ->scalarNode('user_provider')
            ->defaultNull()
            ->info('Service id of the user provider. Auto-detected when the app defines exactly one.')
            ->end()
            ->scalarNode('default_target')
            ->defaultValue('/')
            ->info('Where to redirect after a successful login. Overridable per request with ?target=')
            ->end()
            ->scalarNode('secret')
            ->defaultNull()
            ->info('Optional shared token that must be supplied as ?token=. Defence in depth for shared dev hosts.')
            ->end()
            ->arrayNode('allowed_ips')
            ->scalarPrototype()->end()
            ->defaultValue(self::DEFAULT_ALLOWED_IPS)
            ->info('Client IPs / CIDR ranges permitted to use the endpoints. Set to [] to disable the check.')
            ->end()
            ->arrayNode('identities')
            ->info('Known identities, surfaced by the discovery endpoint and the console command.')
            ->arrayPrototype()
            ->children()
            ->scalarNode('identifier')->isRequired()->cannotBeEmpty()->end()
            ->scalarNode('label')->defaultNull()->end()
            ->scalarNode('description')->defaultNull()->end()
            ->arrayNode('roles')->scalarPrototype()->end()->defaultValue([])->end()
            ->end()
            ->end()
            ->end()
            ->end()
        ;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!$this->isEnabled($builder, $config)) {
            // Deliberate silent no-op. See the class docblock: failing loudly here would mean a
            // production container refusing to compile, which is a worse outcome than a bundle
            // that simply is not there.
            return;
        }

        $services = $container->services()->defaults()->private();

        $services->set('zestly_dev_login.access_guard', AccessGuard::class)
            ->args([
                '$allowedIps' => $config['allowed_ips'],
                '$secret' => $config['secret'],
                '$environment' => '%kernel.environment%',
                '$debug' => '%kernel.debug%',
                '$allowedEnvs' => $config['allowed_envs'],
                '$requireDebug' => $config['require_debug'],
            ])
        ;

        $services->set('zestly_dev_login.identity_provider', ConfiguredIdentityProvider::class)
            ->args(['$identities' => $config['identities']])
        ;

        // Consumers override the identity list by aliasing this interface to their own
        // implementation. That only works if the controller and command depend on the ALIAS
        // rather than on the concrete service below — referencing the concrete id directly
        // makes IdentityProviderInterface look overridable while silently ignoring overrides.
        $services->alias(IdentityProviderInterface::class, 'zestly_dev_login.identity_provider')->public();

        $services->set('zestly_dev_login.controller.login', LoginController::class)
            ->args([
                '$userProvider' => null, // wired by UserProviderPass
                '$security' => new Reference('security.helper'),
                '$guard' => new Reference('zestly_dev_login.access_guard'),
                '$tokenStorage' => new Reference('security.token_storage'),
                '$eventDispatcher' => new Reference('event_dispatcher'),
                '$firewallName' => $config['firewall'],
                '$defaultTarget' => $config['default_target'],
            ])
            ->tag('controller.service_arguments')
        ;

        $services->set('zestly_dev_login.controller.discovery', DiscoveryController::class)
            ->args([
                '$identityProvider' => new Reference(IdentityProviderInterface::class),
                '$guard' => new Reference('zestly_dev_login.access_guard'),
                '$pathPrefix' => $config['path_prefix'],
            ])
            ->tag('controller.service_arguments')
        ;

        $services->set('zestly_dev_login.command', DevLoginCommand::class)
            ->args([
                '$identityProvider' => new Reference(IdentityProviderInterface::class),
                '$guard' => new Reference('zestly_dev_login.access_guard'),
                '$pathPrefix' => $config['path_prefix'],
                '$defaultScheme' => '%router.request_context.scheme%',
                '$defaultHost' => '%router.request_context.host%',
            ])
            ->tag('console.command')
        ;

        $builder->setParameter('zestly_dev_login.path_prefix', $config['path_prefix']);
        $builder->setParameter('zestly_dev_login.user_provider', $config['user_provider']);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new UserProviderPass());
        $container->addCompilerPass(new AccessMapPass());
    }

    /**
     * Environment is the only container-level condition.
     *
     * `require_debug` deliberately is *not* checked here. Gating the container on it would
     * mean that a developer running dev without debug gets a container with no controllers
     * while their routes file still imports the routes — producing a 500 about a missing
     * service instead of an answer. Enforced at request time in AccessGuard it produces a
     * clean 404 with a message that says which knob to turn.
     *
     * @param array<string, mixed> $config
     */
    private function isEnabled(ContainerBuilder $builder, array $config): bool
    {
        $env = $builder->getParameter('kernel.environment');

        if (!\is_string($env)) {
            return false; // Not a state we can reason about — fail closed.
        }

        $allowedEnvs = $config['allowed_envs'] ?? ['dev'];

        $permitted = \in_array($env, $allowedEnvs, true);

        $explicit = $config['enabled'] ?? null;

        if (true === $explicit && !$permitted) {
            throw new \LogicException(\sprintf(
                'zestly_dev_login is explicitly enabled but the "%s" environment is not permitted. '
                .'This bundle grants password-free login to any account and must never be reachable in production. '
                .'Either remove "enabled: true" or add "%s" to zestly_dev_login.allowed_envs deliberately.',
                $env,
                $env,
            ));
        }

        if (false === $explicit) {
            return false;
        }

        return $permitted;
    }

}
