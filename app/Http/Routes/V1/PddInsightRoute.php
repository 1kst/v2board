<?php
namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

/**
 * 只读流量洞察接口（内部风控专用，不对用户暴露，不写进任何前端/文档）。
 * 由 RouteServiceProvider::mapApiRoutes() 自动加载，前缀 /api/v1。
 */
class PddInsightRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'pdd-insight',
            'middleware' => 'pdd_insight'
        ], function ($router) {
            $router->get('/traffic', 'V1\\PddInsight\\InsightController@traffic');
            $router->get('/nodes', 'V1\\PddInsight\\InsightController@nodes');
        });
    }
}
