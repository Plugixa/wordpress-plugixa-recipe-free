<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Recipes\Services;

use PlugixaRecipe\Modules\Recipes\Frontend\RecipeEmbed;
use PlugixaRecipe\Modules\Recipes\Repositories\CanonicalPostRepository;

defined('ABSPATH') || exit;

/**
 * One recipe, one address for search engines.
 *
 * A recipe embedded in a post is readable at two URLs: the post, and the recipe's own
 * page. Left alone they compete, and the page with the story (the one the author links
 * to and readers share) can lose to the bare one. So when a published post embeds a
 * recipe whole, the recipe's own page names that post as its canonical.
 *
 * Nobody sets this by hand: it follows what posts contain, each time one is saved.
 */
final class CanonicalPosts {

    private CanonicalPostRepository $repository;

    public function __construct(?CanonicalPostRepository $repository = null) {
        $this->repository = $repository ?? new CanonicalPostRepository();
    }

    public function init(): void {
        // Trashing and restoring are updates too, so they pass through here.
        add_action('save_post', [$this, 'sync'], 10, 2);
        add_action('deleted_post', [$this, 'forget']);
    }

    /**
     * @param int      $post_id
     * @param \WP_Post $post
     */
    public function sync($post_id, $post): void {
        $post_id = (int) $post_id;

        if (!$post instanceof \WP_Post || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        // Only a post a visitor can open may be anyone's canonical.
        $public   = 'publish' === $post->post_status && is_post_type_viewable($post->post_type);
        $embedded = $public ? RecipeEmbed::embedded_ids((string) $post->post_content) : [];

        foreach ($this->repository->recipes_of($post_id) as $recipe_id) {
            if (!in_array($recipe_id, $embedded, true)) {
                $this->repository->release($recipe_id, $post_id);
            }
        }
        foreach ($embedded as $recipe_id) {
            $this->repository->claim($recipe_id, $post_id);
        }
    }

    /** @param int $post_id */
    public function forget($post_id): void {
        $post_id = (int) $post_id;

        foreach ($this->repository->recipes_of($post_id) as $recipe_id) {
            $this->repository->release($recipe_id, $post_id);
        }
    }

    /**
     * The address search engines should index a recipe at: the post that embeds it while
     * that post is published, otherwise '' (the recipe's own page).
     *
     * The column is not trusted on its own - a post can leave the public eye in ways that
     * never pass through save_post (a direct database edit, another plugin's bulk tool).
     */
    public function url_for(int $recipe_id): string {
        $post_id = $this->repository->post_of($recipe_id);
        if (!$post_id) {
            return '';
        }

        $post = get_post($post_id);
        if (!$post || 'publish' !== $post->post_status || !is_post_type_viewable($post->post_type)
            || !in_array($recipe_id, RecipeEmbed::embedded_ids((string) $post->post_content), true)) {
            return '';
        }

        return (string) get_permalink($post);
    }
}
