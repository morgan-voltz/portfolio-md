<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Storage;

use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Service\PublishedLanguages;

/**
 * What Polylang must not announce about a closed language (ADR-022, point
 * 3): the language is served, but no visitor is sent to it and no page
 * advertises it. Four filters, hooked at load like PolylangPostTypes (a
 * filter only Polylang applies is harmless without it), each asking
 * Service\PublishedLanguages at call time:
 *   - pll_languages_for_browser_preferences: a closed language is never
 *     the best match of the browser's preferences on `/`;
 *   - pll_preferred_language: the cookie Polylang writes on every visit
 *     bypasses the previous filter, so a cookie or a browser value naming
 *     a closed language is refused, and Polylang falls back to the default
 *     language;
 *   - pll_translation_url: an empty translation URL for a closed language
 *     removes it from the hreflang set (Polylang counts the URLs before
 *     emitting anything), from `og:locale:alternate` and from any switcher
 *     that hides the languages without translation, the current language
 *     included, with no exception for logged-in users (a page cache would
 *     leak it);
 *   - pll_rel_hreflang_attributes: `x-default` points to the French page
 *     wherever Polylang emits a set and did not add one itself (it does on
 *     the home page, towards the root of the site).
 * The spike of lot 5a observed each of these on a real site.
 */
final class PolylangPublication
{
    public function __construct(private readonly PublishedLanguages $published)
    {
    }

    public function register(): void
    {
        add_filter('pll_languages_for_browser_preferences', [$this, 'browserLanguages']);
        add_filter('pll_preferred_language', [$this, 'preferredLanguage'], 10, 2);
        add_filter('pll_translation_url', [$this, 'translationUrl'], 10, 2);
        add_filter('pll_rel_hreflang_attributes', [$this, 'hreflangs']);
    }

    /**
     * @param array<int, object> $languages PLL_Language objects, each with a `slug`
     *
     * @return array<int, object>
     */
    public function browserLanguages(array $languages): array
    {
        return array_values(array_filter(
            $languages,
            fn (mixed $language): bool => is_object($language) && isset($language->slug) && $this->isOpen((string) $language->slug),
        ));
    }

    /**
     * @param mixed $slug   the language slug Polylang found in the cookie or the browser, or false
     * @param mixed $cookie the cookie value, or false
     */
    public function preferredLanguage(mixed $slug, mixed $cookie = false): mixed
    {
        return is_string($slug) && !$this->isOpen($slug) ? false : $slug;
    }

    public function translationUrl(mixed $url, mixed $slug): mixed
    {
        return is_string($slug) && !$this->isOpen($slug) ? '' : $url;
    }

    /**
     * @param array<string, string> $hreflangs language code => URL
     *
     * @return array<string, string>
     */
    public function hreflangs(array $hreflangs): array
    {
        $default = Language::default()->value;
        if (!isset($hreflangs['x-default']) && isset($hreflangs[$default])) {
            $hreflangs['x-default'] = $hreflangs[$default];
        }

        return $hreflangs;
    }

    /** A slug the plugin does not know is not its business: Polylang keeps it. */
    private function isOpen(string $slug): bool
    {
        $language = Language::tryFrom($slug);

        return $language === null || $this->published->isPublished($language);
    }
}
