<?php

namespace App\Console\Commands;

use App\Services\MailService;
use App\Services\PlanService;
use App\Services\OrderService;
use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Order;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;

use Exception;

class CheckRenewal extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:renewal';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '自动续费';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        ini_set('memory_limit', -1);
        $users = User::all();

        //$mailService = new MailService();
        foreach ($users as $user) {
            if ($user->auto_renewal && $user->plan_id !== NULL && $user->expired_at !== NULL && $user->expired_at > time() && $user->expired_at - time() < 86400 * 2) {
                try {
                    $latestOrder = Order::where('user_id', $user->id)
                        ->where('period', '!=', 'reset_price')
                        ->where('period', '!=', 'onetime_price')
                        ->where('period', '!=', 'deposit')
                        ->where('status', 3)
                        ->orderBy('created_at', 'desc')
                        ->first();
                    if (!$latestOrder) {
                        throw new Exception("No valid order");
                    }
                    $latestPeriod = $latestOrder->period;

                    $planService = new PlanService($user->plan_id);
                    $plan = $planService->plan;
                    if (!$plan) {
                        throw new Exception("No such plan");
                    }
                    if (!$plan->renew) {
                        throw new Exception('This subscription cannot be renewed');
                    }
                    if($user->balance < $plan[$latestPeriod]) {
                        throw new Exception('No enough balance');
                    }

                    $price = (int)$plan[$latestPeriod];
                    // 周期价缺失(null→0)或非正时跳过：否则原子扣款的 balance>=0 恒成立，
                    // 会给该用户免费续期（例如管理员下架了某周期价后仍有人自动续费）。
                    if ($price <= 0) {
                        throw new Exception('周期价缺失或非正，跳过自动续费');
                    }
                    $newExpired = $this->getTime($latestPeriod, $user->expired_at);
                    DB::beginTransaction();
                    $order = new Order();
                    $orderService = new OrderService($order);
                    $order->site_id = $user->site_id;
                    $order->user_id = $user->id;
                    $order->plan_id = $plan->id;
                    $order->period = $latestPeriod;
                    $order->trade_no = Helper::generateOrderNo();
                    $order->balance_amount = $price;
                    $order->total_amount = 0;
                    $orderService->setVipDiscount($user);
                    $order->type = 2;

                    // 原子扣款 + 续期：余额不足则 affected=0；不再用可能陈旧的
                    // $user 快照写绝对值，避免覆盖并发扣款。
                    $affected = User::where('id', $user->id)
                        ->where('balance', '>=', $price)
                        ->update([
                            'balance' => DB::raw('balance - ' . $price),
                            'expired_at' => $newExpired,
                            'updated_at' => time(),
                        ]);
                    if ($affected !== 1) {
                        throw new Exception('No enough balance');
                    }
                    $order->status = 3;
                    if (!$order->save()) {
                        throw new Exception('自动续费失败');
                    }
                    DB::commit();
                    //$mailService->remindAutorenewal($user);
                } catch (\Throwable $e) {
                    // 只在有活动事务时回滚，且绝不 save() 内存里的 $user（它可能带着
                    // 已被回滚的余额/到期改动，save 会让扣款凭空生效）。
                    if (DB::transactionLevel() > 0) {
                        DB::rollBack();
                    }
                    User::where('id', $user->id)->update([
                        'auto_renewal' => 0,
                        'updated_at' => time(),
                    ]);
                    info('用户自动续费失败', [$e->getMessage(), $user->id]);
                }
            }
        }
    }

    private function getTime($str, $timestamp)
    {
        if ($timestamp < time()) {
            $timestamp = time();
        }
        switch ($str) {
            case 'month_price':
                return strtotime('+1 month', $timestamp);
            case 'quarter_price':
                return strtotime('+3 month', $timestamp);
            case 'half_year_price':
                return strtotime('+6 month', $timestamp);
            case 'year_price':
                return strtotime('+12 month', $timestamp);
            case 'two_year_price':
                return strtotime('+24 month', $timestamp);
            case 'three_year_price':
                return strtotime('+36 month', $timestamp);
        }
    }
}
