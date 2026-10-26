<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Voltz\PortfolioMd\Admin\ImportFeedback;
use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Service\ImportOutcome;
use Voltz\PortfolioMd\Service\ImportResult;

#[CoversClass(ImportFeedback::class)]
final class ImportFeedbackTest extends TestCase
{
    #[Test]
    public function a_creation_is_a_success_that_names_the_post(): void
    {
        $feedback = ImportFeedback::forResult(new ImportResult(ImportOutcome::Created, ContentType::Project, 'momocad', 'MomoCad', 23), false);

        self::assertSame('success', $feedback->level);
        self::assertSame('Créé : project/momocad (post 23).', $feedback->message);
        self::assertSame(23, $feedback->postId);
    }

    #[Test]
    public function a_dry_run_speaks_in_the_conditional_and_has_no_post_yet(): void
    {
        $feedback = ImportFeedback::forResult(new ImportResult(ImportOutcome::Created, ContentType::Article, 'rust', 'Rust', null), true);

        self::assertSame('success', $feedback->level);
        self::assertSame('Vérification : article/rust serait créé.', $feedback->message);
        self::assertNull($feedback->postId);
    }

    #[Test]
    public function an_update_names_the_post_in_both_modes(): void
    {
        $result = new ImportResult(ImportOutcome::Updated, ContentType::Project, 'momocad', 'MomoCad', 23);

        self::assertSame('Mis à jour : project/momocad (post 23).', ImportFeedback::forResult($result, false)->message);
        self::assertSame('Vérification : project/momocad (post 23) serait mis à jour.', ImportFeedback::forResult($result, true)->message);
    }

    #[Test]
    public function an_unchanged_document_is_information_not_success(): void
    {
        $feedback = ImportFeedback::forResult(new ImportResult(ImportOutcome::Unchanged, ContentType::Project, 'momocad', 'MomoCad', 23), false);

        self::assertSame('info', $feedback->level);
        self::assertSame('Inchangé : project/momocad (post 23) est identique au document déjà importé.', $feedback->message);
    }

    #[Test]
    public function a_conflict_is_a_warning_that_carries_the_reason_of_the_service(): void
    {
        $result = new ImportResult(ImportOutcome::Conflict, ContentType::Project, 'momocad', 'MomoCad', 23, 'last written from admin and changed since; use --force to overwrite');

        $feedback = ImportFeedback::forResult($result, false);

        self::assertSame('warning', $feedback->level);
        self::assertSame('Conflit : project/momocad (post 23) n’a pas été écrit (last written from admin and changed since; use --force to overwrite).', $feedback->message);
        self::assertSame(23, $feedback->postId);
    }

    #[Test]
    public function a_failure_is_an_error_that_quotes_the_exception(): void
    {
        $feedback = ImportFeedback::forFailure(new RuntimeException('Document: no frontmatter.'));

        self::assertSame('error', $feedback->level);
        self::assertSame('Échec : Document: no frontmatter.', $feedback->message);
        self::assertNull($feedback->postId);
    }
}
