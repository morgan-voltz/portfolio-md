<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use Voltz\PortfolioMd\Domain\Language;

/**
 * The language gateway of a site without a multilingual plugin: the language
 * lives in the plugin's metas only. Wired until the Polylang adapter of lot 2.
 */
final class NullLanguageGateway implements LanguageGateway
{
    public function setLanguage(int $postId, Language $lang): void
    {
    }

    public function linkTranslations(array $postIdsByLanguage): void
    {
    }
}
