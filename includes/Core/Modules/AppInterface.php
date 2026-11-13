<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Core\Modules;

defined('ABSPATH') || exit;

/**
 * A module the App Manager can show as a card - and, unless it is core, switch off.
 *
 * Separate from ModuleInterface so an add-on module can exist without a card; such a
 * module is simply always on.
 */
interface AppInterface {

    /**
     * What the card is.
     *
     * @return array{
     *   order?: int,
     *   category?: 'core'|'content'|'engagement'|'tools'|'configuration',
     *   core?: bool,
     *   deps?: string[],
     *   default?: bool,
     *   icon?: string,
     *   pro?: bool,
     * }
     */
    public function app(): array;

    /**
     * The card's translated name and one-line description. A method, not two keys in
     * `app()`, because a DISABLED module must still describe itself and its strings are
     * not registered while it is off.
     *
     * @return array{label: string, description: string}
     */
    public function labels(): array;

    /**
     * What switching this off would affect, e.g. "60 reviews". Called only by the App
     * Manager, never on the boot path.
     *
     * @return string[]
     */
    public function usage(): array;

    /** Why the toggle is refused right now (WooCommerce not active), or null. */
    public function blocker(): ?string;
}
