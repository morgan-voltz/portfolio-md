<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\ContentSource;
use Voltz\PortfolioMd\Service\ConflictResolver;
use Voltz\PortfolioMd\Service\ImportOutcome;
use Voltz\PortfolioMd\Service\StoredContent;

/**
 * The decision table of ADR-005, one row per case.
 */
#[CoversClass(ConflictResolver::class)]
#[CoversClass(StoredContent::class)]
final class ConflictResolverTest extends TestCase
{
    private const RENDERER = '1.0.0';

    #[Test]
    #[DataProvider('decisions')]
    public function decides(?StoredContent $existing, string $hash, string $renderVersion, ContentSource $source, bool $force, ImportOutcome $expected): void
    {
        self::assertSame($expected, (new ConflictResolver())->decide($existing, $hash, $renderVersion, $source, $force));
    }

    /**
     * @return iterable<string, array{?StoredContent, string, string, ContentSource, bool, ImportOutcome}>
     */
    public static function decisions(): iterable
    {
        yield 'new content is created' => [null, 'h1', self::RENDERER, ContentSource::Git, false, ImportOutcome::Created];
        yield 'same hash, same renderer: unchanged' => [self::stored('h1', ContentSource::Git), 'h1', self::RENDERER, ContentSource::Git, false, ImportOutcome::Unchanged];
        yield 'same hash from another source: still unchanged' => [self::stored('h1', ContentSource::Admin), 'h1', self::RENDERER, ContentSource::Git, false, ImportOutcome::Unchanged];
        yield 'same hash, older renderer: rendered again' => [self::stored('h1', ContentSource::Admin, '0.9.0'), 'h1', self::RENDERER, ContentSource::Git, false, ImportOutcome::Updated];
        yield 'git over git: fast-forward' => [self::stored('h1', ContentSource::Git), 'h2', self::RENDERER, ContentSource::Git, false, ImportOutcome::Updated];
        yield 'git over admin: conflict' => [self::stored('h1', ContentSource::Admin), 'h2', self::RENDERER, ContentSource::Git, false, ImportOutcome::Conflict];
        yield 'git over paste: conflict' => [self::stored('h1', ContentSource::Paste), 'h2', self::RENDERER, ContentSource::Git, false, ImportOutcome::Conflict];
        yield 'git over a post made by hand: conflict' => [new StoredContent(7, '', null, ''), 'h2', self::RENDERER, ContentSource::Git, false, ImportOutcome::Conflict];
        yield 'git over admin, forced: updated' => [self::stored('h1', ContentSource::Admin), 'h2', self::RENDERER, ContentSource::Git, true, ImportOutcome::Updated];
        yield 'paste over admin: a human act wins' => [self::stored('h1', ContentSource::Admin), 'h2', self::RENDERER, ContentSource::Paste, false, ImportOutcome::Updated];
        yield 'admin over git: a human act wins' => [self::stored('h1', ContentSource::Git), 'h2', self::RENDERER, ContentSource::Admin, false, ImportOutcome::Updated];
    }

    private static function stored(string $hash, ContentSource $source, string $renderVersion = self::RENDERER): StoredContent
    {
        return new StoredContent(7, $hash, $source, $renderVersion);
    }
}
