<?php
/**
 * The archive's page links.
 *
 * A part of the recipe archive view. archive.php includes it, and so does any other layout
 * of the archive page, so it reads the variables archive/vars.php sets up.
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables shared with archive.php, not global-namespace pollution.
?>
<?php if ( $total_pages > 1 ) : ?>
    <nav class="pxr-archive__pagination" aria-label="<?php esc_attr_e( 'Archive pagination', 'plugixa-recipe' ); ?>">
        <?php
        $base_url = home_url( "/{$archive_base}/" . rawurlencode( $archive_term->slug ) );

        // Previous
        if ( $archive_page > 1 ) :
            $prev_url = $archive_page === 2 ? $base_url . '/' : $base_url . '/page/' . ( $archive_page - 1 ) . '/';
            ?>
            <a href="<?php echo esc_url( $prev_url ); ?>" class="pxr-archive__pagination-link pxr-archive__pagination-link--prev">
                &laquo; <?php esc_html_e( 'Previous', 'plugixa-recipe' ); ?>
            </a>
        <?php endif; ?>

        <?php
        // Page numbers
        $range = 2;
        $start = max( 1, $archive_page - $range );
        $end   = min( $total_pages, $archive_page + $range );

        if ( $start > 1 ) : ?>
            <a href="<?php echo esc_url( $base_url . '/' ); ?>" class="pxr-archive__pagination-link">1</a>
            <?php if ( $start > 2 ) : ?>
                <span class="pxr-archive__pagination-dots">&hellip;</span>
            <?php endif; ?>
        <?php endif; ?>

        <?php for ( $i = $start; $i <= $end; $i++ ) :
            $page_url = $i === 1 ? $base_url . '/' : $base_url . '/page/' . $i . '/';
            ?>
            <?php if ( $i === $archive_page ) : ?>
                <span class="pxr-archive__pagination-link pxr-archive__pagination-link--current"><?php echo absint( $i ); ?></span>
            <?php else : ?>
                <a href="<?php echo esc_url( $page_url ); ?>" class="pxr-archive__pagination-link"><?php echo absint( $i ); ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ( $end < $total_pages ) : ?>
            <?php if ( $end < $total_pages - 1 ) : ?>
                <span class="pxr-archive__pagination-dots">&hellip;</span>
            <?php endif; ?>
            <a href="<?php echo esc_url( $base_url . '/page/' . $total_pages . '/' ); ?>" class="pxr-archive__pagination-link"><?php echo absint( $total_pages ); ?></a>
        <?php endif; ?>

        <?php
        // Next
        if ( $archive_page < $total_pages ) :
            $next_url = $base_url . '/page/' . ( $archive_page + 1 ) . '/';
            ?>
            <a href="<?php echo esc_url( $next_url ); ?>" class="pxr-archive__pagination-link pxr-archive__pagination-link--next">
                <?php esc_html_e( 'Next', 'plugixa-recipe' ); ?> &raquo;
            </a>
        <?php endif; ?>
    </nav>
<?php endif; ?>
