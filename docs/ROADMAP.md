# AIYA Core 路线图与完成度

本文是当前迭代的实施规划：对照旧 `framework-required` 评估完成度，定义目标目录树与里程碑。模块归属的最终裁决仍以 [MIGRATION.md](MIGRATION.md) 为准，注册方式见 [ARCHITECTURE.md](ARCHITECTURE.md)。

基线：v0.2.0，2026-09-04 评估与结构定稿。运行环境 WP 7.1 / PHP 容器版，插件已激活，示例页 16 字段保存链路实测可用；完整生命周期（activate / deactivate / uninstall）已随 0.2.0 落地。

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
│  ├─ Runtime/                      # M3 剩余：SchemaVersion 迁移 runner
│  ├─ Settings/
│  │  ├─ Registry.php               # ✅
│  │  ├─ ValueNormalizer.php        # ✅
│  │  ├─ Schema/                    # ✅ Page / Field；M1 增加 note 类型与 options_source
│  │  ├─ Storage/                   # ✅ ValueStore / OptionStore；M1 + LegacyOptionReader（只读 aya_opt_*）
│  │  └─ Options/                   # M1：OptionsResolver（terms/posts/users/sidebars 惰性查询）
│  ├─ Api/
│  │  ├─ Contract/                  # M4：DTO 契约（纯值对象，零 WP 依赖）
│  │  │   PostSummary / PostDetail / TermDto / AuthorDto / ThumbnailDto /
│  │  │   MenuTree / MenuItem / Pagination / Breadcrumb + 契约版本常量
│  │  ├─ Presenter/                 # M4：唯一允许触碰 WP_Post / WP_Term 的映射层（WP 对象 → DTO）
│  │  └─ Rest/                      # M5：aiya/core/v1 控制器（只调用读服务与 Presenter，不查询数据）
│  ├─ Domain/
│  │  └─ Content/                   # M4 读服务：ContentQuery（旧 WP_Query 原型）、MenuService（旧
│  │                                #   WP_Menu 蓝本：结构缓存+每请求激活态）、BreadcrumbService、
│  │                                #   PaginationService；Issue/ Tweet/ 内容域切片在此层扩展
│  ├─ Modules/                      # 基础设施包适配器：实例化 packages/ 服务 + 注册「拓展功能」设置页
│  ├─ Admin/
│  │  ├─ SettingsAdmin.php          # ✅
│  │  ├─ FieldRenderer.php          # ✅；M1 支持 note/分组渲染
│  │  └─ MetaboxAdmin.php           # M2：post/term/user 编辑屏注册、渲染、保存
│  ├─ Metadata/
│  │  ├─ Registry.php               # M2：addPostBox / addTermBox / addUserFields
│  │  └─ Storage/                   # ✅ PostMetaStore / TermMetaStore / UserMetaStore
│  ├─ Infrastructure/
│  │  └─ Headless/                  # ✅ 0.3.0：HeadlessModule——无头化功能裁剪（区块编辑器/站点编辑器/
│  │                                #   外观与定制器/区块小工具/字体库与全局样式/区块样板/评论与
│  │                                #   Pingback/前台头部冗余/Emoji/oEmbed/XML-RPC），开关存储在
│  │                                #   aiya_core_headless，总开关 off = 恢复原生行为；已对照 WP 7.1
│  │                                #   源码逐钩子验证，普通插件即可实现全部裁剪，无需 MU
│  │                                # 其余（Media/ 等）有真实需求才建
│  └─ Http/                         # （M5 起并入 Api/Rest，不再单独设 Http/）
├─ packages/                        # ✅ 基础设施包目录（约定与批次见下节）；opencc-convert tracer 已建
├─ assets/                          # ✅ admin.css / admin.js
├─ languages/                       # ✅ aiya-core.pot 已生成；.po/.mo 待译
├─ tests/
│  ├─ Unit/                         # M1 起：ValueNormalizer / Field / OptionStore
│  └─ Integration/                  # M2+：metabox 保存链路（wp-env 或 wp-cli 驱动）
├─ composer.json                    # ✅ dev 工具链 + path repositories（packages/*）
└─ docs/                            # ✅ ARCHITECTURE / MIGRATION / ROADMAP
```

依赖方向（违反即架构错误）：

- Contract 零依赖；Presenter 是唯一 WP 数据触点；Rest 只调用 Domain 读服务与 Presenter，不查询数据；
- Schema/Normalization 不依赖 Admin 与 HTTP；Admin 依赖 Schema；存储适配器可依赖 WP 函数；
- packages/ 包不得反向依赖 core（不 require `aiya/aiya-core`、不调用 WP 函数、不挂 WP 钩子），由 `Modules/` 适配器单向接入。

## 三、基础设施包约定（packages/）

替代旧主题 `plugins/` require 加载结构。每个子目录一个独立 composer 包：`aiya/<slug>`、`type: library`、PSR-4 `Aiya\Infra\<CamelName>\`，自带 composer.json（php>=8.2 + 自身三方依赖，随根仓库腾讯镜像解析）。core 侧 `Modules/<Name>Module.php` 适配器实例化包服务、把包配置注册进统一「拓展功能」设置页（`aiya_core_addons`，沿用旧 extra-plugin 单页分区开关的 UX），并挂入 Module 系统。

迁移批次（按旧 plugins/ 耦合度探查结论）：

1. **第一批**：`opencc-convert`（tracer 包已建，Converter + locale 策略映射，待接入适配器）、`multi-domain`、`internal-pic-bed`
2. **第二批**：`classic-editor-modify`、`image-manager`（替换 `aya_plugin_opt` 设置层；imagine/imagine 随包）
3. **basic-optimize** 组件不改造成包，直接变成 core 的 Domain/Infrastructure 模块（安全/SMTP/SEO/头像各归其位）
4. **最后**：`sponsor-order-compat`、`patch-flow-hub-post` 重写；`gdluxx-dl` 空目录弃

## 四、里程碑

### M1 设置框架收尾（当前迭代）

- 新增 `note` 字段（info/success/warning/error 变体）与字段分组标题，替代旧 title/content 伪字段；
- `Options/OptionsResolver`：`options_source => ['source' => 'terms|posts|users|sidebars', ...]`，Schema 校验来源定义，Admin 渲染前惰性求值（不查询直到渲染）；
- 读取门面 `aiya_core_opt(string $page, string $id, mixed $default = null)` 与 `aiya_core_opt_bool(...)`；`Storage/LegacyOptionReader` 只读兼容 `aya_opt_{slug}` 旧键，旧→新无写回、无同步；
  - ✅ `aiya_core_opt()` 已随 0.3.0 落地（aiya-core.php 顶层函数，回退字段默认值；`aiya_core_opt_bool` 暂无需求，布尔用 `(bool)` 强转即可）；
  - ✅ 设置框架的首个真实消费方已就位：`Infrastructure/Headless/HeadlessModule`（0.3.0），替代旧 basic-optimize 的禁用开关语义，11 组开关全部走设置框架。
- `languages/` + .pot；`tests/Unit` 覆盖 ValueNormalizer 与 Field；
  - ✅ 工具链已就绪（2026-09-04）：composer dev 依赖（phpstan 2 @ level 8、wpcs 3 + PHPCompatibilityWP、parallel-lint、wp-cli i18n-command）+ `phpstan.neon.dist` / `phpcs.xml.dist` / `phpstan-bootstrap.php`，`composer php:stan|php:cs|php:cbf|php:lint|i18n:pot` 全部通过；宿主机无 PHP 时用 `docker run --rm -v <插件目录>:/app -w /app composer:2 <script>` 执行。`.pot` 已生成于 `languages/aiya-core.pot`。剩余：phpunit 与单测落地。
- 验收：以旧 `opt-basic.php` 的真实字段集在新框架重建「站点」页，保存/校验/重置/动态选项全部工作；单测与 phpstan 绿。

### M2 元数据注册表（对应 MIGRATION 交付序列 2）

- `Metadata/Registry`：`addPostBox(['id','title','screens','context','priority','template','fields'])`、`addTermBox`、`addUserFields`；
- `Admin/MetaboxAdmin` 复用 FieldRenderer 渲染 + ValueNormalizer 保存 + MetaStore 写入；`save_post`（priority 999，模板条件）、`edited_{taxonomy}`、`profile_update` 钩子；
- 兼容读取 `aya_box_{id}`（旧单键全组）；
- 验收：旧主题「独立文章模板」类 metabox 场景重建，字段可存可读；Integration 测试走通保存链路。

### M3 运行时硬化（缩减版：生命周期已随 0.2.0 前移完成）

- 剩余：`Runtime/SchemaVersion` 迁移 runner（schema_version 变更时执行结构升级，如选项形状迁移）；
- `SampleSettings` 降级策略：改为 `WP_DEBUG` 或常量开关下注册，生产不出现示例页；
- 验收：伪造旧 schema_version 走一次升级路径有测试。

### M4 数据契约与内容读取层（契约优先，前移）

DTO 清单直接翻译旧 `inc/core` 的 `*_In_While` 属性表（见工作区 AGENTS.md 的结构说明），并剥离其展示逻辑（K 格式化、timeago、本地化兜底文案、分页 CSS class、菜单 HTML 构造器）：

- `Api/Contract/`：`PostSummary`（id/url/title/type/dates+ISO/excerpt/preview/thumbnail/views/likes/评论数/分类标签/作者摘要）、`PostDetail`（增 content HTML、prev/next、gallery）、`TermDto`（补齐旧版 parent/children 未 DTO 化的不对称）、`AuthorDto`、`ThumbnailDto`、`MenuTree`/`MenuItem`（label/url/target/object/type/children/active）、`Pagination`（standard + simple 两形态）、`Breadcrumb`（`{label,url}[]`）+ 契约版本常量；
- `Presenter/`：WP 对象 → DTO 映射；`the_content` 过滤器在此执行（content HTML 是契约数据）；修复旧 `get_post_views/likes` 缺 property_exists、`WP_Term::get_term()` 布尔优先级两类旧 bug（新实现不引入同类路径）；
- `Domain/Content/`：`ContentQuery`（封装旧 WP_Query 的预设查询集合）、`MenuService`（结构 `wp_cache` 缓存 + 每请求激活态注入 + `wp_update_nav_menu` 清缓存，沿用旧蓝本）、`BreadcrumbService`、`PaginationService`；
- `Modules/` 适配器第一刀：接入 opencc-convert 包 + `aiya_core_addons` 拓展功能设置页；
- 验收：读服务产出 DTO 的形状有单测锁定；Astro 侧可直接按 Contract 生成 TS 类型（M5 才生成）。

### M5 版本化 REST ＋ Astro SSR

- `Api/Rest/`：命名空间 `aiya/core/v1`；控制器只调用 M4 的读服务与 Presenter；资源：内容列表/详情、terms、导航菜单、面包屑/分页（嵌入响应元数据）、站点设置白名单、媒体引用；
- 公开读 + 应用密码写；CORS 允许 Astro 来源白名单；ETag / Cache-Control；
- 产出面向前端的类型契约（OpenAPI 或从 Contract 生成 TS 类型脚本）；
- Astro 侧在 `aiya-astro-bulid/` 初始化：SSR 模式（node adapter，保 SEO），`src/lib/aiya/`（类型化 API client，镜像 Contract、缓存）、`src/pages|components|layouts`；
- 验收：Astro SSR 拉通首屏真实数据，直接命中 WP 域名时由 `aiya-headless` 空壳主题兜底，旧主题可整体退役。

## 五、执行纪律

- 每个里程碑完成时更新本文状态（勾掉条目即可），不在两处维护真相；
- 不为「将来可能用到」预建目录与抽象；切片原则见 MIGRATION.md；
- 运行时验证一律走 Docker wp-cli（`docker compose run --rm wpcli ...`），PHP 语法检查可用 `php -l` 的容器替代方案。
