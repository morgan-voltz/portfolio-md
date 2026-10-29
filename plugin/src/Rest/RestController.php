<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Rest;

/**
 * A group of routes of the plugin's REST namespace. RouteRegistrar hands
 * every controller the namespace on `rest_api_init`; each one registers
 * its own routes with `register_rest_route()`.
 */
interface RestController
{
    public function register(string $namespace): void;
}
