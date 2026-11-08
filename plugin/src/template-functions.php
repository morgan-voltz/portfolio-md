<?php

declare(strict_types=1);

/**
 * The functions a theme calls (ADR-022, points 5, 6 and 8; phase 10): plain functions
 * in the global namespace, loaded by the main file of the plugin, so that a
 * template guards them with function_exists() and needs neither the
 * autoloader nor a class name. They are thin: the work is in the services
 * they build over the WordPress adapters, once per request, and the unit
 * tests cover those services; what the functions add is covered by the
 * smoke tests on the real stack.
 */

use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Meta\InternalMetaRegistrar;
use Voltz\PortfolioMd\Service\ListWithFallback;
use Voltz\PortfolioMd\Service\PublishedDocument;
use Voltz\PortfolioMd\Service\PublishedLanguages;
use Voltz\PortfolioMd\Storage\WordPressContentCatalog;
use Voltz\PortfolioMd\Storage\WordPressPublishedLanguagesStore;

/**
 * The published documents of a kind as a reader of a language gets them
 * (ADR-021): the same documents, in the same order, as the REST list
 * `GET /portfolio/v1/<collection>?language=<lang>&per_page=<limit>`, each
 * served in that language when its translation group holds it, else along
 * the fallback chain (de → en → fr, en → fr) and flagged `isFallback`. The
 * theme prints the cards and the badge; the permalink of a card is the one
 * of the document served, in its own language.
 *
 * @param string $type     'project', 'article' or 'page'
 * @param string $language 'fr', 'de' or 'en'; anything else reads as French, the empty string
 *                         of a site without Polylang included
 * @param int    $limit    cards at most, 1 to 100; the whole portfolio fits in the default
 *
 * @return list<\Voltz\PortfolioMd\Service\ListItem>
 *
 * @throws InvalidArgumentException for a kind that does not exist or a limit outside 1..100:
 *                                  both are written in a template, so they are bugs, not data
 */
function portfolio_md_list_with_fallback(string $type, string $language, int $limit = ListWithFallback::DEFAULT_LIMIT): array
{
    static $list = null;
    $list ??= new ListWithFallback(new WordPressContentCatalog());

    $kind = ContentType::tryFrom($type)
        ?? throw new InvalidArgumentException("Unknown kind of document \"{$type}\"; one of " . implode(', ', ContentType::values()) . ' expected.');

    return $list->items($kind, Language::tryFrom($language) ?? Language::default(), $limit);
}

/**
 * The languages open to the public (ADR-022, point 1), French always and
 * first: what the switcher of the theme may show. An environment setting
 * read from the option, not the languages that have content.
 *
 * @return list<string> language slugs in the order fr, de, en
 */
function portfolio_md_published_languages(): array
{
    static $published = null;
    $published ??= new PublishedLanguages(new WordPressPublishedLanguagesStore());

    return array_map(static fn (Language $language): string => $language->value, $published->all());
}

/**
 * The published document a post carries, as its own language reads it
 * (phase 10): what the page of a project prints around its body, the
 * subtitle, the status, the period, the role, the client, the links, the
 * tags and the skills with their labels. The same object the REST route of
 * the document serves, read through the same catalog; the document is its
 * own translation, so it is never a fallback. Null for an id that is not a
 * published post, for a post the plugin does not know (a page made by hand,
 * without language), for a kind of post that is not a document, or if the
 * catalog answered with another post than the one asked (it cannot, a slug
 * being unique per kind; the guard keeps the promise explicit).
 */
function portfolio_md_document(int $postId): ?PublishedDocument
{
    static $catalog = null;
    $catalog ??= new WordPressContentCatalog();

    $post = get_post($postId);
    if (!$post instanceof WP_Post || $post->post_status !== 'publish' || $post->post_name === '') {
        return null;
    }
    $type = ContentType::tryFrom((string) $post->post_type);
    $language = Language::tryFrom((string) get_post_meta($postId, InternalMetaRegistrar::LANG, true));
    if ($type === null || $language === null) {
        return null;
    }

    $document = $catalog->find($type, $post->post_name, $language);

    return $document !== null && $document->id === $postId ? $document : null;
}
