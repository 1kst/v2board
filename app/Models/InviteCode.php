<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Scopes\SiteScope;

class InviteCode extends Model
{
    protected $table = 'v2_invite_code';
    protected $dateFormat = 'U';
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];

    protected static function booted()
    {
        static::addGlobalScope(new SiteScope());
    }
}
