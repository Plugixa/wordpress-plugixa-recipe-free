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

    /**
     * @return array<int, array<string, mixed>>
     */
    public function submissions(int $user_id): array {
        $out = [];

        foreach ($this->repository->submissions($user_id, self::LIMIT) as $row) {
            $live  = 'published' === $row['status'];
            $out[] = [
                'id'     => (int) $row['id'],
                'title'  => (string) $row['title'],
                // Only a published recipe has a page to link to.
                'url'    => $live ? plugixa_recipe_permalink((int) $row['id'], (string) $row['slug']) : '',
                'image'  => self::image($row['image_id'] ?? 0),
                'status' => self::status_label((string) $row['status']),
                'live'   => $live,
                'date'   => !empty($row['created_at']) ? wp_date(get_option('date_format'), strtotime((string) $row['created_at'])) : '',
            ];
        }

        return $out;
    }

    /** What a submitter is told; the stored word is the site's, not theirs. */
    private static function status_label(string $status): string {
        switch ($status) {
            case 'published':
                return __('Published', 'plugixa-recipe');
            case 'pending':
                return __('Waiting for review', 'plugixa-recipe');
            case 'draft':
                return __('Draft', 'plugixa-recipe');
            default:
                return __('Not published', 'plugixa-recipe');
        }
    }

    /** @param mixed $image_id */
    private static function image($image_id): string {
        $image_id = (int) $image_id;

        return $image_id ? (string) (wp_get_attachment_image_url($image_id, 'medium') ?: '') : '';
    }
}
