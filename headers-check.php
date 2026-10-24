<?php

/**
 * Empty response carrying the dashboard's own security headers. The footer's
 * "Security Headers" check (assets/app.js) sends a HEAD request here and
 * looks at what actually arrives, so it reflects anything a proxy or CDN in
 * front of the site adds or strips, not just what PHP meant to send.
 */

require __DIR__ . '/lib/Security.php';

Security::sendHeaders('html');
header('Cache-Control: no-store');
http_response_code(204);
