# Portfolio MD — child theme of GeneratePress

The WordPress theme of the portfolio, a child of [GeneratePress](https://generatepress.com/) (3.6.1, installed by `bootstrap.sh`). It renders what the `portfolio-md` plugin stores and what Polylang routes: the language switcher, the project list with fallback and the FR badge of ADR-022 (lot 5 of the trilingual site), and, since phase 10 of the roadmap, the page of a project (its facts, its tags, its skills), the status on the cards and the sober design of the site.

## Local setup

The folder is bind-mounted into the containers like the plugin (`docker-compose.yml`, services `wordpress` and `wpcli`), so a change here is visible at once. After adding or changing the mount:

```bash
podman-compose up -d
podman-compose --profile tools run --rm wpcli wp theme list --fields=name,status,version
podman-compose --profile tools run --rm wpcli wp theme activate portfolio-md-child
```

`bootstrap.sh` activates it after installing GeneratePress. The theme has no build step and no dependency.

If the container was started before the folder existed, or the folder was recreated (a branch switch that removed and restored it), the bind mount points at a stale inode and WordPress sees an empty theme: `podman restart <wordpress container>` rebinds it.

## What is in the theme

| File | Role |
|---|---|
| `style.css` | The header (`Template: generatepress`) and the design of the theme (phase 10): a handful of tokens over GeneratePress's global colours (`--contrast-2`, `--base`, `--base-2`, `--base-3`, `--accent`, with their defaults as fallbacks), the switcher, the badge, the grid of cards, the status pill, the facts, tags and skills of a project page, the recent projects panel of the home. GeneratePress enqueues it itself. |
| `functions.php` | The hooks only: the language switcher on `generate_menu_bar_items`, the recent projects block of the home page on `generate_after_content` (three cards of `portfolio_md_list_with_fallback('project', <language>, 3)` and a link to the archive, under the Markdown body of the home page of each language), the page size of the project archive's main query aligned on the list (`pre_get_posts`, so that `/projets/page/2/` is a 404 instead of the same cards again), the header and the footer of the page of a project on `generate_after_entry_title` and `generate_after_entry_content` (phase 10, from `portfolio_md_document(<post id>)` of the plugin, read once per request), and `portfolio_md_child_current_language()` (Polylang's language of the request, French without it). |
| `inc/strings.php` | The few texts the theme says itself, in French, German and English: title of the project archive, label of the switcher, sentence behind the badge, names of the languages, heading and link of the recent projects block, labels of the facts of a project (status, period, role, client, repository, demo), fil rouge mention, headings of the tags and skills, asterisk and note of a provisional wording, names of the months. French is the fallback. |
| `inc/render.php` | The rendering functions, pure (arrays, `ListItem`s and a `PublishedDocument` in, escaped HTML out): the switcher entries reduced to the open languages, the switcher markup, the cards of a list with their status, the badge, the recent projects block, and for the page of a project the status pill, the period, the header and the footer. Tested by the unit suite of the plugin (`plugin/tests/Unit/Theme/`). |
| `archive-project.php` | The archive of the projects, `/<lang>/projets/`: the skeleton of GeneratePress's `archive.php`, the title of the theme in the language of the page, and the cards of `portfolio_md_list_with_fallback('project', <language>)` in the grid of the theme instead of the main loop, which Polylang filters by language. No pagination: the whole portfolio fits. |

**The switcher** (ADR-022, point 5): GeneratePress renders the menu bar twice (main and mobile navigation), so the switcher carries no `id`. It reads the raw elements of `pll_the_languages()` with every `hide_*` off (the plugin, not Polylang, says which languages are shown), keeps the languages of `portfolio_md_published_languages()`, renders the current one as an anchor without `href` (not a link, but styled by GeneratePress like its neighbours), and shows nothing while a single language is open. A language without translation of the page links to its home, as Polylang gives it.

**The badge** (point 8): a card served in another language than the page carries that language on the article and on its link, and after its title the code of that language, with a hidden sentence in the language of the page ("Diese Seite ist nur auf Französisch verfügbar.").

**The page of a project** (phase 10): no `single-project.php`, the hooks of GeneratePress's `content-single.php` are enough. Under the title, in the entry header: the subtitle as a deck, the fil rouge mention when the document says so, then the facts of the frontmatter as a definition list in a named group (`role="group"` on the wrapper, never an `aria-label` on the `<dl>` itself, which no screen reader would announce) in a fixed order (status, period, role, client, repository, demo), each only when the document has it; the labels are the theme's, in the language of the document (which is the language of its page), the values are the document's, the status label comes from the plugin's table (ADR-020 lot 4) and the period is written by the theme from its own month names ("depuis septembre 2026", "janvier 2026 – mai 2026"). After the body: the technologies as pills **without link** (the archives of `tech_tag` are noindex, ADR-022 point 9: nothing sends a visitor or a robot there; each pill named in the language of the page from `content/tags.yaml` through the plugin, ADR-024, and marked `lang` when the name had to fall back on another language), then the skills the project demonstrates, each with its code and its label as the plugin resolves it for the language of the page, marked `lang` when the wording had to fall back on another language, with an asterisk and one note under the list when the referential has no official wording yet. All of it comes from `portfolio_md_document(<post id>)`, the same object the REST route of the document serves, so a hand-made page or a draft gets nothing. The status pill never uses the bare `status-<slug>` class, which WordPress already puts on every post (`status-publish`).

**The design** (phase 10) leans on GeneratePress: its global colours through their CSS variables, its typography and its containers. The theme adds a few tokens (`--pm-*` in `style.css`; the line colour is a lightened `--contrast-3`, because `--base` is a background token too close to the surfaces for a border), the layout (no sidebar anywhere, through the `generate_sidebar_layout` filter: the site has nothing to put in one and WordPress's default widgets would show an empty "Recent Posts" beside every page; the content held to 64rem and the body text of a page or a project to a 46em measure while the grids use the whole width), the grid of cards (`.portfolio-cards`, columns of at least 17rem that shrink to the width of a narrow phone; the titles are `h2` under the title of the archive and `h3` under the `h2` of the home block), the status colours (the only colours of its own: blue for in progress, green for stable, grey for archived, amber for prototype) and the rules of what it renders. Nothing is enqueued, nothing is scripted.

## Checks

```bash
cd plugin && podman run --rm -v "$PWD/..:/repo:z" -w /repo/plugin docker.io/library/composer:2 vendor/bin/phpunit --filter Theme
podman-compose --profile tools run --rm wpcli wp eval-file \
  wp-content/plugins/portfolio-md/tests/Integration/wp-cli/list-smoke.php --user=<your admin login>   # lists, archive rule, portfolio_md_document(), cards and project header
plugin/tools/opening-check.sh http://localhost:8000   # cards, badges and switcher of each page, German closed, open, closed
curl -s http://localhost:8000/fr/projets/momocad/ | grep -c 'project-facts'   # 1: the page of a project wears its facts
```

## Rules

- The theme never decides the language of a document and never writes anything: it reads what the plugin serves (`portfolio_md_list_with_fallback()`, `portfolio_md_published_languages()`, `portfolio_md_document()`) and what Polylang routes.
- Every call to the plugin or to Polylang is guarded by `function_exists()`: the theme renders without them.
- The one-time configuration of the site (static front page, menus, Yoast options, the publication option) is not in the theme: it is `plugin/tools/site-setup.php`, replayable.
