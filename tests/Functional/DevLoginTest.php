<?php

declare(strict_types=1);

namespace Zestly\DevLoginBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zestly\DevLoginBundle\Tests\Fixtures\RestoresErrorHandlers;
use Zestly\DevLoginBundle\Tests\Fixtures\TestKernel;

/**
 * Drives a real kernel over real requests. The safety gates are the product here, so they
 * get the same weight in the suite as the happy path.
 */
final class DevLoginTest extends TestCase
{
    use RestoresErrorHandlers;

    /**
     * @param array<string, mixed>  $config
     * @param array<string, string> $server
     */
    private function handle(string $uri, array $config = [], string $env = 'dev', bool $debug = true, string $clientIp = '127.0.0.1', array $server = []): Response
    {
        $kernel = new TestKernel($env, $debug, $config);
        $kernel->boot();

        $request = Request::create($uri, 'GET', server: ['REMOTE_ADDR' => $clientIp] + $server);
        $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(
            new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()
        ));

        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    public function testLoggingInRedirectsAndAuthenticatesTheUser(): void
    {
        $kernel = new TestKernel('dev', true, []);
        $kernel->boot();

        $login = Request::create('/_dev/login/admin@example.com', 'GET', server: ['REMOTE_ADDR' => '127.0.0.1']);
        $session = new \Symfony\Component\HttpFoundation\Session\Session(
            new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()
        );
        $login->setSession($session);

        $response = $kernel->handle($login);

        self::assertSame(302, $response->getStatusCode(), 'A browser login should redirect');
        self::assertNotNull($session->get('_security_main'), 'The firewall session token must be written');

        // Reuse the same session on a protected route to prove the login actually took.
        // The cookie matters: Symfony's ContextListener restores a token only when
        // Request::hasPreviousSession() is true, which requires the session cookie to be
        // present — a session object alone looks like a brand new visitor.
        $whoami = Request::create('/whoami', 'GET', server: ['REMOTE_ADDR' => '127.0.0.1']);
        $whoami->setSession($session);
        $whoami->cookies->set($session->getName(), $session->getId());

        $body = json_decode((string) $kernel->handle($whoami)->getContent(), true);

        self::assertSame('admin@example.com', $body['identifier'], 'The protected route must see the logged-in user');
        self::assertContains('ROLE_ADMIN', $body['roles'], 'Roles must come from the real user provider');
    }

    public function testJsonAcceptHeaderReturnsTheIdentityInsteadOfARedirect(): void
    {
        $response = $this->handle(
            '/_dev/login/admin@example.com',
            server: ['HTTP_ACCEPT' => 'application/json'],
        );

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('admin@example.com', $body['identifier']);
        self::assertSame('main', $body['firewall']);
    }

    public function testDiscoveryListsConfiguredIdentitiesWithUsableUrls(): void
    {
        $response = $this->handle('/_dev/login', [
            'identities' => [
                ['identifier' => 'admin@example.com', 'label' => 'Admin', 'roles' => ['ROLE_ADMIN']],
                ['identifier' => 'requestor@example.com', 'label' => 'Requestor'],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);

        self::assertCount(2, $body['identities']);
        self::assertSame('Admin', $body['identities'][0]['label']);
        self::assertSame(
            'http://localhost/_dev/login/admin%40example.com',
            $body['identities'][0]['login_url'],
            'Discovery must hand back a URL an agent can follow without assembling it',
        );
        self::assertFalse($body['token_required']);
    }

    public function testApplicationCanOverrideTheIdentityProvider(): void
    {
        $kernel = new TestKernel('dev', true, [
            'identities' => [['identifier' => 'ignored@example.com', 'label' => 'Should not appear']],
        ], overrideIdentityProvider: true);
        $kernel->boot();

        $request = Request::create('/_dev/login', 'GET', server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'tenant-a.localhost',
        ]);

        $body = json_decode((string) $kernel->handle($request)->getContent(), true);

        self::assertCount(1, $body['identities']);
        self::assertSame(
            'Admin on tenant-a.localhost',
            $body['identities'][0]['label'],
            'The application alias must win over the bundle default, and receive the Request',
        );
    }

    public function testUnknownIdentifierIs404(): void
    {
        $response = $this->handle('/_dev/login/nobody@example.com');

        self::assertSame(404, $response->getStatusCode());
    }

    public function testTargetQueryParameterControlsTheRedirect(): void
    {
        $response = $this->handle('/_dev/login/admin@example.com?target=/whoami');

        self::assertSame('/whoami', $response->headers->get('Location'));
    }

    #[DataProvider('openRedirectProvider')]
    public function testOffSiteRedirectTargetsAreIgnored(string $target): void
    {
        $response = $this->handle('/_dev/login/admin@example.com?target='.urlencode($target));

        self::assertSame('/', $response->headers->get('Location'), 'Off-site targets must fall back to the default');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function openRedirectProvider(): iterable
    {
        yield 'protocol relative' => ['//evil.example.com'];
        yield 'absolute url' => ['https://evil.example.com/x'];
        yield 'relative path' => ['dashboard'];
    }

    public function testRequestFromOutsideAllowedIpsIs404(): void
    {
        $response = $this->handle('/_dev/login/admin@example.com', clientIp: '203.0.113.9');

        self::assertSame(404, $response->getStatusCode(), 'A public IP must never reach dev login');
    }

    public function testAllowedIpsCanBeDisabled(): void
    {
        $response = $this->handle(
            '/_dev/login/admin@example.com',
            ['allowed_ips' => []],
            clientIp: '203.0.113.9',
        );

        self::assertSame(302, $response->getStatusCode());
    }

    public function testSecretIsRequiredWhenConfigured(): void
    {
        $config = ['secret' => 's3cret'];

        self::assertSame(404, $this->handle('/_dev/login/admin@example.com', $config)->getStatusCode());
        self::assertSame(404, $this->handle('/_dev/login/admin@example.com?token=wrong', $config)->getStatusCode());
        self::assertSame(302, $this->handle('/_dev/login/admin@example.com?token=s3cret', $config)->getStatusCode());
    }

    public function testDebugIsRequiredByDefault(): void
    {
        $response = $this->handle('/_dev/login/admin@example.com', debug: false);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testPathPrefixIsConfigurable(): void
    {
        $response = $this->handle('/__backstage/admin@example.com', ['path_prefix' => '/__backstage']);

        self::assertSame(302, $response->getStatusCode());
    }
}
