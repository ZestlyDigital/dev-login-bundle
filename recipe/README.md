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

## Constraints worth knowing before editing this

Verified against `symfony-tools/recipes-checker`, which is what their CI runs:

- **No `aliases`.** They are rejected outright in contrib (`lint:manifests --contrib` emits
  "Aliases not supported in the contrib repository"). So there is no `composer require dev-login`
  shorthand — only the full package name.
- **Manifest keys are an allow-list.** `bundles`, `copy-from-recipe`, `copy-from-package`,
  `composer-scripts`, `dotenv`, `env`, `makefile`, `gitignore`, `post-install-output`,
  `container`, `conflict`, `dockerfile`, `docker-compose`, `add-lines`. Anything else fails.
- **Every file and directory here must be listed in `copy-from-recipe`**, directories with a
  trailing slash. Only `manifest.json`, `post-install.txt` and `Makefile` are exempt.
- **Formatting is enforced**: indentation a multiple of 4 spaces in `.yaml`/`.json`, files end
  with a newline, `.yaml` not `.yml`, no symlinks, no `.gitkeep`.
- **Suggestion config only.** Their best practice is that a recipe ships only config that has to
  be set and has no sensible default — which is why the packages file writes `identities` and
  nothing else, even though the bundle has a dozen other options.

## Submitting

Recipes for community packages live in
[`symfony/recipes-contrib`](https://github.com/symfony/recipes-contrib). The package must be
published on Packagist first — their CI resolves it.

1. Fork `symfony/recipes-contrib`.
2. Copy `zestly/` from this directory to the repository root, preserving the path.
3. Open a PR titled `Add zestly/dev-login-bundle recipe for 0.1`.

Merging is **not** fully automatic. Their bot validates and enables auto-merge, but the repo
requires an approving review from someone who is neither the bot nor the author, and either the
author or that reviewer must be a Symfony Core Merger. As a first-time contributor that means
waiting for a Core Merger to look at it.

## Consumers must opt in to contrib recipes

Contrib recipes are not applied silently. An application needs

```bash
composer config extra.symfony.allow-contrib true
```

or it will be prompted to allow the recipe at install time. The README's manual steps therefore
stay relevant for anyone who declines.

Until it is merged, Flex falls back to an auto-generated recipe, which registers the bundle
for `['dev', 'test']` and writes no config. That is why the README documents the manual steps:
they remain correct, and they stay necessary for anyone who declines recipe execution.
