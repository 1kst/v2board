<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\TelegramService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function notify($method, $uuid, Request $request)
    {
        try {
            $paymentService = new PaymentService($method, null, $uuid);
            $verify = $paymentService->notify($request->input());
            if (!$verify) abort(500, 'verify error');
            if (!$this->handle(
                $verify['trade_no'],
                $verify['callback_no'],
                $paymentService->getPaymentId(),
                array_key_exists('total_amount', $verify) ? $verify['total_amount'] : null
            )) {
                abort(500, 'handle error');
            }
            return(isset($verify['custom_result']) ? $verify['custom_result'] : 'success');
        } catch (\Exception $e) {
            abort(500, 'fail');
        }
    }

    private function handle($tradeNo, $callbackNo, $paymentId = null, $paidAmount = null)
    {
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            abort(500, 'order is not found');
        }

        // 同一个网关可处理三站订单。订单保留的 site_id 只用于在后续开通、
        // 余额和套餐查询时切回正确的用户站点，不再限制订单或支付方式的可见性。
        app()->instance('site_id', (int) ($order->site_id ?: 1));

        if ((int)$order->status !== 0) return true;

        // 订单 payment_id 为空 = 从未经 checkout 绑定过任何网关（伪造/重放该单），拒绝。
        if ($paymentId !== null && $order->payment_id === null) {
            info('payment notify on unbound order', [
                'trade_no' => $tradeNo, 'notify_payment_id' => $paymentId,
            ]);
            abort(500, 'payment mismatch');
        }
        // 非空但与本次回调配置不一致：用户可能在付款窗口内切换过支付方式，旧方式的
        // 合法回调仍会到达。此时不能直接拒（否则钱已收、订单永不开通），记日志后交由
        // 下面的金额校验兜底。跨网关伪造已被 PaymentService 的 method↔配置绑定 + 各
        // 网关签名挡在更前面，这里放宽不会打开伪造缺口。
        if ($paymentId !== null && (int)$order->payment_id !== (int)$paymentId) {
            info('payment notify payment_id switched', [
                'trade_no' => $tradeNo,
                'order_payment_id' => $order->payment_id,
                'notify_payment_id' => $paymentId,
            ]);
        }

        // 金额绑定：网关回传实收金额（分）时，必须 >= 订单应付（总额 + 手续费）。
        // 少付拒绝、多付放行。网关未回传金额时不在此拦（由 method↔配置绑定兜底）。
        if ($paidAmount !== null) {
            $expect = (int)$order->total_amount + (int)$order->handling_amount;
            if ((int)$paidAmount < $expect) {
                info('payment notify amount too low', [
                    'trade_no' => $tradeNo,
                    'paid' => $paidAmount,
                    'expect' => $expect,
                ]);
                abort(500, 'amount mismatch');
            }
        }

        $orderService = new OrderService($order);
        if (!$orderService->paid($callbackNo)) {
            return false;
        }
        $telegramService = new TelegramService();
        $message = sprintf(
            "💰成功收款%s元\n———————————————\n订单号：%s",
            $order->total_amount / 100,
            $order->trade_no
        );
        $telegramService->sendMessageWithAdmin($message);
        return true;
    }
}
