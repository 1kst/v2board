<?php

namespace App\Http\Middleware;

use Closure;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * PDD Insight 只读接口鉴权。
 *
 * - 密钥来自 config('pdd_insight.secret')（.env: PDD_INSIGHT_SECRET），未配置则一律拒绝。
 * - 使用 hash_equals() 做定长时间比较，避免时序侧信道。
 * - 可选来源 IP / CIDR 白名单。
 * - 任何失败分支返回完全一致的 403 响应体，不区分"密钥错误"与"未配置"。
 * - 不写任何日志（避免密钥落盘）。
 */
class PddInsight
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
        // trim 两侧：.env 里不小心带上的首尾空白不应导致"配了却永远 403"
        $secret = trim((string)config('pdd_insight.secret', ''));
        // 默认关闭：密钥未配置 / 为空 => 拒绝
        if ($secret === '') {
            return $this->deny();
        }

        $provided = trim((string)$request->header('X-PDD-Insight-Secret', ''));
        if ($provided === '' || !hash_equals($secret, $provided)) {
            return $this->deny();
        }

        if (!$this->isIpAllowed($request)) {
            return $this->deny();
        }

        return $next($request);
    }

    /**
     * 所有拒绝分支共用同一响应，不泄露失败原因。
     *
     * @return \Illuminate\Http\JsonResponse
     */
    private function deny()
    {
        return response()->json(['message' => 'Forbidden'], 403)
            ->header('Cache-Control', 'no-store');
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @return bool
     */
    private function isIpAllowed($request)
    {
        $ranges = array_values(array_filter(
            array_map('trim', explode(',', (string)config('pdd_insight.allow_ips', ''))),
            function ($item) {
                return $item !== '';
            }
        ));
        // 留空 => 不限制
        if (empty($ranges)) {
            return true;
        }

        $ip = $this->clientIp($request);
        if ($ip === null || $ip === '') {
            return false;
        }

        foreach ($ranges as $range) {
            if (IpUtils::checkIp($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 取来源 IP。默认沿用本项目现有做法 $request->ip()；
     * 仅当显式配置了 client_ip_header 时才改读该头（取最左一段）。
     *
     * @param \Illuminate\Http\Request $request
     * @return string|null
     */
    private function clientIp($request)
    {
        $header = trim((string)config('pdd_insight.client_ip_header', ''));
        if ($header !== '') {
            $value = (string)$request->header($header, '');
            if ($value !== '') {
                $candidate = trim(explode(',', $value)[0]);
                if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                    return $candidate;
                }
            }
            // 配了头但拿不到合法 IP => 拒绝，不回退（回退会让白名单形同虚设）
            return null;
        }

        return $request->ip();
    }
}
