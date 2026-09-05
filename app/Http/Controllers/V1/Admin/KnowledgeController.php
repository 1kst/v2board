<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\KnowledgeSave;
use App\Http\Requests\Admin\KnowledgeSort;
use App\Models\Knowledge;
use App\Services\ContentSiteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KnowledgeController extends Controller
{
    public function fetch(Request $request)
    {
        $contentSites = app(ContentSiteService::class);
        if ($request->input('id')) {
            $knowledge = Knowledge::find($request->input('id'));
            if (!$knowledge) abort(500, '知识不存在');
            $contentSites->appendSiteIds(collect([$knowledge]), 'v2_knowledge_site', 'knowledge_id');
            return response([
                'data' => $knowledge->toArray()
            ]);
        }
        $knowledges = Knowledge::select(['title', 'id', 'updated_at', 'category', 'show'])
            ->orderBy('sort', 'ASC')
            ->get();
        $contentSites->appendSiteIds($knowledges, 'v2_knowledge_site', 'knowledge_id');
        return response([
            'data' => $knowledges
        ]);
    }

    public function getCategory(Request $request)
    {
        return response([
            'data' => array_keys(Knowledge::get()->groupBy('category')->toArray())
        ]);
    }

    public function save(KnowledgeSave $request)
    {
        $contentSites = app(ContentSiteService::class);
        $params = $request->validated();
        $siteIds = $contentSites->normalizeSiteIds(
            $params['site_ids'] ?? null,
            $contentSites->requestSiteId($request)
        );
        unset($params['site_ids']);

        try {
            DB::transaction(function () use ($request, $params, $siteIds, $contentSites) {
                if (!$request->input('id')) {
                    $knowledge = Knowledge::create($params);
                } else {
                    $knowledge = Knowledge::find($request->input('id'));
                    if (!$knowledge) abort(500, '知识不存在');
                    $knowledge->update($params);
                }
                $contentSites->sync('v2_knowledge_site', 'knowledge_id', $knowledge->id, $siteIds);
            });
        } catch (\Exception $e) {
            abort(500, '保存失败');
        }

        return response([
            'data' => true
        ]);
    }

    public function show(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, '参数有误');
        }
        $knowledge = Knowledge::find($request->input('id'));
        if (!$knowledge) {
            abort(500, '知识不存在');
        }
        $knowledge->show = $knowledge->show ? 0 : 1;
        if (!$knowledge->save()) {
            abort(500, '保存失败');
        }

        return response([
            'data' => true
        ]);
    }

    public function sort(KnowledgeSort $request)
    {
        DB::beginTransaction();
        try {
            foreach ($request->input('knowledge_ids') as $k => $v) {
                $knowledge = Knowledge::find($v);
                $knowledge->timestamps = false;
                $knowledge->update(['sort' => $k + 1]);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, '保存失败');
        }
        DB::commit();
        return response([
            'data' => true
        ]);
    }

    public function drop(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, '参数有误');
        }
        $knowledge = Knowledge::find($request->input('id'));
        if (!$knowledge) {
            abort(500, '知识不存在');
        }
        DB::table('v2_knowledge_site')->where('knowledge_id', $knowledge->id)->delete();
        if (!$knowledge->delete()) {
            abort(500, '删除失败');
        }

        return response([
            'data' => true
        ]);
    }
}
