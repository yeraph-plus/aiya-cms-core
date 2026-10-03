<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Mail;

/**
 * The mail takeover (2026-10-03 定版): a `wp_mail` args filter is the whole
 * mechanism — content and headers are rewritten into the brand shell and
 * delivery itself stays on WordPress's native chain (default `mail()`),
 * per the owner's ruling that only text and style change while WP
 * behaviour remains untouched. No transport, no queue, no log.
 *
 * Plain-text messages are escaped into the shell's content slot; HTML
 * fragments (the Send Mail screen's composed markup, future per-mail
 * templates) ride the slot as-is. A message already carrying the shell
 * marker passes through untouched, so the per-mail rewrite layer and a
 * re-entrant filter can never double-wrap. The Content-Type header is
 * normalised to text/html, and when a site icon exists its attachment
 * file joins `$embeds` under a fixed Content-ID for the header image
 * (WP 6.9+ embeds, inline CID — never a remote image).
 */
final class MailShell
{
    /** Content-ID the site icon embeds under (default cid = embeds key). */
    public const ICON_CID = 'aiya-site-icon';

    public function __construct(private readonly MailTemplate $template, private readonly ?string $iconPath = null)
    {
    }

    /** The renderer, for the per-mail rewrite layer that produces shell-marked documents of its own. */
    public function template(): MailTemplate
    {
        return $this->template;
    }

    /**
     * Assembles the shell from site configuration: the theme color from
     * the frontend settings, blogname and the front-end origin (the brand
     * link lands on the site readers know), and
     * the site icon's attachment file when one is set.
     */
    public static function fromSite(): self
    {
        $iconPath = null;
        $iconId = (int) get_option('site_icon');
        if ($iconId > 0) {
            $file = get_attached_file($iconId);
            if (is_string($file) && $file !== '' && is_file($file)) {
                $iconPath = $file;
            }
        }

        $color = (string) aiya_core_opt('frontend', 'color_primary', '#e94f69');
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) !== 1) {
            // A malformed custom value must not ride into inline style attributes.
            $color = '#e94f69';
        }

        return new self(
            new MailTemplate(
                $color,
                wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES),
                \Aiya\Core\Domain\Shared\FrontendDomain::originOrHome(),
                $iconPath !== null ? self::ICON_CID : null,
            ),
            $iconPath,
        );
    }

    /**
     * `wp_mail` args filter: wraps the message in the brand shell and
     * normalises the Content-Type header.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function apply(array $args): array
    {
        $message = (string) ($args['message'] ?? '');
        if ($message === '') {
            return $args;
        }

        $headers = $this->headerLines((array) ($args['headers'] ?? []));

        // A multipart body is a fully-formed MIME document a third party
        // built; the takeover only understands single-part text, so it
        // stands down rather than break the boundary structure.
        foreach ($headers as $line) {
            if (preg_match('/^content-type:\s*multipart\//i', trim($line)) === 1) {
                return $args;
            }
        }

        if (str_contains($message, MailTemplate::SHELL_MARKER)) {
            // A marked message is finished brand HTML (this shell's or the
            // rewrite layer's), but message-only filters like
            // retrieve_password_message cannot touch headers — ship it as
            // text/html here or the branded document travels as plain text.
            // The icon embed stays ours to satisfy: the rewrite layer's
            // document references the CID and notification-style callers
            // cannot pass embeds at all.
            $args['headers'] = $this->withoutContentType($headers);
            $args = $this->withIconEmbed($args, $message);

            return $args;
        }

        $isHtml = $this->isHtml($headers);

        $content = $isHtml
            ? $message
            : wpautop(esc_html($message));
        $args['message'] = $this->template->render($content, '', $this->firstRecipient($args['to'] ?? ''));

        // The shell is text/html by construction: any prior Content-Type
        // line goes, the normalised one stays.
        $args['headers'] = $this->withoutContentType($headers);
        $args = $this->withIconEmbed($args, '');

        return $args;
    }

    /**
     * Appends the site icon under the shell's Content-ID whenever the
     * message references it and the icon file is actually there.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function withIconEmbed(array $args, string $message): array
    {
        if ($this->iconPath === null) {
            return $args;
        }
        if ($message !== '' && !str_contains($message, self::ICON_CID)) {
            return $args; // a text-only header references no CID
        }

        // wp_mail also accepts newline-separated path strings — keep
        // the paths that arrived and append the icon under our CID.
        $embeds = is_array($args['embeds'] ?? null)
            ? $args['embeds']
            : explode("\n", str_replace("\r\n", "\n", (string) ($args['embeds'] ?? '')));
        $embeds = array_values(array_filter(array_map('strval', $embeds)));
        $args['embeds'] = array_merge($embeds, [self::ICON_CID => $this->iconPath]);

        return $args;
    }

    /**
     * The shell is text/html by construction: any prior Content-Type line
     * goes, the normalised one stays.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private function withoutContentType(array $lines): array
    {
        return array_values(array_merge(
            array_values(array_filter($lines, static fn (string $line): bool => preg_match('/^content-type:/i', trim($line)) !== 1)),
            [$this->htmlContentType()]
        ));
    }

    /**
     * Header arrays come in two core shapes — a list of "Name: value"
     * strings, or name => value pairs — plus plain strings with newlines;
     * everything lands in one "Name: value" list, empty names dropped.
     *
     * @param array<int|string, mixed> $headers
     * @return list<string>
     */
    private function headerLines(array $headers): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            if (!is_string($value)) {
                continue; // non-standard nested header shapes stay out
            }
            if (is_string($name) && $name !== '') {
                $lines[] = $name . ': ' . $value;
                continue;
            }
            foreach (explode("\n", str_replace("\r\n", "\n", $value)) as $line) {
                if (trim($line) !== '') {
                    $lines[] = trim($line);
                }
            }
        }

        return $lines;
    }

    /** @param list<string> $lines */
    private function isHtml(array $lines): bool
    {
        foreach ($lines as $line) {
            if (preg_match('/^content-type:\s*text\/html/i', trim($line)) === 1) {
                return true;
            }
        }

        return false;
    }

    private function htmlContentType(): string
    {
        $charset = (string) get_option('blog_charset');

        return 'Content-Type: text/html; charset=' . ($charset !== '' ? $charset : 'UTF-8');
    }

    /**
     * The footer's "sent to" line takes the first parseable recipient —
     * `to` arrives as a string (possibly comma-separated) or a list.
     *
     * @param mixed $to
     */
    private function firstRecipient(mixed $to): string
    {
        $joined = is_array($to) ? implode(',', array_map('strval', $to)) : (string) ($to ?? '');
        foreach (explode(',', $joined) as $candidate) {
            $candidate = trim($candidate, " \t\n\r<>\"");
            if (is_email($candidate)) {
                return $candidate;
            }
        }

        return '';
    }
}
