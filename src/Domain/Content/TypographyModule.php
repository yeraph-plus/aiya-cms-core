<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Metadata\Registry;
use Aiya\Infra\Typesetting\ChineseTypesetting;
use WP_Term;
use Aiya\Core\Settings\Registry as SettingsRegistry;

/**
 * 排版工具（旧版 basic-optimize "数据更新" box 的重建，0.37.0）：post 编辑
 * 屏上的四个 action_checkbox——重置发布日期、自动检索标签、格式清理、中文
 * 排版纠正。勾选保存时经由 MetaboxAdmin 的 one-shot action 钩子触发本模块
 * 的处理器；处理器直接改写文章数据，busy 闸门阻断 save_post 重入递归。
 * 中文排版纠正的方法子集在 Optimization 页配置（typography_methods，逗号
 * 分隔，白名单校验），缺省为旧版默认的 insertSpace/removeSpace/full2Half。
 */
final class TypographyModule implements Module
{
    private bool $busy = false;

    public function __construct(
        private SettingsRegistry $settings,
        private Registry $metadata,
    ) {
    }

    public function register(): void
    {
        // Priority 12 appends this module's group after the avatar and slug
        // groups (both priority 11) on the shared Optimization page.
        add_action('aiya_core_register', [$this, 'registerBoxAndSettings'], 12, 0);
        add_action('aiya_core_typography_refresh_date', [$this, 'onRefreshDate']);
        add_action('aiya_core_typography_match_tags', [$this, 'onMatchTags']);
        add_action('aiya_core_typography_cleanup_html', [$this, 'onCleanupHtml']);
        add_action('aiya_core_typography_chinese_typesetting', [$this, 'onChineseTypesetting']);
    }

    public function registerBoxAndSettings(): void
    {
        $this->metadata->addPostBox([
            'id' => 'typography',
            'title' => __('Typography tools', 'aiya-core'),
            'screens' => ['post'],
            'context' => 'normal',
            'priority' => 'low',
            'fields' => [
                [
                    'id' => 'refresh_date',
                    'type' => 'action_checkbox',
                    'label' => __('Refresh the publish date to now', 'aiya-core'),
                    'action' => 'aiya_core_typography_refresh_date',
                ],
                [
                    'id' => 'match_tags',
                    'type' => 'action_checkbox',
                    'label' => __('Match existing tags against the content', 'aiya-core'),
                    'action' => 'aiya_core_typography_match_tags',
                ],
                [
                    'id' => 'cleanup_html',
                    'type' => 'action_checkbox',
                    'label' => __('Clean up legacy HTML (div/center/span, overlapping tags)', 'aiya-core'),
                    'action' => 'aiya_core_typography_cleanup_html',
                ],
                [
                    'id' => 'chinese_typesetting',
                    'type' => 'action_checkbox',
                    'label' => __('Run the Chinese typesetting pass', 'aiya-core'),
                    'action' => 'aiya_core_typography_chinese_typesetting',
                ],
            ],
        ]);

        $this->settings->addFields('optimization', [
            [
                'id' => 'heading_typography',
                'type' => 'heading',
                'label' => __('Chinese typesetting', 'aiya-core'),
            ],
            [
                'id' => 'typography_methods',
                'type' => 'multicheck',
                'label' => __('Enabled typesetting correctors', 'aiya-core'),
                'description' => __('Applied to the title and the content on the Chinese typesetting pass.', 'aiya-core'),
                'default' => ['insertSpace', 'removeSpace', 'full2Half'],
                'options' => [
                    'insertSpace' => __('Insert spaces between CJK and Latin/digits', 'aiya-core'),
                    'removeSpace' => __('Remove spaces around fullwidth punctuation', 'aiya-core'),
                    'full2Half' => __('Fullwidth letters, digits and symbols to halfwidth', 'aiya-core'),
                    'fixPunctuation' => __('Fix incorrect punctuation', 'aiya-core'),
                    'properNoun' => __('Correct proper-noun casing', 'aiya-core'),
                    'removeClass' => __('Strip class attributes', 'aiya-core'),
                    'removeId' => __('Strip id attributes', 'aiya-core'),
                    'removeStyle' => __('Strip style attributes', 'aiya-core'),
                    'removeEmptyParagraph' => __('Remove empty paragraph tags', 'aiya-core'),
                    'removeEmptyTag' => __('Remove all empty tags', 'aiya-core'),
                    'removeIndent' => __('Remove paragraph indentation', 'aiya-core'),
                ],
            ],
        ]);
    }

    public function onRefreshDate(int $postId): void
    {
        if ($this->busy) {
            return;
        }
        $this->busy = true;
        try {
            $now = current_time('mysql');
            wp_update_post([
                'ID' => $postId,
                'post_date' => $now,
                'post_date_gmt' => get_gmt_from_date($now),
            ]);
        } finally {
            $this->busy = false;
        }
    }

    public function onMatchTags(int $postId): void
    {
        $content = (string) get_post_field('post_content', $postId);
        $tagNames = [];
        $tags = get_tags(['hide_empty' => false]);
        if (is_array($tags)) {
            foreach ($tags as $tag) {
                if ($tag instanceof WP_Term) {
                    $tagNames[] = (string) $tag->name;
                }
            }
        }

        $matched = ContentFormatter::matchTagNames($content, $tagNames);
        if ($matched !== []) {
            wp_set_post_tags($postId, $matched, true);
        }
    }

    public function onCleanupHtml(int $postId): void
    {
        $content = (string) get_post_field('post_content', $postId);
        $clean = ContentFormatter::cleanupHtml($content);
        if ($clean !== $content && $clean !== '') {
            $this->updatePost($postId, ['post_content' => $clean]);
        }
    }

    public function onChineseTypesetting(int $postId): void
    {
        if ($this->busy) {
            return;
        }
        $this->busy = true;
        try {
            $methods = $this->enabledMethods();
            $typesetting = new ChineseTypesetting();

            $title = (string) get_post_field('post_title', $postId);
            $content = (string) get_post_field('post_content', $postId);

            wp_update_post([
                'ID' => $postId,
                'post_title' => $typesetting->correct($title, $methods),
                'post_content' => $typesetting->correct($content, $methods),
            ]);
        } finally {
            $this->busy = false;
        }
    }

    /** @return list<string> */
    private function enabledMethods(): array
    {
        $configured = (array) aiya_core_opt('optimization', 'typography_methods', ['insertSpace', 'removeSpace', 'full2Half']);

        return array_values(array_intersect(
            array_map('strval', $configured),
            ChineseTypesetting::METHODS
        ));
    }

    /** @param array<string, mixed> $fields */
    private function updatePost(int $postId, array $fields): void
    {
        $fields['ID'] = $postId;
        wp_update_post($fields);
    }
}
