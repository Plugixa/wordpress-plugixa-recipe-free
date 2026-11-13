<?php
/**
 * [plugixa_recipe_account] - the visitor's three pages as tabs.
 *
 * The panels are the same views the single shortcodes draw, included here, so there is
 * one shell per page whichever way it is reached.
 *
 * @var string $login_url  Log in and come back here.
 * @var bool   $show_saved False when the Favorites app is off.
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

$plugixa_recipe_tabs = array();
if ( ! empty( $show_saved ) ) {
    $plugixa_recipe_tabs['saved'] = array( __( 'Saved recipes', 'plugixa-recipe' ), 'account/saved.php' );
}
$plugixa_recipe_tabs['list']        = array( __( 'Shopping list', 'plugixa-recipe' ), 'account/shopping-list.php' );
$plugixa_recipe_tabs['submissions'] = array( __( 'My recipes', 'plugixa-recipe' ), 'account/submissions.php' );

$plugixa_recipe_first = (string) array_key_first( $plugixa_recipe_tabs );
?>
<div class="pxr-account-tabs pxr-recipes-wrapper" data-pxr-account-tabs>
    <div class="pxr-account-tabs__list" role="tablist" aria-label="<?php esc_attr_e( 'Your account', 'plugixa-recipe' ); ?>">
        <?php foreach ( $plugixa_recipe_tabs as $plugixa_recipe_key => $plugixa_recipe_tab ) : ?>
            <button type="button" role="tab" class="pxr-account-tabs__tab"
                    id="pxr-account-tab-<?php echo esc_attr( $plugixa_recipe_key ); ?>"
                    aria-controls="pxr-account-panel-<?php echo esc_attr( $plugixa_recipe_key ); ?>"
                    aria-selected="<?php echo $plugixa_recipe_key === $plugixa_recipe_first ? 'true' : 'false'; ?>"
                    tabindex="<?php echo $plugixa_recipe_key === $plugixa_recipe_first ? '0' : '-1'; ?>">
                <?php echo esc_html( $plugixa_recipe_tab[0] ); ?>
            </button>
        <?php endforeach; ?>
    </div>

    <?php foreach ( $plugixa_recipe_tabs as $plugixa_recipe_key => $plugixa_recipe_tab ) : ?>
        <div role="tabpanel" class="pxr-account-tabs__panel"
             id="pxr-account-panel-<?php echo esc_attr( $plugixa_recipe_key ); ?>"
             aria-labelledby="pxr-account-tab-<?php echo esc_attr( $plugixa_recipe_key ); ?>"
             <?php echo $plugixa_recipe_key === $plugixa_recipe_first ? '' : 'hidden'; ?>>
            <?php include plugixa_recipe_template( $plugixa_recipe_tab[1] ); ?>
        </div>
    <?php endforeach; ?>
</div>
