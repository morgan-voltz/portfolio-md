<?php

declare(strict_types=1);

/**
 * What the theme renders from what the plugin and Polylang hand over (ADR-022,
 * points 5 and 8): the language switcher and the cards of a list with
 * fallback. Pure functions, arrays and value objects in, HTML out, every
 * value escaped here; they call nothing of WordPress but the escaping
 * functions, so the unit suite of the plugin exercises them without a
 * WordPress. The hooks that feed them are in functions.php.
 */

use Voltz\PortfolioMd\Service\ListItem;

/**
 * The entries of the switcher: the raw elements of pll_the_languages()
 * (Polylang 3.9 hands arrays keyed by slug, with the legacy keys kept),
 * reduced to the open languages, in the order of $published (fr, de, en).
 * An element without slug is skipped; a language Polylang does not know is
 * skipped too.
 *
 * @param array<mixed>  $elements  what pll_the_languages(['raw' => 1, ...]) returned
 * @param list<string>  $published the open languages, portfolio_md_published_languages()
 *
 * @return list<array{slug: string, url: string, name: string, current: bool, noTranslation: bool}>
 */
function portfolio_md_child_switcher_items(array $elements, array $published): array
{
    $bySlug = [];
    foreach ($elements as $element) {
        if (is_object($element)) {
            $element = get_object_vars($element);
        }
        if (!is_array($element)) {
            continue;
        }
        $slug = isset($element['slug']) && is_string($element['slug']) ? $element['slug'] : '';
        if ($slug === '') {
            continue;
        }
        $bySlug[$slug] = [
            'slug' => $slug,
            'url' => isset($element['url']) && is_string($element['url']) ? $element['url'] : '',
            'name' => isset($element['name']) && is_string($element['name']) && $element['name'] !== '' ? $element['name'] : strtoupper($slug),
            'current' => (bool) ($element['is_current'] ?? $element['current_lang'] ?? false),
            'noTranslation' => array_key_exists('has_translations', $element) ? !$element['has_translations'] : (bool) ($element['no_translation'] ?? false),
        ];
    }

    $items = [];
    foreach ($published as $slug) {
        if (isset($bySlug[$slug])) {
            $items[] = $bySlug[$slug];
        }
    }

    return $items;
}

/**
 * The switcher as menu bar items of GeneratePress (one `.menu-bar-item` per
 * language, so its spacing applies), the current language as text, not as a
 * link; a language without translation of the page links to its home, as
 * Polylang gave it, and says so with a class. Nothing when fewer than two
 * languages are open: a switcher with one entry says nothing. No id, since
 * GeneratePress renders the menu bar twice (main and mobile navigation).
 *
 * @param list<array{slug: string, url: string, name: string, current: bool, noTranslation: bool}> $items
 * @param string                                                                                   $label the accessible name of the group, in the language of the page
 */
function portfolio_md_child_render_switcher(array $items, string $label): string
{
    if (count($items) < 2) {
        return '';
    }

    $html = '';
    foreach ($items as $item) {
        $code = esc_html(strtoupper($item['slug']));
        $name = esc_html($item['name']);
        $slug = esc_attr($item['slug']);
        if ($item['current']) {
            $html .= '<span class="menu-bar-item lang-item current-lang"><span class="lang-current" lang="' . $slug . '" aria-current="true"><span aria-hidden="true">' . $code . '</span><span class="screen-reader-text">' . $name . '</span></span></span>';
            continue;
        }
        $html .= '<span class="menu-bar-item lang-item' . ($item['noTranslation'] ? ' no-translation' : '') . '"><a href="' . esc_url($item['url']) . '" lang="' . $slug . '" hreflang="' . $slug . '"><span aria-hidden="true">' . $code . '</span><span class="screen-reader-text">' . $name . '</span></a></span>';
    }

    return '<span class="lang-switcher" role="group" aria-label="' . esc_attr($label) . '">' . $html . '</span>';
}

/**
 * The cards of a list, in the markup GeneratePress gives a post of the loop
 * (article > .inside-article > header.entry-header > h2.entry-title > a, then
 * .entry-summary), so that its stylesheet and the Customizer colours apply.
 * A card served in another language than the page carries that language on
 * the article and on its link, and the badge after its title. An empty list
 * is one sentence.
 *
 * @param list<ListItem> $items
 * @param string         $language the language of the page, for the badge and the empty sentence
 */
function portfolio_md_child_render_cards(array $items, string $language): string
{
    if ($items === []) {
        return '<p class="no-results">' . esc_html(portfolio_md_child_text('no_projects', $language)) . '</p>';
    }

    $html = '';
    foreach ($items as $item) {
        $html .= portfolio_md_child_render_card($item, $language);
    }

    return $html;
}

function portfolio_md_child_render_card(ListItem $item, string $language): string
{
    $documentLanguage = esc_attr($item->lang->value);
    $classes = 'post-' . $item->postId . ' project type-project status-publish hentry portfolio-card' . ($item->isFallback ? ' is-fallback' : '');
    $article = '<article id="post-' . $item->postId . '" class="' . esc_attr($classes) . '"' . ($item->isFallback ? ' lang="' . $documentLanguage . '"' : '') . ' itemtype="https://schema.org/CreativeWork" itemscope>';
    $link = '<a href="' . esc_url($item->permalink) . '" rel="bookmark"' . ($item->isFallback ? ' hreflang="' . $documentLanguage . '"' : '') . '>' . esc_html($item->title) . '</a>';
    $badge = $item->isFallback ? ' ' . portfolio_md_child_render_badge($item->lang->value, $language) : '';
    $summary = $item->excerpt !== '' ? '<div class="entry-summary"><p>' . esc_html($item->excerpt) . '</p></div>' : '';

    return $article
        . '<div class="inside-article">'
        . '<header class="entry-header"><h2 class="entry-title" itemprop="headline">' . $link . $badge . '</h2></header>'
        . $summary
        . '</div></article>';
}

/**
 * The badge of a card served in another language than the page (ADR-022,
 * point 8): the code of that language for the eye, and for a screen reader
 * the sentence, in the language of the page, that says the page is only
 * available in that language.
 *
 * @param string $documentLanguage the language of the document served, 'fr' for the French fallback
 * @param string $pageLanguage     the language of the page
 */
function portfolio_md_child_render_badge(string $documentLanguage, string $pageLanguage): string
{
    $sentence = sprintf(portfolio_md_child_text('only_in', $pageLanguage), portfolio_md_child_language_name($documentLanguage, $pageLanguage));

    return '<span class="lang-badge" title="' . esc_attr($sentence) . '">'
        . '<span lang="' . esc_attr($documentLanguage) . '" aria-hidden="true">' . esc_html(strtoupper($documentLanguage)) . '</span>'
        . '<span class="screen-reader-text" lang="' . esc_attr($pageLanguage) . '">' . esc_html($sentence) . '</span>'
        . '</span>';
}
