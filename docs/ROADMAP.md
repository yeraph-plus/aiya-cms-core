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
│  │  └─ Content/                   # ✅ 0.6.0：SlugModule——自动别名（pinyin / id_av / id_bv，
│  │                                #   术语 pinyin），原语来自 slug-toolkit 包
│  │                                # ✅ 0.7.0：ContentTypeModule + PostType/TaxonomyDefinition +
│  │                                #   ContentTypeRegistry（代码式 CPT/分类法，show_in_rest 默认开）
│  │                                #   + SeoBoxModule（post_seo 协议键字段组）；0.12.0 内置 page_category 独立分类法挂 page，0.15.0 内置 resource CPT + 标准分类 + 5 标签分类法；0.16.0 Engagement 计数服务 + content like/view REST 端点，0.17.0 加 rating 评分端点，0.18.0 特性矩阵（资源评分/文章点赞）
│  │  ├─ Media/                      # ✅ 0.9.0：MediaPaths（URL↔路径/目录规划）+ ThumbnailService
│  │                                #   （缓存键含质量，只读不写 meta）+ CoverService（封面生成 +
│  │                                #   `_aya_thumb` 协议键唯一写入方）
│  │                                # M4 再落 ContentQuery（旧 WP_Query 原型）、MenuService（旧
│  │                                #   WP_Menu 蓝本）、BreadcrumbService、PaginationService；
│  │                                #   Issue/ Tweet/ 域在此扩展
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
│  │                                #   外观与定制器/区块小工具/字体库与全局样式/区块样板/Pingback
│  │                                #   与Trackback/前台头部冗余/Emoji/oEmbed/XML-RPC）；评论默认
│  │                                #   保留（WP 为评论存储+审核面，Astro 经 REST 读写），kill switch
│  │                                #   仅作整体关闭逃生口；开关存储在 aiya_core_headless，总开关
│  │                                #   off = 恢复原生行为；已对照 WP 7.1 源码逐钩子验证，普通插件
│  │                                #   即可实现全部裁剪，无需 MU
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

1. **第一批**：`opencc-convert`（tracer 包已建，Converter + locale 策略映射，待接入适配器）、`multi-domain`；`internal-pic-bed` 已改为 core 内页面（0.9.0 `Admin/PicBedPage`），不再做包
2. **第二批**：`image-manager` ✅ 0.9.0 → `aiya/image-processor` 包 + `Modules/MediaModule` 适配器；`classic-editor-modify`（替换 `aya_plugin_opt` 设置层）
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

**契约权威与批次计划（2026-09-06 定）**：DTO 清单以前端契约 `aiya-astro-bulid/src/lib/aiya/contracts.ts`（v1，camelCase + `{data, meta}` 信封）为对照基准，落地语义见 [AIYA-astro DATA-MAP.md](../../../../aiya-astro-bulid/docs/DATA-MAP.md)；批次顺序 A0 契约对齐 ✅（0.13.0：后端信封/camelCase + 前端认证接线完成）→ B1 站点骨架+文章读取层 ✅（0.18.0：ContentQuery/MenuService/PostPresenter + /site /menus/primary /terms /posts /posts/{id}，DTO 与前端契约对齐）→ B5 公开作者页 ✅（0.19.0：GET /profiles/{slug}，favorites/membership 协议键兼容读，无 email/登录名泄漏）。**站长拍板暂缓**：Mod/Game 新域、Discussion/旧 Tweet 域、`/home` 聚合（依赖前两者）。用户域批次（0.12.0）已完成。

原 M4 清单（保留作 DTO 语义蓝本），DTO 清单直接翻译旧 `inc/core` 的 `*_In_While` 属性表（见工作区 AGENTS.md 的结构说明），并剥离其展示逻辑（K 格式化、timeago、本地化兜底文案、分页 CSS class、菜单 HTML 构造器）：

- `Api/Contract/`：`PostSummary`（id/url/title/type/dates+ISO/excerpt/preview/thumbnail/views/likes/评论数/分类标签/作者摘要）、`PostDetail`（增 content HTML、prev/next、gallery）、`TermDto`（补齐旧版 parent/children 未 DTO 化的不对称）、`AuthorDto`、`ThumbnailDto`、`MenuTree`/`MenuItem`（label/url/target/object/type/children/active）、`Pagination`（standard + simple 两形态）、`Breadcrumb`（`{label,url}[]`）+ 契约版本常量；
- `Presenter/`：WP 对象 → DTO 映射；`the_content` 过滤器在此执行（content HTML 是契约数据）；修复旧 `get_post_views/likes` 缺 property_exists、`WP_Term::get_term()` 布尔优先级两类旧 bug（新实现不引入同类路径）；
- `Domain/Content/`：`ContentQuery`（封装旧 WP_Query 的预设查询集合）、`MenuService`（结构 `wp_cache` 缓存 + 每请求激活态注入 + `wp_update_nav_menu` 清缓存，沿用旧蓝本）、`BreadcrumbService`、`PaginationService`；
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

### M5 版本化 REST ＋ Astro SSR

- `Api/Rest/`：命名空间 `aiya/core/v1`；控制器只调用 M4 的读服务与 Presenter；**认证/用户域骨架已随 M4 用户域批次落地（0.12.0：RestController 模块、Bearer 认证、auth/users 路由、限流）**，本里程碑追加内容资源：内容列表/详情、terms、导航菜单、面包屑/分页（嵌入响应元数据）、站点设置白名单、媒体引用；**评论走 `/wp/v2/comments` 原生路由（保留开放）＋加固层**（限流、垃圾规则、`rest_pre_insert_comment` 钩子——`preprocess_comment` 在 REST 写入路径不触发），Astro 侧评论系统建立其上；
- 公开读 + 应用密码写；CORS 允许 Astro 来源白名单；ETag / Cache-Control；
- 产出面向前端的类型契约（OpenAPI 或从 Contract 生成 TS 类型脚本）；
- Astro 侧在 `aiya-astro-bulid/` 初始化：SSR 模式（node adapter，保 SEO），`src/lib/aiya/`（类型化 API client，镜像 Contract、缓存）、`src/pages|components|layouts`；
- 验收：Astro SSR 拉通首屏真实数据，直接命中 WP 域名时由 `aiya-headless` 空壳主题兜底，旧主题可整体退役。

## 五、执行纪律

- 每个里程碑完成时更新本文状态（勾掉条目即可），不在两处维护真相；
- 不为「将来可能用到」预建目录与抽象；切片原则见 MIGRATION.md；
- 运行时验证一律走 Docker wp-cli（`docker compose run --rm wpcli ...`），PHP 语法检查可用 `php -l` 的容器替代方案。
