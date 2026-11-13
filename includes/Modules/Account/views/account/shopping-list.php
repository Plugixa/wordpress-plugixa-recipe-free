<?php
/**
 * [plugixa_shopping_list] - the shell. The list itself is in the visitor's browser
 * (shopping-list.js); a signed-in visitor's copy is also kept in their account.
 *
 * @var string $login_url Log in and come back here.
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="pxr-account pxr-shopping pxr-recipes-wrapper" data-pxr-shopping-list hidden>

    <form class="pxr-shopping__add" data-pxr-shopping-form>
        <label class="pxr-shopping__field pxr-shopping__field--small">
            <span><?php esc_html_e( 'Amount', 'plugixa-recipe' ); ?></span>
            <input type="text" name="quantity" autocomplete="off" inputmode="text" maxlength="50" />
        </label>
        <label class="pxr-shopping__field">
            <span><?php esc_html_e( 'Item', 'plugixa-recipe' ); ?></span>
            <input type="text" name="name" autocomplete="off" maxlength="200" required />
        </label>
        <button type="submit" class="pxr-tools__btn pxr-tools__btn--primary"><?php esc_html_e( 'Add', 'plugixa-recipe' ); ?></button>
    </form>

    <p class="pxr-account__state" data-pxr-shopping-empty hidden>
        <?php esc_html_e( 'Your list is empty. Add an item above, or use "Add to shopping list" on a recipe.', 'plugixa-recipe' ); ?>
    </p>

    <ul class="pxr-shopping__items" data-pxr-shopping-items role="list"></ul>

    <div class="pxr-shopping__actions" data-pxr-shopping-actions hidden>
        <button type="button" class="pxr-tools__btn" data-pxr-shopping-clear-checked><?php esc_html_e( 'Remove ticked items', 'plugixa-recipe' ); ?></button>
        <button type="button" class="pxr-tools__btn" data-pxr-shopping-copy><?php esc_html_e( 'Copy list', 'plugixa-recipe' ); ?></button>
        <button type="button" class="pxr-tools__btn" data-pxr-shopping-print><?php esc_html_e( 'Print', 'plugixa-recipe' ); ?></button>
        <button type="button" class="pxr-tools__btn" data-pxr-shopping-clear><?php esc_html_e( 'Clear list', 'plugixa-recipe' ); ?></button>
    </div>

    <p class="pxr-shopping__status">
        <span role="status" aria-live="polite" data-pxr-shopping-status></span>
        <span data-pxr-shopping-signed-out hidden>
            <?php esc_html_e( 'This list is kept on this device.', 'plugixa-recipe' ); ?>
            <a href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Log in to keep it on all your devices.', 'plugixa-recipe' ); ?></a>
        </span>
    </p>

    <template data-pxr-shopping-template>
        <li class="pxr-shopping__item">
            <label class="pxr-shopping__label">
                <input type="checkbox" class="pxr-custom-checkbox" data-pxr-shopping-check />
                <span data-pxr-shopping-text></span>
            </label>
            <button type="button" class="pxr-shopping__remove" data-pxr-shopping-remove>
                <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </li>
    </template>
</div>
<noscript><p><?php esc_html_e( 'The shopping list needs JavaScript.', 'plugixa-recipe' ); ?></p></noscript>
