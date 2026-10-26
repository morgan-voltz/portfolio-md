# plugin/ — WordPress plugin `portfolio-md`

This folder hosts the WordPress plugin that accepts Markdown content, stores both the raw Markdown and a derived Gutenberg HTML representation, and exposes a custom REST API for downstream consumers.

The plugin is written in **PHP 8.2+** with modern conventions: PSR-4 autoloading via Composer, dependency injection, a layered architecture inspired by Clean Architecture principles.

## Current status

The **content model is complete** (roadmap phase 1). The plugin registers:

- Custom post types `article` and `project` (`PostTypes\PostTypeRegistrar`)
- Taxonomies `tech_tag` (flat, both types), `project_status` (flat, projects, closed list) and `skill` (hierarchical by referential, projects, official code in the `_portfolio_skill_code` term meta) via `Taxonomies\TaxonomyRegistrar`
- Seventeen post metas, all prefixed `_portfolio_`: four public metas shared by both types (`Meta\CommonMetaRegistrar`), eight public project metas including the `is_fil_rouge` flag (`Meta\ProjectMetaRegistrar`), five internal metas hidden from REST and locked for users (`Meta\InternalMetaRegistrar`). Sanitization rules live in `Meta\Sanitizer`.

What is **not yet** implemented: Markdown parsing (`league/commonmark`), YAML frontmatter handling (`symfony/yaml`), the storage layer that persists both raw Markdown and derived Gutenberg HTML, the admin UI for Markdown editing, and the custom REST endpoints. See [`../docs/architecture/01-plugin-php.md`](../docs/architecture/01-plugin-php.md) for the target architecture and [`../docs/architecture/04-structure-wordpress.md`](../docs/architecture/04-structure-wordpress.md) for the content model.

## Local setup

The plugin is consumed by the WordPress container via the bind mount declared in the root `docker-compose.yml`. Any change you make in this folder is immediately visible in the running WordPress install — no rebuild needed.

```bash
# 1. Install PHP dependencies (host with PHP + Composer)
cd plugin
composer install

# 1 bis. Same thing without PHP on the host: run Composer in a container
podman run --rm -v "$PWD:/app:z" docker.io/library/composer:2 install

# 2. (From the repo root) make sure the WordPress stack is running
podman-compose up -d

# 3. Activate the plugin via WP-CLI
podman-compose --profile tools run --rm wpcli wp plugin activate portfolio-md

# 4. Verify registered post types, taxonomies and seeded terms
podman-compose --profile tools run --rm wpcli wp post-type list
podman-compose --profile tools run --rm wpcli wp taxonomy list
podman-compose --profile tools run --rm wpcli wp term list skill --fields=name,slug,parent
```

For a full setup from scratch (containers + database + WordPress install + plugin activation), use the [`bootstrap.sh`](../bootstrap.sh) script at the repo root.

Activation matters: `Plugin::onActivation()` seeds the closed `project_status` terms and the two `skill` referential parents, then flushes rewrite rules. After changing anything in that method, deactivate and re-activate the plugin.

## Tests

Two complementary suites.

**Unit suite (PHPUnit 11, no WordPress).** The registrars call WordPress functions, so `tests/bootstrap.php` loads small stand-ins (`tests/Support/wp-stubs.php`) that record registrations in `WordPressSpy` or mimic WordPress closely enough for the covered cases. The suite checks that every meta is registered with the right type, REST visibility and callbacks, and that every sanitizer accepts and rejects what it should.

```bash
# Host with PHP
cd plugin
composer test            # or: composer test:unit

# Without PHP on the host
podman run --rm -v "$PWD:/app:z" -w /app docker.io/library/php:8.3-cli vendor/bin/phpunit
```

**Smoke test in the real WordPress (WP-CLI).** `tests/Integration/wp-cli/meta-smoke.php` runs inside the `wpcli` container against the live install: effective registrations, sanitization through `update_post_meta()`, capability rules, REST representation. It creates one draft project and deletes it. Run it from the repo root with the stack up and the plugin activated:

```bash
podman-compose --profile tools run --rm wpcli wp eval-file \
  wp-content/plugins/portfolio-md/tests/Integration/wp-cli/meta-smoke.php \
  --user=<your admin login>
```

`--user` is required for the capability checks. The official `wp-phpunit` framework will come with the storage, REST and admin layers.

## Quick debug references

Inspect the meta keys registered by the plugin for the `article` CPT, with their REST visibility and authorization callback:

```bash
podman-compose --profile tools run --rm wpcli wp eval '
foreach (get_registered_meta_keys("post", "article") as $key => $args) {
  printf("%-34s type=%-8s rest=%s auth=%s\n", $key, $args["type"],
    var_export($args["show_in_rest"], true),
    is_string($args["auth_callback"]) ? $args["auth_callback"] : "callable");
}'
```

Same idea for `project` (replace `"article"` by `"project"`), and for the `skill` term meta with `get_registered_meta_keys("term", "skill")`.

> **Pitfall** : the form `docker compose exec wordpress wp eval "..."` (or `podman-compose exec wordpress wp ...`) **does not work**. The `wordpress:latest` image does not contain the `wp` binary — WP-CLI lives in the separate `wpcli` service. Always invoke WP-CLI through `--profile tools run --rm wpcli`. Full explanation in [`../docs/pedagogie/containers-et-wp-cli.md`](../docs/pedagogie/containers-et-wp-cli.md) §3.

> **Pitfall** : a script run through `wp eval-file` must not start with `declare(strict_types=1)`: WP-CLI evaluates the file with `eval()`, where the declaration is no longer the first statement.

## Common pitfalls

The most frequent issues you'll hit while developing locally are documented in [`../docs/pedagogie/containers-et-wp-cli.md`](../docs/pedagogie/containers-et-wp-cli.md). In particular:

- `wp: executable not found` → you used `exec wordpress` instead of `--profile tools run --rm wpcli`
- `missing services [wpcli]` → you forgot `--profile tools`
- MariaDB restart loop → conflict between `podman compose` and `podman-compose` stacks sharing the same bind mount
- A public meta that cannot be written through REST or Gutenberg, even as administrator → its key starts with `_` and it lacks an `auth_callback`; register it with `MetaRegistration::authorizeEditPost` (see ADR-013)

## Architecture

For complete architectural details (layer boundaries, naming conventions, dependency rules), see [`../docs/architecture/01-plugin-php.md`](../docs/architecture/01-plugin-php.md). For the content model (post types, taxonomies, metas and their sanitization rules), see [`../docs/architecture/04-structure-wordpress.md`](../docs/architecture/04-structure-wordpress.md).
