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
            return $this->fakeSubscribe($user, $flag, ['开通失败，识别码E001，请联系人工客服']);
        }
        if ($this->shouldBlockOldV2rayClient($userAgent, $flag)) {
            return $this->fakeSubscribe($user, $flag, ['客户端过旧不支持最新协议 请更新到最新版本或打开 flclash.eu 下载最新客户端']);
        }
        if ($this->isClashForAndroid($userAgent) || $this->isClashForAndroid($flag)) {
            return $this->fakeSubscribe($user, $flag, ['客户端不支持，请访问flclash.eu下载最新版']);
        }
        if ($this->isClashForWindows($userAgent) || $this->isClashForWindows($flag)) {
            return $this->fakeClashForWindowsSubscribe($userStatusMessage);
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
            if ($clientUpdateMessage) {
                $servers = array_merge($this->buildFakeShadowsocksServers($this->splitFakeNodeNames([$clientUpdateMessage])), $servers);
            }
            if($flag) {
                if (!strpos($flag, 'sing')) {
                    $this->setSubscribeInfoToServers($servers, $user);
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
            return '您的账户已到期，请登录官网续费';
        }

        if ($this->isTrafficUsedUp($user)) {
            return '您的流量已用尽，请登录官网付费重置流量';
        }

        return null;
    }

    private function isUserExpired($user)
    {
        return $user['expired_at'] !== null && $user['expired_at'] <= time();
    }

    private function isTrafficUsedUp($user)
    {
        return $user['transfer_enable'] <= ($user['u'] + $user['d']);
    }

    private function getClientUpdateMessage($userAgent, $flag)
    {
        if ($this->isOneClickStash($userAgent) || $this->isOneClickStash($flag)) {
            return '当前客户端暂不支持我们的加密协议请更换为shadowrocket';
        }

        if ($this->shouldAppendCompatibilityMessage($userAgent) || $this->shouldAppendCompatibilityMessage($flag)) {
            return '你当前使用的客户端可能不兼容最新的加密协议，建议访问 flclash.eu 下载最新客户端。';
        }

        if ($this->shouldUpdateFlClash($userAgent, $flag)) {
            return '你当前的客户端版本不支持新协议，请打开flclash.eu更新到最新版';
        }

        if ($this->shouldUpdateShadowrocket($userAgent, $flag)) {
            return '你当前的小火箭版本不支持新协议，请更新到最新版小火箭';
        }

        return null;
    }

    private function isOneClickStash($flag)
    {
        return strpos($flag, 'oneclick stash') === 0;
    }

    private function shouldAppendCompatibilityMessage($flag)
    {
        $prefixes = [
            'clashx',
            'clash.meta',
            'stashcore',
            'stash',
            'sfi',
            'karing',
            'hiddify',
            'clashmeta',
            'v2rayu',
            'quantumult',
            'casverge',
            'v2raytun',
            'oneclick',
            'v2box',
            'nekobox'
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

    private function shouldBlockOldV2rayClient($userAgent, $flag)
    {
        $version = $this->getV2rayClientVersion($userAgent);
        if (!$version) {
            $version = $this->getV2rayClientVersion($flag);
        }

        return $version && version_compare($version, '7.22.6', '<');
    }

    private function getV2rayClientVersion($flag)
    {
        if (preg_match('/v2rayn(?:g)?\/v?([0-9]+(?:\.[0-9]+)*)/i', $flag, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function fakeSubscribe($user, $flag, array $names)
    {
        $names = $this->splitFakeNodeNames($names);
        $servers = $this->buildFakeShadowsocksServers($names);
        if($flag) {
            if (!strpos($flag, 'sing')) {
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

    private function fakeClashForWindowsSubscribe($statusMessage = null)
    {
        $names = [
            '不支持你当前使用的客户端',
            '请打开flclash.eu',
            '下载最新版客户端'
        ];

        if ($statusMessage) {
            $names[] = $statusMessage;
        }

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
        $current = '';
        $tokens = preg_split('/((?:https?:\/\/)?[a-z0-9.-]+\.[a-z]{2,})/i', $name, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        foreach ($tokens as $token) {
            if ($this->isUrlToken($token)) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }
                $chunks[] = $token;
                continue;
            }

            foreach ($this->splitUnicodeChars($token) as $char) {
                if ($this->unicodeLength($current . $char) > 10) {
                    $chunks[] = $current;
                    $current = '';
                }
                $current .= $char;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private function isUrlToken($value)
    {
        return preg_match('/^(?:https?:\/\/)?[a-z0-9.-]+\.[a-z]{2,}$/i', $value);
    }

    private function splitUnicodeChars($value)
    {
        preg_match_all('/./u', $value, $matches);
        return $matches[0];
    }

    private function unicodeLength($value)
    {
        return preg_match_all('/./u', $value);
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
