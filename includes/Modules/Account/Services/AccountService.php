<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Account\Services;

use PlugixaRecipe\Modules\Account\Repositories\AccountRepository;
use PlugixaRecipe\Modules\Recipes\Enums;
use PlugixaRecipe\Modules\Recipes\Services\RecipePresenter;

defined('ABSPATH') || exit;

/**
 * Turns one visitor's rows into what their account pages draw.
 */
final class AccountService {

    /** More than a page shows; the pages do not paginate yet. */
    private const LIMIT = 200;

    private AccountRepository $repository;

    public function __construct(AccountRepository $repository) {
        $this->repository = $repository;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function saved(int $user_id): array {
        $labels = Enums::difficulty_labels();
        $out    = [];

        foreach ($this->repository->saved($user_id, self::LIMIT) as $row) {
            $out[] = [
                'id'         => (int) $row['id'],
                'title'      => (string) $row['title'],
                'url'        => plugixa_recipe_permalink((int) $row['id'], (string) $row['slug']),
                'image'      => self::image($row['image_id'] ?? 0),
                'time'       => RecipePresenter::duration_label((int) ($row['total_time'] ?? 0) * MINUTE_IN_SECONDS),
                'difficulty' => (string) ($labels[$row['difficulty'] ?? ''] ?? ''),
                'rating'     => (int) ($row['rating_count'] ?? 0) > 0 ? round((float) $row['rating_average'], 1) : 0,
            ];
        }

        return $out;
    }

    /** @param mixed $image_id */
    private static function image($image_id): string {
        $image_id = (int) $image_id;

        return $image_id ? (string) (wp_get_attachment_image_url($image_id, 'medium') ?: '') : '';
    }
}
