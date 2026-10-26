<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\Frontmatter;
use Voltz\PortfolioMd\Domain\InvalidFrontmatterException;
use Voltz\PortfolioMd\Domain\ProjectStatus;

#[CoversClass(Frontmatter::class)]
#[CoversClass(InvalidFrontmatterException::class)]
final class FrontmatterTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function project(array $overrides = []): array
    {
        return array_merge([
            'title' => 'MomoCad',
            'slug' => 'momocad',
            'type' => 'project',
            'date' => new DateTimeImmutable('2026-10-26'),
            'subtitle' => 'Un logiciel de dessin',
            'seo_description' => 'Une phrase.',
            'featured' => true,
            'status' => 'en-cours',
            'role' => 'Solo',
            'period_start' => '2026-09-04',
            'period_end' => '',
            'repo_url' => '',
            'demo_url' => 'https://example.org/demo',
            'client' => '',
            'is_fil_rouge' => true,
            'tags' => ['csharp', 'dotnet'],
            'skills' => ['4.0.2/E00', '4.0.1/FR-13'],
        ], $overrides);
    }

    #[Test]
    public function builds_a_typed_project_frontmatter(): void
    {
        $fm = Frontmatter::fromArray(self::project());

        self::assertSame('MomoCad', $fm->title);
        self::assertSame('momocad', $fm->slug);
        self::assertSame(ContentType::Project, $fm->type);
        self::assertSame('2026-10-26', $fm->date?->format('Y-m-d'));
        self::assertTrue($fm->featured);
        self::assertSame(ProjectStatus::EnCours, $fm->status);
        self::assertSame('2026-09-04', $fm->periodStart?->format('Y-m-d'));
        self::assertNull($fm->periodEnd);
        self::assertSame('', $fm->repoUrl);
        self::assertSame('https://example.org/demo', $fm->demoUrl);
        self::assertTrue($fm->isFilRouge);
        self::assertSame(['csharp', 'dotnet'], $fm->tags);
        self::assertSame(['4.0.2/E00', '4.0.1/FR-13'], $fm->skills);
        self::assertNull($fm->readingTime);
    }

    #[Test]
    public function builds_a_page_with_the_common_keys_only(): void
    {
        $fm = Frontmatter::fromArray([
            'title' => 'À propos',
            'slug' => 'a-propos',
            'type' => 'page',
            'date' => '2026-10-26',
            'seo_description' => 'Qui je suis.',
        ]);

        self::assertSame(ContentType::Page, $fm->type);
        self::assertSame('2026-10-26', $fm->date?->format('Y-m-d'));
        self::assertNull($fm->status);
        self::assertFalse($fm->featured);
        self::assertSame([], $fm->tags);
    }

    #[Test]
    public function builds_an_article_with_a_reading_time(): void
    {
        $fm = Frontmatter::fromArray([
            'title' => 'Rust',
            'slug' => 'rust-ownership',
            'type' => 'article',
            'reading_time' => 8,
            'tags' => ['rust'],
        ]);

        self::assertSame(ContentType::Article, $fm->type);
        self::assertSame(8, $fm->readingTime);
        self::assertNull($fm->date);
    }

    #[Test]
    #[DataProvider('rejectedProvider')]
    public function rejects_invalid_metadata(array $overrides, string $expectedMessagePart): void
    {
        $this->expectException(InvalidFrontmatterException::class);
        $this->expectExceptionMessage($expectedMessagePart);

        Frontmatter::fromArray(self::project($overrides));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function rejectedProvider(): iterable
    {
        yield 'unknown key' => [['colour' => 'red'], 'unknown key(s) "colour"'];
        yield 'dropped category key gets a hint' => [['category' => 'Architecture'], 'ADR-015'];
        yield 'project key on an article' => [['type' => 'article', 'status' => 'stable'], 'unknown key(s)'];
        yield 'bad type' => [['type' => 'post'], 'invalid value for "type"'];
        yield 'bad status' => [['status' => 'finished'], 'invalid value for "status"'];
        yield 'bad slug' => [['slug' => 'Momo Cad'], 'invalid value for "slug"'];
        yield 'empty title' => [['title' => '   '], 'invalid value for "title"'];
        yield 'textual boolean' => [['featured' => 'yes'], 'invalid value for "featured"'];
        yield 'impossible date' => [['period_start' => '2026-02-30'], 'invalid value for "period_start"'];
        yield 'date in the wrong format' => [['date' => '26/10/2026'], 'invalid value for "date"'];
        yield 'url without scheme' => [['repo_url' => 'github.com/x'], 'invalid value for "repo_url"'];
        yield 'tag that is not a slug' => [['tags' => ['C#']], 'invalid value for "tags"'];
        yield 'duplicate tags' => [['tags' => ['rust', 'rust']], 'contains duplicates'];
        yield 'skill code in the wrong shape' => [['skills' => ['E07']], 'invalid value for "skills"'];
        yield 'tags as a string' => [['tags' => 'rust'], 'expected a YAML list'];
        yield 'seo description too long' => [['seo_description' => str_repeat('a', 161)], 'longer than 160'];
    }

    #[Test]
    public function a_project_must_declare_its_status(): void
    {
        $data = self::project();
        unset($data['status']);

        $this->expectException(InvalidFrontmatterException::class);
        $this->expectExceptionMessage('missing required key "status"');

        Frontmatter::fromArray($data);
    }

    #[Test]
    public function title_slug_and_type_are_always_required(): void
    {
        $this->expectException(InvalidFrontmatterException::class);
        $this->expectExceptionMessage('missing required key "slug"');

        Frontmatter::fromArray(['title' => 'x', 'type' => 'page']);
    }

    #[Test]
    public function a_negative_reading_time_is_rejected(): void
    {
        $this->expectException(InvalidFrontmatterException::class);
        $this->expectExceptionMessage('reading_time');

        Frontmatter::fromArray(['title' => 'x', 'slug' => 'x', 'type' => 'article', 'reading_time' => -1]);
    }
}
