<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use RuntimeException;

/**
 * What the language bridge could not do: a language the site does not
 * have, a language or a group the multilingual plugin did not take. Thrown
 * after the post was written, so a replay of the import heals it once the
 * site is configured; the doors report it like any other RuntimeException.
 */
final class LanguageGatewayException extends RuntimeException
{
    /**
     * @param list<string> $found
     */
    public static function languageNotConfigured(string $slug, array $found): self
    {
        return new self(sprintf(
            'The language "%s" is not configured on the site (found: %s): create it in the multilingual plugin before importing documents of that language.',
            $slug,
            $found === [] ? 'none' : implode(', ', $found),
        ));
    }

    public static function languageNotApplied(int $postId, string $slug): self
    {
        return new self("Post {$postId} did not take the language \"{$slug}\": the multilingual plugin refused it.");
    }

    /**
     * @param array<string, int> $expected
     * @param array<string, int> $actual
     */
    public static function groupNotApplied(array $expected, array $actual): self
    {
        return new self(sprintf(
            'The translation group %s was not applied: the multilingual plugin holds %s.',
            self::describe($expected),
            self::describe($actual),
        ));
    }

    public static function apiUnavailable(): self
    {
        return new self('The multilingual plugin does not answer: is Polylang active?');
    }

    /**
     * @param array<string, int> $group
     */
    private static function describe(array $group): string
    {
        if ($group === []) {
            return '{}';
        }
        $parts = [];
        foreach ($group as $lang => $id) {
            $parts[] = "{$lang}: {$id}";
        }

        return '{' . implode(', ', $parts) . '}';
    }
}
