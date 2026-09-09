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
│  │  ├─ Sponsorship/                # ✅ 0.24.0 核心切片：ExpirationFold（叠加折叠纯函数）+
│  │                                #   MembershipService（协议键读取/触发计数）+ OrderService
│  │                                #   （wp_aya_sponsor_orders 唯一事实源 + sponsor_expiration
│  │                                #   唯一写入方）+ RedeemCodeService（原子核销/回滚）+
│  │                                #   SponsorshipModule（0.24.0 兼容建表 + 域设置页）；
│  │                                #   爱发电/易支付网关切片待排
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
│  │                                #   不受总开关约束，kill switch 随之删除）；其余开关存储在
│  │                                #   aiya_core_headless，总开关 off = 恢复原生行为；已对照
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
- ✅ **评论路由退役**：Headless kill switch（`disable_comments`）整套移除——其 stripComments 的 comments_open 强关 / post type support 移除本会打断 aiya 评论端点的 `wp_new_comment` 管线（CommentsController 自检 comments_open），属负资产；`/wp/v2/comments` 改为 `filterRestEndpoints()` **无条件剥离**（不分匿名/登录态、不受 headless_mode 总开关约束）；评论存储 + 后台治理屏（edit-comments.php）保留；与 `lockPublicSurface`（0.22.0，管非契约命名空间对访客关闭）互补；
- 验证：单测 119/283 全绿；运行时实测（/site 新字段全通路含附件解析与中文页脚、`/wp/v2/comments` 匿名与 author bearer 双态 404 而控制组 `/wp/v2/users/me` 200、aiya 评论路由 GET/POST 200 + `comments_open` 未被过滤）；phpstan 抓出并修复 IdentityModule 表自检 `RuntimeException` 缺全局前导反斜杠（命名空间下解析为不存在类，自检触发即 fatal）。

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

- `Api/Rest/`：命名空间 `aiya/core/v1`；控制器只调用 M4 的读服务与 Presenter；**认证/用户域骨架已随 M4 用户域批次落地（0.12.0：RestController 模块、Bearer 认证、auth/users 路由、限流）**，本里程碑追加内容资源：内容列表/详情、terms、导航菜单、面包屑/分页（嵌入响应元数据）、站点设置白名单、媒体引用；**评论走 `/wp/v2/comments` 原生路由（保留开放）＋加固层**（限流、垃圾规则、`rest_pre_insert_comment` 钩子——`preprocess_comment` 在 REST 写入路径不触发），Astro 侧评论系统建立其上；
- 公开读 + 应用密码写；CORS：WP 核心 `rest_send_cors_headers` 现状为回显任意请求 Origin 且 `Allow-Credentials: true`（`wp-includes/rest-api.php`），浏览器直连端点（互动计数、将来评论）因此已跨域可用、无需自写——M5 将其**收紧为 Astro 来源白名单**，与评论加固层同批落地；ETag / Cache-Control；
- **模板零件**（2026-09-08 拍板计划迁移）：旧经典编辑器短代码输入器重设计——后台保留录入 UI（录入规范化零件数据），API 对短代码类内容输出规范化零件结构（不渲染 HTML），Astro 侧建立逐零件解析渲染；零件契约形状随首个真实零件出现时定；
- 产出面向前端的类型契约（OpenAPI 或从 Contract 生成 TS 类型脚本）；
- Astro 侧在 `aiya-astro-bulid/` 初始化：SSR 模式（node adapter，保 SEO），`src/lib/aiya/`（类型化 API client，镜像 Contract、缓存）、`src/pages|components|layouts`；
- 验收：Astro SSR 拉通首屏真实数据，直接命中 WP 域名时由 `aiya-headless` 空壳主题兜底，旧主题可整体退役。

## 五、执行纪律

- 每个里程碑完成时更新本文状态（勾掉条目即可），不在两处维护真相；
- 不为「将来可能用到」预建目录与抽象；切片原则见 MIGRATION.md；
- 运行时验证一律走 Docker wp-cli（`docker compose run --rm wpcli ...`），PHP 语法检查可用 `php -l` 的容器替代方案。
