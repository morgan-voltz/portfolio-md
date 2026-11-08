<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Taxonomies;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Domain\Patterns;
use Voltz\PortfolioMd\Taxonomies\TaxonomyRegistrar;
use Voltz\PortfolioMd\Tests\Support\WordPressSpy;

#[CoversClass(TaxonomyRegistrar::class)]
final class TaxonomyRegistrarTest extends TestCase
{
    private TaxonomyRegistrar $registrar;

    protected function setUp(): void
    {
        WordPressSpy::reset();
        $this->registrar = new TaxonomyRegistrar();
    }

    #[Test]
    public function registers_the_three_taxonomies_on_the_right_post_types(): void
    {
        $this->registrar->register();

        self::assertSame(['tech_tag', 'project_status', 'skill'], array_keys(WordPressSpy::$taxonomies));
        self::assertSame(['article', 'project'], WordPressSpy::$taxonomies['tech_tag']['objectTypes']);
        self::assertSame(['project'], WordPressSpy::$taxonomies['project_status']['objectTypes']);
        self::assertSame(['project'], WordPressSpy::$taxonomies['skill']['objectTypes']);
    }

    #[Test]
    public function tech_tag_and_project_status_are_flat_public_and_in_rest(): void
    {
        $this->registrar->register();

        foreach (['tech_tag' => 'tag', 'project_status' => 'statut'] as $taxonomy => $slug) {
            $args = WordPressSpy::$taxonomies[$taxonomy]['args'];

            self::assertFalse($args['hierarchical'], $taxonomy);
            self::assertTrue($args['public'], $taxonomy);
            self::assertTrue($args['show_in_rest'], $taxonomy);
            self::assertSame($slug, $args['rewrite']['slug'], $taxonomy);
        }
    }

    #[Test]
    public function skill_is_hierarchical_under_competences_and_in_rest(): void
    {
        $this->registrar->register();

        $args = WordPressSpy::$taxonomies['skill']['args'];

        self::assertTrue($args['hierarchical']);
        self::assertTrue($args['public']);
        self::assertTrue($args['show_in_rest']);
        self::assertTrue($args['show_admin_column']);
        self::assertFalse($args['show_tagcloud']);
        self::assertSame(['slug' => 'competences', 'with_front' => false, 'hierarchical' => true], $args['rewrite']);
        self::assertSame('Compétences', $args['labels']['name']);
        self::assertSame('Référentiel', $args['labels']['parent_item']);
    }

    #[Test]
    public function skill_code_term_meta_is_registered_public_and_sanitized(): void
    {
        $this->registrar->register();

        $args = WordPressSpy::$termMetas['skill'][TaxonomyRegistrar::SKILL_CODE_META] ?? null;

        self::assertNotNull($args);
        self::assertSame('string', $args['type']);
        self::assertTrue($args['single']);
        self::assertSame('', $args['default']);
        self::assertTrue($args['show_in_rest']);
        self::assertSame([TaxonomyRegistrar::class, 'sanitizeSkillCode'], $args['sanitize_callback']);
        self::assertSame([TaxonomyRegistrar::class, 'authorizeEditSkillTerm'], $args['auth_callback']);
        self::assertIsCallable($args['sanitize_callback']);
        self::assertIsCallable($args['auth_callback']);
    }

    #[Test]
    public function one_wording_term_meta_per_language_other_than_french_registered_like_the_code(): void
    {
        $this->registrar->register();

        self::assertSame('_portfolio_skill_name_de', TaxonomyRegistrar::skillNameMeta(Language::De));
        self::assertSame('_portfolio_skill_name_en', TaxonomyRegistrar::skillNameMeta(Language::En));
        self::assertArrayNotHasKey(TaxonomyRegistrar::skillNameMeta(Language::Fr), WordPressSpy::$termMetas['skill'], 'the French wording is the term name, not a meta');

        foreach ([Language::De, Language::En] as $language) {
            $args = WordPressSpy::$termMetas['skill'][TaxonomyRegistrar::skillNameMeta($language)] ?? null;

            self::assertNotNull($args, $language->value);
            self::assertSame('string', $args['type']);
            self::assertTrue($args['single']);
            self::assertSame('', $args['default']);
            self::assertTrue($args['show_in_rest']);
            self::assertSame([TaxonomyRegistrar::class, 'sanitizeSkillName'], $args['sanitize_callback']);
            self::assertSame([TaxonomyRegistrar::class, 'authorizeEditSkillTerm'], $args['auth_callback']);
        }
    }

    #[Test]
    public function the_official_flag_is_a_boolean_term_meta_false_by_default(): void
    {
        $this->registrar->register();

        $args = WordPressSpy::$termMetas['skill'][TaxonomyRegistrar::SKILL_OFFICIAL_META] ?? null;

        self::assertNotNull($args);
        self::assertSame('boolean', $args['type']);
        self::assertTrue($args['single']);
        self::assertFalse($args['default']);
        self::assertTrue($args['show_in_rest']);
        self::assertSame([TaxonomyRegistrar::class, 'sanitizeSkillOfficial'], $args['sanitize_callback']);
        self::assertSame([TaxonomyRegistrar::class, 'authorizeEditSkillTerm'], $args['auth_callback']);
        self::assertSame(
            [TaxonomyRegistrar::SKILL_CODE_META, '_portfolio_skill_name_de', '_portfolio_skill_name_en', TaxonomyRegistrar::SKILL_OFFICIAL_META],
            array_keys(WordPressSpy::$termMetas['skill']),
        );
    }

    #[Test]
    public function a_skill_wording_is_plain_text_within_the_length_of_a_term_name(): void
    {
        self::assertSame('Exposer le domaine par une API documentée', TaxonomyRegistrar::sanitizeSkillName(' <em>Exposer le domaine</em> par une API documentée '));
        self::assertSame(Patterns::TERM_NAME_MAX_LENGTH, mb_strlen(TaxonomyRegistrar::sanitizeSkillName(str_repeat('é', 250))));
        self::assertSame('', TaxonomyRegistrar::sanitizeSkillName(null));
    }

    #[Test]
    public function the_official_flag_reads_the_usual_boolean_forms(): void
    {
        self::assertTrue(TaxonomyRegistrar::sanitizeSkillOfficial('1'));
        self::assertTrue(TaxonomyRegistrar::sanitizeSkillOfficial(true));
        self::assertFalse(TaxonomyRegistrar::sanitizeSkillOfficial(''));
        self::assertFalse(TaxonomyRegistrar::sanitizeSkillOfficial('0'));
        self::assertFalse(TaxonomyRegistrar::sanitizeSkillOfficial(null));
        self::assertFalse(TaxonomyRegistrar::sanitizeSkillOfficial('maybe'));
    }

    #[Test]
    public function skill_code_is_short_plain_text(): void
    {
        self::assertSame('4.0.1/C1.3', TaxonomyRegistrar::sanitizeSkillCode(' <b>4.0.1/C1.3</b> '));
        self::assertSame(
            str_repeat('x', TaxonomyRegistrar::SKILL_CODE_MAX_LENGTH),
            TaxonomyRegistrar::sanitizeSkillCode(str_repeat('x', 100)),
        );
        self::assertSame('', TaxonomyRegistrar::sanitizeSkillCode(null));
    }

    #[Test]
    public function authorize_edit_skill_term_asks_whether_the_user_can_edit_that_term(): void
    {
        WordPressSpy::$userCanResult = true;

        self::assertTrue(TaxonomyRegistrar::authorizeEditSkillTerm(false, '_portfolio_skill_code', 15, 3));
        self::assertSame([[3, 'edit_term', 15]], WordPressSpy::$userCanCalls);
    }

    #[Test]
    public function ensure_skill_referential_terms_inserts_the_missing_parents_once(): void
    {
        $this->registrar->ensureSkillReferentialTerms();

        self::assertSame(
            [
                ['term' => 'Compétences 4.0.1', 'taxonomy' => 'skill', 'args' => ['slug' => '4-0-1']],
                ['term' => 'Compétences 4.0.2', 'taxonomy' => 'skill', 'args' => ['slug' => '4-0-2']],
            ],
            WordPressSpy::$insertedTerms,
        );

        $this->registrar->ensureSkillReferentialTerms();

        self::assertCount(2, WordPressSpy::$insertedTerms, 'second call must be a no-op');
    }

    #[Test]
    public function ensure_skill_referential_terms_only_inserts_what_is_missing(): void
    {
        WordPressSpy::$existingTerms['skill'] = ['4-0-1'];

        $this->registrar->ensureSkillReferentialTerms();

        self::assertCount(1, WordPressSpy::$insertedTerms);
        self::assertSame('4-0-2', WordPressSpy::$insertedTerms[0]['args']['slug']);
    }

    #[Test]
    public function ensure_project_status_terms_inserts_the_four_closed_statuses(): void
    {
        $this->registrar->ensureProjectStatusTerms();

        $slugs = array_map(static fn (array $t): string => $t['args']['slug'], WordPressSpy::$insertedTerms);

        self::assertSame(['en-cours', 'stable', 'archive', 'prototype'], $slugs);
        self::assertSame('Archivé', WordPressSpy::$insertedTerms[2]['term']);
    }
}
