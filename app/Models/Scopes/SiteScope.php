<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class SiteScope implements Scope
{
    public function apply(Builder $builder, Model $model)
    {
        if (!app()->bound('site_id')) return;
        $builder->where($model->getTable() . '.site_id', (int)app('site_id'));
    }
}
