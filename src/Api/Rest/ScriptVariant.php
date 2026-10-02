<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Api\Contract\Contract;
use Aiya\Core\Domain\Content\FrontendModule;
use Aiya\Infra\OpenCc\Converter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Chinese script-variant conversion at the contract's exit. The viewer's
 * resolved locale (an explicit profile choice, then the front-end default
 * language, then the site default) maps to an OpenCC strategy; when it is
 * a Traditional variant, every string leaf of the response data converts,
 * so authored content — titles, bodies, taxonomies, menus, UGC — reads in
 * the viewer's script while exactly one authored copy is stored.
 *
 * Scope is the aiya/core/v1 namespace only: aiya-publish is a machine
 * protocol whose payloads must stay byte-stable for the local publisher,
 * and the other firstparty namespaces are server-to-server surfaces. On a
 * zh_CN/en_US site the strategy is null and the filter is a zero-cost
 * pass-through — the dictionary engine never initializes.
 *
 * The walk runs on the enveloped response, downstream of every server-side
 * cache: contentHtml and card object caches keep storing authored
 * (variant-free) strings, so they stay shared across viewers, and each
 * variant viewer pays one conversion pass per request. It also runs before
 * HttpCache's ETag (rest_pre_serve_request), so the hash folds the variant
 * in and a locale switch can never be answered by a stale 304.
 *
 * Strings containing markup go through the tag-aware splitter: text nodes
 * and the visible attributes convert, tags (URLs, data payloads) do not,
 * and code/pre content passes through untouched. String leaves without a
 * CJK ideograph are returned as-is.
 */
final class ScriptVariant
{
    /** Keys whose values are byte-stable protocol or machine data and never convert. */
    private const PROTECTED_KEYS = [
        'slug', 'url', 'uri', 'href', 'src', 'email', 'login', 'apiVersion',
        'requestId', 'timezone', 'locale', 'language', 'token', 'key',
    ];

    /** Visible text attributes converted inside tag tokens. */
    private const VISIBLE_ATTRIBUTES = ['title', 'alt', 'placeholder', 'aria-label'];

    private const CJK = '/[\x{3400}-\x{9FFF}\x{F900}-\x{FAFF}]/u';

    public function __construct(private readonly Converter $converter)
    {
    }

    public function register(): void
    {
        add_filter('rest_post_dispatch', [$this, 'apply'], 20, 3);
    }

    /**
     * @param WP_Error|WP_REST_Response|mixed $result
     * @return WP_Error|WP_REST_Response|mixed
     */
    public function apply(mixed $result, WP_REST_Server $server, WP_REST_Request $request): mixed
    {
        if (!$result instanceof WP_REST_Response
            || !str_starts_with($request->get_route(), '/' . Contract::API_NAMESPACE)
        ) {
            return $result;
        }

        $strategy = $this->converter->strategyForLocale($this->viewerLocale());
        if ($strategy === null) {
            return $result;
        }

        $data = $result->get_data();
        if (!is_array($data) || !array_key_exists('data', $data)) {
            return $result;
        }

        $data['data'] = $this->walk($data['data'], $strategy);
        $result->set_data($data);

        return $result;
    }

    /**
     * The viewer's locale, mirroring the spec: a signed-in member's explicit
     * profile choice wins, everyone else starts from the configured front-end
     * default (falling back to the WP site language). get_user_locale() is
     * deliberately not used — it answers the site language for members who
     * never chose, which would pin them to the old default after the front
     * end switches to its own.
     */
    private function viewerLocale(): string
    {
        if (is_user_logged_in()) {
            $explicit = get_user_meta(get_current_user_id(), 'locale', true);
            if (is_string($explicit) && $explicit !== '') {
                return $explicit;
            }
        }

        return FrontendModule::anonymousLocale();
    }

    /** The decision core, pure: walks one payload tree through the strategy. */
    public function walk(mixed $value, string $strategy): mixed
    {
        if (is_string($value)) {
            return $this->text($value, $strategy);
        }

        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $value[$key] = is_string($key) && in_array($key, self::PROTECTED_KEYS, true)
                    ? $child
                    : $this->walk($child, $strategy);
            }
        }

        return $value;
    }

    /** One string leaf: markup goes through the splitter, plain text wholesale. */
    private function text(string $value, string $strategy): string
    {
        if ($value === '' || !preg_match(self::CJK, $value)) {
            return $value;
        }

        if (str_contains($value, '<')) {
            return $this->markup($value, $strategy);
        }

        return $this->converter->convert($value, $strategy);
    }

    /**
     * Tag-aware conversion over the core html splitter: text tokens convert
     * (except inside code/pre), tag tokens only donate their visible
     * attributes. Everything else — URLs, data-* payloads, comments —
     * passes through byte-stable.
     */
    public function markup(string $html, string $strategy): string
    {
        $tokens = wp_html_split($html);
        $inCode = 0;

        foreach ($tokens as $index => $token) {
            if ($token === '' || $token[0] !== '<') {
                if ($inCode === 0 && preg_match(self::CJK, $token)) {
                    $tokens[$index] = $this->converter->convert($token, $strategy);
                }
                continue;
            }

            if (str_starts_with($token, '<!--')) {
                continue;
            }

            // Case-insensitive against hand-typed markup (<CODE>, <Pre>):
            // the head alone is lowercased, not the whole token.
            $head = strtolower(substr($token, 0, 6));
            $opening = str_starts_with($head, '<code') || str_starts_with($head, '<pre');
            $closing = str_starts_with($head, '</code') || str_starts_with($head, '</pre');
            if ($opening || $closing) {
                $inCode = max(0, $inCode + ($opening ? 1 : -1));
                continue;
            }

            $tokens[$index] = preg_replace_callback(
                '/(\b(?:' . implode('|', self::VISIBLE_ATTRIBUTES) . ')\s*=\s*)("([^"]*)"|\'([^\']*)\')/i',
                function (array $m) use ($strategy): string {
                    $inner = $m[3] ?? $m[4] ?? '';
                    $converted = preg_match(self::CJK, $inner)
                        ? $this->converter->convert($inner, $strategy)
                        : $inner;
                    $quote = $m[2][0];

                    return $m[1] . $quote . $converted . $quote;
                },
                $token
            ) ?? $token;
        }

        return implode('', $tokens);
    }
}
