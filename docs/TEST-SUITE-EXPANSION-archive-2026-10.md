# aiya-core 测试件整备：计划与执行归档（2026-10-05）

> **状态：已完结。** 八批全部落地并通过对抗审查（质量抽样 / 基座保真 / 账实核对三路）。编年史见 `ROADMAP.md` 0.108.0 与 0.109.0 条目；提交锚 `c1afebe`（主体）/ `e2ed678`（对抗审查加固）/ `328c03e`（缺口修复）。本文归档计划原文与执行总结，不再随代码演进更新。终态：**1162 例 / 4323 断言，零失败零跳过，phpcs/phpstan 双 0**；地板 1080/3400。

---

# 计划原文（2026-10-05 站长批准，分 8 批）

**改动面声明**：仅 `aiya-core` 的 `tests/`、`scripts/test-native.sh`、`docs/ROADMAP.md`（每批追加条目），收尾同步根 `AGENTS.md` 现状摘要与 `.agents/skills/aiya-verification/SKILL.md` 的套件数字。不触碰生产 `src/`（批 1 发现真 bug 除外，届时上报站长再动）；不动契约 DTO → 全程无快照流程。共享资源只跑测试不写库。

**全局纪律**：每批门禁 = 原生盘 phpunit 全绿 + phpstan 0 + phpcs 0；新测试先验证「能红」再信绿（假绿 #5）；垫片改动必须镜像生产语义（R7 教训）；删除/合并前逐断言比对，不以子代理结论直接动手。

---

## 批 1 — Mail 接线缺口修复（~1h，先行）

1. `MailModuleTest::testUnhooksTheResetCompletedAdminNotice` 现为空洞断言（垫片注册表从无人注册过 `wp_password_change_notification`，`has_action` 恒 false）。修法：setUp 里先 `add_action('after_password_reset', 'wp_password_change_notification')` 模拟 core default-filters，再 register() 后断言已拆除——3 行改动把空洞例变成真回归。
2. 新增 `register()` 接线端到端例：register() → `wp_mail()` 直调 → 断言 `__aiya_test_mails` 里是品牌壳（证明 `add_filter('wp_mail', [$shell,'apply'], 999)` 存在；CoreMailRewrites/MembershipReceipt 挂线同理补 sanity）；double-boot 例折入本例。
3. MailShellTest 两个 `wp_mail` 端到端例是 0.105 R7 有意加的，**保留不动**，ROADMAP 条目记录此判定。
4. 验证能红：临时注释 `add_filter('wp_mail',…)` 行 → 新例必须红 → 恢复。

## 批 2 — 修剪批（删真冗余 + 同形合并，684 → 约 670）

逐项先做断言级 diff 再动手：

| 项 | 处置 |
|---|---|
| FileServeTest::testTheOpenListAdaptersMap… | 保留 configured()/fields() 断言，删 entries() 映射段（权威在 OpenListPackageTest） |
| FileServeTest::testTheGoFileGroup… | 保留 identity/configured/api-URL，删 url/modified 映射重复 |
| FileServeTest::testTheGoFilePremiumGate… vs GofilePackageTest 同分支 | diff 后择一保留或双双瘦身为单断言 |
| FileServeTest::testAFailedCallReachesTheFailureSeam | **保留**（failure seam 独有覆盖） |
| EpayGatewayTest 签名往返两例 | 与 EpayClientTest 重复的往返断言瘦身，保留回调归一化/checkout id 一致性等独有回归 |
| AfdianOrderUrlTest URL 深层断言 | 与 AfdianGatewayTest 重复择一；限流窗与停售门保留 |
| AfdianClientTest queryOrders 两例 | 合一 |
| AfdianActivatorTest tier 删除 guard 两例 | data provider |
| FileServeDownloadTest gated/password 两例 | data provider |
| OpenList/Gofile 包内小对 | 顺手并入 provider，不强求 |

收尾：`test-native.sh` 地板抬到 实际数−30/−100（沿 0.105 先例留正常涨落余量）；同步 AGENTS.md 与 verification skill 里的套件数字。

## 批 3 — 组织重构批（用例总数不变）

1. **拆 AfdianActivatorTest god-file**：OrderService 三例 → `OrderServiceTest`；MembershipService fold 一例 → `MembershipServiceTest`；SponsorshipModule guard → 就近 `SponsorshipSettingsTest` 或新 `SponsorshipModuleTest`；Activator 本体用例留原文件。
2. **NotificationCommentGuardTest 尾部两例**（thread 文案对，偏题）迁 `DiscussionContentTest`。
3. **小文件并入域邻文件**（只并有自然归属的，不硬凑）：SmiliesContractTest→SmiliesRegistryTest；PostSummaryContractTest→PostCardTest；DiscussionContractTest→DiscussionContentTest；ProfileContractTest+UserProfileContractTest+AuthSessionContractTest→新 `IdentityContractTest`；SiteContractTest→ContentBlocksTest；NotificationContractTest→NotificationLinkerTest；ThreadWorkflowTest→DiscussionBumpTest；TermTaxonomyMoverTest→TermListFilterTest；RoleLevelTest→SecurityModuleTest；FieldRendererTest→FieldTest；PostMetaStoreTest→MetadataStorageTest；ImagineAwareTest→ThumbnailGeneratorTest；FrontendImageDefaultsTest+FrontendLanguageTest→`FrontendModuleTest`；Shortcodes/SearchReplace/ServerStatus/CronManagement 四个 DevTools Page 测试→`DevToolsPagesTest`。
   **例外**：`MigrationChainTest` 不动——AGENTS.md 迁移协议点名此文件；无自然归属的小文件（如 MailReceiptTest）保持原样。
4. 地板不变；ROADMAP 条目附文件数前后对照（预期 −14 个文件左右）。

## 批 4 — REST 基建小件补测（+40~50 例）

纯逻辑、无需 dispatch 基建：`CorsHeaders`（来源白名单→发头/移除 core 宽松默认，安全件）、`RestGuard`（401/429 形状）、`Envelope`（信封与 pagination meta 组装）、`HttpCache`（ETag/304 分层策略）、`DateLabels`/`WireDates`（R-01 P0 修复件，需补 wp_date 垫片）、`VisitorFingerprint`、`RandomToken`、`MimeType`（finfo 回退链）、`FileIcons`。

## 批 5 — 内容域纯逻辑补测（+50~60 例）

`TypographyModule`（中文排版四动作，214 行纯字符串逻辑，最高性价比）、`ContentTypeRegistry`+`PostTypeDefinition`+`TaxonomyDefinition`（声明值对象与守卫）、`PostBox`+`TermBox`（fromArray 校验）、`PostTypeSwitcher`、`MarkedQuery`（热文/相关文基座参数）。XDE_code 为冻结算法走既有间接覆盖，不单独补。

## 批 6 — 钱路补测（+50~70 例）

`StatsRecorder`/`StatsQuery`（月度原子累加、MAU、到期水位清扫——wpdb 垫片排序模拟沿 S10 先例，新测试先证明能红）、`GatewayController`（Epay 回调验签→结算、Afdian webhook 复读——FakeRestRequest 先例）、`AccountService`（邮箱变更重认证闸、改密先吊销令牌的顺序不变量）、`CreditSettings`/`StatsSettings`/`WebhookLogger`/`WireTransport` 小件。

## 批 7 — REST 控制器全量（+120~150 例，可拆 7a/7b）

1. 先建基建并单独验证：泛化 AfdianOrderUrlTest 的 FakeRestRequest 反射式为 `tests/Fixture/` 共享基座（控制器实例化 + 请求构造 + 权限门断言；不引入完整 REST server dispatch，重而值低）。
2. 7a：`AuthController`(324)+`UserController`(414)+`CommentsController`(276)；7b：`DiscussionController`(425)+`CreditController`+`CounterController`+`IntegrationsController`+`NotificationController`+`UploadsController`+`FileServeController`+`SmiliesController`。
3. 断言口径：断言路由/权限/信封承诺与状态码，**不复刻完整载荷形状**（形状权威仍是契约快照，避免双头事实）。

## 批 8 — 收尾

地板终值（预计 ~950 tests/~2800 assertions 一线）、AGENTS.md 与 verification skill 数字终同步、全量六门禁复跑、ROADMAP 收尾条目（含批 3 文件数对照、批 7 基建说明）。

---

**不做清单（记录拍板）**：21 个 Admin 页面类不补测（Ui 原语已有 UiTest 执法，页面属组合层，回归风险低）；Plugin.php/RestController 等组合根不补（装配正确性由激活即用兜底）；DTO 形状类不补（快照+zod 执法）。

**版本节奏（可调）**：批 1+2 → 0.108.0；批 3 → 0.109.0；批 4+5 → 0.110.0；批 6 → 0.111.0；批 7 → 0.112.0（若拆 7a/7b 则 0.112/0.113）；批 8 不占版本号。开工每批先 `git status` 对齐在途改动。

---

# 执行总结

## 逐批对照（计划 → 实际）

| 批 | 计划 | 实际 | 偏差 |
|---|---|---|---|
| 1 Mail 接线 | 空洞例修复 + register() 端到端 | 照计划落地，double-boot 例折入接线例 | 无 |
| 2 修剪 | 24 例候选删/并（预期 684→~670） | 逐断言 diff 后**核销 23、仅 queryOrders 双例合一**（684→683） | 大幅低于预期——「跨层同值断言≠重复」，转换方向与主张归属必须先验（教训入档） |
| 3 归位 | 拆 god-file + 18 小文件归位 | 照计划；两处改判：thread 文案对迁 NotificationPublishLegsTest（原定 DiscussionContentTest）、RoleLevelTest 保留（原定 Security 邻位系域归属错误）；MigrationChainTest 按协议保留 | 无实质 |
| 4 REST 基建小件 | 10 类 +40~50 例 | 10/10 落地，+71 例；顺带补 wp_rand 等四垫片 | 无 |
| 5 内容域 | Typography + 声明件七类 +50~60 例 | 8/8 落地，+95 例 | 无 |
| 6 钱路 | 8 类 +50~70 例 | 8/8 落地，+114 例；**首次代理越界改 7 个 src 文件被处决并全量还原**，此后简报固化「上报不代改」红线 | 无 |
| 7 控制器全量 | 11 控制器 +120~150 例 | 11/11 落地，+158 例（7a 68 / 7b 47 / 7c 43） | 无 |
| 8 收尾 | 地板终值 + 文档同步 + 提交 | 地板 1080/3400；AGENTS/skill/ROADMAP 同步；c1afebe 统一提交 | 版本节奏并批：批 1-3 → 0.108.0、批 4-8 → 0.109.0 |

净变化：**683→1162 例 / 2062→4323 断言；测试文件 105（git 台账 105→106→131）**。

## 新增基座

- `tests/Fixture/RestDoubles.php`：REST 三双身共享（WP_REST_Server 五方法常量按核心真值——PHP 8.5 已移除类常量回退全局常量，缺常量即 Error）；扩展点=就地扩类或文件内子类（GatewayCallbackRequest 先例），禁竞争性本地双身。
- `tests/Fixture/HttpDoubles.php`：wp_remote 四函数（记录 + responder 闭包 + 暂存应答三级解析）。
- `tests/Fixture/OperationsTestWpdb.php`：Operations 域 wpdb 双身 + `Domain\Operations` 命名空间定钟（bootstrap `current_time` 修复后保留为定钟机制）。
- bootstrap 垫片补齐/加固：wp_rand（含 absint）、unstick_post（void）、get_post_type_object、wp_update_post（全字融合 + 三参）、current_time（认 $type，修复 Checkin 自洽假绿类）、get_userdata（回 WP_User，解开 Auth/User 会话面）、WP_User::exists()。
- `scripts/test-native.sh` 双形态：无参全量（地板守卫）/ 带参定点（迭代用）；依据写入 AGENTS.md 与 verification skill。脚本本体仍不入库（0.105 拍板）。

## 对抗审查与处置（2026-10-05）

三路独立审查：R1 测试质量抽样（配额两次截杀，4 处变异确认后由会话亲自补齐 Envelope 钳制与 StatsQuery mrr 恒等式两处独立红证）、R2 基座保真、R3 账实核对。**修复项**（`e2ed678`/`328c03e`）：current_time 认格式（消灭 Checkin 自洽假绿类）、get_userdata 回 WP_User、11 个本地 WP_REST_Server 变体统一核心真值（拆除 B 变体 EDITABLE='PUT' 错值钉死与 C 变体跨文件 fatal）、wp_update_post 全字融合、wp_rand absint、unstick_post void、插件头版本、ROADMAP 文件账 git 真值化、FakeRestRequest 四方法提升基类、WP_User::exists、死守卫清理。**记账未决**：wp_get_current_user 三文件双体分叉（休眠）、is_sticky 不桥接 options（文档化简化）、PicBedStore 上传 happy path 单测不可达（留集成层）、quiet() 守卫分支垫片不可达、StatsQuery::cash() 疑似死代码分支。

## 站长拍板记录

- `(string)+absint()` 尽力取整为 Domain 十处同型的界内惯例；`retentionDays` 负数读绝对值维持现状，防御归界面层。
- flaky 首例（一次未复现）判 docker 抖动不立案，复见再查。
