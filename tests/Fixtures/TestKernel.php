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
use Zestly\DevLoginBundle\Identity\IdentityProviderInterface;
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
        private readonly bool $overrideIdentityProvider = false,
        private readonly bool $firewallWithoutAuthenticator = false,
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
        // A non-debug kernel does not revalidate its container, so anything that changes the
        // container must change this path or the stale one is silently reused. That includes
        // the bundle's own source: editing a constructor and re-running the suite otherwise
        // resurrects a container wired for the previous signature, which fails as a confusing
        // TypeError far from its cause.
        $key = md5(serialize($this->devLoginConfig)
            .($this->overrideIdentityProvider ? 'override' : '')
            .($this->firewallWithoutAuthenticator ? 'noauth' : '')
            .self::sourceFingerprint());

        return sys_get_temp_dir().'/zestly-dev-login/'.$this->environment.'/'
            .($this->debug ? 'debug' : 'nodebug').'/'.substr($key, 0, 12).'/cache';
    }

    private static function sourceFingerprint(): string
    {
        static $fingerprint = null;

        if (null !== $fingerprint) {
            return $fingerprint;
        }

        $newest = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../../src'));

        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $newest = max($newest, $file->getMTime());
            }
        }

        return $fingerprint = (string) $newest;
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
            // A stock symfony/skeleton ships `main` with a provider and NO login mechanism,
            // which makes Security::login() throw. Both shapes are exercised.
            'firewalls' => [
                'main' => array_filter([
                    'lazy' => true,
                    'provider' => 'test_users',
                    'http_basic' => $this->firewallWithoutAuthenticator ? null : true,
                ]),
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

        if ($this->overrideIdentityProvider) {
            // Exactly how a consuming application swaps the identity list in: alias the
            // interface to its own service. If the bundle wires its controllers to the
            // concrete service instead of this alias, the override is silently ignored.
            $container->services()
                ->set(HostAwareIdentityProvider::class)
                ->alias(IdentityProviderInterface::class, HostAwareIdentityProvider::class);
        }
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
