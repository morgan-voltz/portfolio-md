<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Admin;

use Throwable;
use Voltz\PortfolioMd\Service\ImportOutcome;
use Voltz\PortfolioMd\Service\ImportResult;

/**
 * What the admin is told once a document went through the service: a
 * WordPress notice level (`success`, `info`, `warning`, `error`), one
 * sentence, and the post involved when there is one (the screens add the
 * links). Pure, so that the wording is tested without WordPress. The labels
 * are in French like the rest of the admin; the detail of a conflict or a
 * failure is the message of the service, in English like the CLI output.
 */
final readonly class ImportFeedback
{
    public function __construct(
        public string $level,
        public string $message,
        public ?int $postId = null,
    ) {
    }

    public static function forResult(ImportResult $result, bool $dryRun): self
    {
        $target = "{$result->type->value}/{$result->slug}";
        $post = $result->postId === null ? '' : " (post {$result->postId})";

        return match ($result->outcome) {
            ImportOutcome::Created => new self(
                'success',
                $dryRun ? "Vérification : {$target} serait créé." : "Créé : {$target}{$post}.",
                $result->postId,
            ),
            ImportOutcome::Updated => new self(
                'success',
                $dryRun ? "Vérification : {$target}{$post} serait mis à jour." : "Mis à jour : {$target}{$post}.",
                $result->postId,
            ),
            ImportOutcome::Unchanged => new self(
                'info',
                "Inchangé : {$target}{$post} est identique au document déjà importé.",
                $result->postId,
            ),
            ImportOutcome::Conflict => new self(
                'warning',
                "Conflit : {$target}{$post} n’a pas été écrit ({$result->detail}).",
                $result->postId,
            ),
        };
    }

    public static function forFailure(Throwable $failure): self
    {
        return new self('error', 'Échec : ' . $failure->getMessage());
    }
}
