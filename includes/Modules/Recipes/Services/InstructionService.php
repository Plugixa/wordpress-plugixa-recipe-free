<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Recipes\Services;

use PlugixaRecipe\Core\Http\Failure;
use PlugixaRecipe\Modules\Recipes\Repositories\InstructionRepository;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * What a recipe's instruction list is and what may be done to it.
 *
 * The rules live here rather than in the controller: instructions only exist for a recipe
 * that exists, a save replaces the whole list, the step text is accepted under either
 * `instruction_text` or `text`, and a read carries the `text` alias and the image URLs. It
 * holds no SQL: every query is the repository's.
 *
 * A refusal is returned as a WP_Error, never thrown. The controller maps it onto the response.
 */
final class InstructionService {

    private InstructionRepository $instructions;

    public function __construct(InstructionRepository $instructions) {
        $this->instructions = $instructions;
    }

    /**
     * Get all instructions for a recipe.
     *
     * @return array<int, array<string, mixed>>|WP_Error A 404 failure when there is no such recipe.
     */
    public function list(int $recipe_id) {
        if (!$this->instructions->recipe_exists($recipe_id)) {
            return Failure::make(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        $instructions = $this->instructions->for_recipe($recipe_id);

        // Add image URLs and field aliases
        foreach ($instructions as &$inst) {
            $inst['text']  = $inst['instruction_text'];
            $inst['title'] = $inst['title'] ?? '';
            if (!empty($inst['image_id'])) {
                $inst['image_url'] = wp_get_attachment_image_url((int) $inst['image_id'], 'medium');
                $inst['image_thumbnail'] = wp_get_attachment_image_url((int) $inst['image_id'], 'thumbnail');
            }
        }
        unset($inst);

        return $instructions;
    }

    /**
     * Replace all instructions for a recipe (delete existing + insert new).
     *
     * @param mixed $instructions Raw payload value: a list of instruction rows.
     * @return true|WP_Error
     */
    public function replace(int $recipe_id, $instructions) {
        if (!$this->instructions->recipe_exists($recipe_id)) {
            return Failure::make(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        $rows = [];
        foreach ($instructions as $inst) {
            $rows[] = [
                'recipe_id'        => $recipe_id,
                'group_name'       => sanitize_text_field($inst['group_name'] ?? ''),
                'group_order'      => (int) ($inst['group_order'] ?? 0),
                'sort_order'       => (int) ($inst['sort_order'] ?? 0),
                'title'            => sanitize_text_field($inst['title'] ?? ''),
                'instruction_text' => wp_kses_post($inst['instruction_text'] ?? $inst['text'] ?? ''),
                'image_id'         => !empty($inst['image_id']) ? (int) $inst['image_id'] : null,
                'video_url'        => esc_url_raw($inst['video_url'] ?? ''),
                'duration'         => isset($inst['duration']) ? (int) $inst['duration'] : null,
            ];
        }

        $this->instructions->replace($recipe_id, $rows, current_time('mysql'), get_current_user_id());

        return true;
    }

    /**
     * Reorder instructions for a recipe.
     *
     * @param mixed $items Raw payload value: a list of {id, sort_order[, group_order, group_name]}.
     * @return true|WP_Error
     */
    public function reorder(int $recipe_id, $items) {
        if (!$this->instructions->recipe_exists($recipe_id)) {
            return Failure::make(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        if (empty($items) || !is_array($items)) {
            return Failure::make(__('Items are required.', 'plugixa-recipe'));
        }

        $updates = [];
        foreach ($items as $item) {
            $update = ['sort_order' => (int) ($item['sort_order'] ?? 0)];
            if (isset($item['group_order'])) {
                $update['group_order'] = (int) $item['group_order'];
            }
            if (isset($item['group_name'])) {
                $update['group_name'] = sanitize_text_field($item['group_name']);
            }
            $updates[] = [
                'id'     => (int) $item['id'],
                'update' => $update,
            ];
        }

        $this->instructions->reorder($recipe_id, $updates);

        return true;
    }
}
