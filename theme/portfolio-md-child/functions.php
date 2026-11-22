<?php

declare(strict_types=1);

/**
 * Portfolio MD, child theme of GeneratePress (ADR-022, lot 5 of ADR-020).
 *
 * The theme renders what the portfolio-md plugin stores and what Polylang
 * routes: it never decides the language of a document and never writes
 * anything. Every call to the plugin or to Polylang is guarded by
 * function_exists(), so that the theme still renders when either is
 * inactive. The texts of the theme are in inc/strings.php, the rendering
 * in inc/render.php (pure functions, covered by the unit suite of the
 * plugin); this file only hooks them into GeneratePress.
 *
 * Lot 5c: the language switcher on the menu bar (point 5) and, through
 * archive-project.php, the list of projects with fallback and the badge
 * (points 6 and 8). Lot 5d: the recent projects block under the content of
 * the home page of each language (point 10); the menus per language are
 * configuration, plugin/tools/site-setup.php. Phase 10: the header and the
 * footer of the page of a project, on the hooks of GeneratePress's
 * content-single.php, so that no single-project.php is needed, and the
 * layout without sidebar.
 *
 * ADR-027: the visual identity lives here and in style.css, not in the
 * Customizer: the fonts served by the theme (GeneratePress's Google Fonts
 * stylesheet removed whatever the Customizer holds), the logo of the theme
 * on the left of the header and the menu on its right, the headline, the
 * deck and the buttons at the top of a page, the credit of the footer, the
 * default image of the social cards, and no emoji script.
 */

use Voltz\PortfolioMd\Domain\CorePages;
use Voltz\PortfolioMd\Domain\Language;

require_once __DIR__ . '/inc/strings.php';
require_once __DIR__ . '/inc/render.php';

/**
 * The logo of the header, a vector file in assets/ (the brand's logo of theme/logos/, its
 * viewBox trimmed to the drawing, 892 × 256): its CSS width, in pixels, and its height at that width.
 */
const PORTFOLIO_MD_CHILD_LOGO = 'assets/logo-morgan-voltz.svg';
const PORTFOLIO_MD_CHILD_LOGO_WIDTH = 240;
const PORTFOLIO_MD_CHILD_LOGO_HEIGHT = 69;

/** The default image of the social cards, shipped in assets/, at the size Open Graph recommends. */
const PORTFOLIO_MD_CHILD_SOCIAL_IMAGE = 'assets/og-image.png';
const PORTFOLIO_MD_CHILD_SOCIAL_IMAGE_WIDTH = 1200;
const PORTFOLIO_MD_CHILD_SOCIAL_IMAGE_HEIGHT = 630;

/**
 * The language of the page as Polylang resolved it from the URL, French
 * without Polylang: reading the routing of the request, never deciding the
 * language of a document.
 */
function portfolio_md_child_current_language(): string
{
    $slug = function_exists('pll_current_language') ? pll_current_language() : '';

    return is_string($slug) && $slug !== '' ? $slug : 'fr';
}

/**
 * The layout (phase 10): no sidebar on any page. The site has nothing to put
 * in one (no categories, no comments, no recent posts: the content is the
 * documents and their lists), and WordPress's default widgets would show an
 * empty "Recent Posts" and "No comments to show" beside every page. The
 * width this frees goes to the grid of cards; the body text keeps a
 * readable measure in style.css. A filter of GeneratePress, so that the
 * Customizer's layout setting cannot bring the sidebar back by accident.
 */
function portfolio_md_child_sidebar_layout(): string
{
    return 'no-sidebar';
}
add_filter('generate_sidebar_layout', 'portfolio_md_child_sidebar_layout');

/**
 * The language switcher (ADR-022, point 5), on the menu bar of GeneratePress,
 * which renders it in the main navigation and again in the mobile one: the
 * raw elements of Polylang, every language kept (`hide_*` off: the plugin,
 * not Polylang, says which languages are shown), then reduced to the open
 * ones. Without Polylang or without the plugin, nothing.
 */
function portfolio_md_child_language_switcher(): void
{
    if (!function_exists('pll_the_languages') || !function_exists('portfolio_md_published_languages')) {
        return;
    }
    $elements = pll_the_languages(['raw' => 1, 'hide_if_empty' => 0, 'hide_if_no_translation' => 0, 'hide_current' => 0, 'show_wrapper' => 0]);
    $items = portfolio_md_child_switcher_items(is_array($elements) ? $elements : [], portfolio_md_published_languages());

    echo portfolio_md_child_render_switcher($items, portfolio_md_child_text('languages', portfolio_md_child_current_language())); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped while rendered
}
add_action('generate_menu_bar_items', 'portfolio_md_child_language_switcher');

/**
 * The main query of the project archive (ADR-022, point 8): archive-project.php
 * prints the list of the plugin, not the loop, but WordPress still pages the
 * loop and would answer /projets/page/2/ with the same cards. The page size
 * of the loop is aligned on what the template prints, so that a second page
 * exists only beyond it and is a 404 otherwise.
 */
function portfolio_md_child_archive_query(WP_Query $query): void
{
    if (is_admin() || !$query->is_main_query() || !$query->is_post_type_archive('project')) {
        return;
    }
    $query->set('posts_per_page', class_exists(\Voltz\PortfolioMd\Service\ListWithFallback::class) ? \Voltz\PortfolioMd\Service\ListWithFallback::DEFAULT_LIMIT : 50);
}
add_action('pre_get_posts', 'portfolio_md_child_archive_query');

/**
 * The recent projects block of the home page (ADR-022, point 10): under the
 * Markdown body of the home page of the language, on GeneratePress's
 * generate_after_content of the page template, the first three projects as
 * the reader's language gets them and a link to the archive. Polylang
 * translates the static front page per language, so is_front_page() is true
 * on /fr/, /de/ and /en/ alike once each has its home page.
 */
function portfolio_md_child_home_projects(): void
{
    if (!is_front_page() || !is_main_query() || !in_the_loop() || !function_exists('portfolio_md_list_with_fallback')) {
        return;
    }
    $language = portfolio_md_child_current_language();
    $archive = get_post_type_archive_link('project');

    echo portfolio_md_child_render_home_projects(portfolio_md_list_with_fallback('project', $language, 3), $language, is_string($archive) ? $archive : ''); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped while rendered
}
add_action('generate_after_content', 'portfolio_md_child_home_projects');

/**
 * The document of the project being displayed, read once per request from
 * the plugin's catalog (portfolio_md_document(), the same object the REST
 * route serves); null outside the single view of a project in the main
 * loop, without the plugin, or for a post the plugin does not know.
 *
 * @return \Voltz\PortfolioMd\Service\PublishedDocument|null
 */
function portfolio_md_child_single_project_document(): ?object
{
    static $cache = [];
    if (!is_singular('project') || !is_main_query() || !in_the_loop() || !function_exists('portfolio_md_document')) {
        return null;
    }
    $id = (int) get_the_ID();
    if (!array_key_exists($id, $cache)) {
        $cache[$id] = portfolio_md_document($id);
    }

    return $cache[$id];
}

/**
 * The header of the page of a project (phase 10): under the title that
 * GeneratePress prints in the entry header, the subtitle, the fil rouge
 * mention and the facts of the frontmatter (status, period, role, client,
 * repository, demo), in the language of the document, which is the
 * language of its page.
 */
function portfolio_md_child_project_header(): void
{
    $document = portfolio_md_child_single_project_document();
    if ($document !== null) {
        echo portfolio_md_child_render_project_header($document, $document->lang->value); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped while rendered
    }
}
add_action('generate_after_entry_title', 'portfolio_md_child_project_header');

/**
 * The footer of the page of a project (phase 10): after its body, the
 * technologies as bullets without link and the skills it demonstrates with
 * their labels in the language of the document.
 */
function portfolio_md_child_project_footer(): void
{
    $document = portfolio_md_child_single_project_document();
    if ($document !== null) {
        echo portfolio_md_child_render_project_footer($document, $document->lang->value); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped while rendered
    }
}
add_action('generate_after_entry_content', 'portfolio_md_child_project_footer');

/**
 * The address of a core page of the plugin in a language, by its key in
 * CorePages ('accueil', 'a-propos', 'cv', 'contact', written out by the
 * callers so that nothing here touches a class of the plugin before
 * class_exists() said it is there): the published page that carries the
 * slug of that key in that language, a slug being unique across languages
 * (content/README.md); the empty string without the plugin or the page.
 */
function portfolio_md_child_core_page_url(string $key, string $language): string
{
    if (!class_exists(CorePages::class) || !class_exists(Language::class)) {
        return '';
    }
    $page = get_page_by_path(CorePages::slug($key, Language::tryFrom($language) ?? Language::default()), OBJECT, 'page');
    if (!$page instanceof WP_Post || $page->post_status !== 'publish') {
        return '';
    }
    $url = get_permalink($page);

    return is_string($url) ? $url : '';
}

/**
 * The fonts (ADR-027): Space Grotesk and Inter come from the theme (fonts/,
 * the @font-face rules of style.css), never from Google, so that no visitor's
 * address goes to a third party with the first page. GeneratePress enqueues
 * a Google Fonts stylesheet for the fonts of its Customizer font manager;
 * it is removed here whatever the Customizer holds, and with it the two
 * preconnect hints GeneratePress prints only while that stylesheet is
 * queued. Priority 20: after GeneratePress's own enqueue, at 10.
 */
function portfolio_md_child_dequeue_remote_fonts(): void
{
    wp_dequeue_style('generate-google-fonts'); // the typography of GeneratePress 3.1 and later
    wp_dequeue_style('generate-fonts');        // its legacy typography
}
add_action('wp_enqueue_scripts', 'portfolio_md_child_dequeue_remote_fonts', 20);

/**
 * The two font files the top of every page needs (the Latin subsets of the
 * headings and of the text), asked for before the stylesheet that names
 * them is parsed. WordPress prints these preloads in the head (6.1 and
 * later); a font is fetched in CORS mode, hence `crossorigin`.
 *
 * @param array<int, array<string, string>> $resources
 *
 * @return array<int, array<string, string>>
 */
function portfolio_md_child_preload_fonts(array $resources): array
{
    $fonts = get_stylesheet_directory_uri() . '/fonts/';
    foreach (['space-grotesk/space-grotesk-latin-wght-normal.woff2', 'inter/inter-latin-wght-normal.woff2'] as $file) {
        $resources[] = ['href' => $fonts . $file, 'as' => 'font', 'type' => 'font/woff2', 'crossorigin' => 'anonymous'];
    }

    return $resources;
}
add_filter('wp_preload_resources', 'portfolio_md_child_preload_fonts');

/*
 * No emoji script on the public pages (ADR-027): WordPress's loader checks
 * the browser and, when it lacks an emoji, fetches images from a third
 * party (s.w.org). The site writes none. bootstrap.sh meant to switch it
 * off, but use_smilies only stops the conversion of ":)"; these three
 * hooks are the loader, its styles, and the old styles hook that
 * wp_enqueue_emoji_styles() would have unhooked itself.
 */
remove_action('wp_head', 'print_emoji_detection_script', 7);
remove_action('wp_enqueue_scripts', 'wp_enqueue_emoji_styles');
remove_action('wp_print_styles', 'print_emoji_styles');

/**
 * The header (ADR-027): the logo on the left and the menu on its right, on
 * one line, whatever the Customizer's navigation location says. The mobile
 * menu button then sits in the same line (GeneratePress's inline toggle).
 */
function portfolio_md_child_navigation_location(): string
{
    return 'nav-float-right';
}
add_filter('generate_navigation_location', 'portfolio_md_child_navigation_location');

/**
 * The logo of the header is the theme's (ADR-027). GeneratePress prints a
 * logo only when it has an address for one: the filter gives it the
 * theme's, then the output filter replaces its markup by the theme's own,
 * whose size does not depend on a logo set in the Customizer. A vector
 * file is sharp on every screen, so it needs no 2x version.
 */
function portfolio_md_child_logo_url(): string
{
    return get_stylesheet_directory_uri() . '/' . PORTFOLIO_MD_CHILD_LOGO;
}
add_filter('generate_logo', 'portfolio_md_child_logo_url');

function portfolio_md_child_logo_output(): string
{
    return portfolio_md_child_render_logo(
        home_url('/'),
        portfolio_md_child_logo_url(),
        '',
        PORTFOLIO_MD_CHILD_LOGO_WIDTH,
        PORTFOLIO_MD_CHILD_LOGO_HEIGHT,
        (string) get_bloginfo('name'),
    );
}
add_filter('generate_logo_output', 'portfolio_md_child_logo_output');

/*
 * The logo carries the name of the site: no site title nor tagline beside
 * it, and no header widget area, which held the former logo as an image
 * block (ADR-027). The widget area is unhooked rather than emptied, so the
 * widget stays in the database until someone removes it in the admin. The
 * removal waits for after_setup_theme: the functions.php of a child theme
 * is loaded before its parent's, which adds the hook.
 */
add_filter('generate_site_branding_output', '__return_empty_string');

function portfolio_md_child_remove_header_widget(): void
{
    remove_action('generate_after_header_content', 'generate_do_header_widget');
}
add_action('after_setup_theme', 'portfolio_md_child_remove_header_widget');

/**
 * The same widget area in the older float structure of GeneratePress, where
 * the header prints its items from this list rather than from the hook
 * above (a site whose settings predate GeneratePress 3.0).
 *
 * @param list<string> $items
 *
 * @return list<string>
 */
function portfolio_md_child_header_items(array $items): array
{
    return array_values(array_diff($items, ['header-widget']));
}
add_filter('generate_header_items_order', 'portfolio_md_child_header_items');

/**
 * The document of the page being displayed (ADR-027), read once per request
 * from the plugin's catalog like the one of a project; null outside a page
 * of the main loop, without the plugin, or for a page made by hand.
 *
 * @return \Voltz\PortfolioMd\Service\PublishedDocument|null
 */
function portfolio_md_child_page_document(): ?object
{
    static $cache = [];
    if (!is_page() || !is_main_query() || !in_the_loop() || !function_exists('portfolio_md_document')) {
        return null;
    }
    $id = (int) get_the_ID();
    if (!array_key_exists($id, $cache)) {
        $cache[$id] = portfolio_md_document($id);
    }

    return $cache[$id];
}

/**
 * A page whose document has a headline shows it as its h1 (ADR-027):
 * GeneratePress's own h1, the title, is hidden there, and the title keeps
 * naming the page in the menu, the browser tab and the search results.
 */
function portfolio_md_child_show_title(bool $show): bool
{
    $document = portfolio_md_child_page_document();

    return $document !== null && $document->headline !== '' ? false : $show;
}
add_filter('generate_show_title', 'portfolio_md_child_show_title');

/**
 * The top of a page (ADR-027), in its entry header, after the place of the
 * title: the headline, the subtitle as a deck and, on the home page of a
 * language, the two buttons, to the projects and to the CV of that
 * language. The language is the document's, which is the page's.
 */
function portfolio_md_child_page_intro(): void
{
    $document = portfolio_md_child_page_document();
    if ($document === null) {
        return;
    }
    $language = $document->lang->value;
    $actions = [];
    if (is_front_page()) {
        $archive = get_post_type_archive_link('project');
        $actions = [
            ['url' => is_string($archive) ? $archive : '', 'label' => portfolio_md_child_text('see_projects', $language)],
            ['url' => portfolio_md_child_core_page_url('cv', $language), 'label' => portfolio_md_child_text('see_cv', $language)],
        ];
    }

    echo portfolio_md_child_render_page_intro($document->headline, $document->subtitle, $actions); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped while rendered
}
add_action('generate_after_page_title', 'portfolio_md_child_page_intro');

/**
 * The credit of the footer (ADR-027): the copyright, then the profiles of
 * the author and the Contact page of the language, instead of "Built with
 * GeneratePress". The e-mail address stays on the Contact page alone.
 */
function portfolio_md_child_copyright(): string
{
    $language = portfolio_md_child_current_language();
    $links = portfolio_md_child_profiles();
    $links[portfolio_md_child_text('contact', $language)] = portfolio_md_child_core_page_url('contact', $language);

    return portfolio_md_child_render_footer_credit((string) get_bloginfo('name'), (int) wp_date('Y'), $links, portfolio_md_child_text('footer_links', $language));
}
add_filter('generate_copyright', 'portfolio_md_child_copyright');

/**
 * The default image of the social cards (ADR-027): Yoast declares a large
 * card (`twitter:card` summary_large_image) on every page but has no image
 * for a page without one, so LinkedIn or X showed an empty or a random
 * preview. The theme's image is added only when Yoast found none for the
 * page, with its size and type, so that og:image:width, og:image:height and
 * og:image:type are printed too; Yoast then leaves twitter:image out and
 * the card readers fall back on og:image. Yoast passes its image container
 * through this filter without reading the result: the container is
 * changed in place and handed on to the next callback.
 *
 * @param mixed $images Yoast\WP\SEO\Values\Open_Graph\Images
 *
 * @return mixed the same container
 */
function portfolio_md_child_default_social_image(mixed $images): mixed
{
    if (is_object($images) && method_exists($images, 'has_images') && method_exists($images, 'add_image') && !$images->has_images()) {
        $images->add_image([
            'url' => get_stylesheet_directory_uri() . '/' . PORTFOLIO_MD_CHILD_SOCIAL_IMAGE,
            'width' => PORTFOLIO_MD_CHILD_SOCIAL_IMAGE_WIDTH,
            'height' => PORTFOLIO_MD_CHILD_SOCIAL_IMAGE_HEIGHT,
            'type' => 'image/png',
        ]);
    }

    return $images;
}
add_filter('wpseo_add_opengraph_additional_images', 'portfolio_md_child_default_social_image');

/**
 * The title of the project archive in search results and social cards
 * (ADR-027): the archive is the theme's page, so it says its title in the
 * theme's words, in the language of the page ("Projets - Morgan Voltz"),
 * where Yoast's template for a post type archive said "Projets Archive", half
 * in English. Yoast escapes the text.
 */
function portfolio_md_child_archive_seo_title(mixed $title): mixed
{
    if (!is_post_type_archive('project')) {
        return $title;
    }

    return portfolio_md_child_text('projects', portfolio_md_child_current_language()) . ' ' . portfolio_md_child_title_separator() . ' ' . get_bloginfo('name');
}

/**
 * The separator of the titles, as Yoast's settings define it (the dash of the
 * other titles of the site, "CV - Morgan Voltz"), read the way Yoast reads it
 * itself, through its options helper; a dash if that API ever moves.
 */
function portfolio_md_child_title_separator(): string
{
    try {
        $separator = function_exists('YoastSEO') ? YoastSEO()->helpers->options->get_title_separator() : null;
    } catch (Throwable) {
        $separator = null;
    }

    return is_string($separator) && $separator !== '' ? $separator : '-';
}
add_filter('wpseo_title', 'portfolio_md_child_archive_seo_title');
add_filter('wpseo_opengraph_title', 'portfolio_md_child_archive_seo_title');

/**
 * The description of the project archive (ADR-027), which had none: a page
 * without document has no seo_description to copy into Yoast.
 */
function portfolio_md_child_archive_seo_description(mixed $description): mixed
{
    if (!is_post_type_archive('project')) {
        return $description;
    }

    return portfolio_md_child_text('projects_description', portfolio_md_child_current_language());
}
add_filter('wpseo_metadesc', 'portfolio_md_child_archive_seo_description');
add_filter('wpseo_opengraph_desc', 'portfolio_md_child_archive_seo_description');
