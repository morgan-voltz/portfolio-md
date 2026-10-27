<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Rest;

use Voltz\PortfolioMd\Rest\Controller\PreviewController;

/**
 * Registers every route of the plugin under its namespace
 * (01-plugin-php.md §7). The composition root calls it on `rest_api_init`,
 * and builds it only then: most requests never reach the API.
 *
 * One route so far, the preview of the admin editor; the public read
 * endpoints and the Git webhook will join it here.
 */
final class RouteRegistrar
{
    public const NAMESPACE = 'portfolio/v1';

    public function __construct(private readonly PreviewController $preview)
    {
    }

    public function register(): void
    {
        $this->preview->register(self::NAMESPACE);
    }
}
