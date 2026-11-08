<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use Voltz\PortfolioMd\Domain\Language;

/**
 * The rules of ADR-021 that choose which member of a translation group a
 * reader gets. Pure, and shared by both catalogs so that the WordPress
 * store and the in-memory store of the tests cannot disagree on them.
 *
 * A group is every published document of one kind under one translation
 * key, in every language. The reader asks for a language; the document
 * served is the member in that language, else the first member found along
 * the fallback chain of the language (Language::fallbackChain()), else the
 * document the reader named itself, flagged as a fallback. Two members in
 * the same language (a sheet copied without changing its key) are an anomaly
 * `wp portfolio translations reconcile` reports; here the oldest post wins,
 * the rule of ADR-020, except that the document the reader named is always
 * served under its own slug, never replaced by an older twin.
 */
final class GroupResolver
{
    private function __construct()
    {
    }

    /**
     * One member per language, the oldest post when two share a language,
     * in the order of the languages of the site (fr, de, en).
     *
     * @param iterable<GroupMember> $members
     *
     * @return array<string, GroupMember> language value => member
     */
    public static function winners(iterable $members): array
    {
        $oldest = [];
        foreach ($members as $member) {
            $current = $oldest[$member->lang->value] ?? null;
            if ($current === null || $member->id < $current->id) {
                $oldest[$member->lang->value] = $member;
            }
        }

        $winners = [];
        foreach (Language::cases() as $language) {
            if (isset($oldest[$language->value])) {
                $winners[$language->value] = $oldest[$language->value];
            }
        }

        return $winners;
    }

    /**
     * The first language of the fallback chain of the requested one that the
     * group holds, or null when it holds none of them (a German-only document
     * asked in French: the chain of French is French alone).
     *
     * @param array<string, GroupMember> $winners as winners() gives them
     */
    public static function pick(Language $requested, array $winners): ?Language
    {
        foreach ($requested->fallbackChain() as $language) {
            if (isset($winners[$language->value])) {
                return $language;
            }
        }

        return null;
    }

    /**
     * Which document the reader gets, with the `translations` map and the
     * `is_fallback` flag of ADR-021.
     *
     * @param GroupMember           $own     the document the reader named: the French anchor of a list
     *                                       entry, or the document under the slug of a detail route
     * @param iterable<GroupMember> $members every published document of the group, $own included
     *
     * @return array{served: GroupMember, translations: array<string, string>, isFallback: bool}
     *                                       the member to serve; language value => slug of every member,
     *                                       the served one included, in the order fr, de, en; whether
     *                                       the served language differs from the requested one
     */
    public static function resolve(Language $requested, GroupMember $own, iterable $members): array
    {
        $winners = self::winners($members);
        $picked = self::pick($requested, $winners);

        // The reader named a document: when its language is the one picked, that document is
        // served, even if an older twin in the same language won the group.
        $served = $picked === null || $picked === $own->lang ? $own : $winners[$picked->value];

        $slugs = array_map(static fn (GroupMember $member): string => $member->slug, $winners);
        $slugs[$served->lang->value] = $served->slug;

        $translations = [];
        foreach (Language::cases() as $language) {
            if (isset($slugs[$language->value])) {
                $translations[$language->value] = $slugs[$language->value];
            }
        }

        return ['served' => $served, 'translations' => $translations, 'isFallback' => $served->lang !== $requested];
    }
}
