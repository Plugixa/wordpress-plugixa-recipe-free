<?php
/**
 * The variables every part of the recipe archive view reads, taken from
 * $GLOBALS['plugixa_recipe_archive_data'] (filled by Frontend\ArchivePage). Included first,
 * in the scope that then includes the parts. $archive_term is null when there is nothing to draw.
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables shared with archive.php, not global-namespace pollution.

$data         = $GLOBALS['plugixa_recipe_archive_data'] ?? [];
$archive_term     = $data['taxonomy'] ?? null;
$archive_type         = $data['type'] ?? 'category';
$recipes      = $data['recipes'] ?? [];
$archive_page         = $data['page'] ?? 1;
$total_pages  = $data['total_pages'] ?? 1;
$total        = $data['total'] ?? 0;
$archive_base = $data['archive_base'] ?? 'recipe-category';
$view_config  = $data['view_config'] ?? [];


$type_label = $archive_type === 'category'
    ? __( 'Category', 'plugixa-recipe' )
    : __( 'Cuisine', 'plugixa-recipe' );

$image_url = '';
if ( ! empty( $archive_term->image_id ) ) {
    $image_url = wp_get_attachment_image_url( (int) $archive_term->image_id, 'large' ) ?: '';
}
