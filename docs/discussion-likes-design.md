# 轻社区点赞表设计稿（discussion-likes，2026-10-03 登记）

> 状态：**已实施（0.102.0，2026-10-03）**——DL-1～DL-4 全部落地；实施中的增量拍板：线程删除时同步 `purgeForThread()` 清 likes 行（防孤儿，设计稿漏项、实施时补上）。本稿是 1.0 发布门审查（docs/REVIEW-1.0.md）确认的「SQL 表形压平缺口」的最后一项：社区互动计数目前唯一落在文章数据域（post meta `like_count`/`view_count`），社区线程自身没有关系化的互动承载。站长拍板重启轻社区点赞，**独立内容 CRUD、自建表，不跟随文章数据域**。

## 〇、拍板记录

| 时间 | 拍板 |
|---|---|
| 2026-09-09 | 社区点赞**取消**（B2 定基线：CounterService 不扩线程键源，post-meta 方案套不进线程模型） |
| 2026-10-04 | ROADMAP 遗留登记：「轻社区点赞（需自建表计数机制，post-meta 方案套不进）——列入计划本期不做」 |
| **2026-10-03（本稿，取代上两条）** | **重启轻社区点赞**：自建 `aiya_discussion_likes` 表承载独立 CRUD，计数物化进线程表；前后端分工=后端全域接线、前端按约定**只写 zod**（契约镜像 + 快照同步），UI 留待前端批次 |

## 一、范围与语义（拍板点）

1. **仅主题可点赞，回复不可**。轻语义：一张表、一个计数列、零新增通知方向。回复点赞如未来需要，加 `reply_id` 维度是加法演进（新唯一键 + 回复表计数列），本期不做。
2. **登录-only**（对齐 2026-09-17 互动写登录-only 拍板；游客按钮本就禁用）。
3. **幂等**：重复 like 是 no-op（`already` 语义），unlike 对不存在的行是 no-op； unlike 永远允许（不因线程转 closed 而锁定已点用户的状态修正）。
4. **状态门**：`open` 线程可点赞；`closed` 线程禁止新点赞（归档态，与锁回复一致），unlike 不受限。
5. **无通知方向**：点赞不产生通知（轻社交，避免噪音；通知 TYPES 不扩）。
6. **不触碰文章数据域**：不读写 post meta、不复用 CounterService 的键源与预算（其去重 transient 语义是访客向的，与关系表语义不同源）；不接入 hot/related 加权。
7. **计数物化**：`aiya_discussions.like_count` 列，由 LikeService 单点维护（对齐 `syncReplyStats` 的单写者模式）；行表是事实源，计数列是投影。

## 二、SQL（压平链收尾，1.0.0 盖章前的最后窗口）

**新表**（并入讨论域的 1.0.0 安装器条目，成为其第四张表；迁移链条目数不变）：

```sql
CREATE TABLE {prefix}aiya_discussion_likes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    thread_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY actor (thread_id, user_id)
) {charset};
```

- 索引纪律（对齐审查 S 线结论）：唯一键 `(thread_id, user_id)` 同时服务去重与「按线程计数」（索引内聚合）；**不建投机索引**——`user_id` 维度（我的点赞列表）出现真实消费方时再加 `(user_id, created_at)`。
- **threads 表加列**：`like_count BIGINT UNSIGNED NOT NULL DEFAULT 0`（dbDelta 加法，随讨论安装器 DDL 一并演进；存量线程归零、无回填——点赞历史本就不存在）。
- **迁移登记**：新表安装器与 discussions DDL 演进并入 **1.0.0 版本号的链**（pre-1.0 期并入压平链，不落新版本号；`MigrationChainTest` 的「chain stays flat at one version」断言与安装器计数断言同步）。开发库（stored=插件版本 <1.0.0）在下次版本变动时自动对账；线上库在 1.0.0 首请求拿到终态 DDL。
- **uninstall**：`aiya_discussion_likes` 进 DROP 清单（uninstall.php 表清单与安装器逐一对应的纪律不变）；`like_count` 随 threads 表 DROP 自然消失。

## 三、后端接线

**服务**：`Domain/Discussion/DiscussionLikeService`
- `like(int $threadId, int $userId): array{likes: int, viewerLiked: true, already: bool}` —— 事务内 `INSERT IGNORE` + 按影响行数 `UPDATE threads SET like_count = like_count + 1`（0 行插入则跳过计数，天然幂等）。
- `unlike(int $threadId, int $userId): array{likes: int, viewerLiked: false}` —— DELETE + 按影响行数递减（`GREATEST(like_count - 1, 0)` 兜底）。
- `counts(array $threadIds): array<int, int>`、`likedThreadIds(int $viewerId, array $threadIds): array<int, true>` —— 列表批量预取，杜绝逐行查询（对齐审查 C-04 的 cache_users 范式）。
- 前置校验：线程存在、`status = 'open'`（like 方向）；unlike 无状态门。

**Presenter**：`DiscussionPresenter`
- `Discussion` DTO 增 `likes`（计数）与 `viewerLiked`（当前读者态）。
- 列表：一次 `counts()` + 一次 `likedThreadIds()` 预取；游客 `viewerLiked` 恒 `false`（对齐 0.100.0 `viewerLiked` 的游客语义——契约面恒有值，不存在 null 分支）。
- 详情：同一路径（detail 复用 summary 的装配）。

**REST**（`DiscussionController`，命名空间 `aiya/core/v1`）：
- `POST /discussions/(?P<id>\d+)/like`（CREATABLE）与 `DELETE /discussions/{id}/like`（DELETABLE）。
- `permission_callback => '__return_true'` + handler 内登录门（照 CounterController 模式，REST 面统一 R-03 RestGuard 落地后走同一入口）；404=线程不存在，409 语义并入 WP_Error `aiya_thread_closed`。
- 响应信封 `{data: {likes, viewerLiked}}`。
- 限流：RateLimiter 新 bucket `discussion_like`，预算对齐互动写面（实施时取 CounterController like 的同档数值）。

## 四、契约（v1 冻结下的加法演进）

- `Discussion` DTO 尾部加两个带默认值的构造参数：`public readonly int $likes = 0`、`public readonly bool $viewerLiked = false`（既有调用点零破坏；`DiscussionDetail` 经 `array_merge` 自动携带）。
- **docblock 同步**：`Discussion.php:16-17` 现写「Community likes were dropped by decision — the reply count is the only interaction metric」——改写为双指标（replies + likes）语义，契约文档是执法基准，不允许与代码漂移。
- 快照：`wp aiya contracts snapshot` 重生成 → front-station `src/lib/core/contracts.snapshot.v1.json` 同步比对零 diff（字段加法，v1 基线允许）。

## 五、前端（按约定只写 zod）

- `front-station/src/lib/community.ts`：线程 schema 加 `likes: z.number().int().nonnegative().default(0)`、`viewerLiked: z.boolean().default(false)`（默认值保证旧快照载荷可读）。
- `contracts.snapshot.v1.json` 同步 + vitest 契约测试跑绿。
- **不做任何 UI**：岛屿消费（按钮 pressed 态、计数展示）留待前端批次，届时按 0.100.0 互动回显批的 initial-state 范式接线。

## 六、测试与守卫

| 层 | 用例 |
|---|---|
| 迁移 | `MigrationChainTest`：安装器计数 +1、全条目仍钉 1.0.0、新表 DDL 断言 |
| 服务 | `DiscussionLikeServiceTest`：幂等 like（二次 already 且计数不涨）、absent unlike、closed 线程拒 like 不拒 unlike、计数列与行表一致、批量 counts/likedThreadIds |
| REST | 登录门 403 形状、404、closed 409、响应 `{likes, viewerLiked}`、限流 429 |
| 契约 | Presenter 装配断言（列表批量预取只发两条 SQL）+ 快照重生成零 diff |
| 前端 | vitest zod 镜像 + snapshot 基线比对 |

## 七、批次计划（独立批次 DL，排 B2 之后、B6 发布之前）

> 与 B2 同动 `DiscussionService` 安装器与 `MigrationChainTest`，顺序执行避免自冲突；不与邮件域文件面相交。

| 步骤 | 内容 | 验证 |
|---|---|---|
| DL-1 | DDL 两条目（新表安装器 + discussions 加列）+ uninstall 清单 + MigrationChainTest | 原生盘全量 + 开发库版本变动自愈核对（`SHOW INDEX/COLUMNS`） |
| DL-2 | `DiscussionLikeService` + Presenter 装配 | 服务单测（上表） |
| DL-3 | REST 两路由 + 登录门 + 限流 bucket | 控制器单测 + 真链路 curl（like→重复 like→unlike→列表 viewerLiked） |
| DL-4 | 契约 docblock 改写 + 快照重生成 + front-station zod/快照同步 | 快照零 diff + vitest + `composer test:native` + phpstan + phpcs |

## 八、开放项（实施前无需裁决，登记备查）

- 「我的点赞」列表页（`user_id` 维度索引的潜在消费方）——本期无此页面，索引不建。
- 点赞接入热门加权——hot 是文章域的互动窗趋势榜，社区排序维持 `bumped_at` 活动戳，不混源。
