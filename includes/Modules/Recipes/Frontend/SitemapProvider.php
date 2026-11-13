<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Recipes\Frontend;

use PlugixaRecipe\Modules\Recipes\Repositories\SitemapRepository;

defined('ABSPATH') || exit;

/**
 * Recipes in the WordPress sitemap (wp-sitemap.xml).
 *
 * A recipe is a row in our own table, not a post, so core's posts provider has never
 * listed one: until 1.0.1 the only way a crawler found a recipe was by following a link.
 *
 * Three sub-sitemaps: recipes, and the category and cuisine archives that have a
 * published recipe. An SEO plugin that replaces core's sitemap with its own does not
 * read this provider.
 */
final class SitemapProvider extends \WP_Sitemaps_Provider {

    /** Core routes a sitemap by this name, and its rule accepts lowercase letters only. */
    public const NAME = 'plugixarecipes';

    private SitemapRepository $repository;

    public function __construct() {
        $this->name        = self::NAME;
        $this->object_type = 'plugixa_recipe';
        $this->repository  = new SitemapRepository();
    }

    /** Called on `init`: core builds its sitemap index from the providers known by then. */
    public static function register(): void {
        if (!function_exists('wp_register_sitemap_provider')) {
            return;
        }

        $provider = new self();

        // Registered as itself, whatever another plugin wraps it in. Polylang replaces every
        // provider with a multilingual one that lists `posts`, `taxonomies` and providers
        // WITHOUT subtypes - and nothing at all for any other provider that has them. Ours
        // has three, so on a site with Polylang the recipe sitemaps answered at their own
        // address and were missing from the index, which is where a crawler finds them.
        // A recipe's language is the recipe's own column; there is nothing for a wrapper
        // to add. Late, so this is the last word on our provider and on no other.
        add_filter(
            'wp_sitemaps_add_provider',
            static fn($registered, $name) => self::NAME === $name ? $provider : $registered,
            PHP_INT_MAX,
            2
        );

        wp_register_sitemap_provider(self::NAME, $provider);
    }

    /**
     * @return array<string, object> Keyed by the name that appears in the sitemap's URL.
     */
    public function get_object_subtypes() {
        return [
            'recipe'   => (object) ['name' => 'recipe'],
            'category' => (object) ['name' => 'category'],
            'cuisine'  => (object) ['name' => 'cuisine'],
        ];
    }

    /**
     * @param int    $page_num       Page of results, from 1.
     * @param string $object_subtype One of get_object_subtypes().
     * @return array<int, array<string, string>>
     */
    public function get_url_list($page_num, $object_subtype = '') {
        $limit  = wp_sitemaps_get_max_urls($this->object_type);
        $offset = (max(1, (int) $page_num) - 1) * $limit;
        $urls   = [];

        if ('recipe' === $object_subtype) {
            foreach ($this->repository->recipes($limit, $offset) as $row) {
                $entry = ['loc' => plugixa_recipe_permalink((int) $row['id'], (string) $row['slug'])];

                // The day it went live: the one date of a recipe that is public. Left out
                // rather than guessed for a recipe published before the column was filled.
                if (!empty($row['published_at']) && '0000-00-00 00:00:00' !== $row['published_at']) {
                    $entry['lastmod'] = mysql2date('c', $row['published_at'], false);
                }

                $urls[] = $entry;
            }

            return $urls;
        }

        if ('category' === $object_subtype || 'cuisine' === $object_subtype) {
            foreach ($this->repository->terms($object_subtype, $limit, $offset) as $row) {
                $urls[] = ['loc' => plugixa_recipe_archive_url($object_subtype, (string) $row['slug'])];
            }
        }

        return $urls;
    }

    /**
     * @param string $object_subtype One of get_object_subtypes().
     * @return int Zero drops the sub-sitemap from the index.
     */
    public function get_max_num_pages($object_subtype = '') {
        if ('recipe' === $object_subtype) {
            $total = $this->repository->count_recipes();
        } elseif ('category' === $object_subtype || 'cuisine' === $object_subtype) {
            $total = $this->repository->count_terms($object_subtype);
        } else {
            return 0;
        }

        return (int) ceil($total / wp_sitemaps_get_max_urls($this->object_type));
    }
}
