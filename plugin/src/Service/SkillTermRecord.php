<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

/**
 * What the synchronizer needs to know about an existing term of the `skill`
 * taxonomy, whatever stores it.
 */
final readonly class SkillTermRecord
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public string $description,
        public int $parentId,
        public ?string $code,
    ) {
    }
}
