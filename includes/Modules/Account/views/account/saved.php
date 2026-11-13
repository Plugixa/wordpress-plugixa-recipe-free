<?php
/**
 * [plugixa_saved_recipes] - the shell. The same for every visitor; account.js fills it.
 *
 * @var string $login_url  Log in and come back here.
 * @var bool   $show_saved False when the Favorites app is off.
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $show_saved ) ) {
    return;
}
?>
<div class="pxr-account pxr-recipes-wrapper" data-pxr-account="saved">
    <p class="pxr-account__state" data-pxr-account-loading><?php esc_html_e( 'Loading your saved recipes…', 'plugixa-recipe' ); ?></p>

    <p class="pxr-account__state" data-pxr-account-signed-out hidden>
        <?php esc_html_e( 'Log in to see the recipes you saved.', 'plugixa-recipe' ); ?>
        <a class="pxr-jump__link" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Log in', 'plugixa-recipe' ); ?></a>
    </p>

    <p class="pxr-account__state" data-pxr-account-empty hidden>
        <?php esc_html_e( 'You have not saved a recipe yet. Use the Save button on any recipe to keep it here.', 'plugixa-recipe' ); ?>
    </p>

    <p class="pxr-account__state" data-pxr-account-error role="alert" hidden></p>

    <ul class="pxr-account__grid" data-pxr-account-list role="list"></ul>
    <span class="pxr-account__live" role="status" aria-live="polite" data-pxr-account-live></span>

    <template data-pxr-account-template>
        <li class="pxr-account__card">
            <a class="pxr-account__image" data-pxr-card-link tabindex="-1" aria-hidden="true">
                <img data-pxr-card-image alt="" loading="lazy" />
            </a>
            <div class="pxr-account__body">
                <h3 class="pxr-account__title"><a data-pxr-card-link data-pxr-card-title></a></h3>
                <p class="pxr-account__meta" data-pxr-card-meta></p>
                <button type="button" class="pxr-tools__btn" data-pxr-card-remove><?php esc_html_e( 'Remove', 'plugixa-recipe' ); ?></button>
            </div>
        </li>
    </template>
</div>
