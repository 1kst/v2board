<?php

namespace App\Http\Middleware;

use App\Services\SiteConfigService;
use Closure;

class SiteContext
{
    public function handle($request, Closure $next)
    {
        $siteId = app(SiteConfigService::class)->siteIdFromRequest($request);
        $request->attributes->set('site_id', $siteId);
        app()->instance('site_id', $siteId);
        return $next($request);
    }
}
