<?php

namespace App\Services;

use Illuminate\Http\Request;
use InvalidArgumentException;

class SiteConfigService
{
    public function siteIdFromRequest(Request $request): int
    {
        $value = $request->header('X-Site-ID');
        if ($value === null || $value === '') return 1;
        if (!ctype_digit((string)$value) || !isset(config('sites')[(int)$value])) {
            abort(403, 'Invalid site identifier');
        }

        $siteId = (int)$value;
        $expectedKey = (string)config("sites.{$siteId}.key", '');
        $actualKey = (string)$request->header('X-Site-Key', '');
        if ($expectedKey === '' || $actualKey === '' || !hash_equals($expectedKey, $actualKey)) {
            abort(403, 'Invalid site key');
        }
        return $siteId;
    }

    public function get(int $siteId): array
    {
        $site = config("sites.{$siteId}");
        if (!is_array($site)) throw new InvalidArgumentException("Unknown site: {$siteId}");

        return [
            'id' => $siteId,
            'name' => $site['name'] ?: config('v2board.app_name', 'V2Board'),
            'url' => $site['url'] ?: config('v2board.app_url'),
            'email_template' => $site['email_template'] ?: 'site' . $siteId,
            'email_host' => $site['email_host'] ?: config('v2board.email_host'),
            'email_port' => $site['email_port'] ?: config('v2board.email_port'),
            'email_encryption' => $site['email_encryption'] ?: config('v2board.email_encryption'),
            'email_username' => $site['email_username'] ?: config('v2board.email_username'),
            'email_password' => $site['email_password'] ?: config('v2board.email_password'),
            'email_from_address' => $site['email_from_address'] ?: config('v2board.email_from_address'),
        ];
    }

    public function cacheValue(int $siteId, string $value): string
    {
        return 'site_' . $siteId . '_' . strtolower(trim($value));
    }
}
