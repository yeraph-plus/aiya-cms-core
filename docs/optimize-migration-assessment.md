# basic-optimize 组件迁移评估（vs 新无头实现）

对象：旧 `aiya-cms-wordpress-theme/plugins/basic-optimize/inc/` 全部 16 个组件。逐个通读源码，并对照 `wordpress-source/`（WP 7.1）验证所依赖的钩子。评估维度：**WP 7.1 有效性**、**无头架构下的价值**（前台已归 Astro，WP 只保留后台管理 + REST + 媒体 + 用户）、**迁移形态**（直接迁移 / 改造迁移 / 拆解参考 / 弃用）。

`basic-optimize.php` 本体（批量禁用开关）已由 `Infrastructure/Headless/HeadlessModule`（0.3.0）替代；其「禁用 REST API」语义被有意丢弃——REST 是无头架构的命脉。

## 结论总表

| 组件 | 职责 | WP 7.1 | 评估 | 去向 |
|---|---|---|---|---|
| `basic-optimize.php` | 批量禁用开关 | ✅ | ✅ 已迁移 | `HeadlessModule`（0.3.0） |
| `basic-security.php` | 后台访问控制、邮箱登录、用户名防护、REST users/sitemap 移除、登录页参数门禁 | ✅（`wp_sitemaps_add_provider`、`rest_endpoints`、`authenticate` 均在） | **高价值，迁移** | `Infrastructure/Security/SecurityModule`，第一批 |
| `wp-local-avatars.php` | 本地头像（user meta `basic_user_avatar`，`get_avatar_data` 过滤） | ✅（`get_avatar_data` / `get_avatar_url` 在 link-template.php） | **高价值，改造迁移**——meta 键已是持久协议（AGENTS.md 协议表），无头下头像 URL 是 API 契约的一部分 | `Domain/Identity/Avatars`，第二批 |
| `avatar-speed.php` | Gravatar CDN 镜像（七牛/loli/v2ex/weavatar）、默认头像 | ✅（同一组头像过滤器） | 有价值，与本地头像合并（本地优先、CDN 镜像兜底）；Google Fonts 替换部分弃（前台归 Astro） | 并入 `Domain/Identity/Avatars`，第二批 |
| `stmp-mail.php` | SMTP 发信（`phpmailer_init`）+ 关闭新用户通知邮件 | ✅（pluggable.php:622） | 有价值——后台邮件（密码重置等）在无头架构下仍是刚需；SMTP 密码正好用设置框架的写后即焚 `password` 字段 | `Infrastructure/Mail/MailModule`，第二批 |
| `basic-automatic.php` | 自动别名（拼音/ID 式 AV·BV 风格）、保存时中文排版纠正、HTML 清理、自动匹配标签、重置日期、编辑器默认内容 | ✅（`wp_insert_post_data`、`wp_unique_post_slug`、`wp_insert_term_data`、`wp_update_term_data`、`default_content` 均在；`default_content` 现仅后台编辑器上下文） | **高价值，分两步**：拼音/ID 别名独立成片（只依赖 overtrue/pinyin）；排版清理 + 自动标签依赖保存时的动作勾选（`AYF::get_post_action` → 恰是 M1 待定的 `action_checkbox` 字段的真实用例），等 M2 metabox 落地后迁 | `Domain/Content/`（别名为 `SlugGenerator`），第三批 |
| `basic-request.php` | 主查询优化（no_found_rows + EXPLAIN found_posts）、搜索重定向/权限/限流/SQL 改写（标题搜索、ID 搜索、meta 搜索） | ✅（`pre_get_posts`、`posts_clauses` 均在） | **拆解**：前台主查询在无头下不存在，前台搜索 UI 死亡 → 代码不迁；但「IP 限流」「仅标题搜索」「meta 搜索」是 M4 `ContentQuery` / M5 API 的直接设计输入 | 设计参考 → M4/M5；URL 参数拦截（eval/base64/超长）可并入 SecurityModule |
| `seo-stk.php` | wp_head 输出 title/keywords/description、正文关键词自动链接、robots.txt 自定义 | ✅（`pre_get_document_title`、`robots_txt` 在） | **拆解**：输出面随主题退役（SEO 归 Astro head）；**数据面进新架构**——`post_seo` metabox（seo_keywords/seo_desc，协议键 `aya_box_post_seo`）由 M2 Metadata 重建，字段投影进 M4 `PostDetail`/`PageMeta` DTO | 数据 → M2/M4；输出 → 弃 |
| `ua-firewall.php` | UA/IP/URL 参数黑名单 403 | ✅（init 钩子 wp_die 可行） | 低价值——无头后 WP 攻击面只剩 wp-login.php，边缘防护应交给反代/CDN；旧代码里被注释的「登录失败限速」半成品值得重新设计（transients 实现） | SecurityModule backlog，非首批 |
| `custom-dashboard.php` | 后台化妆（前台 admin bar、页脚、欢迎面板、admin bar 链接） | ✅ | 可选、便宜；前台 admin bar 部分无意义 | 可选迁移（后台体验组），低优先级 |
| `wp-dashboard-widget.php` | 仪表盘服务器状态（PHP/OPCache/Apache） | ✅ | 可选诊断工具；Docker 环境下用 wp-cli 也能查 | 可选迁移，低优先级 |
| `comment-filter.php` | 评论反垃圾（黑名单/语言/长度/链接数/自定义正则，`preprocess_comment`） | ✅ | **弃**——评论已整体禁用；未来若 Astro 侧做评论系统，按新架构重设计 | 弃 |
| `wp-widget-cache.php` | 小工具输出缓存 | ✅ | **弃**——widgets/侧边栏随旧前端退役（MIGRATION.md 已定） | 弃 |
| `wp-no-category-url.php` | 去除分类 URL 的 /category/ 前缀 + 301 | ✅ | **弃**——前台 URL 结构归 Astro 路由；旧链接 301 由反代/Astro 处理 | 弃 |
| `site-statistics.php` | GA/自定义脚本注入 wp_head | ✅ | **弃**——统计脚本归 Astro（组件/布局层注入） | 弃 |
| `queries-debug-print.php` | DEBUG 常量 + 页脚打印 SQL | ⚠️ 构造器内 `@define('WP_DEBUG')` 时机过晚，本就是坏味道 | **弃**——用 wp-cli `--debug` 与 Query Monitor 替代 | 弃 |

## 建议迁移批次

1. **第一批 `Infrastructure/Security/SecurityModule`**（替代 basic-security 的有效面）：
   - REST `/wp/v2/users` 与 sitemap users provider 移除（无头下的用户枚举防护；作者信息走 M4 `AuthorDto` 嵌入文章响应，不需要 users 端点）
   - 强制邮箱登录、admin 用户名注册/登录防护
   - 后台按角色门禁 + 登录页 `?auth=` 参数门禁（开关化）
   - URL 参数非法拦截（从 basic-request 摘入）
2. **第二批 `Domain/Identity/Avatars` + `Infrastructure/Mail/MailModule`**：
   - 本地头像保留协议键 `basic_user_avatar`，`get_avatar_data` 过滤服务后台；Gravatar CDN 镜像兜底；M5 在 `AuthorDto.avatar` 中投影
   - SMTP：host/port/auth/加密/from 配置走设置页，密码用 `password` 字段
3. **第三批 `Domain/Content/` 自动别名**（可早于 M2 做别名部分）：
   - 拼音 slug（post/term）+ ID 式 slug（AV/BV 风格 + 前缀设置）；运行时依赖 `overtrue/pinyin` 进根 composer require（core 直接依赖，理由：这是内容写入行为而非独立基础设施，不值得做成包）
   - 中文排版/HTML 清理/自动标签/重置日期：等 M2 的 metabox + `action_checkbox` 字段（此处即该字段的真实用例），批量刷新工具改为 wp-cli command
4. **M4/M5 设计输入**（不迁代码）：搜索限流/标题搜索/meta 搜索 → `ContentQuery` 搜索参数与 API 限流；SEO meta → `PostDetail`/`PageMeta` DTO；robots.txt 与 sitemap 的最终归属（Astro 生成、WP 提供数据）在 M5 一并定。

## 依赖账本

迁移将新增的 composer 依赖：`overtrue/pinyin ^6.0`（第三批，运行时）；`jxlwqq/chinese-typesetting ^1.2`（排版功能落地时，运行时）。均沿用旧 `plugins/composer.json` 已验证的约束。
