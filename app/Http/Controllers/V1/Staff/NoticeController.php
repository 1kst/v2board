<?php

namespace App\Http\Controllers\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NoticeSave;
use App\Models\Notice;
use App\Services\ContentSiteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NoticeController extends Controller
{
    public function fetch(Request $request)
    {
        $notices = Notice::orderBy('id', 'DESC')->get();
        app(ContentSiteService::class)->appendSiteIds($notices, 'v2_notice_site', 'notice_id');

        return response([
            'data' => $notices
        ]);
    }

    public function save(NoticeSave $request)
    {
        $contentSites = app(ContentSiteService::class);
        $data = $request->validated();
        $siteIds = $contentSites->normalizeSiteIds(
            $data['site_ids'] ?? null,
            $contentSites->requestSiteId($request)
        );
        unset($data['site_ids']);

        try {
            DB::transaction(function () use ($request, $data, $siteIds, $contentSites) {
                if (!$request->input('id')) {
                    $notice = Notice::create($data);
                } else {
                    $notice = Notice::find($request->input('id'));
                    if (!$notice) abort(500, '公告不存在');
                    $notice->update($data);
                }
                $contentSites->sync('v2_notice_site', 'notice_id', $notice->id, $siteIds);
            });
        } catch (\Exception $e) {
            abort(500, '保存失败');
        }
        return response([
            'data' => true
        ]);
    }

    public function drop(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, '参数错误');
        }
        $notice = Notice::find($request->input('id'));
        if (!$notice) {
            abort(500, '公告不存在');
        }
        DB::table('v2_notice_site')->where('notice_id', $notice->id)->delete();
        if (!$notice->delete()) {
            abort(500, '删除失败');
        }
        return response([
            'data' => true
        ]);
    }
}
