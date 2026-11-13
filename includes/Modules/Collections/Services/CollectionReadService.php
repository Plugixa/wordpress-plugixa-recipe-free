<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Collections\Services;

use PlugixaRecipe\Modules\Collections\Repositories\CollectionReadRepository;

defined('ABSPATH') || exit;

/**
 * What an anonymous visitor may see of the collections: the public list, decorated for
 * display. It holds no SQL - the pinned public query is CollectionReadRepository.
 */
final class CollectionReadService {

    private CollectionReadRepository $collections;

    public function __construct(CollectionReadRepository $collections) {
        $this->collections = $collections;
    }

    /** @return array<int, array<string, mixed>> */
    public function list(): array {
        $collections = $this->collections->public_list();

        foreach ($collections as &$col) {
            $col['recipe_count'] = (int) $col['recipe_count'];
            if (!empty($col['image_id'])) {
                $col['image_url'] = wp_get_attachment_image_url((int) $col['image_id'], 'medium');
            }
        }
        unset($col);

        return $collections;
    }
}
