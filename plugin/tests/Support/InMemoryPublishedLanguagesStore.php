<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Support;

use Voltz\PortfolioMd\Service\PublishedLanguagesStore;

/**
 * PublishedLanguagesStore backed by a value, for the unit tests: whatever
 * shape a test puts in is handed back as it is, and every write is kept.
 */
final class InMemoryPublishedLanguagesStore implements PublishedLanguagesStore
{
    /** @var list<list<string>> */
    public array $writes = [];

    public function __construct(public mixed $value = null)
    {
    }

    public function read(): mixed
    {
        return $this->value;
    }

    public function write(array $slugs): void
    {
        $this->writes[] = $slugs;
        $this->value = $slugs;
    }
}
