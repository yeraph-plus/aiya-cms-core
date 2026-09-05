# aiya/image-processor

图片处理原语包（WordPress-free）：基于 `imagine/imagine` 的水印叠加、缩略图生成与文章封面绘制。

## 边界

- 包内不调用任何 WP 函数、不挂任何钩子；依赖方向由 core 侧适配器单向接入。
- 输入输出全部是**本地绝对路径 + 数值参数**；URL↔路径解析、目录规划、设置读取都是调用方（`src/Modules/MediaModule.php` 适配器）的职责。
- Imagine 驱动通过构造器注入：接受 `ImagineInterface` 实例或返回它的 `Closure`（惰性解析，构造不触发驱动加载）。
- 包自带素材：`assets/font/`（封面标题与文字水印字体）与 `assets/pattern/`（pattern 封面随机花纹素材），经 `Assets` 提供路径。

## 语义约定

- **opacity 统一为「不透明度」**：`0 = 全透明，100 = 不透明`，与 Imagine 的 alpha 通道约定一致（`Color\RGB::isOpaque()` 即 `alpha === 100`）。旧主题里设置文案（100=全透明）与代码语义相反的缺陷不再保留。
- 缩略图缓存键由调用方拼装（源路径 + 尺寸 + 格式 + 质量），包内不做缓存决策；旧版 `fingerprint()` 预留方法已删除——参数进键，不进指纹黑盒。
- 封面目标路径由调用方给定（每次生成都落新文件）；包内只负责绘制与保存。

## 结构

| 类 | 职责 |
|---|---|
| `ImagineFactory` | imagick → gd 驱动选择（适配器构造注入用） |
| `ImagineAware` | `ImagineInterface|Closure` 惰性解析基类 |
| `SaveOptions` | 格式 + 质量 → Imagine 保存参数；缺省参数合并 |
| `WatermarkSpec` | 水印参数模型（off/image/text，九宫格位置） |
| `CoverSpec` | 封面参数模型（photo/pattern 两模型） |
| `ThumbnailGenerator` | cover-crop-center 缩略图；比例差过大时模糊底 + 等比前景双层渲染 |
| `CropGenerator` | 无合成的纯居中裁剪缩放（头像等固定尺寸资产），总是覆写目标文件 |
| `CoverGenerator` | photo（背景图+蒙版+标题）/ pattern（纯色+花纹+标题）封面绘制 |
| `UploadApplier` | 上传管道：限宽缩放 → 水印 → 格式转换保存（转换后删源文件） |
| `FirstImageMatcher` | 纯正则提取 HTML 内容中第一张图片 URL |
| `Colors` | 十六进制色解析 / 标准化 / 亮度 |
| `Assets` | 包内置字体与花纹素材路径 |
