<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keeps multi-site ownership of notices and knowledge articles in mapping
 * tables. A content record may be assigned to one or more sites.
 */
class ContentSiteService
{
    public function requestSiteId($request): int
    {
        return (int) $request->attributes->get('site_id', 1);
    }

    public function normalizeSiteIds($siteIds, int $fallbackSiteId = 1): array
    {
        $allowed = array_map('intval', array_keys(config('sites', [])));
        $siteIds = is_array($siteIds) ? $siteIds : [$fallbackSiteId];
        $siteIds = array_values(array_unique(array_map('intval', $siteIds)));
        $siteIds = array_values(array_intersect($siteIds, $allowed));

        if (count($siteIds) === 0) {
            abort(422, '至少选择一个归属站点');
        }

        sort($siteIds);
        return $siteIds;
    }

    public function forSite(Builder $builder, string $mappingTable, string $foreignKey, int $siteId): Builder
    {
        $contentTable = $builder->getModel()->getTable();

        return $builder->whereExists(function ($query) use ($mappingTable, $foreignKey, $siteId, $contentTable) {
            $query->select(DB::raw(1))
                ->from($mappingTable)
                ->whereColumn("{$mappingTable}.{$foreignKey}", "{$contentTable}.id")
                ->where("{$mappingTable}.site_id", $siteId);
        });
    }

    public function appendSiteIds(Collection $records, string $mappingTable, string $foreignKey): Collection
    {
        if ($records->isEmpty()) {
            return $records;
        }

        $assignments = DB::table($mappingTable)
            ->whereIn($foreignKey, $records->pluck('id')->all())
            ->orderBy('site_id')
            ->get()
            ->groupBy($foreignKey);

        foreach ($records as $record) {
            $record->site_ids = ($assignments->get($record->id, collect()))
                ->pluck('site_id')
                ->map(function ($siteId) {
                    return (int) $siteId;
                })
                ->values()
                ->all();
        }

        return $records;
    }

    public function sync(string $mappingTable, string $foreignKey, int $contentId, array $siteIds): void
    {
        DB::table($mappingTable)->where($foreignKey, $contentId)->delete();
        DB::table($mappingTable)->insert(array_map(function ($siteId) use ($foreignKey, $contentId) {
            return [
                $foreignKey => $contentId,
                'site_id' => $siteId,
            ];
        }, $siteIds));
    }
}
