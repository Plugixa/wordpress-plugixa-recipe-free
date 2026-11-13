<?php
/**
 * [plugixa_my_submissions] - the shell. The same for every visitor; account.js fills it.
 *
 * @var string $login_url Log in and come back here.
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="pxr-account pxr-recipes-wrapper" data-pxr-account="submissions">
    <p class="pxr-account__state" data-pxr-account-loading><?php esc_html_e( 'Loading your recipes…', 'plugixa-recipe' ); ?></p>

    <p class="pxr-account__state" data-pxr-account-signed-out hidden>
        <?php esc_html_e( 'Log in to see the recipes you submitted.', 'plugixa-recipe' ); ?>
        <a class="pxr-jump__link" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Log in', 'plugixa-recipe' ); ?></a>
    </p>

    <p class="pxr-account__state" data-pxr-account-empty hidden>
        <?php esc_html_e( 'You have not submitted a recipe yet.', 'plugixa-recipe' ); ?>
    </p>

    <p class="pxr-account__state" data-pxr-account-error role="alert" hidden></p>

    <ul class="pxr-account__rows" data-pxr-account-list role="list"></ul>

    <template data-pxr-account-template>
        <li class="pxr-account__row">
            <span class="pxr-account__row-title">
                <a data-pxr-card-link data-pxr-card-title></a>
                <span data-pxr-card-plain-title></span>
            </span>
            <span class="pxr-account__row-date" data-pxr-card-meta></span>
            <span class="pxr-account__status" data-pxr-card-status></span>
        </li>
    </template>
</div>
