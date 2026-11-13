<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 *
 * Forwarding file - the recipe card now lives in includes/Modules/Recipes/views/.
 *
 * Kept for one reason: up to 1.0.0 the related-recipes widget template included the card by
 * this exact path, so a child theme that copied that template to override it still does.
 * Without this file such a site would draw the widget with no cards. It is included into the
 * caller's scope, so every variable the card reads is passed straight through.
 *
 * Use plugixa_recipe_template( 'recipe-card.php' ) in new code. Removed in 2.0.
 *
 * @package PlugixaRecipe
 */

defined( 'ABSPATH' ) || exit;

include plugixa_recipe_template( 'recipe-card.php' );
