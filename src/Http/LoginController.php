<?php

declare(strict_types=1);

namespace Zestly\DevLoginBundle\Http;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Zestly\DevLoginBundle\Security\AccessGuard;

/**
 * Logs in as {identifier}, with no password.
 *
 * Authentication goes through the application's own user provider rather than fetching a
 * row directly. That is the single most important design decision in this bundle: whatever
 * rules the provider already enforces — the account exists, is active, is permitted on this
 * tenant or subdomain — keep enforcing themselves here. A shortcut that bypassed the provider
 * would be simpler, and would quietly make dev behave unlike production in exactly the cases
 * worth testing.
 */
final readonly class LoginController
{
    /**
     * @param UserProviderInterface<UserInterface> $userProvider
     */
    public function __construct(
        private UserProviderInterface $userProvider,
        private Security $security,
        private AccessGuard $guard,
        private TokenStorageInterface $tokenStorage,
        private EventDispatcherInterface $eventDispatcher,
        private string $firewallName,
        private string $defaultTarget,
    ) {
    }

    public function __invoke(Request $request, string $identifier): Response
    {
        $this->guard->assertRequest($request);

        try {
            $user = $this->userProvider->loadUserByIdentifier($identifier);
        } catch (AuthenticationException $e) {
            // The original is deliberately NOT chained as `previous`: Symfony's security
            // ExceptionListener walks the previous-exception chain, finds the
            // AuthenticationException and converts the whole response into a 401 challenge,
            // turning a clear "no such user" into a login prompt.
            //
            // Surface the provider's own reason — "no such user", "wrong tenant", "inactive"
            // are all useful answers, and this only ever renders in a debug environment.
            throw new NotFoundHttpException(\sprintf(
                'Cannot log in as "%s": %s',
                $identifier,
                $e->getMessage(),
            ));
        }

        try {
            $this->security->login($user, firewallName: $this->firewallName);
        } catch (\LogicException) {
            // Security::login() needs the firewall to declare at least one authenticator, and
            // throws "No authenticators found for firewall" when it does not. A stock Symfony
            // skeleton is exactly that case: security.yaml ships a `main` firewall with a
            // provider and no login mechanism until you add one. Failing there would mean the
            // bundle does not work on a fresh install, which is where it is most useful.
            //
            // So fall back to establishing the token directly. Applications that do have an
            // authenticator keep the full path above, with its badge handling and
            // LoginSuccessEvent. Catching LogicException broadly is safe here: every outcome
            // of this method is "log this user in", so a fallback is never more permissive
            // than the call it replaces.
            $this->establishToken($request, $user);
        }

        if ($this->wantsJson($request)) {
            return new JsonResponse([
                'status' => 'ok',
                'identifier' => $user->getUserIdentifier(),
                'roles' => $user->getRoles(),
                'firewall' => $this->firewallName,
            ]);
        }

        return new RedirectResponse($this->resolveTarget($request));
    }

    /**
     * Log in without an authenticator.
     *
     * Setting the token is enough to persist the session: this request is handled by the
     * firewall, so its ContextListener writes the token to the session on response, exactly
     * as it would after a form login. InteractiveLoginEvent is dispatched so application
     * listeners that react to a login still run.
     */
    private function establishToken(Request $request, UserInterface $user): void
    {
        $token = new UsernamePasswordToken($user, $this->firewallName, $user->getRoles());

        $this->tokenStorage->setToken($token);
        $this->eventDispatcher->dispatch(new InteractiveLoginEvent($request, $token));
    }

    /**
     * Only same-site absolute paths are accepted. An open redirect here would be low impact
     * — dev only, already authenticated — but a login endpoint that forwards to an arbitrary
     * host is the kind of pattern that gets copied into somewhere it matters.
     */
    private function resolveTarget(Request $request): string
    {
        $target = $request->query->get('target');

        if (!\is_string($target) || '' === $target) {
            return $this->defaultTarget;
        }

        if (!str_starts_with($target, '/') || str_starts_with($target, '//')) {
            return $this->defaultTarget;
        }

        return $target;
    }

    private function wantsJson(Request $request): bool
    {
        if ('json' === $request->getRequestFormat(null)) {
            return true;
        }

        return str_contains((string) $request->headers->get('Accept'), 'application/json');
    }
}
