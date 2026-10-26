<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use RuntimeException;
use Voltz\PortfolioMd\Domain\ContentType;

/**
 * A document that is well formed but cannot be imported as it stands.
 */
final class ImportException extends RuntimeException
{
    public static function unsupportedType(ContentType $type): self
    {
        return new self("Documents of type \"{$type->value}\" cannot be imported yet: only articles and projects can.");
    }

    public static function slugMismatch(string $slug, string $fileName): self
    {
        return new self(
            "The slug \"{$slug}\" does not match the file name \"{$fileName}\": a file is named after its slug (content/README.md)."
            . ' A sheet copied from another one still carries the slug of its model and would overwrite it.',
        );
    }

    public static function inTrash(ContentType $type, string $slug, int $postId): self
    {
        return new self(
            "Post {$postId} ({$type->value}/{$slug}) is in the trash: restore it or delete it permanently before importing this document,"
            . ' otherwise a second post would be created next to it.',
        );
    }

    /**
     * @param list<string> $codes
     */
    public static function unknownSkills(array $codes): self
    {
        return new self(
            'Unknown competency code(s): ' . implode(', ', $codes)
            . '. Add them to content/skills.yaml and run `wp portfolio skills sync` first.',
        );
    }
}
