<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Core\Exceptions;

use RuntimeException;

defined('ABSPATH') || exit;

/**
 * Thrown when a service exists but cannot be constructed.
 */
final class ContainerException extends RuntimeException {
}
