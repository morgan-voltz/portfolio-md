<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Parsing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Parsing\RelativeLinks;

#[CoversClass(RelativeLinks::class)]
final class RelativeLinksTest extends TestCase
{
    #[Test]
    public function rewrites_links_to_other_documents_into_links_of_the_site(): void
    {
        $html = '<p><a href="../projects/vefta.md">Vefta</a>, <a href="../articles/rust-ownership.md">un article</a>, <a href="../pages/cv.md">le CV</a></p>';

        self::assertSame(
            '<p><a href="/projets/vefta/">Vefta</a>, <a href="/articles/rust-ownership/">un article</a>, <a href="/cv/">le CV</a></p>',
            RelativeLinks::rewrite($html),
        );
    }

    #[Test]
    public function keeps_a_fragment_and_accepts_a_leading_dot_segment(): void
    {
        self::assertSame('<a href="/projets/momocad/#en-chiffres">x</a>', RelativeLinks::rewrite('<a href="../projects/momocad.md#en-chiffres">x</a>'));
        self::assertSame('<a href="/projets/momocad/">x</a>', RelativeLinks::rewrite('<a href="./../projects/momocad.md">x</a>'));
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
            '<img src="../projects/x.md">',
            '<a data-href="../projects/x.md">an attribute whose name only ends in href</a>',
        ];

        foreach ($untouched as $html) {
            self::assertSame($html, RelativeLinks::rewrite($html));
        }
    }
}
