<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Notice;
use App\Services\ContentSiteService;
use Illuminate\Http\Request;

class NoticeController extends Controller
{
    public function fetch(Request $request)
    {
        $contentSites = app(ContentSiteService::class);
        $siteId = $contentSites->requestSiteId($request);

        if ($request->has('id')) {
            $id = $request->input('id');
            $notice = Notice::where('id', $id)->where('show', 1);
            $contentSites->forSite($notice, 'v2_notice_site', 'notice_id', $siteId);
            $notice = $notice->first();
    
            if (!$notice) {
                return response([
                    'message' => 'Notice not found'
                ], 404);
            }
    
            return response([
                'data' => $notice
            ]);
        }
    
        $current = $request->input('current', 1);
        $pageSize = $request->input('pageSize', 5);
    
        $pageSize = min(max($pageSize, 1), 100);
    
        $model = Notice::orderBy('created_at', 'DESC')
            ->where('show', 1);
        $contentSites->forSite($model, 'v2_notice_site', 'notice_id', $siteId);
    
        $total = $model->count();
        $res = $model->forPage($current, $pageSize)->get();
    
        return response([
            'data' => $res,
            'total' => $total
        ]);
    }

}
