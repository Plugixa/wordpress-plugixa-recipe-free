<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Submissions\Services;

defined( 'ABSPATH' ) || exit;

use PlugixaRecipe\Core\Mail\Mailer;

/**
 * Tells the site owner a recipe was submitted from the public form and is waiting for review.
 */
final class SubmissionNotifier {

    /**
     * Send notification email when a new recipe is submitted via the frontend form.
     *
     * @param array $recipe_data Recipe data with keys: title, author_name, description, difficulty, servings.
     * @return bool Whether the email was sent successfully.
     */
    public static function send_new_submission_notification(array $recipe_data): bool {
        $settings = Mailer::get_notification_settings();

        if (empty($settings['notification_enabled'])) {
            return false;
        }

        $recipients = Mailer::get_recipients($settings);
        if (empty($recipients)) {
            return false;
        }

        $subject = sprintf(
            /* translators: %s: recipe title */
            __('New recipe submission: %s', 'plugixa-recipe'),
            $recipe_data['title'] ?? __('Untitled Recipe', 'plugixa-recipe')
        );

        $placeholders = [
            '{recipe_title}' => $recipe_data['title'] ?? __('Untitled Recipe', 'plugixa-recipe'),
            '{author_name}'  => $recipe_data['author_name'] ?? __('Anonymous', 'plugixa-recipe'),
            '{description}'  => $recipe_data['description'] ?? '',
            '{difficulty}'   => ucfirst($recipe_data['difficulty'] ?? 'medium'),
            '{servings}'     => $recipe_data['servings'] ?? '4',
            '{site_name}'    => get_bloginfo('name'),
            '{site_url}'     => home_url(),
            '{admin_url}'    => admin_url('admin.php?page=plugixa-recipe#/recipes'),
            '{date}'         => wp_date(get_option('date_format') . ' ' . get_option('time_format')),
        ];

        $message_text = sprintf(
            /* translators: 1: author name, 2: recipe title */
            __('%1$s has submitted a new recipe: "%2$s". Please review it in the admin panel.', 'plugixa-recipe'),
            $placeholders['{author_name}'],
            $placeholders['{recipe_title}']
        );

        $details = [
            __('Recipe Title', 'plugixa-recipe') => $placeholders['{recipe_title}'],
            __('Submitted By', 'plugixa-recipe') => $placeholders['{author_name}'],
            __('Difficulty', 'plugixa-recipe')    => $placeholders['{difficulty}'],
            __('Servings', 'plugixa-recipe')      => $placeholders['{servings}'],
        ];

        if (!empty($recipe_data['description'])) {
            $details[__('Description', 'plugixa-recipe')] = wp_trim_words($recipe_data['description'], 30);
        }

        $html_body = Mailer::build_html_email(
            __('New Recipe Submission', 'plugixa-recipe'),
            $message_text,
            $details,
            admin_url('admin.php?page=plugixa-recipe#/recipes'),
            __('Review Submissions', 'plugixa-recipe')
        );

        return Mailer::send_email($recipients, $subject, $html_body);
    }
}
