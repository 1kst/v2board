<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;

class Client
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $token = $request->input('token');
        if (empty($token)) {
            // 修改点 1：token 为空
            return redirect()->away('https://www.baidu.com');
        }
        $submethod = (int)config('v2board.show_subscribe_method', 0);
        switch ($submethod) {
            case 0:
                break;
            case 1:
                if (!Cache::has("otpn_{$token}")) {
                    // 修改点 2：OTP token 错误
                    return redirect()->away('https://www.baidu.com');
                }
                $usertoken = Cache::pull("otpn_{$token}");
                Cache::forget("otp_{$usertoken}");
                $token = $usertoken;
                break;
            case 2:
                $usertoken = Cache::get("totp_{$token}");
                if (!$usertoken) {
                    $timestep = (int)config('v2board.show_subscribe_expire', 5) * 60;
                    $counter = floor(time() / $timestep);
                    $counterBytes = pack('N*', 0) . pack('N*', $counter);
                    $idhash = Helper::base64DecodeUrlSafe($token);
                    if (strpos($idhash, ':') === false) {
                        // 修改点 3：TOTP token 格式错误
                        return redirect()->away('https://www.baidu.com');
                    }
                    $parts = explode(':', $idhash, 2);
                    [$userid, $clienthash] = $parts;
                    if (!$userid || !$clienthash) {
                        // 修改点 4：TOTP token 格式错误
                        return redirect()->away('https://www.baidu.com');
                    }
                    $user = User::where('id', $userid)->select('token')->first();
                    if (!$user) {
                        // 修改点 5：TOTP token 中的用户 ID 错误
                        return redirect()->away('https://www.baidu.com');
                    }
                    $usertoken = $user->token;
                    $hash = hash_hmac('sha1', $counterBytes, $usertoken, false);
                    if ($clienthash !== $hash) {
                        // 修改点 6：TOTP token 哈希验证失败
                        return redirect()->away('https://www.baidu.com');
                    }
                    Cache::put("totp_{$token}", $usertoken, $timestep);
                }
                $token = $usertoken;
                break;
            default:
                break;
        }
        $user = User::where('token', $token)->first();
        if (!$user) {
            // 修改点 7：最终的 token 在数据库中找不到
            return redirect()->away('https://www.baidu.com');
        }
        $request->merge([
            'user' => $user
        ]);
        
        // 只有这里是唯一放行的出口
        return $next($request);
    }
}
