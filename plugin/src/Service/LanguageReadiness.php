<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\CorePages;
use Voltz\PortfolioMd\Domain\Language;

/**
 * The readiness grid of ADR-022 (point 2): what a language must hold before
 * it opens to the public. It reads the public catalog, so that "ready"
 * means "what the API and the site would serve": the four core pages
 * published in that language under their own slug (the catalog serves them
 * in that language, not as a fallback) and within their translation group;
 * a menu of their own bound to the navigation; and at least
 * MINIMUM_PROJECTS project sheets translated, the fil rouge of ADR-020. The
 * language of the site is always ready.
 *
 * The grid refuses nothing by itself: `wp portfolio languages publish`
 * reads it and refuses without --force; a direct `wp option update` is
 * still allowed, and safe.
 */
final class LanguageReadiness
{
    public const MINIMUM_PROJECTS = 3;

    public function __construct(
        private readonly ContentCatalog $catalog,
        private readonly MenuBindings $menus,
        private readonly int $minimumProjects = self::MINIMUM_PROJECTS,
    ) {
    }

    public function assess(Language $language): ReadinessReport
    {
        $report = new ReadinessReport($language);
        if ($language->isDefault()) {
            $report->add('language of the site', true, 'French is always open');

            return $report;
        }

        foreach (CorePages::keys() as $key) {
            $slug = CorePages::slug($key, $language);
            $page = $this->catalog->find(ContentType::Page, $slug, $language);
            if ($page === null) {
                $report->add("page {$key}", false, "no published page {$slug}");
            } elseif ($page->lang !== $language || $page->isFallback) {
                $report->add("page {$key}", false, "the page {$slug} is served in {$page->lang->value}, not in {$language->value}");
            } elseif ($page->translationKey !== $key) {
                $report->add("page {$key}", false, "the page {$slug} carries the translation key {$page->translationKey}, not {$key}");
            } else {
                $report->add("page {$key}", true, "page {$slug} published");
            }
        }

        $hasMenu = $this->menus->hasMenu($language);
        $report->add('menu', $hasMenu, $hasMenu ? 'a menu is bound to the navigation' : 'no menu bound to the navigation for this language');

        $translated = $this->translatedProjects($language);
        $report->add(
            "projects ({$this->minimumProjects} translated at least)",
            $translated >= $this->minimumProjects,
            "{$translated} translated sheet(s)",
        );

        return $report;
    }

    /**
     * The project sheets the catalog serves in this language itself, not as
     * a fallback: one page of anchors after another, so that the count is
     * right whatever the size of the collection.
     */
    private function translatedProjects(Language $language): int
    {
        $translated = 0;
        $page = 1;
        do {
            $result = $this->catalog->list(ContentType::Project, new CatalogQuery(page: $page, perPage: CatalogQuery::MAX_PER_PAGE, language: $language));
            foreach ($result->items as $document) {
                if ($document->lang === $language && !$document->isFallback) {
                    $translated++;
                }
            }
            $page++;
        } while ($page <= $result->totalPages());

        return $translated;
    }
}
