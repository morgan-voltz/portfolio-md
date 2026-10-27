# plugin/ — WordPress plugin `portfolio-md`

This folder hosts the WordPress plugin that accepts Markdown content, stores both the raw Markdown and a derived Gutenberg HTML representation, and exposes a custom REST API for downstream consumers.

The plugin is written in **PHP 8.2+** with modern conventions: PSR-4 autoloading via Composer, dependency injection, a layered architecture inspired by Clean Architecture principles.

## Current status

The **content model is complete** (roadmap phase 1). The plugin registers:

- Custom post types `article` and `project` (`PostTypes\PostTypeRegistrar`)
- Taxonomies `tech_tag` (flat, both types), `project_status` (flat, projects, closed list) and `skill` (hierarchical by referential, projects, official code in the `_portfolio_skill_code` term meta) via `Taxonomies\TaxonomyRegistrar`
- Seventeen post metas, all prefixed `_portfolio_`: four public metas shared by both types (`Meta\CommonMetaRegistrar`), eight public project metas including the `is_fil_rouge` flag (`Meta\ProjectMetaRegistrar`), five internal metas hidden from REST and locked for users (`Meta\InternalMetaRegistrar`). Sanitization rules live in `Meta\Sanitizer`; metas written from PHP go through `Meta\MetaWriter::update()`, which slashes the value because `update_post_meta()` unslashes it before storing.

Phases 2 and 3 are done (26 October 2026): `Parsing\FrontmatterParser` turns a Markdown document into a typed `Domain\Frontmatter` plus its body (strict validation, explicit exceptions); `Parsing\MarkdownParser` renders GFM to HTML with server-side code highlighting (`HighlightedCodeRenderer`, highlight.php); `Parsing\GutenbergTransformer` wraps that HTML in core blocks exactly as the editor serialises them; `Service\ContentImportService` applies the source-of-truth rule of ADR-005 and `Storage\WordPressContentRepository` writes posts, terms and metas. Two WP-CLI commands drive it: `wp portfolio skills sync` (taxonomy from `content/skills.yaml`) and `wp portfolio import` (articles and projects from Markdown files), see below.

The two admin doors are in (26 October 2026, server side): `Admin\EditorMetaBox` puts the whole document in a « Document Markdown » box on the classic edit screen of articles and projects (`Admin\EditScreen` turns the block editor off there and removes the native controls the document owns), and `Admin\ImportPage` adds « Portfolio → Importer », a paste page. Both call the same `ContentImportService` as the WP-CLI command; see "Admin screens" below.

What is **not yet** implemented: the rich Markdown editor and the preview of the box (a JavaScript library to choose), the import of static pages (`type: page`), image import into the media library, the Git webhook, and the custom REST endpoints. See [`../docs/architecture/01-plugin-php.md`](../docs/architecture/01-plugin-php.md) for the target architecture and [`../docs/architecture/04-structure-wordpress.md`](../docs/architecture/04-structure-wordpress.md) for the content model.

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

## WP-CLI commands

The compose file mounts `content/` read-only into the `wpcli` service at `/srv/portfolio-content`, so the commands can read the Markdown sources.

```bash
# Preview what the skills grid would change in the skill taxonomy
podman-compose --profile tools run --rm wpcli wp portfolio skills sync /srv/portfolio-content/skills.yaml --dry-run

# Apply it (idempotent: a second run reports "0 created, 0 updated")
podman-compose --profile tools run --rm wpcli wp portfolio skills sync /srv/portfolio-content/skills.yaml

# Check the result
podman-compose --profile tools run --rm wpcli wp term list skill --fields=term_id,name,slug,parent --format=table
podman-compose --profile tools run --rm wpcli wp term meta get <term_id> _portfolio_skill_code
```

Terms that exist in WordPress but not in the grid are listed as orphans and left untouched; deleting them is a human decision. A term WordPress refuses is reported as `FAILED`, the run continues, and the command exits with an error after the full report.

The WordPress side of the synchronization (`Storage\WordPressSkillTermGateway`) has its own smoke test, run the same way as the metas one:

```bash
podman-compose --profile tools run --rm wpcli wp eval-file \
  wp-content/plugins/portfolio-md/tests/Integration/wp-cli/skills-smoke.php /srv/portfolio-content/skills.yaml
```

Import Markdown documents (articles and projects) as posts. Paths are files or directories; `--user` is required so that posts get an author:

```bash
# See what would happen, write nothing
podman-compose --profile tools run --rm wpcli wp portfolio import /srv/portfolio-content/projects --dry-run --user=<your admin login>

# Import (idempotent: a second run reports every document as UNCHANGED)
podman-compose --profile tools run --rm wpcli wp portfolio import /srv/portfolio-content/projects --user=<your admin login>

# One file, created as a draft
podman-compose --profile tools run --rm wpcli wp portfolio import /srv/portfolio-content/projects/momocad.md --draft --user=<your admin login>
```

Each document is parsed, rendered to Gutenberg blocks and stored with its verbatim Markdown and SHA-256 (`_portfolio_md_*` metas). The origin recorded is `git` by default (`--source=paste|admin` otherwise), which drives the conflict rule of ADR-005: a document last edited from the admin is reported as `CONFLICT` and left alone unless `--force` is given. Unknown competency codes fail the document (synchronize the grid first); pages are refused for now. Three guards protect existing posts: a file must be named after its slug (a sheet copied from another one still carries the slug of its model), a slug whose post is in the trash is refused until the post is restored or deleted permanently, and a future `date` is refused for a published post (WordPress would schedule it). The command exits with an error after the full report if any document conflicted or failed.

The import chain has its own smoke test, which creates one article and one project with synthetic content, checks posts, dates, terms, metas, idempotence, the conflict rule and the guards above, then deletes everything it created (`--user` required):

```bash
podman-compose --profile tools run --rm wpcli wp eval-file \
  wp-content/plugins/portfolio-md/tests/Integration/wp-cli/import-smoke.php --user=<your admin login>
```

## Admin screens

Articles and projects open in the **classic edit screen** with a « Document Markdown » box holding the whole document, frontmatter included. Saving the post hands that text to the import service with the post as target and `admin` as source: the document rewrites the title, the slug, the date, the content, the terms and the metas, and the source-of-truth rule of ADR-005 then protects the edit from `wp portfolio import` (which reports a `CONFLICT` until `--force`). Everything the document owns is removed from the screen on purpose: the block editor, the title field, the content editor, the permalink editor, the slug and taxonomy boxes, Quick Edit and Bulk Edit. The publish box, the author, the featured image, the revisions and the custom fields stay.

Four refusals protect the post being edited: the post must exist with the document's type, it must not be in the trash, the document's slug must be free or already the post's own, and a post that holds a document keeps the slug written in that document (rename the file in the repository instead; a URL slug changed through the REST API does not count, the document wins at its next write). A refusal that names another post is detailed only to a user who may edit that post. A refused document leaves the post as it was; the reason and the text are shown in the box at the next visit; a post that never held a document goes back to draft, so that nothing empty is published. The text of the form is normalised (CRLF → LF, final newline) before hashing, so a document saved without changes keeps the hash of its Git file.

« Portfolio → Importer » (`edit_others_posts`) takes a pasted document through the same service with `paste` as source: « Vérifier sans écrire » is the `--dry-run` of the command, « Importer » writes, and a checkbox creates the post as a draft.

The admin doors have their own smoke test, which plays the form submissions through the real hooks and cleans up after itself (`--user` required):

```bash
podman-compose --profile tools run --rm wpcli wp eval-file \
  wp-content/plugins/portfolio-md/tests/Integration/wp-cli/admin-smoke.php --user=<your admin login>
```

## Tests

Two complementary suites.

**Unit suite (PHPUnit 11, no WordPress).** The registrars call WordPress functions, so `tests/bootstrap.php` loads small stand-ins (`tests/Support/wp-stubs.php`) that record registrations in `WordPressSpy` or mimic WordPress closely enough for the covered cases. The suite checks that every meta is registered with the right type, REST visibility and callbacks, and that every sanitizer accepts and rejects what it should. The Markdown pipeline (parser, Gutenberg transformer, import service, conflict rule) is covered there too without any stub, including a full render of every sheet in `content/`.

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
- Backslashes vanish from a meta written in PHP (`\*` becomes `*`) → `update_post_meta()` unslashes its input; write through `MetaWriter::update()`, which applies `wp_slash()` first
- Backslashes vanish from a post written in PHP → `wp_insert_post()` and `wp_update_post()` unslash their input the same way; pass the array through `wp_slash()` (see `Storage\WordPressContentRepository`)
- A code block is flagged as invalid content in the editor → `core/code` validates a bare `<code>`; classes go on the `<pre>` through the `className` attribute, as `GutenbergTransformer` does
- Task-list checkboxes disappear after saving → `wp_kses_post()` does not allow `<input>`; the repository adds it to the allowed tags explicitly
- `FAILED … is in the future` → WordPress turns a published post dated ahead into a scheduled one, invisible on the site; fix the date or import the document with `--draft`
- `FAILED … is in the trash` → a trashed post still owns its slug (WordPress renames it `<slug>__trashed` and keeps the wanted slug in `_wp_desired_post_slug`); restore it or delete it permanently, the import never creates a second post next to it
- `FAILED … does not match the file name` → files are named after their slug; a sheet copied from another one still carries the slug of its model
- A fenced block in a huge pasted log is not coloured → blocks above 32 KiB are rendered plain on purpose (`HighlightedCodeRenderer::MAX_HIGHLIGHT_BYTES`): highlighting time grows about quadratically with the size of the block
- Gutenberg does not open for an article or a project, and the slug box, the taxonomy boxes and Quick Edit are missing → by design (`Admin\EditScreen`): the Markdown document is the only editor of what they would change; edit the frontmatter instead
- A post you just created went back to draft → its document was refused (the box says why, with the text you typed); fix it and save again
- The box shows a text that is not the stored document → it is the text refused at the last save; reload the page to get the stored document back
- `wp portfolio import` reports a `CONFLICT` right after a save from the admin → expected when the text changed (the admin edit is protected; `--force` or copy the box into the file); unexpected for an unchanged text, since the box normalises line endings: check that the file is LF-terminated and ends with a newline
- « Portfolio → Importer » is not in the menu → it needs `edit_others_posts` (editors and administrators)

## Architecture

For complete architectural details (layer boundaries, naming conventions, dependency rules), see [`../docs/architecture/01-plugin-php.md`](../docs/architecture/01-plugin-php.md). For the content model (post types, taxonomies, metas and their sanitization rules), see [`../docs/architecture/04-structure-wordpress.md`](../docs/architecture/04-structure-wordpress.md).
