<?php
/**
 * The method of a single recipe.
 *
 * A part of the single recipe view. single-recipe.php includes it, and so does any other
 * layout of the recipe page, so it reads the variables single/vars.php sets up.
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables shared with single-recipe.php, not global-namespace pollution.
?>
<!-- Instructions (full-width below hero) -->
<?php if ( ! empty( $instruction_groups ) ) : ?>
    <section class="pxr-custom-single__instructions pxr:mb-8">
        <div class="pxr-custom-single__section">
            <h2 class="pxr-custom-single__section-title pxr:text-xl pxr:font-bold pxr:mb-6 pxr:pb-2 pxr:border-b">
                <?php esc_html_e( 'Instructions', 'plugixa-recipe' ); ?>
            </h2>
            <?php
            /**
             * Fires under the Instructions heading of a single recipe: cook mode.
             *
             * @since 1.2.0
             *
             * @param object $recipe    The presented recipe.
             * @param string $unique_id The page's id prefix.
             */
            do_action( 'plugixa_recipe_single_method_tools', $recipe, $unique_id );
            ?>
            <?php
            $step_counter = 0;
            foreach ( $instruction_groups as $group ) :
            ?>
                <?php if ( ! empty( $group->name ) ) : ?>
                    <h3 class="pxr-custom-single__group-title pxr:text-lg pxr:font-semibold pxr:mt-6 pxr:mb-4" data-pxr-step-group>
                        <?php echo esc_html( $group->name ); ?>
                    </h3>
                <?php endif; ?>
                <?php if ( ! empty( $group->steps ) && is_array( $group->steps ) ) : ?>
                    <ol class="pxr-custom-single__step-list pxr:list-none pxr:p-0 pxr:m-0 pxr:space-y-6">
                        <?php foreach ( $group->steps as $step ) :
                            $step_counter++;
                        ?>
                            <li class="pxr-custom-single__step pxr:flex pxr:gap-4" data-pxr-step="<?php echo esc_attr( $step_counter ); ?>">
                                <span class="pxr-custom-single__step-number pxr:flex-shrink-0 pxr:w-8 pxr:h-8 pxr:rounded-full pxr:flex pxr:items-center pxr:justify-center pxr:font-bold pxr:text-sm"
                                      aria-hidden="true">
                                    <?php echo esc_html( $step_counter ); ?>
                                </span>
                                <div class="pxr-custom-single__step-content pxr:flex-grow">
                                    <?php if ( ! empty( $step->title ) || ! empty( $step->timer_label ) ) : ?>
                                        <div class="pxr-custom-single__step-head pxr:flex pxr:flex-wrap pxr:items-center pxr:gap-2 pxr:mb-1">
                                            <?php if ( ! empty( $step->title ) ) : ?>
                                                <strong class="pxr-custom-single__step-title"><?php echo esc_html( $step->title ); ?></strong>
                                            <?php endif; ?>
                                            <?php if ( ! empty( $step->timer_label ) ) : ?>
                                                <span class="pxr-custom-single__step-time pxr:text-xs pxr:px-2 pxr:py-1 pxr:rounded-full pxr:border"
                                                      data-pxr-timer-seconds="<?php echo esc_attr( $step->timer_seconds ); ?>">
                                                    <?php echo esc_html( $step->timer_label ); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="pxr-custom-single__step-text pxr:leading-relaxed" data-pxr-step-text>
                                        <?php echo wp_kses_post( $step->text ?? '' ); ?>
                                    </div>
                                    <?php if ( ! empty( $step->image_url ) ) : ?>
                                        <div class="pxr-custom-single__step-image pxr:mt-3 pxr:rounded-lg pxr:overflow-hidden">
                                            <img src="<?php echo esc_url( $step->image_url ); ?>"
                                                 alt="<?php
                                                     /* translators: %d: step number */
                                                     echo esc_attr( sprintf( __( 'Step %d', 'plugixa-recipe' ), $step_counter ) );
                                                 ?>"
                                                 class="pxr:w-full pxr:h-auto pxr:rounded-lg"
                                                 loading="lazy" />
                                        </div>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $step->video_url ) ) : ?>
                                        <p class="pxr-custom-single__step-video pxr:mt-2 pxr:mb-0 pxr:text-sm">
                                            <a href="<?php echo esc_url( $step->video_url ); ?>" rel="noopener" target="_blank">
                                                <?php
                                                /* translators: %d: step number */
                                                echo esc_html( sprintf( __( 'Watch step %d', 'plugixa-recipe' ), $step_counter ) );
                                                ?>
                                            </a>
                                        </p>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $step->tip ) ) : ?>
                                        <div class="pxr-custom-single__step-tip pxr:mt-2 pxr:py-2 pxr:px-3 pxr:rounded-md pxr:text-sm pxr:border-l-4"
                                             role="note">
                                            <strong><?php esc_html_e( 'Tip:', 'plugixa-recipe' ); ?></strong>
                                            <?php echo esc_html( $step->tip ); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
