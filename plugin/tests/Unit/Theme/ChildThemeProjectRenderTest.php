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

/**
 * The page of a project as the child theme dresses it (phase 10): the header
 * (deck, fil rouge mention, facts), the footer (tags without link, skills
 * with their labels), the period, the status pill, and the status on the
 * cards. Pure functions of theme/portfolio-md-child/inc, exercised with the
 * escaping stubs of the suite and a PublishedDocument built by hand.
 */
final class ChildThemeProjectRenderTest extends TestCase
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

    /**
     * A French project with every fact, as the catalog would read MomoCad.
     *
     * @param array<string, mixed> $overrides constructor arguments to change
     */
    private static function document(array $overrides = []): PublishedDocument
    {
        $arguments = [
            'id' => 13,
            'type' => ContentType::Project,
            'slug' => 'momocad',
            'title' => 'MomoCad',
            'link' => 'http://example.test/fr/projets/momocad/',
            'date' => new DateTimeImmutable('2026-10-25T22:00:00Z'),
            'updated' => new DateTimeImmutable('2026-11-08T10:00:00Z'),
            'markdown' => '',
            'lang' => Language::Fr,
            'translationKey' => 'momocad',
            'subtitle' => 'Un logiciel de dessin 2D façon CAO',
            'tags' => [new DocumentTag('csharp', 'C#'), new DocumentTag('event-sourcing', 'Event sourcing'), new DocumentTag('architecture-hexagonale', 'Architecture hexagonale', ['de' => 'Hexagonale Architektur', 'en' => 'Hexagonal architecture']), new DocumentTag('securite', 'Sécurité', ['en' => 'Security'])],
            'status' => ProjectStatus::EnCours,
            'skills' => [
                new DocumentSkill('e06-tests-unitaires', '4.0.2/E06', ['fr' => 'Tests unitaires', 'de' => 'Unit-Tests', 'en' => 'Unit tests'], false),
                new DocumentSkill('fr-13-developper', '4.0.1/FR-13', ['fr' => 'Développer une application'], true),
            ],
            'isFilRouge' => true,
            'role' => 'Conception, développement, tests',
            'periodStart' => new DateTimeImmutable('2026-09-04'),
            'periodEnd' => null,
            'repoUrl' => 'https://github.com/morgan-voltz/momocad/',
            'demoUrl' => '',
            'client' => '',
        ];

        return new PublishedDocument(...array_merge($arguments, $overrides));
    }

    #[Test]
    public function the_header_shows_the_deck_the_fil_rouge_mention_and_the_facts_in_the_language_of_the_page(): void
    {
        $html = portfolio_md_child_render_project_header(self::document(), 'de');

        self::assertStringStartsWith('<div class="project-header"><p class="project-deck">Un logiciel de dessin 2D façon CAO</p><p class="project-fil-rouge">Leitprojekt (roter Faden) UHA 4.0.2</p><div class="project-facts" role="group" aria-label="Projektdaten"><dl class="project-facts-list">', $html);
        self::assertStringContainsString('<div class="project-fact project-fact-status"><dt>Status</dt><dd><span class="project-status is-en-cours">In Arbeit</span></dd></div>', $html);
        self::assertStringContainsString('<div class="project-fact project-fact-period"><dt>Zeitraum</dt><dd>seit <time datetime="2026-09">September 2026</time></dd></div>', $html);
        self::assertStringContainsString('<div class="project-fact project-fact-role"><dt>Rolle</dt><dd>Conception, développement, tests</dd></div>', $html);
        self::assertStringContainsString('<div class="project-fact project-fact-repository"><dt>Quellcode</dt><dd><a href="https://github.com/morgan-voltz/momocad/" rel="noopener">github.com/morgan-voltz/momocad</a></dd></div></dl></div></div>', $html);
        self::assertStringNotContainsString('Auftraggeber', $html, 'no client, no client fact');
        self::assertStringNotContainsString('project-fact-demo', $html, 'no demo, no demo fact');
    }

    #[Test]
    public function the_facts_keep_a_fixed_order_and_only_the_ones_the_document_has(): void
    {
        $document = self::document([
            'subtitle' => '',
            'isFilRouge' => false,
            'status' => ProjectStatus::Stable,
            'role' => '',
            'periodStart' => new DateTimeImmutable('2026-01-09'),
            'periodEnd' => new DateTimeImmutable('2026-05-31'),
            'repoUrl' => '',
            'demoUrl' => 'https://demo.example.test/app?x=1&y=2',
            'client' => 'm2A <Mulhouse & co>',
        ]);

        $html = portfolio_md_child_render_project_header($document, 'fr');

        self::assertSame(
            '<div class="project-header"><div class="project-facts" role="group" aria-label="Fiche du projet"><dl class="project-facts-list">'
            . '<div class="project-fact project-fact-status"><dt>Statut</dt><dd><span class="project-status is-stable">Stable</span></dd></div>'
            . '<div class="project-fact project-fact-period"><dt>Période</dt><dd><time datetime="2026-01">janvier 2026</time> – <time datetime="2026-05">mai 2026</time></dd></div>'
            . '<div class="project-fact project-fact-client"><dt>Commanditaire</dt><dd>m2A &lt;Mulhouse &amp; co&gt;</dd></div>'
            . '<div class="project-fact project-fact-demo"><dt>Démo</dt><dd><a href="https://demo.example.test/app?x=1&#038;y=2" rel="noopener">demo.example.test/app?x=1&amp;y=2</a></dd></div>'
            . '</dl></div></div>',
            $html,
        );
    }

    #[Test]
    public function a_document_without_deck_nor_mention_nor_fact_has_no_header(): void
    {
        $document = self::document(['subtitle' => '', 'isFilRouge' => false, 'status' => null, 'role' => '', 'periodStart' => null, 'repoUrl' => '', 'demoUrl' => '', 'client' => '']);

        self::assertSame('', portfolio_md_child_render_project_header($document, 'fr'));
    }

    #[Test]
    public function the_period_reads_as_a_reader_of_the_language_writes_it(): void
    {
        $january = new DateTimeImmutable('2026-01-09');
        $may = new DateTimeImmutable('2026-05-31');
        $march = new DateTimeImmutable('2026-03-19');
        $marchEnd = new DateTimeImmutable('2026-03-23');
        $june = new DateTimeImmutable('2026-06-01');

        self::assertSame('<time datetime="2026-01">janvier 2026</time> – <time datetime="2026-05">mai 2026</time>', portfolio_md_child_render_period($january, $may, 'fr'));
        self::assertSame('<time datetime="2026-01">Januar 2026</time> – <time datetime="2026-05">Mai 2026</time>', portfolio_md_child_render_period($january, $may, 'de'));
        self::assertSame('<time datetime="2026-03">mars 2026</time>', portfolio_md_child_render_period($march, $marchEnd, 'fr'), 'started and ended in the same month: one month');
        self::assertSame('since <time datetime="2026-06">June 2026</time>', portfolio_md_child_render_period($june, null, 'en'), 'no end: still running');
        self::assertSame('depuis <time datetime="2026-06">juin 2026</time>', portfolio_md_child_render_period($june, null, 'xx'), 'an unknown language reads French');
        self::assertSame('', portfolio_md_child_render_period(null, $may, 'fr'), 'no start: no period');
    }

    #[Test]
    public function the_footer_lists_the_tags_without_link_and_the_skills_with_their_label_in_the_language_of_the_page(): void
    {
        $html = portfolio_md_child_render_project_footer(self::document(), 'de');

        $tags = substr($html, strpos($html, '<section class="project-tags"'), strpos($html, '</section>') - strpos($html, '<section class="project-tags"'));
        self::assertStringContainsString('<h2 id="project-tags-title">Technologien</h2><ul class="tech-tags"><li class="tech-tag">C#</li><li class="tech-tag">Event sourcing</li><li class="tech-tag">Hexagonale Architektur</li><li class="tech-tag" lang="en">Security</li></ul>', $tags, 'a neutral name is not marked, a translated one reads in German, a name that fell back on English says so (ADR-024)');
        self::assertStringNotContainsString('<a ', $tags, 'the archives of the tags are noindex: no link to them');
        self::assertStringContainsString('<section class="project-skills" aria-labelledby="project-skills-title"><h2 id="project-skills-title">Nachgewiesene Kompetenzen</h2><ul class="skills">', $html);
        self::assertStringContainsString('<li class="skill is-provisional"><span class="skill-code">4.0.2/E06</span> <span class="skill-text"><span class="skill-label">Unit-Tests</span><span class="skill-provisional" title="vorläufige Bezeichnung"><span aria-hidden="true">*</span><span class="screen-reader-text"> (vorläufige Bezeichnung)</span></span></span></li>', $html, 'a German wording, not official in the referential: an asterisk glued to the label');
        self::assertStringContainsString('<li class="skill"><span class="skill-code">4.0.1/FR-13</span> <span class="skill-text"><span class="skill-label" lang="fr">Développer une application</span></span></li>', $html, 'an official wording without German falls back on French and says so');
        self::assertStringEndsWith('</ul><p class="skills-note"><span aria-hidden="true">* </span>Mit einem Sternchen markierte Bezeichnungen sind vorläufig: Der offizielle Kompetenzbogen des Jahrgangs ist noch nicht veröffentlicht.</p></section></div>', $html, 'one note for every asterisk');
    }

    #[Test]
    public function the_footer_speaks_french_on_a_french_page_and_escapes_what_the_documents_say(): void
    {
        $document = self::document(['tags' => [new DocumentTag('c-sharp', 'C# <3 & co')], 'skills' => [new DocumentSkill('x', '4.0.2/E01', ['fr' => 'Classes & objets'], false)]]);

        $html = portfolio_md_child_render_project_footer($document, 'fr');

        self::assertStringContainsString('<h2 id="project-tags-title">Technologies</h2><ul class="tech-tags"><li class="tech-tag">C# &lt;3 &amp; co</li></ul>', $html);
        self::assertStringContainsString('<h2 id="project-skills-title">Compétences démontrées</h2>', $html);
        self::assertStringContainsString('<span class="skill-text"><span class="skill-label">Classes &amp; objets</span><span class="skill-provisional" title="intitulé provisoire">', $html, 'the French wording carries no lang attribute on a French page');
        self::assertStringContainsString('<p class="skills-note"><span aria-hidden="true">* </span>Les intitulés marqués d’un astérisque sont provisoires', $html);
    }

    #[Test]
    public function a_document_without_tags_nor_skills_has_no_footer_and_official_wordings_only_have_no_note(): void
    {
        self::assertSame('', portfolio_md_child_render_project_footer(self::document(['tags' => [], 'skills' => []]), 'fr'));

        $html = portfolio_md_child_render_project_footer(self::document(['tags' => [], 'skills' => [new DocumentSkill('x', '4.0.1/FR-13', ['fr' => 'Développer une application'], true)]]), 'en');

        self::assertStringNotContainsString('skills-note', $html);
        self::assertStringNotContainsString('skill-provisional', $html);
        self::assertStringContainsString('<h2 id="project-skills-title">Skills demonstrated</h2>', $html);
    }

    #[Test]
    public function a_card_wears_the_status_of_its_project_in_the_language_of_the_page(): void
    {
        $item = new ListItem(12, 'MomoCad', 'Un logiciel de dessin 2D', 'http://example.test/fr/projets/momocad/', Language::Fr, Language::En, true, ProjectStatus::Stable);

        $html = portfolio_md_child_render_cards([$item], 'en');

        self::assertStringContainsString('</h2><p class="project-card-status"><span class="project-status is-stable">Stable</span></p></header>', $html);
        self::assertStringContainsString('<span class="project-status is-stable">Stabil</span>', portfolio_md_child_render_cards([$item], 'de'));
    }

    #[Test]
    public function the_title_of_a_card_is_an_h2_under_an_archive_title_and_an_h3_under_the_heading_of_a_block(): void
    {
        $item = new ListItem(12, 'MomoCad', '', 'http://example.test/fr/projets/momocad/', Language::Fr, Language::Fr, false);

        self::assertStringContainsString('<h2 class="entry-title" itemprop="headline"><a href="http://example.test/fr/projets/momocad/" rel="bookmark">MomoCad</a></h2>', portfolio_md_child_render_cards([$item], 'fr'));
        self::assertStringContainsString('<h3 class="entry-title" itemprop="headline"><a href="http://example.test/fr/projets/momocad/" rel="bookmark">MomoCad</a></h3>', portfolio_md_child_render_cards([$item], 'fr', 'h3'));
        self::assertStringContainsString('<h2 class="entry-title"', portfolio_md_child_render_cards([$item], 'fr', 'h1'), 'anything but h3 is an h2');
        $home = portfolio_md_child_render_home_projects([$item], 'fr', '');
        self::assertSame(1, substr_count($home, '<h3 class="entry-title"'), 'the cards of the home sit under the h2 of the block');
        self::assertStringNotContainsString('<h2 class="entry-title"', $home);
    }

    #[Test]
    public function the_status_pill_never_uses_the_status_class_of_wordpress(): void
    {
        foreach (ProjectStatus::cases() as $status) {
            $html = portfolio_md_child_render_status($status, 'fr');
            self::assertStringStartsWith('<span class="project-status is-' . $status->value . '">', $html);
            self::assertStringNotContainsString('class="status-', $html);
        }
        self::assertSame('<span class="project-status is-archive">Archived</span>', portfolio_md_child_render_status(ProjectStatus::Archive, 'en'));
    }

    #[Test]
    public function every_language_has_every_text_and_twelve_months(): void
    {
        $strings = portfolio_md_child_strings();
        foreach (['de', 'en'] as $language) {
            self::assertSame(array_keys($strings['fr']), array_keys($strings[$language]), "the {$language} table has the keys of the French one, in the same order");
        }
        foreach (portfolio_md_child_month_names() as $language => $months) {
            self::assertCount(12, $months, "twelve months in {$language}");
        }
        self::assertSame('août', portfolio_md_child_month_name(8, 'fr'));
        self::assertSame('März', portfolio_md_child_month_name(3, 'de'));
        self::assertSame('December', portfolio_md_child_month_name(12, 'en'));
        self::assertSame('janvier', portfolio_md_child_month_name(1, 'xx'), 'an unknown language reads French');
        self::assertSame('', portfolio_md_child_month_name(13, 'fr'));
    }
}
