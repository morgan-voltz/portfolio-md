<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use DateTimeImmutable;

/**
 * Where the import service reads the time, so that tests can fix it.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
