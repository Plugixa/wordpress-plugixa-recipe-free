<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

/**
 * Widget Template: Recipe Count Badge
 *
 * @var int    $count  Total recipe count
 * @var string $prefix Text before the number
 * @var string $suffix Text after the number
 * @var string $style  'badge' or 'text'
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables, not global-namespace pollution.
?>

<span class="pxr-widget-count pxr-widget-count--<?php echo esc_attr( $style ); ?>">
    <?php if ( $prefix ) : ?><span class="pxr-widget-count__prefix"><?php echo esc_html( $prefix ); ?></span><?php endif; ?>
    <span class="pxr-widget-count__number"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
    <?php if ( $suffix ) : ?><span class="pxr-widget-count__suffix"><?php echo esc_html( $suffix ); ?></span><?php endif; ?>
</span>
