<?php

namespace App\Services;


use App\Models\Payment;

class PaymentService
{
    public $method;
    protected $class;
    protected $config;
    protected $payment;
    protected $storedPayment;

    public function __construct($method, $id = NULL, $uuid = NULL)
    {
        // method 来自回调 URL，先做字符集约束，杜绝借类名做路径/命名空间穿越。
        if (!preg_match('/^[A-Za-z0-9]+$/', (string)$method)) abort(404, 'gate is not found');
        $this->method = $method;
        $this->class = '\\App\\Payments\\' . $this->method;
        if (!class_exists($this->class)) abort(404, 'gate is not found');

        $paymentModel = null;
        if ($uuid) {
            $paymentModel = Payment::where('uuid', $uuid)->first();
        } elseif ($id) {
            $paymentModel = Payment::find($id);
        }
        // 原代码对 first()/find() 直接 ->toArray()，查不到会 fatal（null->toArray）。
        if (($id || $uuid) && !$paymentModel) abort(404, 'gate is not found');

        $this->config = [];
        if ($paymentModel) {
            $payment = $paymentModel->toArray();
            $this->storedPayment = $payment['payment'];   // 该配置实际存储的网关类型
            $this->config = $payment['config'];
            $this->config['enable'] = $payment['enable'];
            $this->config['id'] = $payment['id'];
            $this->config['uuid'] = $payment['uuid'];
            $this->config['notify_domain'] = $payment['notify_domain'];
        }
        $this->payment = new $this->class($this->config);
    }

    public function getPaymentId(): ?int
    {
        return isset($this->config['id']) ? (int)$this->config['id'] : null;
    }

    public function notify($params)
    {
        if (!$this->config['enable']) abort(500, 'gate is not enable');
        // 关键绑定（仅回调路径）：URL 里的 method 必须等于该配置实际存储的网关类型。
        // 否则攻击者可拿「已启用配置的 uuid」+「另一个在缺密钥时会 fail-open 的网关
        // 类名」伪造回调，一个请求换一份订阅。放在 notify 而非构造函数，避免误伤
        // admin 预览表单 / checkout 等复用同一构造函数的合法路径。
        if ($this->storedPayment !== null && (string)$this->storedPayment !== (string)$this->method) {
            abort(404, 'gate is not found');
        }
        return $this->payment->notify($params);
    }

    public function pay($order, $host)
    {
        // custom notify domain name
        $notifyUrl = url("/api/v1/guest/payment/notify/{$this->method}/{$this->config['uuid']}");
        if ($this->config['notify_domain']) {
            $parseUrl = parse_url($notifyUrl);
            $notifyUrl = $this->config['notify_domain'] . $parseUrl['path'];
        }

        return $this->payment->pay([
            'notify_url' => $notifyUrl,
            'return_url' => $host . '/#/payment?trade_no=' . $order['trade_no'],
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
}
