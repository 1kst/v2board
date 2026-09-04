<?php

namespace App\Http\Middleware;

use App\Services\AuthService;
use Closure;

class User
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
        $authorization = $request->input('auth_data') ?? $request->header('authorization');
        if (!$authorization) abort(403, '未登录或登陆已过期');

        $user = AuthService::decryptAuthData($authorization);
        if (!$user) abort(403, '未登录或登陆已过期');
        if ((int)($user['site_id'] ?? 1) !== (int)$request->attributes->get('site_id', 1)) {
            abort(403, '站点与登录账号不匹配');
        }
        $request->merge([
            'user' => $user
        ]);
        return $next($request);
    }
}
