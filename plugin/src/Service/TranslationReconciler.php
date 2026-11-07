<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\Language;

/**
 * The guarantee of ADR-020 against the multilingual plugin's own screens
 * (its language box, its column, quick edit, bulk edit): the language and
 * the translation groups the plugin stores are the truth, and this service
 * pushes them back wherever Polylang drifted, reporting every difference.
 *
 * Two passes per kind of document. The language pass compares the stored
 * language of every live post with the one Polylang gives it, and sets it
 * again when they differ. The group pass takes every translation key held by
 * a live post, computes the group the import would link (TranslationGroup,
 * trash excluded) and compares it with the view of every member: a member
 * that sees another group is a drift, repaired by linking the whole group
 * again; a post alone under its key that still sees a group (its sibling was
 * retired) is a stale group, dissolved by linking the group of one. Two live
 * posts sharing a key and a language cannot be reconciled and are reported
 * as such. A post without stored language is skipped, never adopted from
 * Polylang: the plugin does not read the language to decide.
 *
 * In a dry run nothing is written and the view of a member whose language
 * drifted may look wrong until the language is fixed: the report of a real
 * run is the one to trust.
 */
final class TranslationReconciler
{
    public function __construct(
        private readonly DocumentLister $documents,
        private readonly ContentRepository $repository,
        private readonly LanguageGateway $gateway,
        private readonly LanguageInspector $inspector,
    ) {
    }

    /**
     * @throws LanguageGatewayException when the multilingual plugin does not answer or misses one of the site's languages
     */
    public function reconcile(bool $dryRun): ReconcileReport
    {
        $configured = $this->inspector->configuredLanguages();
        if ($configured === null) {
            throw LanguageGatewayException::apiUnavailable();
        }
        foreach (Language::values() as $slug) {
            if (!in_array($slug, $configured, true)) {
                throw LanguageGatewayException::languageNotConfigured($slug, $configured);
            }
        }

        $report = new ReconcileReport($dryRun);
        foreach (ContentType::cases() as $type) {
            $live = [];
            $slugs = [];
            foreach ($this->documents->listDocuments($type) as $document) {
                if ($document->inTrash) {
                    continue;
                }
                $slugs[$document->id] = $document->documentSlug ?? '';
                if ($document->lang === null) {
                    $report->skipped++;
                    continue;
                }
                $report->checked++;
                $live[] = $document;
                $this->reconcileLanguage($document, $type, $slugs[$document->id], $report);
            }

            $keys = array_values(array_unique(array_filter(array_map(static fn (StoredContent $d): ?string => $d->translationKey, $live))));
            foreach ($keys as $key) {
                $this->reconcileGroup($type, $key, $slugs, $report);
            }
        }

        return $report;
    }

    private function reconcileLanguage(StoredContent $document, ContentType $type, string $slug, ReconcileReport $report): void
    {
        $stored = $document->lang;
        if ($stored === null) {
            return;
        }
        $actual = $this->inspector->languageOf($document->id);
        if ($actual === $stored->value) {
            return;
        }

        $fixed = false;
        if (!$report->dryRun) {
            $this->gateway->setLanguage($document->id, $stored);
            $report->languageFixes++;
            $fixed = true;
        }
        $report->add($document->id, $type, $slug, ReconcileReport::LANGUAGE, sprintf('stored "%s", Polylang says %s', $stored->value, $actual === null ? 'none' : "\"{$actual}\""), $fixed);
    }

    /**
     * @param array<int, string> $slugs post id => slug, for the report
     */
    private function reconcileGroup(ContentType $type, string $key, array $slugs, ReconcileReport $report): void
    {
        $members = $this->repository->findByKey($type, $key);
        $seen = [];
        foreach ($members as $member) {
            if ($member->lang === null) {
                continue;
            }
            if (isset($seen[$member->lang->value])) {
                $report->add($member->id, $type, $slugs[$member->id] ?? '', ReconcileReport::DUPLICATE, sprintf('shares the key "%s" and the language "%s" with post %d: give one of them its own key', $key, $member->lang->value, $seen[$member->lang->value]), false);
                $report->failed++;
                continue;
            }
            $seen[$member->lang->value] = $member->id;
        }

        $expected = TranslationGroup::fromStored($members);
        if (count($expected) >= 2) {
            foreach ($expected as $postId) {
                $view = $this->inspector->translationsOf($postId);
                if (self::sameGroup($view, $expected)) {
                    continue;
                }
                $fixed = false;
                if (!$report->dryRun) {
                    $this->gateway->linkTranslations($expected);
                    $report->groupFixes++;
                    $fixed = true;
                }
                $report->add($postId, $type, $slugs[$postId] ?? '', ReconcileReport::GROUP, sprintf('expected %s, Polylang shows %s from this post', self::describe($expected), self::describe($view)), $fixed);

                return;
            }

            return;
        }

        if (count($expected) === 1) {
            $postId = (int) reset($expected);
            $view = $this->inspector->translationsOf($postId);
            if ($view === []) {
                return;
            }
            $fixed = false;
            if (!$report->dryRun) {
                $this->gateway->linkTranslations($expected);
                $report->groupFixes++;
                $fixed = true;
            }
            $report->add($postId, $type, $slugs[$postId] ?? '', ReconcileReport::STALE_GROUP, sprintf('alone under the key "%s" but still grouped with %s', $key, self::describe($view)), $fixed);
        }
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
     * @param array<string, int> $group
     */
    private static function describe(array $group): string
    {
        if ($group === []) {
            return 'no group';
        }
        $parts = [];
        foreach ($group as $lang => $id) {
            $parts[] = "{$lang}: {$id}";
        }

        return '{' . implode(', ', $parts) . '}';
    }
}
