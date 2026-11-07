<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Storage;

use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Service\LanguageGateway;
use Voltz\PortfolioMd\Service\LanguageGatewayException;
use Voltz\PortfolioMd\Service\LanguageInspector;

/**
 * The language gateway of a site that runs Polylang (ADR-020, lot 2): the
 * language the plugin stores for a post is pushed to Polylang's `language`
 * taxonomy, and the posts of one translation key are tied together in its
 * `post_translations` taxonomy.
 *
 * Polylang's API is checked at every call, not once: the adapter is built
 * when the plugin loads, before Polylang's `api.php` exists, and a front
 * request on a site without languages gets no Polylang context at all. When
 * the API is absent the adapter does nothing, like NullLanguageGateway; the
 * doors behave as on a site without a multilingual plugin.
 *
 * Three facts of Polylang 3.x shape this code:
 *   - `pll_set_post_language()` with a slug the site does not have clears
 *     the language and answers true: the slug is checked first, and the
 *     language is read back after the write;
 *   - `pll_set_post_language()` answers false when the language was already
 *     set, which is not an error;
 *   - `pll_save_post_translations()` silently drops a member whose language
 *     is not the key it is given under, needs a language on the first
 *     member, and edits the description of a group of one without updating
 *     the term: every member gets its language first, the French post goes
 *     first, every member's view is read back, and a view of one post is
 *     taken as "no group".
 */
final class PolylangLanguageGateway implements LanguageGateway, LanguageInspector
{
    /** @var callable(): bool */
    private $available;

    /**
     * @param callable(): bool|null $available whether Polylang's API answers, checked at every call; the
     *                                         default looks for its functions and its model
     */
    public function __construct(?callable $available = null)
    {
        $this->available = $available ?? self::polylangAnswers(...);
    }

    public function setLanguage(int $postId, Language $lang): void
    {
        if (!($this->available)()) {
            return;
        }

        $configured = $this->configuredLanguages() ?? [];
        if (!in_array($lang->value, $configured, true)) {
            throw LanguageGatewayException::languageNotConfigured($lang->value, $configured);
        }
        if ($this->languageOf($postId) === $lang->value) {
            return;
        }

        pll_set_post_language($postId, $lang->value);

        if ($this->languageOf($postId) !== $lang->value) {
            throw LanguageGatewayException::languageNotApplied($postId, $lang->value);
        }
    }

    public function linkTranslations(array $postIdsByLanguage): void
    {
        if (!($this->available)() || $postIdsByLanguage === []) {
            return;
        }

        $configured = $this->configuredLanguages() ?? [];
        foreach ($postIdsByLanguage as $slug => $postId) {
            $language = Language::tryFrom((string) $slug);
            if ($language === null || !in_array($slug, $configured, true)) {
                throw LanguageGatewayException::languageNotConfigured((string) $slug, $configured);
            }
            $this->setLanguage($postId, $language);
        }

        if (count($postIdsByLanguage) === 1) {
            // A group of one means "dissolve what remains": nothing to do when the post is already alone.
            if ($this->translationsOf((int) reset($postIdsByLanguage)) === []) {
                return;
            }
            pll_save_post_translations($postIdsByLanguage);

            return;
        }

        if ($this->everyMemberSees($postIdsByLanguage)) {
            return;
        }

        pll_save_post_translations(self::frenchFirst($postIdsByLanguage));

        foreach ($postIdsByLanguage as $postId) {
            $view = $this->translationsOf($postId);
            if (!self::sameGroup($view, $postIdsByLanguage)) {
                throw LanguageGatewayException::groupNotApplied($postIdsByLanguage, $view);
            }
        }
    }

    public function configuredLanguages(): ?array
    {
        if (!($this->available)()) {
            return null;
        }

        return array_values(array_map(strval(...), pll_languages_list()));
    }

    public function languageOf(int $postId): ?string
    {
        if (!($this->available)()) {
            return null;
        }
        $slug = pll_get_post_language($postId);

        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    public function translationsOf(int $postId): array
    {
        if (!($this->available)()) {
            return [];
        }
        $view = [];
        foreach (pll_get_post_translations($postId) as $slug => $id) {
            $view[(string) $slug] = (int) $id;
        }

        return count($view) <= 1 ? [] : $view;
    }

    /** Polylang's API functions exist and its model is built: true under WP-CLI and in the admin once Polylang loaded. */
    private static function polylangAnswers(): bool
    {
        foreach (['PLL', 'pll_languages_list', 'pll_get_post_language', 'pll_set_post_language', 'pll_get_post_translations', 'pll_save_post_translations'] as $function) {
            if (!function_exists($function)) {
                return false;
            }
        }
        $polylang = PLL();

        return is_object($polylang) && isset($polylang->model);
    }

    /**
     * @param array<string, int> $group
     */
    private function everyMemberSees(array $group): bool
    {
        foreach ($group as $postId) {
            if (!self::sameGroup($this->translationsOf($postId), $group)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, int> $a
     * @param array<string, int> $b
     */
    private static function sameGroup(array $a, array $b): bool
    {
        ksort($a);
        ksort($b);

        return $a === $b;
    }

    /**
     * The French post first: `pll_save_post_translations()` reads the
     * language of the first member and refuses a group whose first member
     * has none; French, the language every group has, is the safe choice.
     *
     * @param array<string, int> $group
     *
     * @return array<string, int>
     */
    private static function frenchFirst(array $group): array
    {
        $default = Language::default()->value;
        if (!isset($group[$default])) {
            return $group;
        }

        return [$default => $group[$default]] + $group;
    }
}
