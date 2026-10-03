<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Mail;

/**
 * The brand mail shell (docs/mail-template-preview.html, 2026-10-03 定版):
 * a 600px table layout with fully inlined styles — Outlook's Word engine
 * and the hard-boiled clients all render this shape. The theme color
 * tints the content layer only (CTA button, links, key-value accent);
 * neutral surfaces keep forced dark mode from inverting into a mess.
 *
 * Output is a complete document carrying {@see self::SHELL_MARKER} — the
 * takeover filter recognises its own (and the per-mail rewrite layer's)
 * product by that marker and never wraps a shell twice.
 */
final class MailTemplate
{
    public const SHELL_MARKER = '<!--aiya-mail-shell-->';

    public function __construct(
        private readonly string $color,
        private readonly string $siteName,
        private readonly string $siteUrl,
        /** Content-ID of the embedded site icon, or null for a text-only header. */
        private readonly ?string $iconCid = null,
    ) {
    }

    /**
     * Wraps rendered content HTML in the brand shell. `$toEmail` rides the
     * footer line when known; `$preheader` is the hidden inbox-summary
     * sentence (empty keeps the slot out of the markup).
     */
    public function render(string $contentHtml, string $preheader = '', string $toEmail = ''): string
    {
        $font = "-apple-system, 'PingFang SC', 'Microsoft YaHei', 'Segoe UI', sans-serif";
        $color = $this->color;
        $name = esc_html($this->siteName);
        $siteUrl = esc_url($this->siteUrl);
        $icon = $this->iconCid === null
            ? ''
            : '<td width="32" style="padding-right: 10px;"><img src="cid:' . esc_attr($this->iconCid) . '"'
                . ' width="32" height="32" alt="" style="display: block; width: 32px; height: 32px; border-radius: 8px;"></td>';
        $preheader = $preheader === ''
            ? ''
            : '<div style="display: none; max-height: 0; overflow: hidden; mso-hide: all;">' . esc_html($preheader) . '</div>';
        $sentTo = $toEmail === ''
            ? ''
            : '<br>' . sprintf(
                esc_html__('Sent to %1$s — you are receiving this message because of activity on your account.', 'aiya-core'),
                esc_html($toEmail)
            );
        $brandLink = '<a href="' . $siteUrl . '" style="color: #a1a1aa; text-decoration: underline;">' . $name . '</a>';

        return self::SHELL_MARKER . '<!DOCTYPE html>
<html lang="zh-CN" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<style>
body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
table, td { mso-table-lspace: 0; mso-table-rspace: 0; }
img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
body { margin: 0 !important; padding: 0 !important; width: 100% !important; }
@media only screen and (max-width: 620px) {
    .aiya-shell { width: 100% !important; }
    .aiya-pad { padding-left: 22px !important; padding-right: 22px !important; }
}
</style>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4f5;">
' . $preheader . '
<!--[if mso]>
<table role="presentation" width="600" align="center"><tr><td>
<![endif]-->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f4f4f5;">
<tr>
<td align="center" style="padding: 28px 12px;">
<table role="presentation" class="aiya-shell" width="600" cellpadding="0" cellspacing="0" style="width: 600px; max-width: 600px; background-color: #ffffff; border-radius: 12px; overflow: hidden;">
<tr>
<td class="aiya-pad" style="padding: 22px 32px 18px;">
<table role="presentation" cellpadding="0" cellspacing="0">
<tr>' . $icon . '<td style="font-family: ' . $font . '; font-size: 16px; font-weight: 600; color: #18181b;">' . $name . '</td></tr>
</table>
</td>
</tr>
<tr>
<td class="aiya-pad" style="padding: 0 32px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
<tr><td style="height: 1px; background-color: #e4e4e7; font-size: 0; line-height: 0;">&nbsp;</td></tr>
</table>
</td>
</tr>
<tr>
<td class="aiya-pad" style="padding: 26px 32px 30px; font-family: ' . $font . '; font-size: 14px; line-height: 1.75; color: #3f3f46;">
' . $contentHtml . '
</td>
</tr>
<tr>
<td class="aiya-pad" style="padding: 0 32px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
<tr><td style="height: 1px; background-color: #e4e4e7; font-size: 0; line-height: 0;">&nbsp;</td></tr>
</table>
</td>
</tr>
<tr>
<td class="aiya-pad" style="padding: 16px 32px 22px; font-family: ' . $font . '; font-size: 12px; line-height: 1.7; color: #a1a1aa;">
' . sprintf(esc_html__('This message was sent automatically by %1$s — do not reply.', 'aiya-core'), $brandLink) . $sentTo . '
</td>
</tr>
</table>
</td>
</tr>
</table>
<!--[if mso]>
</td></tr></table>
<![endif]-->
</body>
</html>';
    }

    /**
     * The key-value panel (receipts, account facts): label in the muted
     * color, value emphasized; the LAST value renders in the theme color —
     * put the urgent figure last. Labels and values are escaped here.
     *
     * @param array<string, string> $rows label => raw value
     */
    public function rows(array $rows): string
    {
        $cells = '';
        $last = array_key_last($rows);
        foreach ($rows as $label => $value) {
            $valueColor = $label === $last ? $this->color : '#18181b';
            $cells .= '<tr>'
                . '<td style="padding: 3px 0; color: #71717a; width: 96px;">' . esc_html($label) . '</td>'
                . '<td style="padding: 3px 0; color: ' . $valueColor . '; font-weight: 600;">' . esc_html($value) . '</td>'
                . '</tr>';
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 20px; background-color: #fafafa; border: 1px solid #e4e4e7; border-radius: 8px;">
<tr><td style="padding: 14px 18px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size: 13px;">' . $cells . '</table>
</td></tr>
</table>';
    }

    /**
     * The bulletproof CTA button (table-based, Outlook-safe) with the
     * plain-link fallback line under it — the standard action component
     * for reset links and their kin.
     */
    public function button(string $label, string $url): string
    {
        $button = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin: 0 0 24px;">
<tr><td style="background-color: ' . $this->color . '; border-radius: 8px;">
<a href="' . esc_url($url) . '" style="display: inline-block; padding: 11px 28px; font-family: -apple-system, \'PingFang SC\', \'Microsoft YaHei\', \'Segoe UI\', sans-serif; font-size: 14px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 8px;">'
            . esc_html($label) . '</a></td></tr>
</table>';

        return $button . '<p style="margin: 0 0 24px; font-size: 12px; color: #71717a;">'
            . esc_html__('If the button does not work, copy this link into your browser:', 'aiya-core') . '<br>'
            . '<a href="' . esc_url($url) . '" style="color: ' . $this->color . '; text-decoration: underline; word-break: break-all;">'
            . esc_html($url) . '</a></p>';
    }
}
