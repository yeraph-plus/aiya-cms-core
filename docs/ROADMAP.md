# AIYA Core 路线图与完成度

本文是当前迭代的实施规划：对照旧 `framework-required` 评估完成度，定义目标目录树与里程碑。模块归属的最终裁决仍以 [MIGRATION.md](MIGRATION.md) 为准，注册方式见 [ARCHITECTURE.md](ARCHITECTURE.md)。

基线：v0.8.0，2026-09-04 评估与结构定稿。运行环境 WP 7.1 / PHP 容器版，插件已激活。已落地：完整生命周期（0.2.0）、无头化裁剪（0.3.0 HeadlessModule）、安全加固（0.4.0 SecurityModule）、头像（0.5.0 AvatarModule）、自动别名（0.6.0 SlugModule + slug-toolkit 包）、元数据字段组与内容类型注册（0.7.0）、设置框架收尾与 schema 迁移 runner（0.8.0，M1/M3 关闭）、媒体栈迁移（0.9.0，image-manager → aiya/image-processor 包 + pic-bed 页面化）。**当前里程碑：M4（数据契约与内容读取层）**；M4 按域分批推进，用户域批次（0.12.0）已把 Identity 的 Contract + Presenter + REST 打样提前落地（见 M4 小节）。

## 一、完成度对照（vs framework-required v1.3）

评估口径：旧框架的「选项框架 + Metabox」部分是本插件的重构范围；其 `plugin/` 目录的 16 个辅助模块按 MIGRATION.md 归属 Domain/Infrastructure，不在本表内。

### 1. 运行时与模块机制 —— 100%

| 旧实现 | 新实现 | 状态 |
|---|---|---|
| `AYA_Plugin_Setup` + 字符串类名 `module()` 魔法 | `Contracts/Module` + `Plugin::addModule()` 显式注入 | ✅ 质量高于旧版 |
| 框架随主题硬绑定启动 | 插件四阶段生命周期 `register()/boot()/activate()/deactivate()` + `uninstall.php` | ✅ 0.2.0，可从 wp-content/plugins 正常启停删除 |

### 2. 设置框架 —— 约 90%

| 能力 | 状态 | 说明 |
|---|---|---|
| 设置页注册（slug/图标/父子页/position/网络模式） | ✅ | `Page` schema 全覆盖旧参数 |
| 保存 / 重置 / nonce / capability | ✅ | `admin_post` 流程 + reset 二次确认 |
| 值清洗与校验 | ✅ | `ValueNormalizer` 按类型匹配 + `WP_Error` + required/min/max + 自定义 sanitize；废弃旧版 htmlspecialchars 双重转义 hack |
| 字段：text / textarea / number / email / url / hidden / radio / select / checkbox / switch / color / array / media / code / tinymce / repeater | ✅ | 覆盖旧版全部高频类型；`upload`→`media`（存附件 ID，优于旧版 URL）；`code_editor`→`code`（wp_enqueue_code_editor，去除外部 CDN 依赖）；`password` 写后即焚为新增 |
| 提示类伪字段（title_1/2、content/message/success/warning/dismiss、html） | ❌ M1 | 旧设置页靠它们做视觉分区与说明，共 9 处使用，必须补 |
| 选择器动态数据源（旧 `sub_mode`：posts/terms/sidebar/user 级联查询） | ❌ M1 | 新 select/radio 目前只有静态 `options` |
| 旧 `group`（单组子字段） | ◐ | `key_value` 覆盖扁平键值；嵌套结构随嵌套 repeater 延后（与 ARCHITECTURE.md 的保守声明一致） |
| 读取门面（旧 `aya_opt()` / `get_checked()` + 静态缓存） | ❌ M1 | 需要 `aiya_core_opt()` 门面；静态缓存由 WP object cache 取代，无需复刻 |
| 旧 `action_checkbox`（保存时触发动作） | ❌ 待定 | 现代做法是字段 + 服务端 hook，M2 期间按真实需求决定是否保留 |
| 旧 `callback` 字段（任意回调渲染） | ❌ 不迁移 | 违背依赖方向，等价物是显式 Module |
| i18n（文本域 `aiya-core`） | ◐ | 代码内 `__()` 已就位，.pot 已生成于 `languages/aiya-core.pot`；.po/.mo 待译 |

### 3. 元数据（Metabox 等价物）—— 约 20%

| 能力 | 状态 | 说明 |
|---|---|---|
| 存储适配器 post / term / user | ✅ | `Metadata/Storage/*`，统一 `ValueStore` 接口 |
| Post metabox 注册 / 渲染 / 保存（旧 `new_box`：screen、context、priority、页面模板条件） | ❌ M2 | 仅缺 Admin 层，渲染/保存应复用 FieldRenderer + ValueNormalizer |
| Term / user 编辑屏字段（旧 `new_tex` 等） | ❌ M2 | 同上 |
| 旧 metabox 键兼容（`aya_box_{id}` 单键存全组） | ❌ M2 | 兼容读取层；协议键清单见工作区 `AGENTS.md`「持久数据协议」 |

### 4. 生命周期 —— 90%（0.2.0 前移完成）

激活（schema_version 记录）、停用（计划任务清理钩位）、`uninstall.php`（前缀清理全部选项，多站点覆盖）已落地；剩余：schema 版本迁移 runner（结构变更时执行升级）。

### 5. 辅助模块（访客计数、widget builder、短代码管理器、CDN、模板重写、REST/AJAX handler 等）—— 0%

按设计不迁移到本框架，逐个落入 Domain/Infrastructure 或随旧前端退役（详见 MIGRATION.md「Legacy disposition」）。其中 `register-theme-menu`（导航菜单）在无头架构下仍需要，最终由 REST 暴露。

### 已知边界行为（非缺陷）

- `aiya_core_register` 重复触发会对同一 slug 抛 `InvalidArgumentException`：真实请求中 `init` 只触发一次；CLI/测试中二次触发需新建 Registry。

## 二、目标目录树

标注：✅ 已有 / M# 建立的切片。目录仍只在第一段可工作代码落地时创建，禁止空目录占位。

```text
aiya-core/
├─ aiya-core.php                    # ✅ 常量、autoloader、激活/停用钩子、boot
├─ uninstall.php                    # ✅ 0.2.0：前缀清理全部选项（多站点覆盖）
├─ src/
│  ├─ Contracts/                    # ✅ Module
│  ├─ Runtime/                      # ✅ 0.8.0：SchemaVersionRunner（aiya_core_schema_migrations
│  │                                #   过滤器注册迁移，init 1 自动对齐版本）
│  ├─ Settings/
│  │  ├─ Registry.php               # ✅
│  │  ├─ ValueNormalizer.php        # ✅（isPersistable 字段跳过存储）
│  │  ├─ Schema/                    # ✅ Page / Field；0.8.0 + note/heading 类型、options_source、
│  │  │                             #   Field::isPersistable()
│  │  ├─ Storage/                   # ✅ ValueStore / OptionStore；M1 + LegacyOptionReader（只读 aya_opt_*）
│  │  └─ Options/                   # ✅ 0.8.0：OptionsResolver（terms/posts/users 惰性求值）
│  ├─ Api/
│  │  ├─ Contract/                  # M4：DTO 契约（纯值对象，零 WP 依赖）；
│  │  │                             #   ✅ 0.12.0 用户域切片：UserProfile / AvatarImage /
│  │  │                             #   AuthSession + Contract 版本常量（VERSION / API_NAMESPACE）
│  │  │                             #   PostSummary / PostDetail / TermDto / AuthorDto /
│  │  │                             #   ThumbnailDto / MenuTree / MenuItem / Pagination /
│  │  │                             #   Breadcrumb 待内容批次
│  │  ├─ Presenter/                 # M4：唯一允许触碰 WP_Post / WP_Term 的映射层（WP 对象 → DTO）；
│  │  │                             #   ✅ 0.12.0 UserPresenter（含旧版 role 语义与赞助协议键兼容读）
│  │  └─ Rest/                      # aiya/core/v1 控制器（只调用读服务与 Presenter，不查询数据）；
│  │                                #   ✅ 0.12.0 用户域打样：RestController（模块 + rest_api_init）+
│  │                                #   TokenAuthentication（determine_current_user Bearer）+
│  │                                #   AuthController + UserController + RateLimiter（transient 固定窗口）；
│  │                                #   内容控制器仍 M5
│  ├─ Domain/
│  │  ├─ Identity/                  # ✅ 0.5.0：AvatarModule——本地头像（协议键 basic_user_avatar）、
│  │                                #   七牛/WeAvatar 镜像、默认头像 URL；设置追加在 Headless
│  │                                #   optimization 页（不开新页）；✅ 0.10.0 v2 重写：文件头像
│  │                                #   `wp-content/avatars/{user_id}/{128,64}.jpg`（CropGenerator
│  │                                #   纯居中裁剪、原图不落盘、URL 直接拼接 + ?v= 缓存击穿），
│  │                                #   资料页文件上传控件（订阅者可自传），storeAvatar 公开供
│  │                                #   REST 复用（0.12.0 起经 storeUploadedAvatar 校验+存储）；
│  │                                #   ✅ 0.12.0：PasswordPolicy（≥8 位 + 字母数字，注册/改密/重置
│  │                                #   共用）、TokenStore（不透明 Bearer 令牌 `{userId}.{secret}`，
│  │                                #   HMAC 哈希落 user meta，14/2 天 TTL，上限 10 枚，改密全吊销）、
│  │                                #   PasswordResetService（WP 原生 reset key + 前台自报域名拼接
│  │                                #   `/reset-password?login=&key=`，来源归一化仅 scheme+host+port，
│  │                                #   `aiya_core_password_reset_allowed_hosts` 过滤器可加白名单）
│  │  ├─ Discussion/                  # ✅ 0.26.0：ThreadType/ThreadStatus（词表 + 状态机）
│  │                                #   + DiscussionService（wp_aiya_discussions/_replies
│  │                                #   自建表唯一写入方，平铺回复 + postRef 绑定工单）
│  │  ├─ Notification/              # ✅ 0.23.0：RoleLevel（guest<subscriber<sponsor<author<
│  │                                #   administrator 阶梯）+ NotificationService（自建表
│  │                                #   wp_aiya_notifications，广播/定向行，唯一写入方）+
│  │                                #   NotificationModule（0.23.0 迁移建表 + 每日清理 cron）
│  │  ├─ Credit/                    # ✅ 0.47.0：CreditAllocator（FIFO 分配纯函数）+
│  │                                #   LedgerService（wp_aiya_credit_entries 桶+流水
│  │                                #   单表账本，余额推导不落 meta，(source,ref,user)
│  │                                #   唯一键幂等，事务 + FOR UPDATE 过期先扣）+
│  │                                #   CreditModule（0.47.0 迁移建表 + 每日清理 cron）；
│  │                                #   0.48.0 纯记账收缩（去 download_cost 报价，
│  │                                #   spend() 由下游自带数额；保留期移前台页）；
│  │                                #   后台见 Admin/CreditsPage（一级菜单「会员」）；
│  │                                #   计划见 docs/credits-membership-plan.md（第二期会员
│  │                                #   tier 周期队列将向此账本发放）
│  │  ├─ Sponsorship/                # ✅ 0.50.0 tier 重写：MembershipScheduler（周期纯函数）+
│  │                                #   EntitlementService（wp_aiya_memberships 周期队列，
│  │                                #   starts_at=max(now,队尾) 顺序生效、发放 CAS 推进、
│  │                                #   桶过期=周期终点、cancelAll 全行翻转）+
│  │                                #   MembershipService（读队列，isSponsor 编辑旁路保留）+
│  │                                #   OrderService（降级纯支付流水）+ RedeemCodeService
│  │                                #   （0.49.0 起直发积分）+ SponsorshipModule（设置页挂
│  │                                #   会员入口 + 发放 cron）；易支付接入，爱发电 SDK 留置
│  │                                #   不接线；sponsor 三协议键退役（见下 Credit/ 注）
│  │  └─ Content/                   # ✅ 0.6.0：SlugModule——自动别名（pinyin / id_av / id_bv，
│  │                                #   术语 pinyin），原语来自 slug-toolkit 包
│  │                                # ✅ 0.7.0：ContentTypeModule + PostType/TaxonomyDefinition +
│  │                                #   ContentTypeRegistry（代码式 CPT/分类法，show_in_rest 默认开）
│  │                                #   + SeoBoxModule（post_seo 协议键字段组）；0.12.0 内置 page_category 独立分类法挂 page，0.15.0 内置 resource CPT + 标准分类 + 5 标签分类法；0.16.0 Engagement 计数服务 + content like/view REST 端点，0.17.0 加 rating 评分端点，0.18.0 特性矩阵（资源评分/文章点赞）
│  │  ├─ Media/                      # ✅ 0.9.0：MediaPaths（URL↔路径/目录规划）+ ThumbnailService
│  │                                #   （缓存键含质量，只读不写 meta）+ CoverService（封面生成 +
│  │                                #   `_aya_thumb` 协议键唯一写入方）
│  │                                # M4 再落 ContentQuery（旧 WP_Query 原型）、
│  │                                #   NavigationModule + PrimaryMenu（0.28.0：设置驱动自增
│  │                                #   菜单 primary/secondary 两组，替代旧 WP_Menu 蓝本的
│  │                                #   MenuService——无头后端弃用 WP 菜单系统后不保留）、
│  │                                #   FrontendModule（0.29.0：前台壳配置设置页，GET /site 的
│  │                                #   logo/defaults/footer 数据源）、BreadcrumbService、
│  │                                #   PaginationService；Discussion/ 域
│  │                                #   在此扩展（Tweet 已取消）
│  ├─ Modules/                      # ✅ 0.9.0：MediaModule——image-processor 包适配器（接管媒体库、
│  │                                #   惰性 Imagine 闭包注入、格式支持检查统一化）+「Image processor」
│  │                                #   设置页（aiya_core_image，13 字段；0.9.1 定名）；后续包适配器
│  │                                #   各自开功能页，不再共用统一页
│  ├─ Admin/
│  │  ├─ SettingsAdmin.php          # ✅
│  │  ├─ FieldRenderer.php          # ✅；control() 公开供元数据/资料页复用
│  │  ├─ MetaboxAdmin.php           # ✅ 0.7.0：post box / term box / user fields 渲染与保存
│  │  ├─ CoverMetabox.php           # ✅ 0.9.0：封面生成 metabox + AJAX（富交互控件，不走字段组 schema）
│  │  └─ PicBedPage.php             # ✅ 0.9.0：图床（0.9.1 起为主菜单项，upload-pics 池、不进媒体库
│  │                                #   与 uploads/、单次压缩落盘、不占媒体库 ID）
│  │  └─ SendMailPage.php           # ✅ 0.11.0：Send Mail 主菜单页——用户 HTML 邮件撰写（经典编辑器
│  │                                #   + 收件人选择），投递仅走 wp_mail() 交由 SMTP 插件接管；
│  │                                #   edit_users 权限 + 会话 nonce
│  ├─ Metadata/
│  │  ├─ Registry.php               # ✅ 0.7.0：addPostBox / addTermBox / addUserFields
│  │  │                             #    （+ PostBox / TermBox 值对象）
│  │  └─ Storage/                   # ✅ PostMetaStore / TermMetaStore / UserMetaStore
│  ├─ Infrastructure/
│  │  └─ Headless/                  # ✅ 0.3.0：HeadlessModule——无头化功能裁剪（区块编辑器/站点编辑器/
│  │                                #   定制器（外观菜单保留：主题切换/菜单管理，壳主题切换
│  │                                #   需要）/区块小工具/字体库与全局样式/区块样板/Pingback
│  │                                #   与Trackback/前台头部冗余/Emoji/oEmbed/XML-RPC）；评论存储
│  │                                #   与审核面保留（WP 后台治理，Astro 经 aiya 路由读写），
│  │                                #   /wp/v2/comments 无条件退役（0.29.0：不分匿名/登录态、
│  │                                #   不受开关约束，kill switch 随之删除）；开关存储在
│  │                                #   aiya_core_optimization（0.39.0 起改名并移除总开关，
│  │                                #   旧 aiya_core_headless 经 SchemaVersionRunner 迁移，
│  │                                #   各开关逐项独立生效）；已对照
│  │                                #   WP 7.1 源码逐钩子验证，普通插件即可实现全部裁剪，无需 MU
│  │  └─ Security/                   # ✅ 0.4.0：SecurityModule——REST users/sitemap users 移除、
│  │                                #   强制邮箱登录、后台角色门禁、登录页参数门禁、URI 探测拦截；
│  │                                #   用户名防护组按决定取消；AIYA Core > Security hardening
│  │                                # 其余（Media/ 等）有真实需求才建
│  └─ Http/                         # （M5 起并入 Api/Rest，不再单独设 Http/）
├─ packages/                        # ✅ 基础设施包目录（约定与批次见下节）；slug-toolkit（✅ 0.6.0）
│                                   #   与 image-processor（✅ 0.9.0，含字体/花纹素材）已接入；
│                                   #   opencc-convert（包体就绪，待适配器）
├─ assets/                          # ✅ admin.css / admin.js
├─ languages/                       # ✅ aiya-core.pot 已生成；.po/.mo 待译
├─ tests/
│  ├─ Unit/                         # ✅ 0.8.0：ValueNormalizer / Field / SchemaVersionRunner；
│  │                                #   0.9.0 + SaveOptions / WatermarkSpec / CoverSpec / Colors /
│  │                                #   FirstImageMatcher / ImagineAware（56 tests 126 assertions；
│  │                                #   tests/bootstrap.php 最小 WP 垫片，无 WP 环境可跑）
│  └─ Integration/                  # M2+：metabox 保存链路（wp-env 或 wp-cli 驱动）
├─ composer.json                    # ✅ dev 工具链 + path repositories（packages/*）+ phpunit
└─ docs/                            # ✅ ARCHITECTURE / MIGRATION / ROADMAP + 迁移评估两份
```

依赖方向（违反即架构错误）：

- Contract 零依赖；Presenter 是唯一 WP 数据触点；Rest 只调用 Domain 读服务与 Presenter，不查询数据；
- Schema/Normalization 不依赖 Admin 与 HTTP；Admin 依赖 Schema；存储适配器可依赖 WP 函数；
- packages/ 包不得反向依赖 core（不 require `aiya/aiya-core`、不调用 WP 函数、不挂 WP 钩子），由 `Modules/` 适配器单向接入。

## 三、基础设施包约定（packages/）

替代旧主题 `plugins/` require 加载结构。每个子目录一个独立 composer 包：`aiya/<slug>`、`type: library`、PSR-4 `Aiya\Infra\<CamelName>\`，自带 composer.json（php>=8.2 + 自身三方依赖，随根仓库腾讯镜像解析）。core 侧 `Modules/<Name>Module.php` 适配器实例化包服务、把包配置注册进**该功能自己的设置页**（旧 extra-plugin 单页分区结构明确不继承，如 image 包 →「Image processor」页），并挂入 Module 系统。

迁移批次（按旧 plugins/ 耦合度探查结论，随落地更新）：

1. **第一批**：`opencc-convert`（tracer 包已建，Converter + locale 策略映射，待接入适配器）；`multi-domain` ❌ 弃用（2026-09-08 拍板：前后端分离后无用）；`internal-pic-bed` 已改为 core 内页面（0.9.0 `Admin/PicBedPage`），不再做包
2. **第二批**：`image-manager` ✅ 0.9.0 → `aiya/image-processor` 包 + `Modules/MediaModule` 适配器；`classic-editor-modify` ❌ 弃用（2026-09-08 拍板）
3. **basic-optimize** 组件不改造成包，直接变成 core 的 Domain/Infrastructure 模块（安全/SMTP/SEO/头像各归其位）
4. **最后**：`sponsor-order-compat`、`patch-flow-hub-post` 重写；`gdluxx-dl` 空目录弃

## 四、里程碑

### M1 设置框架收尾 —— ✅ 已完成（0.8.0）

- ✅ `note` 字段（info/success/warning/error 变体，正文取 description、label 兜底）与 `heading` 字段（level 1-3 分组标题），两者均为非持久字段（`Field::isPersistable()`，ValueNormalizer 跳过、渲染走 WP 原生 notice 样式）；Headless 页已分组消费（三组标题 + 顶部警告）；
- ✅ `Settings/Options/OptionsResolver`：`options_source => ['source' => 'terms|posts|users', ...]`，Schema 校验来源形状（terms 需 taxonomy、posts 需 post_type），渲染前惰性求值（不查询直到渲染）；select/radio 静态 options 优先、有 source 时回退解析；旧 `sub_mode` 的 page/category 用法映射为 posts/terms 源；
- ✅ `aiya_core_opt()` 门面（0.3.0）；`Registry::addFields()` / `Page::appendFields()`（0.5.0）；`FieldRenderer::control` 转公开（0.5.0）；
- ✅ `tests/Unit`：phpunit ^11 + `tests/bootstrap.php`（最小 WP 垫片 + 插件 autoloader 镜像），覆盖 ValueNormalizer（24 断言级）/ Field / SchemaVersionRunner，25 tests 46 assertions 全绿；`composer php:unit`；
- ✅ 工具链（0.3.0 起）：phpstan 2 @ level 8、wpcs 3 + PHPCompatibilityWP、parallel-lint、wp-cli i18n-command；`.pot` 已生成；
- ⏳ 剩余（延后）：以旧 `opt-basic.php` 真实字段集重建「站点」页（验收动态选项 + note/heading 的完整实战）；zh_CN .po/.mo 翻译。

### M2 元数据注册表与内容类型注册 —— ✅ 已完成（0.7.0，代码能力，无 ACF 式界面）

**M2a 字段组（替代旧 `new_box`/`new_tex`）** ✅：

- `Metadata/Registry` + `Metadata/PostBox`/`TermBox` 值对象：`addPostBox(['id','title','screens','context','priority','template','fields'])`、`addTermBox(['id','taxonomies','fields'])`、`addUserFields(['fields'])`；字段复用 Settings 的同一套 `Field` schema，`action_checkbox` 类型已实现（保存时触发 `action` 设置指定的钩子，值不落库）；
- `Admin/MetaboxAdmin`：post box 渲染进编辑屏（context/priority/页面模板条件），term box 渲染进添加/编辑表单（div/tr 两种结构），user fields 进资料页；渲染统一走 `FieldRenderer::control`（公开），保存统一走 `ValueNormalizer`（phpcs 探针确认 nonce/capability 逐 box 校验）；
- 存储沿协议：post box 组键 `aya_box_{id}`（单键全组），term/user 逐字段 meta 键；
- 第一刀 `Domain/Content/SeoBoxModule`：`post_seo` box（seo_keywords/seo_desc，协议键 `aya_box_post_seo`）；
- 运行时验证：渲染含字段与 nonce、保存写协议键、action 触发且不落库、term/user 保存链路全通过。

**M2b 内容类型注册（替代旧 `Register_Post_Type`/`Register_Tax_Type`）** ✅：

- `Domain/Content/PostTypeDefinition` / `TaxonomyDefinition` / `ContentTypeRegistry` / `ContentTypeModule`：领域模块代码声明，注册器在 init 5 统一注册；
- 无头默认值：`show_in_rest => true`、`rest_base => slug`、supports 显式默认不含 comments、context/priority 白名单校验；
- 旧版置顶归档 `the_posts` hack、`__destruct` 注册不迁（前台行为/坏味道）；
- 运行时验证：CPT 注册（REST on）、层级分类法注册。

### M3 运行时硬化 —— ✅ 已完成（0.8.0，生命周期随 0.2.0 前移）

- ✅ `Runtime/SchemaVersionRunner`：`aiya_core_schema_migrations` 过滤器注册迁移（`['version', 'callback']`），按版本升序执行，失败中止且不推进 stored 版本（错误记入 `aiya_core_last_migration_error`）；`aiya_core_schema_version` 在 init 1 自动对齐当前版本；单测覆盖（升序执行 / 当前跳过 / 旧迁移不跑 / 失败中止保留版本）+ 实测 0.7.0→0.8.0 自动推进；
- ⏳ `SampleSettings` 降级策略（WP_DEBUG 或常量开关下注册）：留给站长确认可见性行为时执行；
- ⏳ 伪造升级路径的 Integration 测试已由单测覆盖，无需额外脚本。

### 媒体栈迁移 —— ✅ 已完成（0.9.0）

旧 `plugins/image-manager`（重型包：封面绘制 + 缩略图生产 + 媒体库接管）与 `plugins/internal-pic-bed` 的重构落地，按审查结论修正四类缺陷（字体路径判断反转、水印透明度语义反转、格式支持检查接错路径、缩略图缓存键缺质量参数）：

- ✅ `packages/image-processor`（WP-free，PSR-4 `Aiya\Infra\ImageProcessor`）：`WatermarkSpec` / `CoverSpec` 纯数据模型、`ThumbnailGenerator`（cover-crop + 比例差过大时模糊底双层渲染）、`CoverGenerator`（photo/pattern 两模型 + 反色衬底标题）、`UploadApplier`（限宽→水印→格式转换，转换删源）、`FirstImageMatcher` 纯正则工具、`SaveOptions`（旧三处重复的保存参数表合一）；**Imagine 能力闭包化**——`ImagineAware` 构造器接受 `ImagineInterface|Closure` 惰性解析；`ImagineFactory` 修复旧缺陷（GD 分支只查 class_exists）并加 Imagick 委托健康探测（Docker 镜像缺 PNG delegate 的运行时自动回落 GD）；字体（752K）与花纹素材（4.6M）随包自带（`Assets::fontFile()/patternDir()`），旧默认值不再指向弃用主题；
- ✅ `Modules/MediaModule` 适配器：设置页（0.9.1 定名「Image processor」`aiya_core_image`，13 字段，新键名、语义 1:1；水印不透明度改为 Imagine 同向语义 0 透明→100 不透明，默认 80）；`wp_handle_upload` 接管（`image_take_over_uploads`）；`uploadProcessor()` 以闭包暴露管线供 Admin 控制器组合；惰性组装 `Domain/Media` 两个服务；
- ✅ `Admin/PicBedPage`：富交互封面生成控件之外，图床组件独立成页；0.9.1 修订——删除旧假插件结构遗留的图床设置字段（开关/大小改常量）、页面入口提升为主菜单项、修复 jQuery FormData 上传（`processData/contentType` 必须关闭）、页面说明明确「不进媒体库与 uploads/、单次压缩落盘」；
- ✅ `Domain/Media`：`MediaPaths`（URL/绝对路径/content 相对路径三种入参 → content 目录内本地文件，外部 URL 一律 null）；`ThumbnailService`（缓存键含质量，命中复用；**只读服务不写 meta**——旧版前台渲染时写库的反模式移除）；`CoverService`（photo 模式取特色图→正文首图、无本地背景自动降级 pattern；结果写 `_aya_thumb` 协议键，content 相对路径优先、完整 URL 兜底——该键的唯一写入方）；
- ✅ `Admin/CoverMetabox`：富交互封面生成控件（模式/标题/颜色/预览 + 异步 AJAX），超出字段组 schema 表达力故为 bespoke metabox，原生 admin 样式；`Admin/PicBedPage`：图床页面（upload-pics/YYYY/MM 池、finfo 真实类型 + mime 白名单定扩展名 + 随机文件名、经管线重编码消毒、列表页），路径寻址不占媒体库 ID，短码/HTML 输出随旧前台退役；
- 验证：单测 56/126 全绿（包纯类），phpstan L8 + phpcs 全绿；wp-cli 运行时 18 项验证全过（PNG→JPG 转换、缩略图缓存复用、photo/pattern 封面、`_aya_thumb` 形状、pic-bed 子菜单、外部引用 null 语义），测试数据已清理。

### M4 数据契约与内容读取层（契约优先，前移）

**契约权威与批次计划（2026-09-06 定，2026-09-08 修订）**：DTO 清单以前端契约 `aiya-astro-bulid/src/lib/aiya/contracts.ts`（v1，camelCase + `{data, meta}` 信封）为对照基准，落地语义见 [AIYA-astro DATA-MAP.md](../../../../aiya-astro-bulid/docs/DATA-MAP.md)；批次顺序 A0 契约对齐 ✅（0.13.0：后端信封/camelCase + 前端认证接线完成）→ B1 站点骨架+文章读取层 ✅（0.18.0：ContentQuery/MenuService/PostPresenter + /site /menus/primary /terms /posts /posts/{id}，DTO 与前端契约对齐）→ B5 公开作者页 ✅（0.19.0：GET /profiles/{slug}，favorites/membership 协议键兼容读，无 email/登录名泄漏）
- **C1 评论端点 ✅（0.20.0）**：GET+POST `aiya/core/v1/content/{id}/comments`——平铺列表（parentId、作者名+头像、纯文本 body、标准分页）+ 走 `wp_new_comment` 经典管线写入（preprocess_comment/flood/去重/审核判定全生效，响应带 approved/held 状态），匿名身份沿讨论设置、登录会话预填，覆盖 post/page/resource 三型；旧计划「评论走 /wp/v2/comments 原生路由」由此改道，wp-json 公开面完成全自有化前置。
- **C2 页面与资源端点 ✅（0.21.0）**：`PublicType` 配置对象（每类型的 WP post types、前端 URL 形状、WP→契约分类法映射）参数化 ContentQuery/PostPresenter/路由对——`/pages`、`/pages/{id}`、`/resources`、`/resources/{id}` 与 `/posts` 共享同一代码路径；`/terms` 增 `type` 参数（resource_category 对契约答 category、五个扁平资源分类法答 tag）；三型皆仅 publish 可见、越权类型 404。
- **C3 公开面封锁 ✅（0.22.0）**：Security 设置页新开关（默认开）——`rest_endpoints` 过滤器对无后端会话的访客剥掉 `aiya/core/v1` 之外全部路由（含我们自注册 CPT 在 /wp/v2 的自动展开），`rest_index` 同步收敛命名空间列表；豁免条件 `current_user_can('edit_posts')`（cookie 会话与应用密码 basic 认证保留全量 API，前台 bearer 访客仍限于契约）。**环境前置修复**：wp-config 补 `WP_ENVIRONMENT_TYPE=local`（卷内固化配置），应用密码在 HTTP 下恢复可用——这也是 Astro live 模式 basic 认证的先决条件。wp-json 公开面由此完整闭环：对外只有自有契约 API。——**M4 原生批次至此全部完成**。**2026-09-08 站长拍板**：旧 Tweet 域**取消**（不迁移、不做兼容，旧数据当死数据）；Discussion 域**重启**——以旧 Issue 原型重建为线程形轻社区（见下方 B2 小节，同日二次拍板）；Topic 域取消——「专题」重定义为**分类聚合模板**（无独立域/端点；标签聚合不沿用，计划改标签云页）；资源域数据源拍板为 **resource CPT**（先行重设计该类型 metabox，B3 才开放；重设计范围含 OpenList 嵌入块迁移——配置沿 postmeta 组键协议 `aya_box_oplist_client`、不建表，作用面 post→resource，详见 MIGRATION.md）；`/home` 聚合（B4）回归条件随之只剩 B3。前端契约修订（contracts.ts 移除 Topic/Discussion、`/topics` 改分类聚合、`/community` 退役、新增标签云页）随 B3 前端批执行。用户域批次（0.12.0）已完成。

原 M4 清单（保留作 DTO 语义蓝本），DTO 清单直接翻译旧 `inc/core` 的 `*_In_While` 属性表（见工作区 AGENTS.md 的结构说明），并剥离其展示逻辑（K 格式化、timeago、本地化兜底文案、分页 CSS class、菜单 HTML 构造器）：

- `Api/Contract/`：`PostSummary`（id/url/title/type/dates+ISO/excerpt/preview/thumbnail/views/likes/评论数/分类标签/作者摘要）、`PostDetail`（增 content HTML、prev/next、gallery）、`TermDto`（补齐旧版 parent/children 未 DTO 化的不对称）、`AuthorDto`、`ThumbnailDto`、`MenuTree`/`MenuItem`（label/url/target/object/type/children/active）、`Pagination`（standard + simple 两形态）、`Breadcrumb`（`{label,url}[]`）+ 契约版本常量；
- `Presenter/`：WP 对象 → DTO 映射；`the_content` 过滤器在此执行（content HTML 是契约数据）；修复旧 `get_post_views/likes` 缺 property_exists、`WP_Term::get_term()` 布尔优先级两类旧 bug（新实现不引入同类路径）；
- `Domain/Content/`：`ContentQuery`（封装旧 WP_Query 的预设查询集合）、`NavigationModule` + `PrimaryMenu`（0.28.0：导航设置页 Primary menu / Secondary menu 两组 repeater 自增行 → `Contract\MenuItem`，路由 `/menus/primary` 与 `/menus/secondary` 的 `location` 镜像组键；替代曾按旧蓝本落地的 `MenuService`——2026-09-09 拍板弃用 WP 菜单系统后删除）、`FrontendModule`（0.29.0：前台壳配置设置页，`GET /site` 的 logo/defaults/footer 数据源）、`BreadcrumbService`、`PaginationService`；
- `Modules/` 适配器：image 包的「Image processor」页已落地；opencc-convert 适配器在此追加（独立功能页）；
- 验收：读服务产出 DTO 的形状有单测锁定；Astro 侧可直接按 Contract 生成 TS 类型（M5 才生成）。

### M4 用户域批次 —— ✅ 已完成（0.12.0，Contract + Presenter + REST 打样）

用户域是 M4 第一个落地切片，REST 层随本批次提前进入（内容控制器仍留 M5）：

- **契约**（`Api/Contract/`，零 WP 依赖）：`UserProfile`（id/username/nickname/email/url/description/locale/registered_at ISO8601/role/avatar）、`AvatarImage`（url + thumb_url，版本参数已含在 URL 内）、`AuthSession`（token/token_type/expires_at/expires_in/user）+ `Contract::VERSION` / `API_NAMESPACE` 常量；DTO 均 `toArray()` 锁形，单测锁定；
- **Presenter**：`UserPresenter` 唯一触碰 WP_User；role 沿旧版 `aya_user_toggle_level` 语义（administrator/author/sponsor/subscriber，赞助有效性读协议键 `sponsor_expiration` + `aya_force_cancel_sponsor`）；
- **认证**：不透明 Bearer 令牌（`{userId}.{secret}`），HMAC-SHA256 哈希存 user meta（键 `aiya_core_auth_tokens`，非协议键），`determine_current_user` 过滤器接入、cookie 会话不受影响；TTL 沿 WP cookie 语义（remember 14 天 / 2 天），改密/重置全吊销，登出吊销当枚；公开认证端点带 transient 固定窗口限流（login 20/10min、register 5/h、reset 5/15min，超限 429）；
- **路由**（`aiya/core/v1`）：`POST /auth/register`（表单沿旧版：昵称+邮箱+密码+确认；**登录名由后台生成 UUID**（`wp_generate_uuid4`），前端只交昵称；`users_can_register` 关闭时 403；成功即签发长会话）、`POST /auth/login`（**仅邮箱登录**，`wp_authenticate_email_password`，错误不区分邮箱/密码）、`POST /auth/logout`、`POST /auth/password-reset-request`（`domain` 参数=前台自报来源，链接 `{domain}/reset-password?login=&key=`，来源只保留 scheme+host+port、可经 `aiya_core_password_reset_allowed_hosts` 过滤器加白名单，非法来源回退 site_url；响应不区分邮箱存在与否）、`POST /auth/password-reset/validate`、`POST /auth/password-reset`（复用 WP 原生 `get_password_reset_key`/`check_password_reset_key`/`reset_password`，key 一次性、24h 有效）、`GET /users/me`、`POST|PATCH|PUT /users/me/profile`（nickname/description/url/email/locale，locale 白名单 zh_CN/zh_TW/zh_HK/en_US 沿旧版）、`POST /users/me/avatar`（multipart `avatar` 字段，复用 `AvatarModule::storeUploadedAvatar`）、`DELETE /users/me/avatar`、`POST /users/me/password`（需当前密码，改后所有会话失效）；
- **错误形状**：WP 标准 `{code,message,data:{status}}`，业务码 `aiya_*`；成功载荷纯数据（旧版面向展示的 message/redirect 字段不进契约）；
- 验证：单测 66/144 全绿；wp-cli + curl 运行时全链路实测（注册→登录→me→资料→赞助 role 语义→头像（协议键形状/128+64 文件/?v= 版本）→改密吊销→找回邮件捕获→validate→reset→新密码登录→登出吊销→key 复用 400→注册关闭 403→未授权 401/409/429 分支），测试数据已清理。

### 前台壳配置与评论路由退役 —— ✅ 已完成（0.29.0）

「设置→前台组件」映射补全（此前 /site 只有站点身份五件套、/menus 是唯一的设置驱动端点）+ 评论对外面收敛为纯 aiya 路由（拍板：已无任何 /wp 路由依赖计划）：

- ✅ **Frontend 设置页**（`Domain/Content/FrontendModule`，option `aiya_core_frontend`，父菜单 aiya-core-sample）：品牌 logo（media 存附件 ID——customizer `custom_logo` 降级为兜底，修复 `disable_appearance` 拆掉 customizer 后 Site.logo 永远填不上的缺陷）、外观默认值（`default_color_mode` system/dark/light 沿旧 opt-basic 语义、`default_thumb` 站点级兜底封面）、合规页脚（`icp_beian` / `mps_beian` / `mps_code` 公安查询链接用纯数字段 / `footer_note`，旧 opt-basic 合规语义照搬键意）；
- ✅ **`GET /site` 扩展**（向后兼容加字段）：`defaults: {colorMode, thumb}` + `footer: {icp, mps, mpsCode, note}`；新契约 `SiteDefaults` / `SiteFooter`（零 WP 依赖 + 锁形单测）；`SitePresenter` 组装（附件 ID → full 尺寸 Image，alt 取附件题名缺省站点名；colorMode 白名单校验）；front-station `contracts.ts` siteSchema + mock 同步（页面消费随前端接线批）；未配置时输出兜底值（logo null、system、全空串），每页 SSR 的 /site 新增成本仅 2 次 attachment 查询，不加缓存层（HTTP 缓存头留 M5）；
- ✅ **评论路由退役**：Headless kill switch（`disable_comments`）整套移除——其 stripComments 的 comments_open 强关 / post type support 移除本会打断 aiya 评论端点的 `wp_new_comment` 管线（CommentsController 自检 comments_open），属负资产；`/wp/v2/comments` 改为 `filterRestEndpoints()` **无条件剥离**（不分匿名/登录态、不受任何开关控制）；评论存储 + 后台治理屏（edit-comments.php）保留；与 `lockPublicSurface`（0.22.0，管非契约命名空间对访客关闭）互补；
- 验证：单测 119/283 全绿；运行时实测（/site 新字段全通路含附件解析与中文页脚、`/wp/v2/comments` 匿名与 author bearer 双态 404 而控制组 `/wp/v2/users/me` 200、aiya 评论路由 GET/POST 200 + `comments_open` 未被过滤）；phpstan 抓出并修复 IdentityModule 表自检 `RuntimeException` 缺全局前导反斜杠（命名空间下解析为不存在类，自检触发即 fatal）。

### 契约减负：Profile.banner 砍除 —— ✅ 已完成（0.35.1）

2026-09-11 拍板：banner 是「个人主页横幅」概念，WP 原生无此数据源（用户只有头像），前端连 profile 页都未建——恒 null 无消费方，直接砍除（Profile 契约/Presenter/前端 zod/快照），将来做个人主页横幅时加字段属向后兼容。

### Follow 端点 + 零件渲染语义定稿 + featured 字段 —— ✅ 已完成（0.35.0）

锁定前三项收尾（2026-09-11 拍板）：

- **Follow 端点**（`Domain/Identity/FollowService` + UserController 五路由，表 0.28.0 就绪）：`GET /users/me/following`（我关注的人，Author 数组分页）、`GET /users/me/followers`（我的粉丝）、`POST|DELETE|GET /users/me/following/{userId}`（关注/取关/是否关注）；自关 400、目标不存在 404、写路径限流 30/600；列名走 `%i` 占位符零插值。实测六态全中（自关 400/关注/isFollowing/列表含作者投影/followers 匿名 401/unfollow）；
- **零件语义定稿**（拍板：**不做结构化解析批**）——后台把注册的零件渲染为**自定义 HTML 标签**（PartType 增可选 render 钩子，PartModule 在 init 11 把带渲染器的零件注册为真短代码），content HTML 携带自定义标签，**前端自行解析标签挂载岛屿**。实测：过滤器注册带渲染器的零件 → shortcode 注册 → do_shortcode 输出 `<aiya-notice level="warning">…</aiya-notice>`；目录默认空，渲染器随零件定义批出现；
- **featured 字段**：`PostDetail.hero` 更名 `featured`（特色图 full 原图直出，用途归前端；契约/WIRE_SHAPES/zod/infra 夹具/帖子页消费全同步）；壳主题 aiya-headless 激活 `add_theme_support('post-thumbnails')`（特色图是编辑面而非主题特性），resource CPT supports 原本已含 thumbnail。Profile.banner 维持保留 null（按用户概念无 WP 原生来源）。实测 featured 输出特色图 URL、无 hero 残留。

### 排版工具（post_automatic 重建）—— ✅ 已完成（0.37.0）

旧版 basic-optimize "数据更新" box 的重建（2026-09-11 拍板重新实现）：

- **`packages/typesetting/`（新包，aiya/typesetting，MIT 归因）**：jxlwqq/chinese-typesetting 原样移植（仅改命名空间 `Aiya\Infra\Typesetting` 与词典路径），含 477KB 专有名词词典；正确方法白名单 `ChineseTypesetting::METHODS`（11 种）；
- **`Domain/Content/ContentFormatter`**：格式清理（全角空格/&nbsp; 移除、div/center→p、strong/b 重叠清理、span/section 剥离——旧版 light_insert_data_re_* 四步的 null 安全重写）+ 标签匹配（strpos 旧语义）纯函数；
- **`Domain/Content/TypographyModule`**：post 编辑屏 `typography` box 四个 action_checkbox（重置发布日期/自动检索标签/格式清理/中文排版纠正），busy 闸门阻断 save_post 重入；中文排版纠正方法子集在 Optimization 页 `typography_methods`（array 字段，缺省 insertSpace/removeSpace/full2Half，白名单求交）；旧版别名拼音生成不在本批（0.6.0 SlugModule 已覆盖）；
- 实测：格式清理（div/span/strong 全处理）、insertSpace 生效、标签检索附加、日期刷新；单测 137/310、phpstan、phpcs 全绿。

### 后台 i18n 简体中文 —— ✅ 已完成（0.36.3）

- POT 重建（548 条，覆盖至 0.36.2 全部源串），全量翻译 547 条生成 `languages/aiya-core-zh_CN.po`，容器内 `wp i18n make-mo` 编译 `.mo`（.mo 属构建产物不入库，按需由 .po 重编译）；样板/POT 入库；
- **装载修复**：WP 7.1 的 `load_plugin_textdomain` 不再回退插件本地 languages 目录——Plugin.php 改为优先直载 `AIYA_CORE_PATH/languages/aiya-core-{locale}.mo`（`determine_locale` 解析），core 调用保留为兼容网；
- **标签时序修复**：ContentTypeModule 的 CPT/分类法 label 原在插件文件加载期（翻译装载前）经 `__()` 固化为英文——定义整体延迟到 init 优先级 4（翻译后、registerContentTypes 的 5 之前）。实测 Resource→资源、Page categories→页面分类、resource_original→原作；
- 实测：站点语言 zh_CN 下后台字符串输出中文（模板零件/安全加固/设置已保存）；单测 127/299、phpstan、phpcs 全绿。

### 后台菜单重组 —— ✅ 已完成（0.36.2）

Frontend 设置页升为插件根菜单（菜单名 AIYA Core，前台设置即首项）；全部生产页 parent 换绑 `aiya-core-frontend`；Headless optimization 菜单名简化为 **Optimization**；Sample 拆为独立一级菜单（标题 Sample，仅 `WP_DEBUG` 开启时注册——生产环境不加载），位置 100 沉底。实测注册序：frontend(根) → Optimization → Security → Navigation → Image，WP_DEBUG=false 下 sample 不出现。

### /site 补 favicon 与 registrationOpen —— ✅ 已完成（0.36.1）

锁定后首个加法演进实例（合规示范）：`Site` 契约新增 `favicon: ?Image`（镜像 WP 设置→常规的站点图标，`site_icon` 附件经 Presenter 解析）与 `registrationOpen: bool`（镜像 WP 成员资格设置 `users_can_register`——前端据此决定是否显示注册入口；当前 WP 默认关闭=false，站长在设置→常规开启即变 true）。v1 基线断言通过（加法合规），前端 zod/mock/client 夹具同步，双侧测试全绿。

### 契约 v1 锁定 —— ✅ 已完成（0.36.0）

2026-09-11 站长确认锁定。**v1 契约面**：37 条活动路由（认证 6 / 用户域 12 含 follow / 内容壳 4 / 内容读取 6 / 评论 2 / 计数 3 / 社区 5 / 通知 1——哦按实际计数）与 25 个 Api/Contract DTO。冻结政策三句：

1. **线形冻结**：字段集/类型/可空性不再变——执法工具为 `contracts.snapshot.v1.json` 基线 + 前端 vitest 加法演进断言（基线字段被删/改类型/改可空即测试红）；
2. **只允许加法演进**：新字段、新端点、预留字段填充（Profile.activities/stats、activities 数组）均合规；
3. **破坏性变更必须升版**：开新命名空间或契约版本策略，v1 内不发生。

**约定入册**：更新类端点沿用 WP 的 `EDITABLE` 动词集（POST/PUT/PATCH 等价，core 惯例，非意外冗余）；reserved 字段清单（Profile.activities/stats.activities 恒 0/空、按需填充）；赞助/附件域路由停用中，重启用时按「域重新验收」回锁，不在本锁范围。调试台（swaggerui，命名空间已配 aiya/core/v1）与快照命令随契约演进常规使用。

### 评论改登录-only —— ✅ 已完成（0.34.2）

2026-09-11 拍板简化：评论**仅限登录用户**，账户墙即反垃圾层——0.34.1 的 honeypot 字段与匿名身份（authorName/authorEmail）校验路径整体移除，`comment_registration`/`require_name_email` 设置不再消费。POST 契约收缩为 `{body, parentId?}`；匿名 401 `aiya_login_required`，作者身份取会话 display_name/email；原生管线（重复/泛洪 429/禁词/审核决策）保留。实测：匿名 401、订阅者首评 200 held。

### 评论加固 —— ✅ 已完成（0.34.1）

M5 收尾项。原生防线经 `wp_new_comment` 已全部生效（重复/泛洪/禁词名单/链接数审核 `comment_max_links`/审核决策/老评论者白名单/`comment_registration`/`require_name_email`），本批补齐路由层缺口：

- **honeypot**：POST 契约新增 `website` 参数——前端评论岛须渲染一个隐藏输入（人类不填），任何值 → 400 `aiya_honeypot`；
- **泛洪映射**：native `comment_flood`（同作者 `comment_flood_threshold` 秒内连发）从误映射的 409 改为 429 `aiya_comment_flood`；重复保持 409；
- 实测四条防线：首评 held、同文 409、快速连发 429、蜜罐 400。

### 模板零件框架（编辑器侧）—— ✅ 已完成（0.34.0）

旧 Thickbox 短代码输入器的重构替换（2026-09-11 拍板：**只迁框架，不迁 12 个旧短代码组件**——旧组件是服务端 Tailwind HTML 渲染，与新「零件结构化、Astro 渲染」语义不合，目录默认为空）：

- **`Domain/Parts/`**：`PartType`（tag/label/note/template/fields 声明；`build()` 从原始值组装零件标记——属性对空值省略、checkbox 归一 true/false、属性值 HTML 属性级转义而 content 逐字、自闭合模板忽略 content、未声明键永不进入标记；7 个单测锁形）+ `PartRegistry`（目录默认空，`aiya_core_register_parts` 过滤器注册，重复 tag 抛异常）；
- **`PartModule`（编辑器侧）**：`media_buttons` 钩子原位输出「Template parts」按钮（经典编辑器工具栏原位置）；post.php/post-new.php 的 admin_footer 输出弹窗骨架 + bootstrap JSON（全量零件定义）；`wpdialogs`（core 链接弹窗同款 jQuery UI Dialog 封装，符合管理界面技术白名单）打开弹窗——左列零件、右侧 JS 按 fields schema 渲染控件（text/textarea/select/checkbox）+ 实时标记预览；插入走 `window.send_to_editor`（TinyMCE 与 QuickTags 双通道，与旧实现同通道）；资产 `assets/js|css/parts-dialog.*`；
- **API 解析输出另批**：`the_content` 后的短代码 → 结构化零件解析器（PartParser/PartPresenter，content + parts[] 契约）为独立批次，落地前短代码在 content HTML 中原样存在；
- 实测：默认目录 0、过滤器注册后 build 输出形状正确（`[alert name="Hi"]Body text[/alert]`）、编辑器按钮渲染；单测 126/292、phpstan、phpcs 全绿。

### 契约减负：PostDetail.gallery 砍除 —— ✅ 已完成（0.33.1）

2026-09-11 拍板：gallery 字段非旧主题遗产（旧 DTO 原型无此字段，系 B1 预留槽位），恒空数组无消费方，且正文图已由 content HTML 承载——直接砍除（PostDetail 契约/Presenter/WIRE_SHAPES/前端 zod/infra 测试夹具），将来需要文章相册交互时加字段属向后兼容。快照重生成，双侧测试全绿。

### M5 上线件：CORS 收紧 + HTTP 缓存 + 契约类型同步 —— ✅ 已完成（0.33.0）

- **CORS 白名单**（`Api/Rest/CorsHeaders`）：移除 core 的 `rest_send_cors_headers`（其无条件回显任意 Origin 且 `Allow-Credentials: true`；注意 core 在每次 `rest_api_init` 优先级 10 重新挂载，移除须挂同 hook 优先级 20），改为 Security 设置页 `rest_allowed_origins` 白名单（+ `aiya_core_rest_allowed_origins` 过滤器）——默认空 = 零 CORS 头，命中白名单才回显 Origin（GET/POST/OPTIONS、Authorization+Content-Type、Max-Age 600、无 credentials）。非白名单 origin 下 core server 类仍会输出 Expose-Headers/Allow-Headers 元数据头，但无 Allow-Origin 即无任何跨域授权。范围仅契约命名空间；
- **HTTP 缓存分层**（`Api/Rest/HttpCache`，rest_pre_serve_request 优先级 20）：仅 200 的契约 GET——shell（/site、/menus/*、/terms）`public, max-age=300`；列表（/posts|/pages|/resources）`public, max-age=60`；其余公开 GET（详情/社区/profiles）`public, max-age=0, must-revalidate`；/users/*、/notifications `private, no-store`；非 GET 一律 `no-store`。`ETag = sha1(data 部分 JSON)` 截 32——requestId 保持随机不进哈希；If-None-Match 命中 → `status_header(304)` 空体返回。前端消费侧随 Astro 接线批；
- **契约快照同步**：wp-cli `wp aiya contracts snapshot [--out=]` 反射 Api/Contract 全部 DTO（构造器 promoted 属性名/类型/可空；PostDetail/DiscussionDetail 为 array_merge 扁平线形，手工声明 WIRE_SHAPES——Discussion 的 replies 键被线形数组覆盖），输出排序稳定 JSON；front-station 提交 `contracts.snapshot.json` 并在 vitest 中逐 DTO 比对 zod schema（字段集/可空性/数组对象结构，双向覆盖）。首跑即抓出 7 处真实漂移并全部修复（authSession 剔除 tokenType/expiresIn、discussionDetail 改 thread 组合形（replies 键被线形数组覆盖）、membership 映射 membershipBadge、postMetrics/seo/siteDefaults/siteFooter 提为独立导出 schema）；
- 验收：CORS 三态（未配置无头/命中回显/预检 200）、缓存五档头与 304、快照生成 + `npm test` 106 全绿；后端 119/283、phpstan、phpcs 全绿。

### 图片处理器：卡片缩略图管线 —— ✅ 已完成（0.32.0）

2026-09-11 站长拍板的图片语义定稿：`_thumb` = 卡片缩略图缓存（640×360 常量写在 CardThumbnailService 顶部，不在正文页复用故单一尺寸）；特色图保持 thumbnail 链第一优先（设计保留），同时作为独立字段输出、用途归前端判断；水印只在上传管线：

- **`Domain/Media/CardThumbnailService`（新）**：读/写同一逻辑——`resolveFor`（不生成）：`_thumb` 合成图 → 实时源（特色图→正文首图，cron 未覆盖前直接给源 URL）→ Frontend 设置默认图占位 → null；`generateFor`（cron 半边）：本地源 → 包内 ThumbnailGenerator——忠实沿用旧版 image-manager 配方：比例接近时单层 cover-crop 居中裁切；比例差过大（log 差 ≥ 0.35）时双层渲染（背景 cover-crop 满画布 + 高斯模糊 16 + 白色 55/100 遮罩，前景 contain 等比缩放居中叠加）→ 落盘 `thumbnail/cover/` + 回写 `_thumb`；`pendingIds` 批量队列（post/page/resource 中无 `_thumb` 的公开文，新文优先，批 10 篇）；
- **异步化**：MediaModule 注册 `aiya_core_thumbnails_generate`（自定义五分钟档，`aiya_core_scheduled_events` 纳管停用清理），列表页零内联生成、杜绝首页大量图超时；
- **尺寸统一**：CoverService 编辑器封面 800×450 → 640×360（同一常量），与自动缩略图同尺寸；
- **契约**：`PostDetail` 新增 `hero: ?Image`（特色图 full 原图，标题大幅背景用，用途归前端），PostSummary 不变；front-station zod 同步；（0.32.1 修正：初版误接 CoverGenerator 空标题合成，恢复旧版 ThumbnailGenerator 模糊双层配方——0.9.0 时已 1:1 移植）；
- 实测：cron 批次生成合成图并回写 `_thumb`（含 1:1 源触发模糊合成分支）、REST thumbnail/hero 双字段输出、无图文章落默认占位、有图未跑 cron 时实时源回退、cron 后切换为合成图；单测 119/283、phpstan、phpcs 全绿。测试数据已清理。

### 持久化命名整改 —— ✅ 已完成（0.31.0）

命名审计的拍板落地（2026-09-11 站长逐项拍板：rating_score/rating_count 与 like_count/view_count 保持原状继续使用；wp-content/thumbnail/ 目录名不动）：

- **`_aya_thumb` → `_thumb`**：自动生成值非旧主题遗产，CoverService 写入方 + PostPresenter/CoverMetabox 读取方全量更名，旧键成死数据不做兼容读；
- **metabox 组键 `aya_box_{id}` → `aiya_core_{id}`**：PostBox 组键模式与 post_seo/oplist_client 两处字面量更名，区别旧版避免搜索混淆；旧键死数据；
- **头像目录并入 thumbnail 树**：`wp-content/avatars/{user_id}/` → `wp-content/thumbnail/avatars/{user_id}/`（avatarsDir + content_url ×2 + `basic_user_avatar` 值形状 full 路径），旧路径与旧值形状不再兼容；
- **`aiya_auth_tokens.expires_at` INT → DATETIME**（0.31.0 迁移：ADD COLUMN + FROM_UNIXTIME 回填 + DROP + CHANGE，列类型自检失败 runner 保持版本重试），TokenStore 读写全部换 GMT DATETIME（issue/resolve/裁剪/全局清理）；
- **`aiya_notifications.role_level` → `min_role`**（0.31.0 迁移 CHANGE COLUMN + 列自检），NotificationService/NotificationPage 全量更名——「最低可见角色」语义归一；
- **oplist 对象缓存裸键 `token` → `oplist_server_token`**（纯代码，随缓存自然过期）；
- 实测：迁移自动推进 0.30.0→0.31.0、expires_at=datetime 与 min_role 列就位、DATETIME 列上令牌签发/解析、头像落新树且 meta 值形状更新、通知创建与游客拉取；单测 119/283、phpstan、phpcs 全绿。测试数据已清理。

### 安全审计与报错整改 —— ✅ 已完成（0.30.0）

三线审计（安全/错误一致性/命名）后的整改批；命名整批留待下一轮：

- **安全**：密码重置链接域白名单默认收紧——站点自身 host 恒可用，其它前端 host 需在 Security 设置页 `password_reset_allowed_hosts`（新增 array 字段）或既有过滤器显式配置，伪造域名永收不到含 key 的活链接（实测异域回退 home_url）；`MediaPaths` 内容目录检查加 realpath 归一化（`../` 与符号链接逃逸失效）+ `relativePath` 拒绝 `..` 段；`_aya_thumb` meta 只有能解析回 content 目录的值才进 API，手改值不再原样透传；改绑邮箱要求 `currentPassword` 再认证（403 `aiya_reauth_required`），被盗会话无法静默接管邮箱走重置流；uninstall 补全——删插件自建六表 + `aiya_core_%` usermeta 残留 + transient + cron 事件（保留 wp_aya_* 旧业务表与用户内容）；
- **报错**：`sessionResponse` 捕获 TokenStore 异常转信封 500（登录/注册/重置不再可能裸 500）；改密与重置路径把 revokeAll 前置并检查返回值（吊销失败则不改动密码，旧令牌绝不越迁）；Domain/REST 层 37 处 WP_Error 补 status（400 输入/409 冲突/404 缺失/502 上游，`aiya_not_found` 归一 404）；网关回调订单写入失败改回 fail 触发平台重试（exists() 幂等兜底）；兑换码回滚失败与治理页失败原因落 error_log；FavoriteService::remove 返回 bool、删除端点失败可见；
- **约定成文**：ARCHITECTURE.md 新增「Error handling conventions」——REST 层 WP_Error + status 映射表、Domain 层异常/WP_Error 边界、后台 flash+日志、声明式容忍清单（登出吊销/webhook 日志/头像清理）；
- 实测：重置域白名单三态、绑定不存在 post 400（原 500）、改绑邮箱 403/带密码 200、改密 200 且旧 token 即刻 401；单测 119/283、phpstan、phpcs 全绿。

### 业务路线调整 —— 会员域与外部文件域临时停用（0.29.1）

**2026-09-10 站长拍板**：B3 资源读取层与 Astro 前端接线挂起，先行推进 WP 基础内容形态到 1.0；会员域（Sponsorship）与外部文件域（ExternalFiles/OpenList）**临时停用**——业务路线暂停，非弃用（区别于 Tweet/multi-domain 的取消）：

- **实现**：`Plugin` 域开关常量（`SPONSORSHIP_ENABLED` / `EXTERNAL_FILES_ENABLED`，均 false）——条件注册 SponsorshipModule（设置页 + 迁移注册）、ConvertCodesPage、OplistModule（设置页 + resource 编辑屏 oplist_client box）；RestController 增 `sponsorshipEnabled` 构造参，停用时不注册 plans/orders/redeem/membership/afdian order-url 与 aiya/sponsorship/v1 双回调；附件控制器沿可空参不注册；
- **保留原样**：代码、`wp_aya_sponsor_orders` / `wp_aya_convert_codes` 表、协议键（`sponsor_expiration` / `aya_force_cancel_sponsor` / `aya_box_oplist_client`）、已注册迁移（版本已过、表已存在，重启用时跳过旧迁移不影响）；UserPresenter/ProfilePresenter 的 sponsor 语义直读协议键，停用后随到期自然降级；resource CPT 本体照常（属基础内容形态）；恢复 = 翻两个常量为 true；
- **验证**：插件启动无 fatal；plans / 爱发电回调 / 易支付回调 / attachments 四停用面 404，site / menus / posts / notifications / discussions 全 200；单测 119/283 全绿、phpstan 零错；
- **phpcs 基线漂移整改（同批完成）**：当前容器内 vendor WPCS 对既有域文件报 18E/21W（固定表插值、WP-free 类 json_encode、治理页只读 $_GET 等有意模式；HEAD 与工作区总数一致，0.29.1 业务改动零新增）——系依赖版本漂移所致的基线问题。已逐处核实后整改：真修 5 处（网关日志与附件缓存键改 wp_json_encode、translators 注释、wp_parse_url、AfdianClient 伪代码注释改写），定向豁免 34 处（已 prepare 的变量查询、白名单 IN 片段多行语句、WP-free 客户端的签名/传输 json、只读展示参数、WebhookLogger 尽力而为写日志）；sniff 代码经 -s 实测校准（$_GET 归 NonceVerification.Recommended、插值归 InterpolatedNotPrepared）；phpcs 与 phpstan/@phpstan-ignore 双注释共存时 phpcs 注释置行尾、phpstan 注释紧贴代码行。三件套归零（phpcs exit 0 / 119 tests / phpstan 0）。

### 用户关系表（收藏/关注/令牌）—— ✅ 已完成（0.28.0）

目标用户量按万级规划，usermeta 使用收敛为「协议标量键 + 低频写」；数组型 per-user 值一律改关联表（站长拍板：不需要兼容旧数据）：

- ✅ **`aiya_user_favorites`**（user_id + post_id 唯一、post_id 反查索引、created_at）：`FavoriteService`（add 幂等 / remove / has / published 仅 publish 分页（JOIN posts）/ countForPost 反查）+ `POST|DELETE /users/me/favorites` + `GET /users/me/favorites`（PostSummary 分页）写读端点；`ProfilePresenter` favorites 改读表（契约形状不变，最新收藏在前）；
- ✅ **`aiya_user_follows`**（follower_id + followed_id 唯一、followed_id 反查索引、created_at）：关注能力数据槽位就绪，端点随前端关注功能批设计；
- ✅ **`aiya_auth_tokens`**（id 主键 / token_hash 唯一 / user_id / expires_at / created_at）：TokenStore 重写为表读写（API 四方法签名不变）——修复并发登录丢令牌缺陷；上限 10 枚插入时裁剪最旧 + 过期行惰性清扫；每日 cron 全局清理（`aiya_core_auth_tokens_cleanup`，随 IdentityModule 生命周期）；迁移带表存在自检（dbDelta 静默失败时 runner 保持版本不推进、下次重试）；
- ✅ **协议变更**：`favorite_posts` 降级为死数据（不迁移不读取）；旧令牌不迁移（持有者重新登录即可）；
- ✅ **留任 usermeta**：`sponsor_expiration` / `aya_force_cancel_sponsor` / `basic_user_avatar`（协议键标量低频写）、`description`（WP 原生）、资料页字段组 UserMetaStore（限标量）；
- 验证：单测 117/273 全绿；运行时实测（迁移建三表、收藏增删查幂等与反查计数、令牌签发/解析/吊销/上限裁剪/过期清理、usermeta 零写入、HTTP 端点全链路），测试数据已清理。

### B2 轻社区 Discussion —— ✅ 已完成（0.26.0）

Discussion 不走 Tweet 的 feed 形，改以旧 `inc/func-issue.php` 的自建表线程引擎为蓝本重建（语义参考，实现不搬运）。契约于 2026-09-09 定稿，同批全量落地：

- **数据模型**（**2026-09-08 拍板：线程与回复均不使用 WP post/comments 数据模型，纯自定义表**——无 permalink、不经 `/wp/v2` 暴露、后台无原生编辑屏，读写全部走 `aiya/core/v1` 专用端点；表名换新，由 SchemaVersionRunner 建表）：线程表（id / user_id / type / status / title / content / post_id 反向绑定可空（绑定目标仍是 WP post）/ reply_count + last_reply_* 冗余统计 / created_at / updated_at）+ 回复表（id / thread_id / user_id / content / created_at / updated_at）；回复**平铺无嵌套**（旧原型无 parent_id）；冗余统计由同步函数维护（旧 `aya_issue_sync_comment_stats` 语义）；
- **type 白名单（2026-09-09 定稿）**：`discussion` / `question` / `feedback` 三值——去掉旧 issue 值，工单语义由 postRef 绑定独立承载，与内容性质标签解耦；
- **status 工作流（2026-09-09 定稿）**：`open` / `answered` / `resolved` / `closed` 四值。流转：发帖即 open；他人回复后自动置 answered；楼主或管理员可手动置 resolved / closed / 重开 open；**仅 closed 锁回复**（409）；
- **社区点赞取消（2026-09-09 拍板）**：讨论与回复**永久不做点赞**（非暂缓——CounterService 无需扩非 post 键源），reply 计数是唯一的互动指标；文章/资源正文点赞照旧（Engagement 域）；
- **前端路由（2026-09-09 定稿）**：沿用 `/community/`、`/community/{id}`，契约 url 字段由后端拼此形状；
- **post_id 反向绑定 = 工单/文章讨论**：绑定时校验目标存在；默认作用面 post + resource（沿 Engagement 的 `aiya_core_{feature}_post_types` 过滤器模式可扩），page 不挂；列表 `?post=` 过滤支撑「某文章/资源下的讨论」；改绑级联同步冗余列（旧语义）；
- **契约形状（定稿）**：列表项 `Discussion` = { id, url(/community/{id}), title, type, status, author: Author(B1), postRef: {id,type,title,url}|null, replies: int(冗余计数), lastReplyAt: ISO|null, publishedAt, canEdit/canDelete/canReply: bool }；详情 `DiscussionDetail` = + content{format:'html'} + replies: Reply[]（首页 50，平铺）；`Reply` = { id, author, content{format:'html'}, publishedAt, canDelete: bool }；授权位由服务端按 viewer（作者本人或 `edit_pages` 管理员）推导，游客恒 false；
- **端点组（`aiya/core/v1`，信封）**：`GET /discussions?type=&status=&post=&user=&sort=last_activity|newest&page=&perPage=`（公开读，meta.pagination）、`GET /discussions/{id}`（详情 + replies 首页）、`GET /discussions/{id}/replies?page=`（翻页）、`POST /discussions`（Bearer：title/type/content/postId?，限流 5/h）、`POST /discussions/{id}/replies`（Bearer，限流 30/10min，closed → 409）、`PATCH /discussions/{id}`（作者/管理员：title/content/type/status）、`DELETE /discussions/{id}`、`DELETE /discussions/{id}/replies/{replyId}`（作者/管理员；删线程级联删回复）；
- **后台治理页**：AIYA Core 子菜单列表页（改状态/删除），随实现批落地；
- **边界**：文章评论归 `aiya/core/v1/content/{id}/comments`（原生 `/wp/v2/comments` 已于 0.29.0 无条件退役），Discussion 归轻社区线程与按绑定工单，两者不混用；回复通知留给 Domain/Notification 切片（定向行）；
- **已定（2026-09-08）**：旧 `wp_aya_issues` / `wp_aya_issue_comments` 存量不迁移、不做兼容读取（测试环境从未运行旧主题，无此表）——新表全新 ID 空间，同 Tweet 按死数据处理；
- **Profile.activities 回归**：B5 的 Profile.activities 恒空数组状态由 B2 填充（该用户最近发布的讨论列表项）；
- **落地清单（0.26.0）**：`Domain/Discussion/`（ThreadType/ThreadStatus 纯词表类 + DiscussionService 唯一写入方——CRUD、reply_count 冗余统计同步、open→answered 自动流转（仅 open 且非楼主回复）、仅 closed 锁回复 409、授权 = 作者本人或 `edit_pages`、绑定校验限 publish 的 post/resource）+ `Api/Contract/`（Discussion/DiscussionReply/PostRef/DiscussionDetail，camelCase 锁形单测）+ `DiscussionPresenter`（postRef 复用 PublicTypes 前端路由形状）+ `DiscussionController` 七端点（信封 + meta.pagination + 限流 5/h 发帖、30/10min 回复）+ `Admin/DiscussionModerationPage`（过滤/改状态/删除）；表 `wp_aiya_discussions` + `wp_aiya_discussion_replies` 由 0.26.0 迁移建表；运行时全链路实测（发帖绑定/游客列表/状态机四态流转/锁回复 409/非作者 403/游客 401/删除授权与级联/治理页渲染），测试数据已清理。

### 通知域（Domain/Notification）—— ✅ 已完成（0.23.0）

替代旧 `inc/func-notify.php` 的设置表单公告（每请求内存重建、无持久实体、scope 过滤、时间仅为展示字符串）：

- **数据模型**（自建表，SchemaVersionRunner 建表）：`id` / `type`（v1 仅 `announcement`）/ `user_id`（0 = 广播行，>0 = 定向行，为互动通知预留）/ `role_level`（最低可见级别，白名单 guest < subscriber < sponsor < author < administrator，沿 UserPresenter 语义）/ `title` / `body` / `created_at`；**不预建** actor_id/object_id——互动通知（评论回复/关注）落地时由迁移加列；
- **读取**：`GET /notifications`（Bearer 会话可选——登录按角色过滤广播行并收入定向行，游客仅 guest 级广播行）；已读态在客户端：Astro 本地存最后查看时间（按浏览器、批级新旧、无逐行已读；将来要精确未读数再加服务端 last_read）；
- **后台**：简单管理页（发新通知 + 列表 + 删除，`manage_options` + nonce），保留期天数同页可配；
- **清理**：WP-Cron 每日调度删除过期行（默认 30 天）；低流量站点 cron 由访问驱动的延迟对清理任务无害，停用随生命周期钩位清理；
- 旧 `site_custom_notify_list` / `site_custom_consent_list` 选项不入协议，随旧设置退役（consent 弹窗归前端自有实现）；
- 落地清单：`Domain/Notification/`（RoleLevel 阶梯 + NotificationService 唯一写入方 + NotificationModule 迁移/调度接线）+ `Api/Contract/Notification` + `Api/Rest/NotificationController`（`GET /notifications`，信封包裹）+ `Admin/NotificationPage`（AIYA Core 子菜单页：发布/列表/删除 + 保留期，admin_post 逐动作 nonce）；表 `wp_aiya_notifications` 由 0.23.0 迁移建表（SchemaVersionRunner 首个真实消费者）；单测 + 运行时验证（游客/订阅者/赞助者三级可见性、定向行、prune、保留期往返、管理页渲染），运行时发现的游客 `OR user_id = 0` 退化 bug 已修复；

### 资源编辑面与附件域（Domain/ExternalFiles + resource metabox）—— ✅ 已完成（0.27.0）；⏸ 0.29.1 起临时停用

B3 前置批，落定 resource 编辑屏与附件消费链路（2026-09-09 拍板：门禁整套重写，代理端点本批全含）：

- **`oplist_client` box**：沿旧版 9 字段语义 1:1（sponsor_can/fs_method/path/desc/parent/keywords/per_page/password/refresh），screens 从 post 移到 **resource**（协议组键 `aya_box_oplist_client` 不变）；`password` 保持明文可读（代理请求需重读，不可写后即焚）；旧 `[oplist_cli]` 短代码写入层与每次查看扣触发计数不迁移（短代码归模板零件、新门禁无额度语义）；
- **`Domain/ExternalFiles/`**：`OpenListClient`（WP-free，transport 注入；只移植 login + fs 四读方法 list/get/dirs/search，写操作不搬；错误分级 aiya_oplist_unavailable/auth/denied/not_found）+ `FileIcons`（扩展名→图标类别映射）+ `OplistSettings` + `OplistModule`（域设置页 `aiya_core_oplist`：服务器凭据/Token 缓存时长/链接模式 d·p·r·f/图标开关/默认描述；token transient 缓存 + 失败走 `aiya_core_oplist_error` 钩子）+ `AttachmentService`（box 配置读取 + 门禁矩阵 + link 构造，搜索模式逐项 fs_get 补详情、丢弃已消失项）；
- **门禁（2026-09-09 重写定稿）**：文件列表元数据对**所有人（含游客）公开**；下载链接按 viewer 裁剪——`sponsor_can` 关 = 登录即给，开 = 仅赞助者（`MembershipService::isSponsor`，管理员旁路天然可见）；旧版「登录才可见列表 + 扣计数」废除；
- **端点**：`GET /resources/{id}/attachments`（公开读，信封）→ `{gated, canSeeLinks, items:[{name,size,type,modified,url|null,ready}]}`——url 为 null 即无下载权；列表即实时（ready 恒 true，拉取失败静默为空列表，错误走日志钩子）；
- **resource 编辑屏补全**：`post_seo` box screens + resource（B3 ResourceDetail.seo 数据源）、封面 metabox 默认类型 + resource（`_aya_thumb` 链路）；
- 验证：单测 115/269（客户端路由/错误分级/登录解析、图标映射、门禁矩阵纯函数）；运行时实测（协议键读写、三视角门禁矩阵 guest/subscriber→null、sponsor→link、链接构造含 sign、端点 404/未配置空列表路径、box 注册 resource 屏），测试数据已清理。

### 赞助域（Domain/Sponsorship）—— ✅ 已完成（0.24.0 核心 + 0.25.0 网关切片）；⏸ 0.29.1 起临时停用

总原则：**保持行为但重构设计**。旧结构 = `inc/lib/Afdian_API.php` + `inc/lib/Epay_Core.php`（三方客户端）、`inc/func-payment.php`（爱发电 webhook + 方案卡片 + 兑换码）、`plugins/sponsor-order-compat`（易支付收银台 + 回调）、`inc/func-user.php` 的订单表与叠加到期计算。

✅ 核心切片落地清单（0.24.0）：

- `Domain/Sponsorship/`：`ExpirationFold`（叠加到期折叠纯函数，单测锁旧版语义）、`MembershipService`（协议键读取 + `isSponsor` 含编辑权限旁路 + 触发计数；localNow 与旧 `current_time('timestamp')` 数值等价）、`OrderService`（订单表唯一事实源读写、order_id 幂等、每次变更重折叠并写 `sponsor_expiration`——该协议键唯一写入方）、`RedeemCodeService`（原子核销 + 激活失败回滚 + 批量生成）；`SponsorshipModule`（0.24.0 迁移建两表——存量安装 dbDelta 找到旧表为 no-op，列只加不改义——+ 域自有设置页：爱发电/易支付凭据与开关、方案 repeater）；`Admin/ConvertCodesPage`（兑换码生成/列表/删除）；REST `GET /sponsorship/plans`（公开，方案 + 渠道开关）、`POST /sponsorship/redeem`（Bearer + 限流）、`GET /sponsorship/membership`（Bearer，active/leftDays/totalDays/triggerCount/orders）；`UserPresenter::role` 的赞助判定改为复用 `MembershipService`（语义单源化）；运行时验证：兑换→叠加→幂等→取消重折叠→role 语义→401→管理页渲染全链路，测试数据已清理。

✅ 网关切片落地清单（0.25.0）：

- `AfdianClient` / `EpayClient`（WP-free 纯签名/验签/编码类，传输以闭包注入 `wp_remote_post`）；用户绑定复用 `slug-toolkit` 的 `IdSlugEncoder`（8 位 XDE 冻结算法，与旧 `aya_token_encode($id, 8)` 逐字节一致——旧支付链接挂起的 `custom_order_id` 切换后仍可解码）；`WebhookLogger`（`wp-content/aiya-core-logs/`，设置开关控制）；
- `Api/Rest/GatewayController`：命名空间 `aiya/sponsorship/v1`（第三方回调不进版本化契约，经 SecurityModule 公开面白名单放行——回调恒为匿名平台推送）。**爱发电 webhook**：`POST /afdian/callback` 补签名验证（`md5(token+params+ts)`，篡改 403——旧版裸解析 JSON 的缺陷修正）+ `trade_success` 状态检查 + `custom_order_id` 解码绑定 + `afd_` 订单幂等 + 月数×31 天 + 验签后恒 200；**易支付回调**：`GET /epay/callback` 签名验证（沿旧 SDK 算法含 '0' 排除语义，篡改 400）+ `param` 解码 `userBinding|planKey` 定用户与天数（**金额反查商品已废除**）+ `epc_` 订单幂等；`param` 为站点自产、经网关原样回传的字段（2026-09-09 拍板：格式由新版自定义，两段式，不兼容旧版单段格式——解析失败安全跳过，不做兼容读取）；
- REST 扩充：`POST /sponsorship/orders`（Bearer，{planKey, channel: alipay|wxpay|usdt} → 签名收银台 submitUrl，含 notify_url/return_url——渠道未启用/方案不存在 4xx）、`GET /sponsorship/afdian/order-url`（Bearer，自选金额/预设方案两型，带当前用户 `custom_order_id` 与 remark）、redeem 端点加**爱发电订单号分支**（纯数字 → 在线查单激活，source=afdian，保持旧 ping/去重/查单语义）；`plans` 端点 channels 增加 `afdianHomeUrl`；
- 设置页新增：`afdian_plan_type`（自选金额/预设方案）、`afdian_preset_plan_url`、`epay_return_url`（支付后回跳前端页，归前端路由）；
- 单测：AfdianClient 签名往返/篡改拒绝/绑定往返/transport 注入，EpayClient 签名可验/篡改拒绝/旧 SDK '0' 排除语义锁定；运行时：自签 webhook 激活（3 月=93 天）→ 重放幂等 → 篡改 403；易支付合法签名激活（方案 30 天）→ 重放幂等 → 篡改 400；收银台 submitUrl/渠道开关/afdian order-url/数字兑换错误路径全链路，测试数据已清理。

网关切片验收基准（已全部达成）：

- **订单表兼容（拍板）**：`wp_aya_sponsor_orders` 沿用为唯一订单事实源——列只加不改义（user_id / order_id unique / start_time / duration_days / source / status / created_at），到期模型保持「按 start_time 升序折叠 paid 订单、重算后写 `sponsor_expiration` 协议键」；`wp_aya_convert_codes` 建议沿表兼容，以免作废存量未用兑换码；
- 爱发电 webhook：`custom_order_id` 解码用户绑定、`afd_` 订单号前缀、月数×31 天、order_id 去重、恒 200 应答；
- 爱发电订单号当兑换码：在线查单 → 激活（已激活订单拒绝）；
- 易支付：方案卡（alipay/wxpay/usdt × 商品）→ 收银台提交 → 签名验证回调 → 防串单（param 用户 vs 订单号内嵌用户段）→ `epc_` 订单；
- 兑换码：原子核销（条件 UPDATE 防并发）、激活失败回滚；
- 会员门禁链路：`sponsor_expiration` + `aya_force_cancel_sponsor` + `aya_trigger_count_sponsor`（协议键）→ `aya_is_sponsor` 语义 → UserPresenter role（B5 已消费）。

重构方向（行为保持前提下的修正）——✅ 全部达成（0.24.0/0.25.0）：

- ✅ 爱发电 webhook 补签名验证（篡改 403）+ `trade_success` 状态检查；webhook 路由移出 `aiya/core/v1` 契约命名空间（`aiya/sponsorship/v1`，经 SecurityModule 公开面白名单放行）；
- ✅ 易支付天数改由签名参数携带方案 key（`param` = userBinding|planKey），金额反查废除；
- ✅ 方案/商品域内结构化数据（设置页 repeater + `/sponsorship/plans` DTO）；前端渲染展示文案；
- ✅ 订单/激活收敛为 Domain 服务（OrderService/RedeemCodeService 为表与协议键唯一写入方）；
- ✅ 爱发电订单号当兑换码入 `POST /sponsorship/redeem`（纯数字 → 在线查单激活，source=afdian）；
- ⏳ 旧 React 群岛（subscribe/activate/dashboard）由 Astro 组件重建（前端批次），消费 plans/orders/redeem/membership/order-url 端点。

### M5 版本化 REST ＋ Astro SSR

- `Api/Rest/`：命名空间 `aiya/core/v1`；控制器只调用 M4 的读服务与 Presenter；**认证/用户域骨架已随 M4 用户域批次落地（0.12.0：RestController 模块、Bearer 认证、auth/users 路由、限流）**，本里程碑追加内容资源：内容列表/详情、terms、导航菜单、面包屑/分页（嵌入响应元数据）、站点设置白名单、媒体引用；**评论已落 `aiya/core/v1/content/{id}/comments`（0.20.0 改道，`wp_new_comment` 经典管线全触发——`preprocess_comment`/duplicate/flood/禁词名单/审核决策均有效，`rest_pre_insert_comment` 顾虑随改道消失）＋加固层 ✅（0.34.1：API 限流 5/10min、honeypot 隐藏字段 `website`（前端评论岛建设时需渲染该隐藏输入）、泛洪映射 429、重复 409、WP 讨论设置（comment_registration/require_name_email/comment_max_links/审核与老评论者白名单）全部原生生效），Astro 侧评论系统建立其上；
- 公开读 + 应用密码写；CORS：WP 核心 `rest_send_cors_headers` 现状为回显任意请求 Origin 且 `Allow-Credentials: true`（`wp-includes/rest-api.php`），浏览器直连端点（互动计数、将来评论）因此已跨域可用、无需自写——M5 将其**收紧为 Astro 来源白名单**，与评论加固层同批落地；ETag / Cache-Control；
- **模板零件**（2026-09-08 拍板计划迁移）：旧经典编辑器短代码输入器重设计——后台保留录入 UI（录入规范化零件数据），API 对短代码类内容输出规范化零件结构（不渲染 HTML），Astro 侧建立逐零件解析渲染；零件契约形状随首个真实零件出现时定；
- 产出面向前端的类型契约（OpenAPI 或从 Contract 生成 TS 类型脚本）；
- Astro 侧在 `aiya-astro-bulid/` 初始化：SSR 模式（node adapter，保 SEO），`src/lib/aiya/`（类型化 API client，镜像 Contract、缓存）、`src/pages|components|layouts`；
- 验收：Astro SSR 拉通首屏真实数据，直接命中 WP 域名时由 `aiya-headless` 空壳主题兜底，旧主题可整体退役。

### 菜单图标字段 —— ✅ 已完成（0.37.0）

- `NavigationModule`：primary repeater 新增可选 `icon` 行字段（Lucide 图标名，纯文本）；secondary（页脚菜单）不含该字段；
- 契约：`MenuItem.icon`（`?string`，空值投影为 null）随 `/menus/primary` 透出，secondary 恒 null——前端侧栏渲染设置值优先、按 URL 形状回退（front-station `DesktopSidebar`）；
- `Site` 移除 `logo` 字段（0.36.x 引入的品牌 logo 与 Frontend 设置页 logo 字段一并下线）：品牌图统一走 WP 站点图标（`favicon`）；
- 测试：`PrimaryMenuTest` 补 primary 图标投影 / secondary 恒 null 用例（127/127）；
- 前端对接（front-station）：secondary 组渲染为页脚导航，Footer 同时输出 `site.footer` 备案两链接与 note；PostCard 无缩略图回退 `site.defaults.thumb`。

### 主题色字段 —— ✅ 已完成（0.38.0）

- 契约：`SiteDefaults` 扩展 `theme: SiteTheme`（`{primary: string}`，六位 hex、大写归一）；`/site` 的 `defaults.theme.primary` 驱动前端品牌色板；
- 设置：Frontend 页新增 Branding 区 `color_primary` 取色器（框架原生 `color` 类型 + wp-color-picker，非 hex 输入按默认 `#e94f69` 回退）；
- 前端（front-station）：AppShell head 内联 `:root:root{--primary;--primary-foreground}` 覆盖 tokens（SSR 直出零 FOUC；对比度 YIQ 自动选白/深墨；ClientRouter 换页随 head 持久；prerender 后烘入静态页）。zod `siteThemeSchema` 正则校验同步、快照测试 manifest 注册 `SiteTheme`。

### Optimization 页重排与总开关移除 —— ✅ 已完成（0.39.0）

- 页面 slug：`aiya-core-headless` → `aiya-core-optimization`；选项存储 `aiya_core_headless` → `aiya_core_optimization`，经 SchemaVersionRunner 0.39.0 迁移（拷贝旧值、剔除 `headless_mode` 与历史 `"0"` 残键、删除旧 option；`AIYA_CORE_VERSION` 常量此前停在 0.36.4 与插件头 0.38.0 脱节，本批对齐）；
- 总开关（`headless_mode`）整套移除：十项裁剪开关逐项独立生效，关闭任一项只恢复该表面（`/wp/v2/comments` 退役不受影响，本就无开关）；
- 分组重排：原「Editor surfaces / Front end and protocols / Admin screens and block services」三组合并为「Disabled features（功能禁用）」一组；AvatarModule、SlugModule 各自挂「Avatar settings（头像设置）」「Slug generation（别名生成）」组标题（原先散落在最后一组标题之下）；TypographyModule 组移至页尾（`aiya_core_register` 优先级 10 → 12）；
- 控件：`avatar_cdn_mirror` 与 `slug_post_mode` 由 select 改 radio（页面上仅有的两个 select，选项平铺一目了然）；
- 测试：新增 HeadlessSettingsMigrationTest（全套 137 tests / 310 assertions）；zh_CN PO 同步增删条目并重编译 MO。

### Security 页 REST 开关聚合与三组重排 —— ✅ 已完成（0.40.0）

2026-09-11 拍板：前端只走 aiya/core/v1，原生 /wp/v2 已无公开消费方，两个细粒度开关没有继续拆分的意义：

- **开关聚合**：`guard_rest_users`（/wp/v2/users 匿名剥离）+ `lock_rest_surface`（仅契约路由公开）合并为 `lock_wp_v2` 一项——开启时整个 /wp/v2 对无 `edit_posts` 会话的访客 404（`filterUserEndpoints` 删除，`lockPublicSurface` 接管唯一开关）；副产物修复：后台会话取回 /wp/v2/users，旧媒体库作者筛选降级问题消除；`filterIndexNamespaces` 的命名空间裁剪保留 gateway 前缀（路由公开则索引应列出）。存量 `aiya_core_security` option 未曾保存（纯默认值运行），干净换名无迁移；
- **分组重排**：页面按「REST 路由控制（REST route control：lock_wp_v2、hide_sitemap_users、rest_allowed_origins）/ 登录限制（Login restrictions：force_email_login、password_reset_allowed_hosts、login_param_gate_enable、login_param_gate_value）/ 后台防护（Admin protection：admin_backend_min_role、request_uri_guard）」三组呈现，REST 相关设置全部前置；
- 验证：匿名 HTTP 实测 /wp-json/wp/v2/posts 与 /wp-json/wp/v2/users 均 404、/wp-json/aiya/core/v1/site 200、REST index namespaces 仅剩 `aiya/core/v1`；admin 会话下 /wp/v2 全量可用（wp-cli 双态模拟）；zh_CN PO 增删条目并重编译 MO。

### ThemeSupport 域：主题支持收归插件 —— ✅ 已完成（0.41.0）

2026-09-11 拍板：壳主题回到字面零引导，`add_theme_support` 声明由 aiya-core 持有（可行性先行研究：WP 7.1 的 `$_wp_theme_features` 为纯全局读写，全部消费点（metabox 双重检查/媒体弹窗/body class）都在 init 之后执行，插件在 after_setup_theme 声明完全有效）：

- **新域 `Domain/ThemeSupport/ThemeSupportModule`**，挂 `after_setup_theme` 优先级 20（晚于任何主题自身注册，无参声明把主题的窄化列表扩展为全类型）：
  - `post-thumbnails`：特色图 metabox 与媒体弹窗是编辑面而非主题特性；无参声明（检查层全放行），真实白名单是各类型自己的 `thumbnail` supports（post/page 内置自带、resource CPT 显式声明）；REST `featured_media` 与 `featured` 契约字段本就不读该全局，零影响；
  - `image_default_link_type` 钉死 'none'（`pre_option_` 过滤器，不落库）：旧主题同块遗留策略的移植——内容图片不得链接到 WP 渲染的附件页（Astro 无此路由），新装/重置选项表行为一致；
- **旧主题清单逐项判定**（framework-required register-theme-support，after_setup_theme 块）：`automatic-feed-links`/`title-tag` 只渲染 wp_head（壳兜底页自写 `<title>`、不该长 feed links，不声明才正确）；`menus` 不需要（导航是设置驱动，插件零 nav_menu 调用，设置页文案明说替代 WP 菜单系统）；`post-formats` 唯一消费方 Tweet 域已取消（死数据，声明反而在经典编辑器冒出 Format 噪音）；`html5` 作用于主题渲染的核心标记（搜索/评论表单/画廊），前台归 Astro；`custom-logo`/`custom-background` 是定制器特性，定制器已被 HeadlessModule 拆除；同块的 `add_rewrite_tag('%page_type%')` 属旧路由随旧 REST 退役；
- **壳主题** functions.php 删除唯一钩子，回归纯头文件（style.css + 头守卫 + 兜底 index.php）；
- 测试：新增 ThemeSupportModuleTest 3 用例（钩子挂载、无参声明语义、option 钉死），bootstrap 垫片补 `add_theme_support`（全套 140 tests / 314 assertions）；phpstan 全绿。

### 顶栏 banner 契约与 Frontend 设置 —— ✅ 已完成（0.42.0）

- **契约**：`Site` 新增 `banner: ?Image`（favicon 之后，快照同步）——Frontend 页 `banner_enabled` 开且 `banner_image` 附件可用时输出完整 Image DTO，否则 null（`SitePresenter::banner()` 读 `aiya_core_opt('frontend', ...)`，`attachmentImage` 复用）；
- **设置**：Frontend 页「Presentation defaults」后新增 Header banner 组两项：`banner_enabled`（框架 `switch` 类型）+ `banner_image`（media）；关闭即无 banner；
- **前端（front-station）桌面顶栏重构**：去白底与边框线、取消置顶（2026-09-11 拍板：顶栏不 sticky，随页面滚走，无滚动毛玻璃态）；贴左 = 折叠按钮 + 半透明灰搜索框（内嵌放大镜，`bg-foreground/10` 无背景也可见、`rounded-md` 与卡片同圆角）；贴右 = 暗色切换 + 用户簇；
- **banner 衬底**：有 banner 时图片绝对定位垫在透明导航行下方（页顶同高容器，导航叠图上），二者作为一个整体随页面滚走；
- **暗色模式全链路**：tokens.css 全部语义色改 var 间接（`:root` 亮 + `.dark` 暗双板，`@theme inline` 映射；侧栏硬编码 hover 色一并 token 化；暗色板为临时中性方案，待暗色设计规格后细化）；BaseHead 预涂装内联脚本按 localStorage → `html[data-color-mode-default]`（site `defaults.colorMode`）→ 系统偏好解析，`data-astro-rerun` 随 ClientRouter 换页重跑；顶栏月亮/太阳图标经 `dark:` 变体纯 CSS 显隐，切换写同一 localStorage 键；
- **通知铃铛（登录后簇）**：铃铛（details 面板 + 未读点）+ 纯头像 dropdown（原昵称 summary 简化，昵称留面板头）；新增同源代理 `GET /api/notifications/`（cookie bearer 转发 `/notifications`，游客空表、上游错误透传状态码）；已读态按契约归前端——localStorage last-seen 与 items 最新 `createdAt` 字典序比较，面板打开即落盘清点；日期经 `data-locale-tag`（toBcp47）本地化；
- 测试：SiteContractTest 补 banner 双态断言（全套 140 tests / 316 assertions）；前端 siteSchema.banner + mock/fallback `banner: null` + 四字典补 4 键（vitest 138、astro check 0 错误、live 冒烟含 banner 明暗双态与滚动吸顶实测）。

### 自兼容清理：未上线拍板 —— ✅ 已完成（0.43.0）

2026-09-11 拍板：新版 core 从未上线运行，自迭代产生的字段转换与自兼容层全部清除，过时行按死数据处理：

- **迁移瘦身**：HeadlessModule 的 0.39.0 `aiya_core_headless→aiya_core_optimization` option 迁移删除（本站已迁移完毕，全新库无旧键）；IdentityModule 的 `normalizeTokenExpiry`（int→DATETIME）与 NotificationService 的 `renameRoleLevel`（role_level→min_role）两处 0.31.0 列转换删除——均为 dev 期表结构演化的自兼容，现库已终态；迁移回调直指建表（installTables/installTable），迁移面只剩幂等建表；
- **全新安装建表缺口修复**：`activate()` 原本记录当前版本号导致 runner 跳过全部迁移、全新安装不建任何表——改为记录 `0.0.0`，首次引导经 runner 执行全部建表迁移后推进到当前版本；
- **Avatar 形状收敛**：`basic_user_avatar` 只认现行 `thumbnail/avatars/` 文件形状（新增 FILE_PATH_PREFIX 前缀守卫），媒体库 era（`['id'=>…]`）与旧 `avatars/` 路径/绝对 URL 形状按 2026-09-11 拍板成死数据不再读取，携带者回退 Gravatar 镜像（本站 user 1 旧路径行实测回退正确）；
- 协议语义保留不动：CounterService 对旧主题 like/view 计数值的读写兼容属原始迁移契约；
- 测试：HeadlessSettingsMigrationTest 随迁移代码删除（全套 136 tests / 309 assertions）；phpstan 全绿。

### 页脚重构：备案 repeater + 一言开关 —— ✅ 已完成（0.44.0）

- **契约**：`SiteFooter` 重写为 `{links: list<BeianLink>, hitokoto: bool}`（旧 `icp/mps/mpsCode/note` 四字段退役）；新 DTO `BeianLink{label, url, icon, iconUrl}`，icon 三值模板 `shield/police/custom`（快照/锁同步）；
- **设置**：Frontend 页合规组改为一言 switch + `beian_links` repeater（label/url/icon radio/custom icon_url 四子字段），旧四个独立字段删除；
- **前端**：Footer 重排——左列链接菜单一行 + 固定版权行 `Copyright © {year} AIYA CMS. All rights reserved.`，右列备案链接一行一个（盾形 lucide 图标 / 公安徽章官方图 / 自定义图）；页脚最后一行为一言随机句（旧主题 hitokoto.json 493 条随包内置，SSR 每次请求随机取一句，作者署名跟随）；
- **侧栏滚动**：`#global-sidebar` 滚区 `scrollbar-gutter: stable`，全局滚动条细半透明无箭头（Chromium 走 webkit 伪元素、Firefox 走 @supports 门内的标准属性——两套混用会互相失效，已注明）。

## 五、执行纪律


- 每个里程碑完成时更新本文状态（勾掉条目即可），不在两处维护真相；
- 不为「将来可能用到」预建目录与抽象；切片原则见 MIGRATION.md；
- 运行时验证一律走 Docker wp-cli（`docker compose run --rm wpcli ...`），PHP 语法检查可用 `php -l` 的容器替代方案。

### 1.0 前收口批次（0.45.0 / 0.46.0）—— ✅ 已完成（2026-09-12/13）

2026-09-12 拍板（社区重定基线 + 徽章/锁定/随机 + 通知动作系统按域内监听器落地）：

- **社区契约重定基线**：`type` 三值（discussion/question/feedback）取消，改自定义**板块**——新表 `aiya_discussion_boards`（slug 唯一/sort），`discussions.board_id` 取代 `type` 列，迁移播种讨论/问答/反馈三板块并按旧值回填；契约 DTO 去掉 `type` 加 `board`/`tags`/`images`（DiscussionReply 同加 images），快照与 front-station zod 同步；`GET /discussions/boards`（带计数）、`?board=slug` 过滤、发帖/编辑以 slug 寻址（未知 400，空缺省第一板块）；后台「板块管理」折叠卡片并入轻社区列表页（增/改/排序/删，删除时帖子移交剩余首板块，末板块拒删）；
- **状态两态化**：`answered`/`resolved` 与回复驱动的自动流转删除，`ThreadStatus` 收缩为 `open`/`closed`（closed 锁回复 + 灰徽章，正常态无徽章）；存量硬归一为 open；
- **回复编辑**：`PATCH /discussions/{id}/replies/{rid}`（作者或 edit_pages，路径双 ID 配对校验，kses + ≤9 图），client.ts `updateDiscussionReply`（PATCH 白名单）；
- **随机排序**：`GET /discussions|posts|pages|resources?sort=rand`（orderby rand；置顶提升在 rand 下跳过）；
- **通知动作系统**：新 `Domain/Notification/NotificationActions` 监听器模块（域内自持动作）：8 动作 —— 文章被评论 / 评论被回复（核心 `wp_insert_comment`，仅 approved、跳过自己）、社区帖被回复 / 关注者发帖（`aiya_core_thread_replied|published` 自定义钩子）、被关注（`aiya_core_user_followed`，仅新插入）、赞助生效（`aiya_core_membership_synced` + user-meta 到期时间戳去重）、赞助到期前一日（每日扫描 cron `aiya_core_sponsor_expiry_scan`，跳过强制取消）、密码重置（核心 `password_reset`）；0.46.0 迁移给通知表加 `actor_id/object_type/object_id` 三列；
- **媒体管线增强**：`save_post`（发布态，去重守卫）直连卡片生成——保存即产出 640×360；`refreshFor` 刷新语义（换新 `_thumb` 并删除被替换的 cover 文件，失败保留旧图）；列表行操作「刷新缩略图」（nonce + edit_post）；特色图派生文件（640×360 卡 + 1000×640 详情背景，同三层配方，`thumbnail/{w}x{h}/{attId}-{w}x{h}.{ext}` 确定性命名、文件复用语义不写 `_thumb`）；默认占位图派生卡；
- **内容读取增强**：`PostSummary.badges`（sticky/password/private 机器键，徽章文案归前端）、`PostDetail.locked` + 空 content（密码门形状）；`POST /content/{id}/unlock`（明文常量时间比对 + 种核心 postpass cookie，10 次/10 分钟限流，任意公开类型）；
- **`/site.comments`**：WP 讨论设置十项透出（表单校验/审核/嵌套/分页），登录制为结构性常量故 `comment_registration` 不投影；
- **后台信息架构**：轻社区升一级菜单（资源 7 之下 8；后按拍板移至评论 25 之下 26）、发送邮件移入 AIYA Core 子菜单（资产 hook 后缀随之修正）、通知保留期移至前台页「通知」组（`NotificationService::retentionDays` 改读 `aiya_core_opt('frontend', ...)`，独立 option 删除）、品牌标题组删除与品牌色改名主题色并入外观默认值、用户列表行操作「发送邮件」（携带邮箱预填收件人）、文章列表「刷新缩略图」行操作；
- **安全与裁剪增补**：`big_image_size_threshold` 关闭（图床管线唯一写入方）；`enable_post_by_email_configuration` 关闭（Writing 分节/白名单/wp-mail 三处同时失效）；`lock_wp_v2` 从 Security 页迁至 Optimization 页并收紧为 `publish_posts`（作者以上），sitemap 总开关与 feed 关闭开关落 Optimization 页（`wp_sitemaps_enabled` 过滤器 + 四个 `do_feed_*` 移除，核心 has_action 守卫 404）；
- **激活默认固定链接**：`activate()` 空结构时置 `/%postname%/` 并登记一次性重写刷新标记（init 99 消费，CPT 注册完成后整表重建）——空库安装直连 REST 免 301；
- **开发辅助**：WP_DEBUG 下资源版本附文件 mtime；phpstan bootstrap 补 COOKIEHASH 存根；
- 测试与门禁：139 tests / 320 assertions、全规则集 phpcs、全 src phpstan 全绿；空库安装实测（临时容器全量迁移链路 + 双固定链接形态 REST 矩阵）。

### 积分域（Domain/Credit）—— ✅ 已完成（0.47.0）

2026-09-13 拍板（会员从"通过门"重构为积分记账，计划全文见 `docs/credits-membership-plan.md`；分两期：积分域先落地，会员域 tier 重构第二期）：

- **数据模型**：`wp_aiya_credit_entries` 单表兼作桶与流水——`in` 行即发放桶（`remaining` 剩余计数 + `expires_at` 过期，全部发放可过期，无永久存款），`out` 行即消费流水；`UNIQUE(source, ref, user_id)` 承载一切幂等（签到 `ref=当日`、兑换码 `ref=码值`、未来会员 `ref=order_id#周期k`），`KEY(user_id, expires_at)` 支撑 FIFO；余额恒由 `SUM(remaining)` 推导，不落 user meta（单一事实源）；时间基准 DATETIME GMT（0.31.0 约定）；
- **核心服务**：`CreditAllocator` 零 WP 依赖纯分配器（过期先扣 FIFO 行走，`ExpirationFold` 先例）；`LedgerService`——`grant()`（撞唯一键幂等）、`spend()`（事务 + `SELECT … FOR UPDATE` 锁桶、逐桶 `remaining >= take` 条件守卫、out 流水与扣减同事务，不足回滚返回 `aiya_credit_insufficient` 409 携余额）、`entries()`（分页）、`pruneExpired()`（死桶即清、已关历史按保留期）；`CreditModule` 每日清理 cron（`aiya_core_credits_cleanup`）+ 0.47.0 迁移建表；
- **REST（全加法）**：`GET /credits/balance`（Bearer）、`GET /credits/entries`（Bearer，分页 meta.pagination）、`POST /credits/checkin`（Bearer + 限流，本地日历日 `ref` 撞唯一键，重复 409 `aiya_credit_checkin_done`，开关关闭 403）；契约 DTO `CreditBalance`/`CreditEntry`/`CreditCheckin` 进快照，front-station zod schema + manifest + vitest 同步（107/107）；
- **「积分」设置页**（`aiya_core_credit`，挂 aiya-core-frontend）：签到开关 / 签到发放额 / 积分有效期天数（签到与未来兑换码桶共用）/ 下载积分单价（为延后的付费领取预留）/ 账本保留期；i18n zh_CN 全量落地；
- **`sponsor_can` 删除（实施时拍板，不继承）**：OpenList 盒子字段、`AttachmentService` 门禁矩阵与 `MembershipService` 依赖、附件响应 `gated`/`canSeeLinks` 字段一并移除；下载链接回归"登录可见、游客 `url` 裁剪"唯一规则，免费不计量；付费领取端点延后另行设计（`download_cost` 设置已预留）；存量 meta 组内 `sponsor_can` 键成死数据；门禁矩阵单测同步删除；
- **uninstall**：账本表 drop + 清理 cron 注销；
- 测试与门禁：147 tests / 328 assertions、phpcs、phpstan 全绿；运行时实测：迁移落地、grant 幂等、FIFO 跨桶扣减（先过期桶扣光再扣后桶）、超额 409、checkin 成功流 + 409 幂等、entries 分页信封与 ISO 转换、cron 排程、prune 冒烟，测试数据已清零。

### 积分后台面与纯记账收缩 —— ✅ 已完成（0.48.0）

2026-09-13 实施时拍板（计划文档 §0 第 9 条）——积分域收缩为纯记账 + 后台入口重排：

- **纯记账**：`download_cost` 设置移除——报价归下游调用方，付费行为（未来的付费下载等）自带数额调 `spend(amount, source, ref)`，积分域只回答成功/失败，避免新增业务产生耦合；
- **运维归位**：账本保留期移前台页「积分账本」组（`credit_retention`，紧邻通知保留期），`CreditSettings::retentionDays()` 读取（沿 NotificationService 模式）；积分有效期语义收窄为签到专属，字段并入「每日签到」组；原 Registry「积分」设置页废除；
- **一级菜单「会员」**（`aiya-core-membership`，dashicons-awards，位置 27 轻社区之下）：后续会员域/支付/兑换码做其子页；积分屏为首个子项，改轻社区同款折叠卡片（`Admin/CreditsPage`）——每日签到设置卡（`aiya_core_credit` 三键，admin_post + nonce 保存）、手动发放卡（用户联想搜索沿发送邮件页 AJAX 模式 + 数额/有效期/备注；`source='admin'`，`ref=备注+时间戳随机后缀`，不撞幂等键、次次成功）、积分流水卡（按用户分页：方向着色/来源标签/桶剩余/过期）；用户列表新增「积分」列（实时 SUM，静态缓存去重，链入流水视图）；
- **i18n**：新字符串 zh_CN 全量落地，废弃字符串随 sync 移除；
- 测试与门禁：147 tests / 328 assertions、phpcs、phpstan 全绿；运行时实测：页面渲染（卡片/nonce）、设置保存 option 落值、手动发放（账本行 `admin-`/`smoke-` ref + 流水卡显示 + in 方向着色）、用户列 `aiya_credits` 与链入、前台保留期读取，测试数据（账本行/session）已清零。

### 兑换码接回积分域 + 后台微调 —— ✅ 已完成（0.49.0）

2026-09-13 实施时拍板（计划文档 §0 第 10 条）——§3.5 兑换码改造不等第二期，随积分域接回：

- **数据**：`wp_aya_convert_codes` 加列 `credits`/`valid_days`（CreditModule 0.49.0 迁移持有——赞助域停用中原 0.24.0 迁移不会再跑；dbDelta 对存量安装补列、全新安装建全形表；`duration` 列保留为死语义）；`credits=0` 的旧行核销按无效码拒绝；
- **服务**：`RedeemCodeService` 依赖从 OrderService 换为 LedgerService——核销 = 原子认领（`status=1, user_id, used_to` 条件 UPDATE 唯一胜负）→ `grant()` 建桶（`source='code'`，`ref=码值`，过期 = 兑换 + valid_days），发放失败回滚码为未用（照旧 error_log 诊断）；`generate(quantity, credits, validDays)` **前缀词参数删除**；
- **REST**：新 `POST /credits/redeem`（Bearer + 限流 10/600s，body `{code}`，响应 CreditGrant 形状 granted/balance/expiresAt）；DTO `CreditCheckin` 更名 `CreditGrant`（签到/兑换共用形状，非 v1 基线成员、快照安全换名），front-station zod/manifest/快照同步（vitest 107/107）；SponsorshipController 摘除兑换路由与方法（本地码归积分域，爱发电订单号激活待第二期另定），连削 limiter/afdianClient 死代码；
- **后台**：`ConvertCodesPage` 从停用面接回，挂「会员」一级菜单子页（原 aiya-core-frontend 子页），生成表单 quantity/credits/valid_days（前缀输入移除），列表列 Days→Credits/Validity；积分页流水区从折叠卡改页面直排（`ledgerSection`，页面主浏览面，`<details>` 只剩签到/发放两卡）；
- **i18n**：新字符串 zh_CN 全量落地；
- 测试与门禁：147 tests / 328 assertions、phpcs、phpstan 全绿；运行时实测：0.49.0 迁移加列、generate 出码、REST 核销（granted 50/balance 50/+30 天）、重复核销 409 `aiya_code_used`、账本 `source=code/ref=码值` 桶、兑换码页与积分页直排流水渲染，测试数据（码/账本行/用户/session）已清零。

### 相关文章端点（WPJAM 算法复刻）—— ✅ 已完成（0.50.0）

2026-09-13 从 WPJAM Basic 迁移调研拍板（五个候选功能第一批）：相关文章按共享术语计数的排序算法值得复刻为 headless 读端点，展示层（the_content 自动附加/缩略图选项/HTML 包装器）与 `[related]` 短代码属旧主题形态，不复刻：

- **算法**（`Domain/Content/RelatedPostsQuery`）：收集原文章**全部契约词法**（category + tag 角色，`PublicType->taxonomies` 驱动）的 `term_taxonomy_id` 去重 → 同 PublicType 内 `publish` + 无密码 + 排除自身 → 单条 SQL 排序：`INNER JOIN term_relationships` 限定 IN 集合、`GROUP BY object_id`、`ORDER BY count(tr.object_id) DESC, ID DESC`（共同术语多者靠前，同分新文优先）——clauses 经 `posts_clauses` 过滤器**随查随挂随拆**（WPJAM 原为全局常挂 + orderby 条件触发），`applyClauses` 为纯静态方法（表名 wpdb 缺失时回退默认前缀，单测可钉 SQL 形状）；IN 列表整数收窄后拼接（prepare 无法构造）；
- **端点**：`GET /content/{id}/related`（公开读，任意公开类型，origin 走 detail 同款可见性解析）——参数 `number`（默认 5，1–20）、`days`（0 = 不限，最大 365，`post_date_gmt` 窗口）；响应 `PostSummary[]` 裸数组走中央信封，HTTP 缓存落「其他 public GET」档（max-age=0 + must-revalidate + ETag）；无共享术语返回空列表，**无新鲜度兜底**（沿 WPJAM 语义）；
- **契约**：纯路由加法，零新 DTO，快照不变；与 WPJAM Basic 共存安全——WPJAM 的 `filter_clauses` 认得同一组内部标记（orderby=related + term_taxonomy_ids）会叠加同名 JOIN，但两边限制同一集合、计数平方后单调性不变，排序等价，WPJAM 退役后自然回归单 JOIN；
- 测试与门禁：4 新单测（SQL 子句形状/已有 GROUP BY 追加/脏 ID 收窄/空集合不动 clauses），phpcs、phpstan（本批文件）全绿；运行时实测（`--skip-plugins` 隔离，真实库）：排序 `[B(2 共享), F(1), C(1)]` 并列 ID 降序正确、days=365 排除 2020 旧文、days=20000 钳 365、number=1/999 钳制、密码文与草稿不出现，测试数据已清零。注：本批运行时验证期间 Sponsorship/会员域第二期在途重构导致插件启动态不稳定，REST 端点层待其恢复后例行冒烟即可。

### 会员域 tier 重写：周期队列 + 赞助域重新启用 —— ✅ 已完成（0.50.0）

2026-09-13 拍板实施（计划文档 `docs/credits-membership-plan.md` §0 第 11 条：干净重写不兼容旧接线；爱发电只留 SDK 不接线）。上批注记的在途重构至此收敛，赞助域 REST 已实测恢复：

- **数据模型**：新队列表 `wp_aiya_memberships`——每购买一行（order_id 唯一幂等锚点、tier_key/tier_name/cycle_days/credits_per_cycle 购买时快照、cycles_total/cycles_granted 推进指针、starts_at/ends_at DATETIME GMT、status active/cancelled），`starts_at = max(now, 队尾 MAX(ends_at))` 保证多周期多档位按购买次序顺序生效；`wp_aya_sponsor_orders` 加 `amount`/`tier_key` 列降级为纯支付流水（start_time/duration_days 成死值，列只加不改义）；**三协议键退役**——`sponsor_expiration`/`aya_force_cancel_sponsor`/`aya_trigger_count_sponsor` 停读写 + 迁移删行（未上线，无兼容义务），工作区 AGENTS.md 协议表同步移除；
- **核心服务**：`MembershipScheduler`（周期窗口/到期追账零 WP 依赖纯函数，单测锁定：背靠背无重叠、跳过已发/未到期、停机后一次补发全部到期周期）；`EntitlementService`——`activateFromPayment()`（入队 + order_id 幂等 + `aiya_core_membership_activated` 钩子）、`advance()`（发放 cron：每到期周期发一桶 `source='membership'`/`ref=orderId#c{k}`，桶过期 = 本周期终点，账本唯一键 + 计数器 compare-and-swap 双保险）、`queueFor/window/cancelAll`（强制取消 = 全行翻转）；`MembershipService` 重写读队列（isActive/expiresAt/leftDays/cancel，`isSponsor` 编辑旁路语义保留）；`ExpirationFold` 与 `syncExpiration` 随协议键退役删除；
- **网关**：`GatewayController` 仅易支付——验签（沿 SDK 字节级算法含 '0' 跳过语义）→ `param` 三段式 `userBinding|tierKey|cycles` 定购买（金额反查废除的拍板延续，权利由站点档位配置 × 周期数在签名参数内绑定）→ 记支付 + 入队（各自幂等，重放/半完成重试都安全收敛到 success）；爱发电 webhook 路由与 order-url 端点删除，`AfdianClient` SDK 类保留不接线；
- **REST**：`GET /sponsorship/plans`（公开：epay 渠道+methods + tier 列表）、`GET /sponsorship/membership`（Bearer：active/expiresAt/nextGrantAt/balance/queue 队列视图，triggerCount/forceCancelled 摘除）、`POST /sponsorship/orders`（Bearer：tierKey+channel+cycles → price×cycles 签名收银台）；契约加法两 DTO（MembershipState/MembershipEntitlement），front-station membershipStateSchema/tierSchema/orderCreateSchema 重造（vitest 111/111）；
- **设置页**：赞助域设置页挂「会员」一级菜单（「会员档位」页：tier repeater key/名称/单价/周期长度/每周期积分 + 易支付凭据渠道；爱发电字段块移除）——**菜单挂接修复（审查批次）**：SettingsAdmin 菜单注册默认优先级 10 早于定制页的 20，`add_submenu_page('aiya-core-membership', …)` 因父级未建被 WP 静默提升为孤立顶级路由 `/wp-admin/aiya-core-sponsorship`，SettingsAdmin 的 admin_menu 挪到优先级 30（父菜单先行）后归位为正常子页；发放 cron `aiya_core_membership_grants`（每日）；`SPONSORSHIP_ENABLED` 开关摘除、域常开；`NotificationActions` 改线（激活通知挂新钩子一次性、到期前一日扫描改读队尾 MAX(ends_at)）；`uninstall` 补队列表 drop + 发放 cron 注销 + 退役 meta 行清理；
- **i18n**：新字符串 zh_CN 全量落地；
- 测试与门禁：154 tests / 342 assertions、phpcs、phpstan 全绿；运行时实测：0.50.0 迁移三件套（队列表/orders 加列/meta 删行）、双购买顺序排队（第二单 starts_at = 第一单队尾）、重复激活 409 `aiya_duplicate_order`、advance 周期发放（桶过期 = 周期终点）与追账幂等、取消即失效、真签名网关回调 200 + 重放 200（幂等）+ 篡改签名 400、membership/plans REST 视图全对，测试数据（队列/账本/订单/用户/session/日志）已清零。
- **代码审查修复（同批收口）**：① `advance()` 零积分档（creditsPerCycle=0）不再撞 `grant(0)` 拒绝——跳过发放直接推进计数器（原实现永久卡死 + 每日 error_log）；② CAS 改单调式 `cycles_granted < %d`——多周期追账一轮全部收敛（原实现计数器每次 cron 只进一档，桶靠账本去重兜底）；③ advance 无界扫描改 keyset 分页（500/批）；④ HttpCache 会话档补 `credits(/|$)` 与 `sponsorship/membership`——登录态私有读不再落 `public` 缓存档（实测双路由均 `private, no-store`）；⑤ `spend()` 重复 ref 由 `aiya_db_error` 改判 `aiya_credit_duplicate`（并修 wpdb::query 的 flush 清 last_error 陷阱——ROLLBACK 前先捕获）；⑥ 0.50.0 迁移不再删使用中的通知 marker `aiya_core_sponsor_state_noticed`（到期扫描仍在用；uninstall 清理保留）；⑦ `POST /sponsorship/orders` 补限流 10/600s；⑧ front-station client 写白名单摘死路由 `sponsorship/redeem`、补 `credits/(checkin|redeem)`，新增 tiers/createOrder/redeemCode/creditsBalance/creditsEntries/creditsCheckin 方法（vitest 111/111、tsc 除既有 TS5101 干净）；
- **支付页拆分（0.51.0，站长要求便于后续拓展）**：收银台凭据/渠道/回调日志从「会员档位」页拆出独立设置页「支付」（`sponsorship-payments`，option `aiya_core_sponsorship_payments`，挂会员入口），`SponsorshipSettings::read()` 合并双 option 单形状输出；**渠道三开关改 multicheck 多选**（存字符串数组，ValueNormalizer 白名单归一化）；`channels.methods` wire 形状由对象改列表（新网关加条目不改形状）；`POST /orders` 渠道校验改 `in_array(epayMethods)`（未勾选渠道下单 502 实测）；front-station tiersPayloadSchema 同步（vitest 111/111、tsc 干净）。

### Dev Tools 域（WPJAM 诊断面迁移）—— ✅ 已完成（0.51.0）

2026-09-13 拍板（WPJAM Basic 五项调研的第二批）：debug 专用的 Sample 沙盒页从 Admin/ 迁入新域 `Domain/DevTools`，WPJAM 的四个后台诊断面复刻到同一菜单之下；前端展示类能力（短代码渲染、相关文章的 PHP 端 HTML 附加）不迁移——渲染归 Astro：

- **域与菜单**：`DevToolsModule` 单点 WP_DEBUG 门控（关掉常量即整域消失：菜单、页面、admin_post 处理器全不注册；SamplePage 保留二次防御）——一级菜单「开发工具」（`aiya-core-devtools`，位置 100，原 Sample 槽位），子菜单 系统信息（与父 slug 重合作落地页）· Crons · Rewrites · 短代码 · Sample（经设置管线以 `parent` 挂入，SettingsAdmin 渲染）；`Admin/SampleSettings` 删除，字段定义迁 `DevTools/SamplePage`（26 字段与 `aiya_core_sample` option 原样，无数据迁移）；
- **系统信息**（`ServerStatusPage`，WPJAM server-status 复刻）：服务器卡（主机/内网 IP/OS/文档根 + /proc 核数/内存/运行时长/空闲率/负载）· 版本卡（Web 服务器/MySQL/PHP/Zend/WP/TinyMCE 对照核心最低要求）· PHP 扩展（+Apache 模块条件显示）· Opcache 卡（内存/命中率/键位三条 CSS meter 条 + 版本 + 关键配置项 + 重置按钮）；**/proc 读取改 is_readable 逐文件守卫**（原版要求 open_basedir 含 /proc 才读，Docker 常态空值下这些行静默消失的缺陷修正）；图表用原生表格 + CSS 条，不引外部图表库（WPJAM 依赖 Morris.js/Raphael CDN）；
- **定时作业**（`CronsPage`，WPJAM wpjam-crons 复刻）：cron 数组展平列表（时间/相对时长/Hook/频率，hook 子串筛选 + 分页 20），行操作 立即执行（`do_action_ref_array`）/ 删除（`wp_unschedule_event`），孤儿作业警示条（hook 无监听器计数）+ 一键清理，新建表单（hook 须 `has_filter`、频率 单次 + `wp_get_schedules`、站点本地时间 datetime-local）；事件 id 三段式 `ts|key|hook`（**核心用 md5(args) 作重复事件键**——id 解析按不透明字符串处理，动作用前先对活 cron 数组复核）；WPJAM 的 `wpjam_scheduled` 权重作业队列不迁移；
- **Rewrites**（`RewritesPage`）：缓存规则只读列表（正则/查询两列 + 筛选 + 分页 50）+ 手动刷新规则按钮；WPJAM 的规则裁剪设置不迁移（对应开关已归 Optimization 域所有）；
- **短代码**（`ShortcodesPage`）：`$GLOBALS['shortcode_tags']` 全量只读列表（标签 + 回调可读标签：函数名/`Class::method`/`Closure`），筛选 + 分页；WPJAM 自带短代码（视频解析器等）不迁移，本站渲染走 Parts 框架；
- **图标列表**（`IconsPage`，WPJAM 图标列表 wpjam-icons/dashicons 复刻，只读无交互）：解析核心 `wp-includes/css/dashicons.css` 抽 `.dashicons-{name}:before` 图标名（去重保序、剔除 `.dashicons-before` 应用字形工具类；WPJAM 逐行 fgets 同语义改单次正则），349 个图标渲染为原生样式卡片网格（dashicons 字形 + 名称）；拍板收缩为纯清单——无点击/弹窗/筛选，样式表不可读给错误提示而非 fatal，admin.css 增 `.aiya-icons-grid`/`.aiya-icon-card` 两段样式；
- **样式与测试**：admin.css 增 `.aiya-devtools-bar` meter 条；新单测 10 个（cron 展平/特例 hook 回环/md5 键/畸形 id 拒绝、回调标签四分支、空闲率与百分比钳制）；i18n zh_CN 87 条全量（未翻译 0 条）；phpcs、phpstan、phpunit 164 tests / 385 assertions 全绿；运行时实测：菜单注册与排序、五页 admin 渲染、cron 排程→查找→立即执行→删除链路、孤儿检测与清理、zh_CN 输出（开发工具/系统信息/只执行一次）。


### 批量切换文章类型 —— ✅ 已完成（0.52.0）

2026-09-13 拍板（自研功能，替代 WPJAM post-type-switcher 只切单篇且不迁移内容的形态）：文章列表批量操作新增「切换文章类型」，在 post/page/resource 三张列表屏的 bulk action Select 挂 `aiya_switch_type`；应用操作时弹出 jQuery UI 原生对话框（`wp-jquery-ui-dialog` 皮肤，`admin_footer-edit.php` 输出隐藏对话框标记 + `jquery-ui-dialog` 句柄挂内联 JS 拦截列表表单提交）选择目标类型——含选中计数行（JS 侧填数）、排除当前类型的选项、确认后注入隐藏字段随表单 GET 提交，服务端仍是纯批量往返；选中分类/标签后进入的即是同一列表屏，覆盖「从分类法入口批量迁移」的路径：

- **实现（实施中简化拍板）**：只切类型、术语不迁移——`Domain/Content/PostTypeSwitcher` 仅 `wp_update_post` 换 `post_type`，术语落核心默认行为（切入 `post` 时核心盖默认分类 uncategorized；离开的类型其词法关系原样保留为惰性数据，目标类型不建任何术语）；置顶文章离开 `post` 自动 unstick（核心换类型从不释放置顶，但置顶只对 post 有意义）；曾实现的跨类型术语映射迁移（category→category-role、标签→选定词法、slug/name 匹配复用或新建）按拍板整体撤除；
- **权限**：目标类型 `edit_posts` 一次性校验 + 每行 `edit_post`，不满足计 skipped；同类型跳过；批量 nonce 由核心 edit.php 在 handler 过滤器前统一校验，结果经重定向参数出计数通知（_n 单复数）；
- **i18n**：8 条 zh_CN 全量（未翻译 0）；phpcs、phpstan、phpunit 164 tests 全绿；运行时实测：post→resource（置顶释放/旧术语保留）、resource→post（默认分类盖入/标签清空——核心默认行为）、同类型 skip、非公开类型 skip、草稿状态保留、过滤器挂载确认。


### 批量移动术语到其他分类法 —— ✅ 已完成（0.53.0）

2026-09-13 拍板（0.52.0 切换文章类型的姊妹刀）：九张契约分类法列表屏（edit-tags.php：category / post_tag / page_category / resource_category / 五个资源标签词法）的批量操作新增「移动到其他分类法…」，应用时弹同款 jQuery UI 对话框单选目标（8 个其他契约分类法 radio，含中文可读标签），确认后按 `handle_bulk_actions-edit-{taxonomy}` 过滤器分派——术语屏的批量 nonce（`bulk-tags`）由核心 edit-tags.php 在分派前统一校验，列表表单 POST 提交（`delete_tags[]` 复选框），`Admin/BulkDialogBehavior` 抽为两模块共享的配置驱动弹窗行为（form/checkbox/文案全走 JSON 配置）：

- **服务**（`Domain/Content/TermTaxonomyMover`）：三遍式迁移——①目标词法中按 slug → name 复用现有术语，否则新建（携带名称/slug/描述）；②父级术语同批移动时按映射恢复层级（目标非层级词法则坍缩为顶层）；③`get_objects_in_term` 把源术语的全部对象追加到目标词法（append 不去重已有），随后删除源术语——词表零残留；删除分类时对象被核心自动落到默认分类（`wp_delete_term` 语义），延续「落默认值」拍板；同 slug 跨词法为独立术语行（WP 7.1 已实测非共享）；
- **权限**：源与目标词法 `manage_terms` 双向校验，非法目标（非契约词法/同词法）整批 skipped；通知走重定向计数（_n 单复数）；
- **i18n**：15 条 zh_CN 全量（未翻译 0）；phpcs、phpstan、phpunit 166 tests / 394 assertions 全绿；运行时实测：分类→标签（对象跟随、源删除、余项落默认分类）、标签→资源词法、父+子同批移动层级保持、复用已有目标术语、非法目标拒绝、批量/分派过滤器挂载与对话框布线（非契约词法屏静默）。

### 积分账本语义收敛（0.51.0 迁移，随 0.53.0 批次收口）—— ✅ 已完成

站长按业务流程复述对照后的拍板（计划文档 `docs/credits-membership-plan.md` §0 第 12 条 + §0a）；**范围硬边界：只动 `Domain/Credit`，会员域代码零改动**（EntitlementService 签名/标记/防重语义原样，幂等载体在账本层内部切换）：

- **FIFO 修正**：`spend()` 桶序 `ORDER BY expires_at IS NULL ASC, expires_at ASC, id ASC`——永不过期的桶（admin 编程发放允许 null）是最后才烧的储备；旧排序 MariaDB 升序 NULL 在前，实测坐实过反序；
- **幂等键与去向标记分离（核心刀）**：账本定位为 **API 式预存扣费**（点一次下载扣一次），不是积分商品交易——`UNIQUE(source, ref, user_id)` 把 out 行也当幂等键、同一去向第二次消费 409，等于 schema 替业务拍死按次计费。改为 `dedupe VARCHAR(80) DEFAULT NULL` + `UNIQUE KEY dedupe_key (dedupe, user_id)`：in 行推导 `source:ref` 参与唯一约束（签到/兑换码/会员周期防重语义不变，会员域原签名调用同周期二发 409 实测），out 行 NULL 不去重（MySQL 唯一键语义）= 按次计费天然放行；`spend()` 增可选 `?string $dedupe` 形参（未来一次性领取令牌用，默认不约束）；`ref` 降级纯去向/来源标记；迁移 `upgradeToDedupeKey()`（SchemaVersionRunner 0.51.0，CreditModule 持有）：`SHOW COLUMNS/INDEX` 探测 + `ALTER TABLE` 幂等，实施中修正初版回填先于加列的顺序缺陷；管理页手动发放去时间戳随机后缀（备注即 ref，重复备注 409 带专有 zh 提示）；
- **清理保留期统一**：过期未耗桶从"次日即删"并入 `credit_retention` 保留期——过期行在窗口内可查（前端可渲染"何时过期作废"），活桶任何分支都不命中；cron docblock 同步；
- **明确不做**：冲正/退款原语；entries 筛选参数（混合可接受，有需要再补）；下载侧重复点击防抖（归下载调度）；
- 验收实测五条全过：FIFO（null=10/soon=7）、同 ref 连续两次 spend 均成功、同日式重复 grant 409、显式 dedupe 令牌第二次 409、过期桶保留期内可查；phpunit 166/394、phpstan、phpcs 全绿。
- **上线前全量审查（未上线干净迭代标准）**：库存清点（两域文件/表 0 行/六个 aiya cron/三 option）与计划全文对照后两处修复——① **回调验签语义收敛**：适配器接口增 `callbackFailed(query): bool`（签名无效 = 400 让平台感知篡改；签名有效但不可激活 = 200 停止重试），清除控制器里"new 裸 EpayClient 再验一遍"的抽象泄漏（原先同一签名验 2–3 遍）；② plans 端点 `channels.epay`/`methods` 统一由适配器回答（enabled = 开关 && 凭据完整，工厂 null 语义自洽）。三态实测：有效+可激活（desc 非 null/failed=false）、有效+不可激活（null/false → 200）、篡改（null/true → 400）。**确认无缺口面**：积分/会员两域所有表 0 行、退役协议键零残留、迁移链 0.24→0.49→0.50→0.51 对空库与现库均幂等、uninstall 覆盖两新表/六 cron（含前批 chron）/退役 meta、契约快照与前端 zod/vitest 一致、HttpCache 会话档覆盖 credits/membership、i18n 零缺失；
- **支付网关薄适配（同批，四段业务流程拍板第 4 条）**：新 `PaymentGateway` 接口（id/enabled/channels/createPayment/verifyCallback——支付是值描述，网关不解析金额为权益）+ `EpayGateway` 适配器收编全部 Epay 专属逻辑（凭据读取、签名组装、`binding|tierKey|cycles` 三段 param、回调验签解析）；SponsorshipController/GatewayController 只依赖接口（`gateway()` 工厂单点换网关），plans 的 `channels` 改由 `gateway->channels()` 输出；新增网关 = 一个适配器类 + 设置字段，域流程零改动；**会员域权益查询**：`MembershipService::currentTier()` 返回当前覆盖档位（active 行中窗口最晚者，编辑旁路不视为档位），供后期拓展权益逻辑。实测：适配器 createPayment 签名 URL、未勾渠道 502、真签名回调经适配器解析入账（payment + queue 双行、currentTier 返回 gold）、篡改签名 null；HTTP 端到端 200 全链路。
- **兑换码重写为兑换会员（0.54.0，站长拍板整体重写不继承旧表）**：新表 `wp_aiya_redeem_codes`（tier_key/cycles 语义，DATETIME GMT），旧 `wp_aya_convert_codes` 由 0.54.0 迁移直接 DROP（CreditModule 0.49.0 codes 迁移同步摘除，codes 归属归还赞助域）；核销 = 原子认领 → `activateFromPayment()` 入队（order_id = 码值幂等，与付费订单同路径），积分走常规周期发放绝不预发；重复兑换 409、ghost 档位码 409、激活失败回滚码；`POST /credits/redeem` 路由不变响应换 `MembershipCodeGrant`（tierKey/tierName/cycles），`CreditGrant` 保留给签到；前端 zod/manifest/快照同步（vitest 113/113）；后台表单改 tier 下拉 + 周期数、列表列 Tier/Cycles；uninstall 双 drop 兜底；i18n 7 条 zh_CN 全量。
- **合并推断补刀（同批）**：站长四段业务流程合并复述对照后——① **兑换码永久值**：`valid_days = 0` = 永不过期（账本 null 到期形态，FIFO 天然最后烧），generate/核销去掉 `max(1,…)` 钳制、后台表单 min=0 + 描述、列表 `0` 渲染「永久」、redeem 响应 `expiresAt` 可 null（wire 空串，CreditEntry.expiresAt 本就可空，契约零变化）；实测永久码核销 `expires_at IS NULL`、限时桶先耗尽、`valid_days>=1` 回归无损；② **账本基础设施定稿 + 外部 API 业务归属（二次拍板：不建 Downloads 域）**：`Domain/Credit` 定稿为记账基础设施（grant/spend/balance/entries 四原语，不知业务语义），付费下载等外部服务编排**归 `Domain/ExternalFiles` 域执行**——下载只是外部 API 能力之一，后期扩展同域生长，不按业务逐个建域；域内分工 = 账本认"扣多少、记什么来源"，外部 API 域定"何时扣、扣完给什么"，防抖在域内（B3 恢复该域时设计）——现有 `spend()` 的 `?string $dedupe` 形参与纯字符串 source 已是全部所需接口，账本侧无新增。

### 后台信息架构微调 + OpenList 域重启用（0.55.0，2026-09-14）—— ✅ 已完成

站长三项拍板：会员档位设置与每日签到设置聚合到一个「会员」页面、剩余查账/发放面统一语义命名「积分账本」、OpenList 设置接回 core 并拉起 OpenList 容器做对接测试：

- **会员设置页聚合**：`SponsorshipModule` 设置页（`aiya-core-sponsorship`，parent 会员菜单）标题改「Membership tiers/会员档位」，承载档位 repeater；`CreditModule` 经 `aiya_core_register` 优先级 11 `addFields('sponsorship', …)` 把每日签到三键（checkin_enable/checkin_credits/credit_validity_days）追加以 `heading_checkin` 分组——注册表实测字段序 heading_tiers→tiers→heading_checkin→三键；`Admin/CreditsPage` 摘除 settingsCard/handleSettings/ACTION_SETTINGS（签到设置不再双入口）；
- **积分账本更名**：一级菜单 `aiya-core-membership` 由「Membership」改「Credit ledger/积分账本」，页面即账本（手动发放 + 直排流水 + 用户列表余额列），会员档位/支付/兑换码设置全部收拢为其下 settings 子页；
- **OpenList 重启用**：`Plugin::EXTERNAL_FILES_ENABLED` 翻回 `true`（0.29.1 的临时停用解除）——设置页 `aiya-core-oplist`（8 字段）、resource 编辑屏 `oplist_client` box（metadata 注册表 postBoxes 实测三 box 在位）、`GET /resources/{id}/attachments` 公开端点（游客实测 200 信封空 items）三件套全部恢复；phpstan 对恒真 if 的 `if.alwaysTrue` 以行内 ignore 标注（业务开关保留 null 分支给后续停靠域）；
- **OpenList 容器**：`docker-compose.yml` 增 `openlist` 服务（`openlistteam/openlist:latest`，端口 5244，`user: "0:0"`——镜像 openlist 用户 1001 无法写卷初始化的 data 目录，属上游已知问题；named volume `openlist_data`）——镜像经 docker.m.daocloud.io 转存拉取（dockerpull.cn 镜像源 blob 损坏 text/html、ghcr.io 直连 denied、Docker Hub 直连超时）；`http://localhost:5244` 实测 200，初始管理员密码在容器日志（`docker logs wp_openlist | grep password`）；
- **i18n**：修复 OplistModule 一处 `\u0027` 字面量撇号（make-pot 转义残留，导致 POT/PO 一条 msgid 对不上）；POT 786 条重建、未翻译 0、MO 重编译；WP 运行时实测「积分账本/会员档位/每日签到」即时生效；phpunit 166/394、phpstan、phpcs、vitest 158/158 全绿。
- **菜单钩子时序修复（同批）**：`Admin/SendMailPage` 与 `Admin/NotificationPage` 的 `admin_menu` 注册自优先级 20 提到 **35**——父级 AIYA Core 顶级菜单由 SettingsAdmin 在 30 注册，`add_submenu_page` 在父菜单的 `$admin_page_hooks` 条目存在前调用会把页面钩子退化为 `admin_page_*`，请求时 `user_can_access_admin_page()` 重算 `aiya-core_page_*` 查 `$_registered_pages` 落空，两页一律 403「不能访问此页面」；实测钩子名归位 + 真实登录会话（curl 走 wp-login.php）两页 200 渲染正常。

### 旧命名清算：支付流水表换名（0.56.0，2026-09-14）—— ✅ 已完成

站长拍板：core 需求闭合后追一遍旧主题 `aya_` 遗产（表/字段/meta/option/方面名），迭代替换；**不做 RENAME 迁移、不删 aiya-legacy-cleanup**——本地是开发库，直接改建表代码：

- **实库盘点结论**：旧主题四组自定义表中三组（`wp_aya_issues`/`wp_aya_issue_comments`、`wp_aya_convert_codes`、`wp_aya_flow_hub_posts`）实库本就不存在或已被 0.54.0 迁移 DROP；meta 层 `aya%` 残留 0（三个退役赞助协议键 0.50.0 已删行、`favorite_posts`/`_aya_thumb`/`aya_box_*` 由 aiya-legacy-cleanup 1.1.0 清完）；option 层旧 `aya_opt_{access|basic|land|notify|oplist}` 五方面与 `site_*`/`stie_afdian_*` 字段键实库 0 行（LegacyOptionReader 无必要）；唯一活着的旧名对象是空表 `wp_aya_sponsor_orders`（0.50.0 已加 amount/tier_key 列降级为纯支付流水）；
- **表名更换**：支付流水表 `wp_aya_sponsor_orders` → `wp_aiya_payment_orders`——`SponsorshipModule::installTables()` / `upgradeToTierModel()` 两处 DDL、`OrderService::table()`、uninstall 表清单与头注同步改名；**无 RENAME 迁移**（站点未上线无兼容义务），开发库一次性 `RENAME TABLE` 处置；fresh install 走 activate() 记 0.0.0 全链迁移时新名建表 + 0.50.0 的旧 usermeta 删行迁移不受影响；0.54.0 的 DROP `aya_convert_codes` 与 0.50.0 的协议键删行属历史迁移记录，原样保留；
- **运行时验证**：`OrderService::addPayment/exists/forUser` 对换名后实表写入/查询/去重实测通过（烟雾行已清）；三条 DDL 回调对换名后表幂等重放无破坏；phpcs/phpstan/phpunit 166 tests / 394 assertions 全绿；
- **收口**：实库中不再存在任何 `aya_` 前缀表；协议 meta 键（`like_count`/`view_count`/`_thumb`/`basic_user_avatar` 等）属现行新协议保留不改；aiya-legacy-cleanup 插件按拍板保留不删。

### 缩略图刷新改批量动作（0.56.0，2026-09-15）—— ✅ 已完成

站长拍板：文章列表「刷新缩略图」从行操作移到批量操作下拉，并通用支持所有文章类型：

- **`Admin/CardThumbnailBulkAction`**（新）：按 `bulk_actions-edit-{type}` + `handle_bulk_actions-edit-{type}` 挂载，类型集取 `get_post_types(['show_ui' => true])` 全量（剔除 attachment——其表在 upload.php），过滤器注册推迟到 `init` 20（CPT 在 init 5 才存在，含 resource 与未来 ContentTypeModule 声明的类型）；卡片管线本身类型无关（有源图即可合成），无需契约类型白名单；
- **语义**：逐篇 `edit_post` 校验 + 仅发布态合成（卡片是前台列表资产，同旧行操作口径），`refreshFor` 换新并清理被替换文件，失败/非发布/无权限计入 skipped；重定向计数通知（_n：已刷新 N 项的缩略图 / 跳过 N 项（无可刷新内容或无权限）），批量 nonce 由核心 edit.php 统一校验；
- **MediaModule 摘除**：`post_row_actions` 行操作与 `admin_post_aiya_core_card_refresh` 处理器整体移除（旧 `_thumb` 换新语义不变，save_post 直连与五分钟 cron 批不受影响）；
- 实测：五个 show_ui 类型下拉均含选项；真实登录会话 HTTP 批量往返（bulk nonce → POST → 重定向）刷新 resource 外的 post 136，`_thumb` 换新、旧文件删除、通知「已刷新 1 项的缩略图。」；草稿计入 skipped 且不写 `_thumb`；行操作链接 0 残留；i18n 6 条新增 zh_CN 全量（POT 同步移除废弃单数条目，791 条未翻译 0）；phpunit 166/394、phpstan、phpcs 全绿。

### 术语「自定义外观」改版（0.57.0，2026-09-15）—— ✅ 已完成

站长拍板：分类法自定义 metabox 改版——标题「术语 SEO 与封面」改「自定义外观」、删除 SEO 关键词条目、新增「图标」文本条目（自由填写文本，前端自行解析为图标）；三种文章类型（category/page_category/resource_category）全部兼容，五个资源标签型分类法也挂「图标」：

- **`Domain/Content/TermExtrasModule` 重写为两个 term box**：`term_extras`（三标准分类，封面 thumbnail_id media 控件 + icon 文本）与 `term_icon`（文章「标签」post_tag + 五资源标签词法，仅 icon；post_tag 为 0.57.0 收口补齐——「标签，以及资源类型的多个标签分类法也需要图标」），标题统一「Custom appearance/自定义外观」；字段仍经 Metadata 逐键 term meta 存取（icon 的 meta 键即 `icon`）；**`seo_keywords` 条目整体退役**——无任何 API/展示消费方，存量 term meta 行按未上线拍板当死数据不做迁移；
- **契约加法演进**：`Api/Contract/Term` 增可空 `icon` 字段（PostPresenter::term() 读 term meta，空串归 null），`/terms` 与文章内嵌 categories/tags 共用同一 DTO 全部带出；快照重建 + 前端 `termSchema` 加 `icon: z.string().nullable()`（vitest 158/158）；
- 实测：term box 注册表（标题/分类法/字段序）与前台 `/terms?taxonomy=category` icon 输出、内嵌术语 icon 透传全部核验；i18n 增 4 条删 3 条 zh_CN 全量（792 条未翻译 0）；phpunit 166/394、phpstan、phpcs 全绿。

### /terms 全词法枚举 + Term.vocabulary（0.58.0，2026-09-15）—— ✅ 已完成

站长拍板：补接口 DTO，让 `/terms` 返回类型的所有分类法——原实现 `taxonomy=tag` 只吐 `wpCategoryTaxonomy()` 命中的第一个标签词法（resource 只出 resource_original），资源五个标签词法无法枚举：

- **`Api/Contract/Term` 增 `vocabulary` 字段**（必填 string，排在 icon 前）：`taxonomy` 保持契约分组名（category/tag）不变，`vocabulary` 给出归属词法的代码名（category/post_tag/page_category/resource_category/resource_original/…），前端按它分组 facet；`PostPresenter::term()` 直接取 `$term->taxonomy`，内嵌 categories/tags 与 /terms 共用同一形状；
- **`presentTerms()` 重写为按 `PublicType::taxonomies` 全表遍历**：`taxonomy` 参数收窄语义为「分组过滤」（category=仅分类组、tag=该类型全部标签词法、缺省/all=全部词法打平），路由 args 同步（required 移除、default all、enum 加 all）——`?taxonomy=tag&type=resource` 由此返回五个词法合并列表（修掉 resource_original-only 旧缺陷）；
- **前端同步**：`termSchema` 加 `vocabulary: z.string().min(1)`，`termsQuerySchema` taxonomy 加 `all` 缺省值，`client.terms()` 默认改 `all`（既有 `'category'/'tag'` 调用点全部兼容）；快照重建 vitest 158/158；
- 实测：`?type=resource` 平铺 9 术语跨 resource_category/resource_author/resource_other 三词法、`?taxonomy=tag&type=resource` 两词法合并、post 类型 category+post_tag 双词法、page tag 过滤 0 条、内嵌术语带 vocabulary；phpunit 166/394、phpstan、phpcs、tsc 全绿。

### 会员菜单落地页改为设置表单（0.59.0，2026-09-15）—— ✅ 已完成

站长拍板：会员菜单的第一个页面改为会员设置表单而不是积分账本：

- **Registry 页升顶级**：`SponsorshipModule` 设置页 slug `sponsorship`→`membership`（顶级菜单 slug 保持 `aiya-core-membership`），title「Membership settings/会员设置」、menu_title「Membership/会员」、position 27、不再挂 parent——档位 repeater + 每日签到三键就是菜单落地页；OPTION_NAME `aiya_core_sponsorship` 保持（内部存储键，无契约面）；`CreditModule` 贡献字段改 `addFields('membership',…)`；
- **SettingsAdmin 顶级页加镜像子菜单**（core idiom，空 callback 防双渲染）：顶级菜单落地页=自身，不再被 re-parent 到第一个注册的兄弟页——顺带把 AIYA Core 菜单落地页从漂移到 Optimization 修回「前台设置」（符合 FrontendModule 注明的意图）；
- **CreditsPage 降为子菜单**：slug `aiya-core-membership`→`aiya-core-credits`（积分账本，用户列表余额列与操作回跳链接随常量自动跟随），`admin_menu` 优先级 35 + 子菜单位置 1（紧跟设置表单）；**ConvertCodesPage 同批 20→35**——父顶级菜单由 SettingsAdmin 在 30 注册，20 时机注册子菜单会重演 SendMailPage 的 `admin_page_*` 钩子退化 → 403；
- 实测（真实登录会话）：会员菜单落地 `<h1>会员设置</h1>`（档位列表/周期长度/周期积分/每日签到字段在位），子菜单序 [会员设置, 积分账本, 支付, 兑换码]，四页全部 200 正常渲染，积分账本钩子 `%e4%bc%9a%e5%91%98_page_aiya-core-credits` 正确注册、退化钩子清零；phpunit 166/394、phpstan、phpcs 全绿；i18n 增 Membership settings 删 Membership tiers（792 条未翻译 0）。

### 图标调整 + 后台页面注册全量核查（0.60.0，2026-09-15）—— ✅ 已完成

站长拍板：资源类型菜单图标改 `dashicons-book-alt`、会员菜单组改 `dashicons-awards`；随批对全部后台页面注册逻辑做整体核查（后台页面基本闭合的收口审计）：

- **核查矩阵（运行时探针，admin 构建完整菜单后逐页判定）**：五个顶级菜单（AIYA Core/会员/轻社区/图床/开发工具）+ 十五个子页共 20 页——① 注册钩子与请求时解析钩子逐一比对：`$_registered_pages` 全部命中、退化 `admin_page_*` 钩子清零；② 回调 `has_action` 全部在位（SettingsAdmin 顶级页镜像子菜单为空 callback 属设计，渲染走顶级自身钩子）；③ admin 访问判定 20/20 通过；④ 落地页语义：AIYA Core/会员/开发工具三组首子菜单均为自身镜像，轻社区/图床为无子菜单单页顶级；⑤ DevTools 子菜单序 [镜像, crons, rewrites, shortcodes, icons, sample]。
- **结论**：0.55–0.59 期间修的三处钩子时序问题（SendMail/Notification 35、Credits/Codes 35、SponsorshipModule 页升顶级+镜像）已覆盖全部风险点，当前无遗留缺陷；图标两处生效实测（菜单渲染 class 与 post type `menu_icon`）。
- phpunit 166/394、phpstan、phpcs 全绿。

### 爱发电会员激活接回（0.61.0，2026-09-15）—— ✅ 已完成

站长拍板（计划见 `docs/afdian-membership-activation-plan.md`，§6 五个决策点全部按推荐）：接回爱发电平台的会员激活，两条激活路径，终点统一 `activateFromPayment` 入队（流水/队列双 order_id 唯一键防重放）：

- **路径 B（webhook 自动激活）**：`AfdianGateway`（`PaymentGateway` 第二实现）——`verifyCallback` 验签按官方 WebHook 文档为 **RSA SHA256**（`AfdianClient::verifyWebhook` 重写：平台公钥内置常量，`data.sign` base64 验签，覆盖订单 `out_trade_no+user_id+plan_id+total_amount` 拼接；早先的 md5(token+data+ts) 读法是出站开放 API 的签名机制，不适用于入站 webhook）后解析 `custom_order_id`（XDE 绑定码 → 用户）+ `plan_id`（与后台单一绑定 `afdian_plan_id` 比对，命中取 `afdian_tier_key` 指向的档位）+ `cycles = month`（钳 1–36）；`GatewayController` 增 `POST aiya/sponsorship/v1/afdian/callback`：签名错 400、有效但不可激活（无绑定/方案未绑定）200 忽略、命中则 `addPayment`（`afd_` 前缀流水）+ `activateFromPayment`，响应沿平台 `{ec:200,em:'done'}` 约定；支付页爱发电组（开关/**方案 ID + 绑定档位 Key 单一映射**/user_id/token/日志/webhook 地址说明）；`GET /sponsorship/plans` 增 `channels.afdian` 开关；
- **路径 A（订单号复用兑换框）**：`POST /credits/redeem` 增可选 `channel`（`redeem` 缺省 / `afdian`）——`AfdianActivator`：专属限流（5 次/10 分钟）→ `ping` 按平台 ec 分流（0 无响应 502、400002 时钟 502、400004/400005 凭据 502）→ 已激活预检（409）→ `queryOrder`（404）→ 方案反查（422 `aiya_plan_unbound`，自选金额订单无 plan_id 同样拒绝）→ 记流水 + 激活 → `MembershipCodeGrant`（前端零新形状）；
- **`GET /sponsorship/afdian/order-url?month=`**（登录态，无 tierKey 参数）：返回绑定方案的个性化下单深链（`custom_order_id` 携带绑定码），未绑定 422——沿用 0.25.0 历史路由名，前端既有 `afdianOrderUrlResponseSchema` 直接复活；`EntitlementService` 激活档位快照形状放宽（price/afdianPlanId 可选键）；
- **传输层缺陷修复（同批，实测暴露）**：`AfdianGateway::fromSettings` 原先未注入 HTTP 传输闭包（transport=null），任何请求都不发出而直接落到「接口不可用」——注入 `wp_remote_post` 闭包后用站长真实凭据实测：ping ec 200、假单号 404，链路真实触发；
- **单测**：`AfdianGatewayTest` 七条（签名回环验签/篡改 400 语义/未绑定忽略/月数钳制/深链构造/未绑定档位拒绝/空 plan 隐藏跳转）+ `SponsorshipSettings` 计划反查两条；
- **实测**（测试凭据 + 真实 HTTP）：签名推送 → 流水 90 元 source=afdian + 队列 3 周期 active；同推送重放幂等 200 零新增；篡改签名 400；未绑定方案推送 200 忽略零新增；plans 透出 afdian=true 与 tier 绑定；order-url 出个性化深链/未知档位 404；redeem afdian 通道 ping 不通 502（管道验证）；本地兑换码路径回归无损；i18n 18 条 zh_CN 全量（810 条未翻译 0）；phpunit 174/419、phpstan、phpcs、vitest 158/158、tsc 全绿；测试数据已清理。
- **官方文档合规修订（同批，站长提供官方 WebHook 文档后核对）**：① `AfdianGateway::verifyCallback` 补 `data.type === "order"` 与 `order.status === 2`（交易成功）双重校验——未支付/退款/商品类推送一律忽略（此前只验签名不验交易状态，属真实缺口）；② 路径 A `AfdianActivator` 查单后同样补 `status === 2` 校验（422 `aiya_order_not_paid`）；③ 响应合规确认：回调路由恒回 `Content-Type: application/json` + `{"ec":200,...}`（平台仅校验 ec==200），成功/忽略均为 JSON ec 200，签名错才 400 非成功响应；官方完整字段形状（product_type/sku_detail/discount/address_* 等）实测通过；单测补 status≠2 与 type≠order 两条反例（176/422）；i18n +1（811 条未翻译 0）。

### Smilies 表情包域：目录约定 + 读时正则替换（0.62.0，2026-09-15）—— ✅ 已完成

站长拍板：命名 **Smilies**（非 Emoji）；素材走**目录约定直接投放**现成表情包（阿鲁、AC娘等），**不做上传管理区**；语法 `::代码::`（英文双冒号包裹）；WP 原生 ASCII 表情（use_smilies）一并禁用；本期仅后端基础实现，评论面渲染与前端选择器留接线批次定前后端分工。

- **`Domain/Smilies/SmiliesRegistry`**：扫描 `wp-content/smilies/{包名}/{代码}.{webp|png|gif|jpg|jpeg}` 生成映射——代码 = 文件名去扩展名（1–24 字、禁冒号/空白/markup 字符；**纯数字允许**——真实包 AC经典款 `01`–`149` 与 ARU `0000` 起即纯数字命名，初版禁纯数字被实测否决；`::数字::` 在自然文本中无碰撞面，命中即转换属可接受语义），非法文件静默跳过，包名 = 目录名，URL 经 `content_url()` 分段 `rawurlencode` 兼容中文文件名；跨包重名先包先得（字母序）。**刻意不做持久缓存**：包规模至多数百文件，每请求一次 scandir 亚毫秒级，而目录 mtime 在 Windows bind mount 上不可靠，transient+指纹失效方案实测同秒内建文件 mtime 不变——无缓存即无失效问题，无 DB 往返；目录不存在/为空 = 空表，全链路无错误路径。
- **`Domain/Smilies/SmiliesRenderer`**：`render()` 沿 convert_smilies 手法——`wp_html_split()` 切块（偶数下标为文本节点）+ `code|pre|style|script|textarea` 状态机跳过，仅文本节点跑 `::(注册代码白名单交替)::` 正则（按字节长度降序，`preg_quote` 逐码转义）；**正则刻意不带 lookaround 守卫**——守卫会把相邻连打的 `::a::::b::` 全部挡死（共享冒号串让任何边界断言失效），精确白名单已足够防误伤，退化冒号串 `:::x::` 只会留字面冒号不会出错误图。产物 `<img src alt class="aiya-smilie">`；`strip()` 供摘要清码。**存库始终保留 `::代码::` 原文**，读时转换，换包对存量内容即时生效；短代码 `[tag]` 解析不相干。
- **接线**：`PostPresenter::rendered()` 在 `the_content` 结果外包 `render()`（post/page/resource 详情 + 阅读时长自动覆盖），`excerpt()` 追加 `strip()` 清摘要残码；`DiscussionPresenter` 的 `present()/detail()/reply()` 三处 contentHtml 投影包 `render()`——`tags()`/`images()` 保持吃原始库内容（转换在后），表情图**不会混进九宫格 images 数组**，后端零豁免逻辑；`CommentsController` 不动（body 纯文本契约原样透传，`::代码::` 按字面显示，渲染归属留接线批次拍板）。
- **原生 ASCII 表情禁用**：`SmiliesModule` 挂 `pre_option_use_smilies` 钉 `'0'`（沿 ThemeSupportModule 钉 `image_default_link_type` 的 pre_option 模式；get_option 对 `false` 返回值放行，必须回 falsy 非布尔值）——convert_smilies 全面空转，写作页复选框失效但不写库，停用插件即还原；HeadlessModule `disable_emoji`（s.w.org emoji 脚本）独立不受影响。
- **契约加法**：`SmiliesItem`（code/url）+ `SmiliesPack`（slug/items），`Site` 尾部追加 `smilies`（默认 `[]`，v1 加法）；快照重生成，前端 `contracts.ts` 增 `smiliesItemSchema`/`smiliesPackSchema` + `siteSchema.smilies`，mock 与 client 夹具补字段——仅契约管道同步，无渲染接线。
- **单测**：`SmiliesRegistryTest`（扫描跳过非法项/包序/URL 分段编码/跨包重名/实例即扫即见/代码规则五类）+ `SmiliesRendererTest`（正文命中/长码优先/标签属性不碰/code+pre 跳过/未注册与时间字面/相邻连打全转换/退化串留字面冒号/strip/空表 no-op）；垫片补 `wp_html_split`（简化 core 切分形状）、`esc_url`/`esc_attr`/`content_url`。
- **实测**：测试包阶段（aru/ac 中文名）与站长真实投放三包各过一遍——AC彩娘 50（下载平台乱码文件名）/AC经典款 150（纯数字 `01`–`149`+`76web`）/ARU 306（纯数字 `0000` 起+`x` 前缀）全量收录 506 条，CJK 包名与乱码码 URL 段编码正确；文章 `::01::`/`::0000::`/`::x010::` 精准命中、未注册 `::150::`（无 150.png）与 `::9999::` 保持字面、相邻连打全转换、原生 `:-)` 保持字面；AC彩娘乱码码（正则元字符 `%@$~()[]{}` 等）经 preg_quote 路径正常渲染；摘要无残码；评论 body 原样；社区帖/回复 contentHtml 出 `<img class="aiya-smilie">` 且 images 抽取仅含真实内容图；删包后 `/site` 立即反映（无缓存）；测试数据全清理；phpunit 196/484、phpstan、phpcs 全绿，vitest 162/162 全绿。注意：三包全开时 `/site` 载荷约 50KB（未压缩）——接线批次评估选择器/评论渲染的取数方式时可考虑独立端点或懒加载。
- **留接线批次**：评论面渲染（前端解析 vs 后端出 HTML）、评论框/社区编辑器表情选择器、前端 `safeContent`/`sanitizeDiscussionHtml` 的 img class 放行与讨论面表情图豁免（讨论面表情图现阶段会被 `sanitizeDiscussionHtml` 剥除、九宫格不收——接线时须处理）、DiscussionCard 摘要清码。

### Smilies 独立读端点（0.63.0，2026-09-15）—— ✅ 已完成

站长拍板：表情映射从 `/site` 拆出——真实三包 506 条约 50KB，挂在 shell 端点上让每个页面载荷都背着不需要的数据。

- **新端点 `GET /smilies`**（`Api/Rest/SmiliesController`，公开读 + 中央信封）：返回 `list<SmiliesPack>` 裸数组（沿 `/terms` 裸数组惯例）；`HttpCache` 把 `smilies` 归入 shell 组（`public, max-age=300`）——映射只在站长投放/增删文件时变化，浏览器侧 300s 缓存正合适；`/site` 摘除 `smilies` 字段回 10 字段原形（0.62.0 同批字段未发布即修正，v1 基线从未含它，快照/vitest 无破坏）。
- **前端管道**：`contracts.ts` 摘 `siteSchema.smilies`、增 `smiliesResponseSchema`（`itemEnvelope(z.array(smiliesPackSchema))`），`client.smilies()` 新方法，mock 增 `smilies` 夹具路由（空数组）。
- **实测**：`/site` 无 smilies 字段；`/smilies` 出 3 包 506 条且 `Cache-Control: public, max-age=300`；phpunit 196/481、phpstan、phpcs 全绿，vitest 162/162。

### Smilies 渲染接线：评论后端挂载 + 三面前端放行（0.64.0，2026-09-15）—— ✅ 已完成

站长拍板：渲染解析统一在后端处理，作用面 = 自定义社区/全部文章类型/评论；社区面明确要求挂在图片列表净化之后再解析（兼容九宫格），文章面在 content 读时挂载（避开首图缩略提取）。

- **挂载点核查结论**：社区与文章的后端挂载 0.62.0 已落且顺序正确——`DiscussionPresenter` 的 `tags()`/`images()` 吃原始库内容、`render()` 在其后（present/detail/reply 三处），`PostPresenter::rendered()` 读时包 `the_content`（首图提取在 save/cron 读原始 post_content，与读时转换零交集，token 非 `<img>` 永不被选为首图）。真正的缺口全在前端清洗层 + 评论后端缺挂。
- **评论后端挂载**：`CommentsController` 注入 renderer，`present()` 增 `bodyHtml = render(esc_html(comment_content))`（先实体化后渲染——存量纯文本被 wp_html_split 视为文本节点，token 正则安全跑，只可能注入白名单表情图）；`body` 保留原样 token 形式（契约加法零破坏；Comment 形状不在契约快照，无快照动作）。**核查新发现**：通知摘录（`NotificationActions` 评论 16 词 excerpt）原样携带 token——沿 nullable 默认构造注入 renderer，摘录改先 `strip()` 再 `wp_trim_words`（与文章摘要投影同语义）。
- **前端清洗层放行**（`content.ts`）：① `safeContent` img attrs 加 `class`（文章面保住 `.aiya-smilie`，src 照走 /media/ 代理）；② `sanitizeDiscussionHtml` 加 `img` 白名单 + `exclusiveFilter` 只放行 class 含 `aiya-smilie` 的图——内容图照旧剥除防九宫格双渲染，帖子与回复同一函数闭合；③ 新增 `sanitizeCommentHtml`（allowedTags 仅 img、同款 exclusiveFilter、实体重编码），`CommentSection.tsx` 改渲染 `bodyHtml` 过此函数（`whitespace-pre-line` 保留使换行继续生效）。实现细节：sanitize-html 的 exclusiveFilter 入参是 `frame.tag`（非 tagName），首版写错被新测试当场抓住。
- **样式**：`shell.css` 全局单条 `img.aiya-smilie { display:inline-block; height:1.25em; vertical-align:text-bottom; }`——文章/社区/评论三面共用。
- **接受项**：DiscussionCard 80 字摘要剥标签时表情图随标签消失（不破版不出错、不出残码），维持现状。
- **测试与实测**：新增 `tests/content.test.ts` 六用例（评论面表情图存活+src 代理+外来图剥除/文本实体不透传标签/讨论面豁免/文章面 class 保留）；实测评论 API 双字段（body 原样 token、bodyHtml 出 img 且恶意 `<b>` 被实体化、单冒号时间不碰）、文章详情带 class；phpunit 196/481、phpstan、phpcs 全绿；vitest 167/167、tsc 全绿。

### 全量代码审查与修复批（0.65.0，2026-09-15）—— ✅ 已完成

五个并行审查面（安全扫描 / REST 层 / 生命周期卸载 / Credit+Sponsorship 域 / Admin 层）+ 机器一致性扫描的全量审计，共修复 21 项、确认 1 项接受取舍、排除并行会话在制品：

- **高危 4 项**：① `ContentQuery` 给登录用户加 `private` 状态查询缺 `'perm' => 'readable'`（对照 WP 7.1 核心源码确认无 perm 即无作者限制）——任何订阅者经公开列表可见他人私有文章，补 perm 修复；② `activateFromPayment` 队列尾读后插竞态（并发激活窗口重叠、周期积分双发）——加每用户 `GET_LOCK/RELEASE_LOCK` + finally 释放；③ HttpCache 观察者特定负载（附件签名直链/讨论权限旗标/afdian 深链）走 public 缓存——登录态 GET 一律 `private, no-store`；④ AfdianActivator 半完成激活死路（exists 预检 409 挡重试）——移除预检、依赖唯一键幂等完成半途激活。
- **中危 8 项**：WebhookLogger 写 web 根日志无访问拒绝（补 .htaccess/index.html）；TokenAuthentication 站点全域生效（限定只在 `/aiya/core/v1`、`/aiya/sponsorship/v1` 解析）；限流与访客去重仅 REMOTE_ADDR（新增 `Infrastructure/Http/ClientIp` + `aiya_core_client_ip` 过滤器供反代部署接入）；密码重置 confirm/validate 无限流（补 10 次/10 分钟）；EpayGateway 记账金额改用平台实付 money（而非设置重算）；订单号补随机熵（同秒碰撞会导致第二笔钱收了权益没了）；AfdianActivator 校验 custom_order_id 绑定（绑定他人的订单 409 拒绝抢注）；`currentTier` 补 `startsAt <= now`（未来排队行不再提前生效）。
- **低危/卫生**：DiscussionController 三处 WP_Error 补 status 500；CounterController 三路由补类型化 args；avatar 上传限流 10/h；comments parentId 允许 0；ETag 比较容忍弱验证器与逗号列表；ContractsSnapshot 孤儿 docblock 删除；login gate secret 改 password 型；PicBed render 补 `upload_files` 守卫 + 列表封顶 200 条带溢出提示；SendMail render 补 `edit_users` 守卫 + hook 匹配改 str_ends_with + 邮件 body 补 wp_unslash；NotificationPage body 补 wp_unslash；RedeemCode `used_to` 改 GMT；兑换码 duplicate 分支不再回滚砖码；档位删除后仍记流水（保留审计）；会员 cron 前清扫孤儿队列行（用户已删）；uninstall 补 postmeta `aiya_core_%` 组键、termmeta（thumbnail_id/icon/seo_keywords）、aiya-core-logs 目录、rewrite_rules 刷新；CreditSettings 改读 sponsorship 选项（修复签到设置改了不生效的真 bug——0.55.0 迁移时消费端读取源未跟随）；注册 409 账号枚举为明知取舍（代码注记）。
- **支付查账页 + 用户列表会员状态列（同批补缺）**：`Admin/PaymentsAuditPage`——会员菜单子页「支付查账」（`aiya-core-payments`，35 优先级）：`OrderService::list()` 分页倒序列出全部网关入账（可按持有者过滤），列 = 时间/用户/订单号/档位/金额/来源；用户列表新增「会员」状态列（`MembershipService::currentTier`：当前覆盖档位名 + 到期日期，链接到按用户过滤的支付查账视图）——与积分列同款模式（静态缓存、页界有界查询）。
- **清理**：删除测试遗留的 epay-verify.php / epay-order.json（web 可达调试脚本）；i18n +10 条（824 条未翻译 0）。

### Frontend 设置补 SEO/统计字段并透出 /site（0.66.0，2026-09-15）—— ✅ 已完成

站长拍板：前台设置页补站点级 SEO 关键词、SEO 描述与 Google Analytics 三个字段，并透出到 /site 端点供前端 head 渲染：

- **FrontendModule 设置页**新增「SEO 与统计」组（紧跟账本保留期组之后）：`seo_keywords`（text，逗号分隔关键词）、`seo_description`（textarea，首页 meta 描述）、`ga_measurement_id`（text，衡量 ID 如 G-XXXXXXXXXX——前端据此渲染统计脚本，留空不启用）；
- **契约加法**：`SiteDefaults` 增 `seoKeywords` / `seoDescription` / `gaId`（string，空串=未配置），`SitePresenter` 从 `aiya_core_opt('frontend',…)` 读取；快照重建 + 前端 `siteDefaultsSchema` 同步三字段（vitest 167、tsc 干净）；
- **实测**：后台设置页渲染三个字段；设置值后 `/site` 的 `defaults` 带出 `seoKeywords/seoDescription/gaId`；i18n 6 条新增 zh_CN 全量（含恢复 SeoBox 仍在用的 `SEO keywords` 误删条目；838 条未翻译 0）；phpunit 196/481、phpstan、phpcs 全绿。
- 门禁：phpunit 176/422、phpstan 0 错、phpcs 0、vitest 167、tsc 干净。并行会话 Smilies 域在制品未触碰、不计入门禁。
### 会员运营逻辑闭合：档位启用开关 + 删除守卫（0.67.0，2026-09-15）—— ✅ 已完成

站长拍板两点闭合会员运营逻辑：①档位 repeater 增 `enabled` 启用开关——停用的档位由前端从购买列表剔除；②保存守卫——仍有生效持有者的档位拒绝删除：

- **启用开关**：`SponsorshipSettings::tiers()` 透出 `enabled`（bool，缺省 true——历史行无该键视为启用）；`GET /sponsorship/plans` 的 items 透出给前端做购买列表过滤；`createOrder` 未加后端硬拒绝（前端已把停用档位踢出列表）；
- **删除守卫**：`SettingsAdmin::save()` 新增 `aiya_core_settings_validate` 校验过滤器（normalize 后、replace 前触发，WP_Error 中止保存并在设置页显示错误）；`SponsorshipModule::guardTierDeletion` 订阅——membership 页新旧档位差集里凡有 `wp_aiya_memberships` 活跃行（status=active）的档位一律拒绝，报错列出档位 key；
- 实测：删除仍有持有者的档位 → 保存被拒并显示「以下档位仍有生效中的会员，无法删除：legacy」；删除未使用档位 → 正常保存；plans 端点 items 带 enabled；i18n +4 条 zh_CN 全量（842 条未翻译 0）；phpunit 196/481、phpstan、phpcs、vitest 167、tsc 全绿。


### 对象缓存接入批（0.68.0，2026-09-15）—— ✅ 已完成

站长拍板为线上 Redis Object Cache（Till Krüss drop-in）铺路，把三个热读取面接进对象缓存（全部走标准 `wp_cache_*`，无 drop-in 时退化为每请求内存、行为不变）：

- **TokenStore 解析镜像**：`resolve()` 的自定义表点查改为「object cache 镜像（`aiya_core_auth` 组，TTL 300s）+ 世代号校验」——镜像条目携带 `{user, generation}`，generation 为 user meta `aiya_core_auth_gen` 的每用户递增计数，`revoke()`/`revokeAll()` 各自 +1；命中后比对当前世代，改密/重置全吊销**零延迟生效**（不依赖 TTL 过期），登出同样走世代失效；负镜像（`user=0`）永久成立——token secret 随机且有效性单调递减，死哈希不会复活。无镜像命中才落库，落库结果回写镜像（含「确认死亡」的负缓存，重复无效 token 不再每请求打表）；
- **/site 壳载荷镜像**：`SitePresenter::presentArray()`（新公开读法，`present()` 保持无缓存 DTO 构造器原样，契约测试入口不变）——`/site` 路由改走 `presentArray()`，组装结果整包进 `aiya_core_site` 组，TTL 300s 与 HTTP shell 档 `max-age=300` 对齐；失效只靠 TTL，刻意不做钩子——载荷折叠多页 options + 附件解析，无单一失效信号，且 5 分钟新鲜度正是 Cache-Control 头已施加给 CDN/浏览器副本的同一条契约；
- **Smilies 扫描镜像**：`SmiliesRegistry` 撤销「无持久缓存」决策（原前提是 Windows bind mount mtime 不可靠 + 无失效信号——失效信号难题仍在，但生产 Linux + Redis 下 TTL 兜底可接受）：扫描结果镜像进 `aiya_core_smilies` 组（键 `packs_{md5(目录|baseUrl)}`——URL 烤进条目，二者都属缓存身份；TTL 600s，新包十分钟内可见或 flush 即见）；键折叠目录+baseUrl 同时保证测试 fixture 互不污染；无 drop-in 的开发环境每请求重扫，行为与原先完全一致；
- **配套**：`tests/bootstrap.php` 补 `wp_cache_get/set/delete/flush` 内存垫片（带 TTL 语义）、`wpdb` 语句形状替身（prepare 按核心语义给 `%s` 加引号、按 token_hash/user_id 两种 DELETE 形状删行、按 token_hash+expires_at 匹配读行、统计读次数）、user meta / `wp_salt` / `wp_generate_password` / `current_time` / 时间常量垫片；新增 `tests/Unit/TokenStoreTest` 五用例钉死语义——镜像命中只读表一次、登出立杀热镜像（世代失配回落查表证实）、revokeAll 立杀全部热镜像、负镜像终局（未知 token 第二次不再读表）、畸形 token 零读短路；`SmiliesRegistryTest` 的「每实例重扫即时生效」用例改写为新契约「TTL 内镜像服务、`wp_cache_flush()` 后重扫可见」；uninstall.php 孤注释（transient SQL 清理已于 0.65.0 移除）改写为准确说明——transient 在 drop-in 下活于对象缓存由 `wp_cache_flush()` 兜底、无 drop-in 时靠核心每日 `delete_expired_transients`（该 cron 排程挂 wp-admin 请求，headless 首部署需登一次后台）；
- **实测与门禁**：真实环境 E2E——`/auth/login` 发 token → `/users/me` 双读 → `/auth/logout` → 同 token 立即 401（本环境无 drop-in，镜像路径由 TokenStoreTest 全覆盖）；`/site` 实测 200 + ETag + `public, max-age=300`；顺手把 SponsorshipController 订单熵源 `mt_rand()` 换 `wp_rand()`（phpcs 唯一警告清零）；phpunit 201/501（+5 用例）、phpstan、phpcs 全绿；i18n 零新增（无 UI 字符串）。版本对齐 0.68.0。

### 排版工具 metabox 改版（0.69.0，2026-09-15）—— ✅ 已完成

站长拍板三点：①box 从主栏（normal/low）移到编辑屏**右侧栏**（side/default），post/page/resource 三类编辑屏通用；②工具描述从粗体标题 label 移进 **checkbox 行内文字**——`action_checkbox` 字段补 `checkbox_label`（复用原 label 的 msgid，零新增翻译条目），`MetaboxAdmin::renderPostBox` 对 `action_checkbox` 跳过粗体标题行（该类型全插件仅排版工具使用，无波及）；③组件覆盖 **page 与 resource**——处理器本就对 wp_posts 通用，唯 `onMatchTags` 加 `is_object_in_taxonomy(…, 'post_tag')` 守卫：页面与资源贴不带 post_tag 词法（resource 走五个自定义标签词法），跳过以避免写入不可见的 post_tag 关系；
- 实测：wp-cli 直读 Registry——screens=post,page,resource、context=side、四字段 checkbox_label 均带 zh_CN 译文、post_tag 词法 post=true/page=false/resource=false；phpunit 201/501、phpstan、phpcs 全绿；i18n 零新增。版本对齐 0.69.0。

### WP 原生标题/摘要输出裁剪（0.69.1，2026-09-15）—— ✅ 已完成

站长拍板的 content 域小补丁，落 ThemeSupportModule（与 image_default_link_type 同族的「WP 原生输出裁剪」职责）：`protected_title_format`/`private_title_format` 返回 `'%s'`——两个过滤器传的是 sprintf 格式串（默认 `Protected: %s`），返回 `''` 会把整个标题清空，`'%s'` 才是去前缀留标题（保护/私密信号由 PostSummary.badges 承载，不再有字符串前缀泄漏进 API 标题与后台列表）；`excerpt_more` 返回 `'...'`（核心默认 `' [&hellip;]'`），作用于 feed 与兜底模板等 WP 原生面。配套：PostPresenter 摘要剥离正则从「仅括号形态」扩为兼容裸 `...`/`…`/`&hellip;` 尾标——excerpt_more 改动后自动摘要尾部是裸省略号，不扩正则会让续读标记漏进 API 摘要、破坏「前端持有续读呈现」的既定契约（若想要 API 摘要保留 `...`，还原该正则即可）；
- 实测：wp-cli 直读过滤器返回值 + sprintf 复合验证（`Protected: 我的秘密文章` → `我的秘密文章`）；phpunit 201/501、phpstan、phpcs 全绿；i18n 零新增。版本对齐 0.69.1。

### 模板零件词库首批 + WP 默认短代码退役（0.70.0，2026-09-15）—— ✅ 已完成

站长拍板迁移 list/col_list/collapse/alert/clip_board 五项 + 新增 button + 默认短代码退役，落 `Domain/Parts/BuiltinParts`（经 `aiya_core_register_parts` 过滤器注册，框架空目录兑现首批词库）：

- **渲染为 HTML-first（站长二次拍板简化）**：辅助格式零件直接输出原生 HTML 由前台按 HTML 处理样式，不套自定义标签——`list` 出 `ul/ol+li`（顺手修正旧版 order 映射反了的 bug）、`col_list` 出 `dl+dt/dd`（比例经 `dl` 的 `data-ratio` 携带）、`collapse` 出原生 `details/summary`（浏览器自带交互，零绑定）；无 HTML 原生形态的组件出**用途直名标记标签**：`<alert level title>`；`button`（新增零件：href/target/variant + 按钮文字）为原生 `<a>` + `part-button part-button-{variant}` class 变体，`_blank` 自动带 `rel="noopener"`；`clip_board` 沿旧标记契约 `span[data-clipboard-slot]`（正文 strip_tags 归一为纯文本，空则零输出）；
- **`sponsor_ship` 整体删除（站长拍板）**：contentHtml 是公开共享缓存载荷（HttpCache 分层 + ETag），旧主题在服务端按查看者分支的「赞助者可见」在前后端分离下不可用——要么每查看者 no-store（缓存与 CDN 全废）要么引入按查看者的门禁内容端点；其占位渲染形态（只出空卡、正文丢弃）过不了验证没有使用价值，词库内移除。查看者门禁组件随门禁内容 API 批次回归或不再做；`logged_in` 短代码同样不在迁移清单；
- **WP 默认短代码退役**：`wp_caption/caption/gallery/playlist/audio/video` 六个 `remove_shortcode`（init 11）；`embed` 特殊——`WP_Embed::run_shortcode` 每次 the_content 都会清空重注册它，init 阶段 remove 无效，改为摘除其 `the_content/widget_text_content/widget_block_content` 三处过滤器（裸 URL 自动嵌入随之失效）；核心的 `__return_false` 占位注册保留不动，存量 `[embed]` 标记静默渲染为空而非字面残留；
- **测试**：BuiltinPartsTest 八用例——注册序、build 模板、各渲染形状（ul/ol、dl data-ratio、details/summary、alert 级别白名单回退、button 变体/rel/空 href/clip_board 去标签归一）、wpcli 实测 `[clip_board]` 渲染 `<span data-clipboard-slot>`、`sponsor_ship` 已不存在；phpunit 208/516、phpstan、phpcs 全绿。版本对齐 0.70.0。
- **⚠️ i18n 事故与现状（2026-09-15）**：零件批次的 31 条翻译曾完成（840→871 全量翻译 0），但随后一次 PO 修剪操作失误（`open('w')` 在编辑参数求值失败前已截断文件）把 `languages/aiya-core-zh_CN.po` 清空，且紧接的 MO 重编译把 `.mo` 一并清空——0.57 以来各批次的翻译存量随之丢失。站长拍板本轮跳过 i18n 恢复，后台当前回退英文。**恢复基线**：`wp-content/tmp-aiya-core-zh_CN.po`（547 条，约 0.4x 时代快照）+ 源码内 `__()` 字符串（`i18n-build.py pot` 随时可重建 POT），恢复 = 以 tmp 为基 + 对照 POT 重译缺失段（含本批 6 零件文案）；i18n 恢复列为独立批次。
- **前台接线批待办**（本批之后）：`safeContent` 白名单扩充 `dl/dt/dd/details/summary/data-ratio`、`alert` 标签解析与组件挂载、`part-button*` 与 `data-clipboard-slot` 样式/交互。

### 编辑器表情选择器 + TinyMCE 拓展调研（0.70.0 同批补记，2026-09-15）—— ✅ 已完成（实现部分）

站长拍板把前台表情包组件在后台经典编辑器也做个实现，并调研旧 classic-editor-modify 的 TinyMCE 插件在当前 WP 的可用性：

- **`Admin/SmiliesPicker`**：经典编辑器工具栏「Smilies」按钮（`media_buttons` 30，位于模板零件之后）→ wpdialogs 网格面板（`assets/js/smilies-picker.js` + `smilies-picker.css`）——按包分组的图片预览格，点击把 **`::code::` token 文本**插入光标处（TinyMCE 走 `insertContent`、QuickTags 走 `QTags.insertContent`），存储内容保持纯文本 token，由后端 SmiliesRenderer 在 the_content 转图。数据直接取 `SmiliesRegistry::packs()` 服务端渲染 HTML（实测 3 包 506 码 / 246KB，仅 post.php/post-new.php 装载）；`wp-content/smilies/` 无包时不注册不出按钮；
- **调研与迁移（classic-editor-modify 插件件清单与可用性）**：旧插件 assets 内带 7 个文件——`advlist/table/toc/codesample/textpattern/image/media`（前 5 个在配置中启用，image/media 打包但注释停用）+ 自定义 `add-quicktags.button.js`（注释停用）；另有 4 项非 MCE 行为：按钮重排（行 1/2/3 插入下划线/删除线/字色/字号/字体/表格/代码样例/toc 等）、粘贴 base64 图片自动上传（content_save_pre）、作者下拉角色过滤、标签选择器全量显示。**当前 WP 7.1 内核捆绑 TinyMCE 4.9.11（2020-07-13）**，与旧插件同属 4.x PluginManager 线。**本批已迁移 `Admin/EditorPlugins`**：内核不带、可复用的四件 `advlist/table/toc/codesample`（文件随插件入 `assets/js/mce/`，`mce_external_plugins` 注册）；按钮按旧布局挂载——`toc` 行一 `wp_more` 之后、`table`/`codesample` 行二追加（挂载幂等）；`advlist` 无按钮、加载即增强内核 bullist/numlist。`textpattern` 不带（与内核 wptextpattern 功能重叠、同开双重转换），`image`/`media` 不带（内核自带）；旧插件其余非 MCE 行为（按钮美学重排/base64 粘贴上传/作者过滤/全量标签）未迁移，如需另批评估。测试 EditorPluginsTest 五用例（四件注册精确性、外方注册共存、toc 插入位置、无 wp_more 兜底、幂等）；wpcli 实测 external=row1=row2 全部就位。

### 文章级可见性门禁：登录可见 / 会员可见（0.71.0，2026-09-15）—— ✅ 已完成

站长拍板按调研结论实施：不用自定义 post status（与 publish 互斥会打断全链发布态逻辑、后台 UI 半残），改用 **post meta 门禁旗标 + 复刻既有密码门模式**，文章保持 publish、全部门禁在自有读取面生效：

- **`Domain/Content/PostVisibility`**（新服务）：标量 meta `aiya_core_visibility`（''/login/member，白名单读取）；`satisfied()` 判定——public 恒过、login 需任意登录用户（bearer/cookie 已接，无需新端点）、member 经注入的闭包委托 `MembershipService::isSponsor`（含编辑旁路）；`listExclusions()` 按查看者类生成列表 meta_query 排除子句——访客排除两门、登录非会员仅排除 member、会员零排除（NOT EXISTS + 空值 + NOT IN 三段 OR，手工置/遗留空值行保持公开）；
- **编辑面 `Admin/VisibilityMetabox`**：post/page/resource 编辑屏侧栏 bespoke 单选（CoverMetabox 同款自管模式——标量 meta 需直查，框架组数组不便 meta_query），保存白名单校验 + nonce + edit_post，公开即删键；
- **读取面**：`ContentQuery::list` 注入门禁排除子句；`PostPresenter`——badges 增 `login`/`member` 徽章（配置即出现，HttpCache 同值检测将门禁响应判 private, no-store 防共享缓存泄漏）、受限查看者的摘要置空（防正文首词经 excerpt 泄漏，对齐核心对密码文章的处理）、详情 `content` 置空 + 契约加法两字段 `PostDetail.visibility`（public/login/member）与 `gated`（当前查看者是否被拦）——v1 冻结的加法演进；`ContractsSnapshot` 的 PostDetail WIRE_SHAPES 手工形态同步；
- **实测三视角**：访客——门禁文章不出列表、详情 gated=true/content 空/摘要空/徽章带门禁值/Cache-Control private, no-store；订阅者——login 门禁全见、member 门禁不出列表且详情 gated；管理员（编辑旁路）——两门全见；前台 zod（badges 枚举 + postDetailSchema 两字段）与快照同步，vitest 167/167、tsc 干净；phpunit 219/540（+6 PostVisibilityTest：白名单读取/判定矩阵/门禁镜像/三视角排除子句）、phpstan、phpcs 全绿。测试垫片补 WP_Post 最小替身与 get_current_user_id。- **ExternalFiles 域增网盘链接 box（0.71.0 追加，站长拍板与 OpenList 零件同域）**：`OplistModule` 注册 `pan_links` post box——resource 编辑屏 repeater 自增列表（name/url/code 三子字段：名称、链接、提取码），存储走组协议键 `aiya_core_pan_links`；不加 required（半填行会静默阻断整 box 保存，框架 repeater 分支也不走 sanitize 钩子），改为 `save_post` 20 优先级的 `pruneEmptyPanLinks` 修剪器——仅丢弃全空行（编辑器「Add item」残留），半填行保留、链接 esc_url 归一留给渲染时；随 EXTERNAL_FILES_ENABLED 开关同生（与 oplist_client 一致，站长指定同域耦合）。测试 PanLinksPruneTest 四用例（只删全空行/全空删组键/无存储为 no-op/注册声明含三子字段且 oplist box 并存）+ wpcli 实测 box 注册与 repeater 渲染；API 消费（attachments 端点或详情透出网盘行）留待 B3 接线批次。
版本对齐 0.71.0。
- **未做/后续**：门禁文章仍进 feed/sitemap 与相关文章（标题级暴露，与密码文章现状一致，可接受）；门禁文章的计数不拦（公开无害）；`logged_in` 内容级短代码不迁移（文章级门禁已覆盖其主用例）；i18n 恢复批次一并处理本批文案。

### 文章级 SEO 字段退役（0.72.0，2026-09-16）—— ✅ 已完成

站长拍板：搜索引擎自 2009 年起全线忽略 meta keywords（Google/Bing/百度官方口径一致），文章级 seo_keywords 早已是「从未生效」的死数据——契约 Seo DTO 只有 title/description/noindex，keywords 从未出过程序；seo_desc 的价值被 WP 原生摘要字段覆盖：

- **`Domain/Content/SeoBoxModule` 整体删除**（post_seo box 及 seo_keywords/seo_desc 两字段，post/page/resource 三屏）；存量 meta `aiya_core_post_seo` 当死数据（uninstall 的 `aiya_core_%` LIKE 清理已覆盖，注释同步更新——注意 term meta 的 seo_keywords 是 0.57.0 术语级退役的遗留，清理保留不动）；
- **详情描述回退链简化**：`PostPresenter::detail` 摘除 meta 读取，`Seo.description` 恒取摘要（编辑手写摘要优先、否则自动摘要）——Seo DTO 形状不变（title/description/noindex），契约快照与前台 zod 零改动，前台 `posts/[id].astro` 的回退链自然兼容；
- **保留面**：站点级 seo_keywords/seo_description（前台设置页 → /site）本轮站长未拍板删除、暂留；归档页 meta 走 term description + noindex 策略仍是 B3 接线待办；
- 门禁：phpunit 223/552、phpstan、phpcs 全绿；wpcli 实测注册表 box 列表 = oplist_client/pan_links/typography（post_seo 已消失）。- **排版工具空组键行修复（0.72.0 追加）**：action-checkbox-only box（typography 形态）归一化恒为空数组，框架却照写 `a:0:{}` 序列化行——`MetaboxAdmin::savePostBoxes` 改为归一化结果为空时 `delete()` 组键（顺带清掉历史空行）；测试 MetaboxAdminSaveTest 三用例（空组键删行且 one-shot action 照常触发、可持久化 box 照常存值、无 nonce 跳过），垫片补 `current_user_can`/`wp_verify_nonce`；开发库 4 条存量空行已清。
版本对齐 0.72.0。

### 全量代码审查修复批（0.72.1，2026-09-16）—— ✅ 已完成

四面并行审计（REST/API、Admin、Domain 新功能、生命周期与存储）后修复 18 项，全部经人工核实：

- **高**：`PostDetail.visibility` 线上值——公开文章发空串而前端 zod 是 `public|login|member` 三值枚举（快照只查形状不查值域，vitest 抓不到），`PostPresenter::detail` 归一化 `'' → 'public'`；
- **中**：① `TokenAuthentication` 作用域匹配含查询串（`?x=/aiya/core/v1/` 可在 `/wp/v2` 等面重新激活 bearer），改只匹配 `wp_parse_url` 的 path；② 前台两处接线硬伤——`resourceAttachmentsSchema` 仍要求已删除的 `gated/canSeeLinks`（收缩为 `{items}`，zod 默认剥离旧键）、`createOrder` 用裸 schema 校验带信封响应（改 `orderCreatedResponseSchema`），加上回复 content 的错误注释修正（后端有 smilies 渲染）；③ 门禁文章从 prev/next、/related、公开收藏三旁路泄漏标题元数据——`neighbors()` 与 `RelatedPostsQuery` 并入 `listExclusions()`（applyClauses 只追加 join，meta_query 共存），`FavoriteService::published()` 裸 SQL 补 NOT EXISTS 排除（公开收藏面对所有查看者无差别排除）；④ `TokenStore::bumpGeneration` 非原子（并发撤销丢递增 + 镜像写入时读世代可跨越吊销点）——改原子 SQL 自增（含 `rows_affected === 0` 首次播种回退）+ meta 缓存删除，resolve 侧加双读守卫（世代跨吊销不落正镜像）；⑤ `PartModule` 补摘 `WP_Embed::autoembed` 三挂点（裸 URL 行的服务端 oEmbed 出网）；⑥ 零件属性双重转义（存储期转义 + 渲染期再转义 = 实体字面量显示）——渲染器 `wp_specialchars_decode` 后再转义；⑦ `SendMailPage::assets` 条件反转（编辑器资产在全后台加载、本页反而不加载）；
- **低**：CommentsController 评论 IP 改走 `ClientIp::forVisitor()`（原直读 REMOTE_ADDR，反代下泛洪控制会误伤全员）；HttpCache 契约 GET 200 补 `Vary: Origin`（SSR 预取与浏览器直连双变体）；`SponsorshipController::createOrder` 服务端校验档位 `enabled`（原只靠前端踢出，410）；ConvertCodes 生成量钳 200/cycles 钳 60 + tier_key 白名单；PostTypeSwitch redirect 空串兜底 referer（对齐姊妹刀）；pan_links 修剪器 is_scalar 守卫（数组单元格丢弃整行）；onCleanupHtml 补 busy 闸；epay 回调首段记账死代码删除（记账语义保留：未解析 tier 也记账、记账失败答 fail）；changePassword 补 10 次/10 分钟限流；register 的 wp_update_user 失败改答 500；
- **卫生**：ContentQuery 密码文章 docblock 与 `has_password=false` 对齐；Membership DTO 派生源注释修正；TokenStore docblock 注明 trim/过期 ≤TTL 宽限为接受项；PartModule 空词库时不注册按钮/弹窗/资产（对齐 SmiliesPicker）；
- **legacy-cleanup v1.2.1**：`deleteTaxonomyTerms` 改为先删 relationship/tt 行、仅删失去末行 tt 的孤儿 term（pre-4.4 共享 term_id 防误删）；tweet 转换 UPDATE 失败抛异常防死循环；
- **明确干净区**（四面均确认）：HttpCache 三道缓存闸、限流桶矩阵、路由 args、信封与错误 status、赞助域 GET_LOCK/CAS/验签/幂等、Discussion/Notification/Credit、AvatarModule、PicBed/SendMail/批量动作授权链、uninstall 表名 12/12 与 cron 6/6、`aiya_core_` 前缀纪律无孤儿；
- **遗留拍板项**：postpass 解锁 cookie 在同源代理下断链（B3 前需一次设计拍板：解锁令牌直返正文 or 代理回放 cookie）；WebhookLogger 无轮转；订单熵可加长。i18n 恢复仍为独立批次（git HEAD 有 0.56 时代 788 条 po 可作恢复基座，优于 tmp 547 条快照）。
- 门禁：phpunit 226/556（+3 MetaboxAdminSaveTest）、phpstan、phpcs、vitest 167/167、tsc 全绿。版本对齐 0.72.1。

### 解锁直返正文 + Webhook 日志改常量门控（0.73.0，2026-09-16）—— ✅ 已完成

站长拍板两项（全量审查遗留的拍板项收口）：

- **postpass 解锁绕 cookie、直返正文**：`POST /content/{id}/unlock` 密码校验通过后不再 `setcookie(wp-postpass)`（headless 拓扑下 cookie 落在 Astro→WP 代理跳、浏览器永远拿不到，功能接线即坏），改为直接返回**解锁后的完整 detail**（`PostPresenter::detailUnlocked` 新方法，visibility 门禁照常独立评估）；语义变化 = 解锁变为一次性（下次冷读仍 locked，需再次提交密码），前端可自行在会话内保留响应正文。前台契约新增 `postUnlockResponseSchema = itemEnvelope(postDetailSchema)` + client.unlockPost；`aiya_wrong_password` 403、限流 10/600s 不变；
- **WebhookLogger 改调试常量门控**：删除赞助设置页 `epay_savelog`/`afdian_savelog` 两个开关及 `SponsorshipSettings::read()` 映射，`WebhookLogger::write()` 自带闸门——仅在 wp-config 定义 `AIYA_CORE_WEBHOOK_DEBUG === true` 时落盘 `aiya-core-logs/`（支付数据不因设置页开关被遗忘而无限累积）；GatewayController 八处 if 包装随之拆除，回调行为（验签 400/可用性 200、记账先于 tier 解析、激活幂等）不变；
- 实测：密码文章详情 locked:true → 错误密码 403 → 正确密码 unlock 直返 locked:false + 全文正文；`WebhookLogger::active()` 无常量时 false、write 零落盘；phpunit 226/556、phpstan、phpcs、vitest 167/167、tsc 全绿。版本对齐 0.73.0。

### 中文翻译恢复批（0.73.1，2026-09-16）—— ✅ 已完成

0.72.0 批次事故中丢失的 zh_CN 翻译存量恢复完毕：PO 以 git HEAD 的 0.56 时代版本（786 条已译）为基座，对照最新 POT（863 条）补译 88 条缺失字符串——覆盖 0.57-0.67 各批次遗留（缩略图批量动作、支付查账、图床/发信权限句）、0.70.0 零件词库全量、0.71.0 可见性门禁、0.73.0 Afdian 错误文案与会员设置组、以及审查修复批的新错误串；术语沿既定表（爱发电/图床/模板零件/您无权…/请求过于频繁…），错误文案陈述句。POT 同步重建；MO 编译后实测「快捷列表|可见性门禁|刷新缩略图|支付查账|网盘链接列表|提取码|表情包」全部生效；未翻译 0 条。版本对齐 0.73.1。一次性辅助脚本（guard2.php/lang-diff.py）已删除。

### 可见性门禁 metabox 翻译补漏（0.73.2，2026-09-16）—— ✅ 已完成

可见性单选的标签与描述此前为硬编码英文（未走 `__()`，POT 抓取不到）：补 `__()` 包装并入 POT，补译「所有人/登录用户/仅限会员」三条标签与描述；NotificationPage 列表残留的硬编码 `User #%d` 一并包装补译（用户 #%d）。实测 metabox 渲染中文标签、公开档默认选中；POT 880 条全量翻译 0 缺失；phpunit 226/556、phpstan、phpcs 全绿。版本对齐 0.73.2。

### 封面/卡片管线分写 + image-processor 卫生修复（随 0.74.0，2026-09-17）—— ✅ 已完成

站长报告「封面生成路线不能正确叠加文字标题（和黑色半透明文字遮罩）」。追踪结论：CoverGenerator 的标题绘制本身正常（包级与运行时实测均出字），真凶是**两条管线共写 `thumbnail/cover/` 目录与 `_thumb` 键**——save_post/cron 的自动卡片（按设计无标题）每次保存都顶掉 metabox 手动生成的带标题封面，post 152 的无字封面即自动卡片实物。修复：

- **产物分目录**：`CoverService`（手动、带标题）写入 `thumbnail/cover/manual/`，`CardThumbnailService`（自动、无标题）写入 `thumbnail/cover/auto/`——路径自识别生产者；`MediaPaths` 增 `coverManualDir()/coverAutoDir()`；
- **手动优先**：`CardThumbnailService::refreshFor` 对 `_thumb` 指向 `cover/manual/` 的文章直接跳过（save_post 与批量「刷新缩略图」均不覆盖手动封面，批量动作计为 skipped）；重做封面 = 在编辑器再点一次「Generate cover」；`pendingIds` 天然跳过（手动封面有 `_thumb` 行）；
- **孤儿清理**：`CoverService` 生成新封面时删除被替换的旧封面文件（仅限 `coverDir()` 下符合 `\d{14}_\d{4}` 托管命名模式的文件，手改值不误删——旧自动卡片的孤儿同样被清）；
- **image-processor 卫生**：`CoverGenerator::drawCenterTitle` 的衬条取色（20×20 采样）从行循环内提升到循环外——采样对象是未污染画布，且 Imagick 下省一半 getColorAt 调用；删除从未使用的 `$maxWidth` 死代码。包内其余（Colors/ImagineAware/SaveOptions/FirstImageMatcher/WatermarkSpec/UploadApplier/ImagineFactory 探针）审查通过；
- **metabox 传参核实无问题**：model/title/colors 经 sanitize 后全量进 `CoverSpec::fromArray`，映射完整；字体解析（配置缺失回退包内 AlibabaPuHuiTi）与 Imagick 探针正常。**调度简化（站长拍板）**：`refreshFor` 增 `force` 参数——保存钩子路径改为「`_thumb` 已存在即跳过」（首次发布生成、后续保存不再重derive，特色图变更后靠批量刷新强制更新）；批量「刷新缩略图」为唯一强制口（`force: true` 重derive 自动卡片）；手动封面在两种路径下都绝对跳过。**孤儿收口**：`delete_post` 时清除 `thumbnail/cover/` 下的托管卡片文件（`\d{14}_\d{4}` 命名匹配，手改值不误删）——postmeta 随文章级联删除后文件不再遗留。

### 详情路由 slug 化（0.75.0，2026-09-18）—— ✅ 已完成

详情读端从 id 键改 slug 键：`ContentQuery::bySlug()`（WP_Query name 匹配 + publish/private 查看者判定），路由 `/posts|pages|resources/{slug}`（URL 模板 `%s`），邻接文章仅 post 详情携带（page/resource 空对，契约字段保留）。审查补：slug 参数 maxLength 200。

### 评论富文本与互动登录墙（0.76.0，2026-09-18）—— ✅ 已完成

- 评论体改 kses 白名单受限 HTML（写读双侧过滤，宽限 tiptap 编辑器与上传图 `<img>`），可见文本 5000 字上限 + 原始 20000 上限；游客评论跟 `comment_registration` 开关（原生 name/email 字段 + require_name_email），读端增 `order` 参数；
- 点赞/评分改登录-only（访客哈希去重易刷，视图保持公开）；
- 上传端 docblock 更新：评论可嵌图片 HTML。

### 壳层细节透出与 hero 重排（0.77.0，2026-09-18）—— ✅ 已完成

- `LightboxModule`：the_content 后期 pass 给内容图盖 `aiya-lightbox` class（跳过 smilies 图）；
- `PostDetail` 增 `commentsOpen`（comments_open 镜像）与 `hasManualExcerpt`（区分手写摘要与自动摘要）；
- 详情 hero 改 1000×240 banner 裁剪（原 1000×640），post 类型走「特色图 → 站级默认文章封面（新 Frontend 设置 `default_post_cover`）→ 站级兜底图」链，page/resource 不吃站级默认；
- `SiteComments` 加法透出 `commentRegistration`（游客评论开关）。

### 默认值归站点设置 + 签到策略透出（0.78.0，2026-09-19）—— ✅ 已完成

- 内容列表 `perPage` 默认改读 `posts_per_page`（-1「显示全部」映射 API 上限 100），评论列表默认读 `comments_per_page`/`default_comments_page`——显式传值恒优先；前端四个查询 schema 的写死 `.default()` 改 optional；
- `MembershipState` 加法增 `checkin`（新 `CheckinPolicy` DTO：enabled/credits/validityDays，读 membership 页设置），契约快照重生成；related 代理 `number` 真透传（原本地 slice 遮蔽）。

### 发布审查批（0.79.0，2026-09-19）—— ✅ 已完成

1.0 前全量审查（API/Domain/Admin/发布机械四面并行）后修复：

- **CommentsController 层级重构（高）**：读侧 WP_Comment_Query 与 post/comment 查找下沉 `Domain/Content/CommentQuery`，投影迁 `Api/Presenter/CommentPresenter`（kses 白名单随迁为写读共用契约）；wp_new_comment 留控制器（即被编排的审核管线本身，docblock 记明）；
- **通知列表分页（中）**：`NotificationService::countVisible()` + visible 增 offset，`/notifications` 增 page/perPage 与标准 `meta.pagination`（原 50 条硬顶无分页无提示）；
- **支付审计页 source 过滤器（中）**：原来下拉只改表单不进查询——`OrderService::list` 增白名单 source 条件接通；
- **兑换码档位守卫（中）**：mint 时只查 key 存在不查 `enabled` 布尔，停用档位可发码但购买列表拒买——改为 `enabled` 真值校验；
- **spend() 与 grant() 抑制不对称（中）**：一次性 dedupe 令牌的重复 INSERT 同样会打印 wpdb 调试 HTML——补 suppress_errors 包裹（与 0.51.0 grant 同款）；
- **DiscussionPresenter 授权去重（中）**：canModerate 私有镜像删除，注入 DiscussionService 直调权威判定（契约 flag 与执行不再可能漂移）；
- **uninstall 补 transients 删除**：前缀 DELETE 漏 `_transient_aiya_core_*` 包装行（限流/去重 TTL 最长 30 天）——显式清；
- **列表性能（低）**：PostSummary reading time 改读原始 post_content（ReadingTime 自剥标签），百行列表不再逐行全量跑 the_content 链；
- **参数上限（低）**：/users/me/profile description/url 补 maxLength（2000/300）；
- **契约/注释过时族清理（低×10）**：Notification DTO 的「v1 仅 announcement」、Discussion 四值状态、Membership 协议 meta 推导、CreditGrant「签到+兑换共用」、CreditController 旧唯一键句、Plugin EXTERNAL_FILES「parked」、SponsorshipModule「爱发电未接线」、NotificationService v1 句、评分折叠并发丢票注记、粉丝扇出 100 上限注记、activate 0.0.0 重置语义注记；
- **oplist desc 重标注（中）**：资源盒 desc 与页级 `oplist_file_desc` 两处「Shown to readers」承诺改「Reserved for B3 wiring」（值已持久化，附件契约形状未携带；`pan_links` 盒同类 B3 预留记档）；
- **架构审查批（同日早前提交）**：`GATEWAY_NAMESPACE` 下沉 PaymentGateway 接口 + `aiya_core_firstparty_rest_namespaces` 缝（Domain/Infra 不再 import HTTP 层，ARCHITECTURE.md 登记）、死引用清扫、外接域四缝清点；
- **内容目录统一前缀**：`aiya_logs`/`aiya_thumbnail`（avatars 随树）/`aiya_smilies`/`aiya_upload_pics`，磁盘搬移 + `_thumb`/`basic_user_avatar`/社区内容 URL 一次性改写，死数据顶层 `avatars/` 删除；
- **配套壳主题入库**：`themes/aiya-headless/` 随仓库版本化（README 标记占位主题壳 + ARCHITECTURE.md companion 段），运行位 `wp-content/themes/aiya-headless/` 逐字节同步；壳主题最终形态 = 站点图标品牌行（贴左）+ 定制器 intro 文本域 + robots 双保险 + admin bar 关闭，零插件依赖；
- **运行时升级 PHP 8.4.25**（wordpress:php8.4-apache / cli-php8.4 变体，daocloud 镜像转存）：自研代码零隐式可空违规，phpunit/phpstan/phpcs 全绿复跑，全路径压测零 Deprecated；
- **外观开关收窄**：`disable_appearance` 定格「站点编辑器 + 菜单」（定制器/主题屏供壳主题使用），菜单三层全关（无 support + 子菜单移除 + 403 守卫）；
- **发布机械**：PO 头恢复（0.73.1 恢复批丢头，补 Project-Id-Version/charset/X-Domain 并去重复空条目）、POT 885 条全译 0 缺失、版本对齐 0.79.0、README WP 底线句、Domain Path 头补齐；phpunit 236/569、phpstan、phpcs 全绿。

- **契约执法补口（0.79.0 追加）**：三处快照外裸形状升格为正式 DTO——`TiersPayload`/`PlanChannels`/`Tier`（购买面）、`UploadResult`/`UploadedImage`（社区上传）、`Comment`/`CommentAuthor`（评论投影），快照重生成 + 前端 zod manifest 同步（vitest 195/195，7 DTO 逐字段吻合零漂移）；`client.uploadImage` 的宽松 url 拾取改 `uploadResultSchema` 全形验证；
- **已记录待决（不阻塞 1.0）**：`/discussions/boards` 与 `items` 形两种信封偏差待统一；`opencc-convert` 包保持零消费状态（简繁重建待定，站长拍板 2026-09-19）。

### 干净发布批（0.80.0，2026-09-19）—— ✅ 已完成

站长拍板：core 从未上线，1.0 tag 前不允许任何表名迁移、表结构升级与数据/字段转换——安装即最终形态。十条件迁移链折叠为五条纯 CREATE（版本统一 0.80.0），升级专用回调整体删除：

- **Discussion**：`installTables` 直建最终形状（threads 无 `type` 列、`board_id` 就位），三块默认种子（讨论/问答/反馈）从 `migrateToBoards` 迁入（空表守卫，幂等）；`migrateToBoards` 删除；
- **Credit**：`installTable` 本就是 0.51.0 最终形状，`upgradeToDedupeKey` 删除（其 ALTER-回填两段间的中断半态隐患随之消失）；
- **Notification**：`installTable` 已含 actor/object 列（0.46.0 折叠完成），`migrateToActions` 删除；
- **Sponsorship**：`installTables` 一条建三表（payment_orders + memberships + redeem_codes，原 DDL 逐字重复一份的问题消除）；`upgradeToTierModel`/`upgradeCodesToMembership` 删除（legacy usermeta 三键 DELETE 与 `aya_convert_codes` DROP 随之退场）；`payment_orders` 去掉只写 0 从不读的 `start_time`/`duration_days` 两列（写入点同步删除），`source`/`status` 补 NOT NULL 与全仓 DDL 对齐；
- **重试契约补齐**：五个 CREATE 回调全部补 SHOW TABLES 验证并抛 RuntimeException（沿 IdentityModule 既有纪律）——dbDelta 静默失败时运行器扣住版本号，下一请求重试，而不是带着「成功」标记永久缺表；
- **uninstall 去 legacy**：`aya_convert_codes` 兜底 DROP 与三个退休 protocol key 的 DELETE 移除（净装库不存在这些对象），仅保留 `aiya_core_sponsor_state_noticed` 活跃标记清理；
- **第二遍审查修复**：① `ValueNormalizer` multicheck 缺键清空语义落地（原代码 presence 标志硬编码 true，清空保存被默认值静默重启用——补单测）；② `ThumbnailGenerator` 写失败删除截断残片（复用路径曾会把半截文件永久当缓存命中）；③ typesetting 包 composer.json 反斜杠转义修复（原文件非合法 JSON，靠 composer 宽容解析存活）；④ MetaboxAdmin 三处保存静默吞错改为 transient + admin_notices 一次性提示（与设置页 redirect-with-error 对齐）；
- **dev 库重建实测**：13 表备份（backup-aiya-tables-20260919.sql，含两份无代码引用的 `aya_sponsor_orders` 孤儿 223 行）→ 全删 → 版本标记重置 → 净链一次跑通 11 表 + 种子 + 无迁移错误；社区发帖/板卡、全 REST 矩阵复测正常。phpunit 237/571、phpstan、phpcs 全绿。

**1.0 tag 就绪**：安装路径 = 五条 0.80.0 纯 CREATE，零升级步骤、零数据转换、零 legacy 兼容面（AvatarModule 前缀守卫为防御性存在，非读取路径）。
