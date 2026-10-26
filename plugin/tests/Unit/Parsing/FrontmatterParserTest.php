<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Parsing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\InvalidFrontmatterException;
use Voltz\PortfolioMd\Parsing\FrontmatterParser;
use Voltz\PortfolioMd\Parsing\MalformedDocumentException;
use Voltz\PortfolioMd\Parsing\ParsedDocument;

#[CoversClass(FrontmatterParser::class)]
#[CoversClass(ParsedDocument::class)]
#[CoversClass(MalformedDocumentException::class)]
final class FrontmatterParserTest extends TestCase
{
    private const CONTENT_DIR = __DIR__ . '/../../../../content';

    private FrontmatterParser $parser;

    protected function setUp(): void
    {
        $this->parser = new FrontmatterParser();
    }

    #[Test]
    public function splits_frontmatter_and_body(): void
    {
        $document = $this->parser->parse("---\ntitle: \"Hello\"\nslug: hello\ntype: page\n---\n\n# Hello\n\nBody.\n");

        self::assertSame('Hello', $document->frontmatter->title);
        self::assertSame(ContentType::Page, $document->frontmatter->type);
        self::assertSame("\n# Hello\n\nBody.\n", $document->body);
    }

    #[Test]
    public function parses_the_real_momocad_sheet(): void
    {
        $document = $this->parser->parse((string) file_get_contents(self::CONTENT_DIR . '/projects/momocad.md'));

        self::assertSame('momocad', $document->frontmatter->slug);
        self::assertSame(ContentType::Project, $document->frontmatter->type);
        self::assertTrue($document->frontmatter->isFilRouge);
        self::assertSame('2026-09-04', $document->frontmatter->periodStart?->format('Y-m-d'));
        self::assertContains('4.0.2/E06', $document->frontmatter->skills);
        self::assertStringContainsString('## Le problème', $document->body);
    }

    #[Test]
    public function parses_every_sheet_of_the_content_folder(): void
    {
        $files = array_merge(
            glob(self::CONTENT_DIR . '/projects/*.md') ?: [],
            glob(self::CONTENT_DIR . '/pages/*.md') ?: [],
        );
        self::assertNotEmpty($files, 'content folder not found next to the plugin');

        foreach ($files as $file) {
            $document = $this->parser->parse((string) file_get_contents($file));
            self::assertSame(basename($file, '.md'), $document->frontmatter->slug, $file);
        }
    }

    #[Test]
    public function bare_yaml_dates_become_calendar_dates(): void
    {
        $document = $this->parser->parse("---\ntitle: x\nslug: x\ntype: page\ndate: 2026-10-26\n---\n");

        self::assertSame('2026-10-26', $document->frontmatter->date?->format('Y-m-d'));
        self::assertSame('00:00:00', $document->frontmatter->date?->format('H:i:s'));
    }

    #[Test]
    public function a_bare_impossible_date_is_rejected_instead_of_rolling_over(): void
    {
        $this->expectException(InvalidFrontmatterException::class);
        $this->expectExceptionMessage('invalid value for "period_start": 2026-02-30 is not a calendar date');

        $this->parser->parse("---\ntitle: x\nslug: x\ntype: project\nstatus: stable\nperiod_start: 2026-02-30\n---\n");
    }

    #[Test]
    public function a_quoted_impossible_date_is_rejected_too(): void
    {
        $this->expectException(InvalidFrontmatterException::class);
        $this->expectExceptionMessage('period_end');

        $this->parser->parse("---\ntitle: x\nslug: x\ntype: project\nstatus: stable\nperiod_end: \"2026-02-30\"\n---\n");
    }

    #[Test]
    public function a_bare_date_time_is_reduced_to_its_calendar_day(): void
    {
        $document = $this->parser->parse("---\ntitle: x\nslug: x\ntype: page\ndate: 2026-10-26T23:30:00+02:00\n---\n");

        self::assertSame('2026-10-26 00:00:00 UTC', $document->frontmatter->date?->format('Y-m-d H:i:s e'));
    }

    #[Test]
    public function tolerates_windows_line_endings_and_a_byte_order_mark(): void
    {
        $document = $this->parser->parse("\xEF\xBB\xBF---\r\ntitle: x\r\nslug: x\r\ntype: page\r\n---\r\nBody\r\n");

        self::assertSame('x', $document->frontmatter->slug);
        self::assertSame("Body\n", $document->body);
    }

    #[Test]
    public function a_document_without_frontmatter_is_malformed(): void
    {
        $this->expectException(MalformedDocumentException::class);
        $this->expectExceptionMessage('no YAML frontmatter');

        $this->parser->parse("# Just a title\n\nSome text.\n");
    }

    #[Test]
    public function an_unclosed_frontmatter_is_malformed(): void
    {
        $this->expectException(MalformedDocumentException::class);

        $this->parser->parse("---\ntitle: x\nslug: x\ntype: page\n\n# Body without closing fence\n");
    }

    #[Test]
    public function invalid_yaml_is_malformed(): void
    {
        $this->expectException(MalformedDocumentException::class);
        $this->expectExceptionMessage('not valid YAML');

        $this->parser->parse("---\ntitle: \"unterminated\nslug: x\n---\n");
    }

    #[Test]
    public function a_frontmatter_that_is_a_list_is_malformed(): void
    {
        $this->expectException(MalformedDocumentException::class);
        $this->expectExceptionMessage('mapping');

        $this->parser->parse("---\n- a\n- b\n---\n");
    }

    #[Test]
    public function semantic_errors_surface_as_invalid_frontmatter(): void
    {
        $this->expectException(InvalidFrontmatterException::class);
        $this->expectExceptionMessage('ADR-015');

        $this->parser->parse("---\ntitle: x\nslug: x\ntype: article\ncategory: Architecture\n---\n");
    }
}
