<?php
// activity_helper.php — v3.0
// Include di setiap halaman PHP yang melakukan INSERT/UPDATE/DELETE
// Usage:
//   log_activity($conn, 'frames_main', 'FRAME-001', 'INSERT', $_SESSION['username']);
//   log_activity($conn, 'customer_orders', '123', 'UPDATE', $_SESSION['username']);
//   log_activity($conn, 'customer_orders', '123', 'DELETE', $_SESSION['username']);
//
// ARSITEKTUR BARU:
//   - TIDAK ada push otomatis ke Supabase
//   - Semua perubahan dicatat di local activity_log + pending_sync
//   - Push ke Supabase dilakukan MANUAL via activity_log.php (push_to_cloud.php)

define('AH_PK_MAP', [
    'users'                      => 'user_id',
    'settings'                   => 'setting_key',
    'frames_main'                => 'ufc',
    'frame_staging'              => 'ufc',
    'customer_examinations'      => 'id',
    'customer_orders'            => 'id',
    'custom_frames'              => 'id',
    'prescription_modifications' => 'modification_id',
]);

function log_activity($conn, $table, $record_id, $action, $username) {
    $safe_table    = $conn->real_escape_string($table);
    $safe_record   = $conn->real_escape_string($record_id);
    $safe_action   = $conn->real_escape_string($action);
    $safe_username = $conn->real_escape_string($username);
    $now           = date('Y-m-d H:i:s');

    // ── Auto-create tables if not exist ──────────────────────
    _ah_ensure_tables($conn);

    // ── 1. Log to local activity_log ─────────────────────────
    $conn->query("INSERT INTO activity_log
        (table_name, record_id, action, changed_by, changed_at, sync_flag)
        VALUES ('$safe_table', '$safe_record', '$safe_action', '$safe_username', '$now', 1)");
    $log_id = $conn->insert_id;

    // ── 2. If DELETE, add to deleted_records blacklist ────────
    if ($action === 'DELETE') {
        $conn->query("INSERT IGNORE INTO deleted_records
            (table_name, record_id, deleted_by, deleted_at)
            VALUES ('$safe_table', '$safe_record', '$safe_username', '$now')");
    }

    // ── 3. Get data snapshot for INSERT/UPDATE ────────────────
    $data_snapshot = null;
    if ($action !== 'DELETE') {
        $pk_col = AH_PK_MAP[$table] ?? 'id';
        $result = $conn->query(
            "SELECT * FROM `$safe_table` WHERE `$pk_col` = '$safe_record' LIMIT 1"
        );
        if ($result && $result->num_rows > 0) {
            $data_snapshot = json_encode($result->fetch_assoc());
        }
    }

    // ── 4. Add to pending_sync queue (ALWAYS — no Supabase here) ──
    //    Untuk DELETE: data_snapshot NULL, cukup deleted_records yang mencatat
    //    Untuk INSERT/UPDATE: simpan snapshot data
    $safe_snapshot = $data_snapshot ? $conn->real_escape_string($data_snapshot) : null;
    $snapshot_val  = $safe_snapshot ? "'$safe_snapshot'" : 'NULL';

    $conn->query("INSERT INTO pending_sync
        (table_name, record_id, action, data_snapshot, created_at)
        VALUES ('$safe_table', '$safe_record', '$safe_action', $snapshot_val, '$now')");

    return $log_id;
}

// ── Helper: cek berapa hari data pending tertua ───────────────
// Digunakan oleh index.php untuk warning
function get_oldest_pending_days($conn) {
    $res = $conn->query(
        "SELECT MIN(created_at) AS oldest FROM pending_sync"
    );
    if (!$res) return 0;
    $row = $res->fetch_assoc();
    if (!$row['oldest']) return 0;
    return (time() - strtotime($row['oldest'])) / 86400;
}

// ── Helper: jumlah item pending ──────────────────────────────
function get_pending_count($conn) {
    $res = $conn->query("SELECT COUNT(*) AS total FROM pending_sync");
    if (!$res) return 0;
    return (int)$res->fetch_assoc()['total'];
}

// ── Helper: jumlah deleted_records pending ───────────────────
function get_pending_deleted_count($conn) {
    $res = $conn->query("SELECT COUNT(*) AS total FROM deleted_records");
    if (!$res) return 0;
    return (int)$res->fetch_assoc()['total'];
}

// ── Internal: auto-create tables ─────────────────────────────
function _ah_ensure_tables($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS activity_log (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        table_name  VARCHAR(100) NOT NULL,
        record_id   VARCHAR(255) NOT NULL,
        action      ENUM('INSERT','UPDATE','DELETE') NOT NULL,
        changed_by  VARCHAR(100) NOT NULL,
        changed_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        sync_flag   TINYINT(1) DEFAULT 1
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS pending_sync (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        table_name    VARCHAR(100) NOT NULL,
        record_id     VARCHAR(255) NOT NULL,
        action        ENUM('INSERT','UPDATE','DELETE') NOT NULL,
        data_snapshot TEXT NULL,
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        is_processing TINYINT(1) DEFAULT 0
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS deleted_records (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        table_name  VARCHAR(100) NOT NULL,
        record_id   VARCHAR(255) NOT NULL,
        deleted_by  VARCHAR(100) NOT NULL,
        deleted_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        synced      TINYINT(1) DEFAULT 0,
        UNIQUE KEY unique_deleted (table_name, record_id)
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS sync_status (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        log_id     INT NOT NULL,
        username   VARCHAR(100) NOT NULL,
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_log_user (log_id, username)
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS last_cloud_push (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        pushed_at  DATETIME NOT NULL,
        pushed_by  VARCHAR(100) NOT NULL,
        total_rows INT DEFAULT 0,
        total_dels INT DEFAULT 0
    )");
}