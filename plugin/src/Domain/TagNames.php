<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Domain;

/**
 * The names file as loaded (ADR-024): the technology tags in the order of
 * the file, looked up by slug.
 */
final readonly class TagNames
{
    /** @var array<string, TechTagName> slug => tag */
    private array $bySlug;

    /**
     * @param list<TechTagName> $tags
     */
    public function __construct(public array $tags)
    {
        $bySlug = [];
        foreach ($tags as $tag) {
            $bySlug[$tag->slug] = $tag;
        }
        $this->bySlug = $bySlug;
    }

    public function has(string $slug): bool
    {
        return isset($this->bySlug[$slug]);
    }

    public function get(string $slug): ?TechTagName
    {
        return $this->bySlug[$slug] ?? null;
    }

    /**
     * @return list<string>
     */
    public function slugs(): array
    {
        // PHP turns an all-digit key into an int: the slugs are strings, as the validator compares them.
        return array_map('strval', array_keys($this->bySlug));
    }

    public function count(): int
    {
        return count($this->bySlug);
    }
}
