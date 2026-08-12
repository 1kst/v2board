<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Protocols\General;
use App\Protocols\Singbox\Singbox;
use App\Protocols\Singbox\SingboxOld;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Symfony\Component\Yaml\Yaml;

class ClientController extends Controller
{
    public function subscribe(Request $request)
    {
        $userAgent = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
        $flag = $request->input('flag') ?? $userAgent;
        $flag = strtolower($flag);
        $user = $request->user;
        // account not expired and is not banned.
        $userService = new UserService();
        $userStatusMessage = $this->getUserStatusMessage($user);
        $clientUpdateMessage = $this->getClientUpdateMessage($userAgent, $flag);
        if ($this->isMozilla($userAgent) || $this->isMozilla($flag)) {
            return $this->fakeSubscribe($user, $flag, [$this->getBrowserAccessMessage()]);
        }
        if ($this->shouldBlockOldV2rayClient($userAgent, $flag)) {
            return $this->fakeSubscribe($user, $flag, [$this->getOldVersionClientMessage()]);
        }
        if ($this->isClashForAndroid($userAgent) || $this->isClashForAndroid($flag)) {
            return $this->fakeSubscribe($user, $flag, [$this->getUnsupportedClientMessage('ClashForAndroid')]);
        }
        if ($this->isClashForWindows($userAgent) || $this->isClashForWindows($flag)) {
            return $this->fakeClashForWindowsSubscribe($user, $userStatusMessage);
        }
        if ($userStatusMessage) {
            $names = [$userStatusMessage];
            if ($clientUpdateMessage) {
                $names[] = $clientUpdateMessage;
            }
            return $this->fakeSubscribe($user, $flag, $names);
        }
        if ($userService->isAvailable($user)) {
            $serverService = new ServerService();
            $servers = $serverService->getAvailableServers($user);
            // 套餐信息节点：触发条件与原来完全一致（flag 非空且不是 sing-box 系客户端），
            // 只是提前执行——这样它克隆的是真实节点，而不是下面插入的提示假节点。
            if ($flag && strpos($flag, 'sing') === false) {
                $this->setSubscribeInfoToServers($servers, $user);
            }
            // 提示节点插到最前面：客户端会把节点名按顺序显示成一列，重要提示必须第一眼看到。
            if ($clientUpdateMessage) {
                $servers = array_merge($this->buildFakeShadowsocksServers($this->splitFakeNodeNames([$clientUpdateMessage])), $servers);
            }
            if($flag) {
                // 必须用 === false。上游原版写的是 !strpos($flag, 'sing')：
                // UA 以 sing 开头时 strpos 返回 0，!0 为 true，会错误进入非 sing 分支，
                // 先插一遍套餐信息节点、循环又匹配不到，最后才落到 sing 分支，
                // 导致 sing-box 系客户端的套餐信息节点显示不一致。不要改回 !strpos。
                if (strpos($flag, 'sing') === false) {
                    foreach (array_reverse(glob(app_path('Protocols') . '/*.php')) as $file) {
                        $file = 'App\\Protocols\\' . basename($file, '.php');
                        $class = new $file($user, $servers);
                        if (strpos($flag, $class->flag) !== false) {
                            return $class->handle();
                        }
                    }
                }
                if (strpos($flag, 'sing') !== false) {
                    $version = null;
                    if (preg_match('/sing-box\s+([0-9.]+)/i', $flag, $matches)) {
                        $version = $matches[1];
                    }
                    if (!is_null($version) && $version >= '1.12.0') {
                        $class = new Singbox($user, $servers);
                    } else {
                        $class = new SingboxOld($user, $servers);
                    }
                    return $class->handle();
                }
            }
            $class = new General($user, $servers);
            return $class->handle();
        }
    }

    private function isClashForWindows($flag)
    {
        return strpos($flag, 'clashforwindows') !== false;
    }

    private function isMozilla($flag)
    {
        return strpos($flag, 'mozilla') === 0;
    }

    private function isClashForAndroid($flag)
    {
        return strpos($flag, 'clashforandroid') === 0;
    }

    private function getUserStatusMessage($user)
    {
        if ($this->isUserExpired($user)) {
            return '您的账户已到期，请登录官网续费。';
        }

        // transfer_enable 为 0 / null 属于「没有配额」，不是「流量用尽」，
        // 与 UserService::isAvailable() 的语义保持一致（那里直接判为不可用）。
        if ($this->hasNoTrafficQuota($user)) {
            return '您的账户暂无可用流量，请登录官网购买套餐。';
        }

        if ($this->isTrafficUsedUp($user)) {
            return '您的流量已用尽，请登录官网付费重置流量。';
        }

        return null;
    }

    private function isUserExpired($user)
    {
        return $user['expired_at'] !== null && $user['expired_at'] <= time();
    }

    /**
     * 是否没有任何流量配额（未购买套餐 / 配额被设为 0）。
     * 依据 UserService::isAvailable()：transfer_enable 为假值时用户直接不可用，
     * 官方把它当成「没有套餐配额」而不是「流量用尽」，这里与之对齐。
     */
    private function hasNoTrafficQuota($user)
    {
        return empty($user['transfer_enable']);
    }

    /**
     * 是否流量已用尽。口径取自 UserService::getAvailableUsers() 的
     * whereRaw('u + d < transfer_enable')：u + d >= transfer_enable 即为用尽。
     * transfer_enable = 0 的情况由 hasNoTrafficQuota() 单独处理，
     * 否则 0 <= (0 + 0) 成立，新注册未购买的用户会被误判成「流量已用尽」。
     */
    private function isTrafficUsedUp($user)
    {
        if ($this->hasNoTrafficQuota($user)) {
            return false;
        }

        return ((int)$user['u'] + (int)$user['d']) >= (int)$user['transfer_enable'];
    }

    private function getClientUpdateMessage($userAgent, $flag)
    {
        if ($this->isOneClickStash($userAgent) || $this->isOneClickStash($flag)) {
            return $this->getUnsupportedProtocolMessage();
        }

        // 裸 UA "Clash" 只按真实 User-Agent 判定，不看 $flag：
        // ?flag=clash 是公开的输出格式参数，活跃客户端也会用，不能因此弹提示。
        if ($this->isUnversionedClash($userAgent)
            || $this->shouldAppendCompatibilityMessage($userAgent)
            || $this->shouldAppendCompatibilityMessage($flag)) {
            return $this->getCompatibilityMessage();
        }

        if ($this->shouldUpdateFlClash($userAgent, $flag)) {
            return $this->getFlClashUpdateMessage();
        }

        if ($this->shouldUpdateShadowrocket($userAgent, $flag)) {
            return $this->getShadowrocketUpdateMessage();
        }

        return null;
    }

    /**
     * 统一产出「去官网下载客户端」的引导串，避免每条文案各自拼接域名。
     * 域名取后台「站点地址」config('v2board.app_url')，不硬编码；
     * 未配置或配置不合法时退化成不含域名的说法，绝不输出裸 http:// 或半截 URL。
     */
    private function getWebsiteDownloadHint()
    {
        $domain = $this->getWebsiteDomain();
        if ($domain === '') {
            return '请登录官网下载最新版客户端。';
        }

        return '请访问 ' . $domain . ' 下载最新版客户端。';
    }

    /**
     * 从后台「站点地址」里取出可直接展示的域名：去掉 scheme，
     * 截掉路径 / 查询 / 锚点与结尾斜杠，只保留 host[:port]。
     * 取不到形如 example.com 的域名时返回空串，由调用方退化成无域名文案。
     */
    private function getWebsiteDomain()
    {
        $appUrl = trim((string)config('v2board.app_url', ''));
        if ($appUrl === '') {
            return '';
        }
        $appUrl = preg_replace('/^[a-z][a-z0-9+.\-]*:\/\//i', '', $appUrl);
        $parts = preg_split('/[\/?#]/', $appUrl);
        $domain = trim($parts[0]);
        if (!preg_match('/^[a-z0-9\-]+(?:\.[a-z0-9\-]+)+(?::[0-9]{1,5})?$/i', $domain)) {
            return '';
        }

        return $domain;
    }

    private function getBrowserAccessMessage()
    {
        // E001 是人工客服话术里的识别码，必须保留原样
        return '开通失败，识别码 E001，请联系人工客服。';
    }

    private function getOldVersionClientMessage()
    {
        return '当前客户端版本过旧，已不支持最新协议，' . $this->getWebsiteDownloadHint();
    }

    private function getUnsupportedClientMessage($clientName)
    {
        return $clientName . ' 已停止维护，无法继续使用本站订阅，' . $this->getWebsiteDownloadHint();
    }

    private function getCompatibilityMessage()
    {
        return '当前客户端已停止维护，可能无法兼容最新协议，' . $this->getWebsiteDownloadHint();
    }

    private function getFlClashUpdateMessage()
    {
        return '当前 FlClash 版本过旧，可能无法连接部分节点，' . $this->getWebsiteDownloadHint();
    }

    private function getShadowrocketUpdateMessage()
    {
        // 小火箭只能在 App Store 更新，不引导到官网下载
        return '当前小火箭版本过旧，可能无法连接部分节点，请在 App Store 更新到最新版本。';
    }

    private function getUnsupportedProtocolMessage()
    {
        return '当前客户端不支持本站的加密协议，请更换为 Shadowrocket 后重新导入订阅。';
    }

    private function isOneClickStash($flag)
    {
        return strpos($flag, 'oneclick stash') === 0;
    }

    /**
     * 裸 UA "Clash"（没有版本号）基本是已停止维护的 Clash Premium 或自制脚本。
     * 必须用精确相等判断：一旦写成前缀匹配，clash-verge/、clashforwindows/、
     * clashmetaforandroid/、clash-meta 等全部会被命中，造成大面积误伤。
     */
    private function isUnversionedClash($flag)
    {
        return trim($flag) === 'clash';
    }

    /**
     * 只提醒确凿已停止维护的客户端。活跃维护的客户端一律放行——
     * 宁可漏提示，也不能给正常付费用户乱弹提示。
     */
    private function shouldAppendCompatibilityMessage($flag)
    {
        $prefixes = [
            'clashx',   // ClashX 已归档、停止维护
            'v2rayu',   // V2rayU 长期未更新（真实 UA 里的版本号只有 1）
            'oneclick'  // OneClick 系，保守保留
        ];

        foreach ($prefixes as $prefix) {
            if (strpos($flag, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    private function shouldUpdateFlClash($userAgent, $flag)
    {
        $version = $this->getFlClashVersion($userAgent);
        if (!$version) {
            $version = $this->getFlClashVersion($flag);
        }

        return $version && version_compare($version, '0.8.93', '<');
    }

    private function shouldUpdateShadowrocket($userAgent, $flag)
    {
        $version = $this->getShadowrocketVersion($userAgent);
        if (!$version) {
            $version = $this->getShadowrocketVersion($flag);
        }

        return $version !== null && (int)$version < 3280;
    }

    private function getFlClashVersion($flag)
    {
        if (preg_match('/flclash\/v?([0-9]+(?:\.[0-9]+)*)/i', $flag, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function getShadowrocketVersion($flag)
    {
        if (preg_match('/shadowrocket\/([0-9]+)/i', $flag, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * V2rayN(Windows) 与 V2rayNG(Android) 的版本号体系完全不同：
     * V2rayN 已经到 7.x，V2rayNG 还是 1.9.x / 1.10.x。
     * 两者一旦共用一条正则和 7.22.6 这个阈值，所有 V2rayNG 用户都会被硬拦截，
     * 所以必须各自正则、各自阈值。
     */
    private function shouldBlockOldV2rayClient($userAgent, $flag)
    {
        $v2rayNVersion = $this->getV2rayNVersion($userAgent);
        if (!$v2rayNVersion) {
            $v2rayNVersion = $this->getV2rayNVersion($flag);
        }
        if ($v2rayNVersion && version_compare($v2rayNVersion, '7.22.6', '<')) {
            return true;
        }

        // V2rayNG 仍在活跃维护，按「只拦已停止维护的客户端」的原则不做版本拦截，
        // 阈值只给一个极低的保守值，仅挡住早已废弃的 0.x。
        $v2rayNGVersion = $this->getV2rayNGVersion($userAgent);
        if (!$v2rayNGVersion) {
            $v2rayNGVersion = $this->getV2rayNGVersion($flag);
        }
        if ($v2rayNGVersion && version_compare($v2rayNGVersion, '1.0.0', '<')) {
            return true;
        }

        return false;
    }

    private function getV2rayNVersion($flag)
    {
        // (?!g) 排除 V2rayNG，避免把安卓端的 1.x 版本号拿去和 7.22.6 比较
        if (preg_match('/v2rayn(?!g)\/v?([0-9]+(?:\.[0-9]+)*)/i', $flag, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function getV2rayNGVersion($flag)
    {
        if (preg_match('/v2rayng\/v?([0-9]+(?:\.[0-9]+)*)/i', $flag, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function fakeSubscribe($user, $flag, array $names)
    {
        $names = $this->splitFakeNodeNames($names);
        $servers = $this->buildFakeShadowsocksServers($names);
        if($flag) {
            // 必须用 === false，理由同 subscribe()：上游原版的 !strpos($flag, 'sing')
            // 在 UA 以 sing 开头时会误判。不要改回 !strpos。
            if (strpos($flag, 'sing') === false) {
                foreach (array_reverse(glob(app_path('Protocols') . '/*.php')) as $file) {
                    $file = 'App\\Protocols\\' . basename($file, '.php');
                    $class = new $file($user, $servers);
                    if (strpos($flag, $class->flag) !== false) {
                        return $class->handle();
                    }
                }
            }
            if (strpos($flag, 'sing') !== false) {
                $version = null;
                if (preg_match('/sing-box\s+([0-9.]+)/i', $flag, $matches)) {
                    $version = $matches[1];
                }
                if (!is_null($version) && $version >= '1.12.0') {
                    $class = new Singbox($user, $servers);
                } else {
                    $class = new SingboxOld($user, $servers);
                }
                return $class->handle();
            }
        }
        $class = new General($user, $servers);
        return $class->handle();
    }

    private function buildFakeShadowsocksServers(array $names)
    {
        return array_map(function ($name, $index) {
            return [
                'id' => $index + 1,
                'name' => $name,
                'type' => 'shadowsocks',
                'host' => '127.0.0.1',
                'port' => 9,
                'cipher' => 'aes-128-gcm',
                'network' => 'tcp',
                'obfs' => null
            ];
        }, $names, array_keys($names));
    }

    private function fakeClashForWindowsSubscribe($user, $statusMessage = null)
    {
        // 账户状态（到期 / 流量）比客户端提示更重要，排在最前面
        $names = [];
        if ($statusMessage) {
            $names[] = $statusMessage;
        }
        $names[] = $this->getUnsupportedClientMessage('Clash for Windows');

        $names = $this->splitFakeNodeNames($names);

        $proxies = array_map(function ($name) {
            return [
                'name' => $name,
                'type' => 'ss',
                'server' => '127.0.0.1',
                'port' => 9,
                'cipher' => 'aes-128-gcm',
                'password' => 'unsupported-client',
                'udp' => true
            ];
        }, $names);

        // 响应形式与 App\Protocols\Clash::handle() 保持一致：补上同样的响应头，
        // 否则 Clash for Windows 拿不到流量 / 到期信息，也不知道更新周期。
        $appName = config('v2board.app_name', 'V2Board');
        header("subscription-userinfo: upload={$user['u']}; download={$user['d']}; total={$user['transfer_enable']}; expire={$user['expired_at']}");
        header('profile-update-interval: 24');
        header("content-disposition:attachment;filename*=UTF-8''" . rawurlencode($appName));
        if (config('v2board.app_url')) {
            header('profile-web-page-url:' . config('v2board.app_url'));
        }

        return Yaml::dump([
            'proxies' => $proxies,
            'proxy-groups' => [
                [
                    'name' => 'PROXY',
                    'type' => 'select',
                    'proxies' => $names
                ]
            ],
            'rules' => [
                'MATCH,PROXY'
            ]
        ], 2, 4, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
    }

    private function splitFakeNodeNames(array $names)
    {
        $result = [];
        foreach ($names as $name) {
            $result = array_merge($result, $this->splitFakeNodeName($name));
        }
        return $result;
    }

    private function splitFakeNodeName($name)
    {
        $chunks = [];
        $tokens = preg_split('/((?:https?:\/\/)?[a-z0-9.-]+\.[a-z]{2,}(?::[0-9]{1,5})?)/i', $name, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        foreach ($tokens as $token) {
            if ($this->isUrlToken($token)) {
                // 域名单独成段，且绝不切断
                $chunks[] = $token;
                continue;
            }

            foreach ($this->splitTextIntoClauses($token) as $clause) {
                foreach ($this->splitLongClause($clause) as $piece) {
                    $chunks[] = $piece;
                }
            }
        }

        return $chunks;
    }

    /**
     * 按中文标点断句，标点保留在前一段末尾，让每段都是完整语义片段，
     * 避免出现「您的账户已到期，请」+「登录官网续费」这种硬切结果。
     * 段首段尾的空格会被去掉，不产生空段。
     */
    private function splitTextIntoClauses($text)
    {
        $clauses = [];
        $current = '';
        foreach ($this->splitUnicodeChars($text) as $char) {
            $current .= $char;
            if (strpos('，。、；：！？', $char) !== false) {
                $clause = $this->trimSpaces($current);
                if ($clause !== '') {
                    $clauses[] = $clause;
                }
                $current = '';
            }
        }
        $clause = $this->trimSpaces($current);
        if ($clause !== '') {
            $clauses[] = $clause;
        }

        return $clauses;
    }

    /**
     * 单段仍然超长时才切开，且优先在上限以内的最后一个空格处切，
     * 这样 "Clash for Windows"、"App Store"、"Shadowrocket" 这类词组不会被截断；
     * 实在没有空格可断才按字数硬切。
     *
     * 上限取 18 个字符：中文分句本身普遍只有 5~12 个字，够不到上限，
     * 真正会碰到上限的是含英文客户端名的那一句（如「Clash for Windows 已停止维护，」）；
     * 18 个中文字符在 Clash Verge / FlClash / 小火箭 / sing-box 的节点列表里
     * 仍能一行显示完，比原来的 10 更少产生碎片，也不会长到被截断。
     */
    private function splitLongClause($clause)
    {
        $limit = 18;
        $pieces = [];
        $chars = $this->splitUnicodeChars($clause);
        while (count($chars) > 0) {
            if (count($chars) <= $limit) {
                $piece = $this->trimSpaces(implode('', $chars));
                if ($piece !== '') {
                    $pieces[] = $piece;
                }
                break;
            }
            $cut = -1;
            for ($i = $limit; $i > 0; $i--) {
                if ($this->isSpaceChar($chars[$i])) {
                    $cut = $i;
                    break;
                }
            }
            if ($cut > 0) {
                $piece = $this->trimSpaces(implode('', array_slice($chars, 0, $cut)));
                $rest = array_slice($chars, $cut + 1);
            } else {
                $piece = $this->trimSpaces(implode('', array_slice($chars, 0, $limit)));
                $rest = array_slice($chars, $limit);
            }
            if ($piece === '') {
                // 兜底：绝不产生空段，也绝不死循环
                $piece = implode('', array_slice($chars, 0, $limit));
                $rest = array_slice($chars, $limit);
            }
            $pieces[] = $piece;
            $chars = $rest;
        }

        return $pieces;
    }

    private function isSpaceChar($char)
    {
        return $char === ' ' || $char === "\t" || $char === "\xe3\x80\x80";
    }

    /** 去掉首尾空白（含全角空格），保留中间的空格 */
    private function trimSpaces($value)
    {
        $trimmed = preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $value);
        // preg_replace 遇到非法 UTF-8 会返回 null，兜底成普通 trim，别把文案抹空
        return $trimmed === null ? trim($value) : $trimmed;
    }

    private function isUrlToken($value)
    {
        return preg_match('/^(?:https?:\/\/)?[a-z0-9.-]+\.[a-z]{2,}(?::[0-9]{1,5})?$/i', $value);
    }

    private function splitUnicodeChars($value)
    {
        preg_match_all('/./u', $value, $matches);
        return $matches[0];
    }


    private function setSubscribeInfoToServers(&$servers, $user)
    {
        if (!isset($servers[0])) return;
        if (!(int)config('v2board.show_info_to_server_enable', 0)) return;
        $useTraffic = $user['u'] + $user['d'];
        $totalTraffic = $user['transfer_enable'];
        $remainingTraffic = Helper::trafficConvert($totalTraffic - $useTraffic);
        $expiredDate = $user['expired_at'] ? date('Y-m-d', $user['expired_at']) : '长期有效';
        $userService = new UserService();
        $resetDay = $userService->getResetDay($user);
        array_unshift($servers, array_merge($servers[0], [
            'name' => "套餐到期：{$expiredDate}",
        ]));
        if ($resetDay) {
            array_unshift($servers, array_merge($servers[0], [
                'name' => "距离下次重置剩余：{$resetDay} 天",
            ]));
        }
        array_unshift($servers, array_merge($servers[0], [
            'name' => "剩余流量：{$remainingTraffic}",
        ]));
    }
}
