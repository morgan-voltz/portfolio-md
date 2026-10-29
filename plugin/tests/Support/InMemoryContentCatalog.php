<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Support;

use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Service\CatalogPage;
use Voltz\PortfolioMd\Service\CatalogQuery;
use Voltz\PortfolioMd\Service\ContentCatalog;
use Voltz\PortfolioMd\Service\DocumentSkill;
use Voltz\PortfolioMd\Service\DocumentTag;
use Voltz\PortfolioMd\Service\PublishedDocument;
use Voltz\PortfolioMd\Service\SitemapEntry;

/**
 * ContentCatalog backed by an array, for the unit tests of the public
 * routes. Everything it holds is published: the tests of what is left out
 * (drafts, hand-made posts) belong to the smoke test of the WordPress
 * store. It applies the filters and the pagination the way the store
 * does, newest first (then newest post first at equal dates), and records
 * what it was asked.
 */
final class InMemoryContentCatalog implements ContentCatalog
{
    /** @var list<PublishedDocument> */
    private array $documents = [];

    /** @var list<array{type: ContentType, query: CatalogQuery}> */
    public array $listCalls = [];

    /** @var list<array{type: ContentType, slug: string}> */
    public array $findCalls = [];

    public function add(PublishedDocument ...$documents): void
    {
        foreach ($documents as $document) {
            $this->documents[] = $document;
        }
    }

    public function list(ContentType $type, CatalogQuery $query): CatalogPage
    {
        $this->listCalls[] = ['type' => $type, 'query' => $query];

        $matching = array_values(array_filter(
            $this->documents,
            static fn (PublishedDocument $document): bool => $document->type === $type
                && ($query->tag === null || in_array($query->tag, array_map(static fn (DocumentTag $tag): string => $tag->slug, $document->tags), true))
                && ($query->status === null || $document->status === $query->status)
                && ($query->skill === null || in_array($query->skill, array_map(static fn (DocumentSkill $skill): string => $skill->slug, $document->skills), true)),
        ));
        usort($matching, static fn (PublishedDocument $a, PublishedDocument $b): int => [$b->date, $b->id] <=> [$a->date, $a->id]);

        return new CatalogPage(array_slice($matching, $query->offset(), $query->perPage), count($matching), $query->page, $query->perPage);
    }

    public function find(ContentType $type, string $slug): ?PublishedDocument
    {
        $this->findCalls[] = ['type' => $type, 'slug' => $slug];

        foreach ($this->documents as $document) {
            if ($document->type === $type && $document->slug === $slug) {
                return $document;
            }
        }

        return null;
    }

    public function sitemap(): array
    {
        $entries = array_map(
            static fn (PublishedDocument $document): SitemapEntry => new SitemapEntry($document->type, $document->slug, $document->updated),
            $this->documents,
        );
        // By collection then by slug, as the store orders them.
        usort($entries, static fn (SitemapEntry $a, SitemapEntry $b): int => [$a->type->collection(), $a->slug] <=> [$b->type->collection(), $b->slug]);

        return $entries;
    }
}
