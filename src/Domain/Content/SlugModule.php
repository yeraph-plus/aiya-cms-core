<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Settings\Registry;
use Aiya\Infra\SlugToolkit\IdSlugEncoder;
use Aiya\Infra\SlugToolkit\PinyinConverter;

/**
 * Automatic slugs for Chinese-language content — the slug half of batch 3
 * in docs/optimize-migration-assessment.md. The conversion primitives live
 * in the slug-toolkit package (WordPress-free, policy-free); this module
 * owns the WordPress policy: which hooks fire, which post types are
 * affected, and how candidates are sanitized and deduplicated.
 *
 * Slugs inherit the legacy site's output: pinyin slugs come from
 * overtrue/pinyin (same library), ID slugs from the inherited XDE_code
 * algorithm, so old and new posts share one slug space.
 *
 * ID slugs apply on updates through wp_unique_post_slug; on first insert
 * the post ID does not exist yet, so wp_insert_post writes the generated
 * slug afterwards (guarded against recursion).
 *
 * The content reformatting half of the legacy component (Chinese typesetting,
 * HTML cleanup, auto tags) waits for the M2 action_checkbox field and is not
 * implemented here.
 */
final class SlugModule implements Module
{
    private const PAGE_SLUG = 'headless';
    private const MAX_PINYIN_LENGTH = 60;

    private ?PinyinConverter $pinyin = null;
    private ?IdSlugEncoder $encoder = null;

    public function __construct(private Registry $settings)
    {
    }

    public function register(): void
    {
        add_action('aiya_core_register', [$this, 'settings'], 11, 0);
        add_filter('wp_insert_post_data', [$this, 'postSlugFromTitle'], 10, 2);
        add_filter('wp_unique_post_slug', [$this, 'forcedIdSlug'], 10, 6);
        add_action('wp_insert_post', [$this, 'applyIdSlugAfterInsert'], 10, 3);
        add_filter('wp_insert_term_data', [$this, 'termSlugOnInsert'], 10, 3);
        add_filter('wp_update_term_data', [$this, 'termSlugOnUpdate'], 10, 4);
    }

    public function settings(): void
    {
        $this->settings->addFields(self::PAGE_SLUG, [
            [
                'id' => 'slug_post_mode',
                'type' => 'select',
                'label' => __('Post slug generation', 'aiya-core'),
                'description' => __('Pinyin fills empty slugs from the title; the ID modes force an ID-based slug on every save.', 'aiya-core'),
                'default' => 'off',
                'options' => [
                    'off' => __('Off', 'aiya-core'),
                    'pinyin' => __('Pinyin from title', 'aiya-core'),
                    'id_av' => __('ID, zero padded (AV style)', 'aiya-core'),
                    'id_bv' => __('ID, XDE encoded (BV style)', 'aiya-core'),
                ],
            ],
            [
                'id' => 'slug_post_types',
                'type' => 'array',
                'label' => __('Slug post types', 'aiya-core'),
                'description' => __('Comma-separated post type names the slug modes apply to.', 'aiya-core'),
                'default' => ['post'],
            ],
            [
                'id' => 'slug_term_pinyin',
                'type' => 'switch',
                'label' => __('Term slugs from pinyin', 'aiya-core'),
                'checkbox_label' => __('Generate pinyin slugs for terms with empty slugs', 'aiya-core'),
                'default' => true,
            ],
            [
                'id' => 'slug_id_prefix',
                'type' => 'text',
                'label' => __('ID slug prefix', 'aiya-core'),
                'description' => __('Prepended to generated ID slugs; non-URL characters are stripped.', 'aiya-core'),
                'default' => '',
            ],
        ]);
    }

    /**
     * Pinyin mode: fills the post slug from the title when the caller did
     * not provide one. Core already pre-fills $data['post_name'] (URL-encoded
     * title, uniquified) before this filter, so the emptiness check reads the
     * original $postarr instead; the candidate is deduplicated here because
     * core does not run wp_unique_post_slug again after this filter.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $postarr
     * @return array<string, mixed>
     */
    public function postSlugFromTitle(array $data, array $postarr): array
    {
        if ((string) aiya_core_opt(self::PAGE_SLUG, 'slug_post_mode', 'off') !== 'pinyin') {
            return $data;
        }
        if (!in_array((string) ($data['post_type'] ?? ''), $this->postTypes(), true)) {
            return $data;
        }
        if (($data['post_status'] ?? '') === 'auto-draft' || ($postarr['post_name'] ?? '') !== '' || ($data['post_title'] ?? '') === '') {
            return $data;
        }

        $data['post_name'] = wp_unique_post_slug(
            $this->pinyinCandidate((string) $data['post_title']),
            (int) ($postarr['ID'] ?? 0),
            (string) ($data['post_status'] ?? 'publish'),
            (string) ($data['post_type'] ?? 'post'),
            (int) ($postarr['post_parent'] ?? 0)
        );

        return $data;
    }

    /**
     * ID modes: forces the ID-based slug whenever the post ID exists (all
     * saves after creation; creation itself is handled by applyIdSlugAfterInsert).
     */
    public function forcedIdSlug(string $slug, int $postId, string $postStatus, string $postType, int $postParent, ?string $originalSlug = null): string
    {
        if ($postId <= 0 || !in_array((string) aiya_core_opt(self::PAGE_SLUG, 'slug_post_mode', 'off'), ['id_av', 'id_bv'], true)) {
            return $slug;
        }
        if (!in_array($postType, $this->postTypes(), true)) {
            return $slug;
        }

        return $this->idCandidate($postId);
    }

    /**
     * First insert in an ID mode: the post ID only exists after the row is
     * written, so the generated slug is applied in a second pass. The hook
     * is detached around the update to keep the recursion bounded.
     */
    public function applyIdSlugAfterInsert(int $postId, \WP_Post $post, bool $update): void
    {
        if ($update || $postId <= 0) {
            return;
        }
        if (!in_array((string) aiya_core_opt(self::PAGE_SLUG, 'slug_post_mode', 'off'), ['id_av', 'id_bv'], true)) {
            return;
        }
        if (!in_array($post->post_type, $this->postTypes(), true) || $post->post_status === 'auto-draft') {
            return;
        }

        remove_action('wp_insert_post', [$this, 'applyIdSlugAfterInsert'], 10);
        wp_update_post([
            'ID' => $postId,
            'post_name' => $this->idCandidate($postId),
        ]);
        add_action('wp_insert_post', [$this, 'applyIdSlugAfterInsert'], 10, 3);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function termSlugOnInsert(array $data, string $taxonomy, array $args): array
    {
        return $this->fillTermSlug($data, $args);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function termSlugOnUpdate(array $data, int $termId, string $taxonomy, array $args): array
    {
        return $this->fillTermSlug($data, $args);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function fillTermSlug(array $data, array $args): array
    {
        if (!(bool) aiya_core_opt(self::PAGE_SLUG, 'slug_term_pinyin', true)) {
            return $data;
        }
        // Check the original args: core may already have derived a non-empty
        // slug from the name before this filter.
        if (($args['slug'] ?? '') !== '' || ($data['name'] ?? '') === '') {
            return $data;
        }

        $data['slug'] = sanitize_title($this->pinyin()->permalink((string) $data['name']));

        return $data;
    }

    /** @return list<string> */
    private function postTypes(): array
    {
        $types = aiya_core_opt(self::PAGE_SLUG, 'slug_post_types', ['post']);
        if (!is_array($types)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $types), static fn (string $type): bool => $type !== ''));
    }

    private function idCandidate(int $postId): string
    {
        $prefix = (string) aiya_core_opt(self::PAGE_SLUG, 'slug_id_prefix', '');
        $cleaned = preg_replace('/[^a-zA-Z0-9\-._~]/', '', $prefix);
        $prefix = is_string($cleaned) ? $cleaned : '';

        $mode = (string) aiya_core_opt(self::PAGE_SLUG, 'slug_post_mode', 'off');
        $generated = $mode === 'id_av'
            ? $prefix . str_pad((string) $postId, 8, '0', STR_PAD_LEFT)
            : $prefix . $this->encoder()->encodeId($postId);

        return sanitize_title($generated);
    }

    private function pinyinCandidate(string $title): string
    {
        $slug = $this->pinyin()->permalink($title);

        // Legacy aya_trim_slug semantics: cap at 60 chars, cut back to the
        // last divider so no half syllable remains.
        if (strlen($slug) > self::MAX_PINYIN_LENGTH) {
            $slug = substr($slug, 0, self::MAX_PINYIN_LENGTH);
            $cut = strrpos($slug, '-');
            $slug = $cut === false ? $slug : substr($slug, 0, (int) $cut);
        }

        return sanitize_title($slug);
    }

    private function pinyin(): PinyinConverter
    {
        return $this->pinyin ??= new PinyinConverter();
    }

    private function encoder(): IdSlugEncoder
    {
        return $this->encoder ??= new IdSlugEncoder();
    }
}
