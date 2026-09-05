<?php

namespace App\Services;


use App\Models\Payment;

class PaymentService
{
    public $method;
    protected $class;
    protected $config;
    protected $payment;

    public function __construct($method, $id = NULL, $uuid = NULL)
    {
        $this->method = $method;
        $this->class = '\\App\\Payments\\' . $this->method;
        if (!class_exists($this->class)) abort(500, 'gate is not found');
        if ($id) {
            $paymentModel = Payment::find($id);
            if (!$paymentModel) abort(500, 'payment is not found');
            $payment = $paymentModel->toArray();
        }
        // 支付方式是全局共享的；回调 URL 中的 UUID 只用于定位和验证网关配置。
        // 订单归属应由订单自身决定，不能再由支付方式的历史 site_id 推断。
        if ($uuid) {
            $paymentModel = Payment::where('uuid', $uuid)->first();
            if (!$paymentModel) abort(500, 'payment is not found');
            $payment = $paymentModel->toArray();
        }
        $this->config = [];
        if (isset($payment)) {
            $this->config = $payment['config'];
            $this->config['enable'] = $payment['enable'];
            $this->config['id'] = $payment['id'];
            $this->config['uuid'] = $payment['uuid'];
            $this->config['notify_domain'] = $payment['notify_domain'];
        };
        $this->payment = new $this->class($this->config);
    }

    public function notify($params)
    {
        if (!$this->config['enable']) abort(500, 'gate is not enable');
        return $this->payment->notify($params);
    }

    public function pay($order)
    {
        // 网关通知必须经过已配置的公网回调域名，绝不能退回到 APP_URL（后端地址）。
        $notifyDomain = rtrim((string) ($this->config['notify_domain'] ?? ''), '/');
        if ($notifyDomain === '') {
            abort(500, '请先配置支付回调域名');
        }
        $notifyUrl = $notifyDomain . "/api/v1/guest/payment/notify/{$this->method}/{$this->config['uuid']}";
        $currentBase = $this->currentBase();
        if (!$currentBase) {
            abort(500, '无法识别支付完成跳转的前端域名');
        }
        $returnUrl = $currentBase . '/#/order/' . $order['trade_no'];
        return $this->payment->pay([
            'notify_url' => $notifyUrl,
            'return_url' => $returnUrl,
            'trade_no' => $order['trade_no'],
            'total_amount' => $order['total_amount'],
            'user_id' => $order['user_id'],
            'stripe_token' => $order['stripe_token']
        ]);
    }

    public function form()
    {
        $form = $this->payment->form();
        $keys = array_keys($form);
        foreach ($keys as $key) {
            if (isset($this->config[$key])) $form[$key]['value'] = $this->config[$key];
        }
        return $form;
    }

    private function currentBase()
    {
        $origin = request()->header('Origin');

        if ($origin && preg_match('#^https?://[A-Za-z0-9.\-:\[\]]+$#i', $origin)) {
            return rtrim($origin, '/');
        }

        $host = request()->header('X-Forwarded-Host')
            ?: request()->header('X-Original-Host')
            ?: request()->header('Host');

        if ($host) {
            $host = trim(explode(',', $host)[0]);

            if (preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) {
                $scheme = request()->header('X-Forwarded-Proto')
                    ?: (request()->secure() ? 'https' : 'http');
                return $scheme . '://' . $host;
            }
        }

        $siteId = app()->bound('site_id') ? (int) app('site_id') : 1;
        $siteUrl = rtrim((string) config("sites.{$siteId}.url"), '/');
        if ($siteUrl && filter_var($siteUrl, FILTER_VALIDATE_URL)) {
            return $siteUrl;
        }

        return '';
    }
}
