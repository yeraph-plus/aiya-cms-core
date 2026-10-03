# AIYA Core 1.0 发布前全量代码审查台账（2026-10-03）

> 本文是 1.0 发布门审查的**完整登记与复核台账**：六路审查的发现全量在案，每条带编号、证据与状态位。处置按第 2 节批次计划推进，每批完工即回写本文状态位，批次叙事仍按惯例进 `ROADMAP.md`——本文只管发现与状态，不替代 ROADMAP。
>
> 复核方式：每条带「复核」行（grep/读码/跑测的锚点），回头逐条验证是否已按方向修复；批次完成后重跑第 5 节工具链基线，数字不得劣化。

## 0. 审查元信息

- **审查时间**：2026-10-03
- **审查基线**：审查启动时 HEAD `ffc4c2b`（0.101.0）；裁定核对时 HEAD `d0f170d`（0.101.1，邮件域批次进行中、工作树有未提交改动）。文内行号以审查时点为准，批次推进后会漂移，复核以符号定位为准。
- **方法**：六路并行审查（R 冗余与垫片 / L 契约与层错位 / C 缓存 / S SQL 数据层 / H 代码卫生 / G 发布门），WP 原生函数替代建议均到 `wordpress-source/` 核实；主线对三条最重发现做过抽查实锤（`date_i18n` 用法、`ERROR_TRANSIENT` 键形状、缩略图缓存键）。
- **严重度**：P0 = 1.0 前必须修（正确性 / 安全 / 回归防线失效）；P1 = 应修；P2 = 可选清理或登记性发现。
- **状态位**：☐ 待处理 · ✅ 已修 · ⛔ 驳回（附理由）· ⏭️ 移期（附目标批次/1.0 后）。

## 1. 计分板

| 维度 | P0 | P1 | P2 | 小计 |
|---|---|---|---|---|
| R 冗余与重复实现 | 1 | 5 | 9 | 15 |
| L 契约与层错位 | 0 | 3 | 7 | 10 |
| C 缓存 | 1 | 1 | 3 | 5 |
| S SQL 与数据层 | 0 | 4 | 8 | 12 |
| H 代码卫生 | 1 | 2 | 5 | 8 |
| G 发布门 | 0 | 2 | 6 | 8 |
| **合计** | **3** | **17** | **38** | **58** |

**总评**：无注入面、无契约破坏；卸载覆盖、REST 权限、迁移链、契约快照、构建门禁等硬要素全绿。挡在 1.0 前的是 3 条 P0（时间处理正确性、缩略图缓存陈旧、测试防线失效）与一批「1.0 冻结是最后窗口」的死索引/死列/复制收敛。

## 2. 批次计划

### 2.1 总览

| 批次 | 主题 | 覆盖发现 | 验证门槛 | 状态 |
|---|---|---|---|---|
| B1 | P0 修正与测试防线 | R-01、C-02、H-01、H-02 | 原生盘 phpunit 全绿 + 手工时间/换图抽查 | ✅ 2026-10-03 |
| B2 | SQL 收尾窗（1.0 冻结前唯一机会） | S-01～S-08、H-07 | MigrationChainTest 同步 + 旧库幂等演练 | ✅ 0.102.0（S-01..04/H-07）+ 0.102.1 后批（S-05..08） |
| B3 | REST 面收敛 | R-02、R-03、R-07、R-10、C-04、C-05、L-08 | 快照零 diff + 全路由冒烟 | ☐ |
| B4 | 媒体与杂项收敛 | R-04、R-05、R-06、R-08、R-12、R-13、R-14、R-15、L-07、L-09 | 上传/封面/文件服务手工回归 | ☐ |
| B5 | 卫生与测试补强 | H-03、H-04、H-05、H-06 | 新测试入套件、死代码零引用复核 | ☐ |
| B6 | 架构裁决、文档与发布 | L-01、L-04、L-05、L-06、L-10、G-01～G-05 | 快照比对 + 发布动作清单全过 | ☐ |

**统一验证基线**（每批收尾都要过）：容器内 Linux 原生盘跑 phpunit 全绿（基准 ≥625 tests，B5 后增长）；`phpstan analyse --memory-limit=2G` 0 errors（level 8）；phpcs 0 errors。每批按惯例盖版本戳并在 ROADMAP 追加条目、回写本台账状态位。

**协调注意**：当前工作树有邮件域在途改动（`MailShell.php`/`MailTemplate.php`/`tests/bootstrap.php` 已动）。H-02 也要动 `tests/bootstrap.php`——B1 落地时先与邮件域批次对齐再改，避免同文件冲突。

### 2.2 批次详情

**B1 — P0 修正与测试防线**
- R-01：7 处 `date_i18n(真 epoch)` 全改 `wp_date()`，admin 侧日期标签收敛为一个共享助手（`CronsPage.php:311` 已是正确写法，照抄）。
- C-02：`CardThumbnailService` 派生图缓存键折叠源路径+质量（对齐 `ThumbnailService::cacheKey` 先例），替换附件/改质量即自然重键。
- H-01：建立可信测试入口——CI 或本地脚本把插件树复制到容器本地盘再跑 phpunit，断言测试数 ≈625 作为基准防线。
- H-02：`AfdianOrderUrlTest` 的 fixture 归位（tests/Fixture/ 显式加载）、`esc_html__`/`esc_attr__` 提进 bootstrap.php（与邮件域在途改动协调）。
- 验证：UTC+8 站点后台五个列表页时间人工核对；媒体库替换同一附件后缩略图重生成；`--filter` 单文件可独立运行。

**B2 — SQL 收尾窗**（迁移链压平为 CREATE-only 后 dbDelta 只加不减——加法项由 Runner 在下次插件版本变动时自动补（stored 戳=插件版本，<1.0.0 即全链重跑），减法项必须显式 DROP；照 NotificationService 安装器内 user_id 索引清理的幂等先例（SHOW INDEX 探测 + DROP），把四个减法项的 DROP 内嵌进 1.0.0 安装器：开发库（stored=0.101.1、死对象在库）下次版本变动自愈，线上库（stored ≤0.99）1.0.0 首请求拿到的即修正后 DDL、DROP 全为 no-op——无需手工 SQL 对账）
- S-01：删 `aiya_notifications` 死索引 `actor_id`、`object_ref`（升级库仿 user_id 索引先例补幂等 DROP）。
- S-02：`aiya_discussions` 加 `(status, created_at)`、删冗余 `KEY status`。
- S-03 / S-04：摘死列 `aiya_discussion_replies.updated_at`、`aiya_stats_active.first_seen`。
- S-05：purge 的 termmeta `thumbnail_id` 删除加契约分类白名单 JOIN。
- S-06：5 处 `SHOW TABLES LIKE` 补 `esc_like`。
- S-07 / S-08：SearchReplace 按批执行、线程删除包事务。
- H-07：phpcs 1 error + 2 warnings 全部豁免注释归零。
- 验证：`MigrationChainTest` 同步；拿旧 `schema_version` 库演练首请求幂等对账。

**SQL 压平缺口的最后一项**（轻社区点赞表）不走 B2，单列批次 DL：2026-10-03 站长拍板重启，独立内容 CRUD 不触碰文章数据域（设计稿已随实施退役，语义见 ROADMAP 0.102.0 条目；已落地 ✅）。

**B3 — REST 面收敛**
- R-02：`Envelope` 加 `list($data, ?Pagination)` 静态工厂，替换 12 处手工 meta 装配。
- R-03：RestGuard 统一 `requireLoggedIn()`（8 份）与 429 文案（27 处）。
- R-07：`TokenAuthentication` 改读 `aiya_core_firstparty_rest_namespaces` 宣告清单，消除硬编码第二真相源。
- R-10：删除与 REST args schema 校验重复的手工分页夹取（死代码）。
- C-04：讨论列表 present 前 `cache_users()` 批量预热。
- C-05：通知 feed 按 object_type 分组 IN 预取（threads 服务补 `byIds()`）。
- L-08：discussion update 响应不再静默截断 replies（不回带或 meta 带总数，二选一）。
- 验证：`wp aiya contracts snapshot` 与 front-station 基线零 diff；60 条路由冒烟。

**B4 — 媒体与杂项收敛**
- R-04：图床上传管线下沉 `Domain/Media` 单实现（PicBedPage 与 UploadsController 共用），防撞命名改 `wp_unique_filename()`。
- R-05：metabox 保存错误暂存统一为 FileServeMetabox 的 per-user 键形状。
- R-06：Presenter 层 `formatDate` 四份收敛为共享助手。
- R-08：封面文件名配方与校验正则收敛进 `MediaPaths`。
- R-12：FileServe 两个后端 transport 闭包收敛为共享工厂。
- R-13：Sponsorship 随机串配方统一 `randomSuffix()`。
- R-14：`aiya_core_content` 组 memo 键形状/组名收敛到一处常量。
- R-15：三个 bulk action 的计数 notice 渲染收敛。
- L-07：meta 键/类名引用改属主常量（`CounterService::RATING_KEY` 等、`SmiliesRenderer::IMG_CLASS`）。
- L-09：广告剥离下沉为 `SiteBlocks::withoutAds()` 值对象方法。
- 验证：上传（后台+REST）、封面生成/刷新、fileserve 交付、三个 bulk action 手工回归。

**B5 — 卫生与测试补强**
- H-03：删 7 方法 + 1 常量（清单见发现条目）。
- H-04：补 `RedeemCodeServiceTest`（核销幂等/过期/回滚）与 `LedgerService` FIFO 过期边界用例。
- H-05：`AfdianGateway.php:76` 硬编码中文包 `__()` 或注释说明刻意为之。
- H-06：error_log 9 处统一 WP_DEBUG 门 + phpcs:ignore 注释。
- 验证：新测试入套件后基准数字更新；grep 死代码名零命中。

**B6 — 架构裁决、文档与发布**
- L-01：裁决 Domain 下 14 个 `*Page` 归属（迁 `src/Admin` 或 ARCHITECTURE 明文豁免），二选一落文档。
- L-02 / L-03：**裁决登记但不实施**（1.0 后批次）——visitorHash 破边、AccountService 归宿，裁决结论写进 ARCHITECTURE。
- L-04 / L-05 / L-06：DTO 文档修正（DiscussionDetail 双字段收敛登记、Seo::noindex 语义改写、UserProfile.username docblock 矛盾消除）。
- L-10：ARCHITECTURE.md 零路由节两处失真描述更新（PostSummary.url 已删、mentions 已上线）。
- G-01：ROADMAP 补 0.101.x 收尾与 1.0.0 批次；AGENTS.md 现状摘要同步。
- G-02：拍板 `Requires at least`（建议 7.0，与 WP 7.1 基准一致）。
- G-03：composer.json php 下限对齐 `>=8.5`。
- G-04 / G-05：readme.txt 有意缺省与否在 README.md 记一句；发布动作清单执行（见附录 A）。
- 验证：快照零 diff；tag v1.0.0 走 release.yml 三方门；线上 PUC 升级实测。

### 2.3 移期与登记不动清单（不占 1.0 批次）

| 编号 | 处置 | 理由 |
|---|---|---|
| R-09 / R-11 | ⏭️ 1.0 后 | 骨架复制属抽象度权衡，收益低 |
| L-02 / L-03 | ⏭️ 1.0 后（B6 落裁决） | 改动面大，非发布门阻塞 |
| C-03 | 登记不动 | 已文档化的 TTL 契约，仅未来加装对象缓存时补 flush 钩子 |
| S-09 / S-10 / S-11 / S-12 | 登记不动 | 已知取舍或得不偿失，理由见各条 |
| G-06 / G-07 / G-08 | 记录在案 | 联测项/合理选择/设计意图 |
| H-08 | 提示 | 双文案符合 WP 惯例，翻译时两串都要维护 |

## 3. 发现登记

格式：`编号 [严重度] 标题 状态位`，下含 位置 / 证据 / 方向 / 复核。

### 3.1 R 冗余与重复实现

**R-01 [P0] `date_i18n` 传真 Unix 时间戳，站点时间显示早 8 小时** ✅ B1
- 位置：`src/Admin/DiscussionModerationPage.php:560`、`src/Domain/Credit/CreditsPage.php:496`、`src/Domain/Notification/NotificationPage.php:252`、`src/Domain/Sponsorship/PaymentsAuditPage.php:340`、`src/Domain/Notification/NotificationActions.php:531,580`（五份同体复制 + 两处邮件）。
- 证据：`get_date_from_gmt(...,'U')` 后把真 epoch 传 `date_i18n`；WP 对数字时间戳走 legacy 分支（`wordpress-source/wp-includes/functions.php:198-207`）：先 gmdate 取 UTC 墙钟再当站点时区渲染。UTC+8 站点后台五个列表页与会员到期邮件日期全错。
- 方向：统一改 `wp_date()`（同 functions.php:243，明确接受真 epoch；`CronsPage.php:311-314` 已是正确写法），admin 侧收敛为共享助手。
- 复核：`grep -rn "date_i18n(" src/` → 0 命中；后台列表时间人工核对。

**R-02 [P1] REST 列表信封 meta 手工装配 12 处，绕过 Envelope** ✅ B3
- 位置：`src/Api/Rest/DiscussionController.php:171-178,138-144,232-239`、`ContentController.php:199-208,237-247,334,358-366`、`UserController.php:182,241`、`CommentsController.php:138`、`NotificationController.php:84-91`、`CreditController.php:93`。
- 证据：各端点重复拼 `'meta' => ['apiVersion'=>..., 'requestId'=>..., 'pagination'=>...]`；`Envelope.php:61-63` 因「controllers may build the full meta themselves」放行。
- 方向：`Envelope::list($data, ?Pagination)` 静态工厂，控制器一行调用。信封形状在 v1 冻结下最不该有漂移空间。
- 复核：`grep -rn "requestId" src/Api/Rest/` → 只剩 Envelope 自身。

**R-03 [P1] `requireLoggedIn()` 复制 8 份 + 429 文案 27 处已漂移** ✅ B3
- 位置：`AuthController.php:342`、`CreditController.php:181`、`DiscussionController.php:377`、`FileServeController.php:99`、`IntegrationsController.php:183`、`SponsorshipController.php:281`、`UserController.php:449`、`CounterController.php:62`（变体）。
- 证据：同体 `is_user_logged_in()?:WP_Error` 八份；`Too many requests` 27 处两种措辞并存（CounterController 的文案与未登录文案均不同）。
- 方向：`Api/Rest` 下 RestGuard 统一 `loggedIn()`/`rateLimited()`。
- 复核：`grep -rn "Too many requests" src/` → 单一来源。

**R-04 [P1] 图床上传管线整段复制两份，REST 侧落错层** ☐
- 位置：`src/Domain/Media/PicBedPage.php:196-231` 与 `src/Api/Rest/UploadsController.php:91-110`。
- 证据：大小检查→MimeType::detect→相同文件名配方（`wp_date('d').'-'.time().'-'.wp_generate_password(8,false)`)→move→processUpload→URL/相对路径→getimagesize，逐行同构；仅错误载体不同。
- 方向：下沉 `Domain/Media/PicBedStore` 单实现；防撞命名改 `wp_unique_filename()`（`wordpress-source/wp-includes/functions.php:2589` 有据）。
- 复核：两处调用同一类；`wp_generate_password(8,false)` 配方零命中。

**R-05 [P1] metabox 保存错误暂存双实现，一处缺 user 隔离** ☐
- 位置：`src/Admin/MetaboxAdmin.php:440-460`（全局键 `aiya_core_meta_save_errors`，:46 定义）与 `src/Admin/FileServeMetabox.php:199-225`（per-user 键）。
- 证据：管理员 A 保存报错，2 分钟窗口内管理员 B 打开任意编辑屏会看到 A 的错误（缓存路审查亦报，并入本条）。
- 方向：统一 per-user 键（保留 FileServeMetabox 形状），单一实现。
- 复核：`grep -rn "ERROR_TRANSIENT" src/` → 键全部带 `get_current_user_id()` 后缀。

**R-06 [P1] Presenter 层日期整形助手五份同体复制** ☐
- 位置：`DiscussionPresenter.php:249-252`、`CreditPresenter.php:50-53`、`NotificationPresenter.php:41-44`、`SponsorshipPresenter.php:90-93`、`PostPresenter.php:399-402`（WP_Post 变体）。
- 证据：逐字相同的 `formatDate(string $mysqlGmt): string`（get_date_from_gmt + wp_date('c')）。ARCHITECTURE 明文「转换日期的投影应有自己的家」。
- 方向：`Api/Presenter` 共享静态助手（与 R-01 的 admin 侧助手语义不同：ISO-8601 vs 本地化标签，各自独立）。
- 复核：`grep -c "private function formatDate" src/Api/Presenter/` → ≤1。

**R-07 [P2] Bearer 头解析两份 + firstparty 命名空间清单第二真相源** ✅ B3（过滤器时机不可行——determine_current_user 早于 rest_api_init，改为引用控制器常量）
- 位置：`src/Api/Rest/TokenAuthentication.php:76-91`（:71-73 硬编码三个命名空间）与 `src/Domain/Integrations/ServiceKey.php:49-52`（同一正则）。
- 证据：硬编码 `/aiya/core/v1/` 等与 `aiya_core_firstparty_rest_namespaces` 宣告机制平行——新命名空间宣告后 bearer 认证不会自动跟随。
- 方向：改读过滤器清单；ServiceKey 改收已解析 token。归 B3。
- 复核：TokenAuthentication 内无命名空间字面量。

**R-08 [P2] 封面文件名配方两份 + 校验正则隐形耦合** ☐
- 位置：`src/Domain/Media/CoverService.php:101`（配方）、`:108`（正则 `/\/\d{14}_\d{4}\.(?:jpg|webp|avif)$/`）与 `src/Domain/Media/CardThumbnailService.php:310`（配方）。
- 证据：改配方要同步三处，编译期无感。
- 方向：`MediaPaths::coverFilename($format)` + `isManagedCoverFile($path)`。
- 复核：配方字面量只在 MediaPaths 一处。

**R-09 [P2] HotPostsQuery / RelatedPostsQuery 镜像骨架** ⏭️ 1.0 后
- 位置：`src/Domain/Content/HotPostsQuery.php:82-115` 与 `RelatedPostsQuery.php:73-105`。
- 证据：marker orderby + posts_clauses 过滤器 + 可见性门 + 日期窗口骨架重复，docblock 自认镜像；date_query 窗口行逐字一致。
- 方向（1.0 后）：提取 `runMarkedQuery($args, $filter)` 私有助手防双处漂移；核心排序子句不同，不做整类抽象。
- 复核：两文件共享助手被引用。

**R-10 [P2] perPage/page 手工夹取与 args schema 校验重复（死代码）** ✅ B3
- 位置：`ContentController.php:195`、`NotificationController.php:76`、`CreditController.php:88`。
- 证据：args 已声明 minimum/maximum，WP REST 在回调前即 400（`wordpress-source/wp-includes/rest-api.php:2614-2667`），夹取分支不可达；而 Discussion/User/Comments 只 cast 不夹取，同 API 面两种风格。
- 方向：删夹取，统一依赖 schema 校验。归 B3。
- 复核：三处无夹取分支。

**R-11 [P2] FollowService / FavoriteService 关系表存储骨架复制** ⏭️ 1.0 后
- 位置：`src/Domain/Identity/FollowService.php:35-60` 与 `FavoriteService.php:37-58`。
- 证据：suppress_errors 插入+重复键回查、absent 即成功 delete、count、分页 ids 约 40 行可共享；语义有差（favorite 带可见性门），整类抽象过度。
- 方向（1.0 后）：仅共享 ids()/插入回查。
- 复核：共享段单一来源。

**R-12 [P2] Gofile / OpenList 两模块 transport 闭包近似复制** ☐
- 位置：`src/Modules/GofileModule.php:88-103` 与 `OpenListModule.php:252-273`。
- 证据：同构 wp_remote_* → is_wp_error → SourceLog::writeOnce(md5 键, 300) → {status, body}；注释自认同款。
- 方向：FileServe 提供共享 wire-transport 工厂（headers/动词参数化）。
- 复核：两模块调用同一工厂。

**R-13 [P2] 同域三种随机串配方并存** ☐
- 位置：`src/Api/Rest/SponsorshipController.php:146,236`（`md5(uniqid(wp_rand()))` ×2）与 `src/Domain/Sponsorship/RedeemCodeService.php:125`（`wp_generate_password`）。
- 证据：均可工作（CSPRNG 底料），但同一域三配方无必要。
- 方向：`randomSuffix(int $len)` 助手。
- 复核：`md5(uniqid` 零命中。

**R-14 [P2] 「modified 折叠键对象缓存」memo 模式三份，TTL 各异** ☐
- 位置：`src/Api/Presenter/PostCardPresenter.php:51-84`、`PostPresenter.php:47-48,353-360`、`DiscussionPresenter.php:38-39,185-204`。
- 证据：同组 `aiya_core_content`、同键形状 `前缀_id_md5(modified)`，TTL 分别 600/HOUR_IN_SECONDS/600。
- 方向：共享 memo 助手或至少组名/键形状收敛到一处常量。
- 复核：组名与键形状字面量单处。

**R-15 [P2] 三个 bulk action 的 notice() 渲染三份同构** ☐
- 位置：`src/Admin/CardThumbnailBulkAction.php:110-144`、`TermMoveBulkAction.php:168-202`、`PostTypeSwitchBulkAction.php:162` 起。
- 证据：读 `$_GET` 计数器→`_n()` 拼消息→notice div，约 35 行/份。
- 方向：共享计数器 notice 渲染器，三处留文案映射。
- 复核：notice 渲染函数单处。

### 3.2 L 契约与层错位

**L-01 [P1] Domain 命名空间内嵌 14 个 admin 页面类，放置约定分裂** ☐
- 位置：`src/Domain/` 下 14 个 `*Page.php`（Credit/DevTools×7/Mail/Media/Notification/Operations/Sponsorship×2）+ `AvatarModule.php:417-461`。
- 证据：注册 admin_menu、读 superglobals（CreditsPage.php:141-347、SearchReplacePage.php:59-257）、部分直接 `wp_send_json_*`/挂 `wp_ajax_`；而同类页 `DiscussionModerationPage` 住 `src/Admin`。
- 方向：B6 裁决二选一——迁入 `src/Admin`（域只留服务），或 ARCHITECTURE 明文豁免「域自带管理页」并划 superglobals/wp_send_json 边界。勿维持现状。
- 复核：裁决结论出现在 ARCHITECTURE.md，目录形态与之一致。

**L-02 [P1] 域服务直读请求超全局并依赖 Infrastructure/Http** ⏭️ 1.0 后（B6 落裁决）
- 位置：`src/Domain/Engagement/CounterService.php:232-238`（visitorHash）。
- 证据：内联读 `$_SERVER['HTTP_USER_AGENT']` + 调 `Infrastructure\Http\ClientIp::forVisitor()`；正确范式见 `CommentsController.php:238`（REST 层取 IP 传入）。
- 方向（1.0 后）：指纹由 REST 边界解析后作参数传入，域只收字符串。
- 复核：Domain/ 无 `$_SERVER` 引用。

**L-03 [P1] 账号安全不变量内联 REST 控制器，无 Domain 归宿** ⏭️ 1.0 后（B6 落裁决）
- 位置：`src/Api/Rest/UserController.php:352-371,401-432`、`AuthController.php:152-192,281-289`。
- 证据：改邮箱强制重认证、改密先吊销全部会话（失败即中止）、注册 UUID 用户名铸造、email_exists 409——安全不变量以 wp_update_user 等直写在控制器；ARCHITECTURE 明言控制器 "never query WordPress directly"（读侧做到、写侧无域可走）。
- 方向（1.0 后）：Identity 补 AccountService（changeEmail/changePassword/register）。
- 复核：控制器无 wp_update_user/wp_set_password/wp_create_user 直调。

**L-04 [P2] DiscussionDetail 双字段重复：contentHtml 与 content.html 同值并存** ☐
- 位置：`src/Api/Contract/DiscussionDetail.php:30-38`。
- 证据：detail 载荷同一 HTML 两种形状出现两次；list 载荷只有 contentHtml。v1 冻结下只能加法。
- 方向：登记收敛——detail 消费方归 `content.html`，`contentHtml` 在 detail 面降级为兼容副本（B6 文档化）。
- 复核：ARCHITECTURE/快照注释记载收敛方向。

**L-05 [P2] `Seo::noindex` 恒值死字段** ☐
- 位置：`src/Api/Presenter/PostPresenter.php:167`、`src/Api/Contract/Seo.php`。
- 证据：全仓唯一构造点恒传 false，docblock 声称 "backend decision surfaced verbatim" 但不存在后端写入方。
- 方向：docblock 改写为「保留字段、恒 false、语义归前端」，或收缩构造。归 B6。
- 复核：docblock 与现实一致。

**L-06 [P2] `UserProfile.username` 与自身 docblock 矛盾** ☐
- 位置：`src/Api/Contract/UserProfile.php:20-22`、`src/Api/Presenter/UserPresenter.php:42`。
- 证据：docblock 写明 login "not part of the contract"，字段装载的恰是 UUID `user_login`（仅 /users/me 自视面，无泄漏面）。
- 方向：改 docblock 或改字段语义。归 B6。
- 复核：docblock 自洽。

**L-07 [P2] 跨层字符串键耦合绕过域属主常量** ☐
- 位置：`src/Api/Presenter/PostPresenter.php:543-548`（`'view_count'/'like_count'/'rating_score'/'rating_count'` 字面量 vs `CounterService.php:40-41` 的 RATING_KEY/RATING_COUNT_KEY）；`src/Api/Presenter/CommentPresenter.php:31`（`'aiya-smilie'` vs `SmiliesRenderer::IMG_CLASS`，SmiliesRenderer.php:26）。
- 证据：漂移时静默失真（smilie 会被 kses 剥掉）。
- 方向：引用属主常量。归 B4。
- 复核：四键与类名字面量在 Presenter 零命中。

**L-08 [P2] discussion update 响应的 replies 静默截断且无分页标记** ✅ B3（信封化 + meta.pagination 真实回复分页）
- 位置：`src/Api/Rest/DiscussionController.php:289-295`。
- 证据：update 把第 1 页 50 条回复装进 detail 返回，超 50 条无提示截断，detail 形状无分页字段。
- 方向：update 不回带 replies（走 GET /discussions/{id}/replies），或 meta 带回复总数。归 B3。
- 复核：超 50 条线程的 update 响应可判断完整性。

**L-09 [P2] SitePresenter 以裸字符串键耦合 SiteBlocks 形状** ☐
- 位置：`src/Api/Presenter/SitePresenter.php:102-109`。
- 证据：对 presentArray() 产物按 `'blocks'['adsTop']['adsBottom']` 字面键剥离；SiteBlocks 改键名时赞助者视图静默恢复广告。
- 方向：下沉为 `SiteBlocks::withoutAds()` 值对象方法。归 B4。
- 复核：SitePresenter 无 blocks 键字面量。

**L-10 [P2] ARCHITECTURE.md 零路由节与代码现实漂移** ✅ 0.102.0
- 位置：`docs/ARCHITECTURE.md:132-135,157-160`。
- 证据：称 "PostSummary still ships a legacy url (deprecated)"——现行 PostSummary 已无 url 字段；称 mentions "pending"——Mentions::linkify 已上线并接入三个 Presenter。
- 方向：更新 Verified surfaces / Status 两段。归 B6。
- 复核：文档描述与代码一致。（已随 0.102.0 专项审查修正：PostSummary.url 描述、mentions 状态）

### 3.3 C 缓存

> 前提：运行环境无外部对象缓存，wp_cache_* 为请求级；transient 落 options 表，过期行仅在同名键再读时惰性删除，核心无 GC cron。多条读路径把「对象缓存+TTL」当 freshness contract，当前部署下正确。

**C-01 [P1] 过期 transient 无运行时 GC，限流滚动键永不复读，wp_options 永久增长** ☐
- 位置：`src/Api/Rest/RateLimiter.php:46-50`（键含 `intdiv(time(), window)` 窗口号）、`src/Domain/Engagement/CounterService.php:109-115,133-139,162-174`（去重键 view 1h / like·rating 30d）。
- 证据：窗口一过的键永不被 get_transient 命中→不触发惰性删除；counter_view 预算 120/60s 是全站最高频写面，单 IP 每分钟留 2 行（值行+timeout 行）直到卸载；uninstall.php:335-347 的前缀清扫恰证明无日常清理。影响 options 表膨胀拖慢备份与全场查询。
- 方向：并入现有每日 cron 加前缀式过期清扫（`_transient_timeout_aiya_core_%`、`_transient_aiya_core_%`、`_transient_aiya_svc_ticket_%`），零新依赖。（归 B2 或 B1 皆可，建议 B2 顺 cron 面。）
- 复核：cron 后 `SELECT COUNT(*) FROM wp_options WHERE option_name LIKE '_transient_timeout_aiya_core_%'` 收敛。

**C-02 [P0↑原判 P1] 派生缩略图文件键只含 attachmentId+尺寸，替换附件/改质量后永远供应旧图** ✅ B1（无单测：现有测试不触达生成路径，与 ThumbnailService 先例一致；人工验证=媒体库替换附件/改质量后重生成）
- 位置：`src/Domain/Media/CardThumbnailService.php:236-267`（dest 拼接 :254）、`packages/image-processor/src/ThumbnailGenerator.php:50`（is_file 即复用）。
- 证据：目标名 `{attachmentId}-{w}x{h}.{format}`——(a) 媒体库替换同一附件二进制→永远旧图裁切；(b) 改 `image_quality`（质量不入名）→永不重生成。姊妹 `ThumbnailService.php:22-24,94-97` 已修同一缺陷（键折叠源路径+质量）并注释自我声明，本服务未跟进；全插件无 attachment 变更钩子清理派生树（MediaModule.php:122-138 只清 cover 树）。影响读端 featuredFor/defaultDerivative 长期陈旧，当前部署即正确性问题。
- 方向：dest 名沿用 `ThumbnailService::cacheKey` 折叠（源路径+尺寸+格式+质量）。
- 复核：替换附件二进制后派生图重生成；改质量后重生成。

**C-03 [P2] `aiya_core_content` 组缓存全靠 TTL 兜底，写入方不失效** 登记不动
- 位置：`PostPresenter.php:260-302`、`PostCardPresenter.php:44-52`、`ContentManagementModule.php:57-62`。
- 证据：分组 flush 只挂 `update_option_aiya_core_content`（NSFW 保存）；term 改名/bump 计数/主题 filter 变化不重键。代码注释均声明「TTL is the freshness contract」，无 drop-in 时全部退化为请求级、无跨请求陈旧。
- 处置：设计契约而非缺陷，登记防误判；未来加装对象缓存时在 `edited_term_taxonomy`/`save_post` 补同组 flush_group。

**C-04 [P2] 讨论列表逐行解析作者，未批量预热用户缓存** ✅ B3
- 位置：`src/Api/Presenter/DiscussionPresenter.php:221-236`（每行 get_userdata + get_avatar_url）、`DiscussionController.php:147-169`。
- 证据：自建表行不享受 WP_Query 预热；perPage 上限 100（:57），百行页约 40-200 条额外查询。同插件范式：`PostPresenter.php:223` cache_users。
- 方向：present 前 `cache_users($ids)`（users+usermeta 各一条 IN）。归 B3。
- 复核：列表页查询数（Query Monitor 或 SAVEQUERIES）明显下降。

**C-05 [P2] 通知 feed 逐行回查对象锚点** ✅ B3
- 位置：`src/Api/Presenter/NotificationLinker.php:53`（get_post）、`:65`（get_comment）、`:84`（threads byId 全行+board JOIN）。
- 证据：50 行 feed 最多 50 次额外点查；核心对象有请求内缓存兜底，threads 自建表完全没有。
- 方向：NotificationController 按 object_type 分组 IN 预取（服务加 `byIds()`）。低成本非必做，归 B3。
- 复核：feed 单页点查数下降或登记不做。

### 3.4 S SQL 与数据层

> 全库约 110 处 `$wpdb->` 逐一核对：prepare/%i/IN 占位/esc_like/列名白名单全覆盖，无注入面。结构性前提：迁移链压平为 CREATE-only 幂等安装器后 dbDelta 只加不减，**1.0 冻结是清死列死索引的最后窗口**。

**S-01 [P1] 死索引：aiya_notifications 的 actor_id + object_ref 无查询消费者** ✅ 0.102.0
- 位置：`src/Domain/Notification/NotificationService.php:355-356`（DDL）。
- 证据：全库无 `actor_id=`/`object_id=`/`object_type=` 的 WHERE/JOIN（仅 SELECT 列输出）；每次 INSERT 白维护两个二级索引。
- 方向：1.0 前从 CREATE 摘掉；升级库仿同文件 user_id 索引先例（:366-374）补幂等 DROP。
- 复核：DDL 无此二键；旧库重跑迁移后 `SHOW INDEX` 无此二键。

**S-02 [P1] 社区列表 newest 排序缺索引、单键 status 冗余** ✅ 0.102.0
- 位置：`src/Domain/Discussion/DiscussionService.php:149,804-808`。
- 证据：真实查询 `WHERE status=? [AND …] ORDER BY d.created_at DESC, d.id DESC`（newest 分支）无 (status,created_at) 复合键→全程 filesort；`KEY status` 是 (status,bumped_at) 左前缀，纯冗余。
- 方向：加 `KEY status_created (status, created_at)`、删 `KEY status`。
- 复核：EXPLAIN newest 查询不用 filesort。

**S-03 [P1] 死列：aiya_discussion_replies.updated_at 写不读** ✅ 0.102.0
- 位置：`DiscussionService.php:496,630,826`（写）。
- 证据：replies()/replyById()/syncReplyStats() 的 SELECT 全不取该列；threads.updated_at 有读，此列没有。
- 方向：从 CREATE 摘掉（升级库死列留存，正是最后窗口的原因）。
- 复核：DDL 无此列。

**S-04 [P1] 死列：aiya_stats_active.first_seen 写不读** ✅ 0.102.0
- 位置：`src/Domain/Operations/StatsRecorder.php:111,213`（写）。
- 证据：唯一写方 INSERT IGNORE，MAU 只 `COUNT(*)`（StatsQuery.php:177-182），零读取。
- 方向：删列，或让月报输出首活跃时间用起来（二选一，1.0 前裁决）。
- 复核：DDL 与月报字段一致。

**S-05 [P2] purge 删 termmeta `thumbnail_id` 无 taxonomy 限定，误伤第三方** ✅ B2
- 位置：`uninstall.php:317-319`。
- 证据：无 JOIN term_taxonomy 限定契约分类；站点另装写 term 缩略图的插件（WC product_cat 等）会被一并抹掉。
- 方向：DELETE JOIN 限定契约分类白名单。
- 复核：purge SQL 含 taxonomy IN 白名单。

**S-06 [P2] 5 处 `SHOW TABLES LIKE` 未 esc_like** ✅ B2
- 位置：`DiscussionService.php:834`、`IdentityModule.php:147`、`SponsorshipModule.php:357`、`LedgerService.php:401`、`NotificationService.php:376`（对照 `StatsRecorder.php:117` 有 esc_like）。
- 证据：表名固定前缀无注入，但前缀含 `_` 时 LIKE 单字符通配理论上可误判。
- 方向：统一补 esc_like。
- 复核：`grep -rn "SHOW TABLES LIKE" src/` 全部经 esc_like。

**S-07 [P2] DevTools 替换执行全量载入匹配 ID，无分批** ✅ B2
- 位置：`src/Domain/DevTools/SearchReplacePage.php:265-273`（执行，:212 预览同样无界）。
- 证据：`SELECT ID` 无 LIMIT 全量进 PHP 再拼单条大 UPDATE——大结果集内存与 max_allowed_packet 双风险（admin-only + manage_options + nonce 门内，属可接受风险）。
- 方向：按 id 分批。
- 复核：执行路径有分批循环。

**S-08 [P2] 线程删除两步无事务** ✅ B2（现为三步含点赞清理论 + 事务）
- 位置：`DiscussionService.php:590-597`。
- 证据：先删 replies 再删 threads，中途失败留「回复已清、线程仍在」中间态（reply_count 自愈，线程残留）。spend() 已示范 START TRANSACTION 用法。
- 方向：包事务。
- 复核：删除路径含 START TRANSACTION/COMMIT。

**S-09 [P2] `#tag#` 过滤 = TEXT 前导通配 LIKE 全扫** 登记不动
- 位置：`DiscussionContent.php:113-125` + `DiscussionService.php:142`。
- 证据：tag 内嵌 content、无关系化承载；属「需按子项查询=该压平」方向，但社区规模小、每页 LIMIT、上关系表要动契约定稿。
- 处置：1.0 不动，记录为已知取舍。

**S-10 [P2] 月报读侧全表扫 + PHP 分桶** 登记不动
- 位置：`src/Domain/Operations/StatsQuery.php:209-218,283-289`。
- 证据：entitlements 拉 range 内全行 O(月×行) PHP 分桶；cash 的 COALESCE 范围使索引不可用（表也无键）。admin-only、行数=购买数，可接受，**勿为它建键**。
- 处置：登记。

**S-11 [P2] 两处 ORDER BY id 的 per-user filesort** 登记不动
- 位置：`LedgerService.php:301`（fifo 键不覆盖 ORDER BY id DESC）、`NotificationService.php:268`（要消掉可把 `KEY created_at` 改 `(created_at, id)`）。
- 处置：前者行数受保留期约束不动；后者若顺手可在 B2 改。

**S-12 [P2] payment_orders 的 status 过滤无索引** 登记不动
- 位置：`OrderService.php:216,250`、`StatsQuery.cash`。
- 证据：流水表行数小且增长受 TTL 归档约束，建键得不偿失。
- 处置：登记。

### 3.5 H 代码卫生

**H-01 [P0] 本地 Docker 挂载使 phpunit 静默漏跑，绿灯不可信** ✅ B1（入口=`composer test:native` / scripts/test-native.sh）
- 位置：vendor/phpunit/php-file-iterator（根因链），表现为运行结果。
- 证据：实测挂载路径 `OK (90 tests)`；容器本地盘 `OK (625 tests, 1811 assertions)`。根因：gRPC-FUSE 挂载目录枚举截断 → php-file-iterator 对假 realpath 静默丢弃 → 套件仅发默认隐藏 warning。本机全绿≠全量通过，1.0 回归防线形同虚设（也解释 H-02 为何没红过）。
- 方向：CI 或本地脚本在 Linux 原生盘跑套件；以测试数 ≈625 为基准断言。
- 复核：测试入口输出测试数 ≥ 基准；故意加一个失败用例能跑红。

**H-02 [P1] 两个测试文件不能独立运行（顺序依赖）** ✅ B1
- 位置：`tests/Unit/AfdianOrderUrlTest.php:48`（依赖定义在 `AfdianActivatorTest.php:402` 的 SponsorshipTestWpdb，自身零 require）；`tests/Unit/AdminBarFrontendLinkTest`（被测 `Admin/AdminBarFrontendLink.php:40` 调 esc_html__，bootstrap.php 未定义，靠字母序更早文件的 wp-shims.php 侥幸通过）。
- 证据：单文件跑分别报 Class not found（2 errors）/ 3 errors。
- 方向：fixture 归位 tests/Fixture/ 显式加载；`esc_html__`/`esc_attr__` 提进 bootstrap.php。**注意与邮件域在途的 tests/bootstrap.php 改动协调。**
- 复核：两个文件 `phpunit --filter` 单跑通过。

**H-03 [P1] 死代码：7 个 public 方法 + 1 个常量（全仓库+tests+themes 零引用）** ☐
- 清单：`FavoriteService.php:152 countForPost()`、`FollowService.php:90 countFollowing()`、`MediaPaths.php:98 coverDir()`、`ThumbnailService.php:82 urlForReference()`、`OrderService.php:415 forUser()`、`Metadata/Registry.php:75 postBox()`、`:80 termBox()`、`OrderService.php:38 const STATUSES`。
- 方向：1.0 直接删（git 可找回）。
- 复核：`grep -rn "countForPost\|countFollowing\|coverDir\|urlForReference\|postBox(\|termBox(" src/ tests/` 仅剩定义外零命中后删除。

**H-04 [P2] 兑换码域零测试** ☐
- 位置：`src/Domain/Sponsorship/RedeemCodeService.php`。
- 证据：tests/ 无任何直接或间接引用；原子核销/回滚（含 :94 回滚失败分支）完全裸奔。LedgerService FIFO 过期边界也无专门测试（仅间接扫过）；FileServe 归一化覆盖良好。
- 方向：补 RedeemCodeServiceTest（核销幂等/过期/回滚三场景）+ LedgerService 过期边界用例。归 B5。
- 复核：套件含上述用例且全绿。

**H-05 [P2] 唯一硬编码中文串（非 i18n）** ☐
- 位置：`src/Domain/Sponsorship/AfdianGateway.php:76`。
- 证据：`sprintf('来自「%s」的会员订单', get_bloginfo('name'))` 进爱发电支付 URL 的 remark（packages/payment-afdian/src/Gateway.php:75），赞助页用户可见；全 src 1057 条 `__()` 文案中唯一源语言外字符串。
- 方向：包 `__()` 入 pot，或注释说明刻意为之。归 B5。
- 复核：源码无未包装中文字面量。

**H-06 [P2] error_log 双轨制（2 处门控 / 7 处裸奔）** ☐
- 位置：有门控：`Runtime/Packages.php:83`（phpcs:ignore + WP_DEBUG 门 + 援引约定）；裸奔：`Admin/DiscussionModerationPage.php:323,493`、`NotificationActions.php:613`、`NotificationPage.php:185`、`EntitlementService.php:208`、`RedeemCodeService.php:94`、`MediaPaths.php:156`。
- 证据：均为失败 breadcrumbs（带 `[aiya-core]` 前缀）而非调试残留，但约定只落实一半。
- 方向：统一 WP_DEBUG 门 + phpcs:ignore 注释。归 B5。
- 复核：`grep -rn "error_log" src/` 全部带豁免注释。

**H-07 [P2] phpcs 唯一 1 error + 2 warnings** ✅ 0.102.0
- 位置：`DiscussionService.php:815`（迁移 SQL 表名插值，ValidatedSanitizedInput/MustUsePrepare 误报性命中，与上下文 CREATE 同模式）；`Content/Mentions.php:98`（$match 保留字提示）；`Parts/BuiltinParts.php:203`（未用 $content，签名统一故意留空）。
- 方向：三处豁免注释归零。归 B2。
- 复核：phpcs 0 error 0 warning。

**H-08 [P2] i18n 省略号双文案 2 对** 提示
- 位置：`PostTypeSwitchBulkAction.php:50/81`、`TermMoveBulkAction.php:50/75`。
- 证据：动作带省略号、弹窗标题不带——符合 WP 惯例，非缺陷；翻译时两串都要维护。

### 3.6 G 发布门

**Checklist（审查时点）**：版本一致性 ⚠️ · 插件头部 ⚠️ · uninstall 完备性 ✅ · 生命周期 ✅ · REST 发布面 ✅（60 条路由 0 缺 permission_callback）· cron 清单 ✅（9 事件双路清理）· 打包与依赖 ✅ · 壳主题同步 ✅（diff 零差异）· 契约冻结 ✅（活跑成功、51 DTO）· ROADMAP 遗留 ⚠️（无 1.0 阻塞项）。

**G-01 [P1] 版本叙述漂移：ROADMAP 无 0.101.x 批次条目，AGENTS.md 摘要停在 0.101.0** ☐
- 位置：`docs/ROADMAP.md`（最后版本戳条目 0.100.0 在 :1470 附近）；工作区 `AGENTS.md` 现状摘要。
- 证据：提交 2aa9e47（Stamp 0.101.0）、edc2e4d（0.101.1）后 ROADMAP 再无版本号叙事；实装 0.101.1。
- 方向：ROADMAP 补 0.101.x 收尾 + 1.0.0 批次；AGENTS.md 摘要同步。归 B6。
- 复核：ROADMAP/AGENTS/插件头三方版本自洽。

**G-02 [P1] `Requires at least: 6.4` 与 WP 7.1 基线的 1.0 拍板悬而未决** ☐
- 位置：`aiya-core.php:6`。
- 证据：代码已按 7.x 语义验证过（0.98.0 读 7.1 核心 post.php 实证修订闸、add_submenu_page position），6.4 下限从未被测试。
- 方向：1.0 前拍板——升 `7.0`/`7.1`（推荐，与 AGENTS「WordPress 7.1 基准」一致）或补 6.4 验证。归 B6。
- 复核：头部值 = 拍板结论。

**G-03 [P2] composer PHP 下限与插件头不一致** ☐
- 位置：`composer.json:7` `"php": ">=8.4 <8.6"` vs `aiya-core.php:7` `Requires PHP: 8.5`。
- 方向：require 改 `>=8.5` 对齐声明。归 B6。
- 复核：两处一致。

**G-04 [P2] 0.100.0–0.101.1 从未打 tag，线上 PUC 链停在 v0.99.0** ☐
- 证据：`git tag` 终于 v0.99.0；线上站点从未收到这三个版本。1.0 是首个走完整 release.yml 资产链的大版本；四个数据搬迁正是为 ≤0.94 线上库保留的。
- 方向：上线时实测一次 0.99.0→1.0.0 升级路径（PUC 自动更新链 + 迁移幂等对账）。
- 复核：线上升级后 `aiya_core_schema_version`=1.0.0、`aiya_core_last_migration_error` 不存在。

**G-05 [P2] 无 readme.txt** 登记选择
- 证据：自托管 PUC 分发不要求；若想对齐 WP 惯例（changelog/faq 供后台插件页展示）可补。
- 处置：保持现状则在 README.md 记一句「有意无 readme.txt」。

**G-06 [P2] 计数端点 cookie 会话的核心 nonce 语义（front-station 联测提醒）** 记录在案
- 位置：`CounterController.php:32-46`（`__return_true` + 内部 is_user_logged_in）。
- 证据：浏览器直连保 IP 去重的登录态写请求走 cookie 认证时，核心要求 X-WP-Nonce（rest-api.php:1166 否则 403）。插件侧语义正确无可修。
- 处置：front-station 直连链路须带 nonce 或 bearer，1.0 联测覆盖。

**G-07 [P2] activate() 写 `permalink_structure`，卸载不还原** 记录在案
- 位置：`Plugin.php:255`（写入）、uninstall 无对应清理。
- 处置：站点级配置而非插件数据，purge 不回滚是合理选择；防误报为泄漏。

**G-08 [P2] composer.json 无 version 字段（设计意图）** 记录在案
- 证据：版本唯一真源是 tag（release.yml 三方门强制 tag==header==const）。
- 处置：**不加** version 字段，避免引入第二处需同步的版本位。

### 3.7 ROADMAP 遗留项盘点（发布门审查附带）

| 条目 | 处置登记 | 1.0 前必须裁决？ |
|---|---|---|
| 轻社区点赞、首页区块 community、对象缓存加深 | 列入计划本期不做 | 否 |
| 邮件模板整套替换+事务性邮件 | **已落地销账（2026-10-03，ROADMAP:1528 附近）**——品牌壳接管/四类原生邮件前台化重写/激活回执/欢迎与安全通知/静音四项；到期提醒副本与 From 美化暂缓，注册验证流不做 | 已闭环 |
| @提及通知 | 已落地销账（ROADMAP:1522 附近） | 已闭环 |
| 前端 HTTP 缓存兑现 | 延期（front-station 迭代） | 否 |
| webhook | 预留缝=事件动作，本期零代码 | 否 |
| 路由引用解耦阶段 3（PostSummary.url 删除） | 待前台停读后拍板 | 否（v1 快照不动） |
| 爱发电 plan 绑定重填、GoFile 令牌复验 | 运营者自办 | 建议上线前人工完成，非发布门 |

## 4. 干净面（已核实，无需动作）

- **R 线**：packages/ 零 WP 依赖守得住（支付签名只在包内）；RateLimiter/Envelope/HttpCache/CorsHeaders/ClientIp/TrustedProxy/TokenStore 单一实现经 DI 共享；CounterService/MimeType::detect/RoleLevel/PasswordPolicy/ReadingTime/StatsMath 单一所有者；Settings/Metadata 框架单点进出；UpdateChecker 用 PUC、ContentFormatter 是文档化纯函数移植——均非自造轮子。
- **L 线**：packages/ 零 WP 函数零钩子；无 `aiya/v1` 残留；信封全域一致；`aiya/integrations/v1`、`aiya/sponsorship/v1` 均已宣告；DTO↔Presenter 抽查约 25 个全对齐（除 L-04～06）；Admin 层无直连 wpdb、批量动作全委托域服务；零路由标记词汇表在全部产出面落地（唯一 `<a href>` 是 admin 授权链接 button 零件豁免面）。
- **C 线**：全部 `aiya_core_*` 选项 autoload=off；所有 transient 带非零 TTL；大数组走页面选项/post meta 未进 alloptions；列表主路径 WP_Query 全套预热 + HttpCache max-age=60 + ETag；related/hot/search 有限流+304 护城河（按「缓存加深本期不做」拍板不加层）；stats 月报零缓存合理；fileserve 交付定价对象缓存镜像设计完备；/site 300s 双层一致；`aiya_core_opt` memo 挂三写钩子清除、全部 static 均请求级 memo。
- **S 线**：prepare/%i/esc_like/白名单全覆盖；meta 直查仅两处且为有文档的原子性需要；迁移链幂等成立（GET_LOCK+finally RELEASE、version_compare 方向正确、搬迁条目 array_key_exists 守卫、bumped_at 哨兵回填）；purge 覆盖 13 表+三张通配删+协议键按 AGENTS 保留+9 cron 全清+multisite 循环；fileserve JSON 与 settings 序列化均为「读整块写整块零跨行查询」正确形态，无需压平。
- **H 线**：strict_types 242/242；PSR-4 242/242；文本域全部 aiya-core；零引用类/私有零调用方法 0；注释代码块/TODO/FIXME/var_dump 0；6 大 Admin 页无裸 echo；16 个 AJAX 端点 nonce+capability 全配；84 处 superglobals 全部 sanitize；hash_equals 常时比较；16 个 catch 全有语义无空 catch；pot/PO/MO 2026-10-03 重建（pot 1067 msgid）；translators 注释 33/33；8 个 CSS/JS 全被引用零孤儿、版本统一 AIYA_CORE_VERSION + WP_DEBUG 下 filemtime cache-bust。

## 5. 工具链基线数字（每批收尾重跑比对，不得劣化）

| 项 | 审查时点基线 |
|---|---|
| phpstan（level 8，phpVersion 80500） | 0 errors（需 `--memory-limit=2G`，容器默认 1G 会 worker crashed） |
| phpcs（WordPress-Core+Extra+PHPCompatibilityWP） | 1 error + 2 warnings（H-07） |
| phpunit（**Linux 原生盘真全量**） | OK，625 tests / 1811 assertions（挂载路径 90 不可信，H-01） |
| 缺 strict_types | 0 / 242 |
| markTestSkipped / 零测试文件 | 0 / 0（100 文件全有 test 方法） |
| 死代码 | 7 方法 + 1 常量（H-03） |
| 未测试域 | RedeemCodeService、LedgerService 过期边界（H-04） |

> **B1 后更新（2026-10-03）**：phpunit 原生盘 641 tests / 1863 assertions 全绿（+16 来自邮件域并行批 MailShellTest/MailRewritesTest）；phpstan 0 errors；phpcs 规则集内除 H-07（1E+2W）外，邮件域在途文件 CoreMailRewrites/MailTemplate 另有 5E+4W（归彼侧批次，不计入本台账基线）。H-01/H-02/R-01/C-02 已 ✅。
>
> **0.102.0 后更新（2026-10-03）**：S-01～S-04 随 DL 批次落地（死索引/死列幂等 DROP 内嵌安装器、开发库实测自愈零手工 SQL）；phpunit 基线升至 648 tests / 1898 assertions（+DiscussionLikeTest）；phpcs 规则集内剩余 = H-07（1E，DiscussionService 迁移 SQL 误报性命中）+ 邮件域文件。SQL 压平缺口最后一项（点赞表）已由批次 DL 关闭，见 docs/discussion-likes-design.md。

## 附录 A：1.0 发布动作清单（B6 执行）

1. 拍板 `Requires at least`（建议 7.0）并同步 composer.json php 下限 `>=8.5`（G-02/G-03）。
2. ROADMAP 补 0.101.x 收尾条目与 1.0.0 批次；AGENTS.md 现状摘要更新（G-01）。
3. `aiya-core.php` 头部 `Version:` 与 `AIYA_CORE_VERSION` 同步 `1.0.0`（首个请求幂等跑完压平链，stored→1.0.0）。
4. 重建 POT/PO/MO（走 wp-i18n-zh-cn 流程，版本头自动带 1.0.0）。
5. `wp aiya contracts snapshot` 与 front-station `contracts.snapshot.v1.json` 基线比对零 diff。
6. commit + `git tag v1.0.0 && git push origin v1.0.0`——release.yml 三方门通过，产出 `aiya-cms-core-1.0.0.zip`（含 vendor + 编译 .mo）。
7. 确认 GitHub release 资产名匹配 PUC REQUIRE 正则 `^aiya-cms-core-.*\.zip$`（UpdateCheckerModule.php:43），线上自动更新链实测一次。
8. 线上升级后核对：`aiya_core_schema_version`=1.0.0、`aiya_core_last_migration_error` 不存在。
9. 抽查卸载语义一次：Plugins 屏询问页、WP-CLI 默认 keep、开关开时 purge。
10. front-station 侧同步（快照无 diff 则无需动）。

## 附录 B：自建表索引摘要（数据层审查）

| 表名 | 用途 | 索引评估 | 压平 |
|---|---|---|---|
| aiya_discussion_boards | 社区板块 | 够（slug 唯一） | 否 |
| aiya_discussions | 社区主题 | 缺 (status,created_at)；KEY status 冗余（S-02） | 否（tag 内嵌为已知取舍） |
| aiya_discussion_replies | 回复 | 够；updated_at 死列（S-03） | 否 |
| aiya_user_favorites | 收藏 | 够 | 否 |
| aiya_user_follows | 关注 | 够（双向覆盖） | 否 |
| aiya_auth_tokens | bearer 会话 | 够 | 否 |
| aiya_credit_entries | 积分账本 | 够（dedupe/fifo/direction_expires 各有消费方） | 否 |
| aiya_notifications | 通知 | 主路径够；actor_id/object_ref 死索引（S-01） | 否 |
| aiya_memberships | 会员队列 | 够 | 否 |
| aiya_payment_orders | 支付流水 | 够（status 全扫可接受） | 否 |
| aiya_redeem_codes | 兑换码 | 够 | 否 |
| aiya_stats_monthly / aiya_stats_active | 月报 / MAU | PK 即查询面；first_seen 死列（S-04） | 否 |

## 6. 0.102.0 发布前专项审查（2026-10-03，发布范围 edc2e4d..v0.102.0）

两路并行审查（邮件域 / 其余新增面）覆盖 0.101.1 盖章以来的全部 19 个提交。发现与处置：

| # | 严重度 | 发现 | 处置 |
|---|---|---|---|
| M1 | P1 | 邮件 CID 悬空：marker 分支不补 embeds，五类品牌邮件页头图标碎 | ✅ 修复（marker/包裹两路统一 withIconEmbed + is_file 守卫 + CID 引用检测） |
| M2 | P1 | 激活回执 CTA 指向已退役 `/membership/` 路由 | ✅ 改 `/profile/me/` |
| M3 | P1 | 壳主题硬编码中文（「条评论」、`、`分隔），违反 core 原串契约 | ✅ `get_comments_number_text()` + `get_the_category_list()` 默认分隔 |
| M4 | P2 | like 竞态：UPDATE 0 行被当成功，线程删除竞态留孤儿行 | ✅ `(int)$bumped < 1` 判失败回滚 |
| M5 | P2 | multipart 邮件被强换 text/html 破坏 boundary | ✅ multipart 检测整体让路 |
| M6 | P2 | color_primary 未校验即内插 style | ✅ hex 正则校验，不合格回落默认 |
| M7 | P2 | MailTemplate 硬编码 lang="zh-CN" | ✅ 改 get_locale() 动态 |
| M8 | P2 | membershipReceipt 死参数 + 日期两步手工重复 | ✅ 签名收敛 + DateLabels::fromGmt |
| M9 | P2 | MailTemplate 两处缺 translators 注释 + CoreMailRewrites 两条失效 ignore 码 | ✅ 补注释 + ignore 码族级修正 |
| M10 | P2 | 契约快照遗漏 DiscussionDetail 的新字段（WIRE_SHAPES 未同步） | ✅ 本批 0.102 主体已同步（审查前发现） |
| M11 | P2 | marker 分支对非文本 Content-Type 一律强换（防御缺口） | ✅ 与 M5 一并覆盖 |
| R1 | P2 | 点赞响应 `{likes,viewerLiked,already}` 为 ad-hoc 面不在契约执法 | ✅ B3（LikeResponse DTO + 快照 + zod） |
| R2 | P2 | 登录写面限流按 IP 计（RateLimiter docblock 建议登录面用 hitFor） | ✅ B3（互动/账户写面全切 hitFor(user)；auth 凭据面、匿名读面、webhook 保持 IP） |
| R3 | P2 | lockWpV2 在 rest_endpoints 内 302+exit 语义偏脆（当前影响为零） | ⏭️ 登记 |
| R4 | P2 | DiscussionService::delete 内联 new DiscussionLikeService | ⏭️ 登记（可注入化随 B4） |
| R5 | P2 | 壳主题标题助手转义不一致（当前值均管理端可控，风险低） | ⏭️ 登记 |
| R6 | P2 | 壳页脚品牌链接 home_url vs 前端 origin | ⏭️ 登记（下批统一） |
| R7 | P2 | 测试 shim 不跑 wp_mail filter（CID bug 漏网的结构性原因） | ◐ 已补 marker-embeds/multipart/死路径回归用例；垫片跑 filter 归 B5 |

**同批退役**：`docs/discussion-likes-design.md` 与 `docs/mentions-design.md` 删除（语义已永久化——点赞见 ROADMAP 0.102.0 条目、mentions 见 ARCHITECTURE 零路由节 + ROADMAP「@提及通知落地」条目；git 历史取回原文）；三处 src docblock 指针改指 ARCHITECTURE/ROADMAP。**H-07 归零**：phpcs 0 error 0 warning（三条豁免注释）。**工具链终态**：phpunit 650/1904、phpstan 0、phpcs 0/0、vitest 342/342。
