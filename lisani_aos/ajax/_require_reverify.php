<?php
// lisani_aos/ajax/_require_reverify.php
//
// Guard for destructive/sensitive Settings actions (edit/delete/share of
// Company Documents & Bank Accounts). Instead of accepting a `password`
// field and calling password_verify() in every single endpoint, the client
// first calls ajax/verify_password.php (the existing shared endpoint) which
// sets $_SESSION['aos_reverify_at'] on success. This file just checks that
// flag is present and recent.
//
// Usage (after session_start() + the usual app/user_id auth check):
//   require_once __DIR__ . '/_require_reverify.php';
//   aos_require_recent_reverify();
//
// Assumes the caller already sent `header('Content-Type: application/json');`.

define('AOS_REVERIFY_WINDOW_SECONDS', 120);

function aos_require_recent_reverify(): void
{
    $verifiedAt = $_SESSION['aos_reverify_at'] ?? null;

    if (!$verifiedAt || (time() - (int) $verifiedAt) > AOS_REVERIFY_WINDOW_SECONDS) {
        echo json_encode([
            'success' => false,
            'message' => 'Password verification expired. Please try again.',
        ]);
        exit;
    }
}
