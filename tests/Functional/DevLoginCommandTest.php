<?php

declare(strict_types=1);

namespace Zestly\DevLoginBundle\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Zestly\DevLoginBundle\Tests\Fixtures\RestoresErrorHandlers;
use Zestly\DevLoginBundle\Tests\Fixtures\TestKernel;

/**
 * The command only prints a URL, so what matters is that the URL is one the developer can
 * open: right prefix, right encoding, and the application's own scheme and host by default.
 */
final class DevLoginCommandTest extends TestCase
{
    use RestoresErrorHandlers;

    /**
     * @param array<string, mixed> $input
     */
    private function urlFor(array $input, ?string $defaultUri = null): string
    {
        $kernel = new TestKernel('dev', true, defaultUri: $defaultUri);
        $tester = new CommandTester((new Application($kernel))->find('dev:login'));

        $tester->execute($input);
        $tester->assertCommandIsSuccessful();

        return trim($tester->getDisplay());
    }

    public function testPrintsALoginUrlForTheIdentifier(): void
    {
        self::assertSame(
            'http://localhost/_dev/login/admin%40example.com',
            $this->urlFor(['identifier' => 'admin@example.com']),
        );
    }

    public function testDefaultsToTheApplicationsDefaultUri(): void
    {
        self::assertSame(
            'https://app.example.test/_dev/login/admin%40example.com',
            $this->urlFor(['identifier' => 'admin@example.com'], 'https://app.example.test'),
            'framework.router.default_uri is how an application declares its own host',
        );
    }

    public function testOptionsOverrideSchemeAndHostAndAddQuery(): void
    {
        self::assertSame(
            'https://tenant-a.localhost/_dev/login/admin%40example.com?target=%2Fdashboard',
            $this->urlFor([
                'identifier' => 'admin@example.com',
                '--scheme' => 'https',
                '--host' => 'tenant-a.localhost',
                '--target' => '/dashboard',
            ]),
        );
    }
}
