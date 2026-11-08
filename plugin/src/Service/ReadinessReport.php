<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use Voltz\PortfolioMd\Domain\Language;

/**
 * What a language holds and what it still misses before it opens
 * (ADR-022, point 2): one line per requirement of the grid, in the order
 * LanguageReadiness checks them, ready to be printed or encoded.
 */
final class ReadinessReport
{
    /** @var list<array{label: string, ok: bool, detail: string}> */
    public array $checks = [];

    public function __construct(public readonly Language $language)
    {
    }

    public function add(string $label, bool $ok, string $detail): void
    {
        $this->checks[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
    }

    public function isReady(): bool
    {
        foreach ($this->checks as $check) {
            if (!$check['ok']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string> the labels of the requirements not met, in order
     */
    public function missing(): array
    {
        $missing = [];
        foreach ($this->checks as $check) {
            if (!$check['ok']) {
                $missing[] = $check['label'];
            }
        }

        return $missing;
    }

    /**
     * @return array{language: string, ready: bool, checks: list<array{label: string, ok: bool, detail: string}>}
     */
    public function toArray(): array
    {
        return ['language' => $this->language->value, 'ready' => $this->isReady(), 'checks' => $this->checks];
    }
}
