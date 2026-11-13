<?php
/**
 * "My notes": a visitor's own notes on a recipe, kept in their browser.
 *
 * Nothing is sent anywhere and nobody else can see them - which is the point of a note
 * like "use less salt next time". Hidden until recipe-tools.js shows it: without the
 * script the box could not keep what is typed into it.
 *
 * @var string $unique_id The page's id prefix.
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="pxr-my-notes pxr:mb-8" data-pxr-my-notes hidden
         aria-labelledby="pxr-my-notes-heading-<?php echo esc_attr( $unique_id ); ?>">
    <h2 id="pxr-my-notes-heading-<?php echo esc_attr( $unique_id ); ?>"
        class="pxr-custom-single__section-title pxr:text-xl pxr:font-bold pxr:mb-2">
        <?php esc_html_e( 'My notes', 'plugixa-recipe' ); ?>
    </h2>
    <p class="pxr-my-notes__hint pxr:text-sm pxr:opacity-70 pxr:mb-2" id="pxr-my-notes-hint-<?php echo esc_attr( $unique_id ); ?>">
        <?php esc_html_e( 'Only you can see these. They are kept in this browser, on this device.', 'plugixa-recipe' ); ?>
    </p>
    <textarea class="pxr-my-notes__text" rows="3" maxlength="2000" data-pxr-my-notes-text
              aria-labelledby="pxr-my-notes-heading-<?php echo esc_attr( $unique_id ); ?>"
              aria-describedby="pxr-my-notes-hint-<?php echo esc_attr( $unique_id ); ?>"
              placeholder="<?php esc_attr_e( 'What would you change next time?', 'plugixa-recipe' ); ?>"></textarea>
    <span class="pxr-my-notes__status pxr:text-sm pxr:opacity-70" role="status" aria-live="polite" data-pxr-my-notes-status
          data-saved="<?php esc_attr_e( 'Saved on this device.', 'plugixa-recipe' ); ?>"
          data-failed="<?php esc_attr_e( 'This browser would not keep the note.', 'plugixa-recipe' ); ?>"></span>
</section>
