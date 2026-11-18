<?php
/**
 * The other terms of the archive's kind, as a row of links with this one marked: the way
 * from one category (or cuisine) to the next without going back to a menu.
 *
 * A part of the recipe archive view; it reads the variables archive/vars.php sets up. Its own
 * part, not a piece of archive/header.php: a layout that draws the header over a picture
 * colours everything inside it, and would have painted these links too.
 *
 * Drawn by the [plugixa_categories] / [plugixa_cuisines] widget, which already knows the
 * counts of PUBLISHED recipes and marks the term this page is. Terms with no published recipe
 * are left out: each link here is a promise of recipes.
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables shared with archive.php, not global-namespace pollution.

$terms_tag = 'cuisine' === $archive_type ? 'plugixa_cuisines' : 'plugixa_categories';
if ( ! shortcode_exists( $terms_tag ) ) {
    return;
}

$terms_row = do_shortcode( '[' . $terms_tag . ' style="pills" orderby="name" limit="24" hide_empty="true"]' );
if ( '' === trim( $terms_row ) ) {
    return;
}
?>
<nav class="pxr-archive__terms"
     aria-label="<?php echo esc_attr( 'cuisine' === $archive_type ? __( 'All cuisines', 'plugixa-recipe' ) : __( 'All categories', 'plugixa-recipe' ) ); ?>">
    <?php echo wp_kses( $terms_row, plugixa_recipe_allowed_html() ); ?>
</nav>
