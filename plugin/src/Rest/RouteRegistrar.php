<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Rest;

/**
 * Registers every route of the plugin under its namespace
 * (01-plugin-php.md §7). The composition root calls it on `rest_api_init`,
 * and builds it only then: most requests never reach the API.
 *
 * The controllers are given in the order their routes should be registered:
 * the preview of the admin editor, the three public collections and the
 * sitemap today; the Git webhook will join them here.
 */
final class RouteRegistrar
{
    public const NAMESPACE = 'portfolio/v1';

    /** @var list<RestController> */
    private readonly array $controllers;

    public function __construct(RestController ...$controllers)
    {
        $this->controllers = array_values($controllers);
    }

    public function register(): void
    {
        foreach ($this->controllers as $controller) {
            $controller->register(self::NAMESPACE);
        }
    }
}
