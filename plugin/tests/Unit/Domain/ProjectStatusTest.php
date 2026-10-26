<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
}
