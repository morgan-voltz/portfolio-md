# Portfolio MD — child theme of GeneratePress

The WordPress theme of the portfolio, a child of [GeneratePress](https://generatepress.com/) (3.6.1, installed by `bootstrap.sh`). It renders what the `portfolio-md` plugin stores and what Polylang routes: the language switcher, the project list with fallback and the FR badge of ADR-022 (lot 5 of the trilingual site). The real design (home panels, single templates, final CSS) is phase 10 of the roadmap.

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
| `style.css` | The header (`Template: generatepress`) and a few sober rules: the switcher, the badge. GeneratePress enqueues it itself. |
| `functions.php` | The hooks only: the language switcher on `generate_menu_bar_items`, and `portfolio_md_child_current_language()` (Polylang's language of the request, French without it). |
| `inc/strings.php` | The few texts the theme says itself, in French, German and English: title of the project archive, label of the switcher, sentence behind the badge, names of the languages. French is the fallback. |
| `inc/render.php` | The rendering functions, pure (arrays and `ListItem`s in, escaped HTML out): the switcher entries reduced to the open languages, the switcher markup, the cards of a list, the badge. Tested by the unit suite of the plugin (`plugin/tests/Unit/Theme/`). |
| `archive-project.php` | The archive of the projects, `/<lang>/projets/`: the skeleton of GeneratePress's `archive.php`, the title of the theme in the language of the page, and the cards of `portfolio_md_list_with_fallback('project', <language>)` instead of the main loop, which Polylang filters by language. No pagination: the whole portfolio fits. |

**The switcher** (ADR-022, point 5): GeneratePress renders the menu bar twice (main and mobile navigation), so the switcher carries no `id`. It reads the raw elements of `pll_the_languages()` with every `hide_*` off (the plugin, not Polylang, says which languages are shown), keeps the languages of `portfolio_md_published_languages()`, shows the current one as text, and shows nothing while a single language is open. A language without translation of the page links to its home, as Polylang gives it.

**The badge** (point 8): a card served in another language than the page carries that language on the article and on its link, and after its title the code of that language, with a hidden sentence in the language of the page ("Diese Seite ist nur auf Französisch verfügbar.").

## Checks

```bash
cd plugin && podman run --rm -v "$PWD/..:/repo:z" -w /repo/plugin docker.io/library/composer:2 vendor/bin/phpunit --filter Theme
podman-compose --profile tools run --rm wpcli wp eval-file \
  wp-content/plugins/portfolio-md/tests/Integration/wp-cli/list-smoke.php --user=<your admin login>
plugin/tools/opening-check.sh http://localhost:8000   # cards, badges and switcher of each page, German closed, open, closed
```

## Rules

- The theme never decides the language of a document and never writes anything: it reads what the plugin serves (`portfolio_md_list_with_fallback()`, `portfolio_md_published_languages()`) and what Polylang routes.
- Every call to the plugin or to Polylang is guarded by `function_exists()`: the theme renders without them.
- The one-time configuration of the site (static front page, menus, Yoast options, the publication option) is not in the theme: it is `plugin/tools/site-setup.php`, replayable.
