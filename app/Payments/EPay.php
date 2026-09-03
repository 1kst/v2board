<?php

namespace App\Payments;

class EPay {
    private $config;
    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'url' => [
                'label' => 'URL',
                'description' => '',
                'type' => 'input',
            ],
            'pid' => [
                'label' => 'PID',
                'description' => '',
                'type' => 'input',
            ],
            'key' => [
                'label' => 'KEY',
                'description' => '',
                'type' => 'input',
            ],
            'type' => [
                'label' => 'TYPE',
                'description' => '支付类型，如: alipay, wxpay, qqpay',
                'type' => 'input',
            ]
        ];
    }

    public function pay($order)
    {
        $params = [
            'money' => $order['total_amount'] / 100,
            'name' => $order['trade_no'],
            'notify_url' => $order['notify_url'],
            'return_url' => $order['return_url'],
            'out_trade_no' => $order['trade_no'],
            'pid' => $this->config['pid']
        ];
        if (!empty($this->config['type'])) {
            $params['type'] = $this->config['type'];
        }
        ksort($params);
        reset($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $this->config['key'];
        $params['sign'] = md5($str);
        $params['sign_type'] = 'MD5';
        return [
            'type' => 1, // 0:qrcode 1:url
            'data' => $this->config['url'] . '/submit.php?' . http_build_query($params)
        ];
    }

    public function notify($params)
    {
        $sign = $params['sign'];
        unset($params['sign']);
        unset($params['sign_type']);
        ksort($params);
        reset($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $this->config['key'];
        $generateSignature = md5($str);
        if (!hash_equals($generateSignature, $sign)) {
            return false;
        }

        // 强制要求交易状态为成功，避免未支付/处理中状态被误入账
        $tradeStatus = $params['trade_status'] ?? '';
        if ($tradeStatus !== 'TRADE_SUCCESS') {
            return('fail');
        }

        // 商户号绑定：签名只证明参数由持密钥方生成，仍需确认是本站商户号。
        if (isset($this->config['pid']) &&
            !hash_equals((string)$this->config['pid'], (string)($params['pid'] ?? ''))) {
            return false;
        }

        // 回传实收金额（元→分，字符串解析禁用浮点，(float)"12.10"*100 在部分平台得 1209）。
        // 交给控制器与订单应付金额比对。
        $money = trim((string)($params['money'] ?? ''));
        $amount = null;
        if (preg_match('/^\d{1,9}(\.\d{1,2})?$/', $money)) {
            [$yuan, $cent] = array_pad(explode('.', $money, 2), 2, '0');
            $amount = (int)$yuan * 100 + (int)str_pad(substr($cent, 0, 2), 2, '0');
        }

        return [
            'trade_no' => $params['out_trade_no'],
            'callback_no' => $params['trade_no'],
            'total_amount' => $amount,
        ];
    }
}
