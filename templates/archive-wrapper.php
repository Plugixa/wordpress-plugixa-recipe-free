<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

/**
 * Archive Page Wrapper Template
 *
 * Wraps the archive.php template with theme header/footer.
 * Mirrors single-recipe-wrapper.php for block/classic theme compatibility.
 *
 * @package PlugixaRecipe
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables, not global-namespace pollution.

$data = $GLOBALS['plugixa_recipe_archive_data'] ?? null;
if ( ! $data ) {
    return;
}

if ( wp_is_block_theme() ) {
    ob_start();
    block_template_part( 'header' );
    $header_html = ob_get_clean();

    ob_start();
    block_template_part( 'footer' );
    $footer_html = ob_get_clean();
    ?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo( 'charset' ); ?>">
        <?php wp_head(); ?>
    </head>
    <body <?php body_class(); ?>>
    <?php wp_body_open(); ?>
    <div class="wp-site-blocks">
        <header class="wp-block-template-part">
            <?php echo $header_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- output of core's block_template_part(), i.e. the active THEME's own markup, buffered above so blocks can register their assets before wp_head() runs. WordPress core does exactly this in wp-includes/template-canvas.php ("echo $template_html;") for the same reason. No plugin or user data passes through here, and any escaping would corrupt the theme's markup. ?>
        </header>
        <main id="wp--skip-link--target" class="wp-block-group has-global-padding is-layout-constrained">
            <div class="pxr-recipes-wrapper pxr-archive-page" style="max-width: 1200px; margin: 2rem auto; padding: 0 1rem;">
                <?php include PLUGIXA_RECIPE_PATH . 'templates/archive.php'; ?>
            </div>
        </main>
        <footer class="wp-block-template-part">
            <?php echo $footer_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- output of core's block_template_part(); see the matching note on the header above. ?>
        </footer>
    </div>
    <?php wp_footer(); ?>
    </body>
    </html>
    <?php
} else {
    get_header();
    ?>
    <div class="pxr-recipes-wrapper pxr-archive-page" style="max-width: 1200px; margin: 2rem auto; padding: 0 1rem;">
        <?php include PLUGIXA_RECIPE_PATH . 'templates/archive.php'; ?>
    </div>
    <?php
    get_footer();
}
