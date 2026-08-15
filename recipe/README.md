# Flex recipe

Makes installation a single command:

```bash
composer require --dev zestly/dev-login-bundle
```

With the recipe applied, Flex registers the bundle for `dev` only and writes both config
files, so there is no manual `bundles.php` edit and no routes import to remember.

## Layout

```
zestly/dev-login-bundle/0.1/
├── manifest.json
└── config/
    ├── packages/dev/zestly_dev_login.yaml
    └── routes/dev/zestly_dev_login.yaml
```

`0.1` is the *minimum* version the recipe applies to, not an exact match — it keeps applying
to later releases until a higher-numbered directory supersedes it. Add a new directory only
for a genuinely breaking change in the config shape.

## Submitting

Recipes for community packages live in
[`symfony/recipes-contrib`](https://github.com/symfony/recipes-contrib). The package must be
published on Packagist before the PR will be accepted — the CI there resolves it.

1. Fork `symfony/recipes-contrib`.
2. Copy `zestly/` from this directory to the repository root, preserving the path.
3. Open a PR. Their CI validates the manifest and runs a test install.

Until it is merged, Flex falls back to an auto-generated recipe, which registers the bundle
for `['dev', 'test']` and writes no config. That is why the README documents the manual steps:
they remain correct, and they stay necessary for anyone who declines recipe execution.
