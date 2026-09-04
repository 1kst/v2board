<?php

namespace App\Models;

use App\Models\Scopes\SiteScope;
use Illuminate\Database\Eloquent\Model;

class CommissionLog extends Model
{
    protected $table = 'v2_commission_log';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];

    protected static function booted()
    {
        static::addGlobalScope(new SiteScope());
        static::creating(function (self $commissionLog) {
            if (app()->bound('site_id') && !$commissionLog->site_id) {
                $commissionLog->site_id = (int) app('site_id');
            }
        });
    }
}
