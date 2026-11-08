<?php
/**
 * The archive of the projects, /<lang>/projets/ (ADR-022, point 8): the
 * list with fallback of the plugin instead of the main loop, which Polylang
 * filters by language and leaves empty in a language without projects of
 * its own. Every project is listed in every language, the ones not
 * translated as French cards with the badge, in the order of the French
 * archive. Same skeleton as the archive.php of GeneratePress (header,
 * #primary, main#main, its hooks, sidebars, footer); the title comes from
 * the theme, in the language of the page; no pagination, the whole
 * portfolio fits in one page.
 *
 * @package Portfolio_MD_Child
 */

if (!defined('ABSPATH')) {
    exit;
}

$portfolio_md_language = portfolio_md_child_current_language();

get_header(); ?>

	<div <?php generate_do_attr('content'); ?>>
		<main <?php generate_do_attr('main'); ?>>
			<?php
			do_action('generate_before_main_content');
			?>
			<header <?php generate_do_attr('page-header'); ?>>
				<h1 class="page-title"><?php echo esc_html(portfolio_md_child_text('projects', $portfolio_md_language)); ?></h1>
			</header>
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput -- escaped while rendered
			echo portfolio_md_child_render_cards(
				function_exists('portfolio_md_list_with_fallback') ? portfolio_md_list_with_fallback('project', $portfolio_md_language) : [],
				$portfolio_md_language
			);

			do_action('generate_after_main_content');
			?>
		</main>
	</div>

	<?php
	do_action('generate_after_primary_content_area');

	generate_construct_sidebars();

get_footer();
