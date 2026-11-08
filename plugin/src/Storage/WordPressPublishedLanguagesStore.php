<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Storage;

use Voltz\PortfolioMd\Service\PublishedLanguages;
use Voltz\PortfolioMd\Service\PublishedLanguagesStore;

/**
 * The option `portfolio_md_published_languages` (ADR-022, point 1): the
 * store of Service\PublishedLanguages, plus the two hooks that make the
 * option safe whoever writes it. The normalization filter runs on every
 * write, `wp option update` included, so that a hand-written value is
 * stored clean (known languages only, French always, in order); the write
 * actions clear the XML sitemap cache of Yoast, which is otherwise only
 * invalidated by a content change, so that opening or closing a language
 * takes effect in the sitemap at once. WordPress fires `update_option_*`
 * when the row exists and `add_option_*` when the first write creates it
 * (update_option() hands over to add_option() then), hence both. The hooks
 * are harmless when Yoast is absent. The option is an environment setting,
 * never content: it is not in Git, and the local stack may stay closed
 * while production opens.
 */
final class WordPressPublishedLanguagesStore implements PublishedLanguagesStore
{
    public const OPTION = 'portfolio_md_published_languages';

    /** @var callable(): void */
    private $clearSitemapCache;

    /**
     * @param callable(): void|null $clearSitemapCache what clears the sitemap cache; Yoast's own when null, nothing without Yoast
     */
    public function __construct(?callable $clearSitemapCache = null)
    {
        $this->clearSitemapCache = $clearSitemapCache ?? static function (): void {
            if (class_exists(\WPSEO_Sitemaps_Cache::class)) {
                \WPSEO_Sitemaps_Cache::clear();
            }
        };
    }

    public function register(): void
    {
        add_filter('pre_update_option_' . self::OPTION, [$this, 'normalize']);
        add_action('add_option_' . self::OPTION, [$this, 'clearSitemapCache']);
        add_action('update_option_' . self::OPTION, [$this, 'clearSitemapCache']);
    }

    public function read(): mixed
    {
        return get_option(self::OPTION, null);
    }

    public function write(array $slugs): void
    {
        update_option(self::OPTION, PublishedLanguages::normalize($slugs), false);
    }

    /**
     * Filter `pre_update_option_portfolio_md_published_languages`.
     *
     * @return list<string>
     */
    public function normalize(mixed $value): array
    {
        return PublishedLanguages::normalize($value);
    }

    /**
     * Actions `add_option_…` and `update_option_…` of the option: whatever
     * they pass is ignored.
     */
    public function clearSitemapCache(): void
    {
        ($this->clearSitemapCache)();
    }
}
