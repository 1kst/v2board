<?php

namespace App\Services;

use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Support\Facades\DB;

class OrderService
{
    CONST STR_TO_TIME = [
        'month_price' => 1,
        'quarter_price' => 3,
        'half_year_price' => 6,
        'year_price' => 12,
        'two_year_price' => 24,
        'three_year_price' => 36
    ];
    public $order;
    public $user;

    public function __construct(Order $order)
    {
        $this->order = $order;
        // 订单列表和支付方式是全局的，但 User、Plan 等仍按站点隔离。每次处理
        // 已落库订单前都以订单自身的归属恢复站点上下文，供 HTTP 回调、后台手工
        // 操作和队列任务共用。
        if ($order->exists && $order->site_id) {
            app()->instance('site_id', (int) $order->site_id);
        }
    }

    public function open()
    {
        $orderId = $this->order->id;
        DB::beginTransaction();
        try {
            // 事务内加锁重读并复查状态：CheckOrder 每分钟重派 + 回调派发 + 多进程
            // worker 可能让同一订单被两个 job 并发处理，重复 open() 会二次延长订阅、
            // 二次充值入账。加锁重读把第二个 job 的 status 判断变成当前读，直接幂等退出。
            // 加锁顺序固定 order → user，与 cancel() 一致，避免死锁。
            $order = Order::where('id', $orderId)->lockForUpdate()->first();
            if (!$order || (int)$order->status !== 1) {
                DB::rollBack();
                return;                              // 幂等退出，不是错误
            }
            $user = User::where('id', $order->user_id)->lockForUpdate()->first();
            if (!$user) {
                throw new \Exception('open order failed: ' . $order->trade_no);
            }
            $this->order = $order;
            $this->user = $user;

            if ((int)$order->type === 9) {
                // 余额用原子增量而非绝对值写回：即使别处仍有未收口的余额写入并发，
                // 也不会把这笔充值覆盖掉。
                $delta = $order->total_amount + $this->getbounus($order->total_amount);
                User::where('id', $user->id)->increment('balance', $delta, ['updated_at' => time()]);
            } else {
                $plan = Plan::find($order->plan_id);
                if (!$plan) {
                    throw new \Exception('open order failed: ' . $order->trade_no);
                }
                // 花钱这一行立不变量：refund 不得为负、不得超过本单剩余价值。
                // 上游任何一次金额算术回退都不再能在这里变现。
                $refund = min(max(0, (int)$order->refund_amount), max(0, (int)$order->surplus_amount));
                if ($refund > 0) {
                    User::where('id', $user->id)->increment('balance', $refund, ['updated_at' => time()]);
                }
                if ($order->surplus_order_ids) {
                    // 只折抵仍处于已完成态的订单，避免把已折抵过的再改一次。
                    Order::whereIn('id', $order->surplus_order_ids)
                        ->where('status', 3)
                        ->update(['status' => 4]);
                }
                switch ((string)$order->period) {
                    case 'onetime_price':
                        $this->buyByOneTime($order, $plan);
                        break;
                    case 'reset_price':
                        $this->buyByResetTraffic();
                        break;
                    default:
                        $this->buyByPeriod($order, $plan);
                }
                switch ((int)$order->type) {
                    case 1:
                        $this->openEvent(config('v2board.new_order_event_id', 0));
                        break;
                    case 2:
                        $this->openEvent(config('v2board.renew_order_event_id', 0));
                        break;
                    case 3:
                        $this->openEvent(config('v2board.change_order_event_id', 0));
                        break;
                }
                $this->setSpeedLimit($plan->speed_limit);
                // buyBy* / setSpeedLimit 改写的是加锁重读出来的 $this->user 的
                // plan_id / expired_at / u / d / transfer_enable / speed_limit 等
                // 订阅字段（非 balance），保存无并发覆盖问题。
                if (!$user->save()) {
                    throw new \Exception('open order failed: ' . $order->trade_no);
                }
            }

            // 最终状态迁移用带前置条件的原子更新兜底；持锁前提下必然 affected=1。
            if (Order::where('id', $order->id)->where('status', 1)
                    ->update(['status' => 3, 'updated_at' => time()]) !== 1) {
                throw new \Exception('open order failed: ' . $order->trade_no);
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }


    public function setOrderType(User $user)
    {
        $order = $this->order;
        if ($order->period === 'deposit'){
            $order->type = 9;
        } else if ($order->period === 'reset_price') {
            $order->type = 4;
        } else if ($user->plan_id !== NULL && $order->plan_id !== $user->plan_id && ($user->expired_at > time() || $user->expired_at === NULL)) {
            if (!(int)config('v2board.plan_change_enable', 1)) abort(500, '目前不允许更改订阅，请联系客服或提交工单操作');
            $order->type = 3;
            if ((int)config('v2board.surplus_enable', 1)) $this->getSurplusValue($user, $order);
            // 负数兜底：surplus / total 都夹到非负，避免折抵算出负的 total_amount
            // 一路活到 checkout 的免费分支变现。
            $surplus = max(0, (int)$order->surplus_amount);
            $total = max(0, (int)$order->total_amount);
            $order->surplus_amount = $surplus;
            if ($surplus >= $total) {
                $order->refund_amount = $surplus - $total;
                $order->total_amount = 0;
            } else {
                $order->refund_amount = 0;
                $order->total_amount = $total - $surplus;
            }
        } else if ($user->expired_at > time() && $order->plan_id == $user->plan_id) { // 用户订阅未过期且购买订阅与当前订阅相同 === 续费
            $order->type = 2;
        } else { // 新购
            $order->type = 1;
        }
    }

    public function setVipDiscount(User $user)
    {
        $order = $this->order;
        // 整数化 + 折扣合计硬夹到 [0, 原价]：VIP 折扣与优惠券折扣此前各按原价
        // 计算再累加，两者叠加可让 discount 超过原价，total_amount 变负。
        $gross = max(0, (int)$order->total_amount);      // 此刻仍是套餐原价
        $discount = max(0, (int)$order->discount_amount); // CouponService 已先夹到 [0, gross]
        if ($user->discount) {
            $rate = max(0, min(100, (int)$user->discount));
            $discount += intdiv($gross * $rate, 100);
        }
        $discount = max(0, min($discount, $gross));
        $order->discount_amount = $discount;
        $order->total_amount = $gross - $discount;        // 恒 >= 0
    }

    public function setInvite(User $user):void
    {
        $order = $this->order;
        // 充值不是消费：充值单不产生佣金。原先充值也返佣，而钱仍留在用户自己
        // 余额里、平台无收入，自邀请小号充值即可稳定套取充值额的固定比例。
        if ($order->period === 'deposit' || (int)$order->type === 9) {
            $order->invite_user_id = $user->invite_user_id;
            return;
        }
        // 佣金基数取「套餐实际价值」= 网关实付 + 余额抵扣。setInvite 在余额抵扣之后
        // 才被调用，若只看 total_amount，则「先充值再用余额买套餐」两头都拿不到佣金
        // （充值已不返、套餐 total_amount 被抵扣成 0），邀请人颗粒无收。
        $commissionBase = (int)$order->total_amount + (int)$order->balance_amount;
        if ($user->invite_user_id && $commissionBase <= 0) return;
        $order->invite_user_id = $user->invite_user_id;
        $inviter = User::find($user->invite_user_id);
        if (!$inviter) return;
        $isCommission = false;
        switch ((int)$inviter->commission_type) {
            case 0:
                $commissionFirstTime = (int)config('v2board.commission_first_time_enable', 1);
                $isCommission = (!$commissionFirstTime || ($commissionFirstTime && !$this->haveValidOrder($user)));
                break;
            case 1:
                $isCommission = true;
                break;
            case 2:
                $isCommission = !$this->haveValidOrder($user);
                break;
        }

        if (!$isCommission) return;
        // 整数化，避免浮点写入 int 列
        $rate = ($inviter && $inviter->commission_rate)
            ? (int)$inviter->commission_rate
            : (int)config('v2board.invite_commission', 10);
        $rate = max(0, min(100, $rate));
        $order->commission_balance = intdiv($commissionBase * $rate, 100);
    }

    private function haveValidOrder(User $user)
    {
        return Order::where('user_id', $user->id)
            ->whereNotIn('status', [0, 2])
            ->first();
    }

    private function getSurplusValue(User $user, Order $order)
    {
        if ($user->expired_at === NULL) {
            $this->getSurplusValueByOneTime($user, $order);
        } else {
            $this->getSurplusValueByPeriod($user, $order);
        }
    }


    private function getSurplusValueByOneTime(User $user, Order $order)
    {
        $lastOneTimeOrder = Order::where('user_id', $user->id)
            ->where('period', 'onetime_price')
            ->where('status', 3)
            ->orderBy('id', 'DESC')
            ->first();
        if (!$lastOneTimeOrder) return;
        $nowUserTraffic = $user->transfer_enable / 1073741824;
        if ($nowUserTraffic == 0) return;
        $paidTotalAmount = ($lastOneTimeOrder->total_amount + $lastOneTimeOrder->balance_amount);
        if ($paidTotalAmount == 0) return;
        $notUsedTraffic = $nowUserTraffic - (($user->u + $user->d) / 1073741824);
        $remainingTrafficRatio = $notUsedTraffic / $nowUserTraffic;
        $result = $remainingTrafficRatio * $paidTotalAmount;
        $order->surplus_amount = max($result, 0);
        $orderModel = Order::where('user_id', $user->id)->where('period', '!=', 'reset_price')->where('status', 3);
        $order->surplus_order_ids = array_column($orderModel->get()->toArray(), 'id');
    }

    private function getSurplusValueByPeriod(User $user, Order $order)
    {
        $orders = Order::where('user_id', $user->id)
            ->where('period', '!=', 'reset_price')
            ->where('period', '!=', 'onetime_price')
            ->where('period', '!=', 'deposit')
            ->where('status', 3)
            ->get()
            ->toArray();
        if (!$orders) return;
        $orderAmountSum = 0;
        $orderMonthSum = 0;
        $lastValidateAt = null;
        foreach ($orders as $item) {
            $period = self::STR_TO_TIME[$item['period']];
            $orderEndTime = strtotime("+{$period} month", $item['created_at']);
            if ($orderEndTime < time()) continue;
            $lastValidateAt = $item['created_at'] > $lastValidateAt ? $item['created_at'] : $lastValidateAt;
            $orderMonthSum += $period;
            $orderAmountSum += $item['total_amount'] + $item['balance_amount'] + $item['surplus_amount'] - $item['refund_amount'];
        }
        if ($lastValidateAt === null) return;
    
        $expiredAtByOrder = strtotime("+{$orderMonthSum} month", $lastValidateAt);
        $expiredAtByUser = $user->expired_at;
        if ($expiredAtByOrder < time() || $expiredAtByUser < time()) return;
        $orderSurplusSecond = $expiredAtByUser - time();
        $orderRangeSecond = $expiredAtByOrder - $lastValidateAt;
    
        $totalTraffic = $user->transfer_enable;
        $usedTraffic = ($user->u + $user->d);
        if ($totalTraffic == 0) return;
    
        $remainingTrafficRatio = ($totalTraffic - $usedTraffic) / $totalTraffic;
    
        $avgPricePerSecond = $orderAmountSum / $orderRangeSecond;
        if ($orderRangeSecond <= 31 * 86400) {
            $remainingExpiredTimeRatio = $orderSurplusSecond / $orderRangeSecond;
            $surplusRatio = min($remainingExpiredTimeRatio, $remainingTrafficRatio);
            $orderSurplusAmount = $avgPricePerSecond * $orderSurplusSecond * $surplusRatio;
        } else {
            $monthSeconds = 30 * 86400;
            $firstMonthRemainSeconds = $orderSurplusSecond % $monthSeconds;
            $surplusRatio = min($firstMonthRemainSeconds / $monthSeconds, $remainingTrafficRatio);
            $laterMonthsSeconds = $orderSurplusSecond - $firstMonthRemainSeconds;
            $orderSurplusAmount = $avgPricePerSecond * $monthSeconds * $surplusRatio +
                                  $avgPricePerSecond * $laterMonthsSeconds;
        }
    
        $order->surplus_amount = max($orderSurplusAmount, 0);
        $order->surplus_order_ids = array_column($orders, 'id');
    }

    public function paid(string $callbackNo)
    {
        $order = $this->order;
        if ((int)$order->status !== 0) return true;      // 快路径，仅作优化

        // 带前置条件的原子迁移（CAS）：并发/重放的回调里只有一个能把 status
        // 从 0 迁到 1，其余 affected=0。这同时和 cancel() 争抢同一个
        // `status = 0` 前置条件，堵住「checkout 与 cancel 交叉 → 白拿订阅并退款」。
        $affected = Order::where('id', $order->id)
            ->where('status', 0)
            ->update([
                'status' => 1,
                'paid_at' => time(),
                'callback_no' => $callbackNo,
            ]);

        if ($affected !== 1) {
            // 状态已被并发/重放迁走。对支付网关必须幂等返回成功，否则会被无限重试。
            $current = (int)Order::where('id', $order->id)->value('status');
            if ($current === 2) {
                // 订单已取消却又收到真实付款：钱已收、余额已退，必须人工介入。
                info('order paid after cancel', [
                    'trade_no' => $order->trade_no,
                    'callback_no' => $callbackNo,
                ]);
                try {
                    (new TelegramService())->sendMessageWithAdmin(sprintf(
                        "⚠️ 订单已取消但收到支付回调，请人工处理\n订单号：%s\n回调号：%s",
                        $order->trade_no,
                        $callbackNo
                    ));
                } catch (\Throwable $e) {
                    // 告警失败不能把幂等成功变成 500，否则网关会继续重试。
                    info('notify admin failed: ' . $e->getMessage());
                }
            }
            return true;
        }

        $order->setAttribute('status', 1);
        $order->syncOriginalAttribute('status');

        try {
            OrderHandleJob::dispatch($order->trade_no);
        } catch (\Exception $e) {
            // 状态已是 1，CheckOrder 每分钟兜底会重新派发。
            return false;
        }
        return true;
    }

    public function cancel():bool
    {
        $order = $this->order;
        DB::beginTransaction();
        try {
            // 带前置条件的原子迁移（CAS）：并发取消里只有把 status 从 0 改成 2 的
            // 那一个请求拿到 affected=1，其余 affected=0 直接退出、不退款。
            // 原写法 $order->save() 只生成 where id=?，Eloquent 按加载时的旧值
            // 判脏，每个并发请求都会发出 UPDATE 并各自退一次款。
            $affected = Order::where('id', $order->id)
                ->where('status', 0)
                ->update(['status' => 2]);
            if ($affected !== 1) {
                DB::rollBack();
                return false;
            }
            if ($order->balance_amount) {
                $userService = new UserService();
                if (!$userService->addBalance($order->user_id, $order->balance_amount)) {
                    DB::rollBack();
                    return false;
                }
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return false;
        }
        // 让内存模型与库一致，避免调用方随后再 save() 把 status 写回旧值。
        $order->setAttribute('status', 2);
        $order->syncOriginalAttribute('status');
        return true;
    }

    private function setSpeedLimit($speedLimit)
    {
        $this->user->speed_limit = $speedLimit;
    }

    private function buyByResetTraffic()
    {
        $this->user->u = 0;
        $this->user->d = 0;
    }

    private function buyByPeriod(Order $order, Plan $plan)
    {
        // change plan process
        if ((int)$order->type === 3) {
            $this->user->expired_at = time();
        }
        $this->user->transfer_enable = $plan->transfer_enable * 1073741824;
        $this->user->device_limit = $plan->device_limit;
        // 从一次性转换到循环
        if ($this->user->expired_at === NULL) $this->buyByResetTraffic();
        // 新购
        if ($order->type === 1) $this->buyByResetTraffic();

        // 到期当天续费刷新流量
        $expireDay = date('d', $this->user->expired_at);
        $expireMonth = date('m', $this->user->expired_at);
        $today = date('d');
        $currentMonth = date('m');
        if ($order->type === 2 && $expireMonth == $currentMonth && $expireDay === $today ) {
            $this->buyByResetTraffic();
        }

        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        $this->user->expired_at = $this->getTime($order->period, $this->user->expired_at);
    }

    private function buyByOneTime(Order $order, Plan $plan)
    {
        $transfer_enable = $plan->transfer_enable;
        if (!$order->surplus_order_ids) {
            $notUsedTraffic = ($this->user->transfer_enable - ($this->user->u + $this->user->d)) / 1073741824;
            if ($notUsedTraffic > 0 && $this->user->expired_at == NULL) {
                $transfer_enable += $notUsedTraffic;
            }
        }
        $this->buyByResetTraffic();
        $this->user->transfer_enable = $transfer_enable * 1073741824;
        $this->user->device_limit = $plan->device_limit;
        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        $this->user->expired_at = NULL;
    }

    private function getTime($str, $timestamp)
    {
        if ($timestamp < time()) {
            $timestamp = time();
        }
        switch ($str) {
            case 'month_price':
                return strtotime('+1 month', $timestamp);
            case 'quarter_price':
                return strtotime('+3 month', $timestamp);
            case 'half_year_price':
                return strtotime('+6 month', $timestamp);
            case 'year_price':
                return strtotime('+12 month', $timestamp);
            case 'two_year_price':
                return strtotime('+24 month', $timestamp);
            case 'three_year_price':
                return strtotime('+36 month', $timestamp);
        }
    }

    private function openEvent($eventId)
    {
        switch ((int) $eventId) {
            case 0:
                break;
            case 1:
                $this->buyByResetTraffic();
                break;
        }
    }

    private function getbounus($total_amount) {
        $deposit_bounus = config('v2board.deposit_bounus', []);
        if (empty($deposit_bounus) || $deposit_bounus[0] === null) {
            return 0;
        }
        $add = 0;
        foreach ($deposit_bounus as $tier) {
            list($amount, $bounus) = explode(':', $tier);
            $amount = (float)$amount * 100;
            $bounus = (float)$bounus * 100;
            $amount = (int)$amount;
            $bounus = (int)$bounus;
            if ($total_amount >= $amount) {
                $add = max($add, $bounus);
            }
        }
        return $add;
    }
}
