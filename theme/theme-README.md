# Portfolio MD — child theme of GeneratePress

The WordPress theme of the portfolio, a child of [GeneratePress](https://generatepress.com/) (3.6.1, installed by `bootstrap.sh`). It renders what the `portfolio-md` plugin stores and what Polylang routes: the language switcher, the project list with fallback and the FR badge of ADR-022 (lot 5 of the trilingual site). The real design (home panels, single templates, final CSS) is phase 10 of the roadmap.

## Local setup

The folder is bind-mounted into the containers like the plugin (`docker-compose.yml`, services `wordpress` and `wpcli`), so a change here is visible at once. After adding or changing the mount:

```bash
podman-compose up -d
podman-compose --profile tools run --rm wpcli wp theme list --fields=name,status,version
podman-compose --profile tools run --rm wpcli wp theme activate portfolio-md-child
```

`bootstrap.sh` activates it after installing GeneratePress. The theme has no build step and no dependency: `style.css` (header and rules; GeneratePress enqueues it itself) and `functions.php` (hooks), plus the templates of the WordPress hierarchy as they come (`archive-project.php` with lot 5c).

## Rules

- The theme never decides the language of a document and never writes anything: it reads what the plugin serves (`portfolio_md_list_with_fallback()` from lot 5c) and what Polylang routes.
- Every call to the plugin or to Polylang is guarded by `function_exists()`: the theme renders without them.
- The one-time configuration of the site (static front page, menus, Yoast options, the publication option) is not in the theme: it is `plugin/tools/site-setup.php`, replayable.
