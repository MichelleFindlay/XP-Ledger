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

    /**
     * @param array<string, string> $fields sent as application/x-www-form-urlencoded
     */
    public static function postForm(string $url, array $fields, array $headers = [], int $timeout = 8): ?string
    {
        $body = http_build_query($fields);
        $headers = array_merge(['Content-Type: application/x-www-form-urlencoded'], $headers);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $headers,
            ]);
            $response = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);

            return ($response === false || $error) ? null : $response;
        }

        if (function_exists('file_get_contents') && ini_get('allow_url_fopen')) {
            $context = stream_context_create([
                'http' => [
                    'method'        => 'POST',
                    'timeout'       => $timeout,
                    'header'        => implode("\r\n", $headers),
                    'content'       => $body,
                    'ignore_errors' => true,
                ],
            ]);
            $response = @file_get_contents($url, false, $context);

            return $response === false ? null : $response;
        }

        return null;
    }

    /**
     * GET without following redirects and return the Location header, for
     * OAuth flows that hand back their code in a redirect (PlayStation's).
     */
    public static function redirectLocation(string $url, array $headers = [], int $timeout = 8): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => true,
                CURLOPT_NOBODY         => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_HTTPHEADER     => $headers,
            ]);
            $response = curl_exec($ch);
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);
            $rawHeaders = $response === false ? '' : substr((string) $response, 0, $headerSize);
        } elseif (function_exists('file_get_contents') && ini_get('allow_url_fopen')) {
            $context = stream_context_create([
                'http' => [
                    'timeout'         => $timeout,
                    'header'          => implode("\r\n", $headers),
                    'follow_location' => 0,
                    'ignore_errors'   => true,
                ],
            ]);
            @file_get_contents($url, false, $context);
            $rawHeaders = implode("\n", $http_response_header ?? []);
        } else {
            return null;
        }

        return preg_match('/^location:\s*(\S+)/im', $rawHeaders, $m) ? trim($m[1]) : null;
    }

    /**
     * @param array<mixed> $payload sent as a JSON-encoded request body
     */
    public static function postJson(string $url, array $payload, array $headers = [], int $timeout = 8): ?string
    {
        $body = json_encode($payload);
        $headers = array_merge(['Content-Type: application/json'], $headers);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $headers,
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
                    'method'        => 'POST',
                    'timeout'       => $timeout,
                    'header'        => implode("\r\n", $headers),
                    'content'       => $body,
                    'ignore_errors' => true,
                ],
            ]);
            $response = @file_get_contents($url, false, $context);

            return $response === false ? null : $response;
        }

        return null;
    }
}
