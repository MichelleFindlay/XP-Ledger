<?php

require_once __DIR__ . '/Http.php';

/**
 * HTTP security headers for every response, and the site checks shown in
 * the footer (DNSSEC, security headers).
 *
 * The Content-Security-Policy is strict on purpose — no 'unsafe-inline'
 * anywhere — because that's what securityheaders.com requires for an A+
 * (an 'unsafe-inline' in script-src caps the grade at A). That's why the
 * page has no inline event handlers or style="" attributes: the one inline
 * <script> carries a per-request nonce, and sizes/colours are set from
 * data-* attributes by assets/app.js (CSSOM changes aren't blocked by CSP).
 *
 * Third-party content the page legitimately loads:
 * - images from any https: origin (Steam, Xbox, PlayStation, Nintendo,
 *   RetroAchievements and avatar CDNs — too many, and too changeable, to
 *   list one by one; images can't execute code);
 * - the Google Fonts stylesheet and font files;
 * - the Twitch player, in an iframe.
 */
class Security
{
    private static ?string $nonce = null;

    /**
     * One nonce per request, for the page's inline <script>/<style> tags.
     */
    public static function nonce(): string
    {
        return self::$nonce ??= rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }

    /**
     * Whether the visitor reached us over HTTPS — directly, or via a
     * TLS-terminating proxy such as Cloudflare.
     */
    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }

        return strpos((string) ($_SERVER['HTTP_CF_VISITOR'] ?? ''), '"https"') !== false;
    }

    /**
     * Send the security headers. $type 'html' gets the full page policy;
     * 'api' (JSON / plain text endpoints) gets a lock-everything-down
     * policy, since those responses never load anything themselves.
     */
    public static function sendHeaders(string $type = 'html'): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        header_remove('X-Powered-By');

        // Browsers ignore HSTS received over plain HTTP, so it's safe to
        // send unconditionally; one year is the securityheaders.com target.
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');

        // Everything off except what the Twitch player needs to autoplay
        // (muted) and go fullscreen inside its iframe.
        header('Permissions-Policy: accelerometer=(), autoplay=(self "https://player.twitch.tv"), camera=(), '
            . 'display-capture=(), fullscreen=(self "https://player.twitch.tv"), geolocation=(), gyroscope=(), '
            . 'magnetometer=(), microphone=(), payment=(), usb=()');

        if ($type === 'api') {
            header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");

            return;
        }

        $nonce = self::nonce();
        $policy = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'nonce-{$nonce}' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com",
            "img-src 'self' data: https:",
            "connect-src 'self'",
            'frame-src https://player.twitch.tv',
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ];
        // Only when served over HTTPS — on a plain-HTTP dev server this
        // would rewrite the page's own scripts and styles to https:// and
        // break them.
        if (self::isHttps()) {
            $policy[] = 'upgrade-insecure-requests';
        }

        header('Content-Security-Policy: ' . implode('; ', $policy));
    }

    /**
     * The hostname to run site checks against: config's site_domain if
     * set, otherwise this request's Host header. Null for anything that
     * isn't a public DNS name (localhost, bare IPs, malformed hosts).
     */
    public static function siteDomain(array $config): ?string
    {
        $host = trim((string) ($config['site_domain'] ?? '')) ?: (string) ($_SERVER['HTTP_HOST'] ?? '');
        $host = strtolower(rtrim((string) preg_replace('/:\d+$/', '', $host), '.'));

        if (filter_var($host, FILTER_VALIDATE_IP) || !preg_match('/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) {
            return null;
        }
        if (preg_match('/\.(localhost|local|lan|home|internal|test|example|invalid)$/', $host)) {
            return null;
        }

        return $host;
    }

    /**
     * Whether $host's DNS records are DNSSEC-signed and validate, using
     * Cloudflare's DNS-over-HTTPS resolver: its "AD" (authenticated data)
     * flag is set only when the whole chain of trust checks out. Cached 12
     * hours per host (failed lookups 15 minutes); null if the lookup itself
     * couldn't be done.
     */
    public static function dnssec(string $host): ?bool
    {
        $file = __DIR__ . '/../cache/site_checks.json';
        $cache = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
        $cache = is_array($cache) ? $cache : [];
        $entry = $cache[$host] ?? null;

        if (is_array($entry) && time() < (int) ($entry['until'] ?? 0)) {
            return $entry['dnssec'];
        }

        $response = Http::get('https://cloudflare-dns.com/dns-query?' . http_build_query(['name' => $host, 'type' => 'A']), [
            'Accept: application/dns-json',
            'User-Agent: XP-Ledger/1.0',
        ], 4);
        $data = $response !== null ? json_decode($response, true) : null;
        $result = is_array($data) && isset($data['Status']) ? ((int) $data['Status'] === 0 && !empty($data['AD'])) : null;

        // Host header values are visitor-controlled, so keep the cache
        // bounded: newest 20 hosts only.
        $cache[$host] = ['dnssec' => $result, 'until' => time() + ($result === null ? 900 : 43200)];
        uasort($cache, fn($a, $b) => (int) $b['until'] <=> (int) $a['until']);
        $cache = array_slice($cache, 0, 20, true);

        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($file, json_encode($cache));

        return $result;
    }
}
