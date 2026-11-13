<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Recipes\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table data layer for the admin recipe CRUD. Reads go through PlugixaRecipe\Core\Database\Cache, which the static sniff cannot trace through the wrapper; the write, bulk and duplicate paths are uncached by nature and flush the affected entities afterwards. Every query is a constant literal with table identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed anywhere in this file.

// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- This sniff counts prepare() arguments syntactically and cannot see through the spread operator, so the `...$filter_args` / `...$args` list queries report the wrong count. Those values are passed in one pre-built positional array (the shared WHERE block's placeholder order IS that array's order) precisely so the SQL can stay a constant literal. This is an argument-counting warning, not a security sniff.

use PlugixaRecipe\Core\Database\Repository;

defined('ABSPATH') || exit;

/**
 * Every query the ADMIN recipe routes run against the `recipes` row itself: the filtered
 * list, the single read with its taxonomy and child rows, the row writes, the bulk actions
 * and the two delete cascades.
 *
 * The admin list filters status through a bound gate, `( %d = 0 OR r.status = %s )`, and
 * reads every column - so nothing in this file pins a status, and the public reads (which pin
 * the published status as a hard literal and select a written-out public column list) live
 * in PublicRecipeRepository, never here. The child rows a save rewrites are
 * RecipeChildRepository's.
 *
 * A save is several writes with a hook after them, so the row writes here (insert(),
 * update(), delete_cascade()) do not invalidate the cache themselves: created(), updated(),
 * duplicated() and delete() are the invalidations that close them, called by the service at
 * the point the flush has always sat.
 */
final class RecipeRepository extends Repository {

    /** Recipe + pivot rows change the category/cuisine/course counts as well. */
    protected function entities(): array {
        return ['recipes', 'categories', 'cuisines', 'courses'];
    }

    /**
     * Bound values for the shared WHERE block, in placeholder order. %i identifiers and
     * %s/%d values share one positional list, so table names sit inline.
     *
     * @param array<string, mixed> $criteria As built by RecipeService::list(): search (raw
     *                                       text or ''), status_gate, status, category_id,
     *                                       uncategorized, cuisine_id, course_id, difficulty,
     *                                       has_featured, featured, min_rating, diet (the six
     *                                       gate/value pairs, in column order).
     * @return array<int, mixed>
     */
    private function filter_args(array $criteria): array {
        global $wpdb;

        // Search filter
        $search = '' !== $criteria['search']
            ? '%' . $wpdb->esc_like($criteria['search']) . '%'
            : '';

        $cat_id     = $criteria['category_id'];
        $uncat      = $criteria['uncategorized'];
        $cuisine_id = $criteria['cuisine_id'];
        $course_id  = $criteria['course_id'];
        $difficulty = $criteria['difficulty'];
        $min_rating = $criteria['min_rating'];

        return array_merge([
            $this->table('recipes'),
            $search !== '' ? 1 : 0, $search, $search, $search,
            $criteria['status_gate'], $criteria['status'],
            $cat_id ? 1 : 0, $this->table('recipe_categories'), $cat_id,
            $uncat, $this->table('recipe_categories'),
            $cuisine_id ? 1 : 0, $this->table('recipe_cuisines'), $cuisine_id,
            $course_id ? 1 : 0, $this->table('recipe_courses'), $course_id,
            $difficulty !== '' ? 1 : 0, $difficulty,
            $criteria['has_featured'] ? 1 : 0, $criteria['featured'],
            $min_rating > 0 ? 1 : 0, $min_rating,
        ], $criteria['diet']);
    }

    /**
     * Rows matching the list filters.
     *
     * @param array<string, mixed> $criteria See filter_args().
     */
    public function count(array $criteria): int {
        global $wpdb;

        $filter_args = $this->filter_args($criteria);

        // Count query. Cache key is a stable digest of the bound values - it must NOT embed
        // the SQL text, which now lives inline in prepare().
        // The WHERE block below is repeated verbatim in the two ORDER BY variants further
        // down. It used to be a private class constant concatenated in at each call site,
        // which is more compact and cannot drift - but WordPress.org's review reads the
        // argument to prepare(), and a constant is not a literal to that reading. So the
        // block is written out three times on purpose; if you change one, change all three,
        // and keep the placeholder order in step with the $filter_args the callers build.
        //
        // Every filter is a gate placeholder: bind 0 and the clause is constant-true, so the
        // SQL text never depends on which filters the request set. The two taxonomy filters
        // and the "uncategorized" case are gated EXISTS / NOT EXISTS subqueries rather than
        // optional JOINs, because a JOIN is structure and cannot be gated away by a
        // placeholder (and EXISTS needs no DISTINCT to undo a JOIN's row multiplication).
        return (int) $this->remember('count|' . md5(wp_json_encode($filter_args)), function () use ($wpdb, $filter_args) {
            return (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i r WHERE 1=1
        AND ( %d = 0 OR (r.title LIKE %s OR r.description LIKE %s OR r.author_name LIKE %s) )
        AND ( %d = 0 OR r.status = %s )
        AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rc_f WHERE rc_f.recipe_id = r.id AND rc_f.category_id = %d) )
        AND ( %d = 0 OR NOT EXISTS (SELECT 1 FROM %i rc_n WHERE rc_n.recipe_id = r.id) )
        AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rcu_f WHERE rcu_f.recipe_id = r.id AND rcu_f.cuisine_id = %d) )
        AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rco_f WHERE rco_f.recipe_id = r.id AND rco_f.course_id = %d) )
        AND ( %d = 0 OR r.difficulty = %s )
        AND ( %d = 0 OR r.is_featured = %d )
        AND ( %d = 0 OR r.rating_average >= %f )
        AND ( %d = 0 OR r.is_vegetarian = %d )
        AND ( %d = 0 OR r.is_vegan = %d )
        AND ( %d = 0 OR r.is_gluten_free = %d )
        AND ( %d = 0 OR r.is_dairy_free = %d )
        AND ( %d = 0 OR r.is_keto = %d )
        AND ( %d = 0 OR r.is_low_carb = %d )',
                ...$filter_args
            ));
        });
    }

    /**
     * One page of the list, every column.
     *
     * Main query. ORDER BY column bound with the %i identifier placeholder (WP 6.2+) from
     * the service's allow-list; a direction keyword cannot be bound, so ASC/DESC is two
     * literal queries. r.id is a deterministic tiebreaker so paging stays stable when the
     * sort column has ties.
     *
     * @param array<string, mixed> $criteria See filter_args().
     * @param string               $orderby  A column from the service's allow-list.
     * @param string               $order    'ASC' or 'DESC'
     * @param int                  $limit
     * @param int                  $offset
     * @return array<int, array<string, mixed>>
     */
    public function page(array $criteria, $orderby, string $order, $limit, $offset): array {
        global $wpdb;

        $filter_args = $this->filter_args($criteria);

        $args = array_merge($filter_args, [$orderby, $limit, $offset]);

        return (array) $this->remember('items|' . md5(wp_json_encode($args) . '|' . $order), function () use ($wpdb, $order, $args) {
            if ('ASC' === $order) {
                return $wpdb->get_results($wpdb->prepare(
                    'SELECT r.* FROM %i r WHERE 1=1
        AND ( %d = 0 OR (r.title LIKE %s OR r.description LIKE %s OR r.author_name LIKE %s) )
        AND ( %d = 0 OR r.status = %s )
        AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rc_f WHERE rc_f.recipe_id = r.id AND rc_f.category_id = %d) )
        AND ( %d = 0 OR NOT EXISTS (SELECT 1 FROM %i rc_n WHERE rc_n.recipe_id = r.id) )
        AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rcu_f WHERE rcu_f.recipe_id = r.id AND rcu_f.cuisine_id = %d) )
        AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rco_f WHERE rco_f.recipe_id = r.id AND rco_f.course_id = %d) )
        AND ( %d = 0 OR r.difficulty = %s )
        AND ( %d = 0 OR r.is_featured = %d )
        AND ( %d = 0 OR r.rating_average >= %f )
        AND ( %d = 0 OR r.is_vegetarian = %d )
        AND ( %d = 0 OR r.is_vegan = %d )
        AND ( %d = 0 OR r.is_gluten_free = %d )
        AND ( %d = 0 OR r.is_dairy_free = %d )
        AND ( %d = 0 OR r.is_keto = %d )
        AND ( %d = 0 OR r.is_low_carb = %d )
                      ORDER BY r.%i ASC, r.id ASC
                      LIMIT %d OFFSET %d',
                    ...$args
                ), ARRAY_A);
            }
            return $wpdb->get_results($wpdb->prepare(
                'SELECT r.* FROM %i r WHERE 1=1
        AND ( %d = 0 OR (r.title LIKE %s OR r.description LIKE %s OR r.author_name LIKE %s) )
        AND ( %d = 0 OR r.status = %s )
        AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rc_f WHERE rc_f.recipe_id = r.id AND rc_f.category_id = %d) )
        AND ( %d = 0 OR NOT EXISTS (SELECT 1 FROM %i rc_n WHERE rc_n.recipe_id = r.id) )
        AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rcu_f WHERE rcu_f.recipe_id = r.id AND rcu_f.cuisine_id = %d) )
        AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rco_f WHERE rco_f.recipe_id = r.id AND rco_f.course_id = %d) )
        AND ( %d = 0 OR r.difficulty = %s )
        AND ( %d = 0 OR r.is_featured = %d )
        AND ( %d = 0 OR r.rating_average >= %f )
        AND ( %d = 0 OR r.is_vegetarian = %d )
        AND ( %d = 0 OR r.is_vegan = %d )
        AND ( %d = 0 OR r.is_gluten_free = %d )
        AND ( %d = 0 OR r.is_dairy_free = %d )
        AND ( %d = 0 OR r.is_keto = %d )
        AND ( %d = 0 OR r.is_low_carb = %d )
                      ORDER BY r.%i DESC, r.id DESC
                      LIMIT %d OFFSET %d',
                ...$args
            ), ARRAY_A);
        });
    }

    /**
     * Read-through cached record; the enrichment the service adds (image URLs, taxonomy
     * joins) stays live so a category/cuisine/course rename is never served stale.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array {
        global $wpdb;

        $recipe = $this->remember("item:{$id}", function () use ($wpdb, $id) {
            return $wpdb->get_row(
                $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $this->table('recipes'), $id),
                ARRAY_A
            );
        });

        return $recipe ?: null;
    }

    /**
     * The row as it is in the database right now - for an update, a status change or a
     * duplicate, where a cached copy could be one write behind.
     *
     * @return array<string, mixed>|null
     */
    public function find_uncached(int $id): ?array {
        global $wpdb;

        $recipe = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $this->table('recipes'), $id), ARRAY_A);

        return $recipe ?: null;
    }

    public function exists(int $id): bool {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', $this->table('recipes'), $id));
    }

    /**
     * A recipe's categories, every column, in the order the editor arranged them. Uncached.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function categories(int $id) {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT c.* FROM %i c
                INNER JOIN %i rc ON c.id = rc.category_id
                WHERE rc.recipe_id = %d ORDER BY rc.display_order ASC, c.id ASC',
                $this->table('categories'),
                $this->table('recipe_categories'),
                $id
            ),
            ARRAY_A
        );
    }

    /**
     * A recipe's cuisines, every column. Uncached.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function cuisines(int $id) {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT cu.* FROM %i cu
                INNER JOIN %i rcu ON cu.id = rcu.cuisine_id
                WHERE rcu.recipe_id = %d ORDER BY rcu.display_order ASC, cu.id ASC',
                $this->table('cuisines'),
                $this->table('recipe_cuisines'),
                $id
            ),
            ARRAY_A
        );
    }

    /**
     * A recipe's courses, every column. Uncached.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function courses(int $id) {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT co.* FROM %i co
                INNER JOIN %i rco ON co.id = rco.course_id
                WHERE rco.recipe_id = %d',
                $this->table('courses'),
                $this->table('recipe_courses'),
                $id
            ),
            ARRAY_A
        );
    }

    /**
     * Ingredients (child rows of the recipe -> cached under the 'recipes' entity)
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function ingredients(int $id) {
        global $wpdb;

        return $this->remember("item:{$id}:ingredients", function () use ($wpdb, $id) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE recipe_id = %d ORDER BY group_order ASC, sort_order ASC',
                    $this->table('ingredients'),
                    $id
                ),
                ARRAY_A
            );
        });
    }

    /**
     * Instructions (child rows of the recipe -> cached under the 'recipes' entity)
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function instructions(int $id) {
        global $wpdb;

        return $this->remember("item:{$id}:instructions", function () use ($wpdb, $id) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE recipe_id = %d ORDER BY group_order ASC, sort_order ASC',
                    $this->table('instructions'),
                    $id
                ),
                ARRAY_A
            );
        });
    }

    /**
     * Nutrition (child row of the recipe -> cached under the 'recipes' entity)
     *
     * @return array<string, mixed>|null
     */
    public function nutrition(int $id) {
        global $wpdb;

        return $this->remember("item:{$id}:nutrition", function () use ($wpdb, $id) {
            return $wpdb->get_row(
                $wpdb->prepare('SELECT * FROM %i WHERE recipe_id = %d', $this->table('nutrition'), $id),
                ARRAY_A
            );
        });
    }

    /**
     * A listed recipe's categories: id, name and slug only. Uncached.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function list_categories(int $id) {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT c.id, c.name, c.slug FROM %i c
                INNER JOIN %i rc ON c.id = rc.category_id
                WHERE rc.recipe_id = %d ORDER BY rc.display_order ASC, c.id ASC',
                $this->table('categories'),
                $this->table('recipe_categories'),
                $id
            ),
            ARRAY_A
        );
    }

    /**
     * A listed recipe's cuisines: id, name and slug only. Uncached.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function list_cuisines(int $id) {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT cu.id, cu.name, cu.slug FROM %i cu
                INNER JOIN %i rcu ON cu.id = rcu.cuisine_id
                WHERE rcu.recipe_id = %d ORDER BY rcu.display_order ASC, cu.id ASC',
                $this->table('cuisines'),
                $this->table('recipe_cuisines'),
                $id
            ),
            ARRAY_A
        );
    }

    /**
     * A listed recipe's courses: id, name and slug only. Uncached.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function list_courses(int $id) {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT co.id, co.name, co.slug FROM %i co
                INNER JOIN %i rco ON co.id = rco.course_id
                WHERE rco.recipe_id = %d',
                $this->table('courses'),
                $this->table('recipe_courses'),
                $id
            ),
            ARRAY_A
        );
    }

    /** Is the slug used by a recipe other than $exclude_id? */
    public function slug_taken(string $slug, int $exclude_id = 0): bool {
        global $wpdb;

        if ($exclude_id) {
            return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE slug = %s AND id != %d', $this->table('recipes'), $slug, $exclude_id));
        }

        return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE slug = %s', $this->table('recipes'), $slug));
    }

    /**
     * Store a new recipe row - for a create and for a duplicate.
     *
     * The first write of a save, and it does not invalidate the cache itself: the pivots,
     * the child rows and the save hook follow it, and created() / duplicated() below close
     * the save - exactly where the flush has always been.
     *
     * @param array<string, mixed> $data
     * @return int The new id, or 0 when the insert failed.
     */
    public function insert(array $data) {
        global $wpdb;

        $wpdb->insert($this->table('recipes'), $data);

        return $wpdb->insert_id;
    }

    /**
     * Write the changed fields of a recipe row. Part of a save; see insert() and updated().
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void {
        global $wpdb;

        $wpdb->update($this->table('recipes'), $data, ['id' => $id]);
    }

    /**
     * Close a create: the row, its pivots and child rows are written and the
     * plugixa_recipe_save_recipe action has fired.
     *
     * Recipe + pivot rows changed -> also invalidates category/cuisine/course counts.
     * Must run BEFORE the re-read that builds the response so it is read fresh, not from cache.
     */
    public function created(): void {
        $this->flush();
    }

    /**
     * Close an update: the row, its pivots and child rows are written and the
     * plugixa_recipe_save_recipe action has fired.
     *
     * Recipe + pivot rows changed -> also invalidates category/cuisine/course counts.
     * Must run BEFORE the re-read that builds the response so it is read fresh, not from cache.
     */
    public function updated(): void {
        $this->flush();
    }

    /**
     * Close a duplicate: the copy, its copied children are written and the
     * plugixa_recipe_duplicate_recipe action has fired.
     *
     * New recipe + copied pivot rows -> also invalidates category/cuisine/course counts.
     * Must run BEFORE the re-read that builds the response so it is read fresh, not from cache.
     */
    public function duplicated(): void {
        $this->flush();
    }

    /**
     * Change a recipe's status (and published_at, when it is first published).
     *
     * @param array<string, mixed> $data status, modified_at, modified_by and maybe published_at.
     */
    public function update_status(int $id, array $data): void {
        global $wpdb;

        $wpdb->update($this->table('recipes'), $data, ['id' => $id]);

        // Status/published_at only; no pivot row changes, so the ADMIN taxonomy counts stand.
        // The PUBLIC counts do move - the widgets and public collections count published
        // recipes only - and this flush reaches them because they are cached against
        // 'recipes' as well as their own entity (see Cache::remember()).
        $this->flush_only('recipes');
    }

    /**
     * Store a new display order - the recipes' own, or their order inside one category.
     * One flush for the whole batch, not one per row.
     *
     * @param array<int, array{id: int, display_order: int}> $items
     * @param mixed                                          $category_id The payload's category_id, or null.
     */
    public function reorder(array $items, $category_id): void {
        global $wpdb;

        if ($category_id) {
            // Per-category ordering via pivot table
            foreach ($items as $item) {
                $wpdb->update(
                    $this->table('recipe_categories'),
                    ['display_order' => (int) $item['display_order']],
                    ['recipe_id' => (int) $item['id'], 'category_id' => (int) $category_id]
                );
            }
        } else {
            foreach ($items as $item) {
                $wpdb->update(
                    $this->table('recipes'),
                    ['display_order' => (int) $item['display_order']],
                    ['id' => (int) $item['id']]
                );
            }
        }

        // Ordering changed (recipe display_order, or the per-category pivot order).
        $this->flush_only('recipes');
    }

    /**
     * Apply one bulk action (publish, draft, archive, feature, unfeature) to a set of recipes.
     *
     * The id list binds as ONE %s via FIND_IN_SET rather than a variable-length
     * IN (...), so each statement below stays a constant literal.
     *
     * @param string $action  publish, draft, archive, feature or unfeature.
     * @param string $id_list Comma-separated ids, already intval-mapped by the caller.
     * @param string $now     MySQL datetime for modified_at / published_at.
     * @return bool False when the action is not one of the five: nothing was written and
     *              nothing was flushed.
     */
    public function bulk_action(string $action, string $id_list, string $now, int $user_id): bool {
        global $wpdb;

        switch ($action) {
            case 'publish':
                $wpdb->query($wpdb->prepare(
                    'UPDATE %i SET status = %s, published_at = COALESCE(published_at, %s), modified_at = %s, modified_by = %d WHERE FIND_IN_SET(id, %s)',
                    $this->table('recipes'), 'published', $now, $now, $user_id, $id_list
                ));
                break;

            // `archive` is the action the recipe list's "Archive" button has always sent. It
            // had no case here, so the button answered "Invalid action" every time.
            case 'draft':
            case 'archive':
                $wpdb->query($wpdb->prepare(
                    'UPDATE %i SET status = %s, modified_at = %s, modified_by = %d WHERE FIND_IN_SET(id, %s)',
                    $this->table('recipes'), 'archive' === $action ? 'archived' : 'draft', $now, $user_id, $id_list
                ));
                break;

            case 'feature':
                $wpdb->query($wpdb->prepare(
                    'UPDATE %i SET is_featured = 1, modified_at = %s, modified_by = %d WHERE FIND_IN_SET(id, %s)',
                    $this->table('recipes'), $now, $user_id, $id_list
                ));
                break;

            case 'unfeature':
                $wpdb->query($wpdb->prepare(
                    'UPDATE %i SET is_featured = 0, modified_at = %s, modified_by = %d WHERE FIND_IN_SET(id, %s)',
                    $this->table('recipes'), $now, $user_id, $id_list
                ));
                break;

            default:
                return false;
        }

        // Status / is_featured only; pivot rows (and therefore taxonomy counts) are untouched.
        $this->flush_only('recipes');

        return true;
    }

    /**
     * SINGLE delete, step 1: remove everything keyed to the recipe, leaving the row itself.
     *
     * The plugixa_recipe_delete_recipe action fires between this and delete() - "before a
     * recipe row is deleted, while its id is still valid" - so this does not touch the
     * `recipes` row and does not flush; delete() does both.
     *
     * Delete related data. Every free table keyed by recipe_id belongs here AND in
     * child_tables() below: `likes` was missing from both until 1.0.3. DataIntegrityTest reads
     * the recipe-keyed tables from the schema, so the next one fails a test instead.
     */
    public function delete_cascade(int $id): void {
        global $wpdb;

        $wpdb->delete($this->table('recipe_categories'), ['recipe_id' => $id]);
        $wpdb->delete($this->table('recipe_cuisines'), ['recipe_id' => $id]);
        $wpdb->delete($this->table('recipe_courses'), ['recipe_id' => $id]);
        $wpdb->delete($this->table('ingredients'), ['recipe_id' => $id]);
        $wpdb->delete($this->table('instructions'), ['recipe_id' => $id]);
        $wpdb->delete($this->table('nutrition'), ['recipe_id' => $id]);
        // The helpful votes on this recipe's reviews, while the reviews still say which
        // they are: the votes table has no recipe_id of its own.
        $wpdb->query($wpdb->prepare(
            'DELETE FROM %i WHERE review_id IN ( SELECT id FROM %i WHERE recipe_id = %d )',
            $this->table('review_helpful_votes'),
            $this->table('reviews'),
            $id
        ));
        $wpdb->delete($this->table('reviews'), ['recipe_id' => $id]);
        $wpdb->delete($this->table('favorites'), ['recipe_id' => $id]);
        $wpdb->delete($this->table('likes'), ['recipe_id' => $id]);
        $wpdb->delete($this->table('collection_recipes'), ['recipe_id' => $id]);

        // Shopping-list items belong to the shopper, not the recipe: detach, never delete.
        // The list reads the recipe through a LEFT JOIN, so the item stays and only loses its
        // recipe title. Left dangling instead, the id could show ANOTHER recipe's title on a
        // server that hands a deleted id out again (InnoDB before MySQL 8, after a restart).
        $wpdb->update($this->table('shopping_list_items'), ['recipe_id' => null], ['recipe_id' => $id]);
    }

    /**
     * SINGLE delete, step 2: remove the recipe row and invalidate - after delete_cascade()
     * and after the plugixa_recipe_delete_recipe action.
     */
    public function delete(int $id): void {
        global $wpdb;

        $wpdb->delete($this->table('recipes'), ['id' => $id]);

        // Recipe + pivot rows gone (category/cuisine/course counts change), the recipe's
        // reviews, favorites and collection links were removed above, and any shopping-list
        // item that pointed at it was detached.
        $this->flush('reviews', 'favorites', 'collections', 'shopping_lists');
    }

    /**
     * BULK delete: the free child tables, before the plugixa_recipe_recipe_child_tables
     * filter has had its say. Keep this list in step with delete_cascade()'s.
     *
     * @return string[] Table names, without the plugin prefix.
     */
    public function child_tables(): array {
        return ['recipe_categories', 'recipe_cuisines', 'recipe_courses', 'ingredients', 'instructions', 'nutrition', 'reviews', 'favorites', 'likes', 'collection_recipes'];
    }

    /**
     * BULK delete: remove the recipes and everything keyed to them, and invalidate.
     *
     * The id list binds as ONE %s via FIND_IN_SET rather than a variable-length
     * IN (...), so each statement stays a constant literal for any selection size.
     *
     * @param string[] $tables  child_tables(), as filtered by the service: every table is
     *                          keyed by recipe_id and named without the plugin prefix.
     * @param string   $id_list Comma-separated ids, already intval-mapped by the caller.
     * @return int|bool Rows removed from `recipes`, or false when that statement failed.
     */
    public function delete_many_cascade(array $tables, string $id_list) {
        global $wpdb;

        // Before the loop below removes the reviews: their helpful votes, which are keyed by
        // review and so are in no list of recipe-keyed tables. See delete_cascade().
        $wpdb->query($wpdb->prepare(
            'DELETE FROM %i WHERE review_id IN ( SELECT id FROM %i WHERE FIND_IN_SET(recipe_id, %s) )',
            $this->table('review_helpful_votes'),
            $this->table('reviews'),
            $id_list
        ));

        foreach ($tables as $table) {
            $wpdb->query($wpdb->prepare(
                'DELETE FROM %i WHERE FIND_IN_SET(recipe_id, %s)',
                $this->table($table),
                $id_list
            ));
        }

        // Detach shopping-list items rather than delete them - see delete_cascade().
        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET recipe_id = NULL WHERE FIND_IN_SET(recipe_id, %s)',
            $this->table('shopping_list_items'),
            $id_list
        ));

        $deleted = $wpdb->query($wpdb->prepare(
            'DELETE FROM %i WHERE FIND_IN_SET(id, %s)',
            $this->table('recipes'),
            $id_list
        ));

        // Recipes + pivot rows gone (category/cuisine/course counts change), the deleted
        // recipes' reviews, favorites and collection links were removed above, and their
        // shopping-list items were detached.
        $this->flush('reviews', 'favorites', 'collections', 'shopping_lists');

        return $deleted;
    }
}
