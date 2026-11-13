<?php
/**
 * The archive's header: image, type, name, description and count.
 *
 * A part of the recipe archive view. archive.php includes it, and so does any other layout
 * of the archive page, so it reads the variables archive/vars.php sets up.
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables shared with archive.php, not global-namespace pollution.
?>
<header class="pxr-archive__header">
    <?php if ( $image_url ) : ?>
        <div class="pxr-archive__hero">
            <img src="<?php echo esc_url( $image_url ); ?>"
                 alt="<?php echo esc_attr( $archive_term->name ); ?>"
                 class="pxr-archive__hero-image"
                 loading="lazy" />
        </div>
    <?php endif; ?>

    <div class="pxr-archive__header-content">
        <span class="pxr-archive__type-label"><?php echo esc_html( $type_label ); ?></span>
        <h1 class="pxr-archive__title"><?php echo esc_html( $archive_term->name ); ?></h1>

        <?php if ( ! empty( $archive_term->description ) ) : ?>
            <p class="pxr-archive__description"><?php echo wp_kses_post( $archive_term->description ); ?></p>
        <?php endif; ?>

        <span class="pxr-archive__count">
            <?php
            echo esc_html(
                sprintf(
                    /* translators: %d: number of recipes. */
                    _n( '%d Recipe', '%d Recipes', $total, 'plugixa-recipe' ),
                    $total
                )
            );
            ?>
        </span>
    </div>
</header>
