<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Core\Security;

defined('ABSPATH') || exit;

/**
 * The plugin's own capabilities, who gets them, and the ONE question every permission check asks.
 *
 * Granted to administrators only. Editors, authors and contributors reach the same screens
 * through the WordPress capability each check falls back to - see plugixa_recipe_user_can().
 *
 * WHICH ACTION, HERE; ON WHICH RECORD, ELSEWHERE. `user_can()` answers with capabilities only and
 * then hands the verdict, the entity and the record to the `plugixa_recipe_user_can` filter. With
 * nothing on that filter the answer is exactly what it was before the seam existed - every route,
 * every role (RestPermissionsTest's role matrix). An add-on that knows about ownership ("this
 * person may edit only the recipes they created") answers there, and narrows the admin LISTS
 * through `owner_scope()`, which every owned entity's list query binds as a constant-true gate.
 *
 * `plugixa_recipe_manage` means "may open the app". It is stored on administrators by
 * activation and also DERIVED at runtime by `has_cap()`: anyone who passes the read check's
 * fallback holds it, and the `plugixa_recipe_grants_access` filter may widen or narrow that.
 * The admin menu asks for it, so a person given access by an add-on reaches the screens the
 * REST routes would let them use, and nobody gets a menu entry whose every screen refuses.
 */
final class Capabilities {

    public const MANAGE   = 'plugixa_recipe_manage';
    public const EDIT     = 'plugixa_recipe_edit';
    public const DELETE   = 'plugixa_recipe_delete';
    public const PUBLISH  = 'plugixa_recipe_publish';
    public const SETTINGS = 'plugixa_recipe_settings';

    public const ALL = [
        self::MANAGE,
        self::EDIT,
        self::DELETE,
        self::PUBLISH,
        self::SETTINGS,
    ];

    /**
     * The capabilities a role may be given in a role editor. MANAGE is derived from these.
     */
    public const GRANTABLE = [
        self::EDIT,
        self::DELETE,
        self::PUBLISH,
        self::SETTINGS,
    ];

    /**
     * Action => [plugin capability, WordPress fallback]. The fallbacks are the ones each
     * RestController check has always named; see that class for why delete and publish differ.
     */
    public const ACTIONS = [
        'read'     => [self::MANAGE, 'edit_posts'],
        'create'   => [self::EDIT, 'edit_posts'],
        'edit'     => [self::EDIT, 'edit_posts'],
        'delete'   => [self::DELETE, 'delete_published_posts'],
        'publish'  => [self::PUBLISH, 'publish_posts'],
        'settings' => [self::SETTINGS, 'manage_options'],
    ];

    public static function grant(): void {
        $admin = get_role('administrator');

        if ($admin) {
            foreach (self::ALL as $capability) {
                $admin->add_cap($capability);
            }
        }
    }

    /**
     * Take every plugin capability off every role and every user, and remove the roles a
     * role editor created for this plugin (slug `plugixa_recipe_*`), moving their members to
     * the site's default role first. Uninstall only.
     *
     * Every role, not only `administrator`: a role editor may have granted these anywhere,
     * and a personal grant lives in user meta, where a role sweep never looks. A prefix
     * sweep, like the option and table cleanup, because a hand-written list rots.
     */
    public static function revoke_all(): void {
        $default = (string) get_option('default_role', 'subscriber');

        foreach (array_keys(wp_roles()->roles) as $slug) {
            if (0 === strpos($slug, 'plugixa_recipe_') && $slug !== $default) {
                foreach (get_users(['role' => $slug, 'fields' => 'all']) as $user) {
                    $user->remove_role($slug);
                    if (empty($user->roles)) {
                        $user->add_role($default);
                    }
                }
                remove_role($slug);
                continue;
            }

            $role = get_role($slug);
            foreach (self::ALL as $cap) {
                $role?->remove_cap($cap);
            }
        }

        $holders = get_users(['capability__in' => self::ALL, 'fields' => 'all']);
        foreach ($holders as $user) {
            foreach (self::ALL as $cap) {
                if (isset($user->caps[$cap])) {
                    $user->remove_cap($cap);
                }
            }
        }
    }

    public static function register_hooks(): void {
        add_filter('user_has_cap', [self::class, 'has_cap'], 10, 4);
    }

    /**
     * Derive MANAGE: held by whoever passes the read fallback, unless a listener says otherwise.
     *
     * @param array<string, bool> $allcaps
     * @param string[]            $caps
     * @param array               $args
     * @param \WP_User|null       $user
     * @return array<string, bool>
     */
    public static function has_cap($allcaps, $caps = [], $args = [], $user = null): array {
        $allcaps = (array) $allcaps;

        if (!in_array(self::MANAGE, (array) $caps, true)) {
            return $allcaps;
        }

        if (!empty($allcaps['manage_options'])) {
            $allcaps[self::MANAGE] = true;
            return $allcaps;
        }

        // MANAGE stored, the read fallback, or any grantable capability (a role built in a
        // role editor with "Edit recipes" alone must open the app it was given access to).
        $granted = !empty($allcaps[self::MANAGE]) || !empty($allcaps['edit_posts']);
        foreach (self::GRANTABLE as $cap) {
            $granted = $granted || !empty($allcaps[$cap]);
        }
        $user_id = $user instanceof \WP_User ? (int) $user->ID : (int) ($args[1] ?? 0);

        /**
         * Whether a user may open the recipe app (the admin menu and the read routes).
         *
         * @since 1.4.0
         *
         * @param bool $granted The capability-only answer.
         * @param int  $user_id The user.
         */
        $allcaps[self::MANAGE] = (bool) apply_filters('plugixa_recipe_grants_access', $granted, $user_id);

        return $allcaps;
    }

    /**
     * May a user perform an action on an entity, and on this record?
     *
     * @param string                    $action  read|create|edit|delete|publish|settings.
     * @param string                    $entity  Entity slug (recipes, reviews, collections, ...).
     * @param array<string, mixed>|null $record  The record acted on, when there is one.
     * @param int|null                  $user_id Defaults to the current user.
     */
    public static function user_can(string $action, string $entity, ?array $record = null, ?int $user_id = null): bool {
        $user_id = $user_id ?? get_current_user_id();
        [$cap, $fallback] = self::ACTIONS[$action] ?? self::ACTIONS['settings'];

        $allowed = $user_id === get_current_user_id()
            ? plugixa_recipe_user_can($cap, $fallback)
            : (user_can($user_id, $cap) || user_can($user_id, $fallback));

        /**
         * Whether a user may perform an action on a recipe entity.
         *
         * @since 1.4.0
         *
         * @param bool                      $allowed The capability-only answer.
         * @param string                    $action  read|create|edit|delete|publish|settings.
         * @param string                    $entity  Entity slug.
         * @param array<string, mixed>|null $record  The record, when acting on one.
         * @param int                       $user_id The user.
         */
        return (bool) apply_filters('plugixa_recipe_user_can', $allowed, $action, $entity, $record, $user_id);
    }

    /**
     * Whose rows an admin list of this entity may show: 0 = everyone's, else this user id.
     *
     * Bound by each owned entity's list query as `( %d = 0 OR owner = %d )`. A listener that
     * restricts a user to their own rows returns that user's id; it can never widen a list.
     */
    public static function owner_scope(string $entity, ?int $user_id = null): int {
        $user_id = $user_id ?? get_current_user_id();

        /**
         * Restrict an admin list to one owner's rows.
         *
         * @since 1.4.0
         *
         * @param int    $owner   0 for every row, or the user id whose rows may be listed.
         * @param string $entity  Entity slug.
         * @param int    $user_id The user asking.
         */
        return max(0, (int) apply_filters('plugixa_recipe_owner_scope', 0, $entity, $user_id));
    }
}
