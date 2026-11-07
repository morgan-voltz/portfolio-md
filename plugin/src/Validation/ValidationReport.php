<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Validation;

/**
 * What ContentValidator found in a content tree: which layout the tree has,
 * the errors of each file (and of the tree itself, under ContentValidator::TREE)
 * and, for the files that passed, a short note for the report line.
 */
final readonly class ValidationReport
{
    /** The former layout, content/<folder>/<slug>.md, every file being French: accepted until the content migration. */
    public const LEGACY = 'legacy';

    /** The layout of ADR-020, content/<lang>/<folder>/<slug>.md. */
    public const LANGUAGES = 'languages';

    /**
     * @param array<string, list<string>> $errors by path relative to the content directory, in French
     * @param array<string, string>       $notes  by path, for the files without error: "123 mots, 4 compétences, 3 tags"
     */
    public function __construct(
        public string $layout,
        public array $errors,
        public array $notes,
    ) {
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Every path the report speaks about, the tree first, then the files in order.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        $paths = array_unique([...array_keys($this->errors), ...array_keys($this->notes)]);
        sort($paths);
        if (in_array(ContentValidator::TREE, $paths, true)) {
            $paths = [ContentValidator::TREE, ...array_values(array_diff($paths, [ContentValidator::TREE]))];
        }

        return array_values($paths);
    }
}
