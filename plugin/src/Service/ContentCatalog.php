<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use Voltz\PortfolioMd\Domain\ContentType;

/**
 * Port of the public read endpoints towards the post store
 * (01-plugin-php.md §7): what the site publishes, read-only.
 *
 * Kept apart from ContentRepository on purpose. That port serves the import
 * and is shaped by the conflict rule: it looks up any post under a slug,
 * trashed or unpublished, and reads hashes and provenance. This one serves
 * readers who are not logged in, and only ever sees what the site shows.
 * Implemented by Storage\WordPressContentCatalog in production and by an
 * in-memory fake in the unit tests.
 *
 * A document is a post that holds a Markdown source. A post of the right
 * type made by hand in the admin, without a document, is not in the catalog:
 * the catalog serves Markdown, and such a post has none.
 */
interface ContentCatalog
{
    /**
     * One page of the published documents of this kind, newest first,
     * narrowed by the filters of the query.
     */
    public function list(ContentType $type, CatalogQuery $query): CatalogPage;

    /**
     * The published document of this kind under this slug, or null. A draft,
     * a scheduled, private or password-protected post, a post in the trash
     * and a post without a document are null as well.
     */
    public function find(ContentType $type, string $slug): ?PublishedDocument;

    /**
     * Every published document of every kind, reduced to what a consumer
     * needs to notice a change: kind, slug and last modification.
     *
     * @return list<SitemapEntry>
     */
    public function sitemap(): array;
}
