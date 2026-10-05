# PLAN：后台 UI 工具件（Admin/Ui + 行为层统一 + admin.css 整理）

> 状态：**已立项实施中**（2026-10-04）。批 A（地基 + 样例页）已完成并过全部门禁；
> 原 9.1 期含的兑换码/通知两页页面试点已按站长指示**从工作区撤回**——正式接线
> 统一推迟到组件集齐（listNav/控制零件等）后按批一次到位。第二轮调研拍板扩展：
> 页内 tab 导航（批 E）、Chart.js 图表（批 F，vendored 路线）、一键复制与 PicBed
> 内联错误（批 F）——设计见第十节。第三轮拍板批量多选 + 模态框零件（批 G），
> 设计见第十一节。第四轮拍板 **SettingsAdmin 收敛（批 B）**——全部页面创建收归
> SettingsAdmin 单通道，设计见第十三节；原批 B–H 顺延为 C–I。
> 本文记录计划与拍板结论；进度仅以批次标记呈现。
>
> **组件先行落样例页（2026-10-04）**：批 E/F/G 的组件契约已提前在
> aiya-core-devtools-ui 实现并验证（tabs/chart/copyText/bulkTable/modal 五件 +
> 对应行为层 + `assets/vendor/chart.umd.min.js` vendored，i18n +31 串全补译，
> 四门禁绿 + 容器实弹探针过）——站长指示「先稳定实现再正式替换」，样例页即组件
> 的活文档与回归床。
>
> **分页/操作栏轮（2026-10-04 第四轮）**：`listNav`（复刻核心列表分页解剖：上下
> 双栏、«‹›» 四箭头边界禁用态、顶栏跳页输入/底栏静态读数、N 项总数、one-page/
> no-pages 态）+ `select`/`input` 两个无状态控制零件落地样例页；`pagination`
> 退役（调用方为零）；`enqueue` 补挂核心 `list-tables` 样式；`filterBar` 自动
> 携带当前 `per_page`。**自动触发全部由调用方参数控制**（与 input 的 typeahead
> 同模式）：`jump_nav`（跳页回车导航）与 `per_page_nav`（每页行数切换，paged 归
> 1）默认关闭，参数开 → 渲染行为属性，JS 委托只对带属性者生效；`per_page` 为
> 查询参数不持久化（白名单钳制）。i18n +9 串（含 paging 语境与复数形态）。
>
> **按域重排（2026-10-04 站长拍板）**：组件集齐后，剩余生产页迁移放弃字母批次，
> 改按 core 当前域组织——每批一个菜单域一次到位，见第七节；历史段落（三/四/
> 六/九/十/十一节）中的旧字母仅作档案。
>
> **列表行解剖轮（2026-10-04 第五轮）**：顶栏一行化——`listNav` 顶栏增 `actions`
> 左槽（typically 一张 filterBar 表单），操作栏居左、分页器居右同线，即核心列表
> 屏的 `.tablenav` 解剖；kit 类 `aiya-core-listnav` 落 `.tablenav`，分页零件尺寸
> 从核心的 32px/16px 压到标准 30px 控制件尺寸，与操作栏零件一致。无 `actions`
> 时分页独占右侧（flex-end 兜底），底栏永不承载 actions（契约测试执法）。零新串；
> Dev Tools 三张列表与样例页已接线。
>
> **按钮图标零件（2026-10-04 同日）**：`button()` 的 dashicon 挂载移到标签左侧
> （首个子元素），带图标按钮落 `aiya-core-button--icon`——flex 行垂直居中、
> gap 管图标-文本间隔、dashicon 钳 16px 光学匹配 13px 文本（核心 dashicons 盒
> 为 20px + `vertical-align: top`，内联直排必歪）。旧 `.button .dashicons`
> 通配规则与 dashicons-update 特例一并退役。
>
> 前序批次的账务口径：notice 行对齐 + repeater 折叠卡片已落地并过全部门禁
> （phpunit 645/1902 原生盘、phpstan 0、phpcs 0、lint 402 文件净、i18n 三零、
> 容器实弹探针验证），**暂缓入 ROADMAP**（站长指示）——补账口径见第九节决策点 5。
> Ui 组件样例页（Dev Tools → Ui components）已随批 A 落地，为全组件实弹预览。

## 一、背景（2026-10-04 调研结论）

14 个自渲染操作页（兑换码/通知/积分/社区审核/Crons/搜索替换/运营月报/订单记录/
群发/图床/服务器状态/短代码/重写/图标）各自实现了同一套「页头 + 回执 + 操作卡 +
筛选 + 列表 + 分页」的外观，分歧与重复现状：

| 维度 | 现状 | 份数 |
|---|---|---|
| 操作区卡片 | WP 核心 `.card`+内联样式覆盖 / `details.aiya-core-card` 手写 / `.aiya-core-ops-cost` 定制条 / 无卡片裸 form-table | 4 种 |
| 列表件 class | `wp-list-table widefat fixed striped table-view-list` / `wp-list-table widefat striped` / 裸 `widefat striped` | 3 种 |
| 回执 `notice()` 方法 | 逐字重复（仅消息映射不同） | 5 份 |
| 分页 `tablenav + paginate_links` 块 | 逐行重复的 12 行 | 8 份 |
| 用户搜索 typeahead | 页内 inline `<script>`（积分页自带端点、订单页无 wrap 作用域的克隆版、群发页又一份） | 3 份 |
| `redirectBack()` 助手 | 相同实现 | 4 份 |
| 页头 `wrap+h1+description` | 手写 | 14 处 |

统一的半成品已存在：`.aiya-core-card`（details 折叠卡）、`.aiya-core-filters`、
`.aiya-core-badge`、`.aiya-core-meter` 都是共享 CSS 但无 PHP 出口，页面各写各的 markup。

## 二、目标与非目标

**目标**：`Admin/Ui` 一组无状态 PHP 渲染件统一上述重复面；admin.js 沉淀共享行为
（用户 typeahead 收敛为一份视图类）；admin.css 从「按获得顺序堆叠」改为分区组织。
外观一致、重复度大降、新操作页有据可依。

**非目标（红线与排除项）**：
- 不引 React/Gutenberg——只用 WP 原生 admin 样式 + Backbone/jQuery UI（AGENTS.md 既有约束）；
- REST / Contract / DTO 面零触碰（纯 admin 层）；
- 不迁 `WP_List_Table`（其 screen options/AJAX 机制对本组自渲染页过重）；
- `fileserve.js` / `parts-dialog.js` / `smilies-picker.js` 是域专用件，不并入通用层；
- 设置框架注册页（SettingsAdmin 渲染管线）的字段渲染不进 Ui——FieldRenderer 仍管
  form-table 行级渲染，Ui 只管页面级件，二者不重叠；
- 目标**零新增翻译串**（回执/分页/按钮文案全部复用既有）；实现中若不得不加，走
  `wp-i18n-zh-cn` 流程后再过门禁。

## 三、PHP 组件设计（新文件 `src/Admin/Ui.php`，单类静态件）

> **批 A 交付状态**：9 件已全部落地（`card/staticCard` 为 callable body 自闭合、
> `listTable` 带 phpstan 模板推断、`enqueue(bool $script)` 带开关）；`userPicker`
> 的行为层（UserPickerView）已随样例页提前落地，消费方在批 C 接入。上表是批 A
> 拍板快照，现行组件全集以 `src/Admin/Ui.php` 为真源；后续扩展（图表/批量/
> 模态框/notice/heading）见第十、十一、十四节。

全部为 `echo` 型无状态方法，页面按需调用，不强制整页改造：

```php
Ui::pageHead(string $title, string $description = ''): void   // wrap+h1+description，配 pageFoot()
Ui::flash(string $queryKey, array $messages): void            // note => [text, variant]；sanitize_key 读 $_GET，未知 note 静默
Ui::redirect(string $url, array $args = []): never            // add_query_arg + wp_safe_redirect + exit
Ui::cardOpen(string $summary, bool $open = false): void       // details.aiya-core-card；cardClose() 收口
Ui::cardStatic(string $summary): void                         // div.aiya-core-card--static 变体；cardClose() 收口
Ui::listTable(array $columns, iterable $rows, callable $cell, string $empty = ''): void
Ui::pagination(int $pages, int $current): void                // pages>1 才出 tablenav 块
Ui::userPicker(array $args): void                             // hidden id + 搜索框 + 建议容器 + data-* 契约
Ui::filterOpen(string $submitLabel, array $hidden = []): void // form.aiya-core-filters GET 壳；filterClose() 收口
```

- `listTable`：统一 `wp-list-table widefat fixed striped table-view-list` 基线；
  columns 形如 `['id' => ['label' => 'ID', 'width' => '56px']]`；`$cell($row, $col)`
  逐格 echo；空态行统一由组件出（colspan 自动 = 列数）。
- `cardOpen/cardStatic` 收编兑换码/通知两页的 `.card` + `style="max-width:100%"`
  内联覆盖（核心 `.card` 的 `max-width:520px` 本就与这两页冲突）；月报的
  `.aiya-core-ops-cost` 定制条并入静态卡变体（决策点 3）。
- `userPicker` 的 DOM 类名从 `aiya-credit-user-*` 更名 `aiya-user-*`（订单记录页
  沿用「credit」名是历史包袱；趁重写一并清，CSS 同步，零视觉变化）。
- `filterOpen` 只统一壳与按钮位，字段仍由页手写——筛选字段异质性强，不做字段级抽象。

## 四、admin.js 行为层设计（现 132 行 → 约 210 行）

> **落地状态**：UserPickerView 已随样例页提前实现（data-* 契约 + 250ms 防抖 +
> 重输清 id），行为宿主机制为 `data-aiya-ui` 标记（`Ui::pageHead` 出的 wrap 自带，
> admin.js 启动选择器已扩展）；批 C 只做消费页迁移。

现状：`MediaFieldView` / `RepeaterView`（含上批折叠行为）/ `SettingsView`
（字段初始化 + key-generate + code editor）。

新增 `UserPickerView`（Backbone，进 SettingsView 的 initializeFields 分发）：
- 绑定 `.aiya-user-picker`（`data-aiya-ready` 防重入，多实例天然隔离——修复订单页
  现版全局选择器克隆的隐患）；
- 行为复刻积分页现版语义：250ms debounce、重输即清 hidden id（选中值不得存活于
  可见输入之外）、`wp_ajax` JSON 契约 `{action, nonce, term} → {success,
  data.results:[{id,name,email}]}`、建议项点击回填；
- 回填形状按 `data-aiya-fill` 分流：`id`（积分/订单：填隐藏 id）/ `email`
  （群发：只填邮箱）；
- 端点与 nonce 由组件渲染时的 `data-*` 携带，JS 不感知页域。

enqueue 契约：新增 `Ui::enqueue()`——统一出 `aiya-core-admin`（css+js）与依赖，
替代现状「4 页各自只挂 css、行为靠页内 inline」的碎片状态。SendMail 的发送流、
PicBed 的上传流、Discussion 的弹窗流是页域逻辑，**不进通用层**（决策点 2 定其去留形态）。

## 五、admin.css 整理设计（现 407 行 → 约 380 行）

从「按获得顺序堆叠 15+ 段」重排为三区（保留逐段注释的房屋风格）：
1. **Settings framework**：form-table 基线、字段行对齐（含上批的 presentation rows
   对齐修复）、radio/multicheck/media/repeater（含折叠卡）/secret-clear/color；
2. **Shared components**：`aiya-core-card` 两形态（details + `--static`）、filters、
   badge、meter+devtools-bar、user-picker 建议列表、card 内列表的外边距规则；
3. **Page-specific**：credits 颜色、thread excerpt+dialog、icons grid 等域内残留。

清理：两页 `.card` 内联覆盖消失；重排为纯移动+合并，不改任何现行规则值（上批
repeater 折叠样式原样保留）。

## 六、修改面统计（预估）

> 批 A 的地基部分（`Ui.php` + 静态卡 CSS + 样例页）已实施；原试点的兑换码/通知
> 两页改造已撤回，两行保留为批 C 迁移时的计划口径。

**新增**：

| 文件 | 规模 | 内容 |
|---|---|---|
| `src/Admin/Ui/Ui.php` | 约 280 行 | 上节 9 件 + enqueue() |

**修改**（页 PHP 预估净减 250–350 行；JS 净增约 80；CSS 净减约 30；总净差约 ±0，
价值口径 = 12 处重复逻辑消失、外观归一）：

| 文件 | 收编内容 | 预估变化 | 行为层 |
|---|---|---|---|
| ConvertCodesPage | 页头/flash/静态卡/listTable/pagination/redirect | −45 行 | 无 |
| NotificationPage | 同上（孪生页） | −40 行 | 无 |
| CreditsPage | 页头/flash/userPicker×2/filter 壳/listTable/pagination/redirect | −50 行 | 挂 enqueue，删 40 行内联 |
| PaymentsAuditPage | 同上 + userPicker×1（换掉全局选择器克隆版） | −45 行 | 同上，删 35 行内联 |
| CronsPage | 页头/flash/card/listTable/pagination | −35 行 | 无 |
| ShortcodesPage / RewritesPage | 页头/listTable/pagination/filters | 各 −15 行 | 无 |
| DiscussionModerationPage | 页头/flash/filters/card/listTable/pagination | −30 行 | 弹窗+REST 流留页内不动 |
| SearchReplacePage | 两张卡换 Ui 调用 | −10 行 | 无 |
| OperationsPage | ops-cost→静态卡（待决策点 3）、三表换基线 | −15 行 | 无 |
| SendMailPage | 裸 form-table→静态卡；typeahead 段抽走 | −35 行 | 发送流留页内/拆独立文件（决策点 2） |
| PicBedPage | 上传卡换 Ui；列表件换基线 | −10 行 | 上传流留页内/拆独立文件（决策点 2） |
| ServerStatusPage | 已是 aiya-core-card，仅换 Ui 调用 | −8 行 | 无 |
| IconsPage | 页头换 Ui | −5 行 | 无 |
| SettingsAdmin | render 换 pageHead/flash，redirect 换 Ui::redirect | −15 行 | 无 |
| `assets/js/admin.js` | +UserPickerView + 初始化分发 | +80 行 | — |
| `assets/css/admin.css` | +静态卡/user-picker，重排三区 | −30 行 | — |

**测试**：CronsPage/SearchReplacePage/ServerStatusPage/ShortcodesPage 四个测试类
全部只断言服务/逻辑层（SQL 构造、cron 行摊平、百分比、回调标注），零 markup 断言
——预期零改动，迁移时复核即可。

**i18n**：目标零新串；若 `--static` 卡头等处确需文案，按流程补译后过三零验收。

## 七、迁移批次（2026-10-04 站长拍板：放弃字母分批策略，按 core 域组织）

> **字母批次退役**：早期 A–I 字母批次随「组件先行落样例页 + SettingsAdmin 收敛 +
> 第四轮控制零件」三步完成而失去意义——组件面已集齐，剩余工作只有生产页正式
> 接线，按域一次一个菜单域一批到位。第三/四/六/九/十/十一节中出现的旧字母
> （批 C 列表重型、批 D 表单报表、批 E tab、批 F 图表小件、批 G 批量模态、
> 批 H 文案、批 I 收尾）仅作拍板档案，内容已摊入下列域批次。
>
> **套件驻地原则（不变）**：`admin.js` + `admin.css` 就是 UI 套件通用样式与方法
> 的唯一驻地；页面迭代期间残留的编外独立方法与样式（页内联 script、页专用 CSS
> 段、旧 DOM 类）在收尾批（批 6）清除或收编。

- **批 1（Dev Tools 域）——✅ 已完成**：Crons / Rewrites / Shortcodes / Icons /
  SearchReplace / ServerStatus 六页接全套组件——pageHead/flash/card/filterBar
  （Ui::input 搜索框）/listTable/listNav 双栏（jump_nav + per_page_nav 开）/
  Ui::redirect；`per_page` 查询参数化（白名单钳制，默认 20/50/50，paged 越界
  钳回末页，行为与样例页同款）；三张手写分页块与三份 `paginate_links` 复制段
  退役；图标页描述上移 pageHead；SearchReplace 预览卡与执行表单数据流原样。
  DevToolsModule 模块级 `assets()` 退役（批 B 后为编外残留）：套件资产统一由
  SettingsAdmin 按 hook 分发，Dev Tools 各页由此获得 `list-tables` 样式与行为层。
  沙盒页（devtools-sample/devtools-ui）不属迁移面。i18n 零新串；
  收尾轮（同日站长拍板）：菜单一行化重排 + 标题同步——轨道序为 搜索替换 →
  定时作业 → 固定链接（Rewrites 改名 Permalinks）→ 短代码 → 图标库（Icons 译名
  改库）→ 系统信息（顶级镜像，SettingsAdmin 镜像子页自此支持 menu_position 落位）
  → Settings Kit（Sample 改名）→ UI Kit（Ui components 改名）；样例页（UI Kit）
  分区重整为小件区（按钮/输入框/select 差分）→ 列表件两项（合成顶栏 listNav、
  bulkTable）→ 筛选条 → 回执/卡片 → typeahead/tabs/charts/copy/modal；
  i18n 净 +4 串（Permalinks/Small parts 新译，两个 Kit 名按约定保持原文）；
  **文本总整轮（同日，域迭代项收口）**：两项沙盒页全量文案按新文案规范重写——
  不用破折号两遍式描述、正向点名所调用的 WP 组件并列出入参出参形状、主设计点
  只进页面副标题（UI Kit = 声明式渲染组件、Settings Kit = field 声明式表单零件）、
  中文译文取消句末句号；UI Kit 页改组为组件目录（一组件一节、每组件仅列一次，
  往返回执/筛选回显/批量回显等初版演示功能退役，批量演示 handler 缩为纯重定向
  水管）；i18n 净 -1 串（删 36 归档、新增 30）。**开发工具域全部迭代项至此闭合**，
  文案规范（正向描述 + 入参出参 + 中文去句末句号）作为批 6 全域文案精修的基准。
- **批 2（会员域，菜单=会员设置）**：Credits + Payments（userPicker×3 换
  UserPickerView 消费、`aiya-credit-user-*` → `aiya-user-*` 更名）+ ConvertCodes
  （copyText 落 code 格；批量删除为可选延伸，需先补 `deleteByIds()`）+
  Operations（ops-cost 并入静态卡 + 两图接 Chart.js，表格保留）；
- **批 3（前台域，菜单=AIYA CMS Core）——✅ 已完成**：Notifications（批量删除
  bulkTable）+ SendMail（typeahead 段换 userPicker、裸 form-table→静态卡）+
  PicBed（copyText 落 URL/路径框 + alert() 内联化）。**PicBed 已完成（2026-10-05
  站长点名提前）**：页面壳迁 Ui::pageHead/heading/foot，上传按钮走 Ui::button
  （kit 首个带 id 的提交按钮），上传失败 alert() 改页域内联 notice（消息随
  上传往返到达，壳由内联脚本填充——同 Discussion 错误位模式），上传结果块的
  URL/相对路径挂 copyText（CopyView 点击时读 data 属性，回填即可用），图片池
  列表迁 Ui::listTable（预览/来源/URL/相对路径，宽度入列 spec，空态由组件
  出）；上传流仍为页域内联脚本（既定决策）。i18n 零新串（POT 原生盘重扫
  1169 行零漂移）；门禁 682 tests / 2047 assertions；浏览器过页面结构、模拟
  回填的结果块与 copy 接线、22 行真实池列表。
  **完善轮（同日站长拍板）**：上传表单 + 回显块套入 Ui::card 一体壳；kit 标题
  行补底部间距（h2 `24px 0 12px`、h3 `16px 0 8px`——原先仅上边距，标题与下方
  零件贴合）；已上传列表改版——取消 200 行截断与「最早未列出」提示，新增
  `pic_user` 过滤参数（filterBar + User ID 输入）：留空列根池（递归剪除 u/
  子树）、写了用户 ID 只列 `u/{id}/` 树；去来源列（不再逐行查用户，
  `sourceCell` 与 User 两串退役）；相对路径列换「操作」列——一枚
  `copyText` 直出完整 `<img src="…" alt="">` 标签；预览放大至 96×72。
  **Media 域分界核查结论**（三项均已成立，零改码）：Admin 零图像处理
  （CoverMetabox/CardThumbnailBulkAction 纯委托）；上传双路径由 `PicBedStore`
  单管线承接——管理页传 `picBedDir()`（根+日期）、REST 传
  `userPicBedDir()`（u/{id}+日期），目录差异即全部差异；编辑器走 WP 媒体库、
  落盘被 `wp_handle_upload` 过滤器（MediaModule::handleUpload，
  image_take_over_uploads 开关默认开）接管处理。avatar 存储留 Identity 的
  评估另见会话记录（叶子裁决 + 零消费方交集）。i18n 净 -1 串（+3 新：
  复制 img 标签等；-4 退役）；四门禁 682/2047；浏览器过根池视图（0 泄漏
  u/ 路径）、pic_user=43 视图（15 行全 /u/43/）、卡片壳、复制列与标题间距。
  用户筛选补提示行：填 ID 显示「正在列出 {用户}（#id）上传的文件；打开用户页」
  （链接 user-edit），ID 不存在时明确提示列表为空（+3 串）。
  **收尾轮（2026-10-05）**：Notifications 迁 `Ui::pageHead/flash/staticCard`——
  发布表单入静态卡（Ui::button 首个 primary 提交钮），存量列表整迁
  `Ui::bulkTable`（全选/实时计数/空动作拦截/确认门，批量删除一轮 admin_post
  往返）；批量删除补 `NotificationService::deleteByIds()`（生成 IN 占位 +
  prepare），`adminPage()` 换 `adminRows()` 全量列表（bulkTable 自切片，
  保留期 30 天兜底规模）；per_page 查询参数化随批量条生效（白名单
  10/20/50/100，paged 越界钳回末页）；逐行删除保留（confirm 改
  wp_json_encode 注入，批 1 同款）。SendMail 迁 `Ui::pageHead` + 裸
  form-table 入静态卡，收件人换 `Ui::input` typeahead 开关（fill=email
  保留任意地址自由输入语义），页内联想脚本段退役，搜索端点
  （aiya_core_mail_search）原样 serving UserPickerView；发送流内联脚本不动。
  i18n 净 +3 串（已删除 %d 条通知/删除选中的通知？/撰写，POT 原生盘重扫
  1164→1167）；四门禁 683/2053；浏览器过通知页（发布往返、全选联动、确认门、
  批量删除往返与「已删除 N 条通知」回执、per_page=10 切页）与 SendMail
  （静态卡、联想出建议、点选回填邮箱）。
  **顶级化拍板（2026-10-05 站长）**：通知与发送邮件脱离 AIYA CMS Core 组升
  顶级菜单（bell/email 图标，注册键去 parent）——通知落菜单栏评论下方
  （position 25.5），Settings Schema 的 position 随之放宽 float（整数位在
  PHP 数组键透明归一，既有注册零漂移），发送邮件居图床之后（84）。
  **列收敛（2026-10-05 站长）**：存量列表「最低角色/范围」两列合一为「范围」
  ——定向行显用户 #N、广播行显角色名（判定按 user_id 切分，定向行存储的
  min_role 本就是读取面不消费的惰性值）；ID 列取消（通知是最终产物，无下
  游消费者）；i18n 净 -2 串（Minimum role/Broadcast 退役，POT 原生盘重扫
  1167→1165 差集精确核对）。
  **通知域越界修复批（2026-10-05 站长拍板 P1–P4）**：P1——Sponsorship 补
  `EntitlementService::orderBy()/queueEndsBetween()` 两个读取面，
  NotificationActions 的到期扫描改走服务，通知域对 `aiya_memberships` 的
  表级直读清零；P2——激活回执账单整体搬 `Domain/Mail/MembershipReceipt`
  （挂同一 `aiya_core_membership_activated`，读单走 orderBy），通知域的
  Mail 出边清零，回执单测随之迁 `MailReceiptTest`；P3——Content 域新增
  `ContentEventsModule` 事件委托（`aiya_core_comment_posted` +
  `aiya_core_post_approved/published/updated`，审批门与可见性门归
  Content），NotificationActions 退役对 `wp_insert_comment`/
  `transition_post_status` 的直挂改挂委托事件（held-comment 守卫用例随门
  迁移退役，评论守卫其余用例全保留）；P4——ARCHITECTURE 依赖节记
  Notification=聚合消费方（事件面设计为膨胀）与 Mail=单业务钩子身份，
  AGENTS 关键缝事件表补全（含此前漏记的 user_followed）、Mail 域行注单钩
  子、持久数据协议表登记 `aiya_core_fav_notified_at`/
  `aiya_core_sponsor_state_noticed` 两枚水位键（`aiya_core_` 前缀随卸载
  清扫面系有意为之）。邮件域审查结论：交互面=wp_mail 接管 + 四类原生重写
  + 静音门（皆 WP 原行为）+ 回执唯一业务钩子，零越界；域外无任何 Mail 类
  消费方。四门禁 684/2054（+1：回执单测独立）。
- **批 4（社区域）**：DiscussionModeration 批量 handler ×2（关闭/重开、删除）+
  线程编辑弹窗迁移 `Ui::modal` + confirm GET 链接改 POST；批量条四串 + 模态框
  壳零新串已随样例页入池；
- **批 5（注册表页 tab 化）**：FieldRenderer 面板分组（heading 升格 tab，设计见
  10.1），全部 ≥2 heading 注册页自动生效（optimization/content/image/fileserve/
  sponsorship-payments），Sample 沙盒页即演示床；
- **批 6（收尾）**：文案逐页精修 + 设置键回填（页面清单见第十二节、设置键底表
  见 `SETTINGS-REGROUP.md`——页头描述语气、按钮动词、回执语气与变体、空态句式、
  失败回执四变体收一）；清编外（页内联 script 归置、页专用 CSS 段收编或删除、
  inline confirm() → 模态框危险变体的候选迁移）；全量四门禁 + 视觉回归 +
  ROADMAP 补账（口径见决策点 5）。

推荐执行序：**2 → 3 → 4 → 5 → 6**（4 与 3 可互换；TermMove/PostTypeSwitch 弹窗
迁移（11.3 候选）在批 4 验证 `Ui::modal` 后顺延；6 必须最后）。

## 八、验证门（每批同口径）

四门禁：`parallel-lint` 全树 / `phpcs` exit 0 / `phpstan` 0 / `test-native.sh`
（600 tests / 1500 assertions 钉板下限照旧）；i18n POT/PO 集合差 0 + (ctx,msgid)
重复 0；每页 `wp eval` 渲染探针对照迁移前后 markup 语义（卡开合态、空态行、
分页块、回执变体）；视觉过一遍改动页（浏览器）。

## 九、决策点台账

> 批次字母顺延对照见第七节；第一/二/三轮条目保留拍板时字母，前瞻项已按新字母。

第一轮（工具件本体）：
1. Ui 形态——**已落定**（批 A）：单类静态件、callable 卡、`enqueue(bool $script)`；
   文件落 `src/Admin/Ui.php`（平铺约定，同命名空间免 import）。
2. SendMail/PicBed 域内 JS 拆独立文件 vs 留页内 inline——**开放**，批 D 执行时定（倾向拆）。
3. OperationsPage 的 ops-cost 并入静态卡 vs 保留——**开放**，批 D 执行时定（倾向并入）。
4. 迁移粒度——按批执行中。
5. ROADMAP 补账口径——**开放**（站长指示暂缓入账；各轮工作合成一条 vs 拆多条待定）。
6. userPicker 类名更名（`aiya-credit-user-*` → `aiya-user-*`）——**倾向更名**，批 C 执行。

第二轮（2026-10-04 调研拍板）：
- 页内 tab 导航——✅ **立项**（批 D），设计见 10.1。
- 后台图表——✅ **立项，路线 = Chart.js 稳定版 vendored 进项目目录**（与
  `assets/js/mce` 的 TinyMCE 插件同理，零 CDN），自研 SVG 微图表否决
  （站长：比自己写量少）。设计见 10.2。
- 一键复制、PicBed alert() 内联化——✅ **立项**（批 E），设计见 10.3。
- 共享确认对话框——⏸ **登记候选**（10 处 inline confirm() / 8 文件，清单见 10.4；
  第三轮结论：并入模态框零件作为危险动作变体实现，见 11.2，候选保持挂起）。
- 列表客户端快筛——❌ **否决**（站长：实际数据都需经查询，客户端过滤无意义，
  各页按需自行实现）。

第三轮（2026-10-04 调研拍板）：
- 批量多选组件（列多选 + 批量动作）——✅ **立项**（批 F），主战场轻社区后台，
  设计见 11.1。
- 模态框零件——✅ **立项**（批 F），收敛现存 4 种开框写法，设计见 11.2。
- 模态框消费候选扩充（站长补充 3 项）——✅ **列入候选**：WP 列表屏批量弹窗迁移
  （TermMove/PostTypeSwitch，技术栈已同，迁移机械）、编辑器组（零件插入器 +
  表情包输入器，wpdialog 壳，需先解决编辑器侧加载路径，低优先级）——核实结论
  见 11.3；CardThumbnailBulkAction 无弹窗，不在候选。

## 十、第二轮扩展设计（2026-10-04 调研拍板）

### 10.1 页内 tab 导航（批 E）

候选页实测（容器读设置注册表的顶层行数，非源码粗数）：

| 设置页 | 顶层行数 | 现成 heading 分区 | 处置 |
|---|---|---|---|
| 优化 optimization | 25 | 4 区（已禁用功能/头像设置/别名生成/中文排版） | 最佳候选：字段来自 3+ 模块汇入，tab 恰好表达分区归属 |
| 文件下载 fileserve | 14 | 4 区（服务集成/文件列表/OpenList/GoFile） | 适配器分区，天然 tab |
| 支付 sponsorship-payments | 15 | 2 区（爱发电/订单日志） | 可切 |
| 内容管理 content | 13 | 3 区（保留策略/SEO 与统计/NSFW） | 可切 |
| 图像处理器 image | 13 | 2 区（图像处理/水印） | 边际 |
| 前台设置 frontend | 16 | 无 heading | 不切，保持平铺 |
| 页面区块 blocks | 6（5 repeater） | — | 折叠卡已解决，不需要 |
| Sample 沙盒 | 26 | 有 | 天然演示床 |

设计：
- 切分点 = 现有 `heading` 字段（分组语义已存在，无新概念）：服务端把字段流按
  heading 切成面板，heading 升格为 tab 标签并从表体撤下；首个 heading 之前的
  内容归「通用」面板（+1 串）。
- 切换纯客户端：所有面板留在同一个 `<form>`，保存/重置语义不变；每面板一张
  form-table（core 的 `th` 200px 宽度保证各面板列对齐一致）。
- 视觉 = WP 原生 `.nav-tab-wrapper`（`common.css:2409` 起全局样式，各页已挂
  `common` 依赖）——零新 CSS 依赖；不引 jquery-ui-tabs（还需配主题 CSS）。
- 交互防线：`location.hash` 记忆 + 保存 redirect 携 tab 回跳；表单 `invalid`
  事件自动切到首个失效字段所在面板（与 repeater 折叠同款防线）；heading < 2 的
  页自动退化为平铺（零行为变化）。
- 自渲染操作页不接（ServerStatus/SearchReplace 的卡是顺序流程，tab 会藏流程）；
  `Ui::tabs` 留接口、无消费者。
- 改动面：FieldRenderer 面板分组 ~50 行、admin.js TabView ~40 行、CSS 面板间距
  ~8 行、i18n +1；验证面 = 上表全部 ≥2 heading 的注册页。

### 10.2 Chart.js 图表（批 F）

点位与数据形状（全部现成，服务端 `$trend` 数组直接进 payload，无需新 REST）：
- 运营月报 Trailing 12 months：12 月 × 11 指标 → 折线（发放/消耗/过期 + 下载量）
  + 柱（现金/确认收入/成本）；现状为 12 列宽表 + 每行一根 meter 条，趋势全靠心算。
- Grants by source：12 月 × 4 来源 + 合计 → 堆叠柱。
- ServerStatus 计量条维持现状（2-3 个数值，条已够读；调研即定不升级 gauge）。

实现（站长拍板 vendored 路线）：
- Chart.js v4 稳定版 UMD 构建落 `assets/vendor/chart.umd.min.js`（连同 LICENSE），
  与 `assets/js/mce` 的 TinyMCE 插件同理——零 CDN、随插件分发；仅月报页条件加载。
- 月报页 `wp_add_inline_script` 注入 trend/sources 两组载荷；admin.js 增初始化读
  载荷建图（~50 行）；PHP 侧月报页加两个 canvas——**表格保留，图是表的视图不
  替代表**。
- 图表区标题复用现有 h2 串，系列标签全复用表格列串（Granted/Consumed/…/
  Check-in/…）——i18n 零新增。
- 改动面：vendor ~200KB、OperationsPage ~50 行、admin.js ~50 行、CSS 容器 ~10 行。

### 10.3 小件（批 F）

- **一键复制**：`Ui::copyText(string $text, string $label = '')` 出
  `.aiya-core-copy` 按钮（data 属性携文本），admin.js `navigator.clipboard`
  （非安全上下文 textarea 兜底），成功后按钮短暂反馈。落点 = 兑换码表 code 格
  （运营逐个发码的最高频动作）+ 图床页 readonly URL/路径输入框。i18n +2
  （Copy / Copied.）。
- **PicBed 上传失败 alert() → 内联错误**：全后台唯一 `alert()`，改表单旁内联
  错误文案，复用既有「Upload failed.」串，零新串。

### 10.4 候选与否决登记

- **共享确认对话框（候选，未独立立项）**：inline `confirm()` 约 10 处 / 8 文件——
  通知删除、设置重置、opcode 清理、社区帖删除、板块删除、执行替换、清孤儿任务、
  兑换码全删、Crons 行内操作等；`confirm()` 功能够用。第三轮结论：并入模态框
  零件作为「危险动作变体」实现（红按钮 + 文案位，见 11.2），不再单独立项。
- **列表客户端快筛（否决）**：站长拍板——实际数据都需经查询，客户端过滤无意义，
  各页按需自行实现（如支付页的用户筛选）。
- **月报月份按钮组换月份选择器（否决）**：按钮组直观展示有数据的月份，保持。
- **长设置页粘性保存条（否决）**：偏离 WP 原生惯例；tab（批 E）已解决长度痛点。

## 十一、第三轮扩展设计（2026-10-04 调研拍板）

### 11.1 批量多选组件（批 G）

候选表盘点（全系统带操作的表逐个核实）：

| 表 | 现行行级操作 | 批量动作建议 | 评估 |
|---|---|---|---|
| 轻社区线程表 | 编辑（弹窗）+ 删除（confirm GET 链接，级联回复） | 关闭/重开选中、删除选中 | **主战场**，审核场景天然按批处置 |
| 通知列表 | 删除（confirm GET 链接） | 批量删除 | 简单直赢 |
| 兑换码表 | 无（仅「全删」） | 批量删除选中 | 真功能补齐：需先补 `deleteByIds()` 服务方法 |
| Crons 事件表 | Run now / Delete（行内 confirm） | 批量删除事件（Run 无批量意义） | 有价值，顺位靠后（清孤儿按钮已覆盖部分） |
| 积分流水 / 订单记录 | 只读 | — | **排除**：钱账只读，不可变审计语义 |
| 短代码/重写/月报/服务器状态/搜索替换 | 只读巡检 | — | 排除 |
| 图床图片列表 | 无操作 | — | 排除：删除语义本身不存在（文件可能被帖子引用） |
| 社区板块表（卡内） | 编辑/删除 | — | 排除：行数少且在折叠卡内 |
| WP 原生列表表 | 核心 bulk 机制 | — | 已有核心机制，BulkActionNotice 族工作其上，不归本组件 |

设计：
- `Ui::listTable` 增 bulk 模式：首列 checkbox（表头全选 + indeterminate，视觉复用
  WP 核心 `.check-column`，`common.css:518` 起全局可用）；表格外层包 POST form
  （`admin_post` + nonce）；表格上方出批量条：动作下拉 + 应用 + 「已选 N 项」
  计数——形态与 WP 原生列表一致，零学习成本。
- JS `BulkView`（admin.js 约 60 行）：全选/计数/零选中禁用应用；危险动作先走
  `confirm()`，模态框零件落地后换危险确认变体（与 11.2 汇合）。
- 服务端：各页加一个批量 handler（`ids[]` + nonce + capability），循环复用现有
  单条服务；线程删除已含回复级联。
- 落地顺位：组件契约随批 G 进 `Ui`；轻社区页批 G 首个接入；通知页同批；兑换码
  与 Crons 作为同批可选延伸。

### 11.2 模态框零件（批 G）

现状盘点——同一个「打开对话框」现存 4 种写法：

| 调用方 | 技术 | 形态 |
|---|---|---|
| 社区线程新建/编辑 | raw `jquery-ui-dialog` + `wp-dialog` class + `wp-jquery-ui-dialog` 样式，680px | 页内隐匿 div + 页侧 JS 初始化/填充/提交 |
| TermMove / PostTypeSwitch（挂 WP 原生列表） | raw `jquery-ui-dialog`，380px，BulkDialogBehavior config 注入 inline script | 同上另一份手写 |
| 模板零件插入器 / 表情包输入器 | `wpdialog`（WP media 栈封装） | 编辑器/tinymce 语境 |
| inline `confirm()` ×10 | 原生 | 确认对话框候选（第一、二轮已挂起） |

设计：
- `Ui::modal(string $id, string $title, callable $body, array $args = [])` 出壳
  （autoOpen:false、`wp-dialog` class、宽度/标题走 data-* 配置）；触发声明式：
  任意元素带 `data-aiya-modal="<id>"` 即开；`ModalView`（admin.js 约 50 行）统一
  初始化与开合（escape/遮罩/焦点）。
- **页侧保留填充与提交逻辑**——行编辑的 REST 拉取/回填是页域，零件只管壳与开合
  （与 repeater/用户搜索的分工一致）。
- 技术栈 = `jquery-ui-dialog` + `wp-jquery-ui-dialog`：房内两个现存消费者已在用，
  样式由核心提供，零新依赖，符合 Backbone/jQuery UI 红线。
- **首个消费者 = 社区线程编辑弹窗迁移**（迁移即验证）；批量删除确认即
  「危险动作变体」（红按钮 + 文案位），第一、二轮挂起的确认对话框候选就此并入
  本零件实现路径。
- 入 Ui 样例页演示（声明式触发 + 一个行编辑式填充示例）。

### 11.3 模态框消费候选扩充（站长补充 3 项的核实结论）

| 候选 | 核实结论 | 处置 |
|---|---|---|
| 文章页/分类法页批量操作迁移（TermMove/PostTypeSwitch） | BulkDialogBehavior inline 注入的 380px raw jquery-ui-dialog，与本零件技术栈**完全相同**，迁移 = 换 `Ui::modal` markup + `data-aiya-modal` 触发，退役 BulkDialogBehavior 的初始化段 | ✅ 列入候选——批 G 落地验证后顺延迁移，机械且低风险 |
| 编辑器零件插入器（parts-dialog） | `wpdialog` 壳 + 双栏交互 + `send_to_editor` 通道；编辑器语境需先解决加载路径（模态框若要进编辑器屏，需拆独立小句柄 `modal.js` 供 admin.js 与编辑器两侧共用） | ✅ 列入候选、**低优先级**——壳统一的收益确定，但编辑器焦点/语境回归需专门验证 |
| 编辑器表情包输入器（smilies-picker） | 同为 `wpdialog` 壳（格点 + 点选插 token 即关），比零件插入器简单 | ✅ 列入候选、**低优先级**，与零件插入器同组（编辑器组）顺延 |
| （核查项）CardThumbnailBulkAction | 零弹窗引用——纯后台处理的批量动作 | 不在候选 |

### 11.4 批 G 改动面与 i18n 预估

- `src/Admin/Ui.php`：`modal()` + `listTable` bulk 模式（约 +80 行）；
- `assets/js/admin.js`：`BulkView` + `ModalView`（约 +110 行）；
- 轻社区页接入（批量 handler ×2 + 弹窗迁移 + confirm GET 链接改 POST，约 ±60 行）；
- 通知页批量删除（handler + 表格 bulk 模式，约 +30 行）；样例页两组演示（约 +40 行）；
- CSS：批量条/危险变体按钮 ~15 行；i18n：批量条约 4-5 新串，模态框壳零新串。

## 十二、aiya-core 全量后台页面清单（批 6 文案精修底表）

实测枚举（容器读注册表 + 源码 grep 菜单常量，2026-10-04）：**24 页 = 注册表页 10 +
自注册页 14**；其中 `devtools-sample`/`devtools-ui` 为 WP_DEBUG 沙盒，不在文案
精修范围。URL 形态统一为 `admin.php?page={slug}`。

**注册表页（SettingsAdmin 渲染管线，页面 URL = `aiya-core-{slug}`）：**

| 菜单路径 | slug | 标题 | 行数/分区 | 迁移批 |
|---|---|---|---|---|
| 前台设置（顶级） | aiya-core-frontend | 前台设置 | 16 行/无分区 | —（平铺） |
| 前台设置 → 优化 | aiya-core-optimization | 优化 | 25 行/4 区 | 批 5 |
| 前台设置 → 内容管理 | aiya-core-content | 内容管理 | 13 行/3 区 | 批 5 |
| 前台设置 → 页面区块 | aiya-core-blocks | 页面区块 | 6 行（5 repeater） | —（折叠卡已收） |
| 前台设置 → 图像处理器 | aiya-core-image | 图像处理器 | 13 行/2 区 | 批 5 |
| 前台设置 → 安全加固 | aiya-core-security | 安全加固 | 11 行 | — |
| 前台设置 → 文件下载 | aiya-core-fileserve | 文件下载 | 14 行/4 区 | 批 5 |
| 会员设置（顶级） | aiya-core-membership | 会员设置 | 6 行/1 repeater | — |
| 会员设置 → 支付 | aiya-core-sponsorship-payments | 支付 | 15 行/2 区 | 批 5 |
| Dev Tools → Settings Kit | aiya-core-devtools-sample | Settings Kit | 沙盒 | 沙盒 |

**自注册页（各自 add_menu/add_submenu）：**

| 菜单路径 | slug | 页面 | 迁移批 |
|---|---|---|---|
| （顶级）Dev Tools | aiya-core-devtools | Server Status（顶级镜像） | 批 1 ✅ |
| Dev Tools → Crons | aiya-core-devtools-crons | Crons | 批 1 ✅ |
| Dev Tools → Permalinks | aiya-core-devtools-rewrites | Permalinks | 批 1 ✅ |
| Dev Tools → Shortcodes | aiya-core-devtools-shortcodes | Shortcodes | 批 1 ✅ |
| Dev Tools → Icons | aiya-core-devtools-icons | Icons | 批 1 ✅ |
| Dev Tools → Search & Replace | aiya-core-devtools-search-replace | Search & Replace | 批 1 ✅ |
| Dev Tools → UI Kit | aiya-core-devtools-ui | UI Kit 样例 | 沙盒 |
| 会员设置 → Redemption codes | aiya-core-convert-codes | 兑换码 | 批 2 |
| 会员设置 → Order records | aiya-core-payments | 订单记录 | 批 2 |
| 会员设置 → Credit ledger | aiya-core-credits | 积分流水 | 批 2 |
| 会员设置 → Operations report | aiya-core-operations | 运营月报 | 批 2 |
| 前台设置 → Notifications | aiya-core-notifications | 通知 | 批 3 |
| 前台设置 → Send Mail | aiya-core-send-mail | 群发 | 批 3 |
| 前台设置 → Pic bed | aiya-core-pic-bed | 图床 | 批 3 |
| （顶级）Light Community | aiya-core-discussions | 轻社区 | 批 4 |

**批 6 已知文案痛点种子**（逐页精修时的统一口径清单）：
- 失败回执三处变体：`The operation failed — check the values and try again.`
  （兑换码/通知/积分/Crons）vs `The operation failed.`（社区弹窗）vs
  `Request failed.`（群发）vs `Upload failed.`（图床）——收一条标准失败语气；
- 兑换码/通知两页的 `<h2 class="title">` 内嵌整个删除表单（标题里藏按钮），
  与其他页的标题/动作分离形态不一致；
- 按钮动词不统一：Filter（筛选）/Echo（演示）/Apply/Run now 等，动词-宾语搭配
  逐页核对；
- 空态句式：`No codes stored.` / `No notifications stored.` /
  `No ledger entries yet.` 等——统一句式（「No X yet.」或「No X stored.」择一）；
- 菜单标题与页内 h1 一致性（0.104 已改 Order records 一例，其余页同口径复查）；
- **按钮文案不带标点**（句号/括号/引号等）——如样例页的 `Echo selection (demo)`
  一类括号按钮名在批 6 一并清理（2026-10-04 站长补入的种子检查项）；
- 描述段（`p.description`）长度与语气：有的页一句话、有的页整段操作说明——
  定基准（一句话定位 + 关键约束），长说明下沉到字段 description。

设置键全量底表另见 `SETTINGS-REGROUP.md`（182 键 / 10 option 组，含拟改中文与
拟归属空列，批 6 回填依据）。

## 十三、SettingsAdmin 收敛（批 B，2026-10-04 调研拍板）

**问题**：SettingsAdmin 是项目起步期设计，彼时还没有后台页面；任务分批推进后每个
域各自 add_menu/add_submenu，形成优先级散布（9/30/35 三档编舞）、enqueue 样板
重复（`str_ends_with` 守卫 ×N）、两套渲染管线并行的局面。收尾阶段应补强原管线
做收敛：**所有页面创建收归 SettingsAdmin 一个通道**。

**两案合流结论**（站长两案不冲突，是同一枚硬币的两面——收敛点是注册通道而非
渲染方式）：

1. **Page schema 增加页面种类** `form | callback`：
   - `form`（默认）= 现管线原样（form-table 渲染 + 统一 save/reset），零迁移；
   - `callback` = 页面自带渲染 callable——14 个自注册页的 `render()` 原样搬入
     注册项，既有实现无缝保留；admin_post/AJAX 端点留在页面类（页域逻辑，
     非注册逻辑）。两渲染形态同页共存天然成立（callback 页内用 Ui 组件、
     可嵌 form 子块；form 页获得套件 chrome）。
2. **SettingsAdmin 成为唯一注册与挂接执行者**：`menus()` 单点注册全部页面
   （一个优先级，散布编舞全删）；Page schema 增可选 `render` / `assets` 两个
   callable（内存对象，不涉序列化），`assets()` 按 hookname 分发。
3. **域所有权约定原样**：各域仍在 `aiya_core_register` 里 addPage/addFields
   （含 render）——SettingsAdmin 是统一化公共抽象、只做执行者不做归属方；
   `aiya-core-frontend` 这类无主通用壳就是纯 registry 页（现机制已支持）。
4. **注意点**：callback 页 capability 双保险（外壳查一次、页内 wp_die 保留）；
   network 页不支持 callback（schema 限制，现无需求）；DevTools 的 WP_DEBUG
   门控保持在域侧注册时判断，不动 schema；注册表外的裸 option
   `aiya_core_operations`（运营月报单价）可借批 B 转正（月报页以 callback 入
   注册表，顺带归位）。

**分两步实施**：
- **批 B1（分发器 + 试点）**：Page schema（kind/render/assets）+ SettingsAdmin
  分发器 + 2-3 个简单页迁入验证（pic-bed/shortcodes/rewrites）；
- **批 B2（全量迁入）**：余下自注册页全部迁入（credits/payments/crons/
  convert-codes/notifications/search-replace/operations/send-mail/pic-bed/
  server-status/icons/discussions/ui 样例页），迁移机械（每页删 menu()/assets()
  → 注册项两行）。

**收益**：菜单审计单点化（第十二节清单 ↔ 注册项 1:1）、新增页零样板、
域批次的页面迁移在统一通道上进行、文案精修一条管线过完（批 6）。

**改动面预估**：Page.php +~40 行、SettingsAdmin +~60 行（分发器与 assets 分发）、
Registry +~15 行；各迁移页 −10~15 行（menu/assets 样板删除）；共 14 页 +
框架三文件。

## 十四、第四轮扩展：notice / heading 统一化（2026-10-05 站长拍板）

> 设定页的 note 条目与标题行原是 `FieldRenderer::renderPresentation()` 的私有
> 标记，被 `<tr>` 行外壳锁在 form-table 里；页面侧同样的横幅与分区标题散落
> 手写（`printf('<div class="notice …">')` 约 11 处、`<h2 class="title"
> style="margin-top:24px">` 方言 11 处）。本轮把两段标记升格为套件组件，渲染
> 单点化，表单只留行解剖。

- **新组件**：`Ui::notice(string $text, array{variant?, dismissible?, inline?})`
  ——variant 白名单（info/success/warning/error，未知落 info）与标记在此单点，
  文本一律 `wp_kses_post`（纯文本与带链接的描述同一扇门）；
  `Ui::heading(string $text, int $level = 2)`——level 夹逼 1–3，出
  `<h{level} class="aiya-core-heading">`。
- **委托关系**：`Ui::flash()` 保留自身的 success 兜底契约（UiTest 钉板）后委托
  notice 出标记；`FieldRenderer::renderPresentation()` 只留
  `<tr class="aiya-core-nondata">` 行外壳，内部标记委托两组件——**字段声明
  API（`type => note/heading`）不动，15 个注册模块零改动**。
- **CSS**：`.aiya-core-heading` 的 margin（h2 24px / h3 16px）从
  `.aiya-core-settings` 域提升为套件级（Shared components 区），页内联
  `style="margin-top:…"` 清零。
- **收编面（本轮）**：SettingsAdmin 三条状态横幅、BulkActionNotice、CronsPage
  孤儿横幅（`<p>` 内文字+按钮经 kses 同门）、IconsPage 两条错误横幅、
  Shortcodes/Rewrites 分区标题、SearchReplace 两处 h3、UI Kit 演示页全部分区
  标题（自带组件演示）。**带 `<p>+<ul>` 列表的 metabox 错误块
  （FileServeMetabox / MetaboxAdmin）形状特殊不硬塞，保留手写**；其余域页的
  `h2.title` 与手写横幅归各自域批次（批 2–4）顺带换。
- **坑登记（WP 核心 common.js 搬迁）**：核心行为层把非 `.inline` 的
  `div.notice` 统一搬到 `.wp-header-end`（H1 之后）——页顶横幅（flash、孤儿
  横幅）搬迁无害即常态；页身横幅（表单 note、演示横幅）必须带 `inline` 才留在
  原位。`inline` 只免搬迁、无样式差异。
- **i18n**：净 +4 串（UI Kit 页 +6 新、−2 归档；收编页文案原样），POT/PO
  1155=1155、missing 0、(ctx,msgid) 重复 0。门禁 680 tests / 2033 assertions；
  浏览器过 UI Kit、Settings Kit、定时作业（孤儿横幅实造实清）、短代码、固定
  链接五页。

## 十五、第五轮扩展：listNav 解剖自持（2026-10-05 站长拍板）

> 拍板：操作行与列表行间距过小、阴影压在一起；操作行零件直接套既有按钮与
> 表单零件外观；表样式只处理表体与宽度；叠加的双份样式收敛。

- **标记去核心化**：listNav 不再骑核心 `tablenav` 族类
  （`tablenav`/`tablenav-pages`/`tablenav-pages-navspan`/`pagination-links`/
  `displaying-num`/`current-page`/`paging-input` 全数退役）——核心分页零件
  样式全部 scope 在这几层类之下，类一去，整套覆盖栈自然消失。新标记全 kit
  类：`aiya-core-listnav-pages/-links/-count/-num`；条自身持有间距
  （top `16px 0 12px`、bottom `12px 0 16px`），按钮阴影与表框脱开。
- **零件即裸件**：箭头链接携带共享按钮类（禁用位为带
  `button aiya-core-button disabled` 的 span，核心 `.button.disabled` 对
  span 的既有样式直接生效）；跳页框与每页 select 是共享 input/select 零件
  原样（宽度由 `size` 属性自管）；条不持任何尺寸与外观规则。**关键实测**：
  WP 7.x 原生控制线已是统一 40px（按钮/输入框/select 一致），零件套既有外观
  即天然等高——中途曾按 WP 6.x 记忆加 30px 归一规则，量测反证后删除。
  `one-page` 隐藏链接收归 kit CSS。bulk-bar 的 action select 同走原生线。
- **表侧不动**：`wp-list-table widefat…` 基线本就全由核心 list-tables.css
  承载，kit CSS 对表只有卡体边距与 check-column 宽度两条自有规则，符合
  「表样式只处理自己」；enqueue 注释同步（list-tables 依赖只剩表基线）。
- **UiTest**：tablenav 断言族改 kit 类契约 + `one-page` 新契约；其余行为
  断言（跳页、每页、边界禁用、读数钳制）原样通过。
- **i18n**：UI Kit 页 listNav 描述一串更新（净 0），POT/PO 1154=1154 零差。
  **顺带实锤新坑**：POT 在挂载盘上重建被枚举截断少扫（1155→1145），改在
  容器原生盘副本上重扫归账（真源 1154），坑 7 已入 `aiya-i18n-zh-cn` 技能；
  phpcs 输出经 tail 截断误判绿（上轮 heading printf 漏网）同轮曝光，假绿
  谱系 #6 已入 `aiya-verification` 技能。门禁 680 tests / 2034 assertions；
  浏览器过 UI Kit 列表区（页 1/页 2、跳页、每页开关、批量条）与定时作业页。
  **同日追加拍板（底栏贴右 + 批量件同形态）**：基础条 `justify-content`
  收归 `flex-end`（底栏贴右，无操作位时无需占位零件，`--actions` 组合仍以
  space-between 压出左操作/右分页）；bulkTable 旧体（独立 `.aiya-core-bulk-bar`
  行 + 分离导航）退役——批量控件（action select、应用按钮、实时计数）进顶栏
  操作位，与列表屏同一行解剖，无 nav 时由同类的裸操作条承载；行为层提交按钮
  选择器改为表单根作用域（表单内唯已提交按钮），CSS 删 bulk-bar 规则。途中
  闭包漏 `use ($actions)` 被 phpstan/phpunit 双双抓获（array + null TypeError）。
  门禁 680 tests / 2036 assertions；浏览器过批量区勾选联动（应用解禁、计数回显、
  还原禁用）与底栏贴右。

## 十六、域逻辑归位轮（2026-10-05 站长拍板：域持逻辑与接口，Admin 只做组装）

> 依「Admin surfaces and the domain boundary (1.0.0 rulings)」的 L-01/L-02
> 口径对 Admin 页做逻辑归属复查：域→Admin 引用仅 DevToolsModule 装配器
> （豁免形态）、批量动作三件套与 Credits/Notification/Discussion 均走域服务
> ——合规；三处页面持运行逻辑的实锤本轮收口。

- **① SearchReplace 引擎**：`Domain/DevTools/SearchReplace` 承接全套——纯静态
  白名单与 SQL 构建器（sanitizeColumns/sanitizeTypes/statusesFor/escLike/
  buildWhere/buildUpdateSql）+ 存储操作（counts/samples/statement/
  executeAll，批量 REPLACE 循环与逐 id 清缓存内聚于此）。页面退化为表单、
  预览渲染（snippet 展示件留页）与 admin_post 往返，零 `$wpdb`。
  测试拆分：引擎契约入 `SearchReplaceTest`，snippet 留 `SearchReplacePageTest`。
- **② CronManagement 服务**：`Domain/DevTools/CronManagement` 承接事件词汇
  （flatten/parseEventId 纯静态 + cronArray/findEvent/scheduleLabel）与全部
  变更动作（schedule + hasListener 区分 no_listener/failed 两回执、run、
  unschedule、orphanCount、cleanupOrphans）。页面留列表组装、调度表单与四个
  admin_post 往返。`CronsPageTest` 迁为 `CronManagementTest`。
- **③ Avatar AJAX 处理器上移**：`Modules/AvatarAjaxModule`（新适配器）持有
  两个 wp_ajax 端点——超全局读取、edit_user 门与 nonce 校验、JSON 信封全在
  适配层；域内（AvatarModule）只余纯值入参的管线（storeUploadedAvatar/
  storeAvatar/removeAvatar，REST 路由同源复用），新增 `versionedUrl()` 供
  回执。L-02「传输知识止步域外」的字面违规清零；端点 action 名不变，客户端
  零感知。phpcs 教训：nonce 门禁与 `$_POST` 读必须同函数（嗅探器不跨方法追）。
- **验证**：682 tests / 2047 assertions 全绿；浏览器过定时作业（77 事件列表
  走新服务）、搜索替换（真实查询：11 处命中、5 行样例、语句预览与执行表单）、
  avatar 两端点钩子注册探针。i18n 零新串。

## 十九、repeater 交互重做（2026-10-05 站长拍板五点）

> 旧形态的病根：折叠 toggle 以 `flex:1` 覆盖整条 header bar——点选/拖选文字
> 都会触发折叠，交互状态怪异。本轮重排为「左折叠钮 + 标题 + 右操作簇」。

- **折叠钮最左、取消整区可点**：chevron 缩为最左的小图标钮（20px），标题改
  纯文本 span（`flex:1` 承担推右职责，不再进任何按钮）。
- **拖动与删除右移、去分隔线**：`dashicons-move` 与删除图标聚成
  `.aiya-core-repeater-actions`（margin-left:auto），handle 的 border-bottom
  移除（含折叠态透明化规则一并退役）。
- **删除图标化 + 双击确认**：删除钮换 `dashicons-trash`；首点在其右侧亮出
  「确认删除？」红字（`.aiya-core-repeater-confirm` hidden 属性切换），再点
  才移除。JS `removeItem` 两态化；模板克隆自带 hidden 初始态。
- **repeater 子字段 checkbox → 开关外观**：`renderSwitch()` 从 switch 分支
  抽取（含隐藏 untick 输入），子字段 checkbox 复用之（自带标签文本，免额外
  行标签）——`Enabled` 等子字段即开关。
- **既有条目折叠、新建展开**：维持既有 PHP 语义（存量行 collapsed、模板行
  expanded），浏览器复测通过。i18n +1 串（确认删除？）；四门禁 683/2053
  全绿；浏览器过折叠钮切换、title 不可点、双击删除与开关子字段。
- **去 WP 化扫尾（同日复检）**：条目类不再骑 `postbox`（竖线、hover 选中
  态、内边距全部来自该壳），删除/折叠钮不再用 `button-link`（其
  `text-decoration: underline` 即 trash 图标下横线的来源，主题色 hover 同
  出此门）——两者改纯 kit 类并自带基线样式（无边框无底色、悬停仅图标加深
  `#1d2327`），条目自带 `1px #dcdcde` 圆角白底。浏览器实测：无下划线、无
  hover 染色、无分隔线。
- **kit 健壮性专审（同日，视觉外全量核对）**：PHP 侧逐组件核对转义面
  （notice/heading/card/flash/listNav/listTable/button/input/select/
  filterBar/userPicker/tabs/chart/copyText/bulkTable/modal——esc_html/
  esc_attr/wp_kses_post/白名单全数在位，UiTest 18 条契约覆盖）；JS 侧
  HTML 汇点穷举仅四处（media 预览为 jQuery 元素构造、repeater 模板为服务端
  转义克隆、建议项走 `.text()`、无其他 innerHTML），typeahead 回填走
  `.val()/.text()`，jump/per_page 导航走 `URLSearchParams` 自动编码且
  parseInt 守门，tabs hash 仅匹配已存在 panel id。本轮新面另核：搜索替换
  引擎（列/类型/状态三层白名单 + prepare 占位 + escLike 通配转义，原始
  针体只在 esc_html/esc_attr 后现身）、cron 事件 id（解析后强制对活数组
  三元组核验）、图床复制负载（整包 esc_attr 进 data 属性）。**无 XSS/
  注入发现。**确认文本改注入按钮内部（trash 右侧同按钮），删除
  红色（#d63638，hover 加深）归还删除图标——原灰色系是去 WP 化时的过度收敛。

## 十八、卡片零件迭代（2026-10-05 站长拍板：静态变体 + 卡体内边距）

- **`Ui::staticCard()`**：与 `card()` 同壳、无切换语义的非折叠卡片
  （`div.aiya-core-card--static`，表头无 dashicon、分隔线常显、非指针）——
  供常显内容（上传表单等操作面）使用；图床上传区已换装。summary 由元素
  选择器改为 `.aiya-core-card__summary` 类选择器，两变体共享排版。
- **卡体内边距**：`__body` 原 `padding-top: 0`（内容贴死表头横线），改
  `12px 14px 14px`；随之退役「卡内表格补 12px 顶距」的补偿规则。
  演示页卡片节展示双变体（描述串更新，+2 新串 −1 退役）。UiTest 增
  staticCard 契约。683 tests / 2053 assertions 全绿；浏览器过图床静态卡
  （12px 顶距实测）与演示页双变体。

## 十七、Dev Tools 双因子门禁（2026-10-05 站长拍板：WP_DEBUG ∧ 管理员）

> 原门禁只有 WP_DEBUG 一因子；WP_DEBUG 开启时，域内各页其实已各自收
> `manage_options`，但那是分散契约——本轮把「管理员」升格为域级单点门禁，
> 非管理员对整个域隐身，与任何单页能力声明漂移无关。

- **落点与时机**：门禁骑 `aiya_core_register` 总线（init 优先级 0）——此刻当前
  用户已装载，而 `register()` 本体在插件包含期执行、彼时 pluggable.php 尚未
  存在（字面 `current_user_can` 会 fatal）。非管理员由此**不注册 admin_post/
  ajax 端点、不入注册表**：菜单零渲染、直连 URL 落核心 403、端点未挂钩。
  SamplePage 自挂总线的钩子改为门内直调 `settings()`（总线 mid-fire 期间
  追加钩子不可靠），其 WP_DEBUG 再查保留为纵深防御。
- **时机考证**（wp-settings.php 实序）：cookie 常量 555 行、pluggable 612 行、
  plugins_loaded 630 行、init 779 行——init 时刻用户可用性成立。
- **三态实测**：管理员 + WP_DEBUG 正路径照常（菜单 10 链接、搜索替换渲染）；
  订阅者探针（curl 邮箱登录）直连页 **403**、仪表盘 **0 个 devtools 链接**；
  无用户上下文（wp-cli）注册表 **0 页 devtools**。门禁 682 tests / 2047
  assertions 全绿；i18n 零新串。
