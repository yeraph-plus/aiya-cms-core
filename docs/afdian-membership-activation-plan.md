# 爱发电会员激活接回计划

状态：**已实施**（0.61.0，2026-09-15；§6 五个决策点全部按推荐拍板，其中 §3.4 落地时沿用历史路由名 `/sponsorship/afdian/order-url` 并复用前端既有 `{url}` 信封 schema，未新增 bind-url 形状；下单深链按官方 URL 参数文档核对后补 `product_type=0` 并支持可选 `month` 预选月数，webhook 校验补 `data.type === "order"` 与 `order.status === 2` 双重校验）。本文保留作为实施记录。

## 0. 拍板记录

1. **接回爱发电会员激活**（2026-09-15 站长拍板）：参考旧版实现语义，激活路径两条——①用户在兑换码输入框输入爱发电订单号，走爱发电开放接口查单验证后激活；②会员档位绑定爱发电方案页，站点 webhook 回调自动激活。
2. **干净接线，不继承旧实现代码**：旧版（`func-payment.php` + `Afdian_API`）只取语义参考。0.50.0 已保留 `AfdianClient` SDK（签名/ping/queryOrder/webhook 验签/custom_order_id 绑定解析），本次把它接进 0.50.0 之后的 tier 队列模型（`wp_aiya_memberships` + `activateFromPayment`），与 Epay 收银台同构。
3. **激活入口统一走会员队列**：两条路径的终点都是 `EntitlementService::activateFromPayment(userId, orderId, tier, cycles)`，订单号带 `afd_` 前缀进 `wp_aiya_payment_orders`（钱）与 `wp_aiya_memberships`（权），幂等由两表 order_id 唯一键承接——与 Epay 回调完全同构。
4. **用户绑定沿用 custom_order_id（XDE 8 位）机制**：`AfdianClient::bindUser/resolveUser` 已实现。会员页方案按钮携带登录用户的绑定码跳转爱发电下单页，webhook 回调带回 `custom_order_id` 解析出用户。威胁模型与旧版一致（伪造绑定码只能"替别人付费开会员"，无利可图），接受。
5. **待拍板决策点**（见 §6，实施前需站长确认）。

## 1. 现状盘点（全部已就位，本次只做接线）

| 组件 | 状态 |
|---|---|
| `AfdianClient`（md5 签名 / ping / queryOrder / webhook 验签 / bindUser·resolveUser） | ✅ SDK 完整，零接线 |
| `PaymentGateway` 接口 + `GatewayController`（`aiya/sponsorship/v1` 三态回调） | ✅ Epay 已接，Afdian 增一适配器 + 一路由 |
| `RedeemCodeService` + `POST /credits/redeem`（Bearer + 限流 + MembershipCodeGrant 响应） | ✅ 本地码表路径，Afdian 渠道并列接入 |
| `EntitlementService::activateFromPayment` → `wp_aiya_memberships` 队列 + 周期发放 cron | ✅ |
| `OrderService`（`wp_aiya_payment_orders` 支付流水，order_id UNIQUE） | ✅ |
| `WebhookLogger` | ✅ |
| 档位 repeater（key/name/price/cycle_days/credits_per_cycle） | 需增 `afdian_plan_id` 子字段 |
| 支付设置页（Epay 凭据 + 渠道多选） | 需增爱发电组（开关/user_id/token/日志） |

## 2. 旧版语义对照（继承 / 不继承）

| 旧版行为 | 处置 |
|---|---|
| API 签名 `md5(token+"params"+params+"ts"+ts+"user_id"+uid)`、webhook `md5(token+"params"+data+"ts"+ts)` | ✅ 继承（SDK 已逐字节实现） |
| 订单号激活：ping 可用性 → 已激活预检 → query-order → 激活 → 回显订单信息 | ✅ 继承语义，终点换 `activateFromPayment` |
| webhook `custom_order_id` 携带用户（XDE 8 位） | ✅ 继承（SDK bindUser/resolveUser） |
| `afd_` 订单号前缀（跨平台命名空间） | ✅ 继承 |
| webhook **不验签**（旧版仅靠路径隐蔽） | ❌ 不继承——SDK 验签 + 三态回调（签名错 400） |
| `days = month × 31` 折算天数 | ❌ 不继承——tier 模型下 `cycles = month`，周期长度由档位快照 `cycle_days` 决定 |
| 全站单一预设方案（`site_afdian_preset_plan_url`）/ 自选金额两种模式 | ❌ 不继承——改为**档位级绑定**（每档 `afdian_plan_id`）；自选金额订单无 plan_id，不参与激活（见 §6-2） |
| webhook 任何情况恒 200 | ❌ 不继承——签名错回 400 让平台感知篡改，其余 200（与 Epay 三态一致） |
| `query-sponsor` 定时对账 | ⏸ 不做（webhook + 订单号自服务已覆盖；需要时再评估） |

## 3. 路径 B：方案绑定 + webhook 自动激活（主路径）

### 3.1 设置面

- **会员设置页** tiers repeater 增子字段 `afdian_plan_id`（text，label「Afdian plan ID」）——每档绑定一个爱发电方案；`SponsorshipSettings::tiers()` 透出 `afdianPlanId`。
- **支付设置页**（sponsorship-payments）增「爱发电」组：`afdian_enable`（开关）、`afdian_user_id`、`afdian_token`（secret 存储，走 clear_secrets 机制）、`afdian_savelog`（日志开关，默认关）。webhook 地址（`{site}/wp-json/aiya/sponsorship/v1/afdian/callback`）以组描述静态文案展示，供站长填入爱发电开放平台控制台。

### 3.2 `AfdianGateway` 适配器（`PaymentGateway` 第二实现）

- `id() = 'afdian'`；`enabled() = 开关 && user_id && token 齐备`；`channels() = []`（爱发电不走本站收银台，不出现在 `POST /sponsorship/orders` 渠道枚举）。
- `createPayment()`：返回爱发电下单深链 `https://afdian.com/order/create?plan_id={planId}&custom_order_id={bindUser(userId)}&remark={站点名+用户名}`——与收银台语义同构（binding 原样返回），`orderId` 字段忽略。
- `verifyCallback(webhookBody)`：`verifyWebhook` 验签 → 解析 `data.order`：`custom_order_id → resolveUser → userId`、`plan_id → tierKey`（档位表反查）、`cycles = clamp(1, month, MAX_CYCLES)`、`amount = show_amount`；任一环节缺失返回 null（不可激活）。
- `callbackFailed(body)`：仅验签失败为 true。

### 3.3 回调路由（GatewayController 增 `POST afdian/callback`）

沿 Epay 三态骨架：验签失败 → 400（`callbackFailed`）；签名有效但不可激活（无绑定/方案未绑定/月数异常）→ 200 忽略 + 可选日志；命中 → `orders->exists` 预检 + `addPayment`（`aiya_duplicate_order` 视为幂等）→ `activateFromPayment`（`aiya_duplicate_order` 视为幂等）→ 200。爱发电推送为 JSON body（非 GET query），路由 method POST、`permission_callback => '__return_true'`。

### 3.4 方案页绑定出口（契约加法）

- `GET /sponsorship/plans` items 每档增 `afdianPlanId`（string，未绑定为空串）——公开可缓存。
- 端点 `GET /sponsorship/afdian/order-url?tierKey=`（登录态）：返回 `{url}` 信封——用 `bindUser(当前用户)` 拼好的下单深链（沿用 0.25.0 历史路由名与前端既有 schema）。个性化 URL 不进公开 plans 响应，避免缓存污染。前端会员页档位按钮跳此链接；未绑定爱发电的档位返回 422。

## 4. 路径 A：订单号复用兑换输入框（爱发电渠道验证）

- `POST /credits/redeem` args 增可选 `channel`：`enum ['code','afdian']`，缺省 `code`（本地码表路径行为零变化）。前端同一输入框 + 渠道切换。
- 服务层 `AfdianActivator`（新，域内）：接收 `(userId, orderNo)`：
  1. 专属限流 `afdian_redeem`（5 次/10 分钟，防刷开放接口）；
  2. `ping()` 不可用 → 502 `aiya_afdian_unavailable`；
  3. 幂等预检：订单号（`afd_{no}`）已存在于流水或队列 → 409 `aiya_order_used`（带专有文案）；
  4. `queryOrder` 查单 → 无单 → 404 `aiya_order_not_found`；
  5. `plan_id → tierKey` 反查档位 → 未绑定 → 422 `aiya_plan_unbound`；
  6. `orders->addPayment(userId, 'afd_{no}', tier, show_amount, 'afdian')`（重复 → 409）；
  7. `activateFromPayment` → 响应 `MembershipCodeGrant {tierKey, tierName, cycles}`（复用现有 DTO，前端零新形状）；激活撞 `aiya_duplicate_order` → 409。
- 与本地码表互斥：`channel=afdian` 不触碰 `wp_aiya_redeem_codes`。

## 5. 契约与 i18n 变更（全部加法）

- redeem args + `channel`；plans tier 项 + `afdianPlanId`；新路由 `/sponsorship/afdian/bind-url`（响应 `{url}`）+ 前端 zod/manifest/快照同步（vitest）。
- 错误码新增 5 个（unavailable/used/not_found/unbound/…），信封 status 齐备；zh_CN 文案随批全量。

## 6. 待拍板决策点（实施前确认）

1. **redeem 分流方式**：推荐**显式 `channel` 参数**（前端方案 UI 明确入口）；备选是码表 miss 后自动回退爱发电查单（前端零改动，但接口故障时本地码误报失败、且多一次无谓外呼）。
2. **未绑定档位的爱发电订单（含自选金额订单，无 plan_id）**：推荐**拒绝激活**（422，文案引导绑定方案）；备选是落默认档位。
3. **webhook 签名错响应**：推荐 **400**（与 Epay 三态一致，平台可感知篡改）；旧版恒 200 不继承。
4. **`cycles = order.month`**（月捐=1 周期、一次买 12 个月=12 周期按序生效）：推荐直接采用；备选钳制为 1（丢多次购买语义，不建议）。
5. **分期**：推荐**一批完成**（两路径共享 AfdianGateway + 档位反查，拆批反而两次动契约）；版本 0.61.0。

## 7. 验收口径（实施批）

1. 路径 B：绑定档位 → 会员页跳爱发电深链携带绑定码 → 模拟 webhook 推送（正确签名 + plan_id/custom_order_id/month）→ 流水 + 队列入账、`currentTier` 生效；重放同一推送零新增（幂等）；错误签名 400。
2. 路径 A：真订单号（或传输闭包假单）兑换 → MembershipCodeGrant；同单二次 409；未绑定方案 422；ping 断开 502。
3. 本地兑换码路径回归无损（channel 缺省 code）。
4. 契约快照 + 前端 zod/vitest 同步；门禁全绿；i18n 零缺失。
