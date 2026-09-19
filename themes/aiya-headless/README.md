# AIYA Headless — Shell Theme（项目配套占位主题壳）

本目录是 **AIYA CMS 的配套占位主题壳**，随 aiya-core 仓库版本化，作为
`wp-content/themes/aiya-headless/` 运行位的同步源。仓库内的这份拷贝与
运行位必须保持逐字节一致：改动可以在任一侧进行，收尾时同步回另一侧。

## 定位

- WordPress 是本站的数据后端（内容 / 媒体 / 用户 / 版本化 REST API），
  公开前端由 Astro（front-station）渲染。直连 WP 域名时由本主题兜底，
  保证命中任意路径都得到一份无害的合法文档。
- **刻意不引导任何框架、不依赖 aiya-core**：插件停用后本主题照常工作。
  外观功能全部走 WordPress 原生面——站点图标（设置 → 常规，同时是
  favicon 来源）嵌入卡片顶部，卡片正文由定制器「站点身份」节的
  Intro text 字段编辑（kses 白名单，留空回落内置占位文案）。
- 无头无用的功能面由 aiya-core 的 HeadlessModule 裁剪（菜单、站点
  编辑器、字体库等），本主题不重复声明 theme supports。
- 另有两条自持守卫：`robots_txt` 全站 Disallow 与 `wp_robots`
  noindex/nofollow——WP 主机是后端，不属于任何搜索索引，且有意
  无视 `blog_public`（该选项表达的是 Astro 前端的收录意图）。

## 文件

| 文件 | 职责 |
|---|---|
| `style.css` | 主题头（WP 识别用） |
| `functions.php` | admin bar 关闭、robots 双保险、定制器 intro 字段 |
| `index.php` | 唯一模板：站点图标 + 标题品牌行、可编辑正文、登录态后台入口 |
