<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

/**
 * Recipe Submission Form Template
 *
 * Tabbed layout with all fields matching the backend admin form.
 * Supports inline and modal display modes.
 *
 * @var string $unique_id           Unique shortcode instance ID
 * @var string $display_mode        'inline' or 'modal'
 * @var string $nonce               WP REST nonce
 * @var string $rest_url            REST endpoint URL
 * @var int    $max_file_size       Max upload size in MB
 * @var bool   $turnstile_enabled   Cloudflare Turnstile toggle
 * @var string $turnstile_site_key  Turnstile site key
 * @var array  $category_options    [{id, name}, ...] from DB
 * @var array  $cuisine_options     [{id, name}, ...] from DB
 * @var array  $course_options      [{id, name}, ...] from DB
 * @var array  $difficulty_options  [value => label]
 * @var array  $cost_options        [value => label]
 * @var array  $season_options      [value => label]
 * @var array  $unit_options        [value => label]
 * @var array  $settings            Plugin settings
 *
 * @package PlugixaRecipe
 * @since   1.2.0
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render variables, not global-namespace pollution.

$is_modal = $display_mode === 'modal';
$form_id  = 'pxr-form-' . esc_attr( $unique_id );

$input_classes  = 'pxr-custom-form-input';
$label_classes  = 'pxr-custom-form-label';
$select_classes = 'pxr-custom-form-input pxr-custom-form-select';
?>

<?php if ( $is_modal ) : ?>
<button type="button" class="pxr-custom-open-modal-btn"
        data-pxr-modal-trigger="<?php echo esc_attr( $form_id . '-modal' ); ?>">
    <svg class="pxr-custom-open-modal-btn__icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
    </svg>
    <?php esc_html_e( 'Submit a Recipe', 'plugixa-recipe' ); ?>
</button>
<div id="<?php echo esc_attr( $form_id . '-modal' ); ?>" class="pxr-custom-submission-modal"
     role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $form_id . '-modal-title' ); ?>">
<div class="pxr-custom-modal-backdrop" data-pxr-modal-close></div>
<div class="pxr-custom-modal-scroll">
<div class="pxr-custom-modal-center">
<div class="pxr-custom-modal-panel">
<div class="pxr-custom-modal-header">
    <h2 id="<?php echo esc_attr( $form_id . '-modal-title' ); ?>" class="pxr-custom-modal-title">
        <?php esc_html_e( 'Submit Your Recipe', 'plugixa-recipe' ); ?>
    </h2>
    <button type="button" class="pxr-custom-modal-close" data-pxr-modal-close
            aria-label="<?php esc_attr_e( 'Close', 'plugixa-recipe' ); ?>">
        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
        </svg>
    </button>
</div>
<?php endif; ?>

<div id="<?php echo esc_attr( $unique_id ); ?>" class="pxr-custom-submission-wrapper alignwide"
     data-pxr-recipe-form
     data-api-url="<?php echo esc_url( $rest_url ); ?>"
     data-nonce="<?php echo esc_attr( $nonce ); ?>"
     data-max-file-size="<?php echo esc_attr( $max_file_size ); ?>">

    <!-- Messages -->
    <div class="pxr-custom-form-message pxr:hidden" data-pxr-form-message role="alert" aria-live="polite"></div>

    <!-- Header -->
    <div class="pxr-custom-form-header">
        <h2 class="pxr-custom-form-header__title"><?php esc_html_e( 'Share Your Recipe', 'plugixa-recipe' ); ?></h2>
        <p class="pxr-custom-form-header__subtitle"><?php esc_html_e( 'Tell us about your favorite recipe. We\'ll review it and add it to our collection.', 'plugixa-recipe' ); ?></p>
    </div>

    <form class="pxr-custom-recipe-form" novalidate enctype="multipart/form-data">

        <!-- ═══ TAB BAR ═══ -->
        <div class="pxr-custom-form-tabs" role="tablist">
            <button type="button" class="pxr-custom-form-tab pxr-custom-form-tab--active" data-pxr-tab="basic" role="tab"><?php esc_html_e( 'Basic', 'plugixa-recipe' ); ?></button>
            <button type="button" class="pxr-custom-form-tab" data-pxr-tab="details" role="tab"><?php esc_html_e( 'Details', 'plugixa-recipe' ); ?></button>
            <button type="button" class="pxr-custom-form-tab" data-pxr-tab="tags" role="tab"><?php esc_html_e( 'Tags & Diet', 'plugixa-recipe' ); ?></button>
            <button type="button" class="pxr-custom-form-tab" data-pxr-tab="ingredients" role="tab"><?php esc_html_e( 'Ingredients', 'plugixa-recipe' ); ?></button>
            <button type="button" class="pxr-custom-form-tab" data-pxr-tab="instructions" role="tab"><?php esc_html_e( 'Instructions', 'plugixa-recipe' ); ?></button>
            <button type="button" class="pxr-custom-form-tab" data-pxr-tab="nutrition" role="tab"><?php esc_html_e( 'Nutrition', 'plugixa-recipe' ); ?></button>
            <button type="button" class="pxr-custom-form-tab" data-pxr-tab="extras" role="tab"><?php esc_html_e( 'Extras', 'plugixa-recipe' ); ?></button>
            <?php
            /**
             * Extra tab buttons contributed by the premium overlay. The free build has
             * nothing hooked here and renders no extra tabs.
             */
            do_action( 'plugixa_recipe_submission_form_tabs' );
            ?>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════
             TAB 1: BASIC
             ═══════════════════════════════════════════════════════════════ -->
        <div class="pxr-custom-form-panel pxr-custom-form-panel--active" data-pxr-panel="basic" role="tabpanel">
            <div class="pxr-custom-form-section__fields">

                <div>
                    <label for="<?php echo esc_attr( $form_id ); ?>-title" class="<?php echo esc_attr( $label_classes ); ?>">
                        <?php esc_html_e( 'Recipe Title', 'plugixa-recipe' ); ?> <span class="pxr-custom-form-required">*</span>
                    </label>
                    <input type="text" id="<?php echo esc_attr( $form_id ); ?>-title" name="title" required maxlength="200"
                           placeholder="<?php esc_attr_e( 'e.g., Grandma\'s Chocolate Chip Cookies', 'plugixa-recipe' ); ?>"
                           class="<?php echo esc_attr( $input_classes ); ?>" />
                </div>

                <div>
                    <label for="<?php echo esc_attr( $form_id ); ?>-description" class="<?php echo esc_attr( $label_classes ); ?>">
                        <?php esc_html_e( 'Description', 'plugixa-recipe' ); ?> <span class="pxr-custom-form-required">*</span>
                    </label>
                    <textarea id="<?php echo esc_attr( $form_id ); ?>-description" name="description" rows="3" required maxlength="1000"
                              placeholder="<?php esc_attr_e( 'Briefly describe your recipe...', 'plugixa-recipe' ); ?>"
                              class="<?php echo esc_attr( $input_classes ); ?>"></textarea>
                </div>

                <div>
                    <label for="<?php echo esc_attr( $form_id ); ?>-excerpt" class="<?php echo esc_attr( $label_classes ); ?>">
                        <?php esc_html_e( 'Excerpt', 'plugixa-recipe' ); ?>
                    </label>
                    <textarea id="<?php echo esc_attr( $form_id ); ?>-excerpt" name="excerpt" rows="2" maxlength="500"
                              placeholder="<?php esc_attr_e( 'A short summary for recipe cards...', 'plugixa-recipe' ); ?>"
                              class="<?php echo esc_attr( $input_classes ); ?>"></textarea>
                </div>

                <div>
                    <label for="<?php echo esc_attr( $form_id ); ?>-author" class="<?php echo esc_attr( $label_classes ); ?>">
                        <?php esc_html_e( 'Author Name', 'plugixa-recipe' ); ?>
                    </label>
                    <input type="text" id="<?php echo esc_attr( $form_id ); ?>-author" name="author_name"
                           placeholder="<?php esc_attr_e( 'Your name', 'plugixa-recipe' ); ?>"
                           class="<?php echo esc_attr( $input_classes ); ?>" />
                </div>

                <!-- Image Upload -->
                <div>
                    <label class="<?php echo esc_attr( $label_classes ); ?>"><?php esc_html_e( 'Recipe Image', 'plugixa-recipe' ); ?> <span class="pxr-custom-form-required">*</span></label>
                    <div class="pxr-custom-upload-area" data-pxr-upload-zone tabindex="0">
                        <input type="file" name="recipe_image" accept="image/jpeg,image/png,image/webp"
                               class="pxr:sr-only" data-pxr-file-input />
                        <div class="pxr-custom-upload-placeholder">
                            <svg class="pxr-custom-upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                            <div class="pxr-custom-upload-text">
                                <span class="pxr-custom-upload-link"><?php esc_html_e( 'Click to upload', 'plugixa-recipe' ); ?></span>
                                <span><?php esc_html_e( 'or drag and drop', 'plugixa-recipe' ); ?></span>
                            </div>
                            <p class="pxr-custom-upload-hint"><?php /* translators: %d: maximum file size in megabytes. */ echo esc_html( sprintf( __( 'JPG, PNG or WebP (max %dMB)', 'plugixa-recipe' ), $max_file_size ) ); ?></p>
                        </div>
                        <div class="pxr-custom-upload-preview pxr:hidden" data-pxr-upload-preview>
                            <img alt="" class="pxr-custom-upload-preview__img" data-pxr-upload-preview-img />
                            <button type="button" class="pxr-custom-upload-preview__remove" data-pxr-upload-remove><?php esc_html_e( 'Remove image', 'plugixa-recipe' ); ?></button>
                        </div>
                    </div>
                </div>

                <!-- Gallery Upload -->
                <div>
                    <label class="<?php echo esc_attr( $label_classes ); ?>"><?php esc_html_e( 'Gallery Images', 'plugixa-recipe' ); ?></label>
                    <div class="pxr-custom-gallery-zone" data-pxr-gallery-zone tabindex="0">
                        <input type="file" name="gallery_images[]" accept="image/jpeg,image/png,image/webp" multiple
                               class="pxr:sr-only" data-pxr-gallery-input />
                        <div class="pxr-custom-gallery-placeholder" data-pxr-gallery-placeholder>
                            <svg class="pxr-custom-upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>
                            </svg>
                            <div class="pxr-custom-upload-text">
                                <span class="pxr-custom-upload-link"><?php esc_html_e( 'Click to upload', 'plugixa-recipe' ); ?></span>
                                <span><?php esc_html_e( 'multiple images', 'plugixa-recipe' ); ?></span>
                            </div>
                            <p class="pxr-custom-upload-hint"><?php /* translators: %d: maximum file size in megabytes. */ echo esc_html( sprintf( __( 'JPG, PNG or WebP (max %dMB each)', 'plugixa-recipe' ), $max_file_size ) ); ?></p>
                        </div>
                        <div class="pxr-custom-gallery-grid" data-pxr-gallery-grid></div>
                    </div>
                </div>

            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════
             TAB 2: DETAILS
             ═══════════════════════════════════════════════════════════════ -->
        <div class="pxr-custom-form-panel" data-pxr-panel="details" role="tabpanel">
            <div class="pxr-custom-form-section__fields">

                <!-- Categories Multiselect -->
                <?php if ( ! empty( $category_options ) ) : ?>
                <div>
                    <label class="<?php echo esc_attr( $label_classes ); ?>"><?php esc_html_e( 'Categories', 'plugixa-recipe' ); ?> <span class="pxr-custom-form-required">*</span></label>
                    <div class="pxr-custom-multiselect" data-pxr-multiselect>
                        <div class="pxr-custom-multiselect__trigger" data-pxr-multiselect-trigger tabindex="0">
                            <span class="pxr-custom-multiselect__tags" data-pxr-multiselect-tags></span>
                            <span class="pxr-custom-multiselect__placeholder" data-pxr-multiselect-placeholder><?php esc_html_e( 'Select categories...', 'plugixa-recipe' ); ?></span>
                            <svg class="pxr-custom-multiselect__arrow" width="16" height="16" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                        </div>
                        <div class="pxr-custom-multiselect__dropdown" data-pxr-multiselect-dropdown>
                            <?php foreach ( $category_options as $recipe_cat ) : ?>
                                <label class="pxr-custom-multiselect__option">
                                    <input type="checkbox" name="categories[]" value="<?php echo esc_attr( $recipe_cat['id'] ); ?>" data-pxr-multiselect-checkbox data-label="<?php echo esc_attr( $recipe_cat['name'] ); ?>" />
                                    <?php echo esc_html( $recipe_cat['name'] ); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Cuisine & Course -->
                <div class="pxr-custom-form-grid--2col">
                    <div>
                        <label for="<?php echo esc_attr( $form_id ); ?>-cuisine" class="<?php echo esc_attr( $label_classes ); ?>"><?php esc_html_e( 'Cuisine', 'plugixa-recipe' ); ?></label>
                        <select id="<?php echo esc_attr( $form_id ); ?>-cuisine" name="cuisine_id" class="<?php echo esc_attr( $select_classes ); ?>">
                            <option value=""><?php esc_html_e( 'Select cuisine...', 'plugixa-recipe' ); ?></option>
                            <?php foreach ( $cuisine_options as $c ) : ?>
                                <option value="<?php echo esc_attr( $c['id'] ); ?>"><?php echo esc_html( $c['name'] ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="<?php echo esc_attr( $form_id ); ?>-course" class="<?php echo esc_attr( $label_classes ); ?>"><?php esc_html_e( 'Course', 'plugixa-recipe' ); ?></label>
                        <select id="<?php echo esc_attr( $form_id ); ?>-course" name="course_id" class="<?php echo esc_attr( $select_classes ); ?>">
                            <option value=""><?php esc_html_e( 'Select course...', 'plugixa-recipe' ); ?></option>
                            <?php foreach ( $course_options as $c ) : ?>
                                <option value="<?php echo esc_attr( $c['id'] ); ?>"><?php echo esc_html( $c['name'] ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Difficulty, Cost, Season -->
                <div class="pxr-custom-form-grid--3col">
                    <div>
                        <label for="<?php echo esc_attr( $form_id ); ?>-difficulty" class="<?php echo esc_attr( $label_classes ); ?>">
                            <?php esc_html_e( 'Difficulty', 'plugixa-recipe' ); ?> <span class="pxr-custom-form-required">*</span>
                        </label>
                        <select id="<?php echo esc_attr( $form_id ); ?>-difficulty" name="difficulty" required class="<?php echo esc_attr( $select_classes ); ?>">
                            <option value=""><?php esc_html_e( 'Select...', 'plugixa-recipe' ); ?></option>
                            <?php foreach ( $difficulty_options as $val => $lbl ) : ?>
                                <option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $lbl ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="<?php echo esc_attr( $form_id ); ?>-cost" class="<?php echo esc_attr( $label_classes ); ?>"><?php esc_html_e( 'Cost', 'plugixa-recipe' ); ?></label>
                        <select id="<?php echo esc_attr( $form_id ); ?>-cost" name="cost" class="<?php echo esc_attr( $select_classes ); ?>">
                            <option value=""><?php esc_html_e( 'Select...', 'plugixa-recipe' ); ?></option>
                            <?php foreach ( $cost_options as $val => $lbl ) : ?>
                                <option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $lbl ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="<?php echo esc_attr( $form_id ); ?>-season" class="<?php echo esc_attr( $label_classes ); ?>"><?php esc_html_e( 'Season', 'plugixa-recipe' ); ?></label>
                        <select id="<?php echo esc_attr( $form_id ); ?>-season" name="season" class="<?php echo esc_attr( $select_classes ); ?>">
                            <?php foreach ( $season_options as $val => $lbl ) : ?>
                                <option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $lbl ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Servings + Unit -->
                <div class="pxr-custom-form-grid--2col">
                    <div>
                        <label for="<?php echo esc_attr( $form_id ); ?>-servings" class="<?php echo esc_attr( $label_classes ); ?>">
                            <?php esc_html_e( 'Servings', 'plugixa-recipe' ); ?> <span class="pxr-custom-form-required">*</span>
                        </label>
                        <input type="number" id="<?php echo esc_attr( $form_id ); ?>-servings" name="servings"
                               min="1" max="999" placeholder="4" required class="<?php echo esc_attr( $input_classes ); ?>" />
                    </div>
                    <div>
                        <label for="<?php echo esc_attr( $form_id ); ?>-servings-unit" class="<?php echo esc_attr( $label_classes ); ?>"><?php esc_html_e( 'Servings Unit', 'plugixa-recipe' ); ?></label>
                        <input type="text" id="<?php echo esc_attr( $form_id ); ?>-servings-unit" name="servings_unit"
                               placeholder="<?php esc_attr_e( 'servings, people, portions...', 'plugixa-recipe' ); ?>"
                               class="<?php echo esc_attr( $input_classes ); ?>" />
                    </div>
                </div>

                <!-- Prep, Cook, Rest Time -->
                <div class="pxr-custom-form-grid--3col">
                    <div>
                        <label for="<?php echo esc_attr( $form_id ); ?>-prep-time" class="<?php echo esc_attr( $label_classes ); ?>">
                            <?php esc_html_e( 'Prep Time (min)', 'plugixa-recipe' ); ?> <span class="pxr-custom-form-required">*</span>
                        </label>
                        <input type="number" id="<?php echo esc_attr( $form_id ); ?>-prep-time" name="prep_time"
                               min="0" max="9999" placeholder="15" required class="<?php echo esc_attr( $input_classes ); ?>" />
                    </div>
                    <div>
                        <label for="<?php echo esc_attr( $form_id ); ?>-cook-time" class="<?php echo esc_attr( $label_classes ); ?>">
                            <?php esc_html_e( 'Cook Time (min)', 'plugixa-recipe' ); ?> <span class="pxr-custom-form-required">*</span>
                        </label>
                        <input type="number" id="<?php echo esc_attr( $form_id ); ?>-cook-time" name="cook_time"
                               min="0" max="9999" placeholder="30" required class="<?php echo esc_attr( $input_classes ); ?>" />
                    </div>
                    <div>
                        <label for="<?php echo esc_attr( $form_id ); ?>-rest-time" class="<?php echo esc_attr( $label_classes ); ?>"><?php esc_html_e( 'Rest Time (min)', 'plugixa-recipe' ); ?></label>
                        <input type="number" id="<?php echo esc_attr( $form_id ); ?>-rest-time" name="rest_time"
                               min="0" max="9999" placeholder="0" class="<?php echo esc_attr( $input_classes ); ?>" />
                    </div>
                </div>

            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════
             TAB 3: TAGS & DIET
             ═══════════════════════════════════════════════════════════════ -->
        <div class="pxr-custom-form-panel" data-pxr-panel="tags" role="tabpanel">
            <div class="pxr-custom-form-section__fields">

                <!-- Diet Flags -->
                <fieldset class="pxr-custom-form-fieldset">
                    <legend class="<?php echo esc_attr( $label_classes ); ?> pxr-custom-form-legend"><?php esc_html_e( 'Dietary Information', 'plugixa-recipe' ); ?></legend>
                    <div class="pxr-custom-form-checkbox-group">
                        <div class="pxr-custom-form-checkbox-wrap">
                            <?php
                            $diet_flags = [
                                'is_vegetarian'  => __( 'Vegetarian', 'plugixa-recipe' ),
                                'is_vegan'       => __( 'Vegan', 'plugixa-recipe' ),
                                'is_gluten_free' => __( 'Gluten-Free', 'plugixa-recipe' ),
                                'is_dairy_free'  => __( 'Dairy-Free', 'plugixa-recipe' ),
                                'is_nut_free'    => __( 'Nut-Free', 'plugixa-recipe' ),
                                'is_keto'        => __( 'Keto', 'plugixa-recipe' ),
                                'is_paleo'       => __( 'Paleo', 'plugixa-recipe' ),
                                'is_low_carb'    => __( 'Low Carb', 'plugixa-recipe' ),
                                'is_sugar_free'  => __( 'Sugar-Free', 'plugixa-recipe' ),
                                'is_halal'       => __( 'Halal', 'plugixa-recipe' ),
                                'is_kosher'      => __( 'Kosher', 'plugixa-recipe' ),
                            ];
                            foreach ( $diet_flags as $flag_key => $flag_label ) : ?>
                                <label class="pxr-custom-form-checkbox-label">
                                    <input type="checkbox" name="<?php echo esc_attr( $flag_key ); ?>" value="1" class="pxr-custom-form-checkbox" />
                                    <?php echo esc_html( $flag_label ); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </fieldset>

                <!-- Tags -->
                <div>
                    <label for="<?php echo esc_attr( $form_id ); ?>-tags" class="<?php echo esc_attr( $label_classes ); ?>"><?php esc_html_e( 'Tags', 'plugixa-recipe' ); ?></label>
                    <input type="text" id="<?php echo esc_attr( $form_id ); ?>-tags" name="tags"
                           placeholder="<?php esc_attr_e( 'e.g., summer, grilling, family...', 'plugixa-recipe' ); ?>"
                           class="<?php echo esc_attr( $input_classes ); ?>" />
                    <p class="pxr-custom-form-helper"><?php esc_html_e( 'Separate tags with commas.', 'plugixa-recipe' ); ?></p>
                </div>

                <!-- Equipment -->
                <div>
                    <label for="<?php echo esc_attr( $form_id ); ?>-equipment" class="<?php echo esc_attr( $label_classes ); ?>"><?php esc_html_e( 'Equipment', 'plugixa-recipe' ); ?></label>
                    <input type="text" id="<?php echo esc_attr( $form_id ); ?>-equipment" name="equipment"
                           placeholder="<?php esc_attr_e( 'e.g., oven, blender, skillet...', 'plugixa-recipe' ); ?>"
                           class="<?php echo esc_attr( $input_classes ); ?>" />
                    <p class="pxr-custom-form-helper"><?php esc_html_e( 'Separate equipment with commas.', 'plugixa-recipe' ); ?></p>
                </div>

            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════
             TAB 4: INGREDIENTS
             ═══════════════════════════════════════════════════════════════ -->
        <div class="pxr-custom-form-panel" data-pxr-panel="ingredients" role="tabpanel">
            <div class="pxr-custom-form-section__fields">
                <div class="pxr-custom-form-card">
                    <div class="pxr-custom-form-rows" data-pxr-ingredient-rows>
                        <div class="pxr-custom-ingredient-row" data-pxr-ingredient-row>
                            <input type="number" name="ing_qty[]" step="any" min="0" placeholder="<?php esc_attr_e( 'Qty', 'plugixa-recipe' ); ?>"
                                   class="<?php echo esc_attr( $input_classes ); ?> pxr-custom-ingredient-row__qty" />
                            <select name="ing_unit[]" class="<?php echo esc_attr( $select_classes ); ?> pxr-custom-ingredient-row__unit">
                                <?php foreach ( $unit_options as $uval => $ulbl ) : ?>
                                    <option value="<?php echo esc_attr( $uval ); ?>"><?php echo esc_html( $ulbl ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="ing_name[]" placeholder="<?php esc_attr_e( 'Ingredient name', 'plugixa-recipe' ); ?>" required
                                   class="<?php echo esc_attr( $input_classes ); ?> pxr-custom-ingredient-row__name" />
                            <input type="text" name="ing_notes[]" placeholder="<?php esc_attr_e( 'Notes', 'plugixa-recipe' ); ?>"
                                   class="<?php echo esc_attr( $input_classes ); ?> pxr-custom-ingredient-row__notes" />
                            <button type="button" class="pxr-custom-remove-row" data-pxr-remove-ingredient
                                    aria-label="<?php esc_attr_e( 'Remove ingredient', 'plugixa-recipe' ); ?>" disabled>
                                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20 12H4"/></svg>
                            </button>
                        </div>
                    </div>
                    <button type="button" class="pxr-custom-form-add-btn" data-pxr-add-ingredient>
                        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                        <?php esc_html_e( 'Add Ingredient', 'plugixa-recipe' ); ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════
             TAB 5: INSTRUCTIONS
             ═══════════════════════════════════════════════════════════════ -->
        <div class="pxr-custom-form-panel" data-pxr-panel="instructions" role="tabpanel">
            <div class="pxr-custom-form-section__fields">
                <div class="pxr-custom-form-card">
                    <div class="pxr-custom-form-rows" data-pxr-instruction-rows>
                        <div class="pxr-custom-instruction-row" data-pxr-instruction-row>
                            <span class="pxr-custom-step-num" data-pxr-step-number>1</span>
                            <div class="pxr-custom-instruction-row__fields">
                                <input type="text" name="inst_titles[]" placeholder="<?php esc_attr_e( 'Step title (optional)', 'plugixa-recipe' ); ?>"
                                       class="<?php echo esc_attr( $input_classes ); ?>" />
                                <textarea name="instructions[]" rows="2" required
                                          placeholder="<?php esc_attr_e( 'Describe this step...', 'plugixa-recipe' ); ?>"
                                          class="<?php echo esc_attr( $input_classes ); ?>"></textarea>
                            </div>
                            <button type="button" class="pxr-custom-remove-row" data-pxr-remove-instruction
                                    aria-label="<?php esc_attr_e( 'Remove instruction', 'plugixa-recipe' ); ?>" disabled>
                                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20 12H4"/></svg>
                            </button>
                        </div>
                    </div>
                    <button type="button" class="pxr-custom-form-add-btn" data-pxr-add-instruction>
                        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                        <?php esc_html_e( 'Add Step', 'plugixa-recipe' ); ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════
             TAB 6: NUTRITION
             ═══════════════════════════════════════════════════════════════ -->
        <div class="pxr-custom-form-panel" data-pxr-panel="nutrition" role="tabpanel">
            <div class="pxr-custom-form-section__fields">
                <p class="pxr-custom-form-helper" style="margin-top:0"><?php esc_html_e( 'All nutrition fields are optional. Enter values per serving.', 'plugixa-recipe' ); ?></p>

                <div>
                    <label for="<?php echo esc_attr( $form_id ); ?>-serving-size" class="<?php echo esc_attr( $label_classes ); ?>"><?php esc_html_e( 'Serving Size', 'plugixa-recipe' ); ?></label>
                    <input type="text" id="<?php echo esc_attr( $form_id ); ?>-serving-size" name="nutrition_serving_size"
                           placeholder="<?php esc_attr_e( 'e.g., 1 cup, 100g...', 'plugixa-recipe' ); ?>"
                           class="<?php echo esc_attr( $input_classes ); ?>" />
                </div>

                <div class="pxr-custom-form-grid--3col">
                    <?php
                    $nutrition_fields = [
                        ['nutrition_calories',           __('Calories', 'plugixa-recipe'),        'kcal'],
                        ['nutrition_total_fat',          __('Total Fat', 'plugixa-recipe'),        'g'],
                        ['nutrition_saturated_fat',      __('Saturated Fat', 'plugixa-recipe'),    'g'],
                        ['nutrition_trans_fat',          __('Trans Fat', 'plugixa-recipe'),         'g'],
                        ['nutrition_cholesterol',        __('Cholesterol', 'plugixa-recipe'),       'mg'],
                        ['nutrition_sodium',             __('Sodium', 'plugixa-recipe'),            'mg'],
                        ['nutrition_total_carbohydrate', __('Total Carbs', 'plugixa-recipe'),       'g'],
                        ['nutrition_dietary_fiber',      __('Dietary Fiber', 'plugixa-recipe'),     'g'],
                        ['nutrition_total_sugars',       __('Total Sugars', 'plugixa-recipe'),      'g'],
                        ['nutrition_protein',            __('Protein', 'plugixa-recipe'),           'g'],
                        ['nutrition_vitamin_a',          __('Vitamin A', 'plugixa-recipe'),         '% DV'],
                        ['nutrition_vitamin_c',          __('Vitamin C', 'plugixa-recipe'),         '% DV'],
                        ['nutrition_calcium',            __('Calcium', 'plugixa-recipe'),           'mg'],
                        ['nutrition_iron',               __('Iron', 'plugixa-recipe'),              'mg'],
                        ['nutrition_potassium',          __('Potassium', 'plugixa-recipe'),         'mg'],
                    ];
                    foreach ( $nutrition_fields as list($name, $label, $unit) ) :
                    ?>
                    <div>
                        <label for="<?php echo esc_attr( $form_id . '-' . $name ); ?>" class="<?php echo esc_attr( $label_classes ); ?>"><?php echo esc_html( $label ); ?></label>
                        <div class="pxr-custom-form-input-group">
                            <input type="number" id="<?php echo esc_attr( $form_id . '-' . $name ); ?>" name="<?php echo esc_attr( $name ); ?>"
                                   step="any" min="0" placeholder="0" class="<?php echo esc_attr( $input_classes ); ?> pxr-custom-form-input--has-suffix" />
                            <span class="pxr-custom-form-input-suffix"><?php echo esc_html( $unit ); ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════
             TAB 7: EXTRAS
             ═══════════════════════════════════════════════════════════════ -->
        <div class="pxr-custom-form-panel" data-pxr-panel="extras" role="tabpanel">
            <div class="pxr-custom-form-section__fields">

                <div>
                    <label for="<?php echo esc_attr( $form_id ); ?>-video" class="<?php echo esc_attr( $label_classes ); ?>"><?php esc_html_e( 'Video URL', 'plugixa-recipe' ); ?></label>
                    <input type="url" id="<?php echo esc_attr( $form_id ); ?>-video" name="video_url"
                           placeholder="https://youtube.com/watch?v=..." class="<?php echo esc_attr( $input_classes ); ?>" />
                    <p class="pxr-custom-form-helper"><?php esc_html_e( 'Link to a YouTube or Vimeo video.', 'plugixa-recipe' ); ?></p>
                </div>

                <div>
                    <label for="<?php echo esc_attr( $form_id ); ?>-notes" class="<?php echo esc_attr( $label_classes ); ?>"><?php esc_html_e( 'Notes', 'plugixa-recipe' ); ?></label>
                    <textarea id="<?php echo esc_attr( $form_id ); ?>-notes" name="notes" rows="3" maxlength="5000"
                              placeholder="<?php esc_attr_e( 'Cooking notes, variations...', 'plugixa-recipe' ); ?>"
                              class="<?php echo esc_attr( $input_classes ); ?>"></textarea>
                </div>

                <div>
                    <label for="<?php echo esc_attr( $form_id ); ?>-tips" class="<?php echo esc_attr( $label_classes ); ?>"><?php esc_html_e( 'Tips', 'plugixa-recipe' ); ?></label>
                    <textarea id="<?php echo esc_attr( $form_id ); ?>-tips" name="tips" rows="3" maxlength="5000"
                              placeholder="<?php esc_attr_e( 'Helpful tips, storage instructions...', 'plugixa-recipe' ); ?>"
                              class="<?php echo esc_attr( $input_classes ); ?>"></textarea>
                </div>

                <!-- Source Attribution -->
                <div class="pxr-custom-form-grid--2col">
                    <div>
                        <label for="<?php echo esc_attr( $form_id ); ?>-source-name" class="<?php echo esc_attr( $label_classes ); ?>"><?php esc_html_e( 'Source Name', 'plugixa-recipe' ); ?></label>
                        <input type="text" id="<?php echo esc_attr( $form_id ); ?>-source-name" name="source_name"
                               placeholder="<?php esc_attr_e( 'Original recipe by...', 'plugixa-recipe' ); ?>"
                               class="<?php echo esc_attr( $input_classes ); ?>" />
                    </div>
                    <div>
                        <label for="<?php echo esc_attr( $form_id ); ?>-source-url" class="<?php echo esc_attr( $label_classes ); ?>"><?php esc_html_e( 'Source URL', 'plugixa-recipe' ); ?></label>
                        <input type="url" id="<?php echo esc_attr( $form_id ); ?>-source-url" name="source_url"
                               placeholder="https://..." class="<?php echo esc_attr( $input_classes ); ?>" />
                    </div>
                </div>

            </div>
        </div>

        <?php
        /**
         * Extra form panels contributed by the premium overlay (e.g. the custom-field
         * inputs). The free build has nothing hooked here and renders no extra panels.
         */
        do_action( 'plugixa_recipe_submission_form_panels', $form_id, $label_classes, $input_classes, $select_classes );
        ?>

        <!-- Honeypot — do not fill -->
        <div class="pxr-custom-hp-field" aria-hidden="true">
            <label for="<?php echo esc_attr( $form_id ); ?>-website"><?php esc_html_e( 'Website', 'plugixa-recipe' ); ?></label>
            <input type="text" id="<?php echo esc_attr( $form_id ); ?>-website" name="website_url" tabindex="-1" autocomplete="off" />
        </div>

        <?php if ( $turnstile_enabled && $turnstile_site_key ) : ?>
            <?php
            /*
             * data-response-field-name makes Turnstile write its token straight into a
             * form field called turnstile_token, so `new FormData( form )` picks it up
             * with no JavaScript of ours at all. The reCAPTCHA v3 flow this replaced had
             * to recover the site key by parsing it back out of the injected script URL.
             */
            ?>
            <div class="pxr-custom-captcha-field">
                <div class="cf-turnstile"
                    data-sitekey="<?php echo esc_attr( $turnstile_site_key ); ?>"
                    data-response-field-name="turnstile_token"
                    data-theme="auto"></div>
            </div>
        <?php endif; ?>

        <!-- ═══ ACTION BUTTONS ═══ -->
        <div class="pxr-custom-form-actions">
            <button type="reset" class="pxr-custom-form-actions__reset"><?php esc_html_e( 'Reset', 'plugixa-recipe' ); ?></button>
            <button type="submit" class="pxr-custom-submit-btn" data-pxr-submit-btn>
                <span data-pxr-btn-text><?php esc_html_e( 'Submit Recipe', 'plugixa-recipe' ); ?></span>
                <svg class="pxr:hidden pxr-custom-submit-spinner" data-pxr-btn-spinner viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle class="pxr-custom-submit-spinner__track" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="pxr-custom-submit-spinner__head" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
            </button>
        </div>

    </form>
</div>

<?php if ( $is_modal ) : ?>
</div></div></div></div>
<?php endif; ?>


