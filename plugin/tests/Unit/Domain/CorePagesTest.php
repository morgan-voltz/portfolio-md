<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Domain;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\CorePages;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Domain\Patterns;

#[CoversClass(CorePages::class)]
final class CorePagesTest extends TestCase
{
    #[Test]
    public function the_four_pages_come_in_the_order_of_the_navigation(): void
    {
        self::assertSame(['accueil', 'a-propos', 'cv', 'contact'], CorePages::keys());
    }

    #[Test]
    public function the_french_slug_of_a_page_is_its_key(): void
    {
        foreach (CorePages::keys() as $key) {
            self::assertSame($key, CorePages::slug($key, Language::Fr));
        }
    }

    #[Test]
    public function every_page_has_a_slug_in_every_language_and_never_the_same_twice(): void
    {
        $seen = [];
        foreach (CorePages::keys() as $key) {
            foreach (Language::cases() as $language) {
                $slug = CorePages::slug($key, $language);
                self::assertMatchesRegularExpression(Patterns::SLUG, $slug);
                self::assertArrayNotHasKey($slug, $seen, "{$slug} is used twice: WordPress would suffix the second page with -2");
                $seen[$slug] = true;
            }
        }
    }

    #[Test]
    public function the_slugs_are_the_ones_of_adr_020(): void
    {
        self::assertSame('startseite', CorePages::slug(CorePages::HOME, Language::De));
        self::assertSame('home', CorePages::slug(CorePages::HOME, Language::En));
        self::assertSame('ueber-mich', CorePages::slug(CorePages::ABOUT, Language::De));
        self::assertSame('about', CorePages::slug(CorePages::ABOUT, Language::En));
        self::assertSame('lebenslauf', CorePages::slug(CorePages::CV, Language::De));
        self::assertSame('resume', CorePages::slug(CorePages::CV, Language::En));
        self::assertSame('get-in-touch', CorePages::slug(CorePages::CONTACT, Language::En));
        self::assertSame('kontakt', CorePages::slug(CorePages::CONTACT, Language::De));
    }

    #[Test]
    public function refuses_a_key_that_is_not_a_core_page(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"blog"');

        CorePages::slug('blog', Language::Fr);
    }
}
