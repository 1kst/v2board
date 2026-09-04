<?php

namespace App\Models;

use App\Models\Scopes\SiteScope;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $table = 'v2_payment';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'config' => 'array'
    ];

    protected static function booted()
    {
        static::addGlobalScope(new SiteScope());
        static::creating(function (self $payment) {
            if (app()->bound('site_id') && !$payment->site_id) {
                $payment->site_id = (int) app('site_id');
            }
        });
    }
}
