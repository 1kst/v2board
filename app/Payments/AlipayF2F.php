<?php

/**
 * 自己写别抄，抄NMB抄
 */
namespace App\Payments;

class AlipayF2F {
    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'app_id' => [
                'label' => '支付宝APPID',
                'description' => '',
                'type' => 'input',
            ],
            'private_key' => [
                'label' => '支付宝私钥',
                'description' => '',
                'type' => 'input',
            ],
            'public_key' => [
                'label' => '支付宝公钥',
                'description' => '',
                'type' => 'input',
            ],
            'product_name' => [
                'label' => '自定义商品名称',
                'description' => '将会体现在支付宝账单中',
                'type' => 'input'
            ]
        ];
    }

    public function pay($order)
    {
        try {
            $gateway = new \Library\AlipayF2F();
            $gateway->setMethod('alipay.trade.precreate');
            $gateway->setAppId($this->config['app_id']);
            $gateway->setPrivateKey($this->config['private_key']); // 可以是路径，也可以是密钥内容
            $gateway->setAlipayPublicKey($this->config['public_key']); // 可以是路径，也可以是密钥内容
            $gateway->setNotifyUrl($order['notify_url']);
            $gateway->setBizContent([
                'subject' => $this->config['product_name'] ?? (config('v2board.app_name', 'V2Board') . ' - 订阅'),
                'out_trade_no' => $order['trade_no'],
                'total_amount' => $order['total_amount'] / 100
            ]);
            $gateway->send();
            return [
                'type' => 0, // 0:qrcode 1:url
                'data' => $gateway->getQrCodeUrl()
            ];
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }
    }

    public function notify($params)
    {
        if ($params['trade_status'] !== 'TRADE_SUCCESS') return false;
        $gateway = new \Library\AlipayF2F();
        $gateway->setAppId($this->config['app_id']);
        $gateway->setPrivateKey($this->config['private_key']); // 可以是路径，也可以是密钥内容
        $gateway->setAlipayPublicKey($this->config['public_key']); // 可以是路径，也可以是密钥内容
        try {
            if ($gateway->verify($params)) {
                /**
                 * Payment is successful
                 */
                // 回传实收金额：支付宝 total_amount 单位为元，用字符串切分转分，
                // 禁用浮点（(int)((float)"12.10"*100) 在部分平台得 1209）。
                $amount = null;
                $money = trim((string)($params['total_amount'] ?? ''));
                if (preg_match('/^\d{1,9}(\.\d{1,2})?$/', $money)) {
                    [$yuan, $cent] = array_pad(explode('.', $money, 2), 2, '0');
                    $amount = (int)$yuan * 100 + (int)str_pad(substr($cent, 0, 2), 2, '0');
                }
                return [
                    'trade_no' => $params['out_trade_no'],
                    'callback_no' => $params['trade_no'],
                    'total_amount' => $amount,
                ];
            } else {
                /**
                 * Payment is not successful
                 */
                return false;
            }
        } catch (\Exception $e) {
            /**
             * Payment is not successful
             */
            return false;
        }
    }
}
