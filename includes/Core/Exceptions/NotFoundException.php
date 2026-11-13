<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Core\Exceptions;

use RuntimeException;

defined('ABSPATH') || exit;

/**
 * Thrown when a service id resolves to nothing.
 */
final class NotFoundException extends RuntimeException {
}
