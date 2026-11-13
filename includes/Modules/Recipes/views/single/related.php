<?php
/**
 * Related recipes under a single recipe.
 *
 * A part of the single recipe view. single-recipe.php includes it, and so does any other
 * layout of the recipe page, so it reads the variables single/vars.php sets up.
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables shared with single-recipe.php, not global-namespace pollution.
?>
<?php
// Related Recipes — auto-injected at bottom of single recipe page
$plugixa_recipe_widget_shortcodes = new \PlugixaRecipe\Modules\Shortcodes\Frontend\Widgets\RelatedRecipesWidget();
$plugixa_recipe_related_html      = $plugixa_recipe_widget_shortcodes->render_related_recipes( [
    'recipe_id' => $recipe_id,
    'limit'     => 4,
    'columns'   => 4,
    'relation'  => 'both',
    'title'     => __( 'You May Also Like', 'plugixa-recipe' ),
] );
// Late-escaped through the plugin's central allowlist. Every dynamic value inside is
// already escaped at its own output site during that render; this is the belt-and-braces
// pass the "escape late" guideline asks for at the echo itself.
echo wp_kses( $plugixa_recipe_related_html, plugixa_recipe_allowed_html() );
?>
