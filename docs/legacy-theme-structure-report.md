你说得对：当前 `plugins/` 应理解为“主题内嵌功能模块集合/子仓库”，不是 WordPress 标准插件目录。它通过主题入口手工加载，因此仍属于主题运行时的一部分。

这次建议完全放弃“按现有目录搬迁”的思路，改为按职责重新归类。现有 API、`inc/core` 数据对象、全局函数命名和返回结构都只作为行为参考，不作为新架构约束。

## 一、当前整体结构推断

当前系统实际由五个平面组成：

```text
WordPress
  ↓
主题启动入口 functions.php
  ├─ 加载 plugins/functions.php
  │    ├─ framework-required
  │    ├─ basic-optimize
  │    ├─ image-manager
  │    ├─ internal-pic-bed
  │    ├─ classic-editor-modify
  │    ├─ opencc-convert
  │    ├─ multi-domain
  │    └─ sponsor-order-compat
  │
  ├─ 加载 inc/core/*
  ├─ 加载 inc/func-*
  ├─ 加载 inc/settings/*
  ├─ 加载 inc/widgets/*
  │
  └─ PHP templates → React islands / DOM enhancers
```

这五个平面分别是：

1. 主题运行时和加载器
2. 后台管理及设置框架
3. WordPress 基础设施和行为修改
4. 业务内容域
5. PHP/React 前台交付层

你准备全面重做前台，所以第 5 类不应该迁入新后端插件；它只需要被拆解，用于识别新 API 所需能力。

---

# 二、后台管理

这里的“后台管理”只指管理员/编辑人员使用的界面与操作入口，不包含其背后的业务规则和持久化逻辑。

## 1. 设置页面框架

当前位置：

- [framework-setup.php](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/plugins/framework-required/framework-setup.php)
- [framework-option-page.php](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/plugins/framework-required/inc/framework-option-page.php)
- [framework-build-fields.php](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/plugins/framework-required/inc/framework-build-fields.php)
- [fields](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/plugins/framework-required/inc/fields)

涉及功能：

- 后台菜单和设置页注册
- 设置分组、父子页面
- 字段 schema
- 字段 HTML 渲染
- 表单提交和 option 保存
- 文本、数字、开关、选择、颜色、上传、代码、TinyMCE、数组和分组字段
- 设置值读取和缓存
- 扩展设置页面 `extra-plugin`

当前问题：

- 表单定义、HTML、数据校验、持久化和运行时读取绑定在 `AYF` 上。
- 业务代码必须知道设置页 slug 和字段 ID。
- 后台 UI 实际成为了领域配置的读取接口。

重构归属：

- 后台 UI 保留为管理 adapter。
- 配置 schema、默认值、校验和读取进入独立配置模块。
- 领域代码不再调用 `AYF::get_opt()` 或 `aya_opt()`。

---

## 2. 文章和分类元数据管理

当前位置：

- [framework-metabox-post.php](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/plugins/framework-required/inc/framework-metabox-post.php)
- [framework-metabox-term.php](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/plugins/framework-required/inc/framework-metabox-term.php)
- [inc/settings](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/inc/settings)

涉及功能：

- Post metabox
- Term metabox
- 页面模板条件
- 字段显示与保存
- SEO 字段
- OpenList 配置
- 页面功能配置
- 通知配置
- 访问和赞助配置

需要注意：`inc/settings` 目前混有三种配置。

- 后台呈现配置：字段名称、布局、说明。
- 业务配置：赞助方案、通知规则、OpenList 连接信息。
- 旧主题外观配置：Logo、列表布局、轮播、广告位、颜色等。

重构方向：

- 业务配置迁入对应内容域。
- 站点级基础配置进入配置基础设施。
- 纯视觉配置不迁入后端插件，由 Astro 自己管理或重新建模。

---

## 3. 用户管理增强

当前位置主要在 [func-user.php](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/inc/func-user.php:402)。

涉及功能：

- 用户列表增加赞助相关列
- 用户排序
- 用户详情显示订单
- 手动编辑赞助状态
- 手动强制取消赞助状态
- 收藏内容展示
- 本地头像管理

重构时应拆成：

- 后台用户页面：管理 adapter
- 赞助资格计算：内容域
- 订单持久化：内容域 repository
- 头像上传和媒体关联：基础设施

---

## 4. 编辑器增强

当前位置：

- [classic-editor-modify](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/plugins/classic-editor-modify)
- [module-shortcode-manager.php](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/plugins/framework-required/plugin/module-shortcode-manager.php)
- [func-shotcodes.php](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/inc/func-shotcodes.php)

涉及功能：

- TinyMCE 工具栏调整
- TinyMCE 插件
- QuickTags
- 自动图片上传
- 编辑器内容格式化
- 作者和标签选择增强
- 短代码插入器
- 短代码字段构造

需要拆开：

- 编辑器按钮和弹窗属于后台管理。
- 短代码语义和解析属于内容域。
- 前台短代码 HTML 输出属于旧主题交付层，应淘汰或转换为结构化内容块。

---

## 5. 图片管理后台

当前位置：

- [post-cover-generator.php](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/plugins/image-manager/post-cover-generator.php)
- [internal-pic-bed](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/plugins/internal-pic-bed)

涉及功能：

- 文章封面生成 metabox
- AJAX 封面生成
- 图片上传后台页面
- 图片列表页面
- 图片处理设置
- 水印和字体文件选择
- 简码图床管理

拆分归属：

- metabox、上传页和图片列表：后台管理。
- 图片转换、水印、缩略图和存储：基础设施。
- 文章与封面的关系：内容域。

---

## 6. 独立业务管理页

涉及：

- 激活码管理：[func-payment.php](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/inc/func-payment.php:536)
- Flow Hub 管理：[patch-flow-hub-post](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/plugins/patch-flow-hub-post)
- 图片床管理
- Dashboard 状态面板
- 查询调试页面
- SMTP/SEO/安全配置页面

这些都应被视为对应模块的后台 adapter，而不是业务模块本身。

---

# 三、基础设施

基础设施负责提供技术能力，不决定业务语义。

## 1. 运行时启动和模块加载

当前位置：

- [functions.php](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/functions.php:52)
- [plugins/functions.php](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/plugins/functions.php:29)
- [plugin-functions.php](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/plugins/plugin-functions.php)

当前职责：

- 路径常量
- 文件加载
- Composer 加载
- 模块加载顺序
- 环境检查
- 缺失依赖处理
- 全局类 `AYF`、`AYP`
- 主题内部功能启停

这是新插件首先需要重写的部分。现有加载器不应直接继承，因为它将模块定位、生命周期和主题路径混在了一起。

---

## 2. Hook 和注册机制

当前位置：

- `AYA_Framework_Setup`
- `AYF::module()`
- `AYP::action()`
- `handle-action-hook.php`
- `register-theme-*`

涉及：

- action/filter 注册
- post type 注册
- taxonomy 注册
- menu/sidebar/widget 注册
- theme support
- 插件状态检查
- 环境检查

未来分类：

- 通用 hook 注册器通常没有保留价值，可直接使用 WordPress hook。
- Post type/taxonomy 的定义属于内容域。
- 注册到 WordPress 的动作属于 WordPress adapter。
- Menu、sidebar、widget、theme support 属于旧主题，原则上不迁移。

---

## 3. HTTP 与 REST 传输

当前位置：

- [handle-rest-api.php](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/plugins/framework-required/plugin/handle-rest-api.php)
- [handle-ajax-hook.php](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/plugins/framework-required/plugin/handle-ajax-hook.php)
- [func-api-router.php](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/inc/func-api-router.php)

当前功能：

- 路由注册
- REST 响应包装
- 错误包装
- nonce 检查
- JSON 请求读取
- admin-ajax 注册
- 登录 cookie 交互

这里应明确：

- REST/AJAX 是传输 adapter，不是内容域。
- `func-api-router.php` 中的登录、收藏、点赞等业务不能继续留在路由回调里。
- 新 API 可以使用全新 namespace 和全新响应结构。
- 不需要让新的 API wrapper 兼容 `AYA_WP_REST_API`。

---

## 4. 持久化基础设施

当前存在四种存储方式：

### WordPress options

- `aya_opt_*`
- `extra-plugin`
- WordPress 原生 option

### Post meta

- `like_count`
- `view_count`
- `_aya_thumb`
- `gallery_images`
- `aya_box_oplist_client`
- SEO 和文章设置 metabox

### User meta

- `favorite_posts`
- `sponsor_expiration`
- `aya_trigger_count_sponsor`
- `aya_force_cancel_sponsor`
- `basic_user_avatar`
- `locale`

### 自定义数据表

- Issue 表
- Issue comment 表
- Sponsor order 表
- 激活码表
- Flow Hub 表

重构时不应让新领域直接使用 `$wpdb`、`get_post_meta()` 或 `get_user_meta()`。这些应形成按领域划分的 persistence adapter。

不必立刻迁移存量数据，但新领域模型不能由当前表结构反向决定。

---

## 5. 图片与媒体处理

当前位置：

- [image-manager](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/plugins/image-manager)
- [func-media.php](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/inc/func-media.php)
- [BFI_Thumb.php](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/inc/lib/BFI_Thumb.php)

涉及：

- Imagine 驱动选择
- 图片格式识别
- JPEG/WebP/PNG 保存参数
- 最大宽度缩放
- EXIF 方向
- 水印
- 缩略图
- 封面合成
- 本地路径与 URL 转换
- 上传钩子
- 衍生文件命名和缓存

这是一个典型的基础设施模块。

但以下内容不属于它：

- “文章应该使用哪张封面”：内容域。
- “后台如何选择和生成封面”：后台管理。
- “API 返回什么图片结构”：API 表示层。

---

## 6. 外部系统访问

当前位置：

- `Http_Request`
- `Afdian_API`
- `OpenList_API`
- Epay
- Bing 图片
- 百度翻译
- 一言
- SMTP

基础设施职责：

- HTTP 客户端
- 重试和超时
- 认证签名
- 外部响应解析
- 外部错误归一化
- 日志
- 缓存

而“赞助订单验证成功后赋予什么资格”属于内容域，不属于爱发电 adapter。

---

## 7. 缓存和性能

涉及：

- Widget cache
- Post meta preload
- 菜单缓存
- OpenList token transient
- 登录失败 transient
- Vite manifest cache
- 查询优化
- 静态资源 CDN
- 脚本版本参数处理

未来区分：

- Widget、Vite、主题脚本：随旧主题淘汰。
- 领域缓存：跟随对应领域。
- HTTP/token/cache store：基础设施。
- 查询优化：仅保留经新 API 性能测试证明需要的部分。

---

## 8. 安全与访问保护

`basic-optimize` 中涉及：

- XML-RPC 控制
- REST 开关
- 登录保护
- UA 防火墙
- 上传限制
- WordPress 信息隐藏
- 后台权限修改
- 评论过滤
- 自动更新策略

这是基础设施中的“WordPress 运行策略”。

需要特别注意：当前的“关闭 REST”能力与无头后端目标直接冲突。未来应改成端点白名单、权限和速率控制，而不是全局关闭 REST。

---

## 9. URL、Rewrite 和多域名

当前位置：

- [func-wp-emends.php](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/inc/func-wp-emends.php:42)
- [multi-domain](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/plugins/multi-domain)
- `wp-no-category-url.php`

涉及：

- 自定义作者 URL
- 独立页面路由
- REST prefix 从 `wp-json` 改为 `api`
- canonical 调整
- category base 移除
- 多域名输出替换
- PHP 模板重定向

这里大部分是旧前台路由基础设施。Astro 上线后应重新决定：

- 公开 URL 归 Astro。
- WordPress 只拥有后台和 API URL。
- 不再通过输出缓冲替换域名。
- 不建议继续修改 WordPress REST 原生 prefix；由反向代理提供外部路径即可。

---

## 10. 国际化与内容转换

涉及：

- WordPress textdomain
- 浏览器语言检测
- 用户 locale
- 前端翻译表
- OpenCC 繁简转换

其中：

- 翻译加载、locale 解析：基础设施。
- OpenCC 字符转换引擎：基础设施。
- 哪些内容允许转换、保存原文还是动态转换：内容策略。

---

## 11. WordPress 行为优化

`basic-optimize` 大量功能属于此类：

- 自动 slug
- 自动排版
- revision 控制
- emoji/oEmbed/feed 禁用
- 图片尺寸关闭
- Google Font/Gravatar CDN
- 评论字段修改
- Head 标签清理
- SEO 输出
- Dashboard 定制

这里不应整体迁移。建议逐项做保留判断：

- 对后台编辑体验仍有价值：保留。
- 只服务旧 PHP 前台：淘汰。
- 会影响 Headless API：重新评估。
- 只是通用 WordPress 偏好：可做成独立可选模块。

---

# 四、内容域

内容域是未来新 API 真正应该围绕的核心。它负责业务状态和规则，不负责 REST、HTML、WordPress hooks 或具体存储。

## 1. Content 内容发布域

当前涉及：

- WordPress post/page
- 分类、标签
- 作者
- 文章状态
- 摘要
- 正文
- 置顶
- 密码保护
- 发布时间
- 相关文章
- 上下篇
- 自定义文章提示 taxonomy
- 文章 SEO 数据
- 文章封面关系

相关位置：

- [WP_Post.php](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/inc/core/WP_Post.php)
- [WP_Query.php](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/inc/core/WP_Query.php)
- [WP_Term.php](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/inc/core/WP_Term.php)
- [func-wp-emends.php](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/inc/func-wp-emends.php)

现有 `inc/core` 更接近旧模板 View Model，不是可靠的领域模型。新结构不必继承这些 getter 或字段组织方式。

---

## 2. Identity 身份域

当前涉及：

- 登录
- 登出
- 注册
- 密码找回
- 密码重置
- 修改密码
- 用户资料
- 用户 locale
- 当前用户菜单数据
- 用户权限判断
- 本地头像

主要集中在：

- [func-api-router.php](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/inc/func-api-router.php)
- [func-user.php](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/inc/func-user.php)
- `wp-local-avatars.php`

当前问题是身份用例直接写在 REST callback 内。未来应先定义身份用例，再由 REST adapter 暴露。

---

## 3. Engagement 互动域

当前涉及：

- 文章点赞
- 浏览量
- 收藏
- 评论
- 评论策略
- 用户收藏列表
- 热门文章/热门评论查询

持久数据：

- `like_count`
- `view_count`
- `favorite_posts`
- WordPress comment

风险点：

- 点赞接口允许匿名调用，但当前模型只是递增计数，没有防重、身份或幂等语义。
- 浏览量由页面事件和 WP hook 触发，切到 Astro 后触发方式必然变化。
- 收藏使用 user meta 数组，数据规模增长后不利于查询。

所以新 API 前，应先重新定义三者的业务语义，而不是复制现有数据接口。

---

## 4. Issue 议题/工单域

当前位置：[func-issue.php](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/inc/func-issue.php)

已经包含相对完整的领域能力：

- Issue 类型
- Issue 状态
- 评论状态
- 创建、读取、列表、更新、删除
- Issue 与文章关联
- Issue comment
- 权限判断
- 状态规范化
- 排序和分页
- 用户及文章摘要
- 独立数据表

但是单文件同时包含：

- 表结构安装
- 输入清理
- 权限规则
- 查询
- DTO 格式化
- REST 路由

这应作为首批独立内容域之一重新设计。

`patch-flow-hub-post` 看起来是另一个相似/实验性内容流模块，目前未默认启用。需要决定它与 Issue 是：

- 同一领域的不同工作流；
- 两个独立领域；
- 或已废弃的实验实现。

---

## 5. Tweet 短内容域

当前位置：[func-tweet-post.php](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/inc/func-tweet-post.php)

涉及：

- `tweet` 自定义文章类型
- 标签
- 图集
- 摘要截断
- 创建、更新、删除
- 作者权限
- 发布/审核策略
- 归档筛选

它不只是一个 post type，而是一种具有独立发布规则和交互方式的内容模型，建议保留为单独内容域。

---

## 6. Sponsorship 赞助与权益域

当前位置：

- [func-user.php](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/inc/func-user.php:97)
- [func-payment.php](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/inc/func-payment.php)
- [sponsor-order-compat](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/plugins/sponsor-order-compat)

涉及：

- 赞助订单
- 激活码
- 外部订单验证
- 赞助有效期
- 权益触发次数
- 强制取消
- 套餐
- 爱发电回调
- 支付成功/同步
- 多种订单来源兼容

建议拆分为：

```text
Sponsorship domain
  ├─ plans
  ├─ orders
  ├─ entitlements
  ├─ activation
  └─ eligibility

External adapters
  ├─ Afdian
  ├─ Epay
  ├─ activation code
  └─ future providers
```

不要再使用动态函数名 `aya_verify_code_by_*` 作为核心扩展机制。

---

## 7. Notification 通知域

当前位置：[func-notify.php](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/inc/func-notify.php)

涉及：

- 通知收集
- 登录/匿名范围过滤
- 一次性通知
- 评论通知
- 注册通知
- WooCommerce 订单通知
- 登录失败通知
- Cookie consent 信息

这里混合了：

- 领域事件
- 通知策略
- 通知表示
- 前端弹窗数据

未来可以保留“事件 → 通知”的领域逻辑，但前端展示数据应由新 API 单独生成。

---

## 8. OpenList 集成域

当前位置：[func-openlist.php](D:/PHP_AIYA_CMS/wp-content/themes/aiya-cms-wordpress-theme/inc/func-openlist.php)

涉及：

- 服务端连接配置
- token 获取和缓存
- 目录查询
- 文件直链
- 密码
- 文章级 OpenList 配置
- 短代码
- metabox
- REST 代理
- 前台文件浏览器数据

应拆为：

- OpenList adapter：认证、请求、错误和目录协议。
- Content attachment domain：文章与外部文件目录的关联。
- 后台管理：文章编辑配置。
- 新 API：面向 Astro 的文件列表/下载出口。

---

## 9. Embedded Content 嵌入内容域

当前短代码包括：

- 隐藏内容
- 登录后可见
- 赞助后可见
- Email
- 列表/多栏
- 折叠区
- Alert
- Clipboard
- Bilibili
- 爱发电
- GitHub repository
- Plyr playlist
- OpenList

这部分是未来内容重构中的关键。

不建议让 Astro 直接执行 WordPress shortcode 并接收任意 HTML。更合理的是把它们转成结构化内容节点：

```text
article body
  ├─ paragraph
  ├─ alert
  ├─ media-player
  ├─ external-embed
  ├─ protected-section
  ├─ sponsor-section
  └─ file-browser
```

短期可由 WordPress 解析旧 shortcode，再输出安全的结构化节点；长期在编辑端直接保存块结构。

---

## 10. Site Composition 站点编排域

当前涉及：

- 菜单
- 首页分类分区
- 轮播
- 广告位
- 全局顶部/底部内容
- 面包屑
- 搜索
- 页面入口开关

这部分目前被放在主题设置和 PHP 模板里，但无头化后仍有一部分属于后端内容管理。

需要区分：

- “首页有哪些内容区块”：可作为 CMS 编排内容。
- “区块以几列、什么颜色展示”：属于 Astro。
- “菜单树和链接关系”：CMS 内容。
- “面包屑最终 HTML”：Astro。
- “面包屑所需层级关系”：内容 API。

---

# 五、不应进入新后端插件的旧前台能力

以下内容建议只作为需求参考，不做代码迁移：

- `templates/`
- `src/entrypoints`
- `src/runtime/islands.tsx`
- `icon-slot`
- `badge-slot`
- `post-grid-layout`
- Vite manifest 加载
- `aya_react_island()`
- `aya_template_load()`
- Widgets 的前台 HTML
- PHP 页面路由
- PHP header/footer
- 旧主题资源注入
- 基于 `home_url()` 的前台跳转
- 用输出缓冲替换域名

React 业务交互可以复用设计和行为，但不必保留当前 props 或 API 数据结构。

# 六、建议的新后端高层地图

在还没有设计具体 PHP interface 前，可以先采用这个职责地图：

```text
AIYA Backend Plugin
│
├─ Admin
│  ├─ Settings
│  ├─ Content editing
│  ├─ Media management
│  ├─ User/sponsor management
│  └─ Diagnostics
│
├─ Infrastructure
│  ├─ Runtime/bootstrap
│  ├─ WordPress adapters
│  ├─ Persistence adapters
│  ├─ HTTP/API transport
│  ├─ Authentication transport
│  ├─ Media processing/storage
│  ├─ External integrations
│  ├─ Cache/jobs/logging
│  ├─ Security
│  └─ Localization
│
└─ Domains
   ├─ Content
   ├─ Identity
   ├─ Engagement
   ├─ Issue
   ├─ Tweet
   ├─ Sponsorship
   ├─ Notification
   ├─ External files/OpenList
   ├─ Embedded content
   └─ Site composition
```

最值得先切开的 seam 不是 API，而是：

```text
后台 adapter ──┐
REST adapter ──┼──> 内容域 ──> persistence/external adapters
CLI/job adapter ┘
```

也就是说，先让领域用例不依赖 REST、后台 HTML、全局 WP 请求和具体 meta/table；之后重新设计一套更全面的 API 出口就会自然很多。
