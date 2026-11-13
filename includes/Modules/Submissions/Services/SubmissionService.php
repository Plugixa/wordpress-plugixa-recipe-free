<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Submissions\Services;

use PlugixaRecipe\Core\Http\Failure;
use PlugixaRecipe\Modules\Recipes\Enums;
use PlugixaRecipe\Modules\Recipes\Services\ChildRows;
use PlugixaRecipe\Modules\Submissions\Repositories\SubmissionRepository;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * What an anonymous visitor may do with the public recipe submission form: submit one recipe.
 *
 * The rules live here - who may submit, how often, what a valid recipe is, the status it is
 * stored in - and so does the order they are checked in, which decides the error a visitor
 * sees. It holds no SQL: every statement is SubmissionRepository's.
 *
 * A submission is several calls, because the captcha, the honeypot and the two upload blocks
 * belong to the controller and sit between them: settings_for_visitor() (the login gate),
 * then the captcha and the honeypot (controller), then admit() (rate limit, title,
 * description), then the required-image check (controller), then store() (every other field
 * and the insert), then the uploads (controller, which hands the attachments to
 * attach_image() / attach_gallery()), then complete(). complete() is the step that
 * invalidates the cache; a submission that is stored must reach it.
 *
 * The stored status comes from the `submission_status` setting and from nowhere else: no
 * method here accepts a status from the request.
 *
 * A refusal is returned as a WP_Error, never thrown. The controller maps it onto the response.
 */
final class SubmissionService {

    private SubmissionRepository $submissions;

    public function __construct(SubmissionRepository $submissions) {
        $this->submissions = $submissions;
    }

    /**
     * Coerce one payload value into a plain string before a sanitizer touches it.
     *
     * The same guard as PublicController::scalar_string(), which is protected and so out of
     * reach from here: an ingredient or instruction arrives either as a bare string or as a
     * keyed array, a malformed `{"name": []}` arrives too, and trim(), sanitize_text_field()
     * and wp_kses_post() all raise a TypeError on an array in PHP 8 - a 500 an anonymous
     * caller could trigger at will. Anything that is not scalar becomes ''.
     *
     * This deliberately does NOT sanitize: the caller picks the sanitizer that fits the field.
     *
     * @param mixed $value Raw value from the decoded request payload.
     */
    private static function scalar_string($value): string {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Step 1 of a submission: the plugin settings, unless this visitor has to log in first.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function settings_for_visitor() {
        $settings = get_option('plugixa_recipe_settings', []);

        // Configurable login gate.
        if (!empty($settings['submission_require_login']) && !is_user_logged_in()) {
            return Failure::make(__('You must be logged in to submit a recipe.', 'plugixa-recipe'), 401);
        }

        return $settings;
    }

    /**
     * What the visitor is told once their recipe is in.
     *
     * Settings > Submission form > "Success message", when the site owner wrote one. That
     * field was saved from the first release and shown by nothing: the route always answered
     * with the built-in sentence below.
     *
     * @param array<string, mixed> $settings What settings_for_visitor() returned.
     */
    public function success_message($settings): string {
        $custom = is_array($settings) ? trim(sanitize_text_field((string) ($settings['success_message'] ?? ''))) : '';

        return '' !== $custom ? $custom : __('Thank you! Your recipe has been submitted for review.', 'plugixa-recipe');
    }

    /** The transient that counts one address's submission attempts this hour. */
    private static function rate_key(string $ip): string {
        return 'plugixa_recipe_submit_' . md5($ip);
    }

    /**
     * Has this address used up the hour's attempts? Reads the count; never changes it.
     *
     * @param array<string, mixed> $settings What settings_for_visitor() returned.
     * @return WP_Error|null The 429 refusal, or null while there are attempts left.
     */
    public function rate_limited($settings, string $ip): ?WP_Error {
        $max_per_hour = min((int) ($settings['api_rate_limit'] ?? 100), 10);

        return (int) get_transient(self::rate_key($ip)) >= $max_per_hour
            ? Failure::make(__('Too many submissions. Please try again later.', 'plugixa-recipe'), 429)
            : null;
    }

    /**
     * Use up one of this address's attempts for the hour.
     *
     * Called for a recipe that was STORED and for a FAILED CAPTCHA, and for nothing else.
     * It used to be counted the moment the limit was read, so a visitor who forgot the title
     * or the image burned an attempt each time and could lock themselves out without ever
     * submitting. A failed captcha still counts: that is the one refusal a bot can produce
     * at will, and each one is a request to Cloudflare.
     */
    public function count_attempt(string $ip): void {
        $key = self::rate_key($ip);
        set_transient($key, (int) get_transient($key) + 1, HOUR_IN_SECONDS);
    }

    /**
     * Step 2 of a submission: the hourly rate limit, then the title and the description.
     *
     * The rate limit is only READ here; see count_attempt() for what uses one up.
     *
     * @param array<string, mixed> $settings    What settings_for_visitor() returned.
     * @param string               $ip          The caller's address, for the rate limit.
     * @param string               $title       Already coerced to a string by the controller, unsanitised.
     * @param string               $description Already coerced to a string by the controller, unsanitised.
     * @return array{title: string, description: string}|WP_Error
     */
    public function admit($settings, string $ip, string $title, string $description) {
        $limited = $this->rate_limited($settings, $ip);
        if (null !== $limited) {
            return $limited;
        }

        // Validate required fields.
        $title = sanitize_text_field($title);
        if (empty($title)) {
            return Failure::make(__('Recipe title is required.', 'plugixa-recipe'));
        }
        if (mb_strlen($title) > 200) {
            return Failure::make(__('Recipe title must be 200 characters or fewer.', 'plugixa-recipe'));
        }

        $description = wp_kses_post($description);
        if (empty($description)) {
            return Failure::make(__('Recipe description is required.', 'plugixa-recipe'));
        }
        if (mb_strlen(wp_strip_all_tags($description)) > 5000) {
            return Failure::make(__('Recipe description is too long.', 'plugixa-recipe'));
        }

        return [
            'title'       => $title,
            'description' => $description,
        ];
    }

    /**
     * Step 3 of a submission: validate every remaining field and write the recipe row.
     *
     * The checks run in this order and the first one to fail decides the message: categories,
     * ingredients, instructions, (the unique slug is worked out here), servings, prep time,
     * cook time, excerpt, notes, tips.
     *
     * @param array<string, mixed>                      $settings      What settings_for_visitor() returned.
     * @param array{title: string, description: string} $accepted      What admit() returned.
     * @param array<string, mixed>                      $fields        The rest of the payload as the controller read
     *                                                                 it: text fields already coerced to strings,
     *                                                                 unsanitised; categories, ingredients,
     *                                                                 instructions, the numbers and the flags raw.
     * @param string                                    $submission_ip The caller's address, as stored with the recipe.
     * @return array<string, mixed>|WP_Error The stored submission: id, status, title, description,
     *                                       difficulty, author_name, servings.
     */
    public function store($settings, array $accepted, array $fields, string $submission_ip) {
        $title       = $accepted['title'];
        $description = $accepted['description'];

        // Validate at least one category.
        $categories_input = $fields['categories'];
        if (empty($categories_input) || !is_array($categories_input) || count(array_filter(array_map('intval', $categories_input))) < 1) {
            return Failure::make(__('Please select at least one category.', 'plugixa-recipe'));
        }

        // Pre-validate ingredients: at least one with a name.
        $ingredients_json_raw = $fields['ingredients'];
        $ingredients_pre = is_string($ingredients_json_raw) ? json_decode($ingredients_json_raw, true) : $ingredients_json_raw;
        $has_valid_ingredient = false;
        if (!empty($ingredients_pre) && is_array($ingredients_pre)) {
            foreach ($ingredients_pre as $ing) {
                $name = self::scalar_string(is_string($ing) ? $ing : ($ing['name'] ?? ''));
                if (trim($name) !== '') { $has_valid_ingredient = true; break; }
            }
        }
        if (!$has_valid_ingredient) {
            return Failure::make(__('At least one ingredient with a name is required.', 'plugixa-recipe'));
        }

        // Pre-validate instructions: at least one with text.
        $instructions_json_raw = $fields['instructions'];
        $instructions_pre = is_string($instructions_json_raw) ? json_decode($instructions_json_raw, true) : $instructions_json_raw;
        $has_valid_instruction = false;
        if (!empty($instructions_pre) && is_array($instructions_pre)) {
            foreach ($instructions_pre as $inst) {
                // 'text' is the alias the admin app binds its form to (the read path sets it
                // from instruction_text), so accept either name rather than silently dropping
                // an instruction that used the other one.
                $text = self::scalar_string(is_string($inst) ? $inst : ($inst['instruction_text'] ?? $inst['text'] ?? ''));
                if (trim($text) !== '') { $has_valid_instruction = true; break; }
            }
        }
        if (!$has_valid_instruction) {
            return Failure::make(__('At least one instruction step is required.', 'plugixa-recipe'));
        }

        // Generate unique slug.
        $slug = sanitize_title($title);
        $original_slug = $slug;
        $counter = 1;
        while ($this->submissions->slug_owner($slug)) {
            $slug = $original_slug . '-' . (++$counter);
        }

        $status = sanitize_text_field($settings['submission_status'] ?? 'pending');

        // Validate enums.
        $difficulty = sanitize_text_field($fields['difficulty']);
        if (!in_array($difficulty, Enums::DIFFICULTIES, true)) {
            $difficulty = 'medium';
        }
        $cost = Enums::cost($fields['cost']);

        // Validate season enum.
        $season = sanitize_text_field($fields['season']);
        if (!in_array($season, Enums::SEASONS, true)) {
            $season = 'all';
        }

        // Validate required numeric fields.
        $servings_raw = $fields['servings'];
        if ($servings_raw === '' || (int) $servings_raw < 1) {
            return Failure::make(__('Servings is required and must be at least 1.', 'plugixa-recipe'));
        }
        $servings = min(999, max(1, (int) $servings_raw));

        $prep_time_raw = $fields['prep_time'];
        if ($prep_time_raw === '') {
            return Failure::make(__('Prep time is required.', 'plugixa-recipe'));
        }
        $prep_time = min(9999, max(0, (int) $prep_time_raw));

        $cook_time_raw = $fields['cook_time'];
        if ($cook_time_raw === '') {
            return Failure::make(__('Cook time is required.', 'plugixa-recipe'));
        }
        $cook_time = min(9999, max(0, (int) $cook_time_raw));

        $rest_time = $fields['rest_time'] !== '' ? min(9999, max(0, (int) $fields['rest_time'])) : null;

        // Validate text field max-lengths.
        $excerpt = sanitize_textarea_field($fields['excerpt']);
        if (mb_strlen($excerpt) > 500) {
            return Failure::make(__('Excerpt must be 500 characters or fewer.', 'plugixa-recipe'));
        }
        $notes = wp_kses_post($fields['notes']);
        if (mb_strlen(wp_strip_all_tags($notes)) > 5000) {
            return Failure::make(__('Notes field is too long.', 'plugixa-recipe'));
        }
        $tips = wp_kses_post($fields['tips']);
        if (mb_strlen(wp_strip_all_tags($tips)) > 5000) {
            return Failure::make(__('Tips field is too long.', 'plugixa-recipe'));
        }

        // Parse tags & equipment from comma-separated to JSON.
        $tags_raw  = sanitize_text_field($fields['tags']);
        $tags_json = wp_json_encode(array_values(array_filter(array_map('trim', explode(',', $tags_raw)))));

        $equip_raw  = sanitize_text_field($fields['equipment']);
        $equip_json = wp_json_encode(array_values(array_filter(array_map('trim', explode(',', $equip_raw)))));

        // Insert recipe.
        $recipe_data = [
            'title'          => $title,
            'slug'           => $slug,
            'description'    => $description,
            'excerpt'        => $excerpt,
            'notes'          => $notes,
            'tips'           => $tips,
            'servings'       => $servings,
            'servings_unit'  => sanitize_text_field($fields['servings_unit']),
            'prep_time'      => $prep_time,
            'cook_time'      => $cook_time,
            'rest_time'      => $rest_time,
            'difficulty'     => $difficulty,
            'cost'           => $cost ?: null,
            'season'         => $season,
            'video_url'      => esc_url_raw($fields['video_url']),
            'author_name'    => sanitize_text_field($fields['author_name']),
            // The visitor's own credit, or nothing: this column is printed on the recipe.
            'source'         => sanitize_text_field($fields['source_name']),
            'source_url'     => esc_url_raw($fields['source_url']),
            'tags'           => $tags_json,
            'equipment'      => $equip_json,
            'is_vegetarian'  => (int) (bool) $fields['is_vegetarian'],
            'is_vegan'       => (int) (bool) $fields['is_vegan'],
            'is_gluten_free' => (int) (bool) $fields['is_gluten_free'],
            'is_dairy_free'  => (int) (bool) $fields['is_dairy_free'],
            'is_keto'        => (int) (bool) $fields['is_keto'],
            'is_low_carb'    => (int) (bool) $fields['is_low_carb'],
            'is_nut_free'    => (int) (bool) $fields['is_nut_free'],
            'is_paleo'       => (int) (bool) $fields['is_paleo'],
            'is_sugar_free'  => (int) (bool) $fields['is_sugar_free'],
            'is_halal'       => (int) (bool) $fields['is_halal'],
            'is_kosher'      => (int) (bool) $fields['is_kosher'],
            'status'         => $status,
            'created_at'     => current_time('mysql'),
            'created_by'     => is_user_logged_in() ? get_current_user_id() : 0,
            'submission_ip'  => $submission_ip,
        ];

        $recipe_id = $this->submissions->insert_recipe($recipe_data);

        if (!$recipe_id) {
            return Failure::make(__('Failed to submit recipe.', 'plugixa-recipe'), 500);
        }

        return [
            'id'          => $recipe_id,
            'status'      => $status,
            'title'       => $title,
            'description' => $description,
            'difficulty'  => $difficulty,
            'author_name' => $recipe_data['author_name'],
            'servings'    => $recipe_data['servings'],
        ];
    }

    /**
     * Step 4 of a submission, only when the featured image was uploaded: record it.
     *
     * @param int $attachment_id The attachment the controller's upload block created.
     */
    public function attach_image(int $recipe_id, $attachment_id): void {
        $this->submissions->set_image($recipe_id, $attachment_id);
    }

    /**
     * Step 4 of a submission, only when gallery images were uploaded: record them.
     *
     * @param int[] $gallery_ids Attachment ids the controller's upload block created.
     */
    public function attach_gallery(int $recipe_id, array $gallery_ids): void {
        $this->submissions->set_gallery($recipe_id, wp_json_encode($gallery_ids));
    }

    /**
     * A submission's ingredients and instructions as table rows. Writes nothing.
     *
     * Each list arrives as JSON in a form field (or already decoded), every item either a
     * bare string or a keyed array. A visitor may set: for an ingredient its name, quantity,
     * unit, notes and group; for a step its text, title, group, timer, tip and - by naming
     * the position of an uploaded file - its photo. Nothing else is read: no link_url, no
     * image id, no video. A link in a submitted ingredient would be an anonymous visitor's
     * URL on the site owner's page.
     *
     * @param mixed           $ingredients  JSON string or decoded list.
     * @param mixed           $instructions JSON string or decoded list.
     * @param array<int, int> $step_images  Attachment ids the controller stored, keyed by the
     *                                      `image_index` a step names.
     * @return array{ingredients: array<int, array<string, mixed>>, instructions: array<int, array<string, mixed>>}
     */
    public static function child_rows(int $recipe_id, $ingredients, $instructions, array $step_images = []): array {
        $rows = ['ingredients' => [], 'instructions' => []];

        $ingredients = is_string($ingredients) ? json_decode($ingredients, true) : $ingredients;
        foreach (array_slice(is_array($ingredients) ? $ingredients : [], 0, 100) as $ing) {
            // Sanitize BOTH shapes. The bare-string form used to skip the sanitizer
            // entirely and reach the database insert raw, from a public anonymous endpoint -
            // only the keyed-array form was cleaned.
            $name = sanitize_text_field(self::scalar_string(is_string($ing) ? $ing : ($ing['name'] ?? '')));
            if ('' === $name) {
                continue;
            }
            $ing = is_array($ing) ? $ing : [];

            $rows['ingredients'][] = ChildRows::ingredient($recipe_id, [
                'name'        => $name,
                'quantity'    => self::scalar_string($ing['quantity'] ?? ''),
                'unit'        => self::scalar_string($ing['unit'] ?? ''),
                'notes'       => self::scalar_string($ing['notes'] ?? ''),
                'group_name'  => self::scalar_string($ing['group_name'] ?? ''),
                'group_order' => max(0, min(99, (int) self::scalar_string($ing['group_order'] ?? 0))),
                'sort_order'  => count($rows['ingredients']),
            ]);
        }

        $instructions = is_string($instructions) ? json_decode($instructions, true) : $instructions;
        foreach (array_slice(is_array($instructions) ? $instructions : [], 0, 50) as $inst) {
            // 'text' is the alias the admin app binds its form to (the read path sets it
            // from instruction_text), so accept either name rather than silently dropping
            // an instruction that used the other one.
            $text = self::scalar_string(is_string($inst) ? $inst : ($inst['instruction_text'] ?? $inst['text'] ?? ''));
            if ('' === trim($text)) {
                continue;
            }
            $inst = is_array($inst) ? $inst : [];

            // A photo is one the controller just stored for THIS request, found by position.
            $index = self::scalar_string($inst['image_index'] ?? '');
            $image = ctype_digit($index) ? (int) ($step_images[(int) $index] ?? 0) : 0;

            $rows['instructions'][] = ChildRows::instruction($recipe_id, [
                'title'            => self::scalar_string($inst['title'] ?? ''),
                'instruction_text' => $text,
                'tip'              => self::scalar_string($inst['tip'] ?? ''),
                // A day at most: a timer is for a step, and the column is unsigned.
                'timer_seconds'    => max(0, min(86400, (int) self::scalar_string($inst['timer_seconds'] ?? 0))),
                'image_id'         => $image > 0 ? $image : null,
                'group_name'       => self::scalar_string($inst['group_name'] ?? ''),
                'group_order'      => max(0, min(99, (int) self::scalar_string($inst['group_order'] ?? 0))),
                'sort_order'       => count($rows['instructions']),
            ]);
        }

        return $rows;
    }

    /**
     * Step 5 of a submission: the child rows, the action, the notification, and the cache.
     *
     * @param array<string, mixed> $submission What store() returned.
     * @param array<string, mixed> $fields     categories, cuisine_id, course_id, ingredients,
     *                                         instructions, nutrition - raw, as the controller
     *                                         read them after the uploads - and step_images,
     *                                         the attachment ids it stored for step photos.
     * @param callable             $get_param  The controller's request reader, fn(string $key, $default).
     *                                         Not called here: it is the action's second argument.
     */
    public function complete(array $submission, array $fields, callable $get_param): void {
        $recipe_id = $submission['id'];

        // Save categories (array of IDs → junction table, with FK validation).
        $categories = $fields['categories'];
        if (!empty($categories) && is_array($categories)) {
            $cat_ids = array_filter(array_map('intval', $categories));
            if (!empty($cat_ids)) {
                $valid_cats = $this->submissions->existing_category_ids(implode(',', $cat_ids));
                foreach ($valid_cats as $i => $cat_id) {
                    $this->submissions->add_category([
                        'recipe_id'     => $recipe_id,
                        'category_id'   => (int) $cat_id,
                        'display_order' => $i,
                    ]);
                }
            }
        }

        // Save cuisine (single ID → junction table, with FK validation).
        $cuisine_id = (int) $fields['cuisine_id'];
        if ($cuisine_id > 0) {
            $exists = $this->submissions->cuisine_exists($cuisine_id);
            if ($exists) {
                $this->submissions->add_cuisine([
                    'recipe_id'  => $recipe_id,
                    'cuisine_id' => $cuisine_id,
                ]);
            }
        }

        // Save course (single ID → junction table, with FK validation).
        $course_id = (int) $fields['course_id'];
        if ($course_id > 0) {
            $exists = $this->submissions->course_exists($course_id);
            if ($exists) {
                $this->submissions->add_course([
                    'recipe_id' => $recipe_id,
                    'course_id' => $course_id,
                ]);
            }
        }

        // The ingredient and instruction rows (at most 100 and 50): see child_rows().
        $rows = self::child_rows($recipe_id, $fields['ingredients'], $fields['instructions'], (array) ($fields['step_images'] ?? []));
        foreach ($rows['ingredients'] as $row) {
            $this->submissions->add_ingredient($row);
        }
        foreach ($rows['instructions'] as $row) {
            $this->submissions->add_instruction($row);
        }

        // Insert nutrition data (if any fields provided, with range clamping).
        $nutrition_json = $fields['nutrition'];
        $nutrition      = is_string($nutrition_json) ? json_decode($nutrition_json, true) : $nutrition_json;
        if (!empty($nutrition) && is_array($nutrition)) {
            $nf_names       = ['serving_size', 'calories', 'total_fat', 'saturated_fat', 'trans_fat',
                'cholesterol', 'sodium', 'total_carbohydrate', 'dietary_fiber', 'total_sugars',
                'protein', 'vitamin_a', 'vitamin_c', 'calcium', 'iron', 'potassium'];
            $nutrition_data = ['recipe_id' => $recipe_id];
            $has_values     = false;
            foreach ($nf_names as $nf) {
                $val = $nutrition[$nf] ?? null;
                if ($nf === 'serving_size') {
                    $nutrition_data[$nf] = sanitize_text_field(self::scalar_string($val ?? ''));
                    if (!empty($val)) $has_values = true;
                } else {
                    $numeric_val = ($val !== '' && $val !== null) ? (float) $val : null;
                    if ($numeric_val !== null) {
                        $numeric_val = max(0, min(99999.99, $numeric_val));
                        $has_values = true;
                    }
                    $nutrition_data[$nf] = $numeric_val;
                }
            }
            if ($has_values) {
                $this->submissions->add_nutrition($nutrition_data);
            }
        }

        /**
         * Fires after a recipe submitted through the public form has been stored.
         *
         * The premium overlay hooks this to persist the extra data its own form fields
         * collect.
         *
         * $get_param reads one value of the request: fn(string $key, $default = ''). The
         * value is RAW - unslashed once, and NOT sanitized, because the form posts JSON in
         * some fields and a blanket sanitizer would corrupt it. A listener must therefore:
         *
         * - not call wp_unslash() on it again (on a JSON string that removes the
         *   backslashes JSON needs, and json_decode() then fails for the whole field);
         * - sanitize every value it keeps, with the sanitizer that fits it;
         * - accept only the keys it defined - this is a public endpoint.
         *
         * @param int      $recipe_id The recipe that was just stored.
         * @param callable $get_param Reader for the raw, unslashed request values.
         */
        do_action( 'plugixa_recipe_frontend_submission_saved', $recipe_id, $get_param );

        // Email notification.
        SubmissionNotifier::send_new_submission_notification([
            'title'       => $submission['title'],
            'author_name' => $submission['author_name'],
            'description' => $submission['description'],
            'difficulty'  => $submission['difficulty'],
            'servings'    => $submission['servings'],
        ]);

        // Content write: the new recipe (plus its taxonomy pivots, which feed the recipe counts
        // shown in the category/cuisine/course widgets).
        $this->submissions->submitted();
    }
}
