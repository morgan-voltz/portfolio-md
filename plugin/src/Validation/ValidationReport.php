<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Validation;

/**
 * What ContentValidator found in a content tree: the errors of each file (and
 * of the tree itself, under ContentValidator::TREE) and, for the files that
 * passed, a short note for the report line.
 */
final readonly class ValidationReport
{
    /**
     * @param array<string, list<string>> $errors by path relative to the content directory, in French
     * @param array<string, string>       $notes  by path, for the files without error: "123 mots, 4 compétences, 3 tags"
     */
    public function __construct(
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
