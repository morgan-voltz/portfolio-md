<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Theme;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Domain\ProjectStatus;
use Voltz\PortfolioMd\Service\DocumentSkill;
use Voltz\PortfolioMd\Service\DocumentTag;
use Voltz\PortfolioMd\Service\ListItem;
use Voltz\PortfolioMd\Service\PublishedDocument;
use Voltz\PortfolioMd\Tests\Support\WordPressSpy;

/**
 * What the child theme renders for the visual identity of ADR-027: the top
 * of a page (headline, deck, buttons), the logo of the header, the credit
 * of the footer, the texts of the theme that go with them, and the
 * typography of the texts of the documents (wptexturize, primed here like
 * the other text passes of the suite).
 */
final class ChildThemeIdentityRenderTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $theme = dirname(__DIR__, 4) . '/theme/portfolio-md-child/inc';
        if (!is_file($theme . '/render.php')) {
            self::fail("The child theme was not found at {$theme}: run the suite from a checkout of the whole repository.");
        }
        require_once $theme . '/strings.php';
        require_once $theme . '/render.php';
    }

    protected function setUp(): void
    {
        WordPressSpy::reset();
    }

    protected function tearDown(): void
    {
        WordPressSpy::reset();
    }

    /** wptexturize() as a test primes it: the straight apostrophe becomes the typographic one. */
    private static function primeTexturize(): void
    {
        WordPressSpy::$filterResults['wptexturize'] = static fn (string $text): string => str_replace("'", '’', $text);
    }

    // ------------------------------------------------------------ the top of a page

    #[Test]
    public function the_headline_is_the_h1_the_subtitle_a_deck_and_the_first_button_is_filled(): void
    {
        $html = portfolio_md_child_render_page_intro('Du câblage au code.', 'Développeur full-stack', [
            ['url' => 'http://example.test/fr/projets/', 'label' => 'Voir mes projets'],
            ['url' => 'http://example.test/fr/cv/', 'label' => 'Voir mon CV'],
        ]);

        self::assertSame(
            '<h1 class="entry-title page-headline" itemprop="headline">Du câblage au code.</h1>'
            . '<p class="page-deck">Développeur full-stack</p>'
            . '<p class="page-actions">'
            . '<a class="pm-button is-primary" href="http://example.test/fr/projets/">Voir mes projets</a>'
            . '<a class="pm-button is-secondary" href="http://example.test/fr/cv/">Voir mon CV</a>'
            . '</p>',
            $html,
        );
    }

    #[Test]
    public function a_page_without_headline_keeps_the_title_of_generatepress_and_only_gets_its_deck(): void
    {
        self::assertSame('<p class="page-deck">Une ligne sous le titre</p>', portfolio_md_child_render_page_intro('', 'Une ligne sous le titre'));
    }

    #[Test]
    public function a_page_with_nothing_to_say_gets_nothing(): void
    {
        self::assertSame('', portfolio_md_child_render_page_intro('', '', []));
    }

    #[Test]
    public function a_button_without_address_or_label_is_left_out_and_the_next_one_is_the_filled_one(): void
    {
        $html = portfolio_md_child_render_page_intro('', '', [
            ['url' => '', 'label' => 'Voir mes projets'],
            ['url' => 'http://example.test/fr/cv/', 'label' => 'Voir mon CV'],
            ['url' => 'http://example.test/fr/contact/', 'label' => ''],
        ]);

        self::assertSame('<p class="page-actions"><a class="pm-button is-primary" href="http://example.test/fr/cv/">Voir mon CV</a></p>', $html);
    }

    #[Test]
    public function the_top_of_a_page_sets_and_escapes_the_texts_of_the_document(): void
    {
        self::primeTexturize();

        $html = portfolio_md_child_render_page_intro("Du câblage d'armoires <b>", "L'API & le reste", [
            ['url' => 'http://example.test/fr/projets/?a=1&b=2', 'label' => 'Projets & co'],
        ]);

        self::assertStringContainsString('<h1 class="entry-title page-headline" itemprop="headline">Du câblage d’armoires &lt;b&gt;</h1>', $html);
        self::assertStringContainsString('<p class="page-deck">L’API &amp; le reste</p>', $html);
        self::assertStringContainsString('href="http://example.test/fr/projets/?a=1&#038;b=2">Projets &amp; co</a>', $html);
    }

    // ------------------------------------------------------------ the logo and the footer

    #[Test]
    public function the_logo_links_home_with_its_two_sizes_and_its_size_written_out_and_the_name_of_the_site_as_its_text(): void
    {
        $html = portfolio_md_child_render_logo('http://example.test/fr/', 'http://example.test/logo-240.png', 'http://example.test/logo-480.png', 240, 69, 'Morgan Voltz');

        self::assertSame(
            '<div class="site-logo"><a href="http://example.test/fr/" rel="home">'
            . '<img class="header-image is-logo-image" src="http://example.test/logo-240.png" srcset="http://example.test/logo-240.png 1x, http://example.test/logo-480.png 2x" width="240" height="69" alt="Morgan Voltz">'
            . '</a></div>',
            $html,
        );
    }

    #[Test]
    public function a_logo_without_a_2x_file_has_no_srcset_and_its_text_is_escaped(): void
    {
        $html = portfolio_md_child_render_logo('http://example.test/', 'http://example.test/logo.png', '', 240, 69, 'Morgan "MV" Voltz');

        self::assertStringNotContainsString('srcset', $html);
        self::assertStringContainsString('alt="Morgan &quot;MV&quot; Voltz"', $html);
    }

    #[Test]
    public function the_credit_of_the_footer_is_the_copyright_then_a_named_list_of_links(): void
    {
        $html = portfolio_md_child_render_footer_credit('Morgan Voltz', 2026, ['GitHub' => 'https://github.com/morgan-voltz', 'Contact' => 'http://example.test/fr/contact/'], 'Liens');

        self::assertSame(
            '<span class="copyright">&copy; 2026 Morgan Voltz</span>'
            . '<nav class="footer-links" aria-label="Liens"><ul>'
            . '<li><a href="https://github.com/morgan-voltz">GitHub</a></li>'
            . '<li><a href="http://example.test/fr/contact/">Contact</a></li>'
            . '</ul></nav>',
            $html,
        );
        self::assertStringNotContainsString('GeneratePress', $html);
    }

    #[Test]
    public function a_link_without_address_is_left_out_and_no_link_means_no_list(): void
    {
        $withoutContact = portfolio_md_child_render_footer_credit('Morgan Voltz', 2026, ['GitHub' => 'https://github.com/morgan-voltz', 'Contact' => ''], 'Liens');
        $withoutAny = portfolio_md_child_render_footer_credit('Morgan & Co', 2027, ['Contact' => ''], 'Liens');

        self::assertStringNotContainsString('Contact', $withoutContact);
        self::assertSame('<span class="copyright">&copy; 2027 Morgan &amp; Co</span>', $withoutAny);
    }

    #[Test]
    public function the_footer_links_to_profiles_never_to_an_email_address(): void
    {
        self::assertSame(['GitHub' => 'https://github.com/morgan-voltz'], portfolio_md_child_profiles());
        foreach (portfolio_md_child_profiles() as $url) {
            self::assertStringNotContainsString('@', $url, 'the address lives on the Contact page alone');
        }
    }

    // ------------------------------------------------------------ the texts of the theme

    #[Test]
    public function the_buttons_and_the_footer_speak_the_language_of_the_page(): void
    {
        self::assertSame(['Voir mes projets', 'Voir mon CV', 'Contact'], [portfolio_md_child_text('see_projects', 'fr'), portfolio_md_child_text('see_cv', 'fr'), portfolio_md_child_text('contact', 'fr')]);
        self::assertSame(['Meine Projekte ansehen', 'Meinen Lebenslauf ansehen', 'Kontakt'], [portfolio_md_child_text('see_projects', 'de'), portfolio_md_child_text('see_cv', 'de'), portfolio_md_child_text('contact', 'de')]);
        self::assertSame(['See my projects', 'See my résumé', 'Contact'], [portfolio_md_child_text('see_projects', 'en'), portfolio_md_child_text('see_cv', 'en'), portfolio_md_child_text('contact', 'en')]);
    }

    #[Test]
    public function the_description_of_the_archive_fits_a_search_result_in_every_language(): void
    {
        foreach (['fr', 'de', 'en'] as $language) {
            $description = portfolio_md_child_text('projects_description', $language);
            self::assertNotSame('', $description, $language);
            self::assertLessThanOrEqual(160, mb_strlen($description), "the same bound as a seo_description ({$language})");
        }
    }

    // ------------------------------------------------------------ the typography of the documents

    #[Test]
    public function the_texts_of_a_card_are_set_like_the_body_of_the_page(): void
    {
        self::primeTexturize();
        $item = new ListItem(16, "L'atelier", "Une suite pensée pour l'utilisateur", 'http://example.test/fr/projets/vefta/', Language::Fr, Language::Fr, false);

        $html = portfolio_md_child_render_cards([$item], 'fr');

        self::assertStringContainsString('rel="bookmark">L’atelier</a>', $html);
        self::assertStringContainsString('<p>Une suite pensée pour l’utilisateur</p>', $html);
        self::assertStringNotContainsString("'", strip_tags($html));
    }

    #[Test]
    public function the_deck_the_facts_the_tags_and_the_skills_of_a_project_are_set_too(): void
    {
        self::primeTexturize();
        $document = new PublishedDocument(
            id: 13,
            type: ContentType::Project,
            slug: 'momocad',
            title: 'MomoCad',
            link: 'http://example.test/fr/projets/momocad/',
            date: new DateTimeImmutable('2026-10-25T22:00:00Z'),
            updated: new DateTimeImmutable('2026-11-08T10:00:00Z'),
            markdown: '',
            lang: Language::Fr,
            translationKey: 'momocad',
            tags: [new DocumentTag('design-d-api', "Design d'API")],
            subtitle: "Un logiciel dont l'état est un journal",
            status: ProjectStatus::EnCours,
            skills: [new DocumentSkill('e05', '4.0.2/E05', ['fr' => "Encapsuler l'état d'un objet"], true)],
            role: "Chef d'équipe",
            client: "L'agglomération",
        );

        $header = portfolio_md_child_render_project_header($document, 'fr');
        $footer = portfolio_md_child_render_project_footer($document, 'fr');

        self::assertStringContainsString('<p class="project-deck">Un logiciel dont l’état est un journal</p>', $header);
        self::assertStringContainsString('<dd>Chef d’équipe</dd>', $header);
        self::assertStringContainsString('<dd>L’agglomération</dd>', $header);
        self::assertStringContainsString('<li class="tech-tag">Design d’API</li>', $footer);
        self::assertStringContainsString('<span class="skill-label">Encapsuler l’état d’un objet</span>', $footer);
    }
}
