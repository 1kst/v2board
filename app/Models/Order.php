<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $table = 'v2_order';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'surplus_order_ids' => 'array',
        // 显式整数化：全仓大量 $order->status !== 0 / type === 1 是严格比较，
        // 现在只靠 PDO::ATTR_EMULATE_PREPARES=false 让 tinyint 回来是 PHP int，
        // 一旦 DB options 变动或加 mutator 就会静默失真。cast 兜底，不改库。
        'status' => 'integer',
        'type' => 'integer',
    ];

    protected static function booted()
    {
        static::creating(function (self $order) {
            if (app()->bound('site_id') && !$order->site_id) {
                $order->site_id = (int) app('site_id');
            }
        });
    }
}
