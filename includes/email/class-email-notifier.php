<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Email;

defined( 'ABSPATH' ) || exit;

/**
 * EmailNotifier Class - Sends email notifications for recipe-related events.
 *
 * Handles notification emails for new reviews and new recipe submissions
 * using HTML templates with placeholder substitution.
 */
class EmailNotifier {

    /**
     * Send notification email when a new review is submitted.
     *
     * @param array $review_data Review data with keys: recipe_id, recipe_title, author_name,
     *                           author_email, rating, review_text.
     * @return bool Whether the email was sent successfully.
     */
    public static function send_new_review_notification(array $review_data): bool {
        $settings = self::get_notification_settings();

        if (empty($settings['notification_enabled'])) {
            return false;
        }

        $recipients = self::get_recipients($settings);
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

        $html_body = self::build_html_email(
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

        return self::send_email($recipients, $subject, $html_body);
    }

    /**
     * Send notification email when a new recipe is submitted via the frontend form.
     *
     * @param array $recipe_data Recipe data with keys: title, author_name, description, difficulty, servings.
     * @return bool Whether the email was sent successfully.
     */
    public static function send_new_submission_notification(array $recipe_data): bool {
        $settings = self::get_notification_settings();

        if (empty($settings['notification_enabled'])) {
            return false;
        }

        $recipients = self::get_recipients($settings);
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
            '{admin_url}'    => admin_url('admin.php?page=plugixa-recipe-recipes'),
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

        $html_body = self::build_html_email(
            __('New Recipe Submission', 'plugixa-recipe'),
            $message_text,
            $details,
            admin_url('admin.php?page=plugixa-recipe-recipes'),
            __('Review Submissions', 'plugixa-recipe')
        );

        return self::send_email($recipients, $subject, $html_body);
    }

    /**
     * Build an HTML email from a template.
     *
     * @param string $heading    Email heading text.
     * @param string $message    Main message body.
     * @param array  $details    Key-value pairs for the detail table. Values are RAW (unescaped) and are
     *                           escaped at the output site with esc_html(). Several of them carry
     *                           visitor-submitted text (author name, review body), so they must NOT be
     *                           allowed to contribute markup to an admin's inbox - not even the subset
     *                           wp_kses_post() permits, which would still let a reviewer slip a link or a
     *                           tracking image into the notification.
     * @param string $action_url URL for the call-to-action button.
     * @param string $action_label Label for the CTA button.
     * @return string HTML email body.
     */
    private static function build_html_email(
        string $heading,
        string $message,
        array $details = [],
        string $action_url = '',
        string $action_label = ''
    ): string {
        $site_name = get_bloginfo('name');
        $brand_color = '#2563eb';

        ob_start();
        ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0; padding:0; background:#f3f4f6; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6; padding:30px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:8px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background:<?php echo esc_attr($brand_color); ?>; padding:24px 30px; text-align:center;">
                            <h1 style="color:#ffffff; font-size:22px; margin:0; font-weight:600;">
                                <?php echo esc_html($heading); ?>
                            </h1>
                        </td>
                    </tr>
                    <!-- Body -->
                    <tr>
                        <td style="padding:30px;">
                            <p style="font-size:15px; color:#374151; line-height:1.6; margin:0 0 20px 0;">
                                <?php
                                // esc_html() FIRST, then nl2br(): the message is the admin's
                                // template with visitor-supplied values ({author_name},
                                // {review_text}) already substituted in, so it must not be able
                                // to contribute markup. Escaping first also stops nl2br()'s <br>
                                // tags from being escaped in turn.
                                echo nl2br( esc_html( $message ) );
                                ?>
                            </p>

                            <?php if (!empty($details)): ?>
                            <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb; border-radius:6px; overflow:hidden; margin-bottom:20px;">
                                <?php foreach ($details as $label => $value): ?>
                                <tr>
                                    <td style="padding:10px 14px; background:#f9fafb; font-weight:600; font-size:13px; color:#6b7280; width:35%; border-bottom:1px solid #e5e7eb;">
                                        <?php echo esc_html($label); ?>
                                    </td>
                                    <td style="padding:10px 14px; font-size:14px; color:#111827; border-bottom:1px solid #e5e7eb;">
                                        <?php echo esc_html($value); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </table>
                            <?php endif; ?>

                            <?php if (!empty($action_url) && !empty($action_label)): ?>
                            <div style="text-align:center; margin:25px 0 10px;">
                                <a href="<?php echo esc_url($action_url); ?>"
                                   style="display:inline-block; padding:12px 30px; background:<?php echo esc_attr($brand_color); ?>; color:#ffffff; text-decoration:none; border-radius:6px; font-weight:600; font-size:14px;">
                                    <?php echo esc_html($action_label); ?>
                                </a>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="background:#f9fafb; padding:16px 30px; text-align:center; border-top:1px solid #e5e7eb;">
                            <p style="font-size:12px; color:#9ca3af; margin:0;">
                                <?php echo esc_html(sprintf(
                                    /* translators: %s: site name */
                                    __('This notification was sent by %s', 'plugixa-recipe'),
                                    $site_name
                                )); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
        <?php
        return ob_get_clean();
    }

    /**
     * Send HTML email using wp_mail.
     *
     * @param array  $recipients Array of email addresses.
     * @param string $subject    Email subject.
     * @param string $html_body  HTML email body.
     * @return bool Whether the email was sent.
     */
    private static function send_email(array $recipients, string $subject, string $html_body): bool {
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
        ];

        return wp_mail($recipients, $subject, $html_body, $headers);
    }

    /**
     * Get notification settings from the plugin settings.
     */
    private static function get_notification_settings(): array {
        return (array) get_option('plugixa_recipe_settings', []);
    }

    /**
     * Parse and validate recipient email addresses from settings.
     *
     * @param array $settings Plugin settings.
     * @return array Valid email addresses.
     */
    private static function get_recipients(array $settings): array {
        $recipients_str = $settings['notification_recipients'] ?? '';

        if (empty($recipients_str)) {
            // Fall back to admin email
            $admin_email = get_option('admin_email');
            return $admin_email ? [$admin_email] : [];
        }

        // Support comma and newline separated emails
        $emails = preg_split('/[\s,]+/', $recipients_str, -1, PREG_SPLIT_NO_EMPTY);
        $valid = [];

        foreach ($emails as $email) {
            $email = trim($email);
            if (is_email($email)) {
                $valid[] = $email;
            }
        }

        return $valid;
    }
}
