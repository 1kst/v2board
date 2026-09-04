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
        if ($id) $payment = Payment::find($id)->toArray();
        // 支付回调不带前端的站点请求头。支付 UUID 全库唯一，因此先跳过默认
        // site=1 作用域定位网关，再把回调请求切换到该网关所属站点，随后订单查询
        // 会自然落在同一站点，避免站点 2/3 的合法回调被当作不存在。
        if ($uuid) {
            $paymentModel = Payment::withoutGlobalScopes()->where('uuid', $uuid)->first();
            if (!$paymentModel) abort(500, 'payment is not found');
            app()->instance('site_id', (int) $paymentModel->site_id);
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
        // custom notify domain name
        $notifyUrl = url("/api/v1/guest/payment/notify/{$this->method}/{$this->config['uuid']}");
        if ($this->config['notify_domain']) {
            $parseUrl = parse_url($notifyUrl);
            $notifyUrl = $this->config['notify_domain'] . $parseUrl['path'];
        }
        $currentBase = $this->currentBase();
        if ($currentBase) { 
            $returnUrl = $currentBase . '/#/order/' . $order['trade_no'];
        } else {
            $returnUrl = url('/#/order/' . $order['trade_no']);
        }
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

        return '';
    }
}
