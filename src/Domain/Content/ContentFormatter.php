<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

/**
 * Legacy content-formatting transformations ported from the old
 * basic-optimize "数据更新" actions (0.37.0). Pure string functions:
 * WordPress IO (reading the post, writing it back, tag assignment)
 * belongs to the calling TypographyModule.
 */
final class ContentFormatter
{
    /**
     * 格式清理：合并自旧版 light_insert_data_re_* 四个清理步骤——去除全角
     * 空格与 &nbsp;、div/center 段落归一为 p、清理重叠的 strong/b 标签、
     * 删除 span/section 标签。
     */
    public static function cleanupHtml(string $html): string
    {
        // 去除全角空格与 &nbsp;
        $html = self::sub('/(　)*/', '', $html);
        $html = self::sub('/&nbsp;/', '', $html);

        // div/center 段落替换为 p
        foreach (['div', 'center'] as $tag) {
            $html = self::sub('#<' . $tag . '[^>]*>(.*?)</' . $tag . '>#is', '<p>$1</p>', $html);
        }

        // 清理重叠的 strong/b 标签
        foreach (['strong', 'b'] as $tag) {
            $html = self::sub('#<' . $tag . '><' . $tag . '>(.*?)</' . $tag . '></' . $tag . '>#is', '<' . $tag . '>$1</' . $tag . '>', $html);
            $html = self::sub('#</' . $tag . '><' . $tag . '>#is', '', $html);
            $html = self::sub('#<' . $tag . '></' . $tag . '>#is', '', $html);
        }

        // 删除 span/section 标签（保留内容）
        foreach (['span', 'section'] as $tag) {
            $html = self::sub('/<(\/?' . $tag . '.*?)>/si', '', $html);
        }

        return trim($html);
    }

    /**
     * 自动检索标签：返回正文中出现过的标签名（strpos 全文匹配，旧版语义，
     * 匹配不一定准确）。
     *
     * @param list<string> $tagNames
     * @return list<string>
     */
    public static function matchTagNames(string $content, array $tagNames): array
    {
        $matched = [];
        foreach ($tagNames as $name) {
            $name = (string) $name;
            if ($name !== '' && str_contains($content, $name)) {
                $matched[] = $name;
            }
        }

        return $matched;
    }

    /** Null-safe preg_replace：正则编译失败时保持原文。 */
    private static function sub(string $pattern, string $replacement, string $subject): string
    {
        $result = preg_replace($pattern, $replacement, $subject);

        return is_string($result) ? $result : $subject;
    }
}
