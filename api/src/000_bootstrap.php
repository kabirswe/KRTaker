<?php
/**
 * KRTaker API — landing + app (Phase 2: dashboard backend + RBAC + subscriptions)
 * Routes:
 *   Landing: /api/health, /api/register, /api/verify-otp, /api/resend-otp,
 *            /api/newsletter, /api/contact
 *   App:     /api/app-setup (one-time seed), /api/app-login, /api/app-logout,
 *            /api/app-me, /api/app-bootstrap, /api/app-subscribe,
 *            /api/app-ticket-status, /api/app-payment
 * DB: SQLite at /home/krtaker/krtaker_landing.db (outside webroot)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
/* SA1 v21 hardening: JSON API never renders HTML → lock CSP down; no caching of session data */
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; sandbox");
header('X-Permitted-Cross-Domain-Policies: none');
header('Cross-Origin-Resource-Policy: cross-origin');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

error_reporting(E_ALL);
ini_set('display_errors', '0');

/* ── SA1 v26: JSON-always guarantee — no request may ever return an empty/HTML body.
   Intermittent SQLite locks, PHP limits or a 503 previously produced non-JSON output
   → clients showed "Parse error" and needed manual refresh. Now every exit path emits
   valid JSON: uncaught exceptions become {"ok":false}, and output buffering discards
   any stray bytes that would corrupt the payload. ── */
@ini_set('memory_limit', '256M');
@set_time_limit(90);
if (!headers_sent()) { ob_start(); }
