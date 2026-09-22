# 积分账本与会员域重构计划

状态：**全部完成**——第一期积分域（0.47.0–0.49.0）、第二期会员域 tier 重写（0.50.0，含 `SPONSORSHIP_ENABLED` 重新启用）均已落地。拍板日期 2026-09-13。本文保留作为实施记录与决策依据。

## 0. 拍板记录（2026-09-13）

1. **会员资格从"通过门"改为"积分记账"**：会员不再直接控制用户行为，外部下载等付费行为的关联方是积分账本，不是会员权限门。会员订单只负责生产余额。
2. **积分不是游戏系统，是成本计量**：无回复加分等互动奖励。记账结构采用**桶设计**（bucket ledger）：发放即建桶，扣减按过期时间先进先出（FIFO），所有积分**非永久存款**——签到、会员、兑换码发放的桶一律带过期时间。
3. **计量点 = 付费行为按钮**：用户点击付费行为（本期即外部文件下载按钮）时扣积分、返回下载直链。**不做严格审计**：链接失效、直链重复使用等问题交给外部系统（OpenList）或之后的外部下载支持自行实现。
4. **会员域整体重构为 tier 周期模式**（类 Patreon）：可设多个档位，按月（周期）生效；允许购买多个周期或档位，**按购买次序顺序生效**；积分按周期逐期发放——除非特意配置一个长周期档位（如 300 天档位），否则买一年不得一次性发放全部积分。
5. **清理 usermeta 会员键**：`sponsor_expiration` / `aya_force_cancel_sponsor` / `aya_trigger_count_sponsor` 退役，会员状态改由自建表管理（存量行当死数据，站点未上线，无迁移义务）。
6. **兑换码直发积分账本**：码不再兑换会员天数，改为直接发放积分桶；**取消前缀词编辑**（生成逻辑与后台 UI 同步去掉 prefix）。
7. **分期实施**：先做积分域（第一期），会员域重构独立成批（第二期）。这不是妥协——第一期不依赖会员域的任何改动，两期沿"发放钩子"这条线干净解耦。
8. **（第一期实施时拍板）`sponsor_can` 不继承，直接删除**：OpenList 盒子不再有"仅赞助者"开关，门禁矩阵、响应字段 `gated`/`canSeeLinks`、box 字段一并移除；下载链接回归"登录可见、游客裁剪"的唯一规则。付费下载的计量端点**延后另行设计**——是否以积分领取、如何与盒子配置关联，第二期之后再评估；期间外部文件下载免费（登录即可）。
9. **（0.48.0 实施时拍板）积分域 = 纯记账**：不持有任何业务报价——下载单价设置移除，下游（未来的付费下载等）调用 `spend(amount, source, ref)` 自带数额，积分域只回答成功/失败；账本保留期属运维，移前台页与通知保留期同区（`credit_retention`）；积分有效期语义收窄为签到专属，并入「每日签到」组；后台挂新一级菜单「会员」（后续会员域/支付/兑换码为其子页），积分屏改轻社区同款折叠卡片（签到设置/手动发放），用户列表加余额列。
10. **（0.49.0 实施时拍板）兑换码提前接回积分域**：§3.5 不等第二期，随 0.49.0 落地——码直发积分桶（credits/valid_days 两列、去前缀词）、核销端点挂 `/credits/redeem`、后台兑换码页挂「会员」入口；流水查询从折叠卡改为页面直排（页面主浏览面）。
11. **（0.50.0 实施时拍板）会员域干净重写，爱发电只留 SDK**：旧网关接线不做兼容（param 三段式 `binding|tierKey|cycles`，旧两段式不解析）；爱发电 SDK 类（AfdianClient）保留但全链路不接线（无 webhook 路由、无 order-url 端点、无设置字段），后续需要时按 tier 模型重接；`SPONSORSHIP_ENABLED` 开关随重写摘除（域常开），`EXTERNAL_FILES_ENABLED` 保留。
12. **（0.51.0 修补拍板）积分账本语义三处收敛**（按站长业务流程复述逐条对照后的拍板；**本轮只闭合积分侧，会员域逻辑不动**——会员 cron 的发放防重行为不变，仅其账本行的幂等载体随 B 项被动迁移）：
    - **FIFO 排序**：`spend()` 排序补 `expires_at IS NULL` 靠后——永不过期的桶是最后才烧的储备，不得先于任何有到期时间的桶被消耗（MariaDB 升序 NULL 在前，实测已坐实反序）。
    - **幂等键与去向标记分离**：账本是 **API 式预存扣费**，不是积分商品交易——用户点一次下载扣一次，账本不关心下载本身，只保证"一次创建不扣两次"级别的唯一检查。现 `UNIQUE(source, ref, user_id)` 把 out 行也当幂等键，同一文件第二次消费会被 409 拒绝，等于 schema 层替业务拍死了按次计费。改为独立可空列 `dedupe`：**in 行**写入推导幂等值参与唯一约束（签到 `checkin:{date}`、兑换码 `code:{码值}`、会员 `membership:{orderId}#c{k}`——会员值由账本层推导生成，会员域代码零改动），**out 行**留 NULL（MySQL 唯一键对 NULL 不去重）——按次计费天然放行；`ref` 降级为纯去向/来源标记（人类可读，不上唯一约束）。手动发放随之回归有意义 ref、去掉时间戳随机后缀体操。
    - **清理保留期统一**：过期未耗桶从"次日即删"并入与扣空桶/消费行相同的 `credit_retention` 保留期——同属"已关闭的历史"，用户查"积分何时过期作废"的记录不再一夜蒸发；活桶（未过期且有剩余）永不触碰。
    - **明确不做**：冲正/退款原语（不计划）；`/credits/entries` 筛选参数（混合记录可接受，有需要再补）。

## 0a. 0.51.0 修补计划（积分账本语义收敛批）

**实施记录（已落地，随 0.53.0 批次，迁移版本 0.51.0）**：三处全部落地并按验收口径实测——① FIFO `ORDER BY expires_at IS NULL ASC, expires_at ASC, id ASC`（null 桶 10/明日桶 7，永不过期桶最后烧）；② `dedupe VARCHAR(80) DEFAULT NULL` + `UNIQUE KEY dedupe_key (dedupe, user_id)` 替换旧三列键（迁移幂等实测；实施中修正了初版"回填先于加列"的顺序缺陷——先 `ADD COLUMN` 再回填），grant 行推导 `source:ref`、spend 行默认 NULL 按次放行，`spend()` 增可选 `?string $dedupe` 形参供未来一次性领取令牌；管理页手动发放去时间戳随机后缀、备注即 ref、重复备注 409 带专有提示；③ 过期桶并入 `credit_retention` 保留期（过期行保留期内可查，活桶不触碰）。会员域零改动验证：`grant(uid, 100, 'membership', 'epc_x#c1', …)` 原签名调用，同周期第二次 409——防重由账本层推导键承接。i18n 1 条新串落地。

**批次版本 0.51.0**。范围硬边界：**只动 `Domain/Credit`（账本）+ 一个 schema 迁移 + 管理页手动发放一处**；`Domain/Sponsorship`（会员域）代码零改动——EntitlementService 的调用签名、`ref=orderId#c{k}` 标记、发放防重语义全部维持现状，其幂等载体随迁移在账本层内部完成切换。前端契约不变（`ref`/`source`/方向字段均在，只改行为不改形状）。

### A. FIFO 修复（§12 第 1 条）

- `LedgerService::spend()` 的桶查询排序：`ORDER BY expires_at IS NULL ASC, expires_at ASC, id ASC`——NULL 排最后，同到期时间按入账先后。
- 单测：`CreditAllocator` 不涉及排序（调用方职责），补 `LedgerService` 级行为到运行时验证步骤（null 桶 vs 明日桶，扣减必须烧明日桶）；若 wpdb 垫片可测则落一条集成式单测。

### B. dedupe 列迁移 + 读写改造（§12 第 2 条，核心刀）

**迁移（SchemaVersionRunner，版本随批次）**：`LedgerService::installTable()` 增列 `dedupe VARCHAR(80) DEFAULT NULL` + `UNIQUE KEY dedupe_key (dedupe, user_id)` 替换 `UNIQUE KEY dedupe (source, ref, user_id)`；存量行回填——in 行 `dedupe = CONCAT(source, ':', ref)`，out 行留 NULL（未上线，无真实历史包袱，回填只为幂等键在迁移期间保持连续）。dbDelta 不做唯一键替换/改名，迁移回调用 `SHOW COLUMNS`/`SHOW INDEX` 探测后执行 `ALTER TABLE`（幂等：键已存在即跳过）。

**服务层**：
- `grant()` 签名不变；内部 `dedupe = in ? "$source:$ref" : null`（grant 恒为 in 行）——消费方（签到/兑换码/会员 cron）零改动，错误码 `aiya_credit_duplicate` 语义不变。
- `spend()` 的 out 行 `dedupe = null`，重复 ref 不再撞键；`aiya_credit_duplicate` 分支保留（未来若有按单扣费场景可传 dedupe 值启用幂等）——`spend()` 增可选第 5 参 `?string $dedupe = null`，传了才参与唯一约束，默认 null 不约束。
- 管理页 `CreditsPage::handleGrant()`：去掉 `gmdate().'-'.wp_generate_password()` 后缀，`ref` 直接用备注（空则 `admin`）。
- 会员域不改：cron 继续调 `grant($uid, $credits, SOURCE_MEMBERSHIP, $orderId#c{k}, $endsAt)`，签名与行为原样；防重从旧 (source,ref,user) 键切到 dedupe 键是账本内部推导（`membership:{orderId}#c{k}`），对调用方透明。

### C. 保留期统一（§12 第 3 条）

- `pruneExpired()`：过期桶删除条件由 `expires_at <= now` 改为 `expires_at <= now - retention`；扣空桶与 out 行维持现状；三类共用同一 `credit_retention`。
- 单测：wpdb 垫片覆盖三个 WHERE 分支的 SQL 形状（沿 ShortcodesPage/PostMetaStore 测试的 SQL 断言风格）。

### D. 明确不做（拍板记录 §12 第 4 条）

冲正原语；entries 筛选参数；下载调度侧机制（取真链接的重复点击防抖归下载调度，账本只认扣减请求）。

### 验收口径

1. FIFO：null 桶 + 明日桶各 10，spend 3 → 明日桶 7/null 桶 10。
2. 按次计费：同 ref 连续两次 spend 都成功（第二次余额继续减少），不再 409。
3. 幂等保留：同日第二次签到仍 409；会员域零改动前提下，对同一周期重复 advance 不双发（账本 dedupe 键承接，会员代码未动）。
4. 保留期：过期桶在 retention 窗口内可查、窗口后消失；活桶任何时刻不被清理。
5. 门禁全绿 + i18n 零缺失；迁移在现库实测（列/键替换 + 回填 + 重复执行幂等）。

## 0b. 合并推断实施计划（账本基础设施定稿 + 下载域解耦预备）

站长合并复述四段业务流程（2026-09-13），逐条对照已落地实现后的增量拍板。**本节是账本层的最终语义定稿**；§1/§2 历史描述与此处冲突时以本节为准。

### 四段对照结论

| 站长描述 | 现状 | 缺口 |
|---|---|---|
| 1. 账本：用户余额/消费查询 + 内部增/扣函数 + 来源/去向登记 | ✅ `GET /credits/balance`、`GET /credits/entries`；`grant()`/`spend()`；`source`+`ref` | 无 |
| 2. 签到新增一行；扣除取最早条目、**先到期先扣（包括通过兑换码发放的永久值）** | 签到 ✅；FIFO ✅（0.51.0 起 NULL 靠后）；**兑换码桶强制 `valid_days` 过期，无永久值形态** | **兑换码发放支持永久积分**（本批 A） |
| 3. 计划任务：扣到 0 的条目与消费记录一起到期清理 | ✅ 0.51.0 起三类历史（过期桶/扣空桶/out 行）统一 `credit_retention` 保留期 | 无（描述与 0.51.0 语义一致） |
| 4. API 式预存扣费；积分只是记账基础设施，实际业务流在外部下载实现内；外部服务后期可能扩功能，**拆独立域不耦合** | 账本已是纯记账（`spend()` 自带数额只答成败）；但下载业务流尚无归宿 | **确立下载域解耦方向**（本批 B，非账本批实现） |

### A. 兑换码永久值（账本批内实现，迁移版本随批次）

- **语义**：`valid_days = 0` = 永不过期（`grant()` 的 `expiresAt = null` 形态，FIFO 中天然最后扣）；`valid_days >= 1` 维持现状限时桶。后台生成表单描述同步（0 = 永久）；流水 `expiresAt: null` 已是契约合法形状（CreditEntry.expiresAt 可空），**契约零变化**。
- **改动面**：`RedeemCodeService`——生成 `generate(quantity, credits, validDays)` 去掉 `max(1,…)` 钳制改 `max(0,…)`，`valid_days=0` 时 `grant(..., null)` 且响应 `expiresAt` 置 `time()`（表示立即生效、无过期——wire 值仍为 ISO 时刻，前端无新形状）；核销同分支。后台 `ConvertCodesPage`：表单 `min=0` + 描述；列表列 `0` 渲染"永久"。i18n 2 条。
- **列默认**：`valid_days INT UNSIGNED NOT NULL DEFAULT 30` 不动（默认仍限时，永久是显式选择）。

### B. 外部 API 业务域（2026-09-13 二次拍板：原「Domain/Downloads」命名作废）

- **账本定位定稿**：`Domain/Credit` 是记账基础设施——只提供 `grant()`/`spend()`/`balance()`/`entries()` 四个原语，不知道任何业务语义；业务编排不落在本域。
- **归属更正（站长拍板）**：付费下载等对外部服务的业务编排**在 `Domain/ExternalFiles` 域内执行**（该域停用中、代码完好，恢复时即承接），**不新建 `Domain/Downloads`**——"下载"只是外部 API 能力之一，后期扩展的功能都属外部 API 编排，归同一域，不按业务逐个建域。
- **域内分工（恢复/设计时）**：`ExternalFiles` 现有 OpenList 客户端/盒子/列表面之上，新增计量编排——计量点（领取直链 POST）、防抖（同文件 N 分钟内重复领取不重复扣，账本层不管）、`spend()` 对接。账本与外部 API 域之间的边界：账本只认"扣了多少、记什么来源"，外部 API 域决定"何时扣、扣完给什么"。
- **现有伏笔已就位**：`spend()` 的 `?string $dedupe` 形参（一次性领取令牌）、source 为纯字符串登记——外部 API 域无需账本侧任何新接口。

### 验收口径（A 项）

1. `generate(1, 50, 0)` 出永久码；核销后账本行 `expires_at IS NULL`，`GET /credits/entries` 该行 `expiresAt: null`，余额含该桶。
2. 永久桶 + 限时桶共存时 spend：限时桶先耗尽，永久桶最后烧（0.51.0 FIFO 已保证，回归确认）。
3. `valid_days >= 1` 行为与现状完全一致（回归）。
4. 门禁全绿 + i18n 零缺失。

外部 API 域的计量编排为独立批次（随 `Domain/ExternalFiles` 恢复/B3 设计），不入本批验收。

---

### C. 兑换码重写为兑换会员（0.54.0，站长拍板：整体重写、不继承旧表）

- **语义**：码 = 某档位 × N 周期的会员礼码（tier 下拉 + 周期数），核销 = 原子认领 → `activateFromPayment()` 入队（与付费订单同路径，order_id = 码值幂等）→ 积分走常规周期发放**绝不一次性预发**；重复兑换 409、档位已删除的码核销按无效拒绝、激活失败回滚码为未用。
- **表**：**整体重写不继承**——新表 `wp_aiya_redeem_codes`（code 唯一 / tier_key / cycles / status / user_id / used_to DATETIME / created_at DATETIME GMT）；旧 `wp_aya_convert_codes` 由 0.54.0 迁移直接 DROP（未上线无历史包袱），uninstall 兜底双 drop。CreditModule 的 0.49.0 codes 迁移摘除（codes 表归属归还赞助域）。
- **接口**：`POST /credits/redeem` 路由不变，响应形状换 `MembershipCodeGrant`（tierKey/tierName/cycles，无余额字段——积分未发放）；`CreditGrant` 保留给签到。前端 zod/manifest/快照同步（vitest 113/113、tsc 干净）。
- **后台**：兑换码页挂「会员」入口不变，生成表单改档位下拉（无档位提示先定义）+ 周期数，列表列 Credits/Validity → Tier/Cycles。

## 1. 目标模型总览

```
支付事实（wp_aya_sponsor_orders，降级为纯支付流水）
    │  网关回调 / 兑换码(不再走此路) 记单
    ▼
会员队列（wp_aiya_memberships，tier 周期 entitlement，第二期）
    │  周期推进 cron：每周期开始发放该档位额度
    ▼
积分账本（wp_aiya_credit_entries，桶 + 流水） ◄── 签到（每日一桶）
    │                                            ◄── 兑换码（一码一桶）
    │  FIFO 按过期先扣，原子事务
    ▼
消费（本期仅外部文件下载：POST 领取直链扣 1）
```

不变量：

- **单一事实源**：余额恒由账本推导（`SUM(remaining)`），不建 user meta 缓存；会员有效期恒由队列表推导，不再落 meta。
- **时间基准**：新表一律 DATETIME GMT（0.31.0 约定），不复刻订单表 `start_time` 的本地时钟 int 遗留；展示层经 `wp_date()` 转换。
- **幂等**：一切写入靠唯一键防重——账本 `UNIQUE(source, ref, user_id)`、队列表 `UNIQUE(order_id)`、签到 `ref=用户+当日`、周期发放 `ref=order_id#周期k`。
- **依赖方向**：会员域 → 积分域单向（会员只管往账本发桶，不知道下载的存在）；ExternalFiles → 积分域单向（只问余额、只调扣减）；积分域不依赖任何上游。

## 2. 第一期：积分域 `Domain/Credit` —— ✅ 已完成（0.47.0）

独立批次落地，会员域保持 0.29.1 的停用态不动。期内签到即用，付费盒子靠签到积分运转（免费小额、付费大额的 freemium 形态随第二期会员发放补全）。

**落地记录（2026-09-13）**：`Domain/Credit`（`CreditAllocator` 纯分配器 + `LedgerService` 账本 + `CreditSettings` + `CreditModule`，迁移 0.47.0 建表 `wp_aiya_credit_entries`，每日清理 cron `aiya_core_credits_cleanup`）；REST `GET /credits/balance`、`GET /credits/entries`、`POST /credits/checkin`（见 §2.3 差异说明）；契约 DTO `CreditBalance`/`CreditEntry`/`CreditCheckin` 加法进快照，front-station zod + vitest 同步；`sponsor_can` 按 §0 第 8 条删除；单测 `CreditAllocatorTest`（8 用例）；运行时实测：迁移落地、grant 幂等、FIFO 跨桶扣减、超额 409、checkin 409 幂等与成功流、entries 分页信封、cron 排程、prune 冒烟，测试数据已清零。147 tests / 328 assertions 全绿。

**后台面改造（0.48.0，同日拍板 §0 第 9 条）**：积分域收缩为纯记账——`download_cost` 设置移除（报价归下游调用方，`spend()` 只收数额），保留期字段移交前台页与通知保留期同区（`credit_retention`，`CreditSettings::retentionDays()` 读取），积分有效期语义收窄为签到专属并入「每日签到」组；后台整体移入新一级菜单「会员」（`aiya-core-membership`，位置 27，轻社区之下；会员域/支付/兑换码后续做其子页），积分屏改轻社区同款折叠卡片（`Admin/CreditsPage`）：每日签到设置卡 + 手动发放卡（用户联想搜索 + 数额/有效期/备注，`source=admin`，`ref=备注+时间戳随机后缀` 保证不撞幂等键）+ 积分流水卡（按用户分页查看）；用户列表新增「积分」列（实时 SUM，链入流水视图）。147 tests / 328 assertions、phpstan、phpcs 全绿；页面渲染/设置保存/发放/流水/用户列实测，测试数据已清零。

### 2.1 数据模型

```sql
wp_aiya_credit_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    direction ENUM('in','out') NOT NULL,      -- in=发放桶，out=消费流水
    amount INT UNSIGNED NOT NULL,             -- out 行为本次扣减数
    remaining INT NOT NULL DEFAULT 0,         -- 仅 in 行维护：该桶剩余
    source VARCHAR(32) NOT NULL,              -- checkin | code | membership | spend_download | admin
    ref VARCHAR(64) NOT NULL DEFAULT '',      -- 签到日期 / 码值 / order_id#k / 资源+文件指纹 / 管理备注
    created_at DATETIME NOT NULL,
    expires_at DATETIME NULL,                 -- 仅 in 行；全部可过期
    PRIMARY KEY (id),
    UNIQUE KEY dedupe (source, ref, user_id),
    KEY fifo (user_id, expires_at)
)
```

经 SchemaVersionRunner 建表（版本随落地批次）；uninstall.php 补 drop + cron 注销。

### 2.2 核心服务

- **LedgerService**：`balance()`（SUM 推导）、`grant()`（建桶，撞唯一键幂等）、`spend()`（FIFO 扣减：事务内 `SELECT … FOR UPDATE` 取该用户未过期桶按 `expires_at ASC` 排序，逐桶条件 UPDATE `remaining >= take` 守卫——与 RedeemCodeService 原子核销同一手法——最后插 out 流水）。单用户并发低，此粒度足够。
- **过期先扣分配逻辑抽成零 WP 依赖的纯类**（桶序列 + 扣减额 → 分配方案），照 `ExpirationFold` 先例进 `tests/Unit`。
- **清理 cron**（每日）：删除 `expires_at < now - 保留期` 的行（保留期设置，默认 30 天，照 Notification 域模式）。

### 2.3 REST 面（`aiya/core/v1`，全部加法）

| 路由 | 认证 | 语义 | 状态 |
|---|---|---|---|
| `GET /credits/balance` | Bearer | 余额 + 最近流水摘要 | ✅ 0.47.0（余额） |
| `GET /credits/entries` | Bearer | 分页流水（direction/source/amount/remaining/createdAt/expiresAt） | ✅ 0.47.0 |
| `POST /credits/checkin` | Bearer + RateLimiter | 每日一签，`ref=当日` 撞唯一键；响应发放额与新余额与桶过期 | ✅ 0.47.0 |
| `POST /content/{id}/downloads` | Bearer + RateLimiter | 付费领取：计价 + 扣减 + 返回直链 | ✅ 0.90.0 落地（`Domain/FileServe/DownloadService`）：价由列表组自报（每文件 N 积分），领取用「列表 id + 行 ref」寻址并自行重解析行，30 秒窗口的去重键让连点只扣一次；免费行与编辑旁路走同一入口，唯一计量动作 `aiya_core_download_served` 每次投递恰好一次 |

编辑旁路：领取端点（延后）对 `edit_pages` 会话直接返回链接不扣减（与 `MembershipService::isSponsor` 的编辑旁路语义一致）。

### 2.4 下载计量改造（AttachmentService）—— 改为删除（0.47.0 拍板）

原计划的"门禁改造为积分领取"未实施；2026-09-13 拍板 **`sponsor_can` 直接删除，不继承**（§0 第 8 条）：

- 盒子 `sponsor_can` 字段、`AttachmentService::canSeeLinks()` 门禁矩阵、`MembershipService` 依赖、响应字段 `gated`/`canSeeLinks` 全部移除；存量 meta 组里的 `sponsor_can` 键成死数据不读不写。
- 现行规则：列表元数据对所有人公开；下载链接**登录可见、游客 `url` 裁剪**，免费不计量。
- 付费下载（积分领取直链）延后另行设计——重设计时再定盒子配置形状与计量点。积分域是纯记账（§0 第 9 条）：调用方自带数额调 `spend()`，`download_cost` 设置已移除。

### 2.5 后台面 —— ✅ 已完成（0.47.0 建页 / 0.48.0 改造）

一级菜单「会员」（`aiya-core-membership`，位置 27，轻社区之下；会员域/支付/兑换码后续做其子页），积分屏为轻社区同款折叠卡片（`Admin/CreditsPage`）：

- **每日签到卡**：签到开关、发放额、积分有效期天数（0.48.0 起为签到专属语义）；
- **手动发放卡**：用户联想搜索（同发送邮件页模式）+ 数额/有效期/备注，`source='admin'`，`ref = 备注 + 时间戳随机后缀`（手动发放必须次次成功，不撞幂等键）；
- **积分流水卡**：按用户分页查看（方向着色、来源标签、桶剩余、过期时间）；
- **用户列表「积分」列**：实时 SUM 余额，链入流水视图。

原 Registry 设置页废除；账本保留期归前台页运维区（`credit_retention`，紧邻通知保留期）。

### 2.6 契约与测试

- 契约 v1 加法演进：4 条新路由 + 新 DTO，`wp aiya contracts snapshot` 重立快照 + 前端 vitest 同步。
- 单测覆盖：纯分配器（FIFO、跨桶、并发守卫）、门禁决策矩阵、签到幂等；运行时实测走 wp-cli + HTTP。

## 3. 第二期：会员域重构（tier 周期队列 + 赞助域重新启用）—— ✅ 已完成（0.50.0）

**落地记录（2026-09-13）**：`Domain/Sponsorship` 整体重写——`MembershipScheduler`（周期窗口/追账纯函数 + 单测）+ `EntitlementService`（`wp_aiya_memberships` 队列表：tier 快照、周期计数器 CAS 推进、`starts_at = max(now, 队尾)` 顺序排队、`aiya_core_membership_activated` 钩子、cancelAll 全行翻转）+ `MembershipService` 重写读队列（isActive/expiresAt/leftDays/cancel/isSponsor 编辑旁路保留）+ `OrderService` 降级支付流水（orders 表加 `amount`/`tier_key` 列，start_time/duration_days 成死值）+ `GatewayController` 仅易支付（验签 → 记支付 → 入队列，三段 param，重放幂等）+ `SponsorshipController` 重写（`GET /sponsorship/plans` 渠道+档位、`GET /sponsorship/membership` 队列视图含余额、`POST /sponsorship/orders` price×cycles 收银台）+ `SponsorshipSettings` 去 afdian 块、方案 repeater 改 tier（cycle_days/credits_per_cycle）+ `SponsorshipModule` 0.50.0 迁移（队列表 + orders 加列 + 三协议键及通知 marker 行删除）+ 发放 cron `aiya_core_membership_grants` + `NotificationActions` 改线（激活钩子一次性、到期扫描读队尾 MAX(ends_at)）+ 桶过期策略按默认「本周期终点」实现。契约加法两 DTO（MembershipState/MembershipEntitlement），front-station membershipStateSchema/tierSchema/orderCreateSchema 重造（vitest 111/111）。`SPONSORSHIP_ENABLED` 开关摘除、域常开。phpstan/phpcs 全绿、154 tests / 342 assertions。运行时实测：迁移三件套落地、双购买顺序排队、重复激活 409、advance 周期发放（桶过期=周期终点）、追账幂等、取消即失效、真签名网关回调 200/重放 200/篡改 400、REST 视图与档位列表全对，测试数据已清零。

一个完整批次：tier 模型 + 队列表 + 周期 cron + 兑换码改造 + 协议键退役 + 全部消费方改线，最后翻 `SPONSORSHIP_ENABLED` 重新启用并重立契约基线。（以下为原设计，除注明外与落地一致；兑换码部分已随 0.49.0 提前完成。）

### 3.1 档位设置

赞助域设置页的方案 repeater 重造为 **tier repeater**：`key` / `名称` / `单价` / `cycle_days`（默认 30）/ `每周期积分`。"300 天档位"即 `cycle_days=300` + 大额度，无特殊逻辑（拍板 4 的特例由此覆盖）。

### 3.2 数据模型

```sql
wp_aiya_memberships (
    id BIGINT UNSIGNED AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    order_id VARCHAR(64) NOT NULL,            -- 引用支付流水，幂等锚点
    tier_key VARCHAR(32) NOT NULL,
    cycle_days INT UNSIGNED NOT NULL,
    credits_per_cycle INT UNSIGNED NOT NULL,  -- 购买时快照，后续改档不影响存量
    cycles_total INT UNSIGNED NOT NULL,
    cycles_granted INT UNSIGNED NOT NULL DEFAULT 0,
    starts_at DATETIME NOT NULL,              -- max(now, 该用户队尾 ends_at)
    ends_at DATETIME NOT NULL,                -- starts_at + cycles_total * cycle_days
    status ENUM('active','cancelled') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY order_id (order_id),
    KEY queue (user_id, starts_at)
)
```

- **队列连续性**：购买时 `starts_at = max(now, MAX(ends_at))`——与现 OrderService 的 `$start = max($now, expiration())` 同一手法，多周期多档位按购买次序顺序生效（拍板 4）。
- **有效性判定**：`now < 队尾 active 行 ends_at`，与现在读 `sponsor_expiration` 的判断等价；强制取消 = 该用户全行置 `cancelled`（`aya_force_cancel_sponsor` 的替代，落表不落 meta）。

### 3.3 周期推进 cron

每日扫描 `status='active' AND cycles_granted < cycles_total AND starts_at + cycles_granted*cycle_days <= now` 的行：发桶（额度 `credits_per_cycle`，`ref = order_id#周期k` 撞唯一键）、指针 +1。停机追账天然安全——欠下的周期各补各的桶，幂等由指针 + 唯一键保证。桶的 `expires_at` = **本周期终点**（月度配额用完即作废，成本计量最干净；"订阅期内滚存"变体 = 改为 `ends_at`，做成设置项，默认周期终点）。调度数学抽零 WP 依赖纯类进单测。

### 3.4 支付流分离

`wp_aya_sponsor_orders` **降级为纯支付流水**（列只加不改义教义不变）：网关回调记单；entitlement 行引用 order_id，两边各自幂等。GatewayController 的激活调用从 `OrderService::add()` 改为"记支付 + 插队列行"。`ExpirationFold` 与 `OrderService::syncExpiration()` 随协议键一并退役。

### 3.5 兑换码改造 —— ✅ 提前落地（0.49.0，随积分域接回）

- `wp_aya_convert_codes` 加列 `credits INT UNSIGNED NOT NULL DEFAULT 0` / `valid_days INT UNSIGNED NOT NULL DEFAULT 30`（列只加不改义；`duration` 保留，历史行语义不变，credits=0 的旧行核销按无效码拒绝）。生成时填额度与有效期，核销 = 原子认领 + `grant()` 建桶（`source='code'`，`ref=码值`，桶过期 = 兑换时刻 + valid_days），发放失败回滚码为未用；不再调用 OrderService。**前缀词已取消**（generate 去 prefix 参数与 UI，码形纯随机）。核销端点 `POST /credits/redeem`（Bearer + 限流，响应 CreditGrant 形状），SponsorshipController 的兑换路由随之摘除——爱发电订单号激活待第二期会员重设计时另定入口。

### 3.6 协议键退役与消费方改线

| 项 | 处置 |
|---|---|
| `sponsor_expiration` / `aya_force_cancel_sponsor` / `aya_trigger_count_sponsor` | 停读写，存量行删除（未上线，无兼容义务）；**AGENTS.md 持久数据协议表对应行移除**，ROADMAP/MIGRATION 状态同步 |
| `MembershipService` | 重写为队列表读取（有效期/取消态/role 判定），`incrementTriggerCount` 等残余删除 |
| `UserPresenter::role` | sponsor 判定改读队列表，编辑旁路语义不变 |
| Notification 动作 | "赞助生效"（原 `aiya_core_membership_synced`）改挂新钩子（购买入队 / 周期发放）；"到期前一日扫描"改读队尾 `ends_at` |
| `GET /sponsorship/membership` | 响应重造：tier 队列视图（档位/周期进度/下次发放）+ 积分余额块；`triggerCount` 摘除 |
| 契约 | 赞助/附件域按 0.36.0"锁外挂回"约定**重新进场时重立基线**（v1 冻结不含停用域的活动形状） |

### 3.7 其余职责

- uninstall.php：新表 drop、周期 cron 与清理 cron 注销、退役 meta 行清理。
- 后台用户列表如需展示会员/余额，走列扩展，不新增 user meta。
- 测试：队列纯调度类（周期推进、追账、取消后停发）、发放幂等、门禁矩阵；过期先扣与第一期共用同一分配器。

## 4. 决策清单

**已拍板**：计量点 = 付费按钮领取直链（不审计链接重用）；积分桶全量可过期；下载关联账本不关联会员门；tier 周期模式、按购买次序顺序生效、逐周期发放；长周期档位覆盖"一次性大盘"需求；usermeta 会员键退役改表管；兑换码直发积分、取消前缀词；两期切分。

**落地时取默认、可调**：桶过期策略默认"本周期终点"（滚存做成设置）；兑换码桶有效期第二期拍定；积分流水保留期默认 30 天（前台页可调）；领取端点编辑旁路沿用 `edit_pages`。

**明确不做**：互动加分（回帖/发帖奖励）、连续签到奖励、按传输量计量、链接失效审计、积分转赠/交易。
