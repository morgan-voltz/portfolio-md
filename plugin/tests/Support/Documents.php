<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\ProjectStatus;
use Voltz\PortfolioMd\Service\DocumentSkill;
use Voltz\PortfolioMd\Service\DocumentTag;
use Voltz\PortfolioMd\Service\PublishedDocument;

/**
 * Published documents for the tests of the read side: one of each kind,
 * fully filled, with named overrides for what a test wants to vary.
 */
final class Documents
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $overrides named arguments of PublishedDocument
     */
    public static function article(array $overrides = []): PublishedDocument
    {
        return new PublishedDocument(...[
            'id' => 42,
            'type' => ContentType::Article,
            'slug' => 'rust-ownership-intuition',
            'title' => 'Pourquoi Rust a changé ma façon de penser la mémoire',
            'link' => 'http://example.test/articles/rust-ownership-intuition/',
            'date' => self::utc('2026-04-21 22:00:00'),
            'updated' => self::utc('2026-04-22 10:30:00'),
            'markdown' => "---\ntitle: \"Pourquoi Rust a changé ma façon de penser la mémoire\"\nslug: \"rust-ownership-intuition\"\ntype: \"article\"\n---\n\nLe vrai problème.\n",
            'seoDescription' => 'Une intuition visuelle du borrow checker.',
            'tags' => [new DocumentTag('rust', 'Rust'), new DocumentTag('systems', 'Systems')],
            'subtitle' => 'Une intuition visuelle du borrow checker',
            'readingTime' => 8,
            'featured' => false,
            ...$overrides,
        ]);
    }

    /**
     * @param array<string, mixed> $overrides named arguments of PublishedDocument
     */
    public static function project(array $overrides = []): PublishedDocument
    {
        return new PublishedDocument(...[
            'id' => 23,
            'type' => ContentType::Project,
            'slug' => 'momocad',
            'title' => 'MomoCad',
            'link' => 'http://example.test/projets/momocad/',
            'date' => self::utc('2026-09-30 22:00:00'),
            'updated' => self::utc('2026-10-27 13:13:00'),
            'markdown' => "---\ntitle: \"MomoCad\"\nslug: \"momocad\"\ntype: \"project\"\nstatus: \"en-cours\"\n---\n\nLe problème.\n",
            'seoDescription' => 'Un éditeur de plans.',
            'tags' => [new DocumentTag('csharp', 'C#'), new DocumentTag('avalonia', 'Avalonia')],
            'subtitle' => '',
            'readingTime' => 0,
            'featured' => true,
            'status' => ProjectStatus::EnCours,
            'skills' => [new DocumentSkill('4-0-2-e01', '4.0.2/E01', 'Concevoir une architecture'), new DocumentSkill('4-0-2-e07', '4.0.2/E07', 'Tester')],
            'isFilRouge' => true,
            'role' => 'Développeur unique',
            'periodStart' => self::utc('2026-09-01 00:00:00'),
            'periodEnd' => null,
            'repoUrl' => 'https://gitlab.example/momocad',
            'demoUrl' => '',
            'client' => '',
            ...$overrides,
        ]);
    }

    /**
     * @param array<string, mixed> $overrides named arguments of PublishedDocument
     */
    public static function page(array $overrides = []): PublishedDocument
    {
        return new PublishedDocument(...[
            'id' => 195,
            'type' => ContentType::Page,
            'slug' => 'a-propos',
            'title' => 'À propos',
            'link' => 'http://example.test/a-propos/',
            'date' => self::utc('2026-10-25 22:00:00'),
            'updated' => self::utc('2026-10-27 12:07:00'),
            'markdown' => "---\ntitle: \"À propos\"\nslug: \"a-propos\"\ntype: \"page\"\n---\n\nLe parcours.\n",
            'seoDescription' => 'Qui je suis.',
            ...$overrides,
        ]);
    }

    public static function utc(string $instant): DateTimeImmutable
    {
        return new DateTimeImmutable($instant, new DateTimeZone('UTC'));
    }
}
