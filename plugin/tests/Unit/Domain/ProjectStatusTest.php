<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Domain\ProjectStatus;
use Voltz\PortfolioMd\Taxonomies\TaxonomyRegistrar;

#[CoversClass(ProjectStatus::class)]
final class ProjectStatusTest extends TestCase
{
    #[Test]
    public function the_domain_enum_and_the_taxonomy_terms_never_drift_apart(): void
    {
        $fromTaxonomy = TaxonomyRegistrar::PROJECT_STATUS_TERMS;
        $fromDomain = [];
        foreach (ProjectStatus::cases() as $status) {
            $fromDomain[$status->value] = $status->label();
        }

        self::assertSame($fromTaxonomy, $fromDomain);
    }

    #[Test]
    public function values_are_the_closed_list_of_the_structure_document(): void
    {
        self::assertSame(['en-cours', 'stable', 'archive', 'prototype'], ProjectStatus::values());
    }

    #[Test]
    public function the_label_without_a_language_is_the_french_one(): void
    {
        foreach (ProjectStatus::cases() as $status) {
            self::assertSame($status->label(Language::Fr), $status->label(), $status->value);
        }
    }

    #[Test]
    public function every_status_has_a_label_in_every_language_of_the_site(): void
    {
        $table = [];
        foreach (ProjectStatus::cases() as $status) {
            foreach (Language::cases() as $language) {
                $label = $status->label($language);
                self::assertNotSame('', $label, "{$status->value} in {$language->value}");
                $table[$status->value][$language->value] = $label;
            }
        }

        self::assertSame([
            'en-cours' => ['fr' => 'En cours', 'de' => 'In Arbeit', 'en' => 'In progress'],
            'stable' => ['fr' => 'Stable', 'de' => 'Stabil', 'en' => 'Stable'],
            'archive' => ['fr' => 'Archivé', 'de' => 'Archiviert', 'en' => 'Archived'],
            'prototype' => ['fr' => 'Prototype', 'de' => 'Prototyp', 'en' => 'Prototype'],
        ], $table);
    }
}
