<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Support;

use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Service\CatalogPage;
use Voltz\PortfolioMd\Service\CatalogQuery;
use Voltz\PortfolioMd\Service\ContentCatalog;
use Voltz\PortfolioMd\Service\DocumentSkill;
use Voltz\PortfolioMd\Service\DocumentTag;
use Voltz\PortfolioMd\Service\GroupMember;
use Voltz\PortfolioMd\Service\GroupResolver;
use Voltz\PortfolioMd\Service\PublishedDocument;
use Voltz\PortfolioMd\Service\SitemapEntry;

/**
 * ContentCatalog backed by an array, for the unit tests of the public
 * routes. Everything it holds is published: the tests of what is left out
 * (drafts, hand-made posts, posts without stored language) belong to the
 * smoke test of the WordPress store. It applies the filters, the
 * pagination and the language rules the way the store does (ADR-021): the
 * lists are anchored on the French documents, newest first (then newest
 * post first at equal dates), and every document is served through the
 * same GroupResolver. It records what it was asked.
 */
final class InMemoryContentCatalog implements ContentCatalog
{
    /** @var list<PublishedDocument> */
    private array $documents = [];

    /** @var list<array{type: ContentType, query: CatalogQuery}> */
    public array $listCalls = [];

    /** @var list<array{type: ContentType, slug: string, language: Language}> */
    public array $findCalls = [];

    /** @var list<array{type: ContentType, language: Language}> */
    public array $countOwnCalls = [];

    public function add(PublishedDocument ...$documents): void
    {
        foreach ($documents as $document) {
            $this->documents[] = $document;
        }
    }

    public function list(ContentType $type, CatalogQuery $query): CatalogPage
    {
        $this->listCalls[] = ['type' => $type, 'query' => $query];

        // Anchored on the French documents: the filters, the order and the pages are theirs.
        $anchors = array_values(array_filter(
            $this->documents,
            static fn (PublishedDocument $document): bool => $document->type === $type
                && $document->lang === Language::default()
                && ($query->tag === null || in_array($query->tag, array_map(static fn (DocumentTag $tag): string => $tag->slug, $document->tags), true))
                && ($query->status === null || $document->status === $query->status)
                && ($query->skill === null || in_array($query->skill, array_map(static fn (DocumentSkill $skill): string => $skill->slug, $document->skills), true)),
        ));
        usort($anchors, static fn (PublishedDocument $a, PublishedDocument $b): int => [$b->date, $b->id] <=> [$a->date, $a->id]);

        $items = array_map(
            fn (PublishedDocument $anchor): PublishedDocument => $this->serve($anchor, $query->language),
            array_slice($anchors, $query->offset(), $query->perPage),
        );

        return new CatalogPage($items, count($anchors), $query->page, $query->perPage);
    }

    public function find(ContentType $type, string $slug, Language $language): ?PublishedDocument
    {
        $this->findCalls[] = ['type' => $type, 'slug' => $slug, 'language' => $language];

        foreach ($this->documents as $document) {
            if ($document->type === $type && $document->slug === $slug) {
                return $this->serve($document, $language);
            }
        }

        return null;
    }

    public function sitemap(): array
    {
        $entries = array_map(
            static fn (PublishedDocument $document): SitemapEntry => new SitemapEntry($document->type, $document->slug, $document->updated, $document->lang, $document->translationKey),
            $this->documents,
        );
        // By collection then by slug, as the store orders them.
        usort($entries, static fn (SitemapEntry $a, SitemapEntry $b): int => [$a->type->collection(), $a->slug] <=> [$b->type->collection(), $b->slug]);

        return $entries;
    }

    public function countOwn(ContentType $type, Language $language): int
    {
        $this->countOwnCalls[] = ['type' => $type, 'language' => $language];

        return count(array_filter(
            $this->documents,
            static fn (PublishedDocument $document): bool => $document->type === $type && $document->lang === $language,
        ));
    }

    /** The member of the group of $own that a reader of $requested gets, as the store serves it. */
    private function serve(PublishedDocument $own, Language $requested): PublishedDocument
    {
        $members = array_values(array_filter(
            $this->documents,
            static fn (PublishedDocument $document): bool => $document->type === $own->type && $document->translationKey === $own->translationKey,
        ));
        $byId = [];
        foreach ($members as $member) {
            $byId[$member->id] = $member;
        }

        $choice = GroupResolver::resolve($requested, self::member($own), array_map(self::member(...), $members));
        $served = $byId[$choice['served']->id] ?? $own;

        return $served->withTranslation($choice['translations'], $choice['isFallback'], $requested);
    }

    private static function member(PublishedDocument $document): GroupMember
    {
        return new GroupMember($document->id, $document->lang, $document->slug);
    }
}
