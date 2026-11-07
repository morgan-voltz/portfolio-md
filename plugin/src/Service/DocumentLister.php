<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use Voltz\PortfolioMd\Domain\ContentType;

/**
 * Port of the reconciliation (ADR-020, lot 2) towards the post store: every
 * post of a kind that holds a document, the trash included, so that the
 * languages and the translation groups the multilingual plugin holds can be
 * compared with what the plugin stores. Kept apart from ContentRepository,
 * whose doubles the import tests build by hand.
 */
interface DocumentLister
{
    /**
     * Every post of this kind that holds a document, oldest first, the
     * trash included and flagged as such.
     *
     * @return list<StoredContent>
     */
    public function listDocuments(ContentType $type): array;
}
