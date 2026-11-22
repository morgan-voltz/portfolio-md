# Portfolio MD — child theme of GeneratePress

The WordPress theme of the portfolio, a child of [GeneratePress](https://generatepress.com/) (3.6.1, installed by `bootstrap.sh`). It renders what the `portfolio-md` plugin stores and what Polylang routes: the language switcher, the project list with fallback and the FR badge of ADR-022 (lot 5 of the trilingual site), since phase 10 of the roadmap the page of a project (its facts, its tags, its skills) and the status on the cards, and since ADR-027 the whole visual identity of the site: its fonts, its colours, its header, the top of its pages, its footer and its social image.

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
| `style.css` | The header (`Template: generatepress`) and the design of the site (phase 10, ADR-027): the `@font-face` rules of the two fonts and of their metric-adjusted stand-ins, the brand tokens (`--pm-*`) and GeneratePress's palette redefined from them, the typography, the links and the focus ring, the layout, the header, the top of a page and its buttons, the switcher, the badge, the grid of cards, the status pill, the facts, tags and skills of a project page, the recent projects panel of the home, the footer. GeneratePress enqueues it itself, after the CSS its Customizer generates. |
| `functions.php` | The hooks only: the language switcher on `generate_menu_bar_items`, the recent projects block of the home page on `generate_after_content`, the page size of the project archive's main query (`pre_get_posts`, so that `/projets/page/2/` is a 404 instead of the same cards again), the header and the footer of the page of a project on `generate_after_entry_title` and `generate_after_entry_content`, and since ADR-027: GeneratePress's Google Fonts stylesheet dequeued and the two Latin font files preloaded (`wp_preload_resources`), no emoji script, the navigation on the right of the logo (`generate_navigation_location`), the logo of the theme (`generate_logo`, `generate_logo_output`), no site title, tagline nor header widget area beside it, the top of a page on `generate_after_page_title` (and `generate_show_title` hiding GeneratePress's h1 when the document has a headline), the credit of the footer (`generate_copyright`), the default image of the social cards (`wpseo_add_opengraph_additional_images`), the title and the description of the project archive in Yoast. `portfolio_md_child_current_language()` reads Polylang's language of the request, French without it. |
| `inc/strings.php` | The few texts the theme says itself, in French, German and English: title of the project archive and its description for search results, label of the switcher, sentence behind the badge, names of the languages, heading and link of the recent projects block, labels of the facts of a project (status, period, role, client, repository, demo), fil rouge mention, headings of the tags and skills, asterisk and note of a provisional wording, names of the months, the two buttons of the home page, the label and the links of the footer; the profiles the footer links to (`portfolio_md_child_profiles()`). French is the fallback. |
| `inc/render.php` | The rendering functions, pure (arrays, `ListItem`s and a `PublishedDocument` in, escaped HTML out): the switcher entries reduced to the open languages, the switcher markup, the cards of a list with their status, the badge, the recent projects block, for the page of a project the status pill, the period, the header and the footer, and since ADR-027 the top of a page (`portfolio_md_child_render_page_intro()`), the logo, the credit of the footer, and `portfolio_md_child_esc_text()`, which sets a text of a document with `wptexturize()` before escaping it. Tested by the unit suite of the plugin (`plugin/tests/Unit/Theme/`). |
| `archive-project.php` | The archive of the projects, `/<lang>/projets/`: the skeleton of GeneratePress's `archive.php`, the title of the theme in the language of the page, and the cards of `portfolio_md_list_with_fallback('project', <language>)` in the grid of the theme instead of the main loop, which Polylang filters by language. No pagination: the whole portfolio fits. |
| `fonts/` | Space Grotesk (headings) and Inter (text and menu), variable WOFF2 files of the Latin and Latin Extended subsets, 174 KB in all, from the Fontsource packages `@fontsource-variable/space-grotesk` and `@fontsource-variable/inter` 5.3.0, each with its licence (`OFL.txt`, SIL Open Font License 1.1: the fonts are not under the MIT licence of the repository). |
| `assets/` | The logo of the header at 1x and 2x (`logo-240.png`, `logo-480.png`, from the brand's `logo-signal-fond-clair.png`, trimmed of its transparent margin) and the default image of the social cards (`og-image.png`, 1200 × 630). |

Beside the theme, `theme/og-image/` holds the source of `og-image.png` (an HTML page and the brand's icon) and, in a comment of `og-image.html`, the two commands that regenerate it. It is not deployed: `plugin/tools/deploy-o2switch.sh` ships `theme/portfolio-md-child/` only.

**The switcher** (ADR-022, point 5): GeneratePress renders the menu bar twice (main and mobile navigation), so the switcher carries no `id`. It reads the raw elements of `pll_the_languages()` with every `hide_*` off (the plugin, not Polylang, says which languages are shown), keeps the languages of `portfolio_md_published_languages()`, renders the current one as an anchor without `href` (not a link, but styled by GeneratePress like its neighbours), and shows nothing while a single language is open. A language without translation of the page links to its home, as Polylang gives it.

**The badge** (point 8): a card served in another language than the page carries that language on the article and on its link, and after its title the code of that language, with a hidden sentence in the language of the page ("Diese Seite ist nur auf Französisch verfügbar.").

**The page of a project** (phase 10): no `single-project.php`, the hooks of GeneratePress's `content-single.php` are enough. Under the title, in the entry header: the subtitle as a deck, the fil rouge mention when the document says so, then the facts of the frontmatter as a definition list in a named group (`role="group"` on the wrapper, never an `aria-label` on the `<dl>` itself, which no screen reader would announce) in a fixed order (status, period, role, client, repository, demo), each only when the document has it; the labels are the theme's, in the language of the document (which is the language of its page), the values are the document's, the status label comes from the plugin's table (ADR-020 lot 4) and the period is written by the theme from its own month names ("depuis septembre 2026", "janvier 2026 – mai 2026"). After the body: the technologies as pills **without link** (the archives of `tech_tag` are noindex, ADR-022 point 9: nothing sends a visitor or a robot there; each pill named in the language of the page from `content/tags.yaml` through the plugin, ADR-024, and marked `lang` when the name had to fall back on another language), then the skills the project demonstrates, each with its code and its label as the plugin resolves it for the language of the page, marked `lang` when the wording had to fall back on another language, with an asterisk and one note under the list when the referential has no official wording yet. All of it comes from `portfolio_md_document(<post id>)`, the same object the REST route of the document serves, so a hand-made page or a draft gets nothing. The status pill never uses the bare `status-<slug>` class, which WordPress already puts on every post (`status-publish`).

## The visual identity (ADR-027)

**Where it lives.** In this theme, not in the Customizer of GeneratePress. A rule of `style.css` wins over a Customizer rule of the same weight because GeneratePress prints its Customizer CSS before this stylesheet, so the rules that override the Customizer repeat its selectors (`h1`, `.main-navigation a, .main-navigation .menu-toggle, .main-navigation .menu-bar-items`, `.separate-containers .inside-article, ...`), and the PHP side uses GeneratePress's filters rather than its settings. The Customizer's colours, typography and font manager can therefore be reset to the defaults of GeneratePress without changing anything on the page, which is the last step of the deployment of ADR-027 (`docs/guides/02-exploitation-site-trilingue.md`).

**The brand**, from the logo: night blue `#0B1F3A` (text, headings, filled button, footer) and cyan `#22D3EE`. The cyan is pale (1.8:1 on white): it is never the colour of a text on a light background; it draws accents (the line above the headline, the line under the current menu item) and colours the links of the night-blue footer (9.1:1). On light backgrounds two darker cyans of the same hue take over, `#0891B2` for lines and focus rings (3.7:1, enough for a non-text element) and `#0E7490` for a text (5.4:1). A link is the colour of the text, underlined in cyan: it is told by its underline, not by its colour alone.

**The fonts.** Two families, Space Grotesk for the headings (at most 700: the font stops there, a heavier weight would be drawn by the browser) and Inter for the text and the menu, served by the theme: no visitor's address goes to Google with the first page (the German case of 2022 against Google Fonts). `functions.php` dequeues the Google Fonts stylesheet GeneratePress builds from its font manager, whatever the manager holds, and with it its two preconnect hints; it preloads the Latin file of each family. While a font loads, the text is set in a local Arial (or Liberation Sans) resized to the font's metrics (`size-adjust` and the three overrides, computed from the average character width as Next.js and Fontaine do), so the page barely moves when the font arrives.

**The header**: a white band across the page, the logo of the theme on the left (240 pixels wide, 180 on a phone, its 2x file for sharp screens, `alt` the name of the site) and the menu on its right, the mobile menu button in the same line. The logo carries the name of the site, so the header prints no site title nor tagline beside it, and the header widget area, which held the former logo as an image block without text alternative, is unhooked (the widget stays in the database until someone removes it in the admin).

**The top of a page.** A page whose document has a `headline` (frontmatter, ADR-027) shows it as its h1, GeneratePress's own h1 (the title) being hidden there, so the page keeps one h1 and the title keeps naming it in the menu and the search results; its `subtitle` follows as a deck. On the home page of a language, two buttons follow: the projects (filled) and the CV of that language (outlined), at least 44 pixels high.

**The footer**: the copyright, then the profiles of `portfolio_md_child_profiles()` (GitHub) and the Contact page of the language, instead of "Built with GeneratePress". The e-mail address stays on the Contact page alone (`content/README.md`).

**Search results and social cards.** Yoast declares a large card on every page but had no image to show: the theme adds `assets/og-image.png`, with its size and type, to any page Yoast found no image for (a document has none today). The project archive, a page of the theme, gets its title ("Projets - Morgan Voltz", where Yoast's template said "Projets Archive") and a description from the texts of the theme.

**Typography of the documents.** WordPress sets the body and the titles with `wptexturize()` (typographic apostrophe, French quotation marks with their spaces); the texts the theme prints from the documents (titles and subtitles of the cards, deck, headline, role, client, tag and skill labels) go through it too, through `portfolio_md_child_esc_text()`, so that a card no longer says "l'utilisateur" under a body that says "l’utilisateur".

**No emoji script**: WordPress's loader fetches images from a third party for a browser without an emoji the site does not use; `functions.php` unhooks it. `bootstrap.sh` meant to, but `use_smilies` only stops the conversion of `:)`.

## Checks

```bash
cd plugin && podman run --rm -v "$PWD/..:/repo:z" -w /repo/plugin docker.io/library/composer:2 vendor/bin/phpunit --filter Theme
podman-compose --profile tools run --rm wpcli wp eval-file \
  wp-content/plugins/portfolio-md/tests/Integration/wp-cli/list-smoke.php --user=<your admin login>   # lists, archive rule, portfolio_md_document(), cards and project header
plugin/tools/opening-check.sh http://localhost:8000   # cards, badges and switcher of each page, German closed, open, closed
curl -s http://localhost:8000/fr/projets/momocad/ | grep -c 'project-facts'   # 1: the page of a project wears its facts
curl -s http://localhost:8000/fr/ | grep -c 'fonts.googleapis\|fonts.gstatic\|wp-emoji'   # 0: no third-party font, no emoji script
curl -s http://localhost:8000/fr/ | grep -o '<h1[^>]*>[^<]*'   # one h1, the headline of the home page
curl -s http://localhost:8000/fr/ | grep -o '<meta property="og:image[^>]*>'   # the default image, its width, height and type
```

A screenshot of a page shows the fonts only once they have loaded: `chromium-browser --headless=new --virtual-time-budget=4000 --window-size=1440,1400 --screenshot=<file>.png http://localhost:8000/fr/`. A stylesheet comment that holds the two characters `*/` (a path written with a wildcard, for example) closes early and takes the next rule with it: the first `@font-face` was lost that way once.

## Rules

- The theme never decides the language of a document and never writes anything: it reads what the plugin serves (`portfolio_md_list_with_fallback()`, `portfolio_md_published_languages()`, `portfolio_md_document()`) and what Polylang routes.
- Every call to the plugin or to Polylang is guarded by `function_exists()` or `class_exists()`: the theme renders without them.
- The visual identity lives in the theme (ADR-027): a change of colour, font or header is a change of `style.css` or `functions.php`, reviewed and deployed like code, never a setting of the Customizer.
- The one-time configuration of the site (static front page, menus, Yoast options, the publication option) is not in the theme: it is `plugin/tools/site-setup.php`, replayable.
