# packages/ — 基础设施包目录

替代旧主题 `plugins/` require 加载结构的统一包目录。本目录内每个子目录是一个独立 composer 包（`aiya/<slug>`），但**不随 composer 安装**：根 `composer.json` 没有 path repository，`vendor/aiya` 不存在，Composer 的 autoload 映射里也没有它们。插件自动加载器（`src/Runtime/Packages.php` + `aiya-core.php` 注册的 spl_autoload）在首次请求 `Aiya\Infra\*` 类时惰性读取各包自己的 `composer.json`（`autoload.psr-4`，仅认 `Aiya\Infra\` 前缀）并按需 require 文件；`tests/bootstrap.php` 镜像同一对加载器。

## 包约定

- 包名 `aiya/<slug>`，`type: library`，PSR-4 命名空间 `Aiya\Infra\<CamelName>\`
- **包不得反向依赖 aiya-core**：不 require `aiya/aiya-core`、不调用 WP 函数、不挂 WP 钩子；依赖倒置由 core 侧 `src/Modules/<Name>Module.php` 适配器完成——适配器实例化包服务、把包的配置字段注册进**该功能自己的设置页**（旧 extra-plugin 单页分区结构不继承），并挂入 `Contracts/Module` 系统
- **包→包依赖允许**（图无环、不声明在 `require` 里）：`payment-afdian` → `slug-toolkit` 是唯一实例。**后端接缝不走包**：包只承载「外部请求出口」（协议、签名、错误分级、把结果摊成纯数组），站内词汇表（DTO、行结构、适配器接口）留在 core —— 这样既不需要 provider 包反向依赖 core，也不需要第二个包只为放接口而存在（0.90.0 撤掉了 `file-source` 正是这个原因）
- 包自带 `composer.json` 作为**自身描述**（`php >= 8.4` + 自身三方依赖），但三方依赖声明在**根** `composer.json`（腾讯镜像解析），包目录内不安装 vendor、不产生 vendor/aiya 符号链接
- 新增一个包的代价因此是显式的：投放目录（自动被发现）+ 在根 `composer.json` 声明它的三方依赖；接线后再把它加进根 `phpstan.neon.dist` / `phpcs.xml.dist` 的 paths
- 包自带的 PHPStan / PHPCS 检查在它被接线后纳入根配置；未接线前，PHPStan 经 `scanDirectories` 做**符号发现**（不然找不到 `Aiya\Infra\*`）但不分析，PHPCS 不扫（typesetting 的巨型数组会撑爆嗅探器内存）
- 切片原则不变：只迁移正在真实使用的包，不做空壳预留

## 迁移批次（对照旧主题 plugins/，随落地更新）

1. **第一批**（近零耦合，直接迁移）：`opencc-convert`（首个 tracer 包，已建，待接入适配器）、`multi-domain`；`internal-pic-bed` **不做包**——已按功能直接做成 core 内页面（0.9.0 `src/Admin/PicBedPage.php`，0.9.1 起为主菜单项；图片处理管线以闭包注入，不引包依赖）
2. **第二批**（需替换设置读取层 `aya_plugin_opt`）：`image-manager` ✅ 0.9.0 → `aiya/image-processor`（imagine/imagine 随包，字体与花纹素材随包自带）；`classic-editor-modify`
3. **basic-optimize** 的组件不改造成包，直接变成 core 的 Domain/Infrastructure 模块（安全、SMTP、SEO、头像等各归其位）
4. **最后**：`sponsor-order-compat`、`patch-flow-hub-post`（深度耦合主题支付/模板体系，重写而非迁移）；`gdluxx-dl` 为空目录，弃

## 当前包

| 包 | 状态 |
|---|---|
| `opencc-convert` | 包体就绪（Converter + locale 策略映射）；等 `src/Modules/OpenCcModule.php` 适配器接线，届时在根 `composer.json` 补 `overtrue/php-opencc`（当前刻意不 require，包零消费） |
| `slug-toolkit` | ✅ 0.6.0 已接线（三方依赖 `overtrue/pinyin` 在根 require）：`PinyinConverter`（overtrue/pinyin 基础调用，无策略）+ `IdSlugEncoder`（继承冻结算法 `XDE_code`，输出与旧站逐字节一致）；消费方为 `Domain/Content/SlugModule` |
| `gofile-api` | ✅ 0.91.0 已接线（零三方依赖）：GoFile **只读请求出口** —— `Client`（GET-only、`{status, data}` 信封、平台状态串分级成包内 `Error`，含 `error-notPremium`/`error-wrongToken` 两个实测确认的状态）+ `Gateway`（`account()` 查令牌归属与档位、`accountDetails()`、`contents()` 读文件夹、`search()` 递归搜索 → 纯数组行）；管理端点（创建/更新/删除/移动/复制/直链/重置令牌）与上传**刻意不移植**；消费方为 `Modules/GofileModule`（薄适配器：设置读取 / wp_remote transport）+ `Domain/FileServe/Adapters/GofileAdapter` |
| `image-processor` | ✅ 0.9.0 已接线（三方依赖 `imagine/imagine` 在根 require）：`WatermarkSpec`/`CoverSpec`/`ThumbnailGenerator`/`CoverGenerator`/`UploadApplier`/`CropGenerator`（Imagine 能力经 `ImagineAware` 闭包惰性注入）+ `FirstImageMatcher`/`SaveOptions`/`Colors` 纯工具 + `Assets` 自带字体与花纹素材；消费方为 `Modules/MediaModule` 与 `Domain/Identity/AvatarModule`（详见包内 README 的语义约定） |
| `payment-epay` | ✅ 0.88.0 已接线（零三方依赖，纯 PHP `md5`）：`Client`（submit 参数签名 + 回调验签，算法与旧 SDK 逐字节一致，含「值为 `'0'` 跳过」的怪癖）+ `Gateway`（支付参数组装、回调验签与归一化、`epc_` 前缀、binding 编解码）；消费方为 `Domain/Sponsorship/EpayGateway`（薄适配器，拥有设置读取 / notify URL / WP_Error 与文案） |
| `payment-afdian` | ✅ 0.88.0 已接线（`ext-openssl`）：`Client`（open-API md5 出站签名、RSA 入站验签、内置平台公钥、transport 闭包）+ `Gateway`（订单深链、回调形态与归一化、`afd_` 前缀、周期钳制）；依赖同仓包 `slug-toolkit`（包→包，`require` 不声明）；消费方为 `Domain/Sponsorship/AfdianGateway`（薄适配器）+ `AfdianActivator`（留在核心，它要写钱与权益） |
| `typesetting` | ✅ 0.37.0 已接线（零三方依赖，仅 `ext-json`）：`ChineseTypesetting`（jxlwqq/chinese-typesetting 原样移植）；消费方为 `Modules/TypographyModule` |
| `openlist` | ✅ 0.90.0 重写为**纯请求出口**（零三方依赖）：`Client`（登录 + fs 读操作，transport 闭包注入，平台错误分级成包内 `Error`）+ `Gateway`（`list()` / `search()` 两个 surfacing 调用 → 纯数组行：name / kind / size / modified / path / url；链接本地拼接 f·d·p，不留 `raw_url` 的逐文件请求）；**不与 core 共享任何类型**；消费方为 `Modules/OpenListModule`（薄适配器：设置读取 / wp_remote transport / token 缓存）与 `Domain/FileServe/Adapters/OpenListAdapter`（映射成站内 `Entry`） |
