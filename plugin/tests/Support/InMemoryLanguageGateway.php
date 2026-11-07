<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Support;

use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Service\LanguageGateway;

/**
 * LanguageGateway that remembers what it was told, so that the tests of the
 * import service can read back which language every post was given and
 * which groups were linked, in order.
 */
final class InMemoryLanguageGateway implements LanguageGateway
{
    /** @var array<int, Language> post id => language last set */
    public array $languages = [];

    /** @var list<array{int, Language}> every setLanguage() call, in order */
    public array $calls = [];

    /** @var list<array<string, int>> every linkTranslations() call, in order */
    public array $links = [];

    public function setLanguage(int $postId, Language $lang): void
    {
        $this->languages[$postId] = $lang;
        $this->calls[] = [$postId, $lang];
    }

    public function linkTranslations(array $postIdsByLanguage): void
    {
        $this->links[] = $postIdsByLanguage;
    }
}
