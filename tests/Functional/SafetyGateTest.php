<?php

declare(strict_types=1);

namespace Zestly\DevLoginBundle\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Zestly\DevLoginBundle\Tests\Fixtures\RestoresErrorHandlers;
use Zestly\DevLoginBundle\Tests\Fixtures\TestKernel;

/**
 * The container gate — the one that matters most.
 *
 * Everything else in this bundle is a convenience. This is the test that says a production
 * build contains no code capable of logging anyone in without a password.
 */
final class SafetyGateTest extends TestCase
{
    use RestoresErrorHandlers;

    private function testContainer(TestKernel $kernel): ContainerInterface
    {
        $kernel->boot();

        /** @var ContainerInterface $container */
        $container = $kernel->getContainer()->get('test.service_container');

        return $container;
    }

    public function testNoServicesAreRegisteredInProduction(): void
    {
        $container = $this->testContainer(new TestKernel('prod', false));

        self::assertFalse($container->has('zestly_dev_login.access_guard'), 'The guard must not exist in prod');
        self::assertFalse($container->has('zestly_dev_login.controller.login'), 'The login controller must not exist in prod');
        self::assertFalse($container->has('zestly_dev_login.controller.discovery'), 'The discovery controller must not exist in prod');
        self::assertFalse($container->has('zestly_dev_login.command'), 'The console command must not exist in prod');
    }

    public function testProductionContainerCompilesRatherThanThrowing(): void
    {
        // A bundle left registered in prod must degrade to nothing, not take the site down.
        $kernel = new TestKernel('prod', false);
        $kernel->boot();

        self::assertFalse(
            $kernel->getContainer()->hasParameter('zestly_dev_login.path_prefix'),
            'A disabled bundle should leave no parameters behind',
        );
    }

    public function testExplicitlyEnablingInProductionIsAHardError(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/must never be reachable in production/');

        (new TestKernel('prod', false, ['enabled' => true]))->boot();
    }

    public function testExplicitlyDisablingInDevRemovesEverything(): void
    {
        $container = $this->testContainer(new TestKernel('dev', true, ['enabled' => false]));

        self::assertFalse($container->has('zestly_dev_login.access_guard'));
    }

    public function testAdditionalEnvironmentsCanBeOptedInDeliberately(): void
    {
        $container = $this->testContainer(
            new TestKernel('staging', true, ['allowed_envs' => ['dev', 'staging'], 'enabled' => true])
        );

        self::assertTrue($container->has('zestly_dev_login.access_guard'));
    }

    public function testServicesExistInDev(): void
    {
        $container = $this->testContainer(new TestKernel('dev', true));

        self::assertTrue($container->has('zestly_dev_login.access_guard'));
        self::assertTrue($container->has('zestly_dev_login.controller.login'));
    }
}
