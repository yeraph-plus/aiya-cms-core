# AIYA Headless — Shell Theme（经典渲染壳主题）

本目录是 **AIYA CMS 的配套经典壳主题**，随 aiya-core 仓库版本化，作为
`wp-content/themes/aiya-headless/` 运行位的同步源。仓库内的这份拷贝与
运行位必须保持逐字节一致：改动可以在任一侧进行，收尾时同步回另一侧。

## 定位

- WordPress 是本站的数据后端（内容 / 媒体 / 用户 / 版本化 REST API），
  公开前端由 Astro（front-station）渲染。直连 WP 域名时由本主题兜底，
  把 WordPress 本体的路由形态完整渲染出来：文章列表 / 归档 / 搜索 /
  404 / 单篇 post / page / 附件 / 草稿·待审·定时预览 / 评论。
- 经典主题（无 block editor 面）：**不声明任何 theme supports、不引导
  任何框架、不引用 aiya-core、不读写 aiya-core 的定制字段与自建表**；
  内容出口只有 the_* 系列核心标签。唯一一处「插件感知」：对 aiya-core
  在 WP 原生查询面追加的 `resource` CPT 与 `page_category` 分类法做
  主查询注入与分类法展示（见下），守卫只看 `AIYA_CORE_VERSION`
  常量是否存在，core 停用后本主题照常工作。
- **对 core 原生改动面的适配**：aiya-core 对 WP 本体查询面的追加只有
  两处——`resource` CPT 与挂原生 page 的 `page_category` 分类法
  （均出自 ContentTypeModule）。主题只补一处查询：home / 日期·作者
  归档默认只查 `post`，扩为 `['post', 'resource']`。搜索与术语归档
  一律不动：原生搜索 post_type 为 `any`（全可搜索类型，含 page 与
  resource——窄化成 `['post','resource']` 反而会丢 page，已实测）；
  自定义分类法术语归档由 WP_Query 按挂载类型自动收窄 post_type，
  原生自适配。归档页标题沿用 `get_the_archive_title()` 的 is_tax
  分支（前缀取注册时的 `labels->singular_name`，aiya-core 翻译域
  负责翻译），主题零兼容。
- **单篇 meta 读取自定义分类法**：singular.php 对挂载的公开非内建
  分类法（resource 六法 + page 的 page_category）逐个列出术语
  （空法跳过），标签格式复用核心已译串 `%s:`（taxonomy term
  archive title prefix）+ 分类法注册标签；`category`/`post_tag`/
  `post_format` 保持各自原有渲染不重复。
- **单篇阅读列收窄**：`wp-singular` 下壳从 960px 收窄为 760px
  （720 阅读宽 + 内边距），品牌行/正文/页脚共用一条居中列——主题
  无小工具支持，不预留任何侧栏位。
- UI 直接复用 WordPress 后台样式表：functions.php 点装 core 的
  common / forms / buttons 三个句柄（core 在前台请求同样注册这批
  句柄），主题 style.css 以其为依赖做增量覆盖；界面文案全部走
  core default 文本域（WP 术语的英文原串，站点语言包负责翻译），
  **主题不自带翻译域、不建 i18n 机器**；登录用户前台自动获得
  admin bar（core 自管样式与让位）。
- 评论区只读：只渲染已有评论列表，不提供发表表单与回复链接
  （comment_reply_link 过滤为空），公开页面不给 bot 留提交面。
- 一条自持守卫：robots 双保险（robots_txt 全站 Disallow 与 wp_robots
  noindex/nofollow）——WP 主机是后端，不属于任何搜索索引，且有意
  无视 blog_public（该选项表达的是 Astro 前端的收录意图）。

## 文件

| 文件 | 职责 |
|---|---|
| `style.css` | 主题头 + 全部自有覆盖（解除 admin 600px 最小宽、壳布局、单篇阅读列收窄、分页/评论/正文排版增量） |
| `functions.php` | robots 双保险、admin 样式 enqueue、resource 主查询注入（pre_get_posts，AIYA_CORE_VERSION 守卫）、列表页标题 helper |
| `header.php` / `footer.php` | 文档壳：head（手动 title）、品牌行（站点图标+站名）、页脚 |
| `index.php` | 列表全能模板：home/归档/搜索/404/空态（一条目一卡片 + 分页；首页标题由品牌行承担） |
| `singular.php` | post/page/attachment/预览 通用详情（the_content + wp_link_pages + 自定义分类法 meta + 评论） |
| `comments.php` | 评论列表 + 分页（只读展示，不提供发表表单） |
