<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Core\Modules;

defined('ABSPATH') || exit;

/**
 * Which apps (modules) are switched on - stored flags turned into an answer.
 *
 * Storage is one autoloaded option, a map of module id => bool. Resolution:
 *   - a `core` module is always on;
 *   - otherwise the stored flag wins, falling back to the descriptor's `default`;
 *   - `deps` are applied until nothing changes: a module whose dependency is off is off.
 *
 * A module with no descriptor is not in the map at all, and `ModuleRegistry::enabled()`
 * treats an unknown id as ON - an add-on module must not be silently inert.
 */
final class AppState {

    public const OPTION = 'plugixa_recipe_apps';

    public const CATEGORIES = ['core', 'content', 'engagement', 'tools', 'configuration'];

    /** @var array<string, bool>|null */
    private ?array $flags = null;

    /** @return array<string, bool> The stored flags, as saved. */
    public function flags(): array {
        if (null === $this->flags) {
            $this->flags = [];

            foreach ((array) get_option(self::OPTION, []) as $id => $on) {
                if (is_string($id)) {
                    $this->flags[$id] = (bool) $on;
                }
            }
        }

        return $this->flags;
    }

    /**
     * @param array<string, array<string, mixed>> $descriptors Normalised app descriptors.
     * @return array<string, bool> Resolved state for every described module.
     */
    public function resolve(array $descriptors): array {
        $flags   = $this->flags();
        $enabled = [];

        foreach ($descriptors as $id => $descriptor) {
            $enabled[$id] = !empty($descriptor['core'])
                || (bool) ($flags[$id] ?? ($descriptor['default'] ?? true));
        }

        // A chain of dependencies settles in at most one pass per module.
        for ($pass = 0, $passes = count($descriptors); $pass < $passes; $pass++) {
            $settled = true;

            foreach ($descriptors as $id => $descriptor) {
                if (!$enabled[$id] || !empty($descriptor['core'])) {
                    continue;
                }

                foreach ((array) ($descriptor['deps'] ?? []) as $dep) {
                    if (isset($enabled[$dep]) && !$enabled[$dep]) {
                        $enabled[$id] = false;
                        $settled      = false;
                        break;
                    }
                }
            }

            if ($settled) {
                break;
            }
        }

        return $enabled;
    }

    /**
     * Merge a patch into the stored flags - MERGE, never replace: a premium module's flag
     * must survive a downgrade to free, so it is still what the user chose when premium
     * comes back.
     *
     * @param array<string, bool> $patch
     * @return array<string, bool>
     */
    public function save(array $patch): array {
        $flags = $this->flags();

        foreach ($patch as $id => $on) {
            if (is_string($id) && '' !== $id) {
                $flags[$id] = (bool) $on;
            }
        }

        ksort($flags);
        update_option(self::OPTION, $flags);
        $this->flags = $flags;

        return $flags;
    }

    /** Drop the cached flags, so the next read sees what another writer stored. */
    public function reload(): void {
        $this->flags = null;
    }

    /** Does the option exist at all? (The toggle migration's presence check.) */
    public function stored(): bool {
        return false !== get_option(self::OPTION, false);
    }
}
