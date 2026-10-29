# plugin/ — WordPress plugin `portfolio-md`

This folder hosts the WordPress plugin that accepts Markdown content, stores both the raw Markdown and a derived Gutenberg HTML representation, and exposes a custom REST API for downstream consumers.

The plugin is written in **PHP 8.2+** with modern conventions: PSR-4 autoloading via Composer, dependency injection, a layered architecture inspired by Clean Architecture principles.

## Current status

The **content model is complete** (roadmap phase 1). The plugin registers:

- Custom post types `article` and `project` (`PostTypes\PostTypeRegistrar`)
- Taxonomies `tech_tag` (flat, both types), `project_status` (flat, projects, closed list) and `skill` (hierarchical by referential, projects, official code in the `_portfolio_skill_code` term meta) via `Taxonomies\TaxonomyRegistrar`
- Seventeen post metas, all prefixed `_portfolio_`: four public metas shared by both types (`Meta\CommonMetaRegistrar`), eight public project metas including the `is_fil_rouge` flag (`Meta\ProjectMetaRegistrar`), five internal metas hidden from REST and locked for users (`Meta\InternalMetaRegistrar`). Sanitization rules live in `Meta\Sanitizer`; metas written from PHP go through `Meta\MetaWriter::update()`, which slashes the value because `update_post_meta()` unslashes it before storing.

Phases 2 and 3 are done (26 October 2026): `Parsing\FrontmatterParser` turns a Markdown document into a typed `Domain\Frontmatter` plus its body (strict validation, explicit exceptions); `Parsing\MarkdownParser` renders GFM to HTML with server-side code highlighting (`HighlightedCodeRenderer`, highlight.php); `Parsing\GutenbergTransformer` wraps that HTML in core blocks exactly as the editor serialises them; `Service\ContentImportService` applies the source-of-truth rule of ADR-005 and `Storage\WordPressContentRepository` writes posts, terms and metas. Two WP-CLI commands drive it: `wp portfolio skills sync` (taxonomy from `content/skills.yaml`) and `wp portfolio import` (articles and projects from Markdown files), see below.

The two admin doors are in (26 October 2026, server side): `Admin\EditorMetaBox` puts the whole document in a « Document Markdown » box on the classic edit screen of articles and projects (`Admin\EditScreen` turns the block editor off there and removes the native controls the document owns), and `Admin\ImportPage` adds « Portfolio → Importer », a paste page. Both call the same `ContentImportService` as the WP-CLI command; see "Admin screens" below. Since 27 October 2026 the box is a CodeMirror 6 editor with a preview rendered by the server (`POST /wp-json/portfolio/v1/preview`, the first route of the plugin's REST namespace, ADR-016); the browser side lives in `assets/` and is built with esbuild.

Static pages (`type: page`, 27 October 2026) take the same path into the native page type: no term, the SEO description and the internal metas only; a page that holds a document is edited through the Markdown box, a page made by hand keeps the block editor (ADR-017). Links between documents (`../projects/<slug>.md`) are rewritten into links of the site at render time (`Parsing\RelativeLinks`, render version 1.1.0).

The public read API is in (29 October 2026, ADR-018): `GET /wp-json/portfolio/v1/{articles,projects,pages}`, their `/{slug}` routes and `/sitemap` serve the published documents, Markdown source included, see "REST API" below. So is the Git door (29 October 2026, ADR-019): `POST /wp-json/portfolio/v1/sync` takes the files a push changed, authenticated with a WordPress application password, and a GitHub Actions workflow feeds it, see "Git door" below.

What is **not yet** implemented: image import into the media library. See [`../docs/architecture/01-plugin-php.md`](../docs/architecture/01-plugin-php.md) for the target architecture and [`../docs/architecture/04-structure-wordpress.md`](../docs/architecture/04-structure-wordpress.md) for the content model.

## Local setup

The plugin is consumed by the WordPress container via the bind mount declared in the root `docker-compose.yml`. Any change you make in this folder is immediately visible in the running WordPress install — no rebuild needed.

```bash
# 1. Install PHP dependencies (host with PHP + Composer)
cd plugin
composer install

# 1 bis. Same thing without PHP on the host: run Composer in a container
podman run --rm -v "$PWD:/app:z" docker.io/library/composer:2 install

# 1 ter. Build the admin editor (Node.js 20+): CodeMirror 6 + preview, served from assets/dist
cd assets && npm ci && npm run build && cd ..

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

The bundle (`assets/dist/`) is not committed: build it once after cloning and again after changing anything in `assets/src/` (`npm run watch` rebuilds on save). Without it the admin still works, with a plain textarea and a notice saying how to build.

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

Import Markdown documents (articles, projects and pages) as posts. Paths are files or directories; `--user` is required so that posts get an author:

```bash
# See what would happen, write nothing
podman-compose --profile tools run --rm wpcli wp portfolio import /srv/portfolio-content/projects --dry-run --user=<your admin login>

# Import (idempotent: a second run reports every document as UNCHANGED)
podman-compose --profile tools run --rm wpcli wp portfolio import /srv/portfolio-content/projects --user=<your admin login>

# One file, created as a draft
podman-compose --profile tools run --rm wpcli wp portfolio import /srv/portfolio-content/projects/momocad.md --draft --user=<your admin login>

# The static pages, into the native page type
podman-compose --profile tools run --rm wpcli wp portfolio import /srv/portfolio-content/pages --user=<your admin login>
```

Each document is parsed, rendered to Gutenberg blocks and stored with its verbatim Markdown and SHA-256 (`_portfolio_md_*` metas). The origin recorded is `git` by default (`--source=paste|admin` otherwise), which drives the conflict rule of ADR-005: a document last edited from the admin is reported as `CONFLICT` and left alone unless `--force` is given. Unknown competency codes fail the document (synchronize the grid first). Three guards protect existing posts: a file must be named after its slug (a sheet copied from another one still carries the slug of its model), a slug whose post is in the trash is refused until the post is restored or deleted permanently, and a future `date` is refused for a published post (WordPress would schedule it). The command exits with an error after the full report if any document conflicted or failed.

The import chain has its own smoke test, which creates one article and one project with synthetic content, checks posts, dates, terms, metas, idempotence, the conflict rule and the guards above, then deletes everything it created (`--user` required):

```bash
podman-compose --profile tools run --rm wpcli wp eval-file \
  wp-content/plugins/portfolio-md/tests/Integration/wp-cli/import-smoke.php --user=<your admin login>
```

## Admin screens

Articles and projects open in the **classic edit screen** with a « Document Markdown » box holding the whole document, frontmatter included. Saving the post hands that text to the import service with the post as target and `admin` as source: the document rewrites the title, the slug, the date, the content, the terms and the metas, and the source-of-truth rule of ADR-005 then protects the edit from `wp portfolio import` (which reports a `CONFLICT` until `--force`). Everything the document owns is removed from the screen on purpose: the block editor, the title field, the content editor, the permalink editor, the slug and taxonomy boxes, Quick Edit and Bulk Edit. The publish box, the author, the featured image, the revisions and the custom fields stay.

Four refusals protect the post being edited: the post must exist with the document's type, it must not be in the trash, the document's slug must be free or already the post's own, and a post that holds a document keeps the slug written in that document (rename the file in the repository instead; a URL slug changed through the REST API does not count, the document wins at its next write). A refusal that names another post is detailed only to a user who may edit that post. A refused document leaves the post as it was; the reason and the text are shown in the box at the next visit; a post that never held a document goes back to draft, so that nothing empty is published. The text of the form is normalised (CRLF → LF, final newline) before hashing, so a document saved without changes keeps the hash of its Git file.

« Portfolio → Importer » (`edit_others_posts`) takes a pasted document through the same service with `paste` as source: « Vérifier sans écrire » is the `--dry-run` of the command, « Importer » writes, and a checkbox creates the post as a draft.

A **page** gets the same treatment only when it holds a document (`Admin\MarkdownPosts` reads the internal hash): the block editor is turned off for that page, its screen loses the title field, the content editor, the permalink editor, the slug box and the attributes box (a documented page lives at the root of the site, where the rewritten links point; the repository keeps its parent at zero), and Quick Edit leaves its row. Pasting a page takes `edit_others_pages` on top of `edit_others_posts`. A page made by hand keeps the block editor, and so does « Add New Page »: a new page in Markdown comes through the paste page or `wp portfolio import`.

On both screens the textarea is dressed by the **editor bundle** (`Admin\EditorAssets`, `assets/dist/admin-editor.js`): a CodeMirror 6 editor (YAML frontmatter and GFM highlighting, line wrapping, history, search with Ctrl+F, Tab indents and Escape-then-Tab leaves) next to a **preview rendered by the server**. The textarea stays the form field: every change is copied into it, so the save path above is unchanged. After 600 ms without typing, the whole document goes to `POST /wp-json/portfolio/v1/preview` through `wp.apiFetch` (which carries the REST nonce and renews it); the server parses and renders it with the import's own parser and renderer, applies the kses pass of the store (`Storage\HtmlSanitizer`), then renders the blocks and the typography as the front end does (`do_blocks`, `wptexturize`, `convert_smilies`) but not the shortcodes nor the embeds, which would run code and fetch remote URLs for a text that is only being typed, and answers with the title, a summary of the frontmatter, the HTML, and the problems the import would raise (unreadable document, unknown competency code). The HTML is shown in a sandboxed `<iframe>` that loads only the core block stylesheets: what you see is what the site prints, minus the theme's CSS and the shortcodes (shown as typed), nothing in the frame can run, and its links open in a new tab without opener nor referrer. Problems are listed above the frame while the last rendering stays, dimmed; what the preview cannot know (a slug taken by another post, a conflict with Git) only shows at the save. A button hides the preview and the browser remembers it. The route takes `edit_posts`, writes nothing, and caps the document at a million characters.

The admin doors have their own smoke test, which plays the form submissions through the real hooks and cleans up after itself (`--user` required):

```bash
podman-compose --profile tools run --rm wpcli wp eval-file \
  wp-content/plugins/portfolio-md/tests/Integration/wp-cli/admin-smoke.php --user=<your admin login>
```

The preview route and the editor assets have theirs, which calls the route through the REST server (route, permissions, parameters, front-end rendering, kses pass) and checks what loads on each screen; it needs the bundle built, creates a subscriber to check the refusal and deletes it:

```bash
podman-compose --profile tools run --rm wpcli wp eval-file \
  wp-content/plugins/portfolio-md/tests/Integration/wp-cli/preview-smoke.php --user=<your admin login>
```

## REST API

Since 29 October 2026 the plugin serves its published documents over a public, read-only JSON API under `/wp-json/portfolio/v1/` (ADR-018; the contract is in [`../docs/architecture/01-plugin-php.md`](../docs/architecture/01-plugin-php.md) §7). No authentication: the routes only serve what the site shows to anyone, that is published posts without password that hold a Markdown document. Drafts, scheduled, private, protected and trashed posts, and posts made by hand without document, are neither listed nor served (404).

| Route | What it answers |
|---|---|
| `GET /articles`, `GET /projects`, `GET /pages` | one page of the collection, newest first, as a bare JSON array; totals in the `X-WP-Total` and `X-WP-TotalPages` headers, neighbouring pages in `Link` |
| `GET /articles/{slug}`, `GET /projects/{slug}`, `GET /pages/{slug}` | one document, 404 `portfolio_not_found` otherwise |
| `GET /sitemap` | every published document by collection, `{articles: [{slug, updated}], projects: [...], pages: [...]}`, for cache invalidation |

Parameters of the lists: `page` (from 1) and `per_page` (10 by default, 100 at most); `tag=<slug>` on articles and projects; `status=<slug>` (`en-cours`, `stable`, `archive`, `prototype`) and `skill=<slug>` (a competency term; the slug of a referential selects all its competencies) on projects. The REST server validates them against the declared schema (400 `rest_invalid_param`); a page beyond the last of a non-empty collection answers 400 `rest_post_invalid_page_number` like the native collections (an empty collection answers `[]` whatever the page); the native `_fields` parameter works (`?_fields=slug,title`).

A document carries the keys of its frontmatter in `snake_case`, in a fixed order, the Markdown source last (frontmatter included; the Gutenberg HTML is never returned): `id`, `type`, `slug`, `title`, `link` (permalink), `date` and `updated` (ISO 8601 UTC, `2026-10-25T23:00:00Z`), then for articles and projects `tags` (`[{slug, name}]`), `subtitle`, `seo_description`, `reading_time`, `featured`, then for projects `status` (`{slug, label}`), `skills` (`[{slug, code, label}]`), `is_fil_rouge`, `role`, `period_start`, `period_end`, `repo_url`, `demo_url`, `client`, and `markdown`. An empty optional text and a zero reading time are `null`.

```bash
curl -s 'http://localhost:8000/wp-json/portfolio/v1/projects?per_page=2&status=en-cours' -D -
curl -s 'http://localhost:8000/wp-json/portfolio/v1/projects/momocad?_fields=title,skills'
curl -s 'http://localhost:8000/wp-json/portfolio/v1/sitemap'
```

The read side is `Service\ContentCatalog` (port) + `Storage\WordPressContentCatalog` (WP_Query, published documents only, term and meta caches primed per page) + `Rest\DocumentPresenter` (pure shaping) + `Rest\Controller\CollectionController` (one instance per collection) and `SitemapController`. It is distinct from the import's `ContentRepository`, which looks posts up whatever their status to apply the conflict rule and must never serve a public route.

The routes have their smoke test, which calls them through the REST server as an anonymous visitor over documents it imports and deletes (a published article, project and page, a draft, a protected article, two hand-made posts); it needs the skills grid synchronized:

```bash
podman-compose --profile tools run --rm wpcli wp eval-file \
  wp-content/plugins/portfolio-md/tests/Integration/wp-cli/rest-smoke.php --user=<your admin login>
```

## Git door

Since 29 October 2026 a push to the content repository can be brought into WordPress through `POST /wp-json/portfolio/v1/sync` (ADR-019; the contract is in [`../docs/architecture/01-plugin-php.md`](../docs/architecture/01-plugin-php.md) §6.2). The body carries the files the push changed, with their path relative to `content/` (`projects/<slug>.md`, `pages/<slug>.md`, `articles/<slug>.md`, `skills.yaml`), their text or a `deleted` flag, an optional `dry_run` and an optional `commit_sha` for the logs; one to a hundred files per request.

**Authentication.** The caller is a WordPress user with an **application password** (your profile → *Application Passwords* → add one), sent over Basic auth; WordPress checks it before the route runs, and the request then acts as that user, who authors the posts the push creates. The route requires `edit_others_posts`, `edit_others_pages`, `manage_categories` (the grid writes taxonomy terms) and `unfiltered_html` (an administrator has them). WordPress only accepts application passwords over HTTPS or in a `local` environment: `docker-compose.yml` declares `WP_ENVIRONMENT_TYPE=local` for the dev stack.

**What it does.** Every file is handled on its own: `skills.yaml` first (so a sheet may cite a code the same push introduces), then each Markdown document through the same import service as the WP-CLI command, as a Git write and never forced (ADR-005). The folder must match the document's type. A deleted document moves its post to the trash **only when Git wrote it last**; a post edited in the admin, pasted, or made by hand answers `conflict` and stays. Anything that is not a document (the README, images) is `skipped`. The answer has one line per file (`created`, `updated`, `unchanged`, `conflict`, `failed`, `deleted`, `skipped`, with type, slug, post id and message) and a summary; the HTTP status is **200** when everything went through, **409** when a file waits for a decision in the admin, **422** when a file could not be processed. There is no `force` on this route: resolve a conflict in the admin, with « Portfolio → Importer » (a paste always wins) or with `wp portfolio import --force`. Every synchronization that is not a dry run is recorded in the option `portfolio_md_last_sync`, a replay that changed nothing included: `wp option get portfolio_md_last_sync` says when the site last heard from the repository, not whether that call wrote.

**From GitHub.** `.github/workflows/sync-content.yml` runs on every push to `main` touching `content/`, lists the changed documents (`git diff --no-renames --name-status -z`: NUL-separated, so an accented file name arrives as it is; full history fetched, empty-tree fallback for a first push) and hands them to `.github/scripts/sync-content.py`, which posts them in batches of at most fifty files and four megabytes (`--batch-size`, `--batch-bytes`; PHP's default `post_max_size` is 8M), the skills grid in the first one, and fails the job on anything but 200, with a table in the job summary. `actions/checkout` is pinned to a commit, since the job holds a credential. It needs three repository secrets and does nothing until they exist: `PORTFOLIO_SYNC_URL` (the site), `PORTFOLIO_SYNC_USER` (the login) and `PORTFOLIO_SYNC_PASSWORD` (an application password of that user). *Run workflow* by hand sends every document, optionally as a dry run.

**From this machine.** The same script talks to the local stack; create an application password (profile, or WP-CLI below), export it, and run it from the repository root:

```bash
podman-compose --profile tools run --rm wpcli wp user application-password create <login> local-sync --porcelain
export PORTFOLIO_SYNC_PASSWORD='<the password printed above>'
python3 .github/scripts/sync-content.py --url http://localhost:8000 --user <login> \
  --password-env PORTFOLIO_SYNC_PASSWORD --all --dry-run
# the files a diff changed, as the workflow does:
git diff --no-renames --name-status -z HEAD~1 HEAD -- content/ | python3 .github/scripts/sync-content.py \
  --url http://localhost:8000 --user <login> --password-env PORTFOLIO_SYNC_PASSWORD --name-status -
```

The route has its smoke test, which plays batches through the REST server as the admin (creation, replay, update, the real grid, conflicts, refusals, dry run, deletions, rights) and cleans up after itself, the last-sync option included:

```bash
podman-compose --profile tools run --rm wpcli wp eval-file \
  wp-content/plugins/portfolio-md/tests/Integration/wp-cli/sync-smoke.php --user=<your admin login>
```

## Tests

Three complementary suites for the plugin, plus the tests of the CI script.

**Unit suite (PHPUnit 11, no WordPress).** The registrars call WordPress functions, so `tests/bootstrap.php` loads small stand-ins (`tests/Support/wp-stubs.php`) that record registrations in `WordPressSpy` or mimic WordPress closely enough for the covered cases. The suite checks that every meta is registered with the right type, REST visibility and callbacks, and that every sanitizer accepts and rejects what it should. The Markdown pipeline (parser, Gutenberg transformer, import service, conflict rule) is covered there too without any stub, including a full render of every sheet in `content/`.

```bash
# Host with PHP
cd plugin
composer test            # or: composer test:unit

# Without PHP on the host
podman run --rm -v "$PWD:/app:z" -w /app docker.io/library/php:8.3-cli vendor/bin/phpunit
```

**Browser suite (Node, no browser).** `assets/tests/` covers the JavaScript with Node's own test runner: the preview loop (debounce, stale answers, failures) and the document of the preview frame are pure; the mounting on the textarea runs in a jsdom document with a fake editor and fake requests. CodeMirror itself is not tested. Run it from `plugin/assets` after `npm ci`:

```bash
npm test
```

**Smoke test in the real WordPress (WP-CLI).** `tests/Integration/wp-cli/meta-smoke.php` runs inside the `wpcli` container against the live install: effective registrations, sanitization through `update_post_meta()`, capability rules, REST representation. It creates one draft project and deletes it. Run it from the repo root with the stack up and the plugin activated:

```bash
podman-compose --profile tools run --rm wpcli wp eval-file \
  wp-content/plugins/portfolio-md/tests/Integration/wp-cli/meta-smoke.php \
  --user=<your admin login>
```

`--user` is required for the capability checks. The other smoke scripts (`skills`, `import`, `admin`, `preview`, `rest`, `sync`) are listed in their sections above. The official `wp-phpunit` framework will come if the smoke scripts stop being enough.

**CI script (Python, no network).** `.github/scripts/test_sync_content.py` covers the pure parts of `sync-content.py`: reading the `-z` diff, which paths are documents, the grid first, batches by count and bytes, the summaries. From the repository root:

```bash
python3 -I .github/scripts/test_sync_content.py
```

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
- The box is a plain textarea and a notice talks about `npm ci && npm run build` → the editor bundle is not built (`assets/dist/` is not committed); build it in `plugin/assets`
- The preview says « Aperçu indisponible : … » → the request failed: a nonce that expired is renewed by `wp.apiFetch` on its own, so reload the page if it persists, and check that the REST API answers (`/wp-json/portfolio/v1/preview` needs a logged-in user with `edit_posts`)
- The preview looks plainer than the site → by design: the frame loads the core block styles, not the theme's stylesheet; the HTML is the one the site prints
- A shortcode or an `[embed]` URL stays literal in the preview → by design: the preview runs no shortcode and fetches nothing; the site will run it once the post is published
- A page imported from Markdown opens in the classic screen with the Markdown box instead of the block editor → by design (`Admin\MarkdownPosts`): it holds a document; a page made by hand keeps the block editor. To make a documented page an ordinary one again, delete its `_portfolio_*` metas (`wp post meta delete`)
- Links of the CV page to the project sheets are broken on the site → import the page again: `ContentRenderer` 1.1.0 rewrites `../projects/<slug>.md` into `/projets/<slug>/`, and a post stored with an older render version is rendered again at its next import
- Images referenced by a relative path are broken in the preview → expected until the media import exists; the site shows the same thing
- The Tab key stays in the editor → it indents; press Escape, then Tab, to move the focus out
- The bundle weighs about 560 KB → CodeMirror and its Markdown, YAML and HTML grammars; it is served once and cached. Splitting it into ES modules loaded on demand is the lever if it ever matters
- `GET /wp-json/portfolio/v1/projects/MomoCad` answers 400 `rest_invalid_param` → by design: the REST server matches routes case-insensitively, so the capitals reach the `slug` parameter, whose schema wants a lowercase slug
- A published project is missing from `GET /projects` → it holds no document (made by hand, `_portfolio_md_hash` empty) or has a password; the API serves documents only. `GET /articles` is an empty list with `X-WP-Total: 0` as long as no article exists
- `GET /projects?skill=<code>` answers 400 → the filter takes the term slug, not the code (`4.0.2/E07` is not a slug); read the slug in the `skills` of a project
- `POST /sync` answers 401 with a valid application password → WordPress accepts application passwords over HTTPS or in a `local` environment only; the compose file declares `WP_ENVIRONMENT_TYPE=local`, recreate the container (`podman-compose up -d`) if it was started before that line existed. Check with `wp eval 'var_dump(wp_is_application_passwords_available());'`
- `POST /sync` answers 409 → a document was edited in the admin (or pasted) since Git wrote it: the line names the post; resolve it in the admin, paste the Git version, or run `wp portfolio import --force`; the route itself never forces
- A document deleted from the repository is still published after the push → its post was not Git's to retire (the line says `conflict` and who wrote last): trash it by hand
- `wp portfolio import` or `/sync` reports `… is in the "pages/" folder, which holds documents of type "page"` → the file sits in the folder of another kind: move it, or fix its `type` line
- `sync-content.py` warns `the diff is not NUL-separated` → it was given the line form of `git diff --name-status`, which quotes and escapes an accented path (`"content/projects/fiche accentu\303\251e.md"`); produce the diff with `-z`
- `sync-content.py` reports `HTTP 413` or a `400 rest_invalid_json` for a big batch → the body exceeded PHP's `post_max_size` (8M by default): lower `--batch-bytes`, or raise the limit on the host

## Architecture

For complete architectural details (layer boundaries, naming conventions, dependency rules), see [`../docs/architecture/01-plugin-php.md`](../docs/architecture/01-plugin-php.md). For the content model (post types, taxonomies, metas and their sanitization rules), see [`../docs/architecture/04-structure-wordpress.md`](../docs/architecture/04-structure-wordpress.md).
