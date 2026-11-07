<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\ContentPath;
use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\Language;

#[CoversClass(ContentPath::class)]
final class ContentPathTest extends TestCase
{
    #[Test]
    public function parses_a_french_project_path(): void
    {
        $path = ContentPath::fromRelative('fr/projects/momocad.md');

        self::assertNotNull($path);
        self::assertSame(Language::Fr, $path->lang);
        self::assertSame(ContentType::Project, $path->type);
        self::assertSame('momocad', $path->slug);
    }

    #[Test]
    public function parses_a_german_page_and_an_english_article(): void
    {
        $page = ContentPath::fromRelative('de/pages/lebenslauf.md');
        $article = ContentPath::fromRelative('en/articles/hello-en.md');

        self::assertSame([Language::De, ContentType::Page, 'lebenslauf'], [$page?->lang, $page?->type, $page?->slug]);
        self::assertSame([Language::En, ContentType::Article, 'hello-en'], [$article?->lang, $article?->type, $article?->slug]);
    }

    #[Test]
    #[DataProvider('refusedProvider')]
    public function refuses_what_is_not_a_document_path(string $relative): void
    {
        self::assertNull(ContentPath::fromRelative($relative));
    }

    /** @return iterable<string, array{string}> */
    public static function refusedProvider(): iterable
    {
        yield 'the legacy two-level layout' => ['projects/momocad.md'];
        yield 'four levels' => ['fr/projects/momocad/notes.md'];
        yield 'an unknown language' => ['es/projects/momocad.md'];
        yield 'an unknown folder' => ['fr/posts/momocad.md'];
        yield 'a file that is not Markdown' => ['fr/projects/momocad.png'];
        yield 'a dot segment' => ['fr/../momocad.md'];
        yield 'a leading dot segment' => ['./fr/projects/momocad.md'];
        yield 'an empty slug' => ['fr/projects/.md'];
        yield 'a badly shaped slug' => ['fr/projects/Foo.md'];
        yield 'an empty segment' => ['fr//momocad.md'];
        yield 'a trailing newline in the slug' => ["fr/projects/momocad\n.md"];
        yield 'a slug longer than WordPress allows' => ['fr/projects/' . str_repeat('a', 201) . '.md'];
    }

    #[Test]
    public function to_relative_round_trips(): void
    {
        $path = new ContentPath(Language::En, ContentType::Page, 'resume');

        self::assertSame('en/pages/resume.md', $path->toRelative());
        self::assertEquals($path, ContentPath::fromRelative($path->toRelative()));
    }
}
