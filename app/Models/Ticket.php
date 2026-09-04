<?php

namespace App\Models;

use App\Models\Scopes\SiteScope;
use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    protected $table = 'v2_ticket';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];

    protected static function booted()
    {
        static::addGlobalScope(new SiteScope());
        static::creating(function (self $ticket) {
            if (app()->bound('site_id') && !$ticket->site_id) {
                $ticket->site_id = (int) app('site_id');
            }
        });
    }
}
