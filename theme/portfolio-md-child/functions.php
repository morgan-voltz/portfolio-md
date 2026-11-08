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
 * (points 6 and 8). The home block and the menus come with lot 5d.
 */

require_once __DIR__ . '/inc/strings.php';
require_once __DIR__ . '/inc/render.php';

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
    $elements = pll_the_languages(['raw' => 1, 'hide_if_empty' => 0, 'hide_if_no_translation' => 0, 'hide_current' => 0]);
    $items = portfolio_md_child_switcher_items(is_array($elements) ? $elements : [], portfolio_md_published_languages());

    echo portfolio_md_child_render_switcher($items, portfolio_md_child_text('languages', portfolio_md_child_current_language())); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped while rendered
}
add_action('generate_menu_bar_items', 'portfolio_md_child_language_switcher');
