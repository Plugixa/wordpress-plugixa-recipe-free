<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Core\Http;

use WP_Error;

defined('ABSPATH') || exit;

/**
 * The one shape a refused request takes, wherever the refusal is decided.
 *
 * A service decides most of them - "name is required" is a rule about a cuisine, not about
 * REST - but the client reads a fixed envelope: code `rest_error`, an HTTP status, and a
 * per-field `errors` map. Building that here means a service can refuse something without
 * knowing it is being called over REST, and the admin app sees the same response it always
 * has. RestController::error() is this.
 */
final class Failure {

    /**
     * @param array<string, string> $errors Per-field messages, when there are any.
     */
    public static function make(string $message, int $status = 400, array $errors = []): WP_Error {
        return new WP_Error('rest_error', $message, [
            'status' => $status,
            'errors' => $errors,
        ]);
    }
}
