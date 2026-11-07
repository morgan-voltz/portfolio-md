<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Storage;

use Voltz\PortfolioMd\PostTypes\PostTypeRegistrar;

/**
 * Tells Polylang which post types of the plugin it manages (ADR-020, lot 2):
 * articles and projects, through the `pll_get_post_types` filter, so that a
 * fresh site needs no setting; native pages are Polylang's by default.
 *
 * The filter must be hooked early (Polylang caches the list after
 * `after_setup_theme`), hence from the composition root at plugin load. It
 * is harmless when Polylang is absent: nothing applies it. In Polylang's
 * settings screen (`$isSettings` true) the list is left alone: the types
 * the code manages are shown there as locked, which is what they are.
 */
final class PolylangPostTypes
{
    public function register(): void
    {
        add_filter('pll_get_post_types', [$this, 'manage'], 10, 2);
    }

    /**
     * @param array<string, string> $postTypes post type => post type, as Polylang keeps them
     *
     * @return array<string, string>
     */
    public function manage(array $postTypes, bool $isSettings = false): array
    {
        if ($isSettings) {
            return $postTypes;
        }

        foreach ([PostTypeRegistrar::ARTICLE, PostTypeRegistrar::PROJECT] as $postType) {
            $postTypes[$postType] = $postType;
        }

        return $postTypes;
    }
}
