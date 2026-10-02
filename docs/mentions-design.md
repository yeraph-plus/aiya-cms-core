# @提及（Mentions）设计稿

状态：**已实施归档（2026-10-03）**。实施与设计稿的差异：① 提及蓝链按零路由原则以 `data-aiya-ref="user"` 标记产出（无 href，前台 resolveRefs 补路由）；② 头像展示取消（站长拍板蓝链无头像）；③ 显示名解析走 `$wpdb` 直查（WP_User_Query 精确搜索对 CJK 返回 0 的实测绕开）。实施记录见 ROADMAP「@提及通知落地」批次。本文件仅存设计推演与限制记录。

---

# @提及（Mentions）设计稿（原始推演）

登记于 2026-10-03。对应「开放调研处置登记」列入计划项「@提及通知」。

范围：评论（帖子/页面/资源的评论流）与社区（帖子正文 + 回复）两面的 @ 提及支持——渲染蓝链跳前台用户页 + 被提及者通知。

## 一、语法与身份解析

**令牌**：`@` + `[A-Za-z0-9_\-一-鿿]{2,64}`（`u` 修饰符；无空格）。

**解析顺序**（每个令牌）：

1. `get_user_by('slug', $token)` —— 精确匹配 `user_nicename`；
2. 未命中 → `get_users(['search' => $token, 'search_columns' => ['display_name'], 'exact' => true, 'number' => 2])` —— `display_name` 精确唯一才命中，命中 >1（重名）即放弃：不链接、不通知。

**现实约束（已核实）**：

- 注册用户的 `user_nicename` 就是 UUID（与 login 相同，不可读）——人类身份在 `display_name`（昵称）；
- CJK 昵称天然单令牌，v1 主场景直接覆盖；
- **含空格的显示名 v1 不可提及**（令牌无空格）——记录为限制；v2 可加「对已知显示名的最长前缀匹配」；
- 精确令牌匹配天然回避前缀歧义：`@小明子` 命中小明子、`@小明你好` 不命中任何人。

## 二、服务设计

新类 `Aiya\Core\Domain\Content\Mentions`，两个方法共用同一 tag-aware 令牌扫描（`wp_html_split`，只取文本节点；跳过 `code`/`pre` 与既有标签内部——代码块里的 @ 不链接也不通知，所见即所指）：

| 方法 | 输入 | 输出 | 消费方 |
|---|---|---|---|
| `resolve(string $text): list<int>` | 原文（kses 后 HTML） | 被提及用户 id 列表（首现序、去重、上限 10/内容） | NotificationActions |
| `linkify(string $html): string` | 渲染前 HTML | 蓝链注入后 HTML | 两个 Presenter |

解析查询成本：每个唯一令牌 1–2 次 `get_user_by`/`get_users`；社区 contentHtml 走既有 600s 内容哈希缓存，评论逐条渲染可接受。

## 三、渲染管线接线（零存储改动）

| 面 | 管线 | 说明 |
|---|---|---|
| 评论 | `wp_kses`（存储白名单）→ smilies → `linkify` | **存储白名单不加 `a`**：蓝链只在读侧注入，存储面不给用户开链接口子（防垃圾外链）；正文里的 `@xxx` 以纯文本入库 |
| 社区 bodyHtml | smilies → `linkify` → `do_shortcode` | 存储已走 `wp_kses_post`（本就允许 a）；linkify 在短代码展开前跑，`[list]` 等零件内容里的提及同样生效 |

链接形状：~~`<a href="/profile/{nicename}/">@输入令牌</a>`~~ **已修订（2026-10-03，被后台零路由原则接管（ARCHITECTURE「Zero-routing rule and reference markers」节））**：linkify 改产 `<a data-aiya-ref="user" data-aiya-nicename="{nicename}">@输入令牌</a>`（无 href——后台不产路径），前台 `resolveRefs` 按自身路由表补 `/profile/{nicename}/`。link 文本保持用户输入不重写；nicename 为 UUID 时链接可用但 URL 不优雅（记录为已接受）。

contentHtml 缓存键不变：蓝链随内容 hash 进 600s 缓存，昵称改名的陈旧度由 TTL 界定。

## 四、通知设计

**类型**：`NotificationService::TYPES` 增加 `comment_mentioned`、`thread_mentioned`（`Notification.type` 契约是 string，**快照零变化**）。

**挂点**：**不新增 do_action** —— NotificationActions 现有三动作内联解析：

| 动作 | 原文来源 | 收件人 = resolve − 已收通知者 |
|---|---|---|
| `onCommentInserted` | `comment_content` | actor、post_author、楼中楼父评论者 |
| `onThreadReplied` | 回复内容（`replyById`） | replier、楼主 |
| `onThreadPublished` | 帖子内容 | 作者；且提及名单传 `fanOutToFollowers()` 作排除——被提及的粉丝不重复收 followed 行 |

**规则**：每内容 ≤10 个提及（防通知风暴）；自我提及不通知；审核未过不通知（`wp_insert_comment` approved-only 既有门）；**编辑不重发**（v1 仅创建时解析）；仅解析存在的用户（提及即达是被提及的意义，沿评论/回复先例不做关系门槛）。

**文案**（沿无标题回退模式）：

- 评论：`%1$s mentioned you in a comment on "%2$s".`（excerpt 沿既有 16 词裁剪）
- 社区有题：`%1$s mentioned you in the thread "%2$s".`
- 社区无题：`%1$s mentioned you in a community thread.`

**域边补册**：ARCHITECTURE 跨域消费清单加 Notification → Content(Mentions)。

## 五、前端（唯一改动点）

- `sanitizeCommentHtml`：allowedTags 加 `a`、allowedAttributes 加 `a: ['href']`，transform 收紧——href 非 `/` 开头即降级为无链接（评论 body 里 `a` 的唯一来源就是提及注入，收紧无副作用）；
- `sanitizeDiscussionHtml` 已允许 `a[href]`，零改动；
- 蓝链跳 `/profile/{slug}/`（ProfilePresenter 按 nicename 解析已就绪），样式走既有链接样式（蓝链）。

## 六、契约与快照

**零变化**：`bodyHtml` / `contentHtml` 是字符串；`Notification.type` 是 string。无新端点、无新参数、无迁移。

## 七、测试与探针计划

- `MentionsTest`（垫片补 `get_user_by` / `get_users`）：nicename 命中、唯一 display 命中（CJK）、重名放弃、未知跳过、code/pre 跳过、去重+首现序+上限 10、linkify 精确断言（`<a href="/profile/x/">@x</a>`）、既有链接内部不动；
- 真链路探针：双用户评论提及（通知行 type=comment_mentioned + bodyHtml 含蓝链）、社区回复提及（thread_mentioned）、自我提及不通知、楼主+提及去重场景、未注册 @ 不命中。

## 八、v1 明确不做

含空格显示名的提及（v2 最长前缀匹配）；编辑内容重发提及通知；提及的关系门槛（无需关注/会员即可被提及——这是提及的意义）；提及列表 API（谁提到了我）；**访客 cookie 自选语言**（2026-10-03 站长拍板：无计划、不需要支持——语言跟随登录账户或站点默认）。
