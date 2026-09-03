# packages/ — 基础设施包目录

替代旧主题 `plugins/` require 加载结构的统一 composer 包目录。本目录内每个子目录是一个独立 composer 包，由根 `composer.json` 的 path repository（`packages/*`）索引。

## 包约定

- 包名 `aiya/<slug>`，`type: library`，PSR-4 命名空间 `Aiya\Infra\<CamelName>\`
- **包不得反向依赖 aiya-core**：不 require `aiya/aiya-core`、不调用 WP 函数、不挂 WP 钩子；依赖倒置由 core 侧 `src/Modules/<Name>Module.php` 适配器完成——适配器实例化包服务、把包的配置字段注册进统一的「拓展功能」设置页（`aiya_core_addons`），并挂入 `Contracts/Module` 系统
- 包自带 `composer.json`（`php >= 8.2` + 自身三方依赖）；三方依赖在根仓库解析（腾讯镜像），包目录内不安装 vendor
- 包自带的 PHPStan / PHPCS 检查在它被根 require 后纳入根配置；未纳入前不进入根 `phpstan.neon.dist` 与 `phpcs.xml.dist` 的 paths
- 切片原则不变：只迁移正在真实使用的包，不做空壳预留

## 迁移批次（对照旧主题 plugins/）

1. **第一批**（近零耦合，直接迁移）：`opencc-convert`（首个 tracer 包，已建，待接入适配器）、`multi-domain`、`internal-pic-bed`
2. **第二批**（需替换设置读取层 `aya_plugin_opt`）：`classic-editor-modify`、`image-manager`（imagine/imagine 随包迁移）
3. **basic-optimize** 的组件不改造成包，直接变成 core 的 Domain/Infrastructure 模块（安全、SMTP、SEO、头像等各归其位）
4. **最后**：`sponsor-order-compat`、`patch-flow-hub-post`（深度耦合主题支付/模板体系，重写而非迁移）；`gdluxx-dl` 为空目录，弃

## 当前包

| 包 | 状态 |
|---|---|
| `opencc-convert` | 包体就绪（Converter + locale 策略映射）；等 `src/Modules/OpenCcModule.php` 适配器接入根 require |
| `slug-toolkit` | ✅ 0.6.0 已接入根 require：`PinyinConverter`（overtrue/pinyin 基础调用，无策略）+ `IdSlugEncoder`（继承冻结算法 `XDE_code`，输出与旧站逐字节一致）；消费方为 `Domain/Content/SlugModule` |
