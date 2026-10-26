<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Voltz\PortfolioMd\Service\Clock;

/**
 * A clock stopped at a chosen instant, for deterministic tests.
 */
final class FixedClock implements Clock
{
    public function __construct(private readonly DateTimeImmutable $now)
    {
    }

    public static function at(string $utcInstant): self
    {
        return new self(new DateTimeImmutable($utcInstant, new DateTimeZone('UTC')));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
