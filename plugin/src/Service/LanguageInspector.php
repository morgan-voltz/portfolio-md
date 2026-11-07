<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

/**
 * The read side of the language bridge (ADR-020, lot 2): what the
 * multilingual plugin currently holds for a post. The plugin never decides
 * from it; the reconciliation reads it to report what drifted from the
 * stored language and the stored keys, and to repair it through the
 * LanguageGateway.
 */
interface LanguageInspector
{
    /**
     * The language slugs the site is configured with, or null when no
     * multilingual plugin answers (absent, or not loaded for this request).
     *
     * @return list<string>|null
     */
    public function configuredLanguages(): ?array;

    /** The language slug the multilingual plugin gives this post, or null when it gives none. */
    public function languageOf(int $postId): ?string;

    /**
     * The translation group as seen from this post: language slug => post
     * id. Empty when the post is alone (a group of one is no group). The
     * post itself is not guaranteed to appear in its own view once a group
     * was edited from another member.
     *
     * @return array<string, int>
     */
    public function translationsOf(int $postId): array;
}
