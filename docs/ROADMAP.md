# AIYA Core 路线图与完成度

本文是当前迭代的实施规划：对照旧 `framework-required` 评估完成度，定义目标目录树与里程碑。模块归属的最终裁决仍以 [MIGRATION.md](MIGRATION.md) 为准，注册方式见 [ARCHITECTURE.md](ARCHITECTURE.md)。

基线：v0.1.0，2026-09-04 评估。运行环境 WP 7.1 / PHP 容器版，插件已激活且示例页 16 字段保存链路实测可用。

## 一、完成度对照（vs framework-required v1.3）

评估口径：旧框架的「选项框架 + Metabox」部分是本插件的重构范围；其 `plugin/` 目录的 16 个辅助模块按 MIGRATION.md 归属 Domain/Infrastructure，不在本表内。

### 1. 运行时与模块机制 —— 100%

| 旧实现 | 新实现 | 状态 |
|---|---|---|
| `AYA_Plugin_Setup` + 字符串类名 `module()` 魔法 | `Contracts/Module` + `Plugin::addModule()` 显式注入 | ✅ 质量高于旧版 |

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
| i18n（文本域 `aiya-core`） | ◐ | 代码内 `__()` 已就位，`languages/` 目录与 .pot 未建 |

### 3. 元数据（Metabox 等价物）—— 约 20%

| 能力 | 状态 | 说明 |
|---|---|---|
| 存储适配器 post / term / user | ✅ | `Metadata/Storage/*`，统一 `ValueStore` 接口 |
| Post metabox 注册 / 渲染 / 保存（旧 `new_box`：screen、context、priority、页面模板条件） | ❌ M2 | 仅缺 Admin 层，渲染/保存应复用 FieldRenderer + ValueNormalizer |
| Term / user 编辑屏字段（旧 `new_tex` 等） | ❌ M2 | 同上 |
| 旧 metabox 键兼容（`aya_box_{id}` 单键存全组） | ❌ M2 | 兼容读取层 |

### 4. 生命周期 —— 0%（M3）

激活 / 停用 / 卸载钩子、schema version、迁移 runner、`uninstall.php` 均未开始；当前靠 `register_activation_hook` 缺位 + 默认值兜底运行。

### 5. 辅助模块（访客计数、widget builder、短代码管理器、CDN、模板重写、REST/AJAX handler 等）—— 0%

按设计不迁移到本框架，逐个落入 Domain/Infrastructure 或随旧前端退役（详见 MIGRATION.md「Legacy disposition」）。其中 `register-theme-menu`（导航菜单）在无头架构下仍需要，最终由 REST 暴露。

### 已知边界行为（非缺陷）

- `aiya_core_register` 重复触发会对同一 slug 抛 `InvalidArgumentException`：真实请求中 `init` 只触发一次；CLI/测试中二次触发需新建 Registry。

## 二、目标目录树

标注：✅ 已有 / M# 建立的切片。目录仍只在第一段可工作代码落地时创建，禁止空目录占位。

```text
aiya-core/
├─ aiya-core.php                    # ✅ 常量、autoloader、boot
├─ uninstall.php                    # M3
├─ src/
│  ├─ Contracts/                    # ✅ Module
│  ├─ Runtime/                      # M3：Activation / Deactivation / Uninstall / SchemaVersion
│  ├─ Settings/
│  │  ├─ Registry.php               # ✅
│  │  ├─ ValueNormalizer.php        # ✅
│  │  ├─ Schema/                    # ✅ Page / Field；M1 增加 note 类型与 options_source
│  │  ├─ Storage/                   # ✅ ValueStore / OptionStore；M1 + LegacyOptionReader（只读 aya_opt_*）
│  │  └─ Options/                   # M1：OptionsResolver（terms/posts/users/sidebars 惰性查询）
│  ├─ Admin/
│  │  ├─ SettingsAdmin.php          # ✅
│  │  ├─ FieldRenderer.php          # ✅；M1 支持 note/分组渲染
│  │  └─ MetaboxAdmin.php           # M2：post/term/user 编辑屏注册、渲染、保存
│  ├─ Metadata/
│  │  ├─ Registry.php               # M2：addPostBox / addTermBox / addUserFields
│  │  └─ Storage/                   # ✅ PostMetaStore / TermMetaStore / UserMetaStore
│  ├─ Domain/                       # M4 起：Issue/ / Tweet/ …（首个切片落地时建目录）
│  ├─ Infrastructure/               # M4+：Media/ 等按需
│  └─ Http/
│     └─ Rest/                      # M5：v1 控制器与资源表示
├─ assets/                          # ✅ admin.css / admin.js（Backbone + jQuery UI + WP 原生依赖）
├─ languages/                       # M1：aiya-core.pot + zh_CN 翻译
├─ tests/
│  ├─ Unit/                         # M1 起：ValueNormalizer / Field / OptionStore
│  └─ Integration/                  # M2+：metabox 保存链路（wp-env 或 wp-cli 驱动）
├─ composer.json                    # M1：phpstan + phpunit dev 依赖与脚本
└─ docs/                            # ✅ ARCHITECTURE / MIGRATION / ROADMAP
```

依赖方向不变（ARCHITECTURE.md）：Schema/Normalization 不依赖 Admin 与 HTTP；Admin 依赖 Schema；前端只走 M5 的版本化 API。

## 三、里程碑

### M1 设置框架收尾（当前迭代）

- 新增 `note` 字段（info/success/warning/error 变体）与字段分组标题，替代旧 title/content 伪字段；
- `Options/OptionsResolver`：`options_source => ['source' => 'terms|posts|users|sidebars', ...]`，Schema 校验来源定义，Admin 渲染前惰性求值（不查询直到渲染）；
- 读取门面 `aiya_core_opt(string $page, string $id, mixed $default = null)` 与 `aiya_core_opt_bool(...)`；`Storage/LegacyOptionReader` 只读兼容 `aya_opt_{slug}` 旧键，旧→新无写回、无同步；
- `languages/` + .pot；`tests/Unit` 覆盖 ValueNormalizer 与 Field；composer.json（phpstan max level + phpunit）；
  - ✅ 工具链已就绪（2026-09-04）：composer dev 依赖（phpstan 2 @ level 8、wpcs 3 + PHPCompatibilityWP、parallel-lint、wp-cli i18n-command）+ `phpstan.neon.dist` / `phpcs.xml.dist` / `phpstan-bootstrap.php`，`composer php:stan|php:cs|php:cbf|php:lint|i18n:pot` 全部通过；宿主机无 PHP 时用 `docker run --rm -v <插件目录>:/app -w /app composer:2 <script>` 执行。`.pot` 已生成于 `languages/aiya-core.pot`。剩余：phpunit 与单测落地。
- 验收：以旧 `opt-basic.php` 的真实字段集在新框架重建「站点」页，保存/校验/重置/动态选项全部工作；单测与 phpstan 绿。

### M2 元数据注册表（对应 MIGRATION 交付序列 2）

- `Metadata/Registry`：`addPostBox(['id','title','screens','context','priority','template','fields'])`、`addTermBox`、`addUserFields`；
- `Admin/MetaboxAdmin` 复用 FieldRenderer 渲染 + ValueNormalizer 保存 + MetaStore 写入；`save_post`（priority 999，模板条件）、`edited_{taxonomy}`、`profile_update` 钩子；
- 兼容读取 `aya_box_{id}`（旧单键全组）；
- 验收：旧主题「独立文章模板」类 metabox 场景重建，字段可存可读；Integration 测试走通保存链路。

### M3 运行时硬化（对应 MIGRATION 4）

- activation（默认值播种 + schema_version）、deactivation、`uninstall.php` 白名单清理、迁移 runner；
- `SampleSettings` 降级策略：改为 `WP_DEBUG` 或常量开关下注册，生产不出现示例页；
- 验收：启停删无残留，版本升级路径有测试。

### M4 首个内容域（对应 MIGRATION 6）

- `Domain/Issue` 与 `Domain/Tweet`：CPT 注册模块（含设置页、元数据、REST 就绪的读取层）；
- `Infrastructure/Media` 第一刀（附件处理如有真实需求才建）；
- 验收：后台可管理两类内容，域内读取不依赖 Admin/HTTP。

### M5 版本化 REST（对应 MIGRATION 7）＋ Astro 接入

- `Http/Rest`：命名空间 `aiya/core/v1`；资源：内容列表/详情（含 meta 投影）、terms、导航菜单、站点设置白名单、媒体引用；
- 公开读 + 应用密码写；CORS 允许 Astro 来源白名单；ETag / Cache-Control；
- 产出面向前端的类型契约（OpenAPI 或 TS 类型生成脚本）；
- Astro 侧在 `aiya-astro-bulid/` 初始化：`src/lib/aiya/`（API client、类型、缓存）、`src/pages|components|layouts`；渲染策略（SSG/SSR/混合）在 API 稳定后依部署目标决定；
- 验收：Astro 骨架拉通首屏真实数据，旧主题可整体退役。

## 四、执行纪律

- 每个里程碑完成时更新本文状态（勾掉条目即可），不在两处维护真相；
- 不为「将来可能用到」预建目录与抽象；切片原则见 MIGRATION.md；
- 运行时验证一律走 Docker wp-cli（`docker compose run --rm wpcli ...`），PHP 语法检查可用 `php -l` 的容器替代方案。
