<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Reviews\Services;

defined( 'ABSPATH' ) || exit;

use PlugixaRecipe\Core\Mail\Mailer;

/**
 * Tells the site owner a review was posted. Called by PublicReviewService once a review is
 * stored.
 *
 * It replaced an inline wp_mail() there that passed the raw recipients string through
 * unparsed and, with no message template saved, sent an email with an empty body. This
 * class had been written to do the job properly and was called by nothing.
 */
final class ReviewNotifier {

    /**
     * Send notification email when a new review is submitted.
     *
     * @param array $review_data Review data with keys: recipe_id, recipe_title, author_name,
     *                           author_email, rating, review_text.
     * @return bool Whether the email was sent successfully.
     */
    public static function send_new_review_notification(array $review_data): bool {
        $settings = Mailer::get_notification_settings();

        if (empty($settings['notification_enabled'])) {
            return false;
        }

        $recipients = Mailer::get_recipients($settings);
        if (empty($recipients)) {
            return false;
        }

        $subject = $settings['notification_subject'] ?? __('New recipe review submitted', 'plugixa-recipe');
        $message_template = $settings['notification_message'] ?? __(
            'A new review has been submitted for "{recipe_title}" by {author_name}.',
            'plugixa-recipe'
        );

        // Build star rating display.
        //
        // Literal UTF-8 star characters, NOT the &#9733; entities: the detail values below
        // include visitor-submitted text (author name, review body), so they are escaped
        // with esc_html() at the output site. Entities would be double-encoded by that,
        // which is the only reason this ever needed a raw-HTML escape hatch. Real
        // characters pass through esc_html() untouched, so the values can stay escaped.
        $rating = (int) ($review_data['rating'] ?? 5);
        $stars = str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);

        $placeholders = [
            '{recipe_title}' => $review_data['recipe_title'] ?? __('Unknown Recipe', 'plugixa-recipe'),
            '{author_name}'  => $review_data['author_name'] ?? __('Anonymous', 'plugixa-recipe'),
            '{author_email}' => $review_data['author_email'] ?? '',
            '{rating}'       => $rating . '/5',
            '{rating_stars}' => $stars,
            '{review_text}'  => $review_data['review_text'] ?? '',
            '{site_name}'    => get_bloginfo('name'),
            '{site_url}'     => home_url(),
            '{admin_url}'    => admin_url('admin.php?page=plugixa-recipe-reviews'),
            '{date}'         => wp_date(get_option('date_format') . ' ' . get_option('time_format')),
        ];

        $subject = strtr($subject, $placeholders);
        $message_text = strtr($message_template, $placeholders);

        $html_body = Mailer::build_html_email(
            __('New Review Submitted', 'plugixa-recipe'),
            $message_text,
            [
                __('Recipe', 'plugixa-recipe')  => $placeholders['{recipe_title}'],
                __('Author', 'plugixa-recipe')  => $placeholders['{author_name}'],
                __('Rating', 'plugixa-recipe')  => $stars . ' (' . $rating . '/5)',
                __('Review', 'plugixa-recipe')  => $placeholders['{review_text}'],
            ],
            admin_url('admin.php?page=plugixa-recipe-reviews'),
            __('View All Reviews', 'plugixa-recipe')
        );

        return Mailer::send_email($recipients, $subject, $html_body);
    }
}
