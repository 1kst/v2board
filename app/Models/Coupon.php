<?php

namespace App\Models;

use App\Models\Scopes\SiteScope;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $table = 'v2_coupon';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'limit_plan_ids' => 'array',
        'limit_period' => 'array'
    ];

    protected static function booted()
    {
        static::addGlobalScope(new SiteScope());
        static::creating(function (self $coupon) {
            if (app()->bound('site_id') && !$coupon->site_id) {
                $coupon->site_id = (int) app('site_id');
            }
        });
    }
}
