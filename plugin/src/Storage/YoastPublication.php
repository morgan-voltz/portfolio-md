<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Storage;

use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Meta\InternalMetaRegistrar;
use Voltz\PortfolioMd\PostTypes\PostTypeRegistrar;
use Voltz\PortfolioMd\Service\ContentCatalog;
use Voltz\PortfolioMd\Service\PublishedLanguages;

/**
 * What Yoast must not index or list about a closed language (ADR-022, point
 * 4). Hooked at load, harmless without Yoast (nobody applies the filters):
 *   - wpseo_robots: every view of a closed language is `noindex, follow`.
 *     For a document the language is the one the plugin stores
 *     (`_portfolio_lang`); for the home, the archives and the other views it
 *     is the language of the URL of the request, the one Polylang resolved,
 *     which is reading the routing of the request, not deciding the
 *     language of a document. The archive of a kind of document (the
 *     `post-type-archive` of Yoast) is `noindex, follow` as well while its
 *     language has no document of that kind of its own: the page is then
 *     only French cards under a foreign URL (lot 5c), and the rule lifts
 *     itself at the first translation;
 *   - wpseo_sitemap_entry: an entry whose URL starts with the prefix of a
 *     closed language is dropped (an empty entry is skipped by Yoast);
 *   - wpseo_sitemap_<type>_content, at priority 20: the per-language
 *     archive blocks Polylang concatenates at priority 10 (`/de/projets/`)
 *     are removed for closed languages, the lot 5a spike having shown that
 *     wpseo_sitemap_entry never sees them, and for an open language without
 *     document of that kind of its own, whose archive is `noindex` (lot 5c):
 *     the sitemap and the robots say the same thing.
 * Opening or closing a language clears the sitemap cache through the
 * option store, so the sitemap follows at once.
 */
final class YoastPublication
{
    /** The directives of a view that is served but not indexed: a closed language, or an archive without own documents. */
    public const CLOSED_ROBOTS = 'noindex, follow';

    /** The object type Yoast gives the indexable of a post type archive, its sub type being the post type. */
    public const POST_TYPE_ARCHIVE = 'post-type-archive';

    /** @var list<string> the post types whose sitemap gets Polylang's per-language archive or home blocks */
    public const SITEMAP_TYPES = [PostTypeRegistrar::ARTICLE, PostTypeRegistrar::PROJECT, 'post'];

    /** @var callable(): string */
    private $currentLanguage;

    /** @var callable(string): string */
    private $homeUrl;

    /** @var callable(): string */
    private $currentFilter;

    /**
     * @param ContentCatalog                $catalog         what the site publishes, asked whether a language has documents of its own
     * @param callable(): string|null       $currentLanguage the language of the request; Polylang's when null
     * @param callable(string): string|null $homeUrl         `home_url()` when null
     * @param callable(): string|null       $currentFilter   the name of the filter being applied; WordPress's when null
     */
    public function __construct(
        private readonly PublishedLanguages $published,
        private readonly ContentCatalog $catalog,
        ?callable $currentLanguage = null,
        ?callable $homeUrl = null,
        ?callable $currentFilter = null,
    ) {
        $this->currentLanguage = $currentLanguage ?? static fn (): string => function_exists('pll_current_language') ? (string) pll_current_language() : '';
        $this->homeUrl = $homeUrl ?? static fn (string $path): string => (string) home_url($path);
        $this->currentFilter = $currentFilter ?? static fn (): string => function_exists('current_filter') ? (string) current_filter() : '';
    }

    public function register(): void
    {
        add_filter('wpseo_robots', [$this, 'robots'], 10, 2);
        add_filter('wpseo_sitemap_entry', [$this, 'sitemapEntry'], 10, 3);
        foreach (self::SITEMAP_TYPES as $type) {
            add_filter("wpseo_sitemap_{$type}_content", [$this, 'stripUnindexedArchives'], 20);
        }
    }

    /**
     * @param mixed $robots       the directives Yoast computed, a string such as "index, follow, max-snippet:-1"
     * @param mixed $presentation the Indexable_Presentation of the view, whose model names the object
     */
    public function robots(mixed $robots, mixed $presentation = null): mixed
    {
        $language = $this->viewLanguage($presentation);
        if ($language === null) {
            return $robots;
        }
        if (!$this->published->isPublished($language)) {
            return self::noindex($robots);
        }
        $archiveOf = $this->archiveType($presentation);
        if ($archiveOf !== null && $this->catalog->countOwn($archiveOf, $language) === 0) {
            return self::noindex($robots);
        }

        return $robots;
    }

    /**
     * The directives of a view served but not indexed: `noindex`, keeping a
     * `nofollow` the site owner set in Yoast, since Yoast re-parses the
     * string and would otherwise read `follow` again.
     */
    private static function noindex(mixed $robots): string
    {
        return is_string($robots) && str_contains($robots, 'nofollow') ? 'noindex, nofollow' : self::CLOSED_ROBOTS;
    }

    /**
     * @param mixed $url the entry, an array with a `loc`, or anything else Yoast hands over
     */
    public function sitemapEntry(mixed $url, mixed $type = null, mixed $object = null): mixed
    {
        $loc = is_array($url) ? (string) ($url['loc'] ?? '') : '';
        if ($loc === '') {
            return $url;
        }
        foreach ($this->closedPrefixes() as $prefix) {
            if (str_starts_with($loc, $prefix)) {
                return [];
            }
        }

        return $url;
    }

    /**
     * The raw XML Polylang appended to the sitemap of a kind of document:
     * every `<url>` under the prefix of a closed language goes, and the
     * archive URL of an open language without document of that kind of its
     * own as well, since that archive is `noindex`. The kind comes from the
     * name of the filter being applied, `wpseo_sitemap_<type>_content`.
     */
    public function stripUnindexedArchives(mixed $xml): string
    {
        $xml = (string) $xml;
        foreach ($this->closedPrefixes() as $prefix) {
            $xml = self::stripBlocks($xml, $prefix, false);
        }
        $type = $this->sitemapType();
        if ($type !== null && $type->urlBase() !== '') {
            foreach (Language::cases() as $language) {
                if ($this->published->isPublished($language) && $this->catalog->countOwn($type, $language) === 0) {
                    $xml = self::stripBlocks($xml, ($this->homeUrl)("/{$language->value}/{$type->urlBase()}/"), true);
                }
            }
        }

        return $xml;
    }

    /** Removes the `<url>` blocks whose `<loc>` is $loc, or starts with it when not exact. */
    private static function stripBlocks(string $xml, string $loc, bool $exact): string
    {
        return (string) preg_replace('#<url>\s*<loc>' . preg_quote($loc, '#') . ($exact ? '' : '[^<]*') . '</loc>.*?</url>\s*#s', '', $xml);
    }

    /** The kind of document whose sitemap is being built, read in the name of the filter; null for a kind the plugin does not serve. */
    private function sitemapType(): ?ContentType
    {
        return preg_match('/^wpseo_sitemap_(.+)_content$/', ($this->currentFilter)(), $matches) === 1 ? PostTypeRegistrar::contentTypeOf($matches[1]) : null;
    }

    /** The kind of document whose archive the view is, when it is one the plugin knows. */
    private function archiveType(mixed $presentation): ?ContentType
    {
        $model = self::model($presentation);
        if ($model === null || ($model->object_type ?? null) !== self::POST_TYPE_ARCHIVE) {
            return null;
        }
        $subType = $model->object_sub_type ?? null;

        return is_string($subType) ? PostTypeRegistrar::contentTypeOf($subType) : null;
    }

    private static function model(mixed $presentation): ?object
    {
        return is_object($presentation) && isset($presentation->model) && is_object($presentation->model) ? $presentation->model : null;
    }

    private function viewLanguage(mixed $presentation): ?Language
    {
        $model = self::model($presentation);
        if ($model !== null && ($model->object_type ?? null) === 'post' && (int) ($model->object_id ?? 0) > 0) {
            $stored = get_post_meta((int) $model->object_id, InternalMetaRegistrar::LANG, true);
            if (is_string($stored) && Language::tryFrom($stored) !== null) {
                return Language::from($stored);
            }
        }

        return Language::tryFrom(($this->currentLanguage)());
    }

    /**
     * @return list<string> the home URL of every closed language, with its trailing slash
     */
    private function closedPrefixes(): array
    {
        $prefixes = [];
        foreach (Language::cases() as $language) {
            if (!$this->published->isPublished($language)) {
                $prefixes[] = ($this->homeUrl)("/{$language->value}/");
            }
        }

        return $prefixes;
    }
}
