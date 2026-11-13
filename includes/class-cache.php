<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe;

defined('ABSPATH') || exit;

/**
 * Lightweight object-cache helper for the plugin's custom tables.
 *
 * Uses the WordPress core "last_changed" incremental-key pattern: each entity has
 * its own cache group, and every cached value's key embeds that group's
 * `last_changed` token. Bumping the token on a write makes all previously-cached
 * reads for that entity unreachable (effective invalidation without enumerating keys).
 *
 * With WordPress's default (non-persistent) object cache this de-duplicates repeated
 * reads within a single request; with a persistent backend (Redis / Memcached /
 * Docket) it also caches across requests. Either way it satisfies the
 * `WordPress.DB.DirectDatabaseQuery.NoCaching` requirement for the content reads.
 *
 * NOTE: high-frequency, non-content writes (e.g. per-render view_count increments)
 * deliberately do NOT call flush() — otherwise every page view would invalidate the
 * whole cache. Cached lists therefore carry eventually-consistent view counts.
 */
class Cache {

    /**
     * Entities backed by a custom table. Each maps to its own cache group.
     *
     * Child rows are cached under their owning entity: ingredients, instructions and
     * nutrition are always read through a recipe, so a recipe write flushes them too.
     *
     * This list is documentation — group() builds names dynamically, so the premium
     * overlay can flush its own entities (which do not exist in the free build)
     * without their names ever appearing here.
     *
     * @var string[]
     */
    const ENTITIES = array(
        'recipes',
        'categories',
        'cuisines',
        'courses',
        'reviews',
        'collections',
        'shopping_lists',
        'favorites',
        'units',
        'views',
        'shortcodes',
    );

    /**
     * Cache group name for an entity.
     */
    private static function group(string $entity): string {
        return 'plugixa_recipe_' . $entity;
    }

    /**
     * Current "last changed" token for an entity's group (created on first use).
     */
    private static function last_changed(string $entity): string {
        $group = self::group($entity);
        $token = wp_cache_get('last_changed', $group);

        if (false === $token) {
            $token = microtime();
            wp_cache_set('last_changed', $token, $group);
        }

        return (string) $token;
    }

    /**
     * Read-through cache.
     *
     * A value computed from more than one entity's tables names ALL of them, so flushing
     * any one invalidates it. The public taxonomy and collection counts are why: they count
     * only published recipes, and publishing a draft writes no term, pivot or collection
     * row. Cached under the term entity alone, the old count outlived the status change for
     * as long as a persistent object cache kept the key.
     *
     * @param string|string[] $entity     One of self::ENTITIES (or a premium entity), or
     *                                    several. The first one names the cache group.
     * @param string          $key_suffix MUST capture every input that changes the result
     *                                    (filters, sort, pagination, ids, language, ...).
     * @param callable        $callback   Produces the value on a cache miss (the $wpdb read).
     * @return mixed The cached or freshly-computed value.
     */
    public static function remember(string|array $entity, string $key_suffix, callable $callback) {
        $entities = (array) $entity;
        $group    = self::group($entities[0]);

        // A single entity yields exactly the key it always did: md5(suffix):token.
        $key = md5($key_suffix);
        foreach ($entities as $name) {
            $key .= ':' . self::last_changed($name);
        }

        $found = false;
        $value = wp_cache_get($key, $group, false, $found);
        if ($found) {
            return $value;
        }

        $value = $callback();
        wp_cache_set($key, $value, $group);

        return $value;
    }

    /**
     * Invalidate one or more entities. Call after every CONTENT write
     * (insert / update / delete that changes what reads return).
     *
     * @param string ...$entities Entities to invalidate.
     */
    public static function flush(string ...$entities): void {
        foreach ($entities as $entity) {
            wp_cache_set('last_changed', microtime(), self::group($entity));
        }
    }
}
