# 邮件域重设计调研稿

状态：**已拍板（2026-10-03），待实施**。拍板记录与批次计划见下节；§四起的原始推演保留作机制依据（其中「pre_wp_mail 短路接管」的推荐已被拍板 ②⑥ 否弃，改走 `wp_mail` 参数过滤器最小侵入，见拍板记录的架构修正）。

## 〇、拍板记录（站长，2026-10-03）与实施计划

| # | 决定 |
|---|---|
| ① | **全站接管**：WP 自身邮件与后台手动邮件（SendMailPage）套入同一邮件样式模板 |
| ② | **transport 不做**：无 SMTP/API 适配器、无高频邮件诉求，保持 WP 默认传输 |
| ③ | **模板品牌**：主色 = 前端设置 `color_primary`；logo = WP 站点图标（`get_site_icon_url()`）；站名 = `blogname` |
| ④ | 注册欢迎/验证邮件：下一轮计划（本批不做） |
| ⑤ | 改邮箱/改密码等**链接回落 WP 页面**的邮件：按既有实现（`PasswordResetService` 的 `FrontendDomain::origin()` + 前台路径构建）**重写文案与链接**为前台地址 |
| ⑥ | 无发送日志、无重试；**只替换文本与样式，发送行为保持 WP 原样不侵入** |

**架构修正（拍板推导）**：原推荐的 `pre_wp_mail` 短路必然伴随自管 transport（短路后需自行实例化 PHPMailer 发送），与 ②⑥ 冲突——改走 **`wp_mail` 参数过滤器最小侵入**：只改写 message/headers（套壳/重写），发送仍走 WP 原生链路（默认 `isMail()`），过滤器不做任何传输决策。两层：

1. **逐点重写层**（拍板 ⑤）：WP 自带的 per-mail filter 重写文案+链接为前台 URL 并产出**成品品牌 HTML**——`retrieve_password_message`（wp-login 兜底流的重置链接 → `FrontendDomain::origin() + /reset-password?login&key`，同 `PasswordResetService` 形状）、`wp_new_user_notification_email_user/_admin`、`send_password_change_email`、`send_email_change_email`（其正文里的 wp-login/资料页链接按前台对应面重写）。
2. **通用套壳层**（拍板 ①）：`wp_mail` args filter 兜底——text/plain（core 管理邮件等）转义后进品牌壳内容槽；text/html（SendMailPage 手动邮件）原文进壳；逐点重写层产出打请求级标记防双壳。From/主题不额外改写（不侵入；From 美化留可选小项）。

**批次计划**：

| 批次 | 内容 |
|---|---|
| A | 模板壳（inline CSS、主色 `color_primary`、站点图标/站名头条、页脚免责；CTA/内容槽组件）+ `wp_mail` args 套壳层（plain 转义入壳 / html 入壳 / 标记跳过）+ SendMailPage 套壳 + 单测 |
| B | 逐点重写层（⑤的四组 filter：找回密码兜底流/新用户通知/改密通知/改邮箱通知，前台链接复用 `FrontendDomain` origin 解析，成品 HTML 打标记）+ 单测 |
| C | 到期提醒邮件副本（`onExpiryScan` 的邮件面——事务性邮件立项目标；范围待站长确认随本批或下批） |
| 下一轮 | 注册欢迎/验证邮件（④）；From 地址美化（可选小项） |

i18n：全部新文案走 `aiya-core` 文本域 + `wp-i18n-zh-cn` 流程。

---

# 邮件域重设计调研稿（原始推演）

## 一、现状：薄邮件域的真实内容

| 组件 | 现状 |
|---|---|
| `MailModule` | 仅一件事：`notify_post_author` / `notify_moderator` 两 filter 恒 false——核心评论通知邮件静默（链接死在壳主题上，站内通知已覆盖同事件） |
| `SendMailPage` | 后台「Send Mail」页：经典编辑器写 HTML、收件人可搜用户、`edit_users` + 限流（20 封/10 分钟），经 `wp_mail()` 以 `text/html` 发送——现状下唯一的 HTML 邮件出口，可复用为未来模板的**测试发送入口** |
| `PasswordResetService` | 域内唯一事务邮件：密码重置（纯文本、前台落地链接、失败返回 `WP_Error`）——当前直接裸调 `wp_mail()`，无模板 |
| 其余域 | 零邮件调用（已全仓 grep 核实：通知/赞助/积分/社区全部走站内通知行） |

事实约束：`wp_mail()` 是全站**唯一**邮件收口（pluggable 函数），默认 `isMail()`（PHP `mail()` 传输）、`text/plain`、无任何模板概念。所有"替换邮件"的讨论本质都是：在这一个函数的周边做文章。

## 二、WP 7.x 邮件机制：拦截点全景（已读 wordpress-source 核实）

### wp_mail 内部链与过滤器

```
wp_mail($to,$subject,$message,$headers,$attachments,$embeds)
 ├─ filter wp_mail            —— 改参数（全量 args）
 ├─ filter pre_wp_mail        —— ★短路：返回非 null 即不发 PHPMailer（5.7+）
 ├─ filter wp_mail_from / wp_mail_from_name
 ├─ filter wp_mail_content_type（默认 text/plain）/ wp_mail_charset
 ├─ action phpmailer_init     —— SMTP 插件的经典挂点（直改 PHPMailer）
 ├─ 6.9 新增：$embeds 第 6 参（CID 内嵌图 addEmbeddedImage + wp_mail_embed_args
 │             filter）、headers 里 multipart Content-Type 支持
 └─ 失败：返回 false + action wp_mail_failed
```

### pluggable 可整替的邮件函数

`wp_mail` 本体、`wp_notify_postauthor`、`wp_notify_moderator`（前两者已被 MailModule 用 filter 静默）、`wp_password_change_notification`（重置后→管理员）、`wp_new_user_notification`（新用户→管理员+用户，含重置链接）。

### 三种拦截策略对比

| 策略 | 能做什么 | 代价 | 适用 |
|---|---|---|---|
| `pre_wp_mail` 短路 | **接管全部流量**（core+插件+未知未来调用方），统一模板化+transport；`phpmailer_init` 类 SMTP 插件从此失效（短路后不实例化 PHPMailer——本域即取代其角色） | 收到的多是纯文本，"美化"= 套壳而非重写内容 | 推荐主拦截层 |
| pluggable 整替 | 全权重写某类邮件内容（如新用户欢迎邮件直接产 HTML） | 每个 pluggable 函数必须完整复刻语义（locale 切换、收件人判定）；漏一处即行为回归 | 仅当套壳不够、需要彻底重写个别邮件 |
| 逐点 filter 关停/重写 | `retrieve_password_message`、`send_password_change_email` 等每邮件一个 filter | 面多且散，新 WP 版本新增邮件面（如 6.9 的 Notes 提及）会漏 | 补充手段 |

## 三、本站真实邮件触发面盘点（已逐点核实）

### 用户面（无头前端真实触发）

| # | 邮件 | 触发链 | 收件人 | 现状 | 建议 |
|---|---|---|---|---|---|
| 1 | 密码重置 | 前端 REST → `PasswordResetService`（自有内容） | 用户 | 纯文本 | **模板化 P0**（唯一常态用户事务邮件）；迁移为 Mailer 首个消费者 |
| 2 | 密码重置（壳兜底流） | wp-login.php → `retrieve_password()` | 用户 | 纯文本 | `pre_wp_mail` 套壳（链接是正文裸 URL，套壳不改写） |
| 3 | 「密码已更改」安全通知 | 前端改密 → `wp_update_user` 内 `send_password_change_email`（default true） | 用户 | 纯文本 | 套壳 |
| 4 | 「邮箱已更改」安全通知 | 前端改邮箱 → `wp_update_user` 内 `send_email_change_email` | 旧邮箱 | 纯文本 | 套壳（安全通知有保留价值） |
| 5 | 注册欢迎/验证 | 前端注册 → **无任何邮件**（不调 `wp_new_user_notification`，已核实） | — | 缺口 | 是否新增 = 拍板点 ④ |
| 6 | 会员到期提醒 | `onExpiryScan` 仅站内通知行 | — | 缺口 | 本批立项的事务邮件主目标（到期前 N 日邮件副本） |

### 管理面（admin 触发/低频）

| # | 邮件 | 触发 | 建议 |
|---|---|---|---|
| 7 | 重置后管理员通知 | `after_password_reset` → pluggable | 套壳或保留 |
| 8 | 自动更新结果 ×3 | `class-wp-automatic-updater`（core/plugin/theme） | 套壳（纯文本表格化原文）或保持 |
| 9 | **恢复模式** | fatal 后 `class-wp-recovery-mode-email-service`（含恢复链接——最关键管理邮件） | 套壳必须保留链接完整性 |
| 10 | 站点管理员邮箱变更确认+通知 | `misc.php`（确认→新邮箱）/ `functions.php`（通知→旧邮箱） | 套壳 |
| 11 | 隐私请求组 | `user.php` 3 处 + `privacy-tools.php`（导出/擦除确认与完成） | 套壳 |
| 12 | wp-admin 新用户邀请 | `user-new.php` ×2 | 套壳 |
| 13 | 安装通知 | `wp_new_blog_notification`（仅安装时一次） | 保持 |

### 已静默 / 不触发

评论作者与审核通知（MailModule 已静默）；Notes 便签提及邮件（WP 7.x wp-admin 内部协作面，低频，保持原样）；multisite 16 处（单站点永不触发，不做）。

**触发链注意**：`send_password_change_email` / `send_email_change_email` 是 `wp_update_user` 内联逻辑（default true），前端 REST 改资料路径同样命中——不是 wp-admin 专属。

## 四、替换策略：推荐三层架构

```
┌ 层1  Domain/Mail\Mailer —— 域内事务服务（新）
│    send(MailEnvelope): bool   envelope = to/subject/template/参数槽
│    模板渲染（HTML）→ transport 发送；aiya-core 各域改走它
│    （PasswordResetService 迁移为首个消费者；到期提醒为第二批）
├ 层2  pre_wp_mail 接管 —— 全站收口（新）
│    非 aiya-core 标记的流量：text/plain 套品牌壳（原文进内容槽）→ 同一 transport；
│    text/html 调用方可配原样/轻壳；递归防护 = 内部发信带标记直接走 transport
└ 层3  pluggable 整替 —— 按需（可选）
     仅当个别邮件"套壳不够"需要彻底重写时逐个替换
```

- 层2 落地后，第三方 SMTP 插件的角色被本域取代（短路后 `phpmailer_init` 不再触发）——属预期行为，无需兼容。
- From 策略：`wp_mail_from`/`wp_mail_from_name` filter 或 Mailer 配置，默认 `noreply@{站点域}` + 站点名；From 域必须与发信域的 SPF/DKIM 验证一致，否则进垃圾箱。

## 五、模板体系（美观样式的现实约束）

- **客户端现实**：Outlook（Windows）用 Word 引擎渲染——必须 **600px 定宽 + table 布局 + 全部 inline CSS**；现代客户端可用 `max-width:600px;width:100%` 做 fluid 简化响应式；暗色模式用 `<meta name="color-scheme">` + 中性底色防"纯白被强制反色"。不做运行时 CSS 内联库（emogrifier 类）——**模板文件直接手写 inline style**，数量少（base 壳 + ~6 个内容模板）可控。
- **结构**：base 壳（品牌头条：logo/站名 → 内容槽 → 页脚：站名 + "这是一封系统邮件"免责）；内容组件：CTA 大按钮（重置链接）、信息键值行、警示框（到期提醒）。
- **图片资产**：logo 走媒体库附件 ID 配置，用 WP 6.9 的 `$embeds` CID 内嵌（不依赖外链图、不惧图片代理拦截）；未配置时退化为纯文字站名。
- **品牌数据源**：站点名/logo/主色从站点配置读（logo 用定制性设置或媒体库 ID 新设置项）；文案 zh_CN 单语起步，走 `aiya-core` 文本域（`wp-i18n-zh-cn` 流程）。
- **零路由豁免论证**：邮件里的链接必须是**绝对 URL 且指向前台域名**——邮件天生是前台消费面，与「重置链接/门禁 302」同属 FrontendDomain 配置原点例外；模板内链接一律由 FrontendDomain 设置构造，不写死路由段。

## 六、Transport（事务性接入）

- **适配器注册表**（沿 FileServe `aiya/openlist` 惯例）：接口 `send(envelope): bool`，注册进 Mail 设置页选择。
  - **SMTP 适配器**：PHPMailer `isSMTP()`（WP 自带库），host/port/加密/凭据可配——通用底座，自建邮箱与多数服务商 SMTP 通吃，推荐 v1 必做。
  - **HTTP API 适配器**：按站长实际服务商做 1–2 个（阿里云邮件推送 DirectMail / 腾讯云 SES / SendGrid / Resend / Postmark…各有密钥+区域+签名差异）——拍板点 ②。
- **失败语义**：`send(): bool` 与 `wp_mail` 同形；事务邮件失败必须可见（密码重置已返回 `WP_Error`，前端有对应文案）。
- **日志与重试**：v1 建议——Mail 设置页显示最近 N 条发送记录（时间/收件人/主题/结果，option 存储、上限裁剪）；**不做自动重试队列**（失败即报，用户可重发；到期提醒由每日 cron 天然重试窗口）。队列化留待真实需要。

## 七、拍板点

| # | 问题 | 推荐 |
|---|---|---|
| ① | `pre_wp_mail` 接管范围 | **全部接管**：用户面邮件进专属模板，管理面邮件统一套品牌壳（内容不改写）；vs v1 仅接管用户面、管理邮件保持原样 |
| ② | transport 首批适配器 | SMTP 必做；HTTP API 首个服务商由站长指定（阿里云 DM / 腾讯云 SES / SendGrid / Resend…） |
| ③ | 模板品牌资产 | logo（媒体库 ID）、主色、宽度 600px、zh_CN 文案——站长出样式意图后落模板 |
| ④ | 注册欢迎/验证邮件是否新增 | v1 不做（现状注册无邮件可用）；要做则连同邮箱验证流一起设计 |
| ⑤ | 前端改邮箱无确认链路（直接生效，核对过 `send_confirmation_on_profile_email` 仅挂 wp-admin 的 `personal_options_update`） | 登记**产品缺口**另行拍板（事务确认流），不混入邮件模板批次 |
| ⑥ | 发送日志/重试 v1 范围 | 设置页最近 ~20 条记录、无自动重试 |
