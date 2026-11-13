<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Core\Database;

defined('ABSPATH') || exit;

/**
 * What every repository shares: its table names, and the cache it reads through and flushes.
 *
 * Deliberately NOT a query builder, and not plugixa-inventory's Repository. That one
 * assembles SQL and hands the result to $wpdb->prepare(), which is exactly the shape
 * WordPress.org rejected here: the argument to prepare() must be a literal, and so every
 * query in a concrete repository is written out by hand, one literal per statement. This
 * class gives a repository no way to build one.
 *
 * What it does give is the rule that used to live in every controller's head:
 *
 *     a method that writes ends with $this->flush()
 *
 * The flush sits in the same method as the write, so no caller can forget it, and "flush
 * before the re-read" holds by construction. A missed flush is the one regression this
 * codebase has ever really had. The carve-out stays explicit: methods named increment_*
 * (view, like and helpful counts) do not flush - they run on every render.
 */
abstract class Repository {

    /**
     * The cache entities a write here invalidates, the entity reads are stored under first.
     *
     * More than one when a cached value is computed from several entities' tables: a cuisine
     * list carries recipe counts, so a cuisine write also invalidates 'recipes'.
     *
     * @return string[]
     */
    abstract protected function entities(): array;

    /** Full name of one of the plugin's tables. */
    protected function table(string $name): string {
        return Schema::table($name);
    }

    /**
     * Read through the cache, under this repository's own entity.
     *
     * @param string $key A stable digest of the query's shape and bound values, never its SQL.
     * @return mixed
     */
    protected function remember(string $key, callable $read) {
        return Cache::remember($this->entities()[0], $key, $read);
    }

    /**
     * Read through the cache under a named entity, or under several at once.
     *
     * For the value that does not belong to this repository's own entity alone: a count of
     * published recipes per cuisine is cached under ['cuisines', 'recipes'], so a write to
     * either invalidates it.
     *
     * @param string|string[] $entity
     * @return mixed
     */
    protected function remember_as($entity, string $key, callable $read) {
        return Cache::remember($entity, $key, $read);
    }

    /** Invalidate everything this repository's writes can make stale. */
    protected function flush(string ...$also): void {
        Cache::flush(...array_values(array_unique(array_merge($this->entities(), $also))));
    }

    /**
     * Invalidate exactly these entities and no others.
     *
     * For the write whose reach is narrower or different from entities() - a reply to a
     * review touches 'reviews' but not the recipe's rating. Prefer flush(); use this only to
     * keep an existing write's reach exactly what it was.
     */
    protected function flush_only(string ...$entities): void {
        Cache::flush(...$entities);
    }
}
