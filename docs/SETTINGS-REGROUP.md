# SETTINGS-REGROUP：设置键全量清单与重归属工作底表

> 状态：**工作底表，待站长批注**（2026-10-04 容器实测抽取，182 键）。
> 用法：直接在「拟改中文」「拟归属」两列填定稿——「拟改中文」= 最终展示文本
>（回填时同步改英文源串 + zh_CN 翻译，走 aiya-i18n-zh-cn 流程）；「拟归属」=
> 该键应去的页面 slug（留空 = 不动）。回填逐页操作，每页一回合一验。

## 一、页面总览（10 页 / 10 个 option 组）

| 页面 | 菜单归属 | option 组 | 键数 | 类型分布 |
|---|---|---|---|---|
| devtools-sample（Sample（沙盒）） | Dev Tools | `aiya_core_sample` | 30 | array×1 · checkbox×2 · code×1 · color×1 · email×1 · heading×5 · hidden×1 · key_value×1 · media×1 · multicheck×1 · note×2 · number×2 · password×1 · radio×1 · repeater×1 · select×1 · switch×1 · text×2 · textarea×1 · tinymce×1 · url×2 |
| frontend（前台设置） | 顶级 | `aiya_core_frontend` | 20 | color×1 · heading×4 · media×4 · note×1 · radio×1 · repeater×1 · select×2 · switch×2 · text×3 · url×1 |
| content（内容管理） | 前台设置 | `aiya_core_content` | 13 | heading×3 · multicheck×3 · note×2 · number×2 · text×2 · textarea×1 |
| optimization（优化） | 前台设置 | `aiya_core_optimization` | 25 | heading×4 · media×1 · multicheck×1 · note×1 · radio×3 · switch×14 · text×1 |
| security（安全加固） | 前台设置 | `aiya_core_security` | 11 | array×1 · heading×4 · note×1 · select×1 · switch×4 |
| blocks（页面区块） | 前台设置 | `aiya_core_blocks` | 25 | media×2 · multicheck×1 · note×1 · number×1 · radio×1 · repeater×5 · select×2 · text×7 · url×5 |
| membership（会员设置） | 顶级 | `aiya_core_membership` | 14 | heading×2 · number×6 · repeater×1 · switch×2 · text×2 · textarea×1 |
| membership-payments（支付） | 会员设置 | `aiya_core_membership_payments` | 17 | heading×3 · multicheck×1 · note×1 · number×1 · password×2 · repeater×1 · select×2 · switch×2 · text×3 · url×1 |
| image（图像处理器） | 前台设置 | `aiya_core_image` | 13 | heading×2 · media×1 · number×4 · radio×2 · select×1 · switch×1 · text×2 |
| fileserve（文件下载） | 前台设置 | `aiya_core_fileserve` | 14 | heading×4 · number×2 · password×3 · radio×1 · switch×1 · text×1 · url×2 |

注：注册表之外另有裸 option `aiya_core_operations`（运营月报单价，由自注册页写入）
与运行时键 `aiya_core_schema_version`（迁移盖章，非设置）。

## 二、键清单（按页面分组，repeater 子键以 `[].` 链标记）

### devtools-sample（Sample（沙盒））— `aiya_core_sample` ⚠️ 沙盒页（WP_DEBUG 限定，可不精修）

| 键 | 类型 | 现行英文源串 | 现行中文 | 拟改中文 | 拟归属 |
|---|---|---|---|---|---|
| `sandbox_note` | note | 开发者沙盒：设置框架的所有持久化字段类型都在本页展示。 | 开发者沙盒：设置框架的所有持久化字段类型都在本页展示。 | | |
| `text_heading` | heading | 文本输入 | 文本输入 | | |
| `site_title` | text | 站点标题 | 站点标题 | | |
| `site_description` | textarea | 站点描述 | 站点描述 | | |
| `cache_ttl` | number | 缓存时长 | 缓存时长 | | |
| `contact_email` | email | 联系邮箱 | 联系邮箱 | | |
| `public_url` | url | 公开 URL | 公开 URL | | |
| `sample_api_key` | password | 示例 API 密钥 | 示例 API 密钥 | | |
| `tracking_id` | hidden | tracking_id | tracking_id | | |
| `choices_heading` | heading | 选项 | 选项 | | |
| `feature_enabled` | checkbox | 功能开关 | 功能开关 | | |
| `debug_mode` | switch | 调试模式 | 调试模式 | | |
| `content_mode` | select | 内容模式 | 内容模式 | | |
| `default_locale` | radio | 默认语言 | 默认语言 | | |
| `sample_features` | multicheck | 启用模块 | 启用模块 | | |
| `values_heading` | heading | 媒体与值列表 | 媒体与值列表 | | |
| `color_warning` | note | 仅存储值：密码字段不回显，媒体字段保存附件 ID。 | 仅存储值：密码字段不回显，媒体字段保存附件 ID。 | | |
| `accent_color` | color | 强调色 | 强调色 | | |
| `logo` | media | Logo | Logo | | |
| `allowed_hosts` | array | 允许的主机 | 允许的主机 | | |
| `http_headers` | key_value | 自定义请求头 | 自定义请求头 | | |
| `editor_heading` | heading | 编辑器内容 | 编辑器内容 | | |
| `sample_json` | code | JSON 配置 | JSON 配置 | | |
| `editor_content` | tinymce | 经典编辑器内容 | 经典编辑器内容 | | |
| `repeater_heading` | heading | 动态行 | 动态行 | | |
| `navigation_links` | repeater | 导航链接 | 导航链接 | | |
| `navigation_links[].label` | text | 标签 | 标签 | | |
| `navigation_links[].url` | url | URL | 网址 | | |
| `navigation_links[].priority` | number | 优先级 | 优先级 | | |
| `navigation_links[].enabled` | checkbox | 启用 | 启用 | | |

### frontend（前台设置）— `aiya_core_frontend`

| 键 | 类型 | 现行英文源串 | 现行中文 | 拟改中文 | 拟归属 |
|---|---|---|---|---|---|
| `note_source` | note | 这些字段通过 GET /aiya/core/v1/site 提供给前台外壳；页脚字符串留空即不在页脚显示。 | 这些字段通过 GET /aiya/core/v1/site 提供给前台外壳；页脚字符串留空即不在页脚显示。 | | |
| `heading_basic` | heading | 基础设置 | 基础设置 | | |
| `frontend_domain` | text | 前台域名 | 前台域名 | | |
| `default_language` | select | 前台语言 | 前台语言 | | |
| `heading_appearance` | heading | 外观默认值 | 外观默认值 | | |
| `color_primary` | color | 主题色 | 主题色 | | |
| `default_color_mode` | select | 默认配色模式 | 默认配色模式 | | |
| `default_thumb` | media | 站级兜底封面 | 站级兜底封面 | | |
| `default_hero` | media | 文章头图默认图 | 文章头图默认图 | | |
| `empty_image` | media | 空状态图片 | 空状态图片 | | |
| `heading_banner` | heading | 头部横幅 | 头部横幅 | | |
| `banner_enabled` | switch | 显示头部横幅 | 显示头部横幅 | | |
| `banner_image` | media | 横幅图片 | 横幅图片 | | |
| `heading_compliance` | heading | 合规页脚 | 合规页脚 | | |
| `hitokoto` | switch | 页脚一言 | 页脚一言 | | |
| `beian_links` | repeater | 合规备案链接 | 合规备案链接 | | |
| `beian_links[].label` | text | 文字 | 文字 | | |
| `beian_links[].url` | url | 链接 | 链接 | | |
| `beian_links[].icon` | radio | 图标 | 图标 | | |
| `beian_links[].icon_url` | text | 自定义图标地址 | 自定义图标地址 | | |

### content（内容管理）— `aiya_core_content`

| 键 | 类型 | 现行英文源串 | 现行中文 | 拟改中文 | 拟归属 |
|---|---|---|---|---|---|
| `note_scope` | note | 内容运营设置：保留期限、经 GET /aiya/core/v1/site 供前台使用的站点级 SEO 头部信息，以及读路径可按要求排除的 NSFW 词法。 | 内容运营设置：保留期限、经 GET /aiya/core/v1/site 供前台使用的站点级 SEO 头部信息，以及读路径可按要求排除的 NSFW 词法。 | | |
| `heading_retention` | heading | 保留策略 | 保留策略 | | |
| `notification_retention` | number | 通知保留期（天） | 通知保留期（天） | | |
| `credit_retention` | number | 账本保留期（天） | 账本保留期（天） | | |
| `heading_seo` | heading | SEO 与统计 | SEO 与统计 | | |
| `seo_keywords` | text | SEO 关键词 | SEO 关键词 | | |
| `seo_description` | textarea | SEO 描述 | SEO 描述 | | |
| `ga_measurement_id` | text | Google Analytics ID | Google Analytics ID | | |
| `heading_nsfw` | heading | NSFW 过滤 | NSFW 过滤 | | |
| `note_nsfw` | note | 勾选的术语即视为 NSFW：前台可要求把它们从列表中排除（命中任意一个分类即排除该文章）。默认不生效——详情页与直接访问的分类页仍可达，开启「始终显示 NSFW 内容」的登录用户不受过滤影响。 | 勾选的术语即视为 NSFW：前台可要求把它们从列表中排除（命中任意一个分类即排除该文章）。默认不生效——详情页与直接访问的分类页仍可达，开启「始终显示 NSFW 内容」的登录用户不受过滤影响。 | | |
| `nsfw_post` | multicheck | NSFW 术语——文章 | NSFW 术语——文章 | | |
| `nsfw_page` | multicheck | NSFW 术语——页面 | NSFW 术语——页面 | | |
| `nsfw_resource` | multicheck | NSFW 术语——资源 | NSFW 术语——资源 | | |

### optimization（优化）— `aiya_core_optimization`

| 键 | 类型 | 现行英文源串 | 现行中文 | 拟改中文 | 拟归属 |
|---|---|---|---|---|---|
| `note_scope` | note | 裁剪在运行时强制执行；内容相关的 REST 读取仍对 Astro 前端可用。 | 裁剪在运行时强制执行；内容相关的 REST 读取仍对 Astro 前端可用。 | | |
| `heading_features` | heading | 已禁用功能 | 已禁用功能 | | |
| `disable_block_editor` | switch | 区块编辑器（Gutenberg） | 区块编辑器（Gutenberg） | | |
| `disable_block_widgets` | switch | 区块小工具 | 区块小工具 | | |
| `disable_appearance` | switch | 站点编辑器与菜单 | 站点编辑器与菜单 | | |
| `disable_fonts_global_styles` | switch | 字体库与全局样式 | 字体库与全局样式 | | |
| `disable_block_patterns` | switch | 区块样板与区块目录 | 区块样板与区块目录 | | |
| `disable_revisions` | switch | 文章修订版本 | 文章修订版本 | | |
| `disable_pings` | switch | Pingback 与 Trackback | Pingback 与 Trackback | | |
| `disable_emoji` | switch | Emoji | Emoji | | |
| `disable_oembed` | switch | oEmbed 探测 | oEmbed 探测 | | |
| `disable_xmlrpc` | switch | XML-RPC | XML-RPC | | |
| `strip_frontend_head` | switch | 前台 head 清理 | 前台 head 清理 | | |
| `lock_wp_v2` | switch | WP 原生 REST API（/wp/v2） | WP 原生 REST API（/wp/v2） | | |
| `disable_sitemap` | switch | XML 站点地图 | XML 站点地图 | | |
| `disable_feeds` | switch | Feed 订阅 | Feed 订阅 | | |
| `heading_avatar` | heading | 头像设置 | 头像设置 | | |
| `avatar_cdn_mirror` | radio | Gravatar 镜像 | Gravatar 镜像 | | |
| `avatar_default` | media | 默认头像 | 默认头像 | | |
| `heading_slug` | heading | 别名生成 | 别名生成 | | |
| `slug_post_mode` | radio | 文章别名生成 | 文章别名生成 | | |
| `slug_id_prefix` | text | ID 别名前缀 | ID 别名前缀 | | |
| `slug_term_pinyin` | radio | 分类法别名拼音 | 分类法别名拼音 | | |
| `heading_typography` | heading | 中文排版 | 中文排版 | | |
| `typography_methods` | multicheck | 启用的排版纠正项 | 启用的排版纠正项 | | |

### security（安全加固）— `aiya_core_security`

| 键 | 类型 | 现行英文源串 | 现行中文 | 拟改中文 | 拟归属 |
|---|---|---|---|---|---|
| `heading_rest` | heading | REST 路由控制 | REST 路由控制 | | |
| `rest_allowed_origins` | array | REST 跨域来源 | REST 跨域来源 | | |
| `heading_login` | heading | 登录限制 | 登录限制 | | |
| `force_email_login` | switch | 邮箱登录 | 邮箱登录 | | |
| `login_param_gate_enable` | switch | 登录页倒计时门禁 | 登录页倒计时门禁 | | |
| `heading_admin` | heading | 后台防护 | 后台防护 | | |
| `admin_backend_min_role` | select | 后台最低角色 | 后台最低角色 | | |
| `request_uri_guard` | switch | 请求 URI 守卫 | 请求 URI 守卫 | | |
| `heading_uninstall` | heading | 卸载 | 卸载 | | |
| `note_uninstall` | note | 删除插件默认保留其全部数据，重装后可原样恢复。从插件列表删除时总会先询问；下方开关是 WP-CLI 与脚本化卸载的既定答案，清除操作不可撤销。 | 删除插件默认保留其全部数据，重装后可原样恢复。从插件列表删除时总会先询问；下方开关是 WP-CLI 与脚本化卸载的既定答案，清除操作不可撤销。 | | |
| `uninstall_purge` | switch | 脚本卸载时清除数据 | 脚本卸载时清除数据 | | |

### blocks（页面区块）— `aiya_core_blocks`

| 键 | 类型 | 现行英文源串 | 现行中文 | 拟改中文 | 拟归属 |
|---|---|---|---|---|---|
| `home_sections` | repeater | 首页区块 | 首页区块 | | |
| `home_sections[].title` | text | 标题 | 标题 | | |
| `home_sections[].icon` | text | 图标 | 图标 | | |
| `home_sections[].type` | radio | 内容类型 | 内容类型 | | |
| `home_sections[].categories` | multicheck | 分类 | 分类 | | |
| `home_sections[].count` | number | 帖子数量 | 帖子数量 | | |
| `home_sections[].more_url` | url | 「更多」链接 | 「更多」链接 | | |
| `note_source` | note | 这些列表替代 WordPress 菜单系统，并填充壳层的动态槽位：前台经 GET /aiya/core/v1/site 的 `blocks` 组按列表顺序读取。 | 这些列表替代 WordPress 菜单系统，并填充壳层的动态槽位：前台经 GET /aiya/core/v1/site 的 `blocks` 组按列表顺序读取。 | | |
| `primary_items` | repeater | 主菜单 | 主菜单 | | |
| `primary_items[].label` | text | 标签 | 标签 | | |
| `primary_items[].url` | url | URL | 网址 | | |
| `primary_items[].icon` | text | 图标 | 图标 | | |
| `primary_items[].target` | select | 打开方式 | 打开方式 | | |
| `secondary_items` | repeater | 次菜单 | 次菜单 | | |
| `secondary_items[].label` | text | 标签 | 标签 | | |
| `secondary_items[].url` | url | URL | 网址 | | |
| `secondary_items[].target` | select | 打开方式 | 打开方式 | | |
| `ads_top` | repeater | 页面顶部广告 | 页面顶部广告 | | |
| `ads_top[].url` | url | 链接 | 链接 | | |
| `ads_top[].label` | text | 链接文本 | 链接文本 | | |
| `ads_top[].image` | media | 广告图 | 广告图 | | |
| `ads_bottom` | repeater | 页面底部广告 | 页面底部广告 | | |
| `ads_bottom[].url` | url | 链接 | 链接 | | |
| `ads_bottom[].label` | text | 链接文本 | 链接文本 | | |
| `ads_bottom[].image` | media | 广告图 | 广告图 | | |

### membership（会员设置）— `aiya_core_membership`

| 键 | 类型 | 现行英文源串 | 现行中文 | 拟改中文 | 拟归属 |
|---|---|---|---|---|---|
| `heading_tiers` | heading | 档位 | 档位 | | |
| `tiers` | repeater | 档位列表 | 档位列表 | | |
| `tiers[].enabled` | switch | 启用 | 启用 | | |
| `tiers[].key` | text | 键名 | 键名 | | |
| `tiers[].name` | text | 名称 | 名称 | | |
| `tiers[].description` | textarea | 描述 | 描述 | | |
| `tiers[].price` | number | 单价（每周期） | 单价（每周期） | | |
| `tiers[].cycle_days` | number | 周期长度（天） | 周期长度（天） | | |
| `tiers[].credits_per_cycle` | number | 每周期积分 | 每周期积分 | | |
| `tiers[].cycles` | number | 每次购买的周期数 | 每次购买的周期数 | | |
| `heading_checkin` | heading | 每日签到 | 每日签到 | | |
| `checkin_enable` | switch | 签到 | 签到 | | |
| `checkin_credits` | number | 签到发放额 | 签到发放额 | | |
| `credit_validity_days` | number | 积分有效期（天） | 积分有效期（天） | | |

### membership-payments（支付）— `aiya_core_membership_payments`

| 键 | 类型 | 现行英文源串 | 现行中文 | 拟改中文 | 拟归属 |
|---|---|---|---|---|---|
| `heading_epay` | heading | 易支付网关（收银台集成） | 易支付网关（收银台集成） | | |
| `epay_enable` | switch | 易支付集成 | 易支付集成 | | |
| `epay_pid` | text | 商户 ID（pid） | 商户 ID（pid） | | |
| `epay_key` | password | 商户密钥 | 商户密钥 | | |
| `epay_gateway` | url | 网关提交地址 | 网关提交地址 | | |
| `epay_methods` | multicheck | 支付渠道 | 支付渠道 | | |
| `heading_afdian` | heading | 爱发电（平台推送） | 爱发电（平台推送） | | |
| `afdian_enable` | switch | 爱发电集成 | 爱发电集成 | | |
| `afdian_bindings` | repeater | 爱发电方案绑定 | 爱发电方案绑定 | | |
| `afdian_bindings[].plan_id` | text | 爱发电方案 ID | 爱发电方案 ID | | |
| `afdian_bindings[].tier_key` | select | 会员档位 | 会员档位 | | |
| `afdian_fallback_tier` | select | 兜底档位 | 兜底档位 | | |
| `afdian_user_id` | text | 爱发电用户 ID | 爱发电用户 ID | | |
| `afdian_token` | password | 爱发电 API Token | 爱发电 API Token | | |
| `afdian_webhook_note` | note | 爱发电 Webhook 地址 | 爱发电 Webhook 地址 | | |
| `heading_order_log` | heading | 订单日志 | 订单日志 | | |
| `unpaid_order_retention` | number | 未支付订单保留期（天） | 未支付订单保留期（天） | | |

### image（图像处理器）— `aiya_core_image`

| 键 | 类型 | 现行英文源串 | 现行中文 | 拟改中文 | 拟归属 |
|---|---|---|---|---|---|
| `heading_image` | heading | 图像处理 | 图像处理 | | |
| `image_take_over_uploads` | switch | 媒体库接管 | 媒体库接管 | | |
| `image_save_format` | radio | 保存格式 | 保存格式 | | |
| `image_max_width` | number | 最大图片宽度 | 最大图片宽度 | | |
| `image_quality` | number | 图像质量 | 图像质量 | | |
| `image_font_file` | text | 字体文件 | 字体文件 | | |
| `heading_watermark` | heading | 水印 | 水印 | | |
| `image_watermark_mode` | radio | 水印模式 | 水印模式 | | |
| `image_watermark_position` | select | 水印位置 | 水印位置 | | |
| `image_watermark_image` | media | 水印图片 | 水印图片 | | |
| `image_watermark_text` | text | 水印文字 | 水印文字 | | |
| `image_watermark_font_size` | number | 水印字号 | 水印字号 | | |
| `image_watermark_opacity` | number | 水印不透明度（文字） | 水印不透明度（文字） | | |

### fileserve（文件下载）— `aiya_core_fileserve`

| 键 | 类型 | 现行英文源串 | 现行中文 | 拟改中文 | 拟归属 |
|---|---|---|---|---|---|
| `heading_integrations` | heading | 服务集成 | 服务集成 | | |
| `service_key` | password | 服务密钥 | 服务密钥 | | |
| `fileserve_heading_common` | heading | 文件列表 | 文件列表 | | |
| `fileserve_cache_minutes` | number | 列表缓存（分钟） | 列表缓存（分钟） | | |
| `fileserve_icons` | switch | 文件类型图标 | 文件类型图标 | | |
| `fileserve_heading_oplist` | heading | OpenList 服务 | OpenList 服务 | | |
| `fileserve_oplist_server_url` | url | 服务器地址 | 服务器地址 | | |
| `fileserve_oplist_public_url` | url | 外链地址 | 外链地址 | | |
| `fileserve_oplist_server_user` | text | API 用户名 | API 用户名 | | |
| `fileserve_oplist_server_password` | password | API 密码 | API 密码 | | |
| `fileserve_oplist_token_hours` | number | 令牌缓存（小时） | 令牌缓存（小时） | | |
| `fileserve_oplist_link_mode` | radio | 下载链接模式 | 下载链接模式 | | |
| `fileserve_heading_gofile` | heading | GoFile | GoFile | | |
| `fileserve_gofile_token` | password | 账号令牌 | 账号令牌 | | |
