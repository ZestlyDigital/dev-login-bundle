<p align="center">
  <img src="https://repository-images.githubusercontent.com/1334663449/611f5257-b7f9-42a8-a94a-deef120ce8bb" alt="Dev Login Bundle — the simplest way for AI agents to log in to your Symfony app. No passwords." width="100%">
</p>

# Dev Login Bundle

**Log in as any user, without a password, in your Symfony dev environment.**
Built for humans — and for coding agents that can't type your password.

[![CI](https://github.com/ZestlyDigital/dev-login-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/ZestlyDigital/dev-login-bundle/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/zestly/dev-login-bundle.svg)](https://packagist.org/packages/zestly/dev-login-bundle)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

```bash
composer require --dev zestly/dev-login-bundle
```

```
GET /_dev/login/admin@example.com     → logged in, redirected
GET /_dev/login                       → JSON list of who you can be
php bin/console dev:login admin@…     → prints the URL
```

---

## Why

Typing a password into your own dev environment is a small annoyance you've stopped noticing.

Then you point a coding agent at your app, and it stops being small. The agent opens a browser,
hits your login wall, and halts — waiting for a human to come back and type `password`. Every
session. You can't leave it running, because the thing blocking it is the one thing it cannot do.

Laravel developers solved the human half of this years ago
([`spatie/laravel-login-link`](https://github.com/spatie/laravel-login-link) alone does ~94k
installs a month). Symfony has had no equivalent at all, and nobody in either ecosystem has built
for the agent half — every existing package is a button on a login page, which is no use to
something with no hands.

This is that, for Symfony, designed so an agent can drive it unattended.

## Install

```bash
composer require --dev zestly/dev-login-bundle
```

If you allow Symfony Flex to execute the recipe, that is the whole installation — the bundle is
registered for `dev` only and both config files are written for you.

<details>
<summary>Installing without the recipe</summary>

Register the bundle for `dev` only:

```php
// config/bundles.php
return [
    // ...
    Zestly\DevLoginBundle\ZestlyDevLoginBundle::class => ['dev' => true],
];
```

Import the routes into an environment-scoped file. The directory name is doing real work here —
it is what keeps these URLs out of your production router:

```yaml
# config/routes/dev/zestly_dev_login.yaml
zestly_dev_login:
    resource: '@ZestlyDevLoginBundle/config/routes.php'
```

</details>

No `security.yaml` changes are needed either way — see [Access control](#access-control) for why
that is harder than it looks, and what the bundle does about it.

## Use

### As a human

```
http://localhost:8000/_dev/login/admin@example.com
http://localhost:8000/_dev/login/admin@example.com?target=/dashboard
```

### As an agent

Ask who's available, get URLs back:

```bash
curl -s localhost:8000/_dev/login | jq
```

```json
{
  "host": "localhost",
  "token_required": false,
  "usage": {
    "login": "http://localhost:8000/_dev/login/{identifier}",
    "json": "Send \"Accept: application/json\" to the login URL to receive the resulting identity instead of a redirect.",
    "note": "Any identifier your application's user provider accepts will work. The list below is a convenience, not a whitelist."
  },
  "identities": [
    {
      "identifier": "admin@example.com",
      "label": "Admin",
      "roles": ["ROLE_ADMIN"],
      "login_url": "http://localhost:8000/_dev/login/admin%40example.com"
    }
  ]
}
```

Declare the menu in config:

```yaml
# config/packages/dev/zestly_dev_login.yaml
zestly_dev_login:
    identities:
        - { identifier: 'admin@example.com',     label: 'Admin',     roles: ['ROLE_ADMIN'] }
        - { identifier: 'requestor@example.com', label: 'Requestor', description: 'Sees only their own tickets' }
```

Or serve it from your fixtures, a repository, or per-subdomain, by implementing
[`IdentityProviderInterface`](src/Identity/IdentityProviderInterface.php).

### Telling your agent it exists

Add this to your `CLAUDE.md`, `AGENTS.md`, or equivalent:

```markdown
## Logging in during local testing

Never ask the user for a password. `GET /_dev/login` returns the available
identities and a `login_url` for each. Navigate to a `login_url` to become that
user. Add `?target=/some/path` to land somewhere specific.
```

## Safety

This bundle grants password-free login to any account. That is the whole point, and it is also
exactly why the interesting part isn't the login — it's the four independent gates standing
between it and anything that isn't your laptop. Each one is sufficient on its own.

| # | Gate | What it does |
|---|------|--------------|
| 1 | **Container** | Outside `allowed_envs`, the extension registers *nothing* — no services, no controllers, no command. There is no code to reach. |
| 2 | **Routing** | Routes are imported by your app from `config/routes/dev/`, so a production router never learns the paths exist. |
| 3 | **Network** | Requests from outside `allowed_ips` (loopback + RFC1918 by default) are rejected. |
| 4 | **Runtime** | The environment and debug flag are re-checked on every request and every command invocation. |

Two deliberate design choices worth knowing about:

**Failure is silent, not loud.** A bundle left registered in production degrades to a no-op rather
than throwing. Refusing to compile the container would fail *closed* in the strictest sense, and it
would also take a live site down — a worse outcome than a bundle that simply isn't there. The one
exception is an explicit `enabled: true` in a disallowed environment, which is unambiguous
misconfiguration rather than an accident. That throws at compile time, breaking a deploy's cache
warmup rather than the running app.

**Rejections are 404, not 403.** A 403 confirms the endpoint exists. A 404 tells a scanner nothing.

Authentication always goes through your application's own user provider rather than fetching a row
directly, so whatever rules it already enforces — the account exists, is active, is permitted on
this tenant — keep enforcing themselves. A shortcut here would be simpler and would quietly make
dev behave unlike production in precisely the cases worth testing.

### Access control

Your `access_control` almost certainly ends with a catch-all:

```yaml
- { path: ^/, roles: ROLE_USER }
```

Access rules are first-match-wins, so the dev login endpoints have to be matched *before* it. Both
obvious ways of arranging that fail:

- Adding a rule under `when@dev:` — environment config is merged **after** your own rules, so it
  lands below the catch-all and is never reached.
- Prepending an `access_control` section from the bundle — `security.access_control` is declared
  `cannotBeOverwritten()`, so Symfony rejects the config outright.

So the rule is injected one layer lower, into the built `security.access_map` service, where
ordering is just the order of method calls and can be controlled precisely. You don't have to touch
your production `security.yaml`, and the test suite runs against a fixture app with a `^/` catch-all
so a regression here fails the build.

## Multi-tenant apps

Nothing extra to configure. Because logins go through your user provider, a provider that rejects
users from the wrong tenant keeps rejecting them here — `tenant-a.localhost/_dev/login/user@b.com`
fails exactly as it should.

To vary the *advertised* list per host, implement `IdentityProviderInterface`; it receives the
current `Request`, so `tenant-a.localhost` can list a different menu from `tenant-b.localhost`.

## Configuration

Every option, with its default:

```yaml
zestly_dev_login:
    enabled: ~                    # null = auto (on inside allowed_envs)
    allowed_envs: ['dev']
    require_debug: true
    path_prefix: '/_dev/login'
    firewall: 'main'
    user_provider: ~              # auto-detected when the app defines exactly one
    default_target: '/'
    secret: ~                     # if set, requires ?token=<secret>
    allowed_ips:
        - '127.0.0.1'
        - '::1'
        - '10.0.0.0/8'
        - '172.16.0.0/12'
        - '192.168.0.0/16'
        - 'fc00::/7'
    identities: []
```

Set `secret` if your dev environment is reachable by anyone but you. Set `allowed_ips: []` to
disable the network gate — for example in CI, where the client IP isn't predictable.

## Requirements

PHP 8.2+ · Symfony 7.0 / 8.0

## Contributing

```bash
composer install
composer test
composer phpstan
```

## Licence

MIT — see [LICENSE](LICENSE).

Built by [Zestly Digital](https://zestlydigital.com).
