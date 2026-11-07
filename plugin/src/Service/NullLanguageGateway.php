<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use Voltz\PortfolioMd\Domain\Language;

/**
 * The language gateway that does nothing: the language lives in the plugin's
 * metas only. The default of the import service when no adapter is injected
 * (the tests, the smoke scripts); the composition root wires the Polylang
 * adapter, which behaves the same while Polylang is absent.
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
