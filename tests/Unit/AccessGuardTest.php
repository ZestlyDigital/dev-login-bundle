<?php

declare(strict_types=1);

namespace Zestly\DevLoginBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Zestly\DevLoginBundle\Security\AccessGuard;
use Zestly\DevLoginBundle\ZestlyDevLoginBundle;

final class AccessGuardTest extends TestCase
{
    /**
     * @param list<string> $allowedIps
     */
    private function guard(
        array $allowedIps = ZestlyDevLoginBundle::DEFAULT_ALLOWED_IPS,
        ?string $secret = null,
        string $environment = 'dev',
        bool $debug = true,
        bool $requireDebug = true,
    ): AccessGuard {
        return new AccessGuard($allowedIps, $secret, $environment, $debug, ['dev'], $requireDebug);
    }

    private function request(string $clientIp = '127.0.0.1', string $uri = '/_dev/login/x'): Request
    {
        return Request::create($uri, 'GET', server: ['REMOTE_ADDR' => $clientIp]);
    }

    public function testPermittedEnvironmentAndLoopbackPasses(): void
    {
        $this->guard()->assertRequest($this->request());

        $this->expectNotToPerformAssertions();
    }

    #[DataProvider('privateIpProvider')]
    public function testPrivateRangesAreAllowedByDefault(string $ip): void
    {
        $this->guard()->assertRequest($this->request($ip));

        $this->expectNotToPerformAssertions();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function privateIpProvider(): iterable
    {
        yield 'loopback v4' => ['127.0.0.1'];
        yield 'loopback v6' => ['::1'];
        yield 'docker bridge' => ['172.17.0.4'];
        yield 'home lan' => ['192.168.1.20'];
        yield 'corporate 10/8' => ['10.4.5.6'];
    }

    #[DataProvider('publicIpProvider')]
    public function testPublicAddressesAreRejected(string $ip): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->guard()->assertRequest($this->request($ip));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publicIpProvider(): iterable
    {
        yield 'documentation range' => ['203.0.113.9'];
        yield 'public dns' => ['8.8.8.8'];
        yield 'public v6' => ['2001:4860:4860::8888'];
    }

    public function testEmptyAllowListDisablesTheNetworkCheck(): void
    {
        $this->guard(allowedIps: [])->assertRequest($this->request('8.8.8.8'));

        $this->expectNotToPerformAssertions();
    }

    public function testDisallowedEnvironmentIsRejected(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessageMatches('/not available in the "prod" environment/');

        $this->guard(environment: 'prod')->assertEnvironment();
    }

    public function testDebugIsRequiredUnlessOptedOut(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->guard(debug: false)->assertEnvironment();
    }

    public function testDebugRequirementCanBeWaived(): void
    {
        $this->guard(debug: false, requireDebug: false)->assertEnvironment();

        $this->expectNotToPerformAssertions();
    }

    public function testSecretMustMatchWhenSet(): void
    {
        $guard = $this->guard(secret: 'letmein');

        self::assertTrue($guard->isSecretRequired());

        $guard->assertRequest($this->request(uri: '/_dev/login/x?token=letmein'));

        $this->expectException(NotFoundHttpException::class);
        $guard->assertRequest($this->request(uri: '/_dev/login/x?token=nope'));
    }

    public function testMissingSecretIsRejected(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->guard(secret: 'letmein')->assertRequest($this->request());
    }
}
