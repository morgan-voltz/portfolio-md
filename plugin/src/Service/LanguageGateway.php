<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use Voltz\PortfolioMd\Domain\Language;

/**
 * Port of the import service towards whatever tells WordPress the language
 * of a post and ties its translations together (ADR-020): Polylang in
 * production (Storage\PolylangLanguageGateway, which does nothing while
 * Polylang is absent), nothing at all when no adapter is injected
 * (NullLanguageGateway), a journal in the unit tests.
 *
 * The plugin stays the owner of the language: it stores it in its own metas
 * and pushes it through this port at every write; it never reads the
 * multilingual plugin to decide what is true. The reconciliation of lot 2
 * reads it through LanguageInspector, to report and repair only.
 * Both methods are idempotent: an adapter compares before it writes, so the
 * import may call them on every document, changed or not, and repair a
 * language that a failed earlier call left unset.
 */
interface LanguageGateway
{
    public function setLanguage(int $postId, Language $lang): void;

    /**
     * Ties the posts of one translation group together, one post per
     * language. The import never passes a group of one; the reconciliation
     * does, meaning "dissolve what remains of the group of this post".
     *
     * @param array<string, int> $postIdsByLanguage language value => post id
     */
    public function linkTranslations(array $postIdsByLanguage): void;
}
