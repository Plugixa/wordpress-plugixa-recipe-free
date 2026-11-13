<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Core\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * The plumbing every notification email shares: who receives it, the HTML frame, the send.
 *
 * What an email SAYS belongs to the module it is about - see the Submissions and Reviews
 * notifiers. This class knows nothing about either.
 */
final class Mailer {

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
    public static function build_html_email(
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
    public static function send_email(array $recipients, string $subject, string $html_body): bool {
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
        ];

        return wp_mail($recipients, $subject, $html_body, $headers);
    }

    /**
     * Get notification settings from the plugin settings.
     */
    public static function get_notification_settings(): array {
        return (array) get_option('plugixa_recipe_settings', []);
    }

    /**
     * Parse and validate recipient email addresses from settings.
     *
     * @param array $settings Plugin settings.
     * @return array Valid email addresses.
     */
    public static function get_recipients(array $settings): array {
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
