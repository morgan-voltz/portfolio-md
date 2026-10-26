<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Admin\SubmittedMarkdown;

#[CoversClass(SubmittedMarkdown::class)]
final class SubmittedMarkdownTest extends TestCase
{
    #[Test]
    public function turns_the_line_breaks_of_a_form_submission_back_into_newlines(): void
    {
        self::assertSame("---\ntitle: \"A\"\n---\n\nCorps.\n", SubmittedMarkdown::normalize("---\r\ntitle: \"A\"\r\n---\r\n\r\nCorps.\r\n"));
    }

    #[Test]
    public function turns_lone_carriage_returns_into_newlines_too(): void
    {
        self::assertSame("a\nb\n", SubmittedMarkdown::normalize("a\rb\r"));
    }

    #[Test]
    public function ends_the_text_with_a_newline_like_every_file_of_the_repository(): void
    {
        self::assertSame("Corps.\n", SubmittedMarkdown::normalize('Corps.'));
    }

    #[Test]
    public function leaves_a_text_that_already_ends_with_a_newline_alone(): void
    {
        self::assertSame("Corps.\n\n\n", SubmittedMarkdown::normalize("Corps.\n\n\n"));
    }

    #[Test]
    public function leaves_an_empty_submission_empty(): void
    {
        self::assertSame('', SubmittedMarkdown::normalize(''));
    }

    #[Test]
    public function a_file_of_the_repository_comes_back_byte_for_byte(): void
    {
        $file = "---\ntitle: \"A\"\n---\n\nUn `C:\\chemin` et deux  espaces.\n";

        self::assertSame($file, SubmittedMarkdown::normalize($file));
    }
}
