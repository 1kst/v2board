<?php

/*
|--------------------------------------------------------------------------
| PDD Insight (只读流量洞察接口)
|--------------------------------------------------------------------------
|
| 供外部风控系统 (pdd-api) 拉取"用户流量口径"与"节点 host 清单"的只读接口配置。
| 默认关闭：secret 为空时 /api/v1/pdd-insight/* 一律返回 403。
| 所有值均来自 .env，不要把密钥写进本文件。
|
*/

return [
    // 访问密钥。对应请求头 X-PDD-Insight-Secret，使用 hash_equals() 比较。
    // 留空 / 未配置 => 接口一律 403（默认关闭，而非默认开放）。
    'secret' => env('PDD_INSIGHT_SECRET', ''),

    // 可选的来源 IP 白名单，逗号分隔，支持单 IP 与 CIDR（IPv4/IPv6）。
    // 例：PDD_INSIGHT_ALLOW_IPS=1.2.3.4,10.0.0.0/8,2001:db8::/32
    // 留空 => 不做来源 IP 限制。
    'allow_ips' => env('PDD_INSIGHT_ALLOW_IPS', ''),

    // 取真实来源 IP 用的请求头名（例：X-Real-IP）。
    // 留空 => 使用 $request->ip()，与本项目其它位置（AuthService / AuthController）一致。
    // 注意：本项目 TrustProxies 未配置 $proxies，若站点跑在 nginx 反代之后，
    // $request->ip() 得到的是反代自身的地址（通常 127.0.0.1）。
    // 只有在反代会强制覆写该头的前提下才配置此项，否则该头可被客户端伪造。
    'client_ip_header' => env('PDD_INSIGHT_CLIENT_IP_HEADER', ''),
];
