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
  内容出口只有 the_* 系列核心标签，插件停用后本主题照常工作。
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
| `style.css` | 主题头 + 全部自有覆盖（解除 admin 600px 最小宽、壳布局、分页/评论/正文排版增量） |
| `functions.php` | robots 双保险、admin 样式 enqueue、列表页标题 helper |
| `header.php` / `footer.php` | 文档壳：head（手动 title）、品牌行（站点图标+站名）、页脚 |
| `index.php` | 列表全能模板：home/归档/搜索/404/空态（一条目一卡片 + 分页；首页标题由品牌行承担） |
| `singular.php` | post/page/attachment/预览 通用详情（the_content + wp_link_pages + 评论） |
| `comments.php` | 评论列表 + 分页（只读展示，不提供发表表单） |
