<?php

/**
 * Imports the JSON produced by extract-content-site-data.js into a V2Board
 * installation and rebuilds notice/knowledge site ownership mappings.
 *
 * Usage:
 *   php import-content-site-data.php /path/content-site-data.json --apply
 *
 * Without --apply this script only reports the planned changes.
 */

use App\Models\Knowledge;
use App\Models\Notice;
use App\Services\ContentSiteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if ($argc < 2) {
    fwrite(STDERR, "Usage: php import-content-site-data.php <content-site-data.json> [--apply]\n");
    exit(1);
}

$inputFile = $argv[1];
$apply = in_array('--apply', $argv, true);
if (!is_file($inputFile)) {
    fwrite(STDERR, "Input file does not exist: {$inputFile}\n");
    exit(1);
}
if (!Schema::hasTable('v2_notice_site') || !Schema::hasTable('v2_knowledge_site')) {
    fwrite(STDERR, "Site ownership migration has not been applied.\n");
    exit(1);
}

$payload = json_decode(file_get_contents($inputFile), true);
if (!is_array($payload) || ($payload['schema_version'] ?? null) !== 1 || !is_array($payload['sites'] ?? null)) {
    fwrite(STDERR, "Invalid content site JSON.\n");
    exit(1);
}

function contentSignature(array $record, array $fields): string
{
    $values = [];
    foreach ($fields as $field) {
        $value = $record[$field] ?? null;
        $values[] = $value === null ? null : (string) $value;
    }
    return hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function canonicalTags($tags): ?string
{
    if ($tags === null || $tags === '') {
        return null;
    }
    if (is_string($tags)) {
        $decoded = json_decode($tags, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $tags = $decoded;
        }
    }
    return json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function modelToRecord($model, string $type): array
{
    if ($type === 'notice') {
        return [
            'title' => $model->title,
            'content' => $model->content,
            'img_url' => $model->img_url,
            'tags' => canonicalTags($model->getRawOriginal('tags')),
        ];
    }

    return [
        'language' => $model->language,
        'category' => $model->category,
        'title' => $model->title,
        'body' => $model->body,
    ];
}

function sourceToRecord(array $record, string $type): array
{
    if ($type === 'notice') {
        $record['tags'] = canonicalTags($record['tags'] ?? null);
        return $record;
    }
    return $record;
}

function makeModel(array $record, string $type)
{
    if ($type === 'notice') {
        $tags = $record['tags'] ?? null;
        if (is_string($tags)) {
            $decoded = json_decode($tags, true);
            $tags = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        }
        return new Notice([
            'title' => $record['title'],
            'content' => $record['content'],
            'show' => (int) ($record['show'] ?? 0),
            'img_url' => $record['img_url'] ?? null,
            'tags' => $tags,
            'created_at' => $record['created_at'],
            'updated_at' => $record['updated_at'],
        ]);
    }

    return new Knowledge([
        'language' => $record['language'],
        'category' => $record['category'],
        'title' => $record['title'],
        'body' => $record['body'],
        'sort' => $record['sort'] ?? null,
        'show' => (int) ($record['show'] ?? 0),
        'created_at' => $record['created_at'],
        'updated_at' => $record['updated_at'],
    ]);
}

function syncType(array $sites, string $type, bool $apply, ContentSiteService $siteService): array
{
    $modelClass = $type === 'notice' ? Notice::class : Knowledge::class;
    $mappingTable = $type === 'notice' ? 'v2_notice_site' : 'v2_knowledge_site';
    $foreignKey = $type === 'notice' ? 'notice_id' : 'knowledge_id';
    $fields = $type === 'notice'
        ? ['title', 'content', 'img_url', 'tags']
        : ['language', 'category', 'title', 'body'];

    $sourceGroups = [];
    foreach ($sites as $siteId => $content) {
        $siteId = (int) $siteId;
        if (!in_array($siteId, [1, 2, 3], true)) {
            throw new RuntimeException("Unsupported site_id: {$siteId}");
        }
        foreach (($content[$type === 'notice' ? 'notices' : 'knowledges'] ?? []) as $record) {
            $record = sourceToRecord($record, $type);
            $signature = contentSignature($record, $fields);
            $sourceGroups[$signature]['records_by_site'][$siteId][] = $record;
        }
    }

    $currentBySignature = [];
    $existingModelIds = [];
    foreach ($modelClass::all() as $model) {
        $signature = contentSignature(modelToRecord($model, $type), $fields);
        $currentBySignature[$signature][] = $model;
        $existingModelIds[(int) $model->id] = true;
    }

    $summary = [
        'source_records' => 0,
        'existing_matched' => 0,
        'new_records' => 0,
        'mapping_rows_rebuilt' => 0,
        'current_records_left_unchanged' => 0,
    ];
    $touchedExistingIds = [];

    foreach ($sourceGroups as $signature => $group) {
        $recordsBySite = $group['records_by_site'];
        $summary['source_records'] += array_sum(array_map('count', $recordsBySite));
        $requiredCopies = max(array_map('count', $recordsBySite));
        $models = $currentBySignature[$signature] ?? [];

        for ($index = count($models); $index < $requiredCopies; $index += 1) {
            $sourceRecord = reset($recordsBySite)[0];
            $model = makeModel($sourceRecord, $type);
            if ($apply) {
                $model->save();
            }
            $models[] = $model;
            $summary['new_records'] += 1;
        }

        for ($index = 0; $index < $requiredCopies; $index += 1) {
            $siteIds = [];
            foreach ($recordsBySite as $siteId => $records) {
                if ($index < count($records)) {
                    $siteIds[] = (int) $siteId;
                }
            }
            sort($siteIds);
            $model = $models[$index];
            if ($model->exists) {
                $summary['existing_matched'] += 1;
            }
            if ($apply) {
                $siteService->sync($mappingTable, $foreignKey, $model->id, $siteIds);
            }
            $summary['mapping_rows_rebuilt'] += count($siteIds);
            if (isset($existingModelIds[(int) $model->id])) {
                $touchedExistingIds[(int) $model->id] = true;
            }
        }
    }

    $summary['current_records_left_unchanged'] = count($existingModelIds) - count($touchedExistingIds);
    return $summary;
}

$siteService = app(ContentSiteService::class);
$runner = function () use ($payload, $apply, $siteService) {
    return [
        'mode' => $apply ? 'apply' : 'dry-run',
        'notice' => syncType($payload['sites'], 'notice', $apply, $siteService),
        'knowledge' => syncType($payload['sites'], 'knowledge', $apply, $siteService),
    ];
};

try {
    $summary = $apply ? DB::transaction($runner) : $runner();
    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
