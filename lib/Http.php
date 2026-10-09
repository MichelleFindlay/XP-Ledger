<?php

/**
 * Tiny HTTP GET helper that works whether or not the curl extension is
 * enabled — some shared PHP hosts ship without it, which would otherwise
 * fatal-error with "Call to undefined function curl_init()".
 *
 * Non-2xx responses still return their body: Steam answers some perfectly
 * ordinary questions ("this game has no achievements") with an HTTP 400
 * and a JSON explanation, which callers need to read rather than treat as
 * a network failure. Callers validate the decoded shape themselves.
 */
class Http
{
    public static function get(string $url, array $headers = [], int $timeout = 8): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_ENCODING       => '',
            ]);
            $response = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);

            return ($response === false || $error) ? null : $response;
        }

        if (function_exists('file_get_contents') && ini_get('allow_url_fopen')) {
            $context = stream_context_create([
                'http' => [
                    'timeout'       => $timeout,
                    'header'        => implode("\r\n", $headers),
                    'ignore_errors' => true,
                ],
            ]);
            $response = @file_get_contents($url, false, $context);

            return $response === false ? null : $response;
        }

        return null;
    }
}
