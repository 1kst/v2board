<?php

namespace App\Utils;

/**
 * Verifies a Cloudflare Turnstile token.
 *
 * Clients post the token as `recaptcha_data` and the panel keeps storing the
 * pair of keys under the `recaptcha_*` config names — those names predate the
 * switch away from Google reCAPTCHA and are left alone so the admin panel and
 * `/guest/comm/config` keep working untouched. Only the service being asked
 * changed.
 */
class TurnstileVerifier
{
    private const ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public static function verify($token): bool
    {
        $secret = trim((string)config('v2board.recaptcha_key'));
        $token = trim((string)$token);
        // Fails closed: with the check switched on but nothing to check
        // against, letting the request through would silently disable it.
        if ($secret === '' || $token === '') {
            info('Turnstile verify skipped: missing secret or token');
            return false;
        }
        // remoteip is deliberately omitted: behind a CDN the request IP seen
        // here is often the edge's, and a mismatched remoteip makes Turnstile
        // reject an otherwise valid token.
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => self::ENDPOINT,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'secret' => $secret,
                'response' => $token,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            // Generous on purpose: a panel reaching Cloudflare over a slow
            // path still needs to succeed, and a timeout here reads to the
            // user as "verification failed" on an otherwise valid token.
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $status !== 200) {
            info('Turnstile verify request failed: ' . ($error ?: 'HTTP ' . $status));
            return false;
        }
        $result = json_decode($body, true);
        if (!is_array($result)) {
            info('Turnstile verify returned an unreadable body');
            return false;
        }
        if (empty($result['success'])) {
            // error-codes names the cause: invalid-input-secret means the
            // configured key is not a Turnstile secret, and
            // timeout-or-duplicate means that token was already redeemed.
            info('Turnstile verify rejected: ' . json_encode($result['error-codes'] ?? []));
            return false;
        }
        return true;
    }
}
