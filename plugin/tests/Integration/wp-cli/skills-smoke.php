<?php

/**
 * Smoke test of the skills synchronization against a real WordPress, run
 * through WP-CLI. It exercises Storage\WordPressSkillTermGateway, the only
 * part of the chain the unit suite cannot reach.
 *
 * From the repository root, with the stack up and the plugin activated:
 *
 *   podman-compose --profile tools run --rm wpcli wp eval-file \
 *       wp-content/plugins/portfolio-md/tests/Integration/wp-cli/skills-smoke.php \
 *       /srv/portfolio-content/skills.yaml
 *
 * The script synchronizes the given grid (dry run, then for real, twice),
 * checks the term metas, then plants a foreign term to see it reported as an
 * orphan and left in place, and removes that foreign term afterwards. It
 * leaves the synchronized taxonomy as is: that is the desired state.
 *
 * No `declare(strict_types=1)`: `wp eval-file` runs the file through eval().
 */

use Voltz\PortfolioMd\Parsing\SkillsGridLoader;
use Voltz\PortfolioMd\Service\SkillTermSynchronizer;
use Voltz\PortfolioMd\Storage\WordPressSkillTermGateway;
use Voltz\PortfolioMd\Taxonomies\TaxonomyRegistrar;

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "This script must be run through `wp eval-file`.\n");
    exit(1);
}

$gridFile = $args[0] ?? '/srv/portfolio-content/skills.yaml';
$failures = 0;

$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    if ($ok) {
        WP_CLI::log("PASS  {$label}");

        return;
    }

    $failures++;
    WP_CLI::warning("FAIL  {$label}" . ($detail !== '' ? " ({$detail})" : ''));
};

$countTerms = static fn (): int => (int) wp_count_terms(['taxonomy' => TaxonomyRegistrar::SKILL, 'hide_empty' => false]);

$grid = (new SkillsGridLoader())->load($gridFile);
$expectedTerms = count($grid->referentials) + $grid->countSkills();
$gateway = new WordPressSkillTermGateway();
$synchronizer = new SkillTermSynchronizer($gateway);

// 1. Dry run writes nothing ----------------------------------------------------------

$before = $countTerms();
$dry = $synchronizer->synchronize($grid, dryRun: true);
$check('dry run writes nothing', $countTerms() === $before, "terms {$before} -> {$countTerms()}");
$check('dry run reports the whole grid', $dry->createdCount() + $dry->updatedCount() + $dry->unchangedCount() === $expectedTerms);

// 2. Real synchronization, then an idempotent second pass --------------------------------

$first = $synchronizer->synchronize($grid);
$check('no failure on the first synchronization', !$first->hasFailures(), $first->summary());
$check('every grid entry exists afterwards', $countTerms() === $expectedTerms, "{$countTerms()} terms for {$expectedTerms} entries");

$second = $synchronizer->synchronize($grid);
$check('second pass is idempotent', $second->createdCount() === 0 && $second->updatedCount() === 0 && $second->recodedCount() === 0 && !$second->hasFailures(), $second->summary());
$check('second pass claims every term', $second->unchangedCount() === $expectedTerms && $second->orphanCount() === 0, $second->summary());

// 3. Term metas and hierarchy -----------------------------------------------------------------

foreach ($grid->referentials as $referential) {
    $parent = get_term_by('slug', $referential->slug, TaxonomyRegistrar::SKILL);
    $check("referential {$referential->code} is a top-level term carrying its code", $parent instanceof WP_Term && (int) $parent->parent === 0 && get_term_meta($parent->term_id, TaxonomyRegistrar::SKILL_CODE_META, true) === $referential->code);

    foreach (array_slice($referential->skills, 0, 2) as $skill) {
        $child = get_term_by('slug', $skill->slug, TaxonomyRegistrar::SKILL);
        $check(
            "skill {$skill->code} sits under its referential with its code and name",
            $child instanceof WP_Term && $parent instanceof WP_Term && (int) $child->parent === (int) $parent->term_id
                && get_term_meta($child->term_id, TaxonomyRegistrar::SKILL_CODE_META, true) === $skill->code
                && $child->name === $skill->name,
        );
    }
}

$sample = $grid->referentials[0]->skills[0];
$found = $gateway->findByCode($sample->code);
$check('gateway finds a term by its code meta', $found !== null && $found->slug === $sample->slug);
$check('gateway finds a term by slug under its parent', $found !== null && $gateway->findBySlug($sample->slug, $found->parentId)?->code === $sample->code);
$check('gateway does not find a child slug at top level', $gateway->findBySlug($sample->slug, 0) === null);

// 4. A foreign term is reported as an orphan and left untouched ---------------------------

$parentTerm = get_term_by('slug', $grid->referentials[0]->slug, TaxonomyRegistrar::SKILL);
$foreign = wp_insert_term('Compétence étrangère (smoke)', TaxonomyRegistrar::SKILL, [
    'slug' => 'zz-smoke-orphan',
    'parent' => $parentTerm instanceof WP_Term ? $parentTerm->term_id : 0,
]);

if (is_wp_error($foreign)) {
    WP_CLI::error('could not plant the foreign term: ' . $foreign->get_error_message());
}

try {
    update_term_meta((int) $foreign['term_id'], TaxonomyRegistrar::SKILL_CODE_META, '9.9.9/ZZ-99');
    $third = $synchronizer->synchronize($grid);

    $check('a term absent from the grid is reported as an orphan', $third->orphanCount() === 1, $third->summary());
    $check('the orphan line names its code', str_contains(implode("\n", $third->lines()), '9.9.9/ZZ-99'));
    $check('the orphan is left in place', get_term((int) $foreign['term_id'], TaxonomyRegistrar::SKILL) instanceof WP_Term);
    $check('nothing else changed', $third->createdCount() === 0 && $third->updatedCount() === 0 && !$third->hasFailures(), $third->summary());

    // 5. A slug collision is refused loudly rather than silently suffixed ----------------------
    $collision = null;
    try {
        $gateway->insert('Doublon de slug (smoke)', $sample->slug, '', 0, null);
    } catch (RuntimeException $exception) {
        $collision = $exception->getMessage();
    }
    $check('a duplicate slug raises an explicit error', $collision !== null && str_contains($collision, 'Slug collision'), (string) $collision);
    $suffixed = get_term_by('slug', $sample->slug . '-2', TaxonomyRegistrar::SKILL);
    if ($suffixed instanceof WP_Term) {
        wp_delete_term($suffixed->term_id, TaxonomyRegistrar::SKILL);
    }
} finally {
    wp_delete_term((int) $foreign['term_id'], TaxonomyRegistrar::SKILL);
}

$check('the foreign term is cleaned up', get_term((int) $foreign['term_id'], TaxonomyRegistrar::SKILL) === null);
$check('the taxonomy is back to the grid', $countTerms() === $expectedTerms, "{$countTerms()} terms");

if ($failures > 0) {
    WP_CLI::error("{$failures} check(s) failed");
}

WP_CLI::success('all checks passed');
