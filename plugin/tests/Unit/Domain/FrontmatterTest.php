<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Domain\Frontmatter;
use Voltz\PortfolioMd\Domain\InvalidFrontmatterException;
use Voltz\PortfolioMd\Domain\ProjectStatus;

#[CoversClass(Frontmatter::class)]
#[CoversClass(InvalidFrontmatterException::class)]
final class FrontmatterTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function project(array $overrides = []): array
    {
        return array_merge([
            'title' => 'MomoCad',
            'slug' => 'momocad',
            'type' => 'project',
            'lang' => 'fr',
            'date' => new DateTimeImmutable('2026-10-26'),
            'subtitle' => 'Un logiciel de dessin',
            'seo_description' => 'Une phrase.',
            'featured' => true,
            'status' => 'en-cours',
            'role' => 'Solo',
            'period_start' => '2026-09-04',
            'period_end' => '',
            'repo_url' => '',
            'demo_url' => 'https://example.org/demo',
            'client' => '',
            'is_fil_rouge' => true,
            'tags' => ['csharp', 'dotnet'],
            'skills' => ['4.0.2/E00', '4.0.1/FR-13'],
        ], $overrides);
    }

    #[Test]
    public function builds_a_typed_project_frontmatter(): void
    {
        $fm = Frontmatter::fromArray(self::project());

        self::assertSame('MomoCad', $fm->title);
        self::assertSame('momocad', $fm->slug);
        self::assertSame(ContentType::Project, $fm->type);
        self::assertSame('2026-10-26', $fm->date?->format('Y-m-d'));
        self::assertTrue($fm->featured);
        self::assertSame(ProjectStatus::EnCours, $fm->status);
        self::assertSame('2026-09-04', $fm->periodStart?->format('Y-m-d'));
        self::assertNull($fm->periodEnd);
        self::assertSame('', $fm->repoUrl);
        self::assertSame('https://example.org/demo', $fm->demoUrl);
        self::assertTrue($fm->isFilRouge);
        self::assertSame(['csharp', 'dotnet'], $fm->tags);
        self::assertSame(['4.0.2/E00', '4.0.1/FR-13'], $fm->skills);
        self::assertNull($fm->readingTime);
    }

    #[Test]
    public function builds_a_page_with_the_common_keys_only(): void
    {
        $fm = Frontmatter::fromArray([
            'title' => 'À propos',
            'slug' => 'a-propos',
            'type' => 'page',
            'lang' => 'fr',
            'date' => '2026-10-26',
            'seo_description' => 'Qui je suis.',
        ]);

        self::assertSame(ContentType::Page, $fm->type);
        self::assertSame('2026-10-26', $fm->date?->format('Y-m-d'));
        self::assertNull($fm->status);
        self::assertFalse($fm->featured);
        self::assertSame([], $fm->tags);
    }

    #[Test]
    public function builds_an_article_with_a_reading_time(): void
    {
        $fm = Frontmatter::fromArray([
            'title' => 'Rust',
            'slug' => 'rust-ownership',
            'type' => 'article',
            'lang' => 'fr',
            'reading_time' => 8,
            'tags' => ['rust'],
        ]);

        self::assertSame(ContentType::Article, $fm->type);
        self::assertSame(8, $fm->readingTime);
        self::assertNull($fm->date);
    }

    #[Test]
    #[DataProvider('rejectedProvider')]
    public function rejects_invalid_metadata(array $overrides, string $expectedMessagePart): void
    {
        $this->expectException(InvalidFrontmatterException::class);
        $this->expectExceptionMessage($expectedMessagePart);

        Frontmatter::fromArray(self::project($overrides));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function rejectedProvider(): iterable
    {
        yield 'unknown key' => [['colour' => 'red'], 'unknown key(s) "colour"'];
        yield 'dropped category key gets a hint' => [['category' => 'Architecture'], 'ADR-015'];
        yield 'project key on an article' => [['type' => 'article', 'status' => 'stable'], 'unknown key(s)'];
        yield 'bad type' => [['type' => 'post'], 'invalid value for "type"'];
        yield 'bad status' => [['status' => 'finished'], 'invalid value for "status"'];
        yield 'bad slug' => [['slug' => 'Momo Cad'], 'invalid value for "slug"'];
        yield 'empty title' => [['title' => '   '], 'invalid value for "title"'];
        yield 'textual boolean' => [['featured' => 'yes'], 'invalid value for "featured"'];
        yield 'impossible date' => [['period_start' => '2026-02-30'], 'invalid value for "period_start"'];
        yield 'date in the wrong format' => [['date' => '26/10/2026'], 'invalid value for "date"'];
        yield 'url without scheme' => [['repo_url' => 'github.com/x'], 'invalid value for "repo_url"'];
        yield 'tag that is not a slug' => [['tags' => ['C#']], 'invalid value for "tags"'];
        yield 'duplicate tags' => [['tags' => ['rust', 'rust']], 'contains duplicates'];
        yield 'skill code in the wrong shape' => [['skills' => ['E07']], 'invalid value for "skills"'];
        yield 'tags as a string' => [['tags' => 'rust'], 'expected a YAML list'];
        yield 'seo description too long' => [['seo_description' => str_repeat('a', 161)], 'longer than 160'];
    }

    #[Test]
    public function a_project_must_declare_its_status(): void
    {
        $data = self::project();
        unset($data['status']);

        $this->expectException(InvalidFrontmatterException::class);
        $this->expectExceptionMessage('missing required key "status"');

        Frontmatter::fromArray($data);
    }

    #[Test]
    public function title_slug_and_type_are_always_required(): void
    {
        $this->expectException(InvalidFrontmatterException::class);
        $this->expectExceptionMessage('missing required key "slug"');

        Frontmatter::fromArray(['title' => 'x', 'type' => 'page', 'lang' => 'fr']);
    }

    #[Test]
    public function a_negative_reading_time_is_rejected(): void
    {
        $this->expectException(InvalidFrontmatterException::class);
        $this->expectExceptionMessage('reading_time');

        Frontmatter::fromArray(['title' => 'x', 'slug' => 'x', 'type' => 'article', 'lang' => 'fr', 'reading_time' => -1]);
    }

    #[Test]
    public function a_reading_time_above_the_shared_bound_is_rejected(): void
    {
        $this->expectException(InvalidFrontmatterException::class);
        $this->expectExceptionMessage('between 0 and 240');

        Frontmatter::fromArray(['title' => 'x', 'slug' => 'x', 'type' => 'article', 'lang' => 'fr', 'reading_time' => Frontmatter::READING_TIME_MAX_MINUTES + 1]);
    }

    #[Test]
    public function a_date_time_with_an_offset_keeps_its_calendar_day_at_midnight_utc(): void
    {
        $fm = Frontmatter::fromArray(self::project(['period_end' => new DateTimeImmutable('2026-10-26T01:00:00+02:00')]));

        self::assertSame('2026-10-26 00:00:00 UTC', $fm->periodEnd?->format('Y-m-d H:i:s e'));
        self::assertSame('UTC', $fm->periodStart?->getTimezone()->getName(), 'the string branch uses the same timezone');
    }

    #[Test]
    public function a_french_document_says_its_language_and_is_its_own_translation_key(): void
    {
        $fm = Frontmatter::fromArray(self::project());

        self::assertSame(Language::Fr, $fm->lang);
        self::assertSame('momocad', $fm->translationKey, 'a French document is its own translation key');
    }

    #[Test]
    public function lang_is_required(): void
    {
        $data = self::project();
        unset($data['lang']);

        $this->expectException(InvalidFrontmatterException::class);
        $this->expectExceptionMessage('missing required key "lang"');

        Frontmatter::fromArray($data);
    }

    #[Test]
    public function lang_accepts_each_language_with_the_matching_slug(): void
    {
        $de = Frontmatter::fromArray(self::project(['lang' => 'de', 'slug' => 'momocad-de', 'translation_key' => 'momocad']));
        $en = Frontmatter::fromArray(self::project(['lang' => 'en', 'slug' => 'momocad-en', 'translation_key' => 'momocad']));

        self::assertSame([Language::De, 'momocad-de', 'momocad'], [$de->lang, $de->slug, $de->translationKey]);
        self::assertSame([Language::En, 'momocad-en', 'momocad'], [$en->lang, $en->slug, $en->translationKey]);
    }

    #[Test]
    #[DataProvider('languageRejectedProvider')]
    public function rejects_invalid_language_metadata(array $overrides, string $expectedMessagePart): void
    {
        $this->expectException(InvalidFrontmatterException::class);
        $this->expectExceptionMessage($expectedMessagePart);

        Frontmatter::fromArray(self::project($overrides));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function languageRejectedProvider(): iterable
    {
        yield 'lang outside the list' => [['lang' => 'es'], 'expected one of fr, de, en'];
        yield 'lang that is not a string' => [['lang' => 42], 'invalid value for "lang"'];
        yield 'french translation key different from the slug' => [['lang' => 'fr', 'translation_key' => 'other'], 'must use its own slug as key'];
        yield 'translation key that is not a slug' => [['lang' => 'de', 'slug' => 'momocad-de', 'translation_key' => 'Momo Cad'], 'invalid value for "translation_key"'];
        yield 'german project without the suffix' => [['lang' => 'de', 'slug' => 'momocad', 'translation_key' => 'momocad'], 'must end with "-de"'];
        yield 'english project without the suffix' => [['lang' => 'en', 'slug' => 'momocad', 'translation_key' => 'momocad'], 'must end with "-en"'];
        yield 'atlas-de in a french document' => [['lang' => 'fr', 'slug' => 'atlas-de'], 'must not end with "-de" or "-en"'];
        yield 'atlas-en in a french document' => [['slug' => 'atlas-en'], 'must not end with "-de" or "-en"'];
        yield 'german project with the english suffix' => [['lang' => 'de', 'slug' => 'momocad-en', 'translation_key' => 'momocad'], 'must end with "-de"'];
    }

    #[Test]
    public function translation_key_is_required_outside_french(): void
    {
        $this->expectException(InvalidFrontmatterException::class);
        $this->expectExceptionMessage('missing required key "translation_key"');

        Frontmatter::fromArray(self::project(['lang' => 'de', 'slug' => 'momocad-de']));
    }

    #[Test]
    public function a_french_document_may_write_its_own_slug_as_translation_key(): void
    {
        $fm = Frontmatter::fromArray(self::project(['lang' => 'fr', 'translation_key' => 'momocad']));

        self::assertSame('momocad', $fm->translationKey);
    }

    #[Test]
    public function an_english_article_says_its_language_in_its_slug(): void
    {
        $fm = Frontmatter::fromArray(['title' => 'Rust', 'slug' => 'rust-ownership-en', 'type' => 'article', 'lang' => 'en', 'translation_key' => 'rust-ownership']);

        self::assertSame(Language::En, $fm->lang);
        self::assertSame('rust-ownership', $fm->translationKey);

        $this->expectException(InvalidFrontmatterException::class);
        $this->expectExceptionMessage('must end with "-en"');
        Frontmatter::fromArray(['title' => 'Rust', 'slug' => 'rust-ownership', 'type' => 'article', 'lang' => 'en', 'translation_key' => 'rust-ownership']);
    }

    #[Test]
    public function pages_are_exempt_from_the_suffix_rule(): void
    {
        $german = Frontmatter::fromArray(['title' => 'Lebenslauf', 'slug' => 'lebenslauf', 'type' => 'page', 'lang' => 'de', 'translation_key' => 'cv']);
        $frenchWithASuffixLookingSlug = Frontmatter::fromArray(['title' => 'About', 'slug' => 'about-en', 'type' => 'page', 'lang' => 'fr']);

        self::assertSame(['lebenslauf', 'cv'], [$german->slug, $german->translationKey]);
        self::assertSame(['about-en', 'about-en'], [$frenchWithASuffixLookingSlug->slug, $frenchWithASuffixLookingSlug->translationKey]);
    }

    #[Test]
    public function allowed_keys_always_contain_the_language_keys(): void
    {
        foreach (ContentType::cases() as $type) {
            self::assertContains('lang', Frontmatter::allowedKeys($type), $type->value);
            self::assertContains('translation_key', Frontmatter::allowedKeys($type), $type->value);
        }
        self::assertSame(['title', 'slug', 'type', 'date', 'seo_description', 'lang', 'translation_key'], Frontmatter::allowedKeys(ContentType::Page));
        self::assertContains('reading_time', Frontmatter::allowedKeys(ContentType::Article));
        self::assertNotContains('reading_time', Frontmatter::allowedKeys(ContentType::Project));
    }
}
