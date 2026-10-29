<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

/**
 * One file of the content repository as a Git synchronization hands it
 * over (01-plugin-php.md §6.2): its path relative to `content/`
 * (`projects/<slug>.md`, `pages/<slug>.md`, `skills.yaml`), its whole text,
 * or the fact that it was deleted, in which case it has no text.
 */
final readonly class SyncFile
{
    public function __construct(
        public string $path,
        public ?string $content,
        public bool $deleted = false,
    ) {
    }
}
