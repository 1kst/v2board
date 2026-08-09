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
        if ((int)$order->status !== 0) return true;

        // 回调对应的支付配置必须与下单时 checkout 绑定的 payment_id 一致。
        // checkout 必然写入 payment_id 后才跳转网关，因此合法回调时它一定非空；
        // 为空说明该单没走过网关 checkout（伪造/重放），拒绝。
        if ($paymentId !== null) {
            if ($order->payment_id === null || (int)$order->payment_id !== (int)$paymentId) {
                info('payment notify payment_id mismatch', [
                    'trade_no' => $tradeNo,
                    'order_payment_id' => $order->payment_id,
                    'notify_payment_id' => $paymentId,
                ]);
                abort(500, 'payment mismatch');
            }
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
