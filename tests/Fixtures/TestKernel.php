<?php

declare(strict_types=1);

namespace Zestly\DevLoginBundle\Tests\Fixtures;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Zestly\DevLoginBundle\ZestlyDevLoginBundle;

/**
 * Minimal application used by the functional tests.
 *
 * Kept deliberately close to a stock skeleton — an in-memory provider, one firewall, one
 * protected route — so that a test failing here means the bundle is wrong, not that the
 * fixture app is exotic.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $devLoginConfig
     */
    public function __construct(
        string $environment = 'dev',
        bool $debug = true,
        private readonly array $devLoginConfig = [],
    ) {
        parent::__construct($environment, $debug);
    }

    /**
     * @return iterable<BundleInterface>
     */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new ZestlyDevLoginBundle();
    }

    public function getCacheDir(): string
    {
        // Debug belongs in the key: a non-debug kernel does not revalidate its container, so
        // sharing a directory with a debug build silently resurrects the wrong container.
        return sys_get_temp_dir().'/zestly-dev-login/'.$this->environment.'/'
            .($this->debug ? 'debug' : 'nodebug').'/'
            .substr(md5(serialize($this->devLoginConfig)), 0, 8).'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/zestly-dev-login/'.$this->environment.'/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'php_errors' => ['log' => true],
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'router' => ['utf8' => true],
        ]);

        $container->extension('security', [
            'password_hashers' => [
                UserInterface::class => ['algorithm' => 'plaintext'],
            ],
            'providers' => [
                'test_users' => [
                    'memory' => [
                        'users' => [
                            'admin@example.com' => ['password' => 'x', 'roles' => ['ROLE_ADMIN', 'ROLE_USER']],
                            'requestor@example.com' => ['password' => 'x', 'roles' => ['ROLE_USER']],
                        ],
                    ],
                ],
            ],
            'firewalls' => [
                'main' => [
                    'lazy' => true,
                    'provider' => 'test_users',
                    'http_basic' => true,
                ],
            ],
            // The catch-all is the point. Nearly every real application ends its access
            // rules this way, and it is what makes the naive fixes for endpoint access
            // (a `when@dev:` access_control entry, or a prepended config section) fail.
            // If AccessMapPass regresses, every functional test below turns into a redirect
            // to the login page rather than a login.
            'access_control' => [
                ['path' => '^/whoami', 'roles' => 'ROLE_USER'],
                ['path' => '^/', 'roles' => 'ROLE_USER'],
            ],
        ]);

        $container->extension('zestly_dev_login', $this->devLoginConfig);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        if ('prod' !== $this->environment) {
            $routes->import(__DIR__.'/../../config/routes.php');
        }

        $routes->add('whoami', '/whoami')->controller([self::class, 'whoami']);
    }

    public function whoami(#[CurrentUser] ?UserInterface $user): JsonResponse
    {
        return new JsonResponse([
            'identifier' => $user?->getUserIdentifier(),
            'roles' => $user?->getRoles() ?? [],
        ]);
    }
}
