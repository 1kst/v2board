<?php

namespace App\Http\Controllers\V1\PddInsight;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 只读流量洞察接口（供外部风控系统 pdd-api 使用）。
 *
 * 设计约束：
 * - 严格只读，不写任何表。
 * - 最小权限：只输出流量口径、用户主键 id 与节点 host/port，绝不输出明文 token /
 *   email / 密码 salt / 支付 / 邀请关系 / 工单 / 备注 / 节点凭据(UUID、密钥、server_key)。
 * - 用户关联键是 th = hash('sha256', trim($token))，与 pdd-api 侧日志一致。
 *   刻意在 PHP 内计算而非用 MySQL SHA2()/TRIM()：MySQL 的 TRIM() 只去空格，
 *   PHP trim() 还会去 \t\n\r\0\x0B，两者对异常 token 会给出不同结果。
 * - 全部走 Query Builder（不用 Eloquent 模型），避免 $casts 改写值、避免 N+1。
 */
class InsightController extends Controller
{
    /** 默认分页大小 */
    private const PER_PAGE_DEFAULT = 500;

    /** 分页硬上限（超出即夹紧，防一次拉爆内存） */
    private const PER_PAGE_MAX = 1000;

    /**
     * v2_stat_user 的口径。
     *
     * 依据（本仓库实测）：
     *  - app/Services/UserService.php:227 是唯一的写入入口，硬编码 'd'；
     *  - app/Jobs/StatUserJob.php 默认参数 $recordType = 'd'，handle() 里
     *    `if ($this->recordType === 'm') {}` 是空分支（record_at 恒为当天零点）；
     *  - app/Console/Commands/V2boardStatistics.php::statUser() 也只写 'd'
     *    （且该方法在 handle() 里已被注释掉）。
     * 结论：本系统 v2_stat_user 只有按天记录 'd'。仍显式过滤 record_type='d'，
     * 这样即使历史数据里混有 'm' 行也不会把流量重复累加。
     */
    private const STAT_RECORD_TYPE = 'd';

    /** 节点表：type => 表名（与 install.sql / ServerService 的 type 命名一致） */
    private const NODE_TABLES = [
        'vless' => 'v2_server_vless',
        'vmess' => 'v2_server_vmess',
        'trojan' => 'v2_server_trojan',
        'shadowsocks' => 'v2_server_shadowsocks',
        'hysteria' => 'v2_server_hysteria',
        'tuic' => 'v2_server_tuic',
        'anytls' => 'v2_server_anytls',
        'v2node' => 'v2_server_v2node',
    ];

    /**
     * GET /api/v1/pdd-insight/traffic?page=1&per_page=500
     *
     * 查询行为：
     *  1) 一条 COUNT(*) 取 total；
     *  2) 一条 v2_user 的 ORDER BY id + LIMIT/OFFSET，只 select 需要的 10 列；
     *  3) 一条 v2_stat_user 聚合：WHERE user_id IN (本页 id) AND record_type='d'
     *     GROUP BY user_id（命中 KEY user_id），本页只有 1 次查询，无 N+1；
     *  4) 一条取最早 record_at（ORDER BY record_at LIMIT 1，命中 KEY record_at）。
     * 即每次请求固定 4 条 SQL，与 per_page 无关。
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function traffic(Request $request)
    {
        $perPage = (int)$request->input('per_page', self::PER_PAGE_DEFAULT);
        if ($perPage < 1) {
            $perPage = self::PER_PAGE_DEFAULT;
        }
        if ($perPage > self::PER_PAGE_MAX) {
            $perPage = self::PER_PAGE_MAX;
        }

        $page = (int)$request->input('page', 1);
        if ($page < 1) {
            $page = 1;
        }

        $total = (int)DB::table('v2_user')->count();

        $users = DB::table('v2_user')
            ->select([
                'id',
                'token',
                'u',
                'd',
                'transfer_enable',
                'expired_at',
                'plan_id',
                'created_at',
                'last_login_at',
                'banned',
            ])
            ->orderBy('id', 'ASC')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $userIds = [];
        foreach ($users as $user) {
            $userIds[] = (int)$user->id;
        }

        $lifetime = $this->lifetimeTraffic($userIds);

        $data = [];
        foreach ($users as $user) {
            $userId = (int)$user->id;
            $data[] = [
                'th' => hash('sha256', trim((string)$user->token)),
                // uid = v2_user.id（主键，恒有值）。给风控后台定位到具体用户用，
                // 纯数字、不含个人信息；邮箱等敏感字段仍然不输出。
                'uid' => $userId,
                'lt_u' => isset($lifetime[$userId]) ? $lifetime[$userId]['u'] : 0,
                'lt_d' => isset($lifetime[$userId]) ? $lifetime[$userId]['d'] : 0,
                'cur_u' => (int)$user->u,
                'cur_d' => (int)$user->d,
                'quota' => (int)$user->transfer_enable,
                'expired_at' => $this->nullableInt($user->expired_at),
                'plan_id' => $this->nullableInt($user->plan_id),
                'created_at' => (int)$user->created_at,
                'last_login_at' => $this->nullableInt($user->last_login_at),
                'banned' => (int)$user->banned,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'has_more' => ($page * $perPage) < $total,
                // 口径说明：lt_u / lt_d 只能累加 v2_stat_user 里还留存的记录。
                // 本系统 reset:log 计划任务会删除 record_at < 两个月前的行
                // (app/Console/Commands/ResetLog.php:48)，所以 lt_* 实际是
                // "自 stat_since 起"的累加，不是真正的开户至今。
                // stat_since = 表中最早的 record_at；无数据时为 null。
                'stat_since' => $this->statSince(),
                'stat_record_type' => self::STAT_RECORD_TYPE,
            ],
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * GET /api/v1/pdd-insight/nodes
     *
     * 只返回 type / host / port。host 可能是域名也可能是 IP，port 可能是
     * 单端口或端口段（如 "10000-20000"），一律原样返回，由消费方解析。
     * 不做 show/group 过滤：白名单需要的是"所有节点机房出口"，隐藏节点同样是节点。
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function nodes(Request $request)
    {
        $data = [];
        $seen = [];

        foreach (self::NODE_TABLES as $type => $table) {
            // 老版本升级上来的库可能缺某张协议表，缺表就跳过（不影响其它协议）
            if (!Schema::hasTable($table)) {
                continue;
            }

            $rows = DB::table($table)
                ->select(['host', 'port'])
                ->orderBy('id', 'ASC')
                ->get();

            foreach ($rows as $row) {
                $host = trim((string)$row->host);
                if ($host === '') {
                    continue;
                }
                $port = (string)$row->port;

                // 父/子节点常共用同一 host:port，去重减少无意义重复
                $key = $type . '|' . $host . '|' . $port;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $data[] = [
                    'type' => $type,
                    'host' => $host,
                    'port' => $port,
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * 本页用户的生命周期流量聚合（单条 GROUP BY，命中 KEY user_id）。
     *
     * @param array $userIds
     * @return array user_id => ['u' => int, 'd' => int]
     */
    private function lifetimeTraffic(array $userIds)
    {
        if (empty($userIds)) {
            return [];
        }

        $rows = DB::table('v2_stat_user')
            ->select('user_id')
            ->selectRaw('SUM(`u`) AS lt_u')
            ->selectRaw('SUM(`d`) AS lt_d')
            ->whereIn('user_id', $userIds)
            ->where('record_type', self::STAT_RECORD_TYPE)
            ->groupBy('user_id')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row->user_id] = [
                'u' => (int)$row->lt_u,
                'd' => (int)$row->lt_d,
            ];
        }

        return $result;
    }

    /**
     * v2_stat_user 中最早的 record_at，用于告知消费方数据起点（保留期口径）。
     *
     * @return int|null
     */
    private function statSince()
    {
        $recordAt = DB::table('v2_stat_user')
            ->where('record_type', self::STAT_RECORD_TYPE)
            ->orderBy('record_at', 'ASC')
            ->limit(1)
            ->value('record_at');

        return $recordAt === null ? null : (int)$recordAt;
    }

    /**
     * @param mixed $value
     * @return int|null
     */
    private function nullableInt($value)
    {
        return $value === null ? null : (int)$value;
    }
}
