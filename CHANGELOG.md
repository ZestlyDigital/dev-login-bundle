# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.1] - 2026-08-15

### Fixed

- Logging in failed with a 500 (`No authenticators found for firewall "main"`) on any firewall
  that declares no login mechanism — which is precisely the shape a stock `symfony/skeleton`
  ships, so the bundle did not work on a fresh install. `Security::login()` requires the
  firewall to have at least one authenticator; when it does not, the session token is now
  established directly and `InteractiveLoginEvent` dispatched. Applications that do have an
  authenticator are unaffected and keep the full login path.

## [0.1.0] - 2026-08-15

Initial release.

### Added

- `GET {prefix}/{identifier}` logs in as any user your application's user provider accepts,
  with no password. Redirects by default; returns JSON when asked for it via `Accept`.
- `GET {prefix}` discovery endpoint returning the available identities and a ready-to-follow
  `login_url` for each, built from the current host so it stays correct under subdomain routing.
- `dev:login` console command — lists known identities, or prints a login URL for one.
- `IdentityProviderInterface` for serving the identity list from fixtures, a repository, or
  per-host logic instead of static configuration.
- Four independent safety gates: container registration, environment-scoped routing, client IP
  allow-list, and a runtime environment re-check.
- Optional shared `secret`, required as `?token=`, for dev environments reachable by others.
- Automatic `PUBLIC_ACCESS` handling via the `security.access_map` service, so a host application
  needs no changes to its production `security.yaml`.
- Symfony Flex recipe under `recipe/`, reducing installation to `composer require --dev`.
  Registers the bundle for `dev` only and writes both config files. Pending submission to
  `symfony/recipes-contrib`, which requires the package to be on Packagist first.

[Unreleased]: https://github.com/ZestlyDigital/dev-login-bundle/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/ZestlyDigital/dev-login-bundle/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/ZestlyDigital/dev-login-bundle/releases/tag/v0.1.0
