<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Support;

use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Service\LanguageGateway;
use Voltz\PortfolioMd\Service\LanguageInspector;

/**
 * LanguageGateway that remembers what it was told, so that the tests of the
 * import service can read back which language every post was given and
 * which groups were linked, in order. It plays the multilingual plugin too
 * (LanguageInspector): what it was told is what it holds, and a test may
 * prime what Polylang would hold ($polylangLanguages, $polylangGroups) to
 * make the reconciliation find a drift.
 */
final class InMemoryLanguageGateway implements LanguageGateway, LanguageInspector
{
    /** @var array<int, Language> post id => language last set */
    public array $languages = [];

    /** @var list<array{int, Language}> every setLanguage() call, in order */
    public array $calls = [];

    /** @var list<array<string, int>> every linkTranslations() call, in order */
    public array $links = [];

    /** @var list<string>|null what the site is configured with; null plays an absent Polylang */
    public ?array $configuredLanguages = ['fr', 'de', 'en'];

    /** @var array<int, string> post id => language slug the multilingual plugin holds */
    public array $polylangLanguages = [];

    /** @var array<int, array<string, int>> post id => the group as seen from this post */
    public array $polylangGroups = [];

    public function setLanguage(int $postId, Language $lang): void
    {
        $this->languages[$postId] = $lang;
        $this->calls[] = [$postId, $lang];
        $this->polylangLanguages[$postId] = $lang->value;
    }

    public function linkTranslations(array $postIdsByLanguage): void
    {
        $this->links[] = $postIdsByLanguage;
        foreach ($postIdsByLanguage as $postId) {
            $this->polylangGroups[$postId] = count($postIdsByLanguage) > 1 ? $postIdsByLanguage : [];
        }
    }

    public function configuredLanguages(): ?array
    {
        return $this->configuredLanguages;
    }

    public function languageOf(int $postId): ?string
    {
        return $this->polylangLanguages[$postId] ?? null;
    }

    public function translationsOf(int $postId): array
    {
        $view = $this->polylangGroups[$postId] ?? [];

        return count($view) <= 1 ? [] : $view;
    }
}
