# plugin/ — WordPress plugin `portfolio-md`

This folder hosts the WordPress plugin that accepts Markdown content, stores both the raw Markdown and a derived Gutenberg HTML representation, and exposes a custom REST API for downstream consumers.

The plugin is written in **PHP 8.2+** with modern conventions: PSR-4 autoloading via Composer, dependency injection, a layered architecture inspired by Clean Architecture principles.

## Current status

Implementation in progress. The current scaffolding includes:

- PSR-4 autoloading wired through `composer.json` (namespace `Voltz\PortfolioMd`)
- Plugin entry point `portfolio-md.php` with composition root `Voltz\PortfolioMd\Plugin`
- Custom post types `article` and `project` registered via `PostTypes\PostTypeRegistrar`
- Taxonomies `tech_tag` and `project_status` registered via `Taxonomies\TaxonomyRegistrar`
- Meta registration in progress on the active branch (`Meta\CommonMetaRegistrar`, `Meta\ProjectMetaRegistrar`, `Meta\InternalMetaRegistrar`)

What is **not yet** implemented: Markdown parsing (will rely on `league/commonmark`), YAML frontmatter handling (`symfony/yaml`), the storage layer that persists both raw Markdown and derived Gutenberg HTML, the admin UI for Markdown editing, and the custom REST endpoints. See [`../docs/architecture/04-structure-wordpress.md`](../docs/architecture/04-structure-wordpress.md) for the full target architecture.

## Local setup

The plugin is consumed by the WordPress container via the bind mount declared in the root `docker-compose.yml`. Any change you make in this folder is immediately visible in the running WordPress install — no rebuild needed.

```bash
# 1. Install PHP dependencies
cd plugin
composer install

# 2. (From the repo root) make sure the WordPress stack is running
podman-compose up -d

# 3. Activate the plugin via WP-CLI
podman-compose --profile tools run --rm wpcli wp plugin activate portfolio-md

# 4. Verify registered post types and taxonomies
podman-compose --profile tools run --rm wpcli wp post-type list
podman-compose --profile tools run --rm wpcli wp taxonomy list
```

For a full setup from scratch (containers + database + WordPress install + plugin activation), use the [`bootstrap.sh`](../bootstrap.sh) script at the repo root.

## Tests

PHPUnit 11 is declared in `require-dev` and the autoload-dev maps `Voltz\PortfolioMd\Tests\` to `tests/`, so the dependency layer is ready. **What is not yet wired**: a `tests/` directory, a `phpunit.xml.dist` configuration file, and a `"scripts": { "test": "phpunit" }` entry in `composer.json`.

Until those three pieces are added, `composer test` returns `Command "test" is not defined`. Once wired, the suite is intended to run entirely on the host (no WordPress runtime needed) — integration tests that require a live WordPress install will live in a separate suite.

## Quick debug references

Inspect the meta keys registered by the plugin for the `article` CPT (useful while wiring `Meta\CommonMetaRegistrar` and friends):

```bash
podman-compose --profile tools run --rm wpcli \
  wp eval "var_dump(get_registered_meta_keys('post', 'article'));"
```

Same idea for `project`:

```bash
podman-compose --profile tools run --rm wpcli \
  wp eval "var_dump(get_registered_meta_keys('post', 'project'));"
```

> **Pitfall** : the form `docker compose exec wordpress wp eval "..."` (or `podman-compose exec wordpress wp ...`) **does not work**. The `wordpress:latest` image does not contain the `wp` binary — WP-CLI lives in the separate `wpcli` service. Always invoke WP-CLI through `--profile tools run --rm wpcli`. Full explanation in [`../docs/pedagogie/containers-et-wp-cli.md`](../docs/pedagogie/containers-et-wp-cli.md) §3.

## Common pitfalls

The most frequent issues you'll hit while developing locally are documented in [`../docs/pedagogie/containers-et-wp-cli.md`](../docs/pedagogie/containers-et-wp-cli.md). In particular:

- `wp: executable not found` → you used `exec wordpress` instead of `--profile tools run --rm wpcli`
- `missing services [wpcli]` → you forgot `--profile tools`
- MariaDB restart loop → conflict between `podman compose` and `podman-compose` stacks sharing the same bind mount

## Architecture

For complete architectural details (layer boundaries, naming conventions, dependency rules), see [`../docs/architecture/04-structure-wordpress.md`](../docs/architecture/04-structure-wordpress.md).
