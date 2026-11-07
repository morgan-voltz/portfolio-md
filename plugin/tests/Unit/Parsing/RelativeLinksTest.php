<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Parsing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Parsing\RelativeLinks;

#[CoversClass(RelativeLinks::class)]
final class RelativeLinksTest extends TestCase
{
    #[Test]
    public function rewrites_links_to_other_documents_into_links_of_the_site_in_the_language_of_the_document(): void
    {
        $html = '<p><a href="../projects/vefta.md">Vefta</a>, <a href="../articles/rust-ownership.md">un article</a>, <a href="../pages/cv.md">le CV</a></p>';

        self::assertSame(
            '<p><a href="/fr/projets/vefta/">Vefta</a>, <a href="/fr/articles/rust-ownership/">un article</a>, <a href="/fr/cv/">le CV</a></p>',
            RelativeLinks::rewrite($html, Language::Fr),
        );
        self::assertSame(
            '<p><a href="/de/projets/vefta/">Vefta</a>, <a href="/de/articles/rust-ownership/">un article</a>, <a href="/de/cv/">le CV</a></p>',
            RelativeLinks::rewrite($html, Language::De),
        );
    }

    #[Test]
    public function a_link_that_names_a_language_is_rewritten_into_that_language(): void
    {
        self::assertSame('<a href="/en/resume/">x</a>', RelativeLinks::rewrite('<a href="../../en/pages/resume.md">x</a>', Language::Fr));
        self::assertSame('<a href="/fr/projets/momocad/">x</a>', RelativeLinks::rewrite('<a href="../../fr/projects/momocad.md">x</a>', Language::De));
        self::assertSame('<a href="../../xx/projects/y.md">x</a>', RelativeLinks::rewrite('<a href="../../xx/projects/y.md">x</a>', Language::Fr), 'a language the site does not have is left alone');
    }

    #[Test]
    public function keeps_a_fragment_and_accepts_a_leading_dot_segment(): void
    {
        self::assertSame('<a href="/fr/projets/momocad/#en-chiffres">x</a>', RelativeLinks::rewrite('<a href="../projects/momocad.md#en-chiffres">x</a>', Language::Fr));
        self::assertSame('<a href="/fr/projets/momocad/">x</a>', RelativeLinks::rewrite('<a href="./../projects/momocad.md">x</a>', Language::Fr));
        self::assertSame('<a href="/de/projets/momocad-de/#zahlen">x</a>', RelativeLinks::rewrite('<a href="../../de/projects/momocad-de.md#zahlen">x</a>', Language::Fr));
    }

    #[Test]
    public function leaves_every_other_link_alone(): void
    {
        $untouched = [
            '<a href="https://example.com/projects/x.md">external</a>',
            '<a href="/projets/x/">already a site link</a>',
            '<a href="#section">anchor</a>',
            '<a href="../images/schema.png">a file</a>',
            '<a href="../projects/Not-A-Slug.md">bad slug</a>',
            '<a href="../drafts/x.md">unknown folder</a>',
            '<a href="x.md">a sibling, which the content does not use</a>',
            '<a href="../../de/drafts/x.md">unknown folder in another language</a>',
            '<img src="../projects/x.md">',
            '<a data-href="../projects/x.md">an attribute whose name only ends in href</a>',
        ];

        foreach ($untouched as $html) {
            self::assertSame($html, RelativeLinks::rewrite($html, Language::Fr));
        }
    }

    #[Test]
    public function a_double_dot_without_language_an_uppercase_language_and_a_third_level_are_left_alone(): void
    {
        $html = '<a href="../../projects/x.md">a</a> <a href="../../FR/projects/x.md">b</a> <a href="../../../fr/projects/x.md">c</a>';

        self::assertSame($html, RelativeLinks::rewrite($html, Language::Fr));
    }
}
