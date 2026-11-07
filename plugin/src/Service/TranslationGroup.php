<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

/**
 * The rule that turns the posts of one translation key into the group a
 * language gateway receives: one post per language, the oldest when two
 * share a language (a sheet copied without changing its key), a post without
 * stored language left out (made by hand, or not imported since the plugin
 * stores languages).
 *
 * Shared by the import service, which links a group after every write, and
 * by the reconciliation of lot 2 (ADR-020), which compares what Polylang
 * holds with what the plugin stores.
 */
final class TranslationGroup
{
    private function __construct()
    {
    }

    /**
     * @param iterable<StoredContent> $contents oldest first, as ContentRepository::findByKey() gives them
     *
     * @return array<string, int> language value => post id, in the order of the first post of each language
     */
    public static function fromStored(iterable $contents): array
    {
        $byLanguage = [];
        foreach ($contents as $content) {
            if ($content->lang === null) {
                continue;
            }
            $byLanguage[$content->lang->value] ??= $content->id;
        }

        return $byLanguage;
    }
}
