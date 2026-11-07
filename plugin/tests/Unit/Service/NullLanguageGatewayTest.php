<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Service\NullLanguageGateway;

#[CoversClass(NullLanguageGateway::class)]
final class NullLanguageGatewayTest extends TestCase
{
    #[Test]
    public function accepts_every_call_without_doing_anything(): void
    {
        $this->expectNotToPerformAssertions();
        $gateway = new NullLanguageGateway();

        $gateway->setLanguage(42, Language::De);
        $gateway->linkTranslations(['fr' => 41, 'de' => 42]);
    }
}
