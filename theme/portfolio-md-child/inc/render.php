<?php

declare(strict_types=1);

/**
 * What the theme renders from what the plugin and Polylang hand over (ADR-022,
 * points 5, 8 and 10; phase 10): the language switcher, the cards of a list
 * with fallback, the recent projects block of the home page, and the header
 * and footer of the page of a project (its facts, its tags, its skills);
 * since ADR-027, the top of a page (its headline, its deck, its buttons) and
 * the credit of the footer.
 * Pure functions, arrays and value objects in, HTML out, every value escaped
 * here; they call nothing of WordPress but the escaping functions and
 * wptexturize(), so the unit suite of the plugin exercises them without a
 * WordPress. The hooks that feed them are in functions.php.
 */

use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Domain\ProjectStatus;
use Voltz\PortfolioMd\Service\ListItem;
use Voltz\PortfolioMd\Service\PublishedDocument;

/**
 * A text of a document (a title, a subtitle, a headline, a role, a label),
 * set as WordPress sets the body around it, then escaped (ADR-027): the
 * straight apostrophe of the Markdown becomes the typographic one, the
 * French quotation marks get their spaces, as wptexturize() does for the
 * body and the titles of WordPress. Without it the deck of a project showed
 * "l'utilisateur" under a body that said "l’utilisateur". esc_html() keeps
 * the entities wptexturize() writes (it never encodes an entity twice).
 */
function portfolio_md_child_esc_text(string $text): string
{
    return esc_html(wptexturize($text));
}

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
 * language, so its spacing applies), the current language as an anchor
 * without href, a placeholder that is not a link but takes the same room as
 * its neighbours; a language without translation of the page links to its
 * home, as Polylang gave it, and says so with a class. Nothing when fewer than two
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
            $html .= '<span class="menu-bar-item lang-item current-lang"><a class="lang-current" lang="' . $slug . '" aria-current="page"><span aria-hidden="true">' . $code . '</span><span class="screen-reader-text">' . $name . '</span></a></span>';
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
 * the article and on its link, and the badge after its title; a project
 * card wears the status of its project under the title, in the language of
 * the page (phase 10). An empty list is one sentence.
 *
 * @param list<ListItem> $items
 * @param string         $language the language of the page, for the badge and the empty sentence
 * @param string         $heading  the heading element of a card title: 'h2' under the title of an
 *                                 archive, 'h3' under the h2 of a block (the home); anything else is 'h2'
 */
function portfolio_md_child_render_cards(array $items, string $language, string $heading = 'h2'): string
{
    if ($items === []) {
        return '<p class="no-results">' . esc_html(portfolio_md_child_text('no_projects', $language)) . '</p>';
    }

    $html = '';
    foreach ($items as $item) {
        $html .= portfolio_md_child_render_card($item, $language, $heading);
    }

    return $html;
}

function portfolio_md_child_render_card(ListItem $item, string $language, string $heading = 'h2'): string
{
    $heading = $heading === 'h3' ? 'h3' : 'h2';
    $documentLanguage = esc_attr($item->lang->value);
    $classes = 'post-' . $item->postId . ' project type-project status-publish hentry portfolio-card' . ($item->isFallback ? ' is-fallback' : '');
    $article = '<article id="post-' . $item->postId . '" class="' . esc_attr($classes) . '"' . ($item->isFallback ? ' lang="' . $documentLanguage . '"' : '') . ' itemtype="https://schema.org/CreativeWork" itemscope>';
    $link = '<a href="' . esc_url($item->permalink) . '" rel="bookmark"' . ($item->isFallback ? ' hreflang="' . $documentLanguage . '"' : '') . '>' . portfolio_md_child_esc_text($item->title) . '</a>';
    $badge = $item->isFallback ? ' ' . portfolio_md_child_render_badge($item->lang->value, $language) : '';
    $status = $item->status !== null ? '<p class="project-card-status">' . portfolio_md_child_render_status($item->status, $language) . '</p>' : '';
    $summary = $item->excerpt !== '' ? '<div class="entry-summary"><p>' . portfolio_md_child_esc_text($item->excerpt) . '</p></div>' : '';

    return $article
        . '<div class="inside-article">'
        . '<header class="entry-header"><' . $heading . ' class="entry-title" itemprop="headline">' . $link . $badge . '</' . $heading . '>' . $status . '</header>'
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

/**
 * The recent projects block of the home page (ADR-022, point 10): a heading,
 * the cards of the first projects as the reader's language gets them, in the
 * same grid as the archive and with h3 titles under the h2 of the block, and
 * a link to the archive of the language. Nothing when the list is empty: a
 * home page without projects says nothing rather than an empty heading.
 *
 * @param list<ListItem> $items      the first projects, portfolio_md_list_with_fallback('project', $language, 3)
 * @param string         $language   the language of the page
 * @param string         $archiveUrl the archive of the projects in that language, get_post_type_archive_link('project')
 */
function portfolio_md_child_render_home_projects(array $items, string $language, string $archiveUrl): string
{
    if ($items === []) {
        return '';
    }

    return '<section class="home-projects" aria-labelledby="home-projects-title">'
        . '<h2 id="home-projects-title" class="home-projects-title">' . esc_html(portfolio_md_child_text('recent_projects', $language)) . '</h2>'
        . '<div class="portfolio-cards">' . portfolio_md_child_render_cards($items, $language, 'h3') . '</div>'
        . ($archiveUrl !== '' ? '<p class="home-projects-more"><a href="' . esc_url($archiveUrl) . '">' . esc_html(portfolio_md_child_text('all_projects', $language)) . '</a></p>' : '')
        . '</section>';
}

/**
 * The language of the page as the plugin's enum, French for anything the
 * site does not know: the status and skill labels are tables keyed by it.
 */
function portfolio_md_child_language(string $language): Language
{
    return Language::tryFrom($language) ?? Language::default();
}

/**
 * The status of a project as a pill: its slug as a class for the colour, its
 * label in the language of the page (the table of Domain\ProjectStatus,
 * ADR-020 lot 4). Never the bare `status-<slug>` class: WordPress already
 * puts `status-publish` on every post.
 */
function portfolio_md_child_render_status(ProjectStatus $status, string $language): string
{
    return '<span class="project-status is-' . esc_attr($status->value) . '">' . esc_html($status->label(portfolio_md_child_language($language))) . '</span>';
}

/**
 * A month and its year as a reader of the language writes them, in a <time>
 * whose machine date is the month: "septembre 2026", "September 2026".
 */
function portfolio_md_child_render_month(DateTimeImmutable $date, string $language): string
{
    return '<time datetime="' . esc_attr($date->format('Y-m')) . '">' . esc_html(portfolio_md_child_month_name((int) $date->format('n'), $language) . ' ' . $date->format('Y')) . '</time>';
}

/**
 * The period of a project (phase 10): nothing without a start; "depuis
 * septembre 2026" while it runs; one month when it started and ended in
 * the same month; "janvier 2026 – mai 2026" otherwise. The frontmatter
 * gives days; the page says months, enough for a portfolio.
 */
function portfolio_md_child_render_period(?DateTimeImmutable $start, ?DateTimeImmutable $end, string $language): string
{
    if ($start === null) {
        return '';
    }
    if ($end === null) {
        return sprintf(esc_html(portfolio_md_child_text('since', $language)), portfolio_md_child_render_month($start, $language));
    }
    if ($start->format('Y-m') === $end->format('Y-m')) {
        return portfolio_md_child_render_month($start, $language);
    }

    return portfolio_md_child_render_month($start, $language) . ' – ' . portfolio_md_child_render_month($end, $language);
}

/**
 * A link to the repository or the demo of a project: the address without
 * its scheme and trailing slash for the eye, the whole address as the target.
 */
function portfolio_md_child_render_external_link(string $url): string
{
    $shown = rtrim((string) preg_replace('#^https?://#i', '', $url), '/');

    return '<a href="' . esc_url($url) . '" rel="noopener">' . esc_html($shown !== '' ? $shown : $url) . '</a>';
}

/**
 * The header of the page of a project (phase 10), under the title that
 * GeneratePress prints: the subtitle as a deck, the fil rouge mention, then
 * the facts of the frontmatter as a definition list in a named group, in a
 * fixed order (status, period, role, client, repository, demo), each only
 * when the document has it. The labels are the theme's, in the language of
 * the page; the values are the document's. Nothing for a document without
 * any of them.
 */
function portfolio_md_child_render_project_header(PublishedDocument $document, string $language): string
{
    $html = '';
    if ($document->subtitle !== '') {
        $html .= '<p class="project-deck">' . portfolio_md_child_esc_text($document->subtitle) . '</p>';
    }
    if ($document->isFilRouge) {
        $html .= '<p class="project-fil-rouge">' . esc_html(portfolio_md_child_text('fil_rouge', $language)) . '</p>';
    }

    $facts = [];
    if ($document->status !== null) {
        $facts['status'] = portfolio_md_child_render_status($document->status, $language);
    }
    $period = portfolio_md_child_render_period($document->periodStart, $document->periodEnd, $language);
    if ($period !== '') {
        $facts['period'] = $period;
    }
    if ($document->role !== '') {
        $facts['role'] = portfolio_md_child_esc_text($document->role);
    }
    if ($document->client !== '') {
        $facts['client'] = portfolio_md_child_esc_text($document->client);
    }
    if ($document->repoUrl !== '') {
        $facts['repository'] = portfolio_md_child_render_external_link($document->repoUrl);
    }
    if ($document->demoUrl !== '') {
        $facts['demo'] = portfolio_md_child_render_external_link($document->demoUrl);
    }
    if ($facts !== []) {
        $html .= '<div class="project-facts" role="group" aria-label="' . esc_attr(portfolio_md_child_text('project_facts', $language)) . '"><dl class="project-facts-list">';
        foreach ($facts as $key => $value) {
            $html .= '<div class="project-fact project-fact-' . $key . '"><dt>' . esc_html(portfolio_md_child_text($key, $language)) . '</dt><dd>' . $value . '</dd></div>';
        }
        $html .= '</dl></div>';
    }

    return $html === '' ? '' : '<div class="project-header">' . $html . '</div>';
}

/**
 * The footer of the page of a project (phase 10), after its body: the
 * technologies as bullets without link (the archives of `tech_tag` are
 * noindex, ADR-022 point 9: nothing sends a visitor or a robot there), each
 * named in the language of the page as the plugin resolves it (ADR-024),
 * marked with its language when the name had to fall back on another, then
 * the skills the project demonstrates, each with its code and its label in
 * the language of the page as the plugin resolves it (ADR-020 lot 4), the
 * label marked with its language when it had to fall back on another one,
 * an asterisk when the referential has no official wording yet and, once
 * under the list, the sentence that says what the asterisk means. Nothing
 * for a document without tags nor skills.
 */
function portfolio_md_child_render_project_footer(PublishedDocument $document, string $language): string
{
    $html = '';
    if ($document->tags !== []) {
        $page = portfolio_md_child_language($language);
        $items = '';
        foreach ($document->tags as $tag) {
            $wording = $tag->wordingLanguage($page);
            $items .= '<li class="tech-tag"' . ($wording === $page ? '' : ' lang="' . esc_attr($wording->value) . '"') . '>' . portfolio_md_child_esc_text($tag->label($page)) . '</li>';
        }
        $html .= '<section class="project-tags" aria-labelledby="project-tags-title">'
            . '<h2 id="project-tags-title">' . esc_html(portfolio_md_child_text('technologies', $language)) . '</h2>'
            . '<ul class="tech-tags">' . $items . '</ul></section>';
    }
    if ($document->skills !== []) {
        $page = portfolio_md_child_language($language);
        $items = '';
        $provisional = false;
        foreach ($document->skills as $skill) {
            $wording = $skill->wordingLanguage($page);
            $provisional = $provisional || !$skill->official;
            $items .= '<li class="skill' . ($skill->official ? '' : ' is-provisional') . '">'
                . '<span class="skill-code">' . esc_html($skill->code) . '</span> '
                . '<span class="skill-text"><span class="skill-label"' . ($wording === $page ? '' : ' lang="' . esc_attr($wording->value) . '"') . '>' . portfolio_md_child_esc_text($skill->label($page)) . '</span>'
                . ($skill->official ? '' : '<span class="skill-provisional" title="' . esc_attr(portfolio_md_child_text('provisional', $language)) . '"><span aria-hidden="true">*</span><span class="screen-reader-text"> (' . esc_html(portfolio_md_child_text('provisional', $language)) . ')</span></span>')
                . '</span></li>';
        }
        $html .= '<section class="project-skills" aria-labelledby="project-skills-title">'
            . '<h2 id="project-skills-title">' . esc_html(portfolio_md_child_text('skills', $language)) . '</h2>'
            . '<ul class="skills">' . $items . '</ul>'
            . ($provisional ? '<p class="skills-note"><span aria-hidden="true">* </span>' . esc_html(portfolio_md_child_text('provisional_note', $language)) . '</p>' : '')
            . '</section>';
    }

    return $html === '' ? '' : '<div class="project-footer">' . $html . '</div>';
}

/**
 * The top of a page the plugin knows (ADR-027), inside the entry header that
 * GeneratePress prints: the headline of the document as the h1 when it has
 * one (functions.php then hides the title GeneratePress would print, so the
 * page keeps a single h1, and the title keeps naming the page in the menu
 * and the browser tab), the subtitle as a deck under it, then the actions:
 * links styled as buttons, the first one filled, the others outlined, an
 * action without address or label left out. Nothing when there is nothing
 * to say, so a page without headline, subtitle nor action is unchanged.
 *
 * @param list<array{url: string, label: string}> $actions the buttons, in their order
 */
function portfolio_md_child_render_page_intro(string $headline, string $subtitle, array $actions = []): string
{
    $html = '';
    if ($headline !== '') {
        $html .= '<h1 class="entry-title page-headline" itemprop="headline">' . portfolio_md_child_esc_text($headline) . '</h1>';
    }
    if ($subtitle !== '') {
        $html .= '<p class="page-deck">' . portfolio_md_child_esc_text($subtitle) . '</p>';
    }

    $buttons = '';
    foreach ($actions as $action) {
        $url = (string) ($action['url'] ?? '');
        $label = (string) ($action['label'] ?? '');
        if ($url === '' || $label === '') {
            continue;
        }
        $buttons .= '<a class="pm-button ' . ($buttons === '' ? 'is-primary' : 'is-secondary') . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }
    if ($buttons !== '') {
        $html .= '<p class="page-actions">' . $buttons . '</p>';
    }

    return $html;
}

/**
 * The logo of the header (ADR-027), the whole markup GeneratePress prints
 * for its own logo (`.site-logo`, `.header-image`), so that its header rules
 * apply: a link to the home of the language, an image shipped in the theme
 * (a vector file, or a bitmap with its 2x version when $src2x is given),
 * its size written out so the header does not move when it loads, and the
 * name of the site as its text, which a screen reader reads as the name of
 * the link. The logo carries the name of the
 * site, so the header prints no title beside it (functions.php).
 */
function portfolio_md_child_render_logo(string $homeUrl, string $src, string $src2x, int $width, int $height, string $alt): string
{
    return '<div class="site-logo"><a href="' . esc_url($homeUrl) . '" rel="home">'
        . '<img class="header-image is-logo-image" src="' . esc_url($src) . '"'
        . ($src2x !== '' ? ' srcset="' . esc_url($src) . ' 1x, ' . esc_url($src2x) . ' 2x"' : '')
        . ' width="' . $width . '" height="' . $height . '" alt="' . esc_attr($alt) . '">'
        . '</a></div>';
}

/**
 * The credit of the footer (ADR-027), in place of GeneratePress's "Built
 * with GeneratePress": the copyright of the site, then its links (the
 * profiles of portfolio_md_child_profiles(), the Contact page) as a named
 * list, a link without address left out.
 *
 * @param array<string, string> $links label => URL, in their order
 * @param string                $label the accessible name of the list, in the language of the page
 */
function portfolio_md_child_render_footer_credit(string $siteName, int $year, array $links, string $label): string
{
    $items = '';
    foreach ($links as $text => $url) {
        if ($url === '') {
            continue;
        }
        $items .= '<li><a href="' . esc_url($url) . '">' . esc_html((string) $text) . '</a></li>';
    }

    return '<span class="copyright">&copy; ' . $year . ' ' . esc_html($siteName) . '</span>'
        . ($items !== '' ? '<nav class="footer-links" aria-label="' . esc_attr($label) . '"><ul>' . $items . '</ul></nav>' : '');
}
