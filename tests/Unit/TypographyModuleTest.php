<?php

declare(strict_types=1);

namespace {
    /**
     * Test-local doubles for the six WordPress functions the typography
     * handlers call that tests/bootstrap.php does not provide. They are
     * guarded (a bootstrap addition wins by load order), record every write
     * into their own globals, and have no consumer anywhere else in the
     * suite — other test files stay untouched by their presence.
     */

    if (!function_exists('wp_update_post')) {
        /**
         * Records the payload, mirrors it onto the fixture post (the way
         * core writes the row), and — when the test staged a callback —
         * fires it mid-write, which is exactly the save_post window the
         * busy gate has to survive.
         *
         * @param array<string, mixed> $postarr
         */
        function wp_update_post(array $postarr, bool $wpError = false): int|WP_Error
        {
            $GLOBALS['__aiya_test_post_updates'][] = $postarr;

            $onUpdate = $GLOBALS['__aiya_test_on_post_update'] ?? null;
            if (is_callable($onUpdate)) {
                $onUpdate((int) ($postarr['ID'] ?? 0), $postarr);
            }

            $post = get_post((int) ($postarr['ID'] ?? 0));
            if ($post instanceof WP_Post) {
                foreach ($postarr as $field => $value) {
                    if ($field !== 'ID') {
                        $post->{$field} = $value;
                    }
                }
            }

            return (int) ($postarr['ID'] ?? 0);
        }
    }

    if (!function_exists('get_gmt_from_date')) {
        /**
         * UTC-only double, like the suite's other date doubles; the
         * tests pin date_default_timezone_set('UTC') so local and GMT
         * stamps coincide by construction.
         */
        function get_gmt_from_date(string $date, string $format = 'Y-m-d H:i:s'): string
        {
            $parsed = strtotime($date);

            return $parsed === false ? $date : gmdate($format, $parsed);
        }
    }

    if (!function_exists('get_post_type')) {
        function get_post_type(mixed $post = null): string|false
        {
            $record = $GLOBALS['__aiya_test_posts'][(int) $post] ?? null;

            return $record instanceof WP_Post ? (string) $record->post_type : false;
        }
    }

    if (!function_exists('is_object_in_taxonomy')) {
        /** Fixture: $GLOBALS['__aiya_test_type_taxonomies'][type][taxonomy] = true. */
        function is_object_in_taxonomy(string $postType, string $taxonomy): bool
        {
            return (bool) ($GLOBALS['__aiya_test_type_taxonomies'][$postType][$taxonomy] ?? false);
        }
    }

    if (!function_exists('get_tags')) {
        /** Fixture may inject false to stand for a failed tag query. */
        function get_tags(array $args = []): mixed
        {
            return $GLOBALS['__aiya_test_tag_query'] ?? [];
        }
    }

    if (!function_exists('wp_set_post_tags')) {
        /**
         * @param list<string> $tags
         * @return list<string>
         */
        function wp_set_post_tags(int $postId, array $tags = [], bool $append = true): array
        {
            $GLOBALS['__aiya_test_tag_assignments'][$postId] = ['tags' => $tags, 'append' => $append];

            return $tags;
        }
    }
}

namespace Aiya\Core\Tests\Unit {

    use Aiya\Core\Domain\Typography\TypographyModule;
    use Aiya\Core\Metadata\Registry as MetadataRegistry;
    use Aiya\Core\Settings\Registry as SettingsRegistry;
    use Aiya\Infra\Typesetting\ChineseTypesetting;
    use PHPUnit\Framework\TestCase;
    use WP_Post;
    use WP_Term;

    /**
     * The four one-shot typography actions on the post edit sidebar: date
     * refresh, tag matching, legacy HTML cleanup and the Chinese typesetting
     * pass. Every corrector branch pins its fullwidth/halfwidth and
     * punctuation-squeezing semantics through a concrete transformed string,
     * the busy gate must swallow re-entrant calls issued from inside the
     * write (the save_post window) but reset afterwards, and the method
     * whitelist reads the Optimization option in configured order while
     * dropping unknown names.
     */
    final class TypographyModuleTest extends TestCase
    {
        private string $timezone;

        private SettingsRegistry $settings;

        protected function setUp(): void
        {
            $this->timezone = date_default_timezone_get();
            // The date refresh asserts local == GMT, so the suite pins UTC
            // the same way the suite's UTC-only date doubles assume.
            date_default_timezone_set('UTC');

            $GLOBALS['__aiya_test_posts'] = [];
            $GLOBALS['__aiya_test_options'] = [];
            $GLOBALS['__aiya_test_filters'] = [];
            $GLOBALS['__aiya_test_meta_boxes'] = [];
            $GLOBALS['__aiya_test_post_updates'] = [];
            $GLOBALS['__aiya_test_on_post_update'] = null;
            $GLOBALS['__aiya_test_tag_query'] = [];
            $GLOBALS['__aiya_test_tag_assignments'] = [];
            $GLOBALS['__aiya_test_type_taxonomies'] = [];

            $this->settings = new SettingsRegistry();
            $this->settings->addPage([
                'slug' => 'backend',
                'title' => 'Backend',
                'option_name' => 'aiya_core_backend',
            ]);
        }

        protected function tearDown(): void
        {
            date_default_timezone_set($this->timezone);
            $GLOBALS['__aiya_test_on_post_update'] = null;
        }

        private function module(): TypographyModule
        {
            return new TypographyModule($this->settings, new MetadataRegistry());
        }

        private function seedPost(int $id, string $title, string $content, string $type = 'post'): WP_Post
        {
            $post = new WP_Post((object) [
                'ID' => $id,
                'post_type' => $type,
                'post_status' => 'publish',
                'post_title' => $title,
                'post_content' => $content,
            ]);
            $GLOBALS['__aiya_test_posts'][$id] = $post;

            return $post;
        }

        /** @return list<array<string, mixed>> */
        private function updates(): array
        {
            return $GLOBALS['__aiya_test_post_updates'];
        }

        /**
         * Runs the Chinese typesetting pass over a single content string
         * with an explicit method set and returns the written content, so
         * every corrector branch pins one concrete transformation.
         *
         * @param list<string> $methods
         */
        private function typesetContent(string $content, array $methods): string
        {
            $GLOBALS['__aiya_test_options']['backend']['typography_methods'] = $methods;
            $this->seedPost(31, '', $content);
            $this->module()->onChineseTypesetting(31);

            $updates = $this->updates();
            $payload = $updates === [] ? null : $updates[count($updates) - 1];
            self::assertIsArray($payload, 'the typesetting pass always writes the post back');

            return (string) $payload['post_content'];
        }

        // ---- Refresh date --------------------------------------------------

        public function testRefreshDateWritesTheCurrentLocalAndGmtStamp(): void
        {
            $this->seedPost(9, '旧标题', '内容');
            $before = current_time('mysql');

            $this->module()->onRefreshDate(9);

            $after = current_time('mysql');
            $payload = $this->updates()[0] ?? null;
            self::assertIsArray($payload);
            self::assertSame(9, $payload['ID']);
            self::assertGreaterThanOrEqual($before, $payload['post_date'], 'the local stamp must fall inside the call window');
            self::assertLessThanOrEqual($after, $payload['post_date']);
            self::assertSame($payload['post_date'], $payload['post_date_gmt'], 'UTC pinned: the GMT twin equals the local stamp');
        }

        public function testRefreshDateIgnoresReentrantCallsFromInsideTheWrite(): void
        {
            // wp_update_post fires save_post in core; the double replays that
            // window by re-invoking the handler mid-write — the recursion
            // must die on the busy gate and land exactly one row.
            $module = $this->module();
            $this->seedPost(9, '旧标题', '内容');
            $GLOBALS['__aiya_test_on_post_update'] = static function (int $postId) use ($module): void {
                $module->onRefreshDate($postId);
            };

            $module->onRefreshDate(9);

            self::assertCount(1, $this->updates(), 'the re-entrant call performs no second write');
        }

        public function testRefreshDateResumesWritingOnceTheBusyCallReturns(): void
        {
            $module = $this->module();
            $this->seedPost(9, '旧标题', '内容');
            $GLOBALS['__aiya_test_on_post_update'] = static function (int $postId) use ($module): void {
                $module->onRefreshDate($postId);
            };
            $module->onRefreshDate(9);

            $GLOBALS['__aiya_test_on_post_update'] = null;
            $module->onRefreshDate(9);

            self::assertCount(2, $this->updates(), 'busy resets in finally: the next one-shot writes again');
        }

        // ---- Match tags ----------------------------------------------------

        public function testMatchTagsSkipsTypesWithoutThePostTagVocabulary(): void
        {
            $GLOBALS['__aiya_test_tag_query'] = [new WP_Term((object) ['term_id' => 1, 'name' => 'Linux'])];
            $this->seedPost(5, '', '一篇提到 Linux 的页面', 'page');

            $this->module()->onMatchTags(5);

            self::assertSame([], $GLOBALS['__aiya_test_tag_assignments'], 'pages collect nothing: no post_tag lexicon, no write');
        }

        public function testMatchTagsSkipsTypesCarryingOnlyTheirOwnTagTaxonomy(): void
        {
            // resource 贴带自己的标签词法——挂到 post_tag 只会沉淀不可见的
            // 关联，处理器直接跳过。
            $GLOBALS['__aiya_test_type_taxonomies']['resource']['resource_tag'] = true;
            $GLOBALS['__aiya_test_tag_query'] = [new WP_Term((object) ['term_id' => 1, 'name' => 'Linux'])];
            $this->seedPost(6, '', '一篇提到 Linux 的资源贴', 'resource');

            $this->module()->onMatchTags(6);

            self::assertSame([], $GLOBALS['__aiya_test_tag_assignments']);
        }

        public function testMatchTagsSkipsMissingPosts(): void
        {
            $this->module()->onMatchTags(404);

            self::assertSame([], $GLOBALS['__aiya_test_tag_assignments']);
        }

        public function testMatchTagsReadsNamesFromTermObjectsAndSkipsJunkEntries(): void
        {
            $GLOBALS['__aiya_test_type_taxonomies']['post']['post_tag'] = true;
            // A real tag query can hand back non-object rows; only WP_Term
            // names may feed the matcher.
            $GLOBALS['__aiya_test_tag_query'] = [
                new WP_Term((object) ['term_id' => 1, 'name' => 'Linux']),
                99,
                new WP_Term((object) ['term_id' => 2, 'name' => 'Docker']),
            ];
            $this->seedPost(7, '', '一篇 Docker 实战笔记');

            $this->module()->onMatchTags(7);

            self::assertSame(['tags' => ['Docker'], 'append' => true], $GLOBALS['__aiya_test_tag_assignments'][7] ?? []);
        }

        public function testMatchTagsSkipsAssignmentWhenNothingMatches(): void
        {
            $GLOBALS['__aiya_test_type_taxonomies']['post']['post_tag'] = true;
            $GLOBALS['__aiya_test_tag_query'] = [
                new WP_Term((object) ['term_id' => 1, 'name' => 'Linux']),
                new WP_Term((object) ['term_id' => 2, 'name' => 'Docker']),
            ];
            $this->seedPost(7, '', '本文不提任何词库里的名字');

            $this->module()->onMatchTags(7);

            self::assertSame([], $GLOBALS['__aiya_test_tag_assignments']);
        }

        public function testMatchTagsToleratesANonArrayTagQuery(): void
        {
            $GLOBALS['__aiya_test_type_taxonomies']['post']['post_tag'] = true;
            $GLOBALS['__aiya_test_tag_query'] = false;
            $this->seedPost(7, '', '一篇 Docker 实战笔记');

            $this->module()->onMatchTags(7);

            self::assertSame([], $GLOBALS['__aiya_test_tag_assignments']);
        }

        // ---- Cleanup HTML ----------------------------------------------------

        public function testCleanupNormalizesLegacyMarkupAndWritesBack(): void
        {
            // div 段落归一为 p 且不携带旧属性，span 只剥壳留内容，全角空格
            // 与 &nbsp; 一律物理删除。
            $this->seedPost(11, '标题', '<div class="legacy">你好　世界<span style="color:red">重点</span>&nbsp;</div>');

            $this->module()->onCleanupHtml(11);

            $payload = $this->updates()[0] ?? null;
            self::assertIsArray($payload);
            self::assertSame(11, $payload['ID']);
            self::assertSame('<p>你好世界重点</p>', $payload['post_content']);
        }

        public function testCleanupMergesOverlappingEmphasisTags(): void
        {
            // 重叠 strong 合并、相邻 b 接续、空 b 直接消失——旧编辑器的
            // 三种脏形态。
            $this->seedPost(12, '', '<strong><strong>重点</strong></strong>普通<b>一</b><b>二</b><b></b>');

            $this->module()->onCleanupHtml(12);

            $payload = $this->updates()[0] ?? null;
            self::assertIsArray($payload);
            self::assertSame('<strong>重点</strong>普通<b>一二</b>', $payload['post_content']);
        }

        public function testCleanupLeavesCleanContentUnwritten(): void
        {
            $this->seedPost(13, '', '<p>已经是干净的段落</p>');

            $this->module()->onCleanupHtml(13);

            self::assertSame([], $this->updates(), 'unchanged content costs no write');
        }

        public function testCleanupSkipsTheWriteWhenCleaningLeavesNothing(): void
        {
            // 内容只剩全角空格：清理结果为空串时绝不落库清空正文。
            $this->seedPost(14, '', '　　');

            $this->module()->onCleanupHtml(14);

            self::assertSame([], $this->updates());
        }

        public function testCleanupIgnoresReentrantCallsFromInsideTheWrite(): void
        {
            $module = $this->module();
            $this->seedPost(15, '', '<div>x</div>');
            $GLOBALS['__aiya_test_on_post_update'] = static function (int $postId) use ($module): void {
                $module->onCleanupHtml($postId);
            };

            $module->onCleanupHtml(15);

            self::assertCount(1, $this->updates());
        }

        // ---- Chinese typesetting: one corrector per case ---------------------

        public function testInsertSpaceSeparatesCjkFromLatinAndDigits(): void
        {
            // 中西之间加空格是双向的：文a 与 3中 各补一侧，夹在中间的
            // 数字两侧都补。
            self::assertSame('中文 abc123 中文', $this->typesetContent('中文abc123中文', ['insertSpace']));
            self::assertSame('版本 2 发布', $this->typesetContent('版本2发布', ['insertSpace']));
        }

        public function testInsertSpaceSpacesQuotesAroundCjk(): void
        {
            // 引号加空格后 fix_quote 再收紧内侧——成品外侧悬空、内侧紧贴。
            self::assertSame('中文 "引号"', $this->typesetContent('中文"引号"', ['insertSpace']));
        }

        public function testInsertSpaceSpacesHalfwidthBracketsBetweenCjk(): void
        {
            self::assertSame('查看 (详情) 这里', $this->typesetContent('查看(详情)这里', ['insertSpace']));
        }

        public function testRemoveSpaceDropsSpacesAroundFullwidthPunctuation(): void
        {
            // 全角标点前后不放空格：两侧同时出现时一次清干净。
            self::assertSame('你好，世界。', $this->typesetContent('你好 ， 世界 。', ['removeSpace']));
        }

        public function testFull2HalfConvertsLettersDigitsSymbolsAndIdeographicSpace(): void
        {
            // 有限度全角转半角：字母数字与符号进半角，全角标点保留原样。
            self::assertSame('ABC123-50%', $this->typesetContent('ＡＢＣ１２３－５０％', ['full2Half']));
            self::assertSame('中文 混排', $this->typesetContent('中文　混排', ['full2Half']));
        }

        public function testFixPunctuationRepairsBrokenEllipses(): void
        {
            // 三个半角句点或两个以上省略号统一为中文省略号……
            self::assertSame('等等……', $this->typesetContent('等等...', ['fixPunctuation']));
            self::assertSame('怎么办……', $this->typesetContent('怎么办…………', ['fixPunctuation']));
        }

        public function testFixPunctuationUpgradesHalfwidthMarksAfterCjk(): void
        {
            // 中文（及）》”）之后的 !?.,():; 升级为全角中文标点。
            self::assertSame('你好！', $this->typesetContent('你好!', ['fixPunctuation']));
            self::assertSame('（完）。', $this->typesetContent('（完）.', ['fixPunctuation']));
            self::assertSame('他说，"hi"', $this->typesetContent('他说,"hi"', ['fixPunctuation']));
        }

        public function testFixPunctuationCollapsesRepeatedFullwidthMarks(): void
        {
            // 中文标点不重复：连续同符号只保留第一个。
            self::assertSame('什么？', $this->typesetContent('什么？？？', ['fixPunctuation']));
            self::assertSame('哇！', $this->typesetContent('哇！！！', ['fixPunctuation']));
        }

        public function testProperNounRestoresCanonicalCasing(): void
        {
            // 专有名词按词库恢复规范大小写（php→PHP、ios→iOS）。
            self::assertSame('我用PHP和iOS写站', $this->typesetContent('我用php和ios写站', ['properNoun']));
        }

        public function testRemoveClassStripsClassAttributes(): void
        {
            self::assertSame('<p>段落</p>', $this->typesetContent('<p class="wp-block">段落</p>', ['removeClass']));
        }

        public function testRemoveIdStripsIdAttributes(): void
        {
            self::assertSame('<div>内容</div>', $this->typesetContent('<div id="old">内容</div>', ['removeId']));
        }

        public function testRemoveStyleStripsStyleAttributes(): void
        {
            self::assertSame('<span>红</span>', $this->typesetContent('<span style="color:red;">红</span>', ['removeStyle']));
        }

        public function testRemoveEmptyParagraphRemovesBlankAndNestedBlankParagraphs(): void
        {
            // 空段落与纯空格段剥掉；全角空格段因旧版逐字节字符类只吃一个
            // 字节而幸免——移植原样的既有行为，按现状钉住。
            self::assertSame(
                '<p>正文</p><p>　</p>',
                $this->typesetContent('<p>正文</p><p></p><p> </p><p>　</p>', ['removeEmptyParagraph'])
            );
            self::assertSame('', $this->typesetContent('<p><p></p></p>', ['removeEmptyParagraph']));
        }

        public function testRemoveEmptyTagRemovesNestedEmptyTags(): void
        {
            // removeEmptyTag 覆盖一切空标签（correct() 内含 removeEmptyParagraph）。
            self::assertSame('留存文字', $this->typesetContent('<div><span></span></div>留存文字', ['removeEmptyTag']));
        }

        public function testRemoveIndentDropsLeadingParagraphIndents(): void
        {
            // 段首缩进（全角空格与 &nbsp; 变体）剥掉，正文位置不动。
            self::assertSame(
                '<p>首行缩进</p><p>第二段</p><p>正常段</p>',
                $this->typesetContent('<p>　　首行缩进</p><p>&nbsp;&nbsp;第二段</p><p>正常段</p>', ['removeIndent'])
            );
        }

        // ---- Chinese typesetting: method selection wiring --------------------

        public function testDefaultMethodSetAppliesWhenNoConfigurationExists(): void
        {
            // 未配置时回落旧版三件套，生效顺序 removeSpace → full2Half →
            // insertSpace：标题全角 A 转半角后与中文之间补出空格。
            $this->seedPost(21, 'Ａ中文', '说ＨＥＬＬＯ ，');

            $this->module()->onChineseTypesetting(21);

            $payload = $this->updates()[0] ?? null;
            self::assertIsArray($payload);
            self::assertSame(['ID' => 21, 'post_title' => 'A 中文', 'post_content' => '说 HELLO，'], $payload);
        }

        public function testConfiguredOrderDrivesTheCorrectorPipeline(): void
        {
            // 'ｐｈｐ' 区分顺序：先转半角则专有名词修出 PHP，先修名词则
            // 全角字形不在词库命中、转完只剩小写 php（insertSpace 由
            // correct() 强制垫底，不参与顺序博弈）。
            $GLOBALS['__aiya_test_options']['backend']['typography_methods'] = ['properNoun', 'full2Half'];
            $this->seedPost(22, 'ｐｈｐ', '');
            $this->module()->onChineseTypesetting(22);

            $GLOBALS['__aiya_test_options']['backend']['typography_methods'] = ['full2Half', 'properNoun'];
            $this->seedPost(23, 'ｐｈｐ', '');
            $this->module()->onChineseTypesetting(23);

            self::assertSame('php', $this->updates()[0]['post_title'], 'noun pass ran on the fullwidth glyphs and missed');
            self::assertSame('PHP', $this->updates()[1]['post_title'], 'conversion ran first, the noun pass sees halfwidth php');
        }

        public function testUnknownMethodNamesAreDroppedFromTheWhitelist(): void
        {
            // 白名单外交集为空项：未知名剔除后只剩 full2Half，不触发插空格。
            self::assertSame('A中文', $this->typesetContent('Ａ中文', ['full2Half', 'timeTravel']));
        }

        public function testAnEmptyEffectiveSetFallsBackToEveryCorrector(): void
        {
            // 配置全是未知名 → 交集为空 → correct() 反射出全部纠正器：
            // 省略号修复、全角转半角、专有名词、插空格四条链一起生效。
            self::assertSame('在用 PHP……', $this->typesetContent('在用ｐｈｐ...', ['warpDrive']));
        }

        public function testTypesettingIgnoresReentrantCallsFromInsideTheWrite(): void
        {
            $module = $this->module();
            $this->seedPost(24, 'Ａ中文', '正文');
            $GLOBALS['__aiya_test_on_post_update'] = static function (int $postId) use ($module): void {
                $module->onChineseTypesetting($postId);
            };

            $module->onChineseTypesetting(24);

            self::assertCount(1, $this->updates(), 'the save_post replay must not correct twice');
        }

        // ---- Registration wiring ----------------------------------------------

        public function testRegisterWiresTheOneShotActions(): void
        {
            $module = $this->module();
            $module->register();

            self::assertTrue(has_action('aiya_core_register', [$module, 'registerBoxAndSettings']));
            self::assertTrue(has_action('aiya_core_typography_refresh_date', [$module, 'onRefreshDate']));
            self::assertTrue(has_action('aiya_core_typography_match_tags', [$module, 'onMatchTags']));
            self::assertTrue(has_action('aiya_core_typography_cleanup_html', [$module, 'onCleanupHtml']));
            self::assertTrue(has_action('aiya_core_typography_chinese_typesetting', [$module, 'onChineseTypesetting']));
        }

        public function testTheTypographyBoxAndItsMethodSettingAreDeclared(): void
        {
            $metadata = new MetadataRegistry();
            $module = new TypographyModule($this->settings, $metadata);

            $module->registerBoxAndSettings();

            $boxes = $metadata->postBoxes();
            self::assertCount(1, $boxes);
            self::assertSame('typography', $boxes[0]->id());
            self::assertSame(['post', 'page', 'resource'], $boxes[0]->screens());
            self::assertSame('side', $boxes[0]->context());

            $actions = [];
            foreach ($boxes[0]->fields() as $field) {
                $actions[$field->id()] = $field->setting('action');
            }
            self::assertSame([
                'refresh_date' => 'aiya_core_typography_refresh_date',
                'match_tags' => 'aiya_core_typography_match_tags',
                'cleanup_html' => 'aiya_core_typography_cleanup_html',
                'chinese_typesetting' => 'aiya_core_typography_chinese_typesetting',
            ], $actions, 'each action checkbox fires exactly its one-shot hook');

            $methods = null;
            foreach ($this->settings->page('backend')?->fields() ?? [] as $field) {
                if ($field->id() === 'typography_methods') {
                    $methods = $field;
                }
            }
            self::assertNotNull($methods, 'the corrector multicheck lives on the shared Backend page');
            self::assertSame('multicheck', $methods->type());
            self::assertSame(['insertSpace', 'removeSpace', 'full2Half'], $methods->defaultValue());
            self::assertSame(
                ChineseTypesetting::METHODS,
                array_keys($methods->options()),
                'the settings surface must enumerate the whole corrector whitelist'
            );
        }
    }
}
