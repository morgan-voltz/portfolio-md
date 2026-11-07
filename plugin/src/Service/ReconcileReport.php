<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use Voltz\PortfolioMd\Domain\ContentType;

/**
 * What a reconciliation of the languages found (TranslationReconciler): the
 * counts, and one line per difference between what the plugin stores and
 * what the multilingual plugin holds, fixed or not.
 */
final class ReconcileReport
{
    public const LANGUAGE = 'language';
    public const GROUP = 'group';
    public const STALE_GROUP = 'stale-group';
    public const DUPLICATE = 'duplicate';

    /** Posts with a stored language, compared with the multilingual plugin. */
    public int $checked = 0;

    /** Posts without stored language (made by hand, or not imported since the plugin stores languages), left alone. */
    public int $skipped = 0;

    public int $languageFixes = 0;

    public int $groupFixes = 0;

    /** Differences that cannot be fixed by the reconciliation (two live posts sharing a key and a language). */
    public int $failed = 0;

    /** @var list<array{postId: int, type: string, slug: string, kind: string, detail: string, fixed: bool}> */
    public array $differences = [];

    public function __construct(public readonly bool $dryRun)
    {
    }

    public function add(int $postId, ContentType $type, string $slug, string $kind, string $detail, bool $fixed): void
    {
        $this->differences[] = ['postId' => $postId, 'type' => $type->value, 'slug' => $slug, 'kind' => $kind, 'detail' => $detail, 'fixed' => $fixed];
    }

    public function hasDifferences(): bool
    {
        return $this->differences !== [];
    }

    public function summary(): string
    {
        $languages = count(array_filter($this->differences, static fn (array $d): bool => $d['kind'] === self::LANGUAGE));
        $groups = count(array_filter($this->differences, static fn (array $d): bool => in_array($d['kind'], [self::GROUP, self::STALE_GROUP], true)));

        return sprintf(
            '%d post(s) checked, %d skipped (no stored language), %d language difference(s), %d group difference(s), %d fixed, %d failed%s',
            $this->checked,
            $this->skipped,
            $languages,
            $groups,
            $this->languageFixes + $this->groupFixes,
            $this->failed,
            $this->dryRun ? ' (dry run: nothing written)' : '',
        );
    }

    /**
     * @return array{dryRun: bool, counts: array<string, int>, differences: list<array{postId: int, type: string, slug: string, kind: string, detail: string, fixed: bool}>}
     */
    public function toArray(): array
    {
        return [
            'dryRun' => $this->dryRun,
            'counts' => [
                'checked' => $this->checked,
                'skipped' => $this->skipped,
                'languageFixes' => $this->languageFixes,
                'groupFixes' => $this->groupFixes,
                'failed' => $this->failed,
            ],
            'differences' => $this->differences,
        ];
    }
}
