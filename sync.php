<?php
// ============================================================
// sync.php — all-in-one device sync page (theme: Dark Neumorphism, matches index.php)
// Handles: zip creation, DB export/import, cross-device pull, and serving zip to the other device.
// ============================================================
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Explicitly pin the timezone. Without this, PHP falls back to whatever
// php.ini's date.timezone says (often UTC on a fresh XAMPP/Termux install),
// which silently breaks any time-of-day logic (like the Cross-Device Data
// Sync update window) if it doesn't match the device's actual local clock.
date_default_timezone_set('Asia/Jakarta');

// Pull (download zip + extract + mirror-delete + DB import) can legitimately
// take a while, especially over slow WiFi or on Android hardware. Without
// this, PHP's default execution time limit can kill the request mid-way and
// Apache returns its own HTML error page instead of our JSON response —
// causing "Unexpected token '<', <!DOCTYPE" on the client.
set_time_limit(300);
ini_set('max_execution_time', 300);
ini_set('memory_limit', '512M');

ob_start(); // buffers any accidental warning/notice output so it can be stripped
            // before AJAX/JSON or binary (zip) responses below — otherwise a single
            // stray PHP notice breaks JSON parsing on the client with errors like
            // "Unexpected token '<'".

// Safety net: if a FATAL error happens anywhere below (e.g. a missing PHP
// extension), PHP stops immediately and would normally dump raw HTML —
// breaking every AJAX call. This converts that into a valid JSON error
// instead, so the browser always gets parseable JSON, and the real error
// message is visible in the status box instead of a generic parse error.
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        if (ob_get_level() > 0) { ob_clean(); }
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'message' => 'Fatal server error: ' . $error['message'] . ' (' . basename($error['file']) . ':' . $error['line'] . ')',
        ]);
    }
});

// ============================================================
// CONFIG — SYNC_TOKEN must be IDENTICAL on both PC and Android.
// PC/Android IP + port are NOT hardcoded — they're stored in
// data_json/sync_config.json so they can be edited from the UI
// whenever the WiFi-assigned IP changes, without touching this file.
// ============================================================
define('SYNC_TOKEN', 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET');
define('SYNC_APP_FOLDER_NAME', 'optic_pos');

$appDir       = __DIR__;
$htdocsDir    = dirname($appDir);
$syncJsonDir  = $appDir . '/data_json';
$syncJsonFile = $syncJsonDir . '/sync_config.json';

function sync_default_config() {
    return [
        'pc_ip'        => '192.168.18.13',
        'pc_port'      => '80',
        'android_ip'   => '192.168.18.20',
        'android_port' => '8080',
    ];
}

function sync_load_config($jsonFile) {
    $defaults = sync_default_config();
    if (!file_exists($jsonFile)) return $defaults;
    $raw = file_get_contents($jsonFile);
    $data = json_decode($raw, true);
    if (!is_array($data)) return $defaults;
    return array_merge($defaults, $data);
}

function sync_save_config($jsonFile, $jsonDir, $newValues) {
    if (!is_dir($jsonDir)) mkdir($jsonDir, 0755, true);
    $current = sync_load_config($jsonFile);
    $merged = array_merge($current, $newValues);
    $ok = file_put_contents($jsonFile, json_encode($merged, JSON_PRETTY_PRINT));
    return $ok !== false ? $merged : false;
}

/**
 * Two-way pull confirmation: a 2-digit code generated on the SOURCE device
 * when the user runs "Create Local ZIP" there. The person doing the pull on
 * the OTHER device must read that code (by looking at the source device)
 * and type it in before the pull is allowed to proceed. This proves the
 * person is physically at (or in control of) the source device, not just
 * that the token/IP happen to be correct.
 */
function sync_otp_file($jsonDir) {
    return $jsonDir . '/sync_otp.json';
}

function sync_generate_otp($jsonDir) {
    if (!is_dir($jsonDir)) mkdir($jsonDir, 0755, true);
    $code = str_pad((string) random_int(0, 99), 2, '0', STR_PAD_LEFT);
    $data = [
        'code' => $code,
        'generated_at' => time(),
        'expires_at' => time() + 300, // 5 minutes
        'used' => false,
    ];
    file_put_contents(sync_otp_file($jsonDir), json_encode($data));
    return $code;
}

function sync_validate_and_consume_otp($jsonDir, $submittedCode) {
    $file = sync_otp_file($jsonDir);
    if (!file_exists($file)) {
        return ['ok' => false, 'message' => 'No confirmation code has been generated on this device yet. Run "Create Local ZIP" on this device first, then use the 2-digit code shown there.'];
    }
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data) || !isset($data['code'])) {
        return ['ok' => false, 'message' => 'Confirmation code data is corrupted. Generate a new one via "Create Local ZIP".'];
    }
    if (!empty($data['used'])) {
        return ['ok' => false, 'message' => 'This confirmation code was already used. Generate a new one via "Create Local ZIP".'];
    }
    if (time() > (int) ($data['expires_at'] ?? 0)) {
        return ['ok' => false, 'message' => 'Confirmation code expired. Generate a new one via "Create Local ZIP".'];
    }
    $submittedCode = str_pad(preg_replace('/\D/', '', (string) $submittedCode), 2, '0', STR_PAD_LEFT);
    if (!hash_equals((string) $data['code'], $submittedCode)) {
        return ['ok' => false, 'message' => 'Incorrect confirmation code.'];
    }
    $data['used'] = true;
    file_put_contents($file, json_encode($data));
    return ['ok' => true, 'message' => 'Confirmation code accepted.'];
}

$syncConfig = sync_load_config($syncJsonFile);

/**
 * Detects whether THIS device is the one configured as "PC" or "Android" in
 * sync_config.json. Used to disable the matching Pull card entirely.
 * Returns 'pc' | 'android' | 'unknown'.
 *
 * Primary signal: SERVER_PORT. Comparing IP alone is unreliable — if the
 * page is opened via "localhost" or "127.0.0.1" instead of the LAN IP,
 * SERVER_ADDR reports the loopback address instead of the real one, so it
 * never matches the configured pc_ip/android_ip. The port Apache is
 * actually listening on (80 for XAMPP, 8080 for Termux in this setup)
 * stays correct regardless of hostname used to reach it.
 */
function sync_detect_own_role($syncConfig) {
    $ownPort = (string) ($_SERVER['SERVER_PORT'] ?? '');
    $pcPort = (string) $syncConfig['pc_port'];
    $androidPort = (string) $syncConfig['android_port'];

    if ($ownPort !== '' && $pcPort !== $androidPort) {
        if ($ownPort === $pcPort) return 'pc';
        if ($ownPort === $androidPort) return 'android';
    }

    // Fallback: IP match (covers the case where both ports happen to be equal)
    $ownIp = $_SERVER['SERVER_ADDR'] ?? null;
    if ($ownIp !== null) {
        if ($ownIp === $syncConfig['pc_ip']) return 'pc';
        if ($ownIp === $syncConfig['android_ip']) return 'android';
    }

    return 'unknown';
}
$syncOwnRole = sync_detect_own_role($syncConfig);

// ============================================================
// HELPER FUNCTIONS
// ============================================================

/**
 * Zips only the "source code" portion of the app: top-level *.php/*.css/*.js
 * files directly in $sourceDir, plus the image/, manual/, and phpqrcode/
 * folders (recursively). Deliberately excludes database/, qrcodes/,
 * main_qrcodes/, data_json/, backups/, and .git — this is a code-only
 * update, never touches data.
 */
function sync_zip_code_only($sourceDir, $zipFile) {
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'message' => 'PHP ZipArchive extension is not enabled on this server.'];
    }
    if (!is_dir($sourceDir)) {
        return ['ok' => false, 'message' => "Source folder not found: $sourceDir"];
    }
    $zip = new ZipArchive();
    if (file_exists($zipFile)) @unlink($zipFile);
    if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return ['ok' => false, 'message' => "Could not create zip file: $zipFile"];
    }
    $topFolderName = basename($sourceDir);
    $fileCount = 0;

    // Top-level code/style files (non-recursive). db_config.php is deliberately
    // EXCLUDED — it's device-specific (PC has no socket param, Android needs
    // one for Termux's MariaDB). Overlaying the wrong one breaks the DB
    // connection entirely on the receiving device.
    $excludedRootFiles = ['db_config.php'];
    foreach (glob($sourceDir . '/*.{php,css,js}', GLOB_BRACE) as $filePath) {
        if (!is_file($filePath)) continue;
        if (in_array(basename($filePath), $excludedRootFiles, true)) continue;
        $zip->addFile($filePath, $topFolderName . '/' . basename($filePath));
        $fileCount++;
    }

    // Whole folders that are pure source/assets, never user data
    $codeFolders = ['image', 'manual', 'phpqrcode'];
    foreach ($codeFolders as $folderName) {
        $folderPath = $sourceDir . '/' . $folderName;
        if (!is_dir($folderPath)) continue;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folderPath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $relativePath = $topFolderName . '/' . $folderName . '/' . str_replace('\\', '/', substr($item->getPathname(), strlen($folderPath) + 1));
            if ($item->isDir()) {
                $zip->addEmptyDir($relativePath);
            } else {
                $zip->addFile($item->getPathname(), $relativePath);
                $fileCount++;
            }
        }
    }

    $zip->close();
    return ['ok' => true, 'message' => "Code-only zip created with $fileCount files.", 'file_count' => $fileCount];
}

function sync_zip_folder($sourceDir, $zipFile, $excludeNames = [], $excludeDirNames = ['.git', '.svn', 'backups']) {
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'message' => 'PHP ZipArchive extension is not enabled on this server. On XAMPP: open php.ini, uncomment (remove the leading ";" from) "extension=zip", then restart Apache. On Termux: run "pkg install php-zip" (or reinstall php), then restart the server.'];
    }
    if (!is_dir($sourceDir)) {
        return ['ok' => false, 'message' => "Source folder not found: $sourceDir"];
    }
    $zip = new ZipArchive();
    if (file_exists($zipFile)) @unlink($zipFile);
    if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return ['ok' => false, 'message' => "Could not create zip file: $zipFile"];
    }
    $topFolderName = basename($sourceDir);
    $fileCount = 0;
    $manifestFiles = []; // ['path' => relative path incl. top folder, 'size' => bytes]
    $totalSize = 0;

    // RecursiveCallbackFilterIterator lets us skip descending into excluded
    // directories entirely (e.g. .git) instead of just not adding them —
    // much faster than walking a whole git history just to discard it.
    $dirIterator = new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS);
    $filteredIterator = new RecursiveCallbackFilterIterator($dirIterator, function ($current) use ($excludeDirNames) {
        if ($current->isDir() && in_array($current->getFilename(), $excludeDirNames, true)) {
            return false; // don't descend into this directory at all
        }
        return true;
    });
    $iterator = new RecursiveIteratorIterator($filteredIterator, RecursiveIteratorIterator::SELF_FIRST);

    foreach ($iterator as $item) {
        if (in_array($item->getFilename(), $excludeNames, true)) continue;
        $relativePath = $topFolderName . '/' . substr($item->getPathname(), strlen($sourceDir) + 1);
        $relativePath = str_replace('\\', '/', $relativePath);
        if ($item->isDir()) {
            $zip->addEmptyDir($relativePath);
        } else {
            $zip->addFile($item->getPathname(), $relativePath);
            $fileCount++;
            $size = $item->getSize();
            $totalSize += $size;
            $manifestFiles[] = ['path' => $relativePath, 'size' => $size];
        }
    }

    // Manifest hash: a single fingerprint of the whole file list (path+size,
    // sorted for determinism) — lets the receiving device confirm everything
    // arrived intact with one comparison instead of eyeballing every file.
    $hashLines = array_map(function ($f) { return $f['path'] . '|' . $f['size']; }, $manifestFiles);
    sort($hashLines);
    $manifestHash = hash('sha256', implode("\n", $hashLines));

    $manifest = [
        'generated_at' => date('c'),
        'file_count'   => $fileCount,
        'total_size'   => $totalSize,
        'hash'         => $manifestHash,
        'files'        => $manifestFiles,
    ];
    // Stored at the zip ROOT (no top-folder prefix) so it lands next to the
    // app folder on extract, not inside it — kept separate from app files.
    $zip->addFromString('_sync_manifest.json', json_encode($manifest));

    $zip->close();
    return [
        'ok' => true,
        'message' => "Zip created with $fileCount files.",
        'file_count' => $fileCount,
        'total_size' => $totalSize,
        'manifest_hash' => $manifestHash,
        'manifest_files' => $manifestFiles,
    ];
}

/**
 * Walks an existing local folder (e.g. right after extraction) and builds
 * the same kind of manifest sync_zip_folder() produces, so it can be
 * compared against the source device's embedded _sync_manifest.json.
 */
function sync_build_folder_manifest($sourceDir, $excludeDirNames = ['.git', '.svn', 'backups']) {
    $topFolderName = basename($sourceDir);
    $manifestFiles = [];
    $totalSize = 0;

    if (!is_dir($sourceDir)) {
        return ['file_count' => 0, 'total_size' => 0, 'hash' => hash('sha256', ''), 'files' => []];
    }

    $dirIterator = new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS);
    $filteredIterator = new RecursiveCallbackFilterIterator($dirIterator, function ($current) use ($excludeDirNames) {
        if ($current->isDir() && in_array($current->getFilename(), $excludeDirNames, true)) {
            return false;
        }
        return true;
    });
    $iterator = new RecursiveIteratorIterator($filteredIterator, RecursiveIteratorIterator::SELF_FIRST);

    foreach ($iterator as $item) {
        if ($item->isDir()) continue;
        $relativePath = $topFolderName . '/' . str_replace('\\', '/', substr($item->getPathname(), strlen($sourceDir) + 1));
        $size = $item->getSize();
        $totalSize += $size;
        $manifestFiles[] = ['path' => $relativePath, 'size' => $size];
    }

    $hashLines = array_map(function ($f) { return $f['path'] . '|' . $f['size']; }, $manifestFiles);
    sort($hashLines);
    $hash = hash('sha256', implode("\n", $hashLines));

    return [
        'file_count' => count($manifestFiles),
        'total_size' => $totalSize,
        'hash'       => $hash,
        'files'      => $manifestFiles,
    ];
}

function sync_extract_zip($zipFile, $htdocsDir) {
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'message' => 'PHP ZipArchive extension is not enabled on this server.'];
    }
    if (!file_exists($zipFile)) return ['ok' => false, 'message' => "Zip file not found: $zipFile"];
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) return ['ok' => false, 'message' => "Could not open zip file: $zipFile"];
    if (!$zip->extractTo($htdocsDir)) {
        $zip->close();
        return ['ok' => false, 'message' => "Extraction failed."];
    }
    $numFiles = $zip->numFiles;
    $zip->close();
    return ['ok' => true, 'message' => "Extracted $numFiles entries into $htdocsDir"];
}

/**
 * Extracts a zip on top of htdocs AND deletes any local file/folder inside
 * $topFolderName that is NOT present in the zip — a true "mirror" overwrite,
 * not just an overlay. Use with care: anything local-only (e.g. a file the
 * other device doesn't have) will be permanently removed, except paths
 * matching $excludePatterns (matched against basename with fnmatch).
 *
 * @param string $zipFile        Path to the incoming zip
 * @param string $htdocsDir      Absolute path to htdocs (zip's top entry is extracted here)
 * @param string $topFolderName  The top-level folder name inside the zip (e.g. "optic_pos")
 * @param array  $excludePatterns  fnmatch patterns (basenames) to keep even if not in the zip
 * @return array ['ok' => bool, 'message' => string, 'deleted_count' => int]
 */
function sync_extract_zip_mirror($zipFile, $htdocsDir, $topFolderName, $excludePatterns = [], $excludeDirNames = ['.git', '.svn']) {
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'message' => 'PHP ZipArchive extension is not enabled on this server.', 'deleted_count' => 0];
    }
    if (!file_exists($zipFile)) return ['ok' => false, 'message' => "Zip file not found: $zipFile", 'deleted_count' => 0];
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) return ['ok' => false, 'message' => "Could not open zip file: $zipFile", 'deleted_count' => 0];

    // Collect every path contained in the zip (files AND their parent directories)
    $zipPaths = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = rtrim(str_replace('\\', '/', $zip->getNameIndex($i)), '/');
        $zipPaths[$name] = true;
        $parts = explode('/', $name);
        $path = '';
        foreach ($parts as $idx => $part) {
            $path = ($idx === 0) ? $part : $path . '/' . $part;
            $zipPaths[$path] = true;
        }
    }

    if (!$zip->extractTo($htdocsDir)) {
        $zip->close();
        return ['ok' => false, 'message' => "Extraction failed.", 'deleted_count' => 0];
    }
    $numFiles = $zip->numFiles;
    $zip->close();

    // Mirror pass: remove anything locally present that wasn't in the zip.
    // Directories in $excludeDirNames (e.g. .git) are skipped entirely —
    // never descended into, never deleted — since they're intentionally
    // excluded from the zip and are local-only, per-device state.
    $appDir = $htdocsDir . '/' . $topFolderName;
    $deleted = 0;
    if (is_dir($appDir)) {
        $dirIterator = new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS);
        $filteredIterator = new RecursiveCallbackFilterIterator($dirIterator, function ($current) use ($excludeDirNames) {
            if ($current->isDir() && in_array($current->getFilename(), $excludeDirNames, true)) {
                return false;
            }
            return true;
        });
        $iterator = new RecursiveIteratorIterator($filteredIterator, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $relative = $topFolderName . '/' . str_replace('\\', '/', substr($item->getPathname(), strlen($appDir) + 1));
            if (isset($zipPaths[$relative])) continue;

            $skip = false;
            foreach ($excludePatterns as $pattern) {
                if (fnmatch($pattern, $item->getFilename())) { $skip = true; break; }
            }
            if ($skip) continue;

            if ($item->isDir()) {
                @rmdir($item->getPathname()); // only succeeds if now empty
            } else {
                @unlink($item->getPathname());
                $deleted++;
            }
        }
    }

    return [
        'ok' => true,
        'message' => "Extracted $numFiles entries, removed $deleted local-only file(s) not present in the source.",
        'deleted_count' => $deleted,
    ];
}

function sync_export_db_to_sql($conn, $outputFile) {
    $tablesResult = $conn->query("SHOW TABLES");
    if (!$tablesResult) return ['ok' => false, 'message' => "SHOW TABLES failed: " . $conn->error, 'table_count' => 0];

    $fh = fopen($outputFile, 'w');
    if (!$fh) return ['ok' => false, 'message' => "Could not open $outputFile for writing", 'table_count' => 0];

    fwrite($fh, "-- Generated by sync.php on " . date('Y-m-d H:i:s') . "\n");
    fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n");
    fwrite($fh, "SET AUTOCOMMIT=0;\n");
    fwrite($fh, "START TRANSACTION;\n\n");

    $BATCH_SIZE = 200; // rows per INSERT statement — far fewer round-trips than one INSERT per row

    $tableCount = 0;
    while ($row = $tablesResult->fetch_array()) {
        $table = $row[0];
        $tableCount++;
        $createResult = $conn->query("SHOW CREATE TABLE `$table`");
        $createRow = $createResult->fetch_array();
        fwrite($fh, "DROP TABLE IF EXISTS `$table`;\n");
        fwrite($fh, $createRow[1] . ";\n\n");

        $dataResult = $conn->query("SELECT * FROM `$table`");
        if ($dataResult && $dataResult->num_rows > 0) {
            $fields = $dataResult->fetch_fields();
            $fieldNames = array_map(function ($f) { return "`{$f->name}`"; }, $fields);
            $fieldList = implode(', ', $fieldNames);

            // For the `users` table, scrub session_token / session_expires in the
            // EXPORTED dump only — this is a read-only SELECT, so the live database
            // rows are never modified. Prevents an active login session on one
            // device from leaking into / clashing with the other device via sync.
            $isUsersTable = (strtolower($table) === 'users');
            $columnNamesLower = array_map(function ($f) { return strtolower($f->name); }, $fields);

            $rowTuples = [];
            $flushBatch = function () use (&$rowTuples, $fh, $table, $fieldList) {
                if (empty($rowTuples)) return;
                fwrite($fh, "INSERT INTO `$table` ($fieldList) VALUES\n" . implode(",\n", $rowTuples) . ";\n");
                $rowTuples = [];
            };

            while ($dataRow = $dataResult->fetch_row()) {
                $values = [];
                foreach ($dataRow as $idx => $val) {
                    if ($isUsersTable && in_array($columnNamesLower[$idx], ['session_token', 'session_expires'], true)) {
                        $values[] = 'NULL';
                        continue;
                    }
                    $values[] = ($val === null) ? 'NULL' : "'" . $conn->real_escape_string($val) . "'";
                }
                $rowTuples[] = '(' . implode(', ', $values) . ')';
                if (count($rowTuples) >= $BATCH_SIZE) $flushBatch();
            }
            $flushBatch();
            fwrite($fh, "\n");
        }
    }
    fwrite($fh, "COMMIT;\n");
    fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);
    return ['ok' => true, 'message' => "Exported $tableCount tables.", 'table_count' => $tableCount];
}

function sync_import_sql_file($conn, $sqlFile) {
    if (!file_exists($sqlFile)) return ['ok' => false, 'message' => "SQL file not found: $sqlFile"];
    $sql = file_get_contents($sqlFile);
    if ($sql === false || trim($sql) === '') return ['ok' => false, 'message' => "SQL file is empty or unreadable"];
    if (!$conn->multi_query($sql)) return ['ok' => false, 'message' => "Import failed: " . $conn->error];

    $statementCount = 0;
    do {
        if ($result = $conn->store_result()) $result->free();
        $statementCount++;
        if ($conn->error) return ['ok' => false, 'message' => "Import error at statement $statementCount: " . $conn->error];
    } while ($conn->more_results() && $conn->next_result());

    return ['ok' => true, 'message' => "Database imported successfully ($statementCount statements)."];
}

/**
 * Fast-path import for Android/Termux: shells out to the native mariadb/mysql
 * CLI instead of going through PHP mysqli. Command-line import is much faster
 * for large dumps on mobile flash storage. Returns null (not an ['ok'=>..]
 * array) if shell_exec is disabled or no CLI client is found on PATH — the
 * caller should treat null as "not available, fall back to the PHP method",
 * so this is purely an optimization and never a hard dependency.
 */
function sync_import_sql_native_android($sqlFile) {
    if (!function_exists('shell_exec') || stripos((string) ini_get('disable_functions'), 'shell_exec') !== false) {
        return null;
    }
    if (!file_exists($sqlFile)) {
        return ['ok' => false, 'message' => "SQL file not found: $sqlFile"];
    }

    $binary = null;
    foreach (['mariadb', 'mysql'] as $candidate) {
        $which = trim((string) @shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null'));
        if ($which !== '') { $binary = $candidate; break; }
    }
    if ($binary === null) {
        return null; // no CLI client found — let the caller fall back to PHP
    }

    $socket = '/data/data/com.termux/files/usr/var/run/mysqld.sock';
    $cmd = escapeshellarg($binary) . ' --socket=' . escapeshellarg($socket)
        . ' -u root optic_pos_db < ' . escapeshellarg($sqlFile) . ' 2>&1';
    $output = @shell_exec($cmd);
    $output = ($output === null) ? '' : trim($output);

    if ($output !== '' && (stripos($output, 'error') !== false || stripos($output, 'denied') !== false || stripos($output, 'not found') !== false)) {
        return ['ok' => false, 'message' => "Native import ($binary) failed: $output"];
    }

    return ['ok' => true, 'message' => "Database imported successfully via native $binary client (fast path)."];
}

/**
 * Fast-path import for PC/XAMPP: shells out to mysql.exe from the XAMPP
 * install instead of PHP mysqli. Mirrors sync_import_sql_native_android().
 *
 * DISABLED for now: this path had a dangerous flaw — when the shell command
 * failed to actually run (e.g. wrong binary path, pipe/redirection not
 * behaving as expected on a given Windows setup), shell_exec() returned
 * empty output, which the old success check misread as "no errors == it
 * worked." That let a Pull silently report success while the database was
 * never actually imported. Until this can be verified with a much stricter
 * success check (e.g. confirming actual row changes, not just absence of
 * the word "error" in the output), this always returns null so callers
 * fall back to the proven PHP mysqli import path.
 */
function sync_import_sql_native_pc($sqlFile) {
    return null; // disabled — see comment above. Falls back to sync_import_sql_file().
}

/**
 * (Kept for future reference, currently unreachable while the function
 * above returns null unconditionally.)
 */
function sync_import_sql_native_pc_UNUSED($sqlFile) {
    if (!function_exists('shell_exec') || stripos((string) ini_get('disable_functions'), 'shell_exec') !== false) {
        return null;
    }
    if (!file_exists($sqlFile)) {
        return ['ok' => false, 'message' => "SQL file not found: $sqlFile"];
    }
    if (DIRECTORY_SEPARATOR !== '\\') {
        return null; // this device isn't Windows — not applicable
    }

    $candidates = [
        'C:\\xampp\\mysql\\bin\\mysql.exe',
        'C:\\xampp\\mysql\\bin\\mariadb.exe',
    ];
    $binary = null;
    foreach ($candidates as $candidate) {
        if (file_exists($candidate)) { $binary = $candidate; break; }
    }
    if ($binary === null) {
        // Fall back to checking PATH
        $which = trim((string) @shell_exec('where mysql 2>nul'));
        if ($which !== '') { $binary = explode("\n", $which)[0]; }
    }
    if ($binary === null) {
        return null; // no CLI client found — let the caller fall back to PHP
    }

    // Windows cmd.exe doesn't support `< file` the same way inside shell_exec
    // reliably across setups, so pipe the file content in via `type`.
    $cmd = 'type ' . escapeshellarg($sqlFile) . ' | ' . escapeshellarg($binary) . ' -u root optic_pos_db 2>&1';
    $output = @shell_exec($cmd);
    $output = ($output === null) ? '' : trim($output);

    if ($output !== '' && (stripos($output, 'error') !== false || stripos($output, 'denied') !== false || stripos($output, 'not recognized') !== false)) {
        return ['ok' => false, 'message' => "Native import (mysql.exe) failed: $output"];
    }

    return ['ok' => true, 'message' => "Database imported successfully via native mysql.exe client (fast path)."];
}

function sync_format_bytes($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

/**
 * Sanitizes an IP address (or any string) for safe use as a folder name.
 */
function sync_sanitize_ip_folder_name($ip) {
    return preg_replace('/[^a-zA-Z0-9._-]/', '_', $ip);
}

/**
 * Backs up this device's current database into database/backups/<own_ip>/,
 * keeping at most $maxKeep files per IP folder — the oldest backup is
 * deleted automatically once a new one would exceed the limit.
 */
function sync_backup_db_with_retention($conn, $appDir, $maxKeep = 7) {
    $ownIp = sync_sanitize_ip_folder_name($_SERVER['SERVER_ADDR'] ?? 'unknown_ip');
    $backupDir = $appDir . '/database/backups/' . $ownIp;
    if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);

    $backupFile = $backupDir . '/backup_' . date('Ymd_His') . '.sql';
    $result = sync_export_db_to_sql($conn, $backupFile);

    // Retention: keep only the $maxKeep most recent backups in this IP's folder
    $existing = glob($backupDir . '/backup_*.sql');
    if ($existing !== false && count($existing) > $maxKeep) {
        usort($existing, function ($a, $b) { return filemtime($a) <=> filemtime($b); });
        $toDelete = array_slice($existing, 0, count($existing) - $maxKeep);
        foreach ($toDelete as $oldFile) {
            @unlink($oldFile);
        }
    }

    return ['ok' => $result['ok'], 'message' => $result['message'], 'file' => $backupFile, 'ip_folder' => $ownIp];
}

/**
 * Restores a .sql file into the CURRENT device's database, trying the
 * fast native CLI path first (matching this device's detected role) and
 * falling back to the PHP mysqli method — same strategy used by Pull.
 */
function sync_import_sql_native_dispatch($sqlFile, $ownRole, $conn) {
    $result = null;
    if ($ownRole === 'android') {
        $result = sync_import_sql_native_android($sqlFile);
    } elseif ($ownRole === 'pc') {
        $result = sync_import_sql_native_pc($sqlFile);
    }
    if ($result === null) {
        $result = sync_import_sql_file($conn, $sqlFile);
    }
    return $result;
}

function sync_parse_activity_item($listValue) {
    $listValue = trim($listValue);
    if (preg_match('/^(.+?)\s*\[folder\]\s*$/i', $listValue, $m)) {
        return ['type' => 'folder', 'name' => trim($m[1])];
    }
    return ['type' => 'table', 'name' => $listValue];
}

function sync_find_date_column($conn, $table) {
    // Explicit mapping based on the actual optic_pos_db schema — far more
    // reliable than guessing from column names/types. Update this list if
    // tables are added/changed.
    $knownDateColumns = [
        'customer_examinations'        => 'updated_at',   // added via add_updated_at_columns.sql, auto-tracks real last-changed time
        'customer_orders'               => 'updated_at',
        'custom_frames'                 => 'updated_at',   // added via add_updated_at_columns.sql
        'deleted_records'                => 'deleted_at',
        'frames_main'                    => 'updated_at',
        'frame_staging'                  => 'updated_at',
        'prescription_modifications'    => 'modified_at',
        'activity_log'                   => 'changed_at',
    ];
    if (isset($knownDateColumns[$table])) {
        return $knownDateColumns[$table];
    }

    // Fallback for any table not in the map above: best-effort guess.
    $result = $conn->query("SHOW COLUMNS FROM `$table`");
    if (!$result) return null;
    $candidates = [];
    while ($col = $result->fetch_assoc()) {
        $type = strtolower($col['Type']);
        if (strpos($type, 'date') !== false || strpos($type, 'timestamp') !== false) {
            $candidates[] = $col['Field'];
        }
    }
    if (empty($candidates)) return null;
    foreach (['changed_at', 'updated_at', 'created_at', 'modified_at', 'deleted_at', 'examination_date', 'order_date', 'date'] as $preferred) {
        if (in_array($preferred, $candidates, true)) return $preferred;
    }
    return $candidates[0];
}

function sync_export_table_rows_partial($conn, $table, $dateStr) {
    $dateCol = sync_find_date_column($conn, $table);
    if ($dateCol !== null) {
        $safeDate = $conn->real_escape_string(date('Y-m-d', strtotime($dateStr)));
        $where = "WHERE DATE(`$dateCol`) = '" . $safeDate . "'";
    } else {
        $where = '';
    }

    $dataResult = $conn->query("SELECT * FROM `$table` $where");
    $sql = '';
    $rowCount = 0;
    if ($dataResult && $dataResult->num_rows > 0) {
        $fields = $dataResult->fetch_fields();
        $fieldNames = array_map(function ($f) { return "`{$f->name}`"; }, $fields);
        $fieldList = implode(', ', $fieldNames);
        $isUsersTable = (strtolower($table) === 'users');
        $columnNamesLower = array_map(function ($f) { return strtolower($f->name); }, $fields);

        while ($row = $dataResult->fetch_row()) {
            $values = [];
            foreach ($row as $idx => $val) {
                if ($isUsersTable && in_array($columnNamesLower[$idx], ['session_token', 'session_expires'], true)) {
                    $values[] = 'NULL';
                    continue;
                }
                $values[] = ($val === null) ? 'NULL' : "'" . $conn->real_escape_string($val) . "'";
            }
            $sql .= "REPLACE INTO `$table` ($fieldList) VALUES (" . implode(', ', $values) . ");\n";
            $rowCount++;
        }
    }
    return ['sql' => $sql, 'row_count' => $rowCount, 'date_col' => $dateCol];
}

function sync_zip_specific_folders($appDir, $folderNames, $zipFile) {
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'message' => 'PHP ZipArchive extension is not enabled on this server.'];
    }
    $zip = new ZipArchive();
    if (file_exists($zipFile)) @unlink($zipFile);
    if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return ['ok' => false, 'message' => "Could not create zip file: $zipFile"];
    }
    $topFolderName = basename($appDir);
    $fileCount = 0;
    $fileList = [];
    foreach ($folderNames as $folderName) {
        $folderPath = $appDir . '/' . $folderName;
        if (!is_dir($folderPath)) continue;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folderPath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $relativePath = $topFolderName . '/' . $folderName . '/' . str_replace('\\', '/', substr($item->getPathname(), strlen($folderPath) + 1));
            if ($item->isDir()) {
                $zip->addEmptyDir($relativePath);
            } else {
                $zip->addFile($item->getPathname(), $relativePath);
                $fileCount++;
                $fileList[] = [
                    'path' => $relativePath,
                    'size' => sync_format_bytes($item->getSize()),
                    'crc32' => sprintf('%08x', crc32(file_get_contents($item->getPathname()))),
                ];
            }
        }
    }
    return ['ok' => true, 'zip' => $zip, 'file_count' => $fileCount, 'file_list' => $fileList];
}

function sync_is_within_update_window($settingValue) {
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim((string) $settingValue), $m)) {
        return false;
    }
    $hour = (int) $m[1];
    $min = (int) $m[2];
    $now = time();
    $todayStart = mktime($hour, $min, 0, (int) date('n'), (int) date('j'), (int) date('Y'));
    foreach ([$todayStart, strtotime('-1 day', $todayStart)] as $start) {
        if ($now >= $start && $now < ($start + 8 * 3600)) return true;
    }
    return false;
}

/**
 * Per-file CRC32 map (relative path => crc32 hex), excluding .git/.svn/backups.
 * Used to pinpoint exactly which files differ between devices.
 */
function sync_build_file_crc_manifest($appDir, $excludeDirNames = ['.git', '.svn', 'backups']) {
    $files = [];
    if (!is_dir($appDir)) return $files;
    // db_config.php is EXPECTED to differ between devices (Android needs a
    // socket param, PC doesn't) — comparing it would always false-flag as a
    // mismatch even when everything is actually fine.
    $excludedFiles = ['db_config.php'];
    $dirIterator = new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS);
    $filtered = new RecursiveCallbackFilterIterator($dirIterator, function ($current) use ($excludeDirNames) {
        if ($current->isDir() && in_array($current->getFilename(), $excludeDirNames, true)) return false;
        return true;
    });
    $iterator = new RecursiveIteratorIterator($filtered, RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $item) {
        if ($item->isDir()) continue;
        if (in_array($item->getFilename(), $excludedFiles, true)) continue;
        $rel = str_replace('\\', '/', substr($item->getPathname(), strlen($appDir) + 1));
        $files[$rel] = sprintf('%08x', crc32(file_get_contents($item->getPathname())));
    }
    return $files;
}

/**
 * Per-table content hash (table name => sha256 of its row data), so a
 * mismatch can be pinpointed to exactly which table(s) differ instead of
 * just "the database differs somewhere."
 */
function sync_compute_per_table_hashes($conn) {
    $hashes = [];
    $tablesResult = $conn->query("SHOW TABLES");
    if (!$tablesResult) return $hashes;
    while ($row = $tablesResult->fetch_array()) {
        $table = $row[0];
        $dataResult = $conn->query("SELECT * FROM `$table`");
        $lines = [];
        if ($dataResult) {
            $fields = $dataResult->fetch_fields();
            $isUsersTable = (strtolower($table) === 'users');
            $colsLower = array_map(function ($f) { return strtolower($f->name); }, $fields);
            while ($r = $dataResult->fetch_row()) {
                $vals = [];
                foreach ($r as $idx => $v) {
                    if ($isUsersTable && in_array($colsLower[$idx], ['session_token', 'session_expires', 'last_login'], true)) {
                        $vals[] = 'NULL';
                        continue;
                    }
                    $vals[] = ($v === null) ? 'NULL' : $v;
                }
                $lines[] = implode('|', $vals);
            }
        }
        sort($lines); // order-independent — row order shouldn't count as a "difference"
        $hashes[$table] = hash('sha256', implode("\n", $lines));
    }
    return $hashes;
}

/**
 * Computes a fingerprint of this device's entire current state: per-file
 * CRC32s plus per-table content hashes. Used by "Verify Full Sync" to
 * confirm PC and Android are identical, AND to pinpoint exactly which
 * files/tables differ when they aren't — without transferring anything.
 */
function sync_compute_full_state_hash($appDir, $conn) {
    $fileCrcMap = sync_build_file_crc_manifest($appDir);
    ksort($fileCrcMap);
    $tableHashes = sync_compute_per_table_hashes($conn);
    ksort($tableHashes);
    return [
        'file_hash' => hash('sha256', json_encode($fileCrcMap)),
        'file_count' => count($fileCrcMap),
        'files' => $fileCrcMap,
        'db_hash' => hash('sha256', json_encode($tableHashes)),
        'tables' => $tableHashes,
    ];
}

function sync_db_config_content($variant) {
    if ($variant === 'android') {
        return <<<'DBEOF'
<?php
$servername = "localhost";
$db_username = "root";
$db_password = "";
$dbname = "optic_pos_db";
$socket = "/data/data/com.termux/files/usr/var/run/mysqld.sock";
$conn = new mysqli($servername, $db_username, $db_password, $dbname, 3306, $socket);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8");
if (!function_exists('close_db_connection')) {
    function close_db_connection($conn) {
        if ($conn) { $conn->close(); }
    }
}
?>
DBEOF;
    }
    return <<<'DBEOF'
<?php
$servername = "localhost";
$db_username = "root";
$db_password = "";
$dbname = "optic_pos_db";
$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8");
if (!function_exists('close_db_connection')) {
    function close_db_connection($conn) {
        if ($conn) { $conn->close(); }
    }
}
?>
DBEOF;
}

// ============================================================
// ENDPOINT: this device SERVES its zip to the other device.
// Token-protected only (no session — the other device isn't logged in here).
// Called as: sync.php?action=serve&token=...
// ============================================================
if (isset($_GET['action']) && $_GET['action'] === 'serve') {
    include 'db_config.php'; // provides $conn

    $token = $_GET['token'] ?? '';
    if (!hash_equals(SYNC_TOKEN, $token)) {
        http_response_code(403);
        if (ob_get_level() > 0) ob_clean();
        header('Content-Type: application/json');
        if (ob_get_level() > 0) { ob_clean(); }
        echo json_encode(['ok' => false, 'message' => 'Invalid or missing token.']);
        exit();
    }

    // Two-way pull confirmation: the puller must supply the 2-digit code that
    // was generated HERE via "Create Local ZIP". See sync_generate_otp().
    $otpCheck = sync_validate_and_consume_otp($syncJsonDir, $_GET['otp'] ?? '');
    if (!$otpCheck['ok']) {
        http_response_code(403);
        if (ob_get_level() > 0) ob_clean();
        header('Content-Type: application/json');
        if (ob_get_level() > 0) { ob_clean(); }
        echo json_encode(['ok' => false, 'message' => $otpCheck['message']]);
        exit();
    }

    $dbDumpPath = $appDir . '/database/optic_pos_db.sql';
    $zipPath    = $htdocsDir . '/' . SYNC_APP_FOLDER_NAME . '.zip';

    if (!is_dir($appDir . '/database')) mkdir($appDir . '/database', 0755, true);

    $dbResult = sync_export_db_to_sql($conn, $dbDumpPath);
    if (!$dbResult['ok']) {
        http_response_code(500);
        if (ob_get_level() > 0) ob_clean();
        header('Content-Type: application/json');
        if (ob_get_level() > 0) { ob_clean(); }
        echo json_encode(['ok' => false, 'message' => 'DB export failed: ' . $dbResult['message']]);
        exit();
    }

    $zipResult = sync_zip_folder($appDir, $zipPath, [SYNC_APP_FOLDER_NAME . '.zip']);
    if (!$zipResult['ok']) {
        http_response_code(500);
        if (ob_get_level() > 0) ob_clean();
        header('Content-Type: application/json');
        if (ob_get_level() > 0) { ob_clean(); }
        echo json_encode(['ok' => false, 'message' => 'Zip creation failed: ' . $zipResult['message']]);
        exit();
    }

    if (ob_get_level() > 0) ob_clean();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . SYNC_APP_FOLDER_NAME . '.zip"');
    header('Content-Length: ' . filesize($zipPath));
    readfile($zipPath);
    exit();
}

// ============================================================
// ENDPOINT: lightweight connectivity check — confirms Apache/PHP is
// alive on this device and reports its local IP. No token required
// since this reveals nothing except "the server is up". Used by the
// "Test Koneksi" button so a wrong/stale IP or a stopped server is
// caught BEFORE attempting an actual zip/DB pull.
// Called as: sync.php?action=ping
// ============================================================
if (isset($_GET['action']) && $_GET['action'] === 'ping') {
    if (ob_get_level() > 0) ob_clean();
    header('Content-Type: application/json');
    if (ob_get_level() > 0) { ob_clean(); }
    echo json_encode([
        'ok' => true,
        'device_ip' => $_SERVER['SERVER_ADDR'] ?? null,
        'app_folder_exists' => is_dir($appDir),
        'time' => date('H:i:s'),
    ]);
    exit();
}

// ============================================================
// ENDPOINT: serve a CODE-ONLY zip (no database, no user data folders).
// Used by "Update Source Code (from PC)" on Android. Token-protected only,
// no OTP — this never touches data, so the two-way confirmation used for
// full Pull isn't required here.
// Called as: sync.php?action=serve_code&token=...
// ============================================================
if (isset($_GET['action']) && $_GET['action'] === 'serve_code') {
    $token = $_GET['token'] ?? '';
    if (!hash_equals(SYNC_TOKEN, $token)) {
        http_response_code(403);
        if (ob_get_level() > 0) ob_clean();
        header('Content-Type: application/json');
        if (ob_get_level() > 0) { ob_clean(); }
        echo json_encode(['ok' => false, 'message' => 'Invalid or missing token.']);
        exit();
    }

    // ignore_user_abort: keeps this script running to completion (including
    // cleanup below) even if the requesting device times out/disconnects
    // early — otherwise a half-finished request can leave a locked temp zip
    // behind on Windows, which then breaks the NEXT request that tries to
    // overwrite it.
    ignore_user_abort(true);

    // A unique filename per request (not a fixed name) avoids ever colliding
    // with a leftover/locked zip from a previous request.
    $zipPath = sys_get_temp_dir() . '/' . SYNC_APP_FOLDER_NAME . '_code_only_' . uniqid() . '.zip';
    $zipResult = sync_zip_code_only($appDir, $zipPath);
    if (!$zipResult['ok']) {
        @unlink($zipPath);
        http_response_code(500);
        if (ob_get_level() > 0) ob_clean();
        header('Content-Type: application/json');
        if (ob_get_level() > 0) { ob_clean(); }
        echo json_encode(['ok' => false, 'message' => $zipResult['message']]);
        exit();
    }

    if (ob_get_level() > 0) ob_clean();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . SYNC_APP_FOLDER_NAME . '_code_only.zip"');
    header('Content-Length: ' . filesize($zipPath));
    readfile($zipPath);
    @unlink($zipPath);
    exit();
}

// ============================================================
// ENDPOINT: receive a PARTIAL update pushed from the other device
// (activity_log-driven). Token-protected, no session — the sender isn't
// logged in here. Accepts the raw zip bytes as the POST body: folders are
// overlaid (never deleted — this is a subset, not a full mirror), and
// _partial_update.sql (if present) is imported into this device's DB.
// Called as: sync.php?action=receive_partial_update&token=... (POST body = zip bytes)
// ============================================================
// ============================================================
// ENDPOINT: reports this device's current state fingerprint (file manifest
// hash + database dump hash), for "Verify Full Sync" to compare against the
// other device WITHOUT transferring or changing anything.
// Called as: sync.php?action=get_full_state_hash&token=...
// ============================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_full_state_hash') {
    $token = $_GET['token'] ?? '';
    if (!hash_equals(SYNC_TOKEN, $token)) {
        http_response_code(403);
        if (ob_get_level() > 0) ob_clean();
        header('Content-Type: application/json');
        if (ob_get_level() > 0) { ob_clean(); }
        echo json_encode(['ok' => false, 'message' => 'Invalid or missing token.']);
        exit();
    }
    include 'db_config.php'; // provides $conn
    $state = sync_compute_full_state_hash($appDir, $conn);
    if (ob_get_level() > 0) ob_clean();
    header('Content-Type: application/json');
    if (ob_get_level() > 0) { ob_clean(); }
    echo json_encode(array_merge(['ok' => true], $state));
    exit();
}

if (isset($_GET['action']) && $_GET['action'] === 'receive_partial_update') {
    $token = $_GET['token'] ?? '';
    if (!hash_equals(SYNC_TOKEN, $token)) {
        http_response_code(403);
        if (ob_get_level() > 0) ob_clean();
        header('Content-Type: application/json');
        if (ob_get_level() > 0) { ob_clean(); }
        echo json_encode(['ok' => false, 'message' => 'Invalid or missing token.']);
        exit();
    }

    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || strlen($rawBody) === 0) {
        if (ob_get_level() > 0) ob_clean();
        header('Content-Type: application/json');
        if (ob_get_level() > 0) { ob_clean(); }
        echo json_encode(['ok' => false, 'message' => 'No data received.']);
        exit();
    }

    $tmpZip = sys_get_temp_dir() . '/' . SYNC_APP_FOLDER_NAME . '_partial_incoming.zip';
    file_put_contents($tmpZip, $rawBody);

    if (!class_exists('ZipArchive')) {
        @unlink($tmpZip);
        if (ob_get_level() > 0) ob_clean();
        header('Content-Type: application/json');
        if (ob_get_level() > 0) { ob_clean(); }
        echo json_encode(['ok' => false, 'message' => 'PHP ZipArchive extension is not enabled on this server.']);
        exit();
    }
    $zip = new ZipArchive();
    if ($zip->open($tmpZip) !== true) {
        @unlink($tmpZip);
        if (ob_get_level() > 0) ob_clean();
        header('Content-Type: application/json');
        if (ob_get_level() > 0) { ob_clean(); }
        echo json_encode(['ok' => false, 'message' => 'Could not open the incoming update zip.']);
        exit();
    }
    $extracted = $zip->extractTo($htdocsDir); // overlay only — no mirror-delete
    $numFiles = $zip->numFiles;
    $zip->close();
    @unlink($tmpZip);

    if (!$extracted) {
        if (ob_get_level() > 0) ob_clean();
        header('Content-Type: application/json');
        if (ob_get_level() > 0) { ob_clean(); }
        echo json_encode(['ok' => false, 'message' => 'Extraction failed.']);
        exit();
    }

    $sqlFile = $htdocsDir . '/_partial_update.sql';
    $importMessage = 'No database changes in this update.';
    $conn = null;
    if (file_exists($sqlFile) && filesize($sqlFile) > 0) {
        include 'db_config.php'; // provides $conn for this unauthenticated request
        $importResult = sync_import_sql_file($conn, $sqlFile);
        $importMessage = $importResult['message'];
        if (!$importResult['ok']) {
            @unlink($sqlFile);
            if (ob_get_level() > 0) ob_clean();
            header('Content-Type: application/json');
            if (ob_get_level() > 0) { ob_clean(); }
            echo json_encode(['ok' => false, 'message' => 'File update OK, but DB import failed: ' . $importMessage]);
            exit();
        }
    }
    @unlink($sqlFile);

    // Verify what actually landed against the sender's manifest (file CRCs +
    // expected row counts per table), so both sides can trust the push worked.
    $manifestPath = $htdocsDir . '/_partial_update_manifest.json';
    $verification = ['available' => false];
    if (file_exists($manifestPath)) {
        $manifest = json_decode(file_get_contents($manifestPath), true);
        @unlink($manifestPath);
        if (is_array($manifest)) {
            $filesChecked = 0;
            $filesMismatched = [];
            foreach (($manifest['files'] ?? []) as $f) {
                $filesChecked++;
                $localPath = $htdocsDir . '/' . $f['path'];
                if (!file_exists($localPath)) {
                    $filesMismatched[] = $f['path'] . ' (missing)';
                    continue;
                }
                $localCrc = sprintf('%08x', crc32(file_get_contents($localPath)));
                if ($localCrc !== $f['crc32']) {
                    $filesMismatched[] = $f['path'] . ' (checksum mismatch)';
                }
            }

            $tablesChecked = 0;
            $tablesMismatched = [];
            if ($conn !== null) {
                foreach (($manifest['tables'] ?? []) as $t) {
                    $tablesChecked++;
                    $actualCount = 0;
                    if (!empty($t['date_col'])) {
                        $safeDate = $conn->real_escape_string($t['date']);
                        $r = $conn->query("SELECT COUNT(*) AS c FROM `{$t['table']}` WHERE DATE(`{$t['date_col']}`) = '$safeDate'");
                        $actualCount = $r ? (int) $r->fetch_assoc()['c'] : 0;
                    } else {
                        $r = $conn->query("SELECT COUNT(*) AS c FROM `{$t['table']}`");
                        $actualCount = $r ? (int) $r->fetch_assoc()['c'] : 0;
                    }
                    if ($actualCount < (int) $t['expected_rows']) {
                        $tablesMismatched[] = "{$t['table']} (expected {$t['expected_rows']}, found $actualCount)";
                    }
                }
            }

            $verification = [
                'available' => true,
                'files_ok' => empty($filesMismatched),
                'files_checked' => $filesChecked,
                'files_mismatched' => $filesMismatched,
                'tables_ok' => empty($tablesMismatched),
                'tables_checked' => $tablesChecked,
                'tables_mismatched' => $tablesMismatched,
            ];
        }
    }

    if (ob_get_level() > 0) ob_clean();
    header('Content-Type: application/json');
    if (ob_get_level() > 0) { ob_clean(); }
    echo json_encode(['ok' => true, 'message' => "Applied $numFiles file(s). $importMessage", 'verification' => $verification]);
    exit();
}

// ============================================================
// Normal page load below this point needs a logged-in admin session.
// ============================================================
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
$current_role = $_SESSION['role'];
if ($current_role !== 'admin') {
    header("Location: index.php");
    exit();
}

include 'db_config.php';
include 'config_helper.php';
include 'auth_check.php';

// --- Fetch the configured Main Admin username (used to gate the Full System card) ---
$sync_main_admin_username = '';
$mainAdminResult = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'main_admin_username' LIMIT 1");
if ($mainAdminResult && $mainAdminResult->num_rows > 0) {
    $sync_main_admin_username = $mainAdminResult->fetch_assoc()['setting_value'] ?? '';
}

// --- Activity-log-driven cross-device update: time window + pending count ---
$syncBlockingTimeSetting = '';
$blockingTimeResult = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'db_backup_blocking_time' LIMIT 1");
if ($blockingTimeResult && $blockingTimeResult->num_rows > 0) {
    $syncBlockingTimeSetting = $blockingTimeResult->fetch_assoc()['setting_value'] ?? '';
}
$syncUpdateWindowOpen = sync_is_within_update_window($syncBlockingTimeSetting);

$syncPendingActivityCount = 0;
$activityCountResult = @$conn->query("SELECT COUNT(*) AS c FROM activity_log");
if ($activityCountResult && $activityCountResult->num_rows > 0) {
    $syncPendingActivityCount = (int) ($activityCountResult->fetch_assoc()['c'] ?? 0);
}

// --- Connection-test gating: IP Settings and Full System stay locked until
//     both "Test PC" and "Test Android" have succeeded at least once this session. ---
$syncPcTestOk      = $_SESSION['sync_conn_ok']['pc'] ?? null;      // null = not tested yet, true/false = last result
$syncAndroidTestOk = $_SESSION['sync_conn_ok']['android'] ?? null;
$syncBothConnected = ($syncPcTestOk === true && $syncAndroidTestOk === true);
// IP Settings stays locked (not expandable) until a test has actually failed
// for at least one device — before any test, or once both succeed, it's locked.
$syncIpSettingsLocked = !($syncPcTestOk === false || $syncAndroidTestOk === false);

// Fly-window content for each card's info button — built as plain PHP strings
// here and injected via json_encode() in the HTML below, so there's no
// manual quote-escaping inside onclick attributes (which was fragile and
// previously broke the Connection Test info button).
$syncInfoConnTest = 'Checks whether Apache on the PC/Android is running and reachable, before attempting a real sync.<br><br>This device\'s IP right now: <code>' . htmlspecialchars($_SERVER['SERVER_ADDR'] ?? 'unknown') . '</code>';
$syncInfoIpSettings = 'Locked until a Connection Test actually fails for a device — before any test, or once both succeed, editing is blocked to prevent accidental changes. A device becomes editable here only while its Connection Test is failing.';
$syncInfoCreateZip = 'Creates <code>optic_pos.zip</code> in the htdocs folder (this device), complete with the latest database dump and a file manifest.<br><br>Also generates a 2-digit confirmation code the OTHER device needs to enter before it can pull from this one.';
$syncInfoPullPc = 'This device will fetch data from the PC (' . htmlspecialchars($syncConfig['pc_ip'] . ':' . $syncConfig['pc_port']) . '), then extract it and import its database.<br><br><b>Mirror overwrite: any local file not present on the PC will be deleted.</b><br><br>Run this from <b>Android</b>. Requires the 2-digit code shown on the PC after it runs "Create Local ZIP".';
if ($syncOwnRole === 'pc') $syncInfoPullPc .= '<br><br>🚫 This device IS the configured PC — cannot pull from itself.';
$syncInfoPullAndroid = 'This device will fetch data from Android (' . htmlspecialchars($syncConfig['android_ip'] . ':' . $syncConfig['android_port']) . '), then extract it and import its database.<br><br><b>Mirror overwrite: any local file not present on Android will be deleted.</b><br><br>Run this from <b>PC</b>. Requires the 2-digit code shown on Android after it runs "Create Local ZIP".';
if ($syncOwnRole === 'android') $syncInfoPullAndroid .= '<br><br>🚫 This device IS the configured Android — cannot pull from itself.';

// ============================================================
// AJAX ACTIONS (require session — checked above)
// ============================================================
if (isset($_POST['action'])) {
    if (ob_get_level() > 0) ob_clean();
    header('Content-Type: application/json');
    $action = $_POST['action'];

    // ---- 0. Test connectivity to the other device (no data moved) ----
    if ($action === 'test_connection') {
        $target = $_POST['target'] ?? '';
        if ($target === 'pc') {
            $pingUrl = "http://" . $syncConfig['pc_ip'] . ":" . $syncConfig['pc_port'] . "/" . SYNC_APP_FOLDER_NAME . "/sync.php?action=ping";
        } elseif ($target === 'android') {
            $pingUrl = "http://" . $syncConfig['android_ip'] . ":" . $syncConfig['android_port'] . "/" . SYNC_APP_FOLDER_NAME . "/sync.php?action=ping";
        } else {
            if (ob_get_level() > 0) { ob_clean(); }
            echo json_encode(['ok' => false, 'message' => 'Unknown target.']);
            exit();
        }

        $myIp = $_SERVER['SERVER_ADDR'] ?? null;

        $start = microtime(true);
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $response = @file_get_contents($pingUrl, false, $ctx);
        $elapsedMs = round((microtime(true) - $start) * 1000);

        if ($response === false) {
            $_SESSION['sync_conn_ok'][$target] = false;
            if (ob_get_level() > 0) { ob_clean(); }
            echo json_encode([
                'ok' => false,
                'message' => "Could not reach $pingUrl. Possible causes: Apache is down on the target device, the IP/port in the config is wrong, or the two devices aren't on the same WiFi network.",
                'my_ip' => $myIp,
            ]);
            exit();
        }

        $parsed = json_decode($response, true);
        $theirIp = $parsed['device_ip'] ?? null;

        // Rough same-subnet check: compare the first 3 octets (typical /24 home WiFi)
        $sameSubnet = null;
        if ($myIp && $theirIp) {
            $myPrefix = implode('.', array_slice(explode('.', $myIp), 0, 3));
            $theirPrefix = implode('.', array_slice(explode('.', $theirIp), 0, 3));
            $sameSubnet = ($myPrefix === $theirPrefix);
        }

        $_SESSION['sync_conn_ok'][$target] = true;

        if (ob_get_level() > 0) { ob_clean(); }
        echo json_encode([
            'ok' => true,
            'message' => "Connected ({$elapsedMs}ms). This device's IP: $myIp, target's IP: $theirIp." .
                ($sameSubnet === false ? " ⚠️ Looks like different WiFi subnets — double check." : ($sameSubnet === true ? " Same WiFi network." : "")),
            'my_ip' => $myIp,
            'their_ip' => $theirIp,
            'latency_ms' => $elapsedMs,
            'both_connected' => !empty($_SESSION['sync_conn_ok']['pc']) && !empty($_SESSION['sync_conn_ok']['android']),
        ]);
        exit();
    }

    // ---- Verify Full Sync: compare this device's entire code+DB state against the other's ----
    if ($action === 'verify_full_sync') {
        if ($syncOwnRole === 'android') {
            $otherUrl = "http://" . $syncConfig['pc_ip'] . ":" . $syncConfig['pc_port'] . "/" . SYNC_APP_FOLDER_NAME . "/sync.php?action=get_full_state_hash&token=" . urlencode(SYNC_TOKEN);
            $otherLabel = 'PC';
        } elseif ($syncOwnRole === 'pc') {
            $otherUrl = "http://" . $syncConfig['android_ip'] . ":" . $syncConfig['android_port'] . "/" . SYNC_APP_FOLDER_NAME . "/sync.php?action=get_full_state_hash&token=" . urlencode(SYNC_TOKEN);
            $otherLabel = 'Android';
        } else {
            if (ob_get_level() > 0) { ob_clean(); }
            echo json_encode(['ok' => false, 'message' => 'Could not determine this device\'s role — check IP Settings.']);
            exit();
        }

        $ownState = sync_compute_full_state_hash($appDir, $conn);

        $ctx = stream_context_create(['http' => ['timeout' => 60, 'ignore_errors' => true]]);
        $response = @file_get_contents($otherUrl, false, $ctx);
        if ($response === false) {
            if (ob_get_level() > 0) { ob_clean(); }
            echo json_encode(['ok' => false, 'message' => "Could not reach $otherLabel to compare."]);
            exit();
        }
        $otherState = json_decode($response, true);
        if (!is_array($otherState) || empty($otherState['ok'])) {
            $msg = is_array($otherState) ? ($otherState['message'] ?? "$otherLabel rejected the request.") : "$otherLabel returned an unexpected response.";
            if (ob_get_level() > 0) { ob_clean(); }
            echo json_encode(['ok' => false, 'message' => $msg]);
            exit();
        }

        $filesMatch = hash_equals((string) $ownState['file_hash'], (string) $otherState['file_hash']);
        $dbMatch = hash_equals((string) $ownState['db_hash'], (string) $otherState['db_hash']);

        // Pinpoint exactly which files/tables differ, not just "something differs"
        $fileDiffs = [];
        if (!$filesMatch) {
            $ownFiles = $ownState['files'] ?? [];
            $otherFiles = $otherState['files'] ?? [];
            $allPaths = array_unique(array_merge(array_keys($ownFiles), array_keys($otherFiles)));
            foreach ($allPaths as $path) {
                $a = $ownFiles[$path] ?? null;
                $b = $otherFiles[$path] ?? null;
                if ($a === $b) continue;
                if ($a === null) $fileDiffs[] = "$path (missing on THIS device, present on $otherLabel)";
                elseif ($b === null) $fileDiffs[] = "$path (missing on $otherLabel, present on this device)";
                else $fileDiffs[] = "$path (content differs)";
            }
            sort($fileDiffs);
        }

        $tableDiffs = [];
        if (!$dbMatch) {
            $ownTables = $ownState['tables'] ?? [];
            $otherTables = $otherState['tables'] ?? [];
            $allTables = array_unique(array_merge(array_keys($ownTables), array_keys($otherTables)));
            foreach ($allTables as $table) {
                if (($ownTables[$table] ?? null) !== ($otherTables[$table] ?? null)) {
                    $tableDiffs[] = $table;
                }
            }
            sort($tableDiffs);
        }

        if (ob_get_level() > 0) { ob_clean(); }
        echo json_encode([
            'ok' => $filesMatch && $dbMatch, // reflects the RESULT (match/mismatch), not just "the check ran"
            'message' => ($filesMatch && $dbMatch)
                ? "Everything matches — source code and database are identical on this device and $otherLabel."
                : "Mismatch found between this device and $otherLabel — see details below.",
            'files_match' => $filesMatch,
            'db_match' => $dbMatch,
            'own_file_count' => $ownState['file_count'],
            'other_file_count' => $otherState['file_count'],
            'other_label' => $otherLabel,
            'file_diffs' => $fileDiffs,
            'table_diffs' => $tableDiffs,
        ]);
        exit();
    }

    // ---- Save updated PC/Android IP & port to data_json/sync_config.json ----
    if ($action === 'save_config') {
        $newValues = [
            'pc_ip'        => trim($_POST['pc_ip'] ?? ''),
            'pc_port'      => trim($_POST['pc_port'] ?? ''),
            'android_ip'   => trim($_POST['android_ip'] ?? ''),
            'android_port' => trim($_POST['android_port'] ?? ''),
        ];
        foreach ($newValues as $key => $val) {
            if ($val === '') {
                if (ob_get_level() > 0) { ob_clean(); }
                echo json_encode(['ok' => false, 'message' => "Field \"$key\" cannot be empty."]);
                exit();
            }
        }
        $saved = sync_save_config($syncJsonFile, $syncJsonDir, $newValues);
        if ($saved === false) {
            if (ob_get_level() > 0) { ob_clean(); }
            echo json_encode(['ok' => false, 'message' => 'Could not write data_json/sync_config.json — check folder permissions.']);
            exit();
        }
        if (ob_get_level() > 0) { ob_clean(); }
        echo json_encode(['ok' => true, 'message' => 'Config saved.', 'config' => $saved]);
        exit();
    }

    // ---- Manual DB backup: always overwrites database/optic_pos_db.sql ----
    if ($action === 'backup_db_now') {
        if (!is_dir($appDir . '/database')) mkdir($appDir . '/database', 0755, true);
        $result = sync_export_db_to_sql($conn, $appDir . '/database/optic_pos_db.sql');
        if (ob_get_level() > 0) { ob_clean(); }
        echo json_encode(['ok' => $result['ok'], 'message' => $result['ok'] ? 'Backup saved to database/optic_pos_db.sql — ' . $result['message'] : $result['message']]);
        exit();
    }

    // ---- Restore from the fixed manual backup: database/optic_pos_db.sql ----
    if ($action === 'restore_fixed_backup') {
        $file = $appDir . '/database/optic_pos_db.sql';
        if (!file_exists($file)) {
            if (ob_get_level() > 0) { ob_clean(); }
            echo json_encode(['ok' => false, 'message' => 'No backup found at database/optic_pos_db.sql yet. Use "Backup Database Now" first.']);
            exit();
        }
        $result = sync_import_sql_native_dispatch($file, $syncOwnRole, $conn);
        if (ob_get_level() > 0) { ob_clean(); }
        echo json_encode(['ok' => $result['ok'], 'message' => $result['message']]);
        exit();
    }

    // ---- List this device's auto-generated per-IP backups (database/backups/<ip>/) ----
    if ($action === 'list_ip_backups') {
        $ownIpFolder = sync_sanitize_ip_folder_name($_SERVER['SERVER_ADDR'] ?? 'unknown_ip');
        $backupDir = $appDir . '/database/backups/' . $ownIpFolder;
        $files = [];
        if (is_dir($backupDir)) {
            foreach (glob($backupDir . '/backup_*.sql') as $f) {
                $files[] = [
                    'name' => basename($f),
                    'size' => sync_format_bytes(filesize($f)),
                    'modified' => date('Y-m-d H:i:s', filemtime($f)),
                ];
            }
            usort($files, function ($a, $b) { return strcmp($b['name'], $a['name']); }); // newest first
        }
        if (ob_get_level() > 0) { ob_clean(); }
        echo json_encode(['ok' => true, 'ip_folder' => $ownIpFolder, 'files' => $files]);
        exit();
    }

    // ---- Restore a specific auto-generated per-IP backup, chosen from the list above ----
    if ($action === 'restore_ip_backup') {
        $ownIpFolder = sync_sanitize_ip_folder_name($_SERVER['SERVER_ADDR'] ?? 'unknown_ip');
        $backupDir = $appDir . '/database/backups/' . $ownIpFolder;
        $requested = basename($_POST['file'] ?? ''); // basename() blocks path traversal
        $file = $backupDir . '/' . $requested;

        if (!preg_match('/^backup_\d{8}_\d{6}\.sql$/', $requested) || !file_exists($file)) {
            if (ob_get_level() > 0) { ob_clean(); }
            echo json_encode(['ok' => false, 'message' => 'Backup file not found or invalid.']);
            exit();
        }

        $result = sync_import_sql_native_dispatch($file, $syncOwnRole, $conn);
        if (ob_get_level() > 0) { ob_clean(); }
        echo json_encode(['ok' => $result['ok'], 'message' => $result['message']]);
        exit();
    }

    // ---- Push activity-log-driven partial update to the OTHER device ----
    // target='pc' means "send MY changes TO the PC" (only valid when this
    // device IS Android). target='android' means the reverse.
    if ($action === 'push_scoped_update') {
        $target = $_POST['target'] ?? '';
        if ($target === 'pc' && $syncOwnRole !== 'android') {
            if (ob_get_level() > 0) { ob_clean(); }
            echo json_encode(['ok' => false, 'message' => 'This device is not Android — cannot push to the PC from here.']);
            exit();
        }
        if ($target === 'android' && $syncOwnRole !== 'pc') {
            if (ob_get_level() > 0) { ob_clean(); }
            echo json_encode(['ok' => false, 'message' => 'This device is not the PC — cannot push to Android from here.']);
            exit();
        }
        if (!in_array($target, ['pc', 'android'], true)) {
            if (ob_get_level() > 0) { ob_clean(); }
            echo json_encode(['ok' => false, 'message' => 'Unknown target.']);
            exit();
        }
        if (!$syncUpdateWindowOpen) {
            if (ob_get_level() > 0) { ob_clean(); }
            echo json_encode(['ok' => false, 'message' => 'Outside the allowed update window (see Settings → db_backup_blocking_time).']);
            exit();
        }

        $logResult = $conn->query("SELECT * FROM activity_log");
        if (!$logResult || $logResult->num_rows === 0) {
            if (ob_get_level() > 0) { ob_clean(); }
            echo json_encode(['ok' => false, 'message' => 'Nothing pending in activity_log — nothing to push.']);
            exit();
        }

        $folderNames = [];
        $tableEntries = [];
        $rowIds = [];
        while ($row = $logResult->fetch_assoc()) {
            $rowIds[] = $row['id'];
            $item = sync_parse_activity_item($row['list']);
            if ($item['type'] === 'folder') {
                $folderNames[$item['name']] = true;
            } else {
                $tableEntries[] = ['table' => $item['name'], 'date' => $row['changed_at']];
            }
        }
        $folderNames = array_keys($folderNames);

        // Build the zip: flagged folders + a _partial_update.sql at the root
        $zipPath = sys_get_temp_dir() . '/' . SYNC_APP_FOLDER_NAME . '_push_' . uniqid() . '.zip';
        $zipBuild = sync_zip_specific_folders($appDir, $folderNames, $zipPath);
        if (!$zipBuild['ok']) {
            if (ob_get_level() > 0) { ob_clean(); }
            echo json_encode(['ok' => false, 'message' => $zipBuild['message']]);
            exit();
        }
        $zip = $zipBuild['zip'];

        $sqlText = "SET FOREIGN_KEY_CHECKS=0;\nSTART TRANSACTION;\n";
        $totalRows = 0;
        $tablesSummary = [];
        $tableExpectations = [];
        foreach ($tableEntries as $te) {
            $exp = sync_export_table_rows_partial($conn, $te['table'], $te['date']);
            $sqlText .= $exp['sql'];
            $totalRows += $exp['row_count'];
            $tablesSummary[] = $te['table'] . ' (' . $exp['row_count'] . ' rows)';
            $tableExpectations[] = [
                'table' => $te['table'],
                'date' => date('Y-m-d', strtotime($te['date'])),
                'date_col' => $exp['date_col'],
                'expected_rows' => $exp['row_count'],
            ];
        }
        $sqlText .= "COMMIT;\nSET FOREIGN_KEY_CHECKS=1;\n";
        $zip->addFromString('_partial_update.sql', $sqlText);
        // Verification manifest: lets the receiver confirm what actually landed
        // matches what was sent (file checksums + expected row counts per table).
        $zip->addFromString('_partial_update_manifest.json', json_encode([
            'files' => $zipBuild['file_list'],
            'tables' => $tableExpectations,
        ]));
        $zip->close();

        // Push to the target device
        if ($target === 'pc') {
            $targetUrl = "http://" . $syncConfig['pc_ip'] . ":" . $syncConfig['pc_port'] . "/" . SYNC_APP_FOLDER_NAME . "/sync.php?action=receive_partial_update&token=" . urlencode(SYNC_TOKEN);
        } else {
            $targetUrl = "http://" . $syncConfig['android_ip'] . ":" . $syncConfig['android_port'] . "/" . SYNC_APP_FOLDER_NAME . "/sync.php?action=receive_partial_update&token=" . urlencode(SYNC_TOKEN);
        }
        $zipBytes = file_get_contents($zipPath);
        @unlink($zipPath);
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/octet-stream\r\n",
            'content' => $zipBytes,
            'timeout' => 120,
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents($targetUrl, false, $ctx);

        if ($response === false) {
            if (ob_get_level() > 0) { ob_clean(); }
            echo json_encode(['ok' => false, 'message' => 'Could not reach the target device to push the update.']);
            exit();
        }
        $responsePayload = json_decode($response, true);
        if (!is_array($responsePayload) || empty($responsePayload['ok'])) {
            $msg = is_array($responsePayload) ? ($responsePayload['message'] ?? 'Target device rejected the update.') : 'Target device returned an unexpected response.';
            if (ob_get_level() > 0) { ob_clean(); }
            echo json_encode(['ok' => false, 'message' => $msg]);
            exit();
        }

        // Success — remove the exact rows that were included in this push
        if (!empty($rowIds)) {
            $idList = implode(',', array_map('intval', $rowIds));
            $conn->query("DELETE FROM activity_log WHERE id IN ($idList)");
        }

        if (ob_get_level() > 0) { ob_clean(); }
        echo json_encode([
            'ok' => true,
            'message' => 'Pushed ' . count($folderNames) . ' folder(s) and ' . $totalRows . ' row(s) across ' . count($tableEntries) . ' table item(s). Target says: ' . $responsePayload['message'] . '. Cleared ' . count($rowIds) . ' activity_log entr' . (count($rowIds) === 1 ? 'y' : 'ies') . '.',
            'tables_summary' => $tablesSummary,
            'folders_summary' => $folderNames,
            'updated_files' => $zipBuild['file_list'],
            'verification' => $responsePayload['verification'] ?? ['available' => false],
        ]);
        exit();
    }

    // ---- Update Source Code (from PC) — Android only, code files only, no DB ----
    if ($action === 'pull_code_only') {
        if ($syncOwnRole !== 'android') {
            if (ob_get_level() > 0) { ob_clean(); }
            echo json_encode(['ok' => false, 'message' => 'This action is only available on the Android device.']);
            exit();
        }
        $sourceUrl = "http://" . $syncConfig['pc_ip'] . ":" . $syncConfig['pc_port'] . "/" . SYNC_APP_FOLDER_NAME . "/sync.php?action=serve_code&token=" . urlencode(SYNC_TOKEN);
        $tmpZip = sys_get_temp_dir() . '/' . SYNC_APP_FOLDER_NAME . '_code_only_incoming_' . uniqid() . '.zip';
        $ctx = stream_context_create(['http' => ['timeout' => 60, 'ignore_errors' => true]]);
        $data = @file_get_contents($sourceUrl, false, $ctx);
        if ($data === false) {
            if (ob_get_level() > 0) { ob_clean(); }
            echo json_encode(['ok' => false, 'message' => 'Could not reach the PC. Check that its server is running and the IP/port in IP Settings is correct.']);
            exit();
        }
        if (substr(ltrim($data), 0, 1) === '{') {
            $errorPayload = json_decode($data, true);
            $msg = (is_array($errorPayload) && isset($errorPayload['message'])) ? $errorPayload['message'] : 'The PC rejected the request.';
            if (ob_get_level() > 0) { ob_clean(); }
            echo json_encode(['ok' => false, 'message' => $msg]);
            exit();
        }
        file_put_contents($tmpZip, $data);

        // Overlay only — never delete anything (this is a code-only subset, not a full mirror)
        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            @unlink($tmpZip);
            if (ob_get_level() > 0) { ob_clean(); }
            echo json_encode(['ok' => false, 'message' => 'Could not open the incoming code zip.']);
            exit();
        }
        // List every file BEFORE extracting, so the UI can show exactly what changed
        $updatedFiles = [];
        $syncPhpEntryName = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false || substr($stat['name'], -1) === '/') continue; // skip directory entries
            $updatedFiles[] = ['path' => $stat['name'], 'size' => sync_format_bytes($stat['size']), 'crc32' => sprintf('%08x', $stat['crc'])];
            if (basename($stat['name']) === 'sync.php') {
                $syncPhpEntryName = $stat['name'];
            }
        }

        // sync.php is the file currently executing THIS request. Overwriting it
        // via a normal in-place write leaves a brief window where it's only
        // partially written — if another request hits Apache at that exact
        // moment, it can read a truncated/corrupt file and error out (matching
        // the intermittent "<!DOCTYPE" failures reported). Extract everything
        // ELSE normally, then write sync.php separately via write-to-temp +
        // atomic rename(), which never leaves a half-written file on disk.
        $entriesToExtractNormally = array_values(array_filter(
            array_map(function ($f) { return $f['path']; }, $updatedFiles),
            function ($path) use ($syncPhpEntryName) { return $path !== $syncPhpEntryName; }
        ));
        // Also include directory entries so folder structure is created
        $allEntryNames = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $allEntryNames[] = $zip->getNameIndex($i);
        }
        $dirEntries = array_filter($allEntryNames, function ($n) { return substr($n, -1) === '/'; });
        $extracted = $zip->extractTo($htdocsDir, array_merge($dirEntries, $entriesToExtractNormally));

        $syncPhpVerifyNote = null;
        if ($syncPhpEntryName !== null) {
            $syncPhpContent = $zip->getFromName($syncPhpEntryName);
            if ($syncPhpContent !== false) {
                // $syncPhpEntryName is like "optic_pos/sync.php" — the real file
                // lives at $appDir/sync.php (i.e. $htdocsDir/optic_pos/sync.php),
                // NOT directly under $htdocsDir. Write the temp file in the SAME
                // folder as the real target so rename() stays atomic.
                $realSyncPhpPath = $appDir . '/sync.php';
                $tempSyncPath = $appDir . '/sync.php.new_' . uniqid();
                file_put_contents($tempSyncPath, $syncPhpContent);
                if (!rename($tempSyncPath, $realSyncPhpPath)) {
                    // Rename can fail on some setups (permissions, filesystem
                    // quirks) — fall back to a direct overwrite rather than
                    // silently leaving sync.php un-updated.
                    file_put_contents($realSyncPhpPath, $syncPhpContent);
                    @unlink($tempSyncPath);
                    $syncPhpVerifyNote = 'sync.php updated via direct write (atomic rename failed, used fallback).';
                }
            }
        }

        $numFiles = $zip->numFiles;
        $zip->close();
        @unlink($tmpZip);

        // Verify each extracted file's checksum matches what was in the zip
        $filesMismatched = [];
        if ($extracted) {
            foreach ($updatedFiles as $f) {
                $localPath = $htdocsDir . '/' . $f['path'];
                if (!file_exists($localPath)) {
                    $filesMismatched[] = $f['path'] . ' (missing)';
                    continue;
                }
                $localCrc = sprintf('%08x', crc32(file_get_contents($localPath)));
                if ($localCrc !== $f['crc32']) {
                    $filesMismatched[] = $f['path'] . ' (checksum mismatch)';
                }
            }
        }

        if (ob_get_level() > 0) { ob_clean(); }
        echo json_encode([
            'ok' => $extracted,
            'message' => ($extracted ? "Source code updated — $numFiles files overlaid from PC." : 'Extraction failed.') . ($syncPhpVerifyNote ? ' ' . $syncPhpVerifyNote : ''),
            'updated_files' => $updatedFiles,
            'verification' => [
                'available' => true,
                'files_ok' => empty($filesMismatched),
                'files_checked' => count($updatedFiles),
                'files_mismatched' => $filesMismatched,
                'tables_ok' => true,
                'tables_checked' => 0,
                'tables_mismatched' => [],
            ],
        ]);
        exit();
    }

    // ---- 1. Create a local zip (whichever device runs this) ----
    if ($action === 'create_zip') {
        if (!is_dir($appDir . '/database')) mkdir($appDir . '/database', 0755, true);
        $dbResult = sync_export_db_to_sql($conn, $appDir . '/database/optic_pos_db.sql');
        if (!$dbResult['ok']) {
            if (ob_get_level() > 0) { ob_clean(); }
            echo json_encode(['ok' => false, 'message' => 'DB export failed: ' . $dbResult['message']]);
            exit();
        }
        $zipPath = $htdocsDir . '/' . SYNC_APP_FOLDER_NAME . '.zip';
        $zipResult = sync_zip_folder($appDir, $zipPath, [SYNC_APP_FOLDER_NAME . '.zip']);
        if (!$zipResult['ok']) {
            if (ob_get_level() > 0) { ob_clean(); }
            echo json_encode(['ok' => false, 'message' => $zipResult['message']]);
            exit();
        }

        // Generate a fresh 2-digit confirmation code: whoever pulls FROM this
        // device (on the other device) must enter this code before the pull
        // is allowed to proceed. See sync_generate_otp() for details.
        $otpCode = sync_generate_otp($syncJsonDir);

        // Cap the file list sent back to the browser so huge trees don't bloat
        // the response — the full list is still embedded inside the zip itself
        // (_sync_manifest.json) and is fully used for comparison after a pull.
        $manifestPreview = array_slice($zipResult['manifest_files'], 0, 300);

        if (ob_get_level() > 0) { ob_clean(); }
        echo json_encode([
            'ok' => true,
            'message' => $dbResult['message'] . ' ' . $zipResult['message'],
            'size' => sync_format_bytes(filesize($zipPath)),
            'otp' => $otpCode,
            'manifest_count' => $zipResult['file_count'],
            'manifest_total_size' => sync_format_bytes($zipResult['total_size']),
            'manifest_hash' => $zipResult['manifest_hash'],
            'manifest_preview' => $manifestPreview,
            'manifest_truncated' => count($zipResult['manifest_files']) > 300,
        ]);
        exit();
    }

    // ---- 2 & 3. Pull a fresh zip from the OTHER device and install it here ----
    if ($action === 'pull') {
        $target = $_POST['target'] ?? '';
        $otpInput = $_POST['otp'] ?? '';
        if (trim($otpInput) === '') {
            if (ob_get_level() > 0) { ob_clean(); }
            echo json_encode(['ok' => false, 'message' => 'Please enter the 2-digit confirmation code shown on the source device (run "Create Local ZIP" there first).']);
            exit();
        }
        if ($target === 'pc') {
            $sourceUrl   = "http://" . $syncConfig['pc_ip'] . ":" . $syncConfig['pc_port'] . "/" . SYNC_APP_FOLDER_NAME . "/sync.php?action=serve&token=" . urlencode(SYNC_TOKEN) . "&otp=" . urlencode($otpInput);
            $dbConfigTpl = 'android'; // this device is Android
        } elseif ($target === 'android') {
            $sourceUrl   = "http://" . $syncConfig['android_ip'] . ":" . $syncConfig['android_port'] . "/" . SYNC_APP_FOLDER_NAME . "/sync.php?action=serve&token=" . urlencode(SYNC_TOKEN) . "&otp=" . urlencode($otpInput);
            $dbConfigTpl = 'pc'; // this device is PC/XAMPP
        } else {
            if (ob_get_level() > 0) { ob_clean(); }
            echo json_encode(['ok' => false, 'message' => 'Unknown target.']);
            exit();
        }

        $tmpZip = sys_get_temp_dir() . '/' . SYNC_APP_FOLDER_NAME . '_incoming.zip';
        // ignore_errors: true lets us read the response BODY even on a non-2xx
        // status (e.g. 403 for a wrong OTP/token), instead of file_get_contents
        // just returning false with no way to see the actual error message.
        $ctx = stream_context_create(['http' => ['timeout' => 120, 'ignore_errors' => true]]);
        $data = @file_get_contents($sourceUrl, false, $ctx);
        if ($data === false) {
            if (ob_get_level() > 0) { ob_clean(); }
            echo json_encode(['ok' => false, 'message' => "Could not reach the source device. Check that its server is running and the IP/port in IP Settings is correct."]);
            exit();
        }
        // A JSON error body (e.g. wrong/expired OTP, bad token) instead of a
        // real zip — surface the source's actual message instead of guessing.
        if (substr(ltrim($data), 0, 1) === '{') {
            $errorPayload = json_decode($data, true);
            $msg = (is_array($errorPayload) && isset($errorPayload['message'])) ? $errorPayload['message'] : 'The source device rejected the request.';
            if (ob_get_level() > 0) { ob_clean(); }
            echo json_encode(['ok' => false, 'message' => $msg]);
            exit();
        }
        file_put_contents($tmpZip, $data);

        // Backup current DB before overwriting — stored per-IP with 7-file retention
        if (!is_dir($appDir . '/database')) mkdir($appDir . '/database', 0755, true);
        $backupResult = sync_backup_db_with_retention($conn, $appDir, 7);

        // Extract on top of htdocs, deleting anything local that isn't in the
        // incoming zip (true mirror overwrite). The local backups folder is
        // excluded so past backups survive even though they're not part of
        // the source device's zip.
        $extractResult = sync_extract_zip_mirror(
            $tmpZip,
            $htdocsDir,
            SYNC_APP_FOLDER_NAME,
            [SYNC_APP_FOLDER_NAME . '.zip'],
            ['.git', '.svn', 'backups']
        );
        @unlink($tmpZip);
        if (!$extractResult['ok']) {
            if (ob_get_level() > 0) { ob_clean(); }
            echo json_encode(['ok' => false, 'message' => $extractResult['message']]);
            exit();
        }

        // Integrity check: compare the source's embedded manifest (what was
        // zipped) against what actually landed on disk after extraction.
        // A single hash comparison is far more scalable than eyeballing a
        // list of hundreds/thousands of files, while still proving nothing
        // was lost, corrupted, or added along the way.
        $sourceManifestPath = $htdocsDir . '/_sync_manifest.json';
        $manifestCompare = ['available' => false];
        if (file_exists($sourceManifestPath)) {
            $sourceManifest = json_decode(file_get_contents($sourceManifestPath), true);
            @unlink($sourceManifestPath); // cleanup — not part of the app itself
            if (is_array($sourceManifest) && isset($sourceManifest['hash'])) {
                $localManifest = sync_build_folder_manifest($appDir);
                $manifestCompare = [
                    'available'   => true,
                    'match'       => hash_equals((string) $sourceManifest['hash'], (string) $localManifest['hash']),
                    'source_count' => $sourceManifest['file_count'] ?? null,
                    'local_count'  => $localManifest['file_count'],
                    'source_total_size' => sync_format_bytes($sourceManifest['total_size'] ?? 0),
                    'local_total_size'  => sync_format_bytes($localManifest['total_size']),
                ];
            }
        }

        // Regenerate db_config.php for THIS device's environment
        file_put_contents($appDir . '/db_config.php', sync_db_config_content($dbConfigTpl));

        // Android/Termux ships a newer PHP where mysqli::ping() is deprecated.
        // config_helper.php coming FROM the PC still calls it, which prints a
        // "Deprecated" notice before any HTML — breaking the header() redirect
        // in auth_check.php ("headers already sent"). server.sh used to patch
        // this via sed after every manual update; replicate that fix here so
        // it's not lost when config_helper.php gets overwritten by the pull.
        if ($dbConfigTpl === 'android') {
            $configHelperPath = $appDir . '/config_helper.php';
            if (file_exists($configHelperPath)) {
                $configHelperContent = file_get_contents($configHelperPath);
                $patched = str_replace(
                    'isset($conn) && $conn->ping()',
                    'isset($conn)',
                    $configHelperContent
                );
                if ($patched !== $configHelperContent) {
                    file_put_contents($configHelperPath, $patched);
                }
            }
        }

        // Open a fresh connection directly (NOT by re-including db_config.php —
        // that risks "Cannot redeclare function" and can also serve a stale
        // cached copy if PHP OPcache hasn't picked up the file we just wrote).
        if ($dbConfigTpl === 'android') {
            $freshConn = @new mysqli('localhost', 'root', '', 'optic_pos_db', 3306, '/data/data/com.termux/files/usr/var/run/mysqld.sock');
        } else {
            $freshConn = @new mysqli('localhost', 'root', '', 'optic_pos_db');
        }
        if ($freshConn->connect_error) {
            if (ob_get_level() > 0) { ob_clean(); }
            echo json_encode(['ok' => false, 'message' => $extractResult['message'] . ' | DB reconnect failed: ' . $freshConn->connect_error]);
            exit();
        }
        $freshConn->set_charset('utf8');

        $incomingDump = $appDir . '/database/optic_pos_db.sql';

        // Speed optimization: shell out to each platform's native mysql/mariadb
        // CLI instead of PHP mysqli — command-line import is dramatically
        // faster than row-by-row PHP for large dumps. Falls back to the PHP
        // method if shell_exec is disabled or no CLI client is found, so it's
        // never worse than before, only potentially faster.
        $importResult = null;
        if ($target === 'pc') {
            // This device is Android, pulling FROM the PC.
            $importResult = sync_import_sql_native_android($incomingDump);
        } elseif ($target === 'android') {
            // This device is PC, pulling FROM Android.
            $importResult = sync_import_sql_native_pc($incomingDump);
        }
        if ($importResult === null) {
            $importResult = sync_import_sql_file($freshConn, $incomingDump);
        }
        $freshConn->close();

        if (ob_get_level() > 0) { ob_clean(); }
        echo json_encode([
            'ok' => $importResult['ok'],
            'message' => $extractResult['message'] . ' | ' . $importResult['message'],
            'manifest_compare' => $manifestCompare,
        ]);
        exit();
    }

    // ---- 4. Verify the Main Admin password to unlock the Full System card ----
    if ($action === 'verify_main_admin') {
        $inputPassword = $_POST['password'] ?? '';
        if ($sync_main_admin_username === '' || $inputPassword === '') {
            if (ob_get_level() > 0) { ob_clean(); }
            echo json_encode(['ok' => false, 'message' => 'Main Admin is not configured or the password is empty.']);
            exit();
        }
        $stmt = $conn->prepare("SELECT password_hash FROM users WHERE username = ? AND role = 'admin'");
        $stmt->bind_param("s", $sync_main_admin_username);
        $stmt->execute();
        $res = $stmt->get_result();
        $adminRow = $res->fetch_assoc();
        $stmt->close();

        if ($adminRow && password_verify($inputPassword, $adminRow['password_hash'])) {
            if (ob_get_level() > 0) { ob_clean(); }
            echo json_encode(['ok' => true, 'message' => 'Unlocked.']);
        } else {
            if (ob_get_level() > 0) { ob_clean(); }
            echo json_encode(['ok' => false, 'message' => 'Incorrect Main Admin password.']);
        }
        exit();
    }

    if (ob_get_level() > 0) { ob_clean(); }
    echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Optical Store System - Sync</title>
        <link rel="stylesheet" href="style.css">
        <style>
            .sync-wrapper { max-width: 720px; margin: 0 auto; padding: 20px; }

            /* This page's card grows with its collapsible content, so make sure
               the shared .main-card/.content-area rules don't clip it - local
               override only, doesn't touch style.css or other pages. */
            .content-area,
            .content-area .main-card {
                max-height: none !important;
                height: auto !important;
                overflow: visible !important;
            }

            .sync-card {
                background: #1c1e22;
                border-radius: 20px;
                padding: 22px;
                margin-bottom: 20px;
                box-shadow:
                    8px 8px 18px rgba(0, 0, 0, 0.55),
                    -8px -8px 18px rgba(255, 255, 255, 0.03);
            }

            .sync-card h3 { margin: 0 0 6px 0; color: #f2f2f2; font-size: 16px; }

            .sync-card p.desc { margin: 0 0 16px 0; color: #9a9da1; font-size: 13px; line-height: 1.5; }

            .sync-btn {
                display: inline-flex;
                width: 100%;
                justify-content: center;
                align-items: center;
                gap: 10px;
                background: linear-gradient(135deg, #22d3ee, #0891b2);
                color: #06222a;
                border: none;
                border-radius: 14px;
                padding: 14px 20px;
                font-size: 16px;
                font-weight: 800;
                letter-spacing: 0.3px;
                cursor: pointer;
                box-shadow:
                    0 6px 16px rgba(34, 211, 238, 0.35),
                    0 0 0 1px rgba(255, 255, 255, 0.06) inset;
                transition: box-shadow 0.15s ease, transform 0.1s ease, filter 0.15s ease;
            }
            .sync-btn:hover {
                filter: brightness(1.08);
                box-shadow:
                    0 8px 20px rgba(34, 211, 238, 0.55),
                    0 0 0 1px rgba(255, 255, 255, 0.08) inset;
            }
            .sync-btn:active { transform: scale(0.97); }
            .sync-btn.warn {
                background: linear-gradient(135deg, #ffab6e, #ff7a3d);
                color: #2a1204;
                box-shadow:
                    0 6px 16px rgba(255, 138, 101, 0.4),
                    0 0 0 1px rgba(255, 255, 255, 0.06) inset;
            }
            .sync-btn.warn:hover {
                filter: brightness(1.08);
                box-shadow:
                    0 8px 20px rgba(255, 138, 101, 0.6),
                    0 0 0 1px rgba(255, 255, 255, 0.08) inset;
            }
            .sync-btn:disabled { opacity: 0.5; cursor: not-allowed; }

            .sync-status {
                margin-top: 14px;
                font-size: 13px;
                line-height: 1.5;
                padding: 12px 14px;
                border-radius: 12px;
                background: #17181b;
                color: #c9cbce;
                display: none;
                white-space: pre-wrap;
                word-break: break-word;
            }
            .sync-status.show { display: block; }
            .sync-status.ok { color: #7fe3a0; }
            .sync-status.err { color: #ff8a65; }

            /* Shake feedback when clicking a locked (gated) section header */
            @keyframes syncLockedShake {
                0%, 100% { transform: translateX(0); }
                20% { transform: translateX(-4px); }
                40% { transform: translateX(4px); }
                60% { transform: translateX(-3px); }
                80% { transform: translateX(3px); }
            }
            .section-header.locked-shake {
                animation: syncLockedShake 0.35s ease;
            }
            .collapsible-section[data-locked="1"] > .section-header {
                cursor: not-allowed;
                opacity: 0.65;
            }

            /* ---- Blocking processing overlay ---- */
            #processingBackdrop { z-index: 1300; } /* above other fly windows */
            .processing-spinner {
                width: 44px;
                height: 44px;
                margin: 0 auto;
                border-radius: 50%;
                border: 4px solid rgba(103, 232, 249, 0.2);
                border-top-color: #7fe3f0;
                animation: syncSpin 0.8s linear infinite;
            }
            @keyframes syncSpin {
                to { transform: rotate(360deg); }
            }

            /* Pulsing glow reminder on Full System: shown when the user reported
               using the OTHER device last, meaning data here may be stale. */
            @keyframes syncNeedsSyncGlow {
                0%, 100% { box-shadow: 0 0 0 0 rgba(255, 190, 90, 0.55); }
                50% { box-shadow: 0 0 0 10px rgba(255, 190, 90, 0); }
            }
            .needs-sync-glow {
                animation: syncNeedsSyncGlow 1.6s ease-in-out infinite;
                border: 1px solid rgba(255, 190, 90, 0.5);
            }

            /* The leading emoji in a card title doubles as the info trigger —
               enlarges on hover/focus to signal it's clickable, opens the fly window. */
            .collapsible-section { position: relative; }
            .card-icon-trigger {
                display: inline-block;
                cursor: pointer;
                transition: transform 0.15s ease;
                transform-origin: center;
            }
            .card-icon-trigger:hover,
            .card-icon-trigger:focus {
                transform: scale(1.5);
            }

            /* --- Collapsible cards inside the Full System wrapper --- */
            .collapsible-section > .section-header {
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: space-between;
                user-select: none;
                font-size: 16px;
            }
            .collapsible-section > .section-header:focus-visible {
                outline: 2px solid var(--text-muted);
                outline-offset: 2px;
            }
            .section-toggle-icon {
                display: inline-block;
                font-size: 22px;
                color: var(--text-muted);
                transition: transform 0.2s ease;
                margin-left: 10px;
            }
            .collapsible-section.is-open > .section-header .section-toggle-icon {
                transform: rotate(90deg);
            }

            /* ---- Mobile: text/icons were too large — scale everything down ---- */
            @media (max-width: 480px) {
                .collapsible-section > .section-header {
                    font-size: 14px;
                }
                .section-toggle-icon {
                    font-size: 18px;
                }
                .card-icon-trigger:hover,
                .card-icon-trigger:focus {
                    transform: scale(1.3);
                }
                .sync-btn {
                    font-size: 13px;
                    padding: 12px 14px;
                }
                .sync-card p.desc,
                .config-body p.desc {
                    font-size: 12px;
                }
                .company-name {
                    font-size: 22px;
                }
            }

            .config-body {
                display: none;
                overflow: hidden;
            }
            .collapsible-section.is-open > .config-body {
                display: block;
            }

            /* --- Full System wrapper card (password-gated) --- */
            .full-system-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
            }
            .full-system-body {
                display: none;
                overflow: hidden;
            }
            .collapsible-section.is-open > .full-system-body {
                display: block;
            }

            /* Small text-style link button for "How do I find my IP?" — deliberately NOT
               a neumorphic sync-btn, so it reads as a lightweight help link, not an action. */
            .iphelp-link-btn {
                display: inline-block;
                width: auto;
                background: none;
                border: none;
                padding: 2px 0;
                margin: 0;
                font-size: 12px;
                font-weight: 500;
                color: #7fe3f0;
                cursor: pointer;
                text-decoration: underline;
                text-underline-offset: 3px;
                box-shadow: none;
            }
            .iphelp-link-btn:hover { color: #a7edf5; }
        </style>
        <!-- button logout, back animation for logo -->
        <style>
            .neu-button.disabled {
                opacity: 0.4;
                cursor: not-allowed;
                pointer-events: none;
                filter: grayscale(1);
            }

            /* ===== New neumorphic style for Back & Logout buttons ===== */
            .neu-pill-btn {
                display: inline-flex;
                align-items: center;
                gap: 10px;
                background: #1c1e22;
                border: none;
                border-radius: 32px;
                padding: 6px 16px 6px 6px;
                cursor: pointer;
                box-shadow:
                    6px 6px 14px rgba(0, 0, 0, 0.55),
                    -6px -6px 14px rgba(255, 255, 255, 0.03);
                transition: transform 0.15s ease, box-shadow 0.15s ease;
                font-family: inherit;
            }

            .neu-pill-btn:hover {
                box-shadow:
                    6px 6px 16px rgba(0, 0, 0, 0.6),
                    -6px -6px 16px rgba(255, 255, 255, 0.04);
            }

            .neu-pill-btn:active {
                transform: scale(0.96);
            }

            /* Overflow hidden so the icon can slide across without spilling out */
            .neu-pill-btn {
                overflow: hidden;
            }

            .neu-pill-icon {
                width: 32px;
                height: 32px;
                min-width: 32px;
                border-radius: 50%;
                background: #17181b;
                display: flex;
                align-items: center;
                justify-content: center;
                box-shadow:
                    inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                    inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                    0 0 10px rgba(103, 232, 249, 0.35);
                transition: box-shadow 0.15s ease, transform 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            }

            /* Pressed state: icon slides to the right, text fades and slides out */
            .neu-pill-btn.pressed {
                box-shadow:
                    inset 4px 4px 10px rgba(0, 0, 0, 0.6),
                    inset -4px -4px 10px rgba(255, 255, 255, 0.03);
            }

            .neu-pill-btn.pressed .neu-pill-icon {
                transform: translateX(calc(100% + 24px));
                box-shadow:
                    inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                    inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                    0 0 18px rgba(103, 232, 249, 0.7);
            }

            .neu-pill-btn.pressed .neu-pill-text {
                opacity: 0;
                transform: translateX(15px);
            }

            .neu-pill-btn.pressed .neu-pill-icon,
            .neu-pill-btn:active .neu-pill-icon {
                box-shadow:
                    inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                    inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                    0 0 18px rgba(103, 232, 249, 0.7);
            }

            .neu-pill-icon svg {
                width: 15px;
                height: 15px;
                stroke: #7fe3f0;
                filter: drop-shadow(0 0 4px rgba(103, 232, 249, 0.8));
            }

            .neu-pill-text {
                display: flex;
                flex-direction: column;
                line-height: 1.15;
                text-align: left;
                transition: opacity 0.25s ease, transform 0.25s ease;
            }

            .neu-pill-text .line1 {
                font-weight: 700;
                font-size: 10px;
                letter-spacing: 0.4px;
                color: #f2f2f2;
            }

            .neu-pill-text .line2 {
                font-weight: 400;
                font-size: 9px;
                letter-spacing: 0.4px;
                color: #9a9da1;
            }

            /* Logout variant: warm amber/orange tone instead of cyan */
            .neu-pill-btn.logout-variant .neu-pill-icon {
                box-shadow:
                    inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                    inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                    0 0 10px rgba(255, 138, 101, 0.4);
            }

            .neu-pill-btn.logout-variant.pressed .neu-pill-icon {
                box-shadow:
                    inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                    inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                    0 0 18px rgba(255, 138, 101, 0.75);
            }

            .neu-pill-btn.logout-variant .neu-pill-icon svg {
                stroke: #ff8a65;
                filter: drop-shadow(0 0 4px rgba(255, 138, 101, 0.8));
            }

            /* ===== Logo zoom (fly window) effect ===== */
            .logo-backdrop {
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0);
                backdrop-filter: blur(0px);
                -webkit-backdrop-filter: blur(0px);
                z-index: 999;
                opacity: 0;
                pointer-events: none;
                transition: background 0.3s ease, opacity 0.3s ease, backdrop-filter 0.3s ease;
            }

            .logo-backdrop.active {
                background: rgba(0, 0, 0, 0.6);
                backdrop-filter: blur(10px);
                -webkit-backdrop-filter: blur(10px);
                opacity: 1;
                pointer-events: auto;
            }

            .logo-box img {
                cursor: pointer;
                transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                            top 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                            left 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            }

            .logo-box img.zoomed {
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%) scale(2.8);
                z-index: 1000;
            }

            /* ===== Affected-items confirmation fly window ===== */
            .affected-backdrop {
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0);
                backdrop-filter: blur(0px);
                -webkit-backdrop-filter: blur(0px);
                z-index: 1100;
                opacity: 0;
                pointer-events: none;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: background 0.3s ease, opacity 0.3s ease, backdrop-filter 0.3s ease;
            }

            .affected-backdrop.active {
                background: rgba(0, 0, 0, 0.6);
                backdrop-filter: blur(10px);
                -webkit-backdrop-filter: blur(10px);
                opacity: 1;
                pointer-events: auto;
            }

            .affected-modal {
                background: #1c1e22;
                border-radius: 18px;
                padding: 24px;
                width: 90%;
                max-width: 420px;
                box-shadow:
                    8px 8px 20px rgba(0, 0, 0, 0.55),
                    -8px -8px 20px rgba(255, 255, 255, 0.03);
                transform: scale(0.9);
                opacity: 0;
                transition: transform 0.25s ease, opacity 0.25s ease;
            }

            .affected-backdrop.active .affected-modal {
                transform: scale(1);
                opacity: 1;
            }

            .affected-modal h2 {
                color: #f2f2f2;
                font-size: 15px;
                letter-spacing: 0.5px;
                margin: 0 0 16px 0;
                text-align: center;
            }

            /* ===== IP Help fly window (same pattern as affected-modal, larger + scrollable) ===== */
            .iphelp-backdrop {
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0);
                backdrop-filter: blur(0px);
                -webkit-backdrop-filter: blur(0px);
                z-index: 1100;
                opacity: 0;
                pointer-events: none;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
                transition: background 0.3s ease, opacity 0.3s ease, backdrop-filter 0.3s ease;
            }
            .iphelp-backdrop.active {
                background: rgba(0, 0, 0, 0.6);
                backdrop-filter: blur(10px);
                -webkit-backdrop-filter: blur(10px);
                opacity: 1;
                pointer-events: auto;
            }
            .iphelp-modal {
                background: #1c1e22;
                border-radius: 18px;
                padding: 24px;
                width: 100%;
                max-width: 480px;
                max-height: 82vh;
                overflow-y: auto;
                box-shadow:
                    8px 8px 20px rgba(0, 0, 0, 0.55),
                    -8px -8px 20px rgba(255, 255, 255, 0.03);
                transform: scale(0.9);
                opacity: 0;
                transition: transform 0.25s ease, opacity 0.25s ease;
            }
            .iphelp-backdrop.active .iphelp-modal {
                transform: scale(1);
                opacity: 1;
            }
            .iphelp-modal h2 {
                color: #f2f2f2;
                font-size: 16px;
                margin: 0 0 16px 0;
                text-align: center;
            }
            .iphelp-modal h4 {
                color: #7fe3f0;
                font-size: 13px;
                margin: 18px 0 8px 0;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .iphelp-modal h4:first-of-type { margin-top: 0; }
            .iphelp-modal ol, .iphelp-modal ul {
                margin: 0 0 4px 0;
                padding-left: 20px;
                color: #c9cbce;
                font-size: 13px;
                line-height: 1.7;
            }
            .iphelp-modal code {
                background: #17181b;
                padding: 2px 6px;
                border-radius: 6px;
                color: #ff8a65;
                font-size: 12px;
            }
            .iphelp-close-btn {
                display: block;
                width: 100%;
                margin-top: 20px;
                background: #17181b;
                color: #f2f2f2;
                border: none;
                border-radius: 14px;
                padding: 12px;
                font-size: 14px;
                font-weight: 600;
                cursor: pointer;
                box-shadow:
                    inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                    inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                    0 0 10px rgba(103, 232, 249, 0.25);
            }

            .affected-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 20px;
            }

            .affected-table th, .affected-table td {
                text-align: left;
                padding: 8px 10px;
                font-size: 13px;
                color: #e0e0e0;
                border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            }

            /* Colored item name (list content) inside the affected-items table */
            .affected-table td.affected-item-name {
                color: #7fe3f0;
                font-weight: 600;
            }

            .affected-table th {
                color: #9a9da1;
                font-weight: 600;
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            .affected-table input[type="checkbox"] {
                width: 16px;
                height: 16px;
                accent-color: #ff8a65;
                cursor: pointer;
            }

            .affected-actions {
                display: flex;
                gap: 10px;
                justify-content: flex-end;
            }

            .affected-actions button {
                border: none;
                border-radius: 24px;
                padding: 8px 18px;
                font-size: 12px;
                font-weight: 600;
                letter-spacing: 0.4px;
                cursor: pointer;
                font-family: inherit;
                transition: transform 0.15s ease, box-shadow 0.15s ease;
            }

            .affected-actions button:active {
                transform: scale(0.96);
            }

            .affected-confirm-btn {
                background: #17181b;
                color: #7fe3f0;
                box-shadow:
                    inset 2px 2px 5px rgba(0, 0, 0, 0.6),
                    inset -2px -2px 5px rgba(255, 255, 255, 0.04);
            }

            .affected-cancel-btn {
                background: #17181b;
                color: #9a9da1;
                box-shadow:
                    inset 2px 2px 5px rgba(0, 0, 0, 0.6),
                    inset -2px -2px 5px rgba(255, 255, 255, 0.04);
            }
        </style>
    </head>

    <body>
        <div class="main-wrapper">
            <div class="content-area" style="flex-direction: column">
                <div class="header-container" style="
                margin-left: auto; 
                margin-right: auto; 
                width: 100%;">
                    <button type="button" class="logout-btn neu-pill-btn logout-variant" id="logoutBtn" onclick="handleLogoutClick(this)">
                        <span class="neu-pill-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                                <polyline points="16 17 21 12 16 7"></polyline>
                                <line x1="21" y1="12" x2="9" y2="12"></line>
                            </svg>
                        </span>
                        <span class="neu-pill-text">
                            <span class="line1">LOGOUT</span>
                        </span>
                    </button>
                
                    <div class="brand-section">
                        <div class="logo-box">
                            <img id="storeLogo" src="<?php echo htmlspecialchars($BRAND_IMAGE_PATH); ?>" alt="Brand Logo" style="height: 40px;" onclick="zoomInLogo(this)" ondblclick="zoomOutLogo(this)">
                        </div>
                        <h1 class="company-name"><?php echo htmlspecialchars($STORE_NAME); ?></h1>
                        <p class="company-address"><?php echo htmlspecialchars($STORE_ADDRESS); ?></p>
                    </div>
                </div>

                <div class="main-card">
                    <div class="sync-card collapsible-section" id="connTestCard">
                        <div class="section-header" role="button" tabindex="0" aria-expanded="false">
                            <span><span class="card-icon-trigger" onclick="event.stopPropagation(); showCardInfo('Connection Test &amp; IP Settings', <?php echo htmlspecialchars(json_encode($syncInfoConnTest . '<br><br>' . $syncInfoIpSettings), ENT_QUOTES); ?>);">📡</span> Connection Test &amp; IP Settings</span>
                            <span class="section-toggle-icon">▸</span>
                        </div>
                        <div class="config-body">
                            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                                <button class="sync-btn" id="btnTestPc" style="flex:1; min-width:140px;" onclick="testConnection('pc', this, 'statusTestPc')">🖥️ Test PC</button>
                                <button class="sync-btn" id="btnTestAndroid" style="flex:1; min-width:140px;" onclick="testConnection('android', this, 'statusTestAndroid')">📱 Test Android</button>
                            </div>
                            <div class="sync-status" id="statusTestPc"></div>
                            <div class="sync-status" id="statusTestAndroid"></div>

                            <hr style="border: none; border-top: 1px solid rgba(255,255,255,0.06); margin: 18px 0;">

                            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom: 8px;">
                                <span style="font-size:13px; color:#9a9da1; font-weight:600;">📶 IP ADDRESSES</span>
                                <button type="button" class="iphelp-link-btn" onclick="event.stopPropagation(); openIpHelp();">❓ How do I find my IP?</button>
                            </div>

                            <div id="ipSettingsFields" data-locked="<?php echo $syncIpSettingsLocked ? '1' : '0'; ?>" style="display:grid; grid-template-columns: 1fr 90px; gap: 10px 12px; align-items:end;">
                                <div class="input-group" style="margin:0;">
                                    <label style="font-size:12px; color:#9a9da1;">PC IP address <?php echo $syncPcTestOk === false ? '✏️' : ($syncPcTestOk === true ? '🔒' : ''); ?></label>
                                    <input type="text" id="cfgPcIp" value="<?php echo htmlspecialchars($syncConfig['pc_ip']); ?>" placeholder="192.168.18.10" <?php echo ($syncPcTestOk !== false) ? 'readonly' : ''; ?>>
                                </div>
                                <div class="input-group" style="margin:0;">
                                    <label style="font-size:12px; color:#9a9da1;">Port</label>
                                    <input type="text" id="cfgPcPort" value="<?php echo htmlspecialchars($syncConfig['pc_port']); ?>" placeholder="80" <?php echo ($syncPcTestOk !== false) ? 'readonly' : ''; ?>>
                                </div>
                                <div class="input-group" style="margin:0;">
                                    <label style="font-size:12px; color:#9a9da1;">Android IP address <?php echo $syncAndroidTestOk === false ? '✏️' : ($syncAndroidTestOk === true ? '🔒' : ''); ?></label>
                                    <input type="text" id="cfgAndroidIp" value="<?php echo htmlspecialchars($syncConfig['android_ip']); ?>" placeholder="192.168.18.4" <?php echo ($syncAndroidTestOk !== false) ? 'readonly' : ''; ?>>
                                </div>
                                <div class="input-group" style="margin:0;">
                                    <label style="font-size:12px; color:#9a9da1;">Port</label>
                                    <input type="text" id="cfgAndroidPort" value="<?php echo htmlspecialchars($syncConfig['android_port']); ?>" placeholder="8080" <?php echo ($syncAndroidTestOk !== false) ? 'readonly' : ''; ?>>
                                </div>
                            </div>
                            <button class="sync-btn" id="btnSaveConfig" style="margin-top:14px;" onclick="saveIpConfig(this)" <?php echo $syncIpSettingsLocked ? 'disabled' : ''; ?>>💾 Save IP Settings</button>
                            <div class="sync-status" id="statusSaveConfig"></div>
                        </div>
                    </div>

                    <div class="config-section collapsible-section" id="fullSystemSection" data-locked="<?php echo $syncBothConnected ? '0' : '1'; ?>">
                        <div class="section-header full-system-header" role="button" tabindex="0" aria-expanded="false">
                            <span id="fullSystemHeaderText"><span style="font-size:1.5em;">🗄️</span> Full System (Database &amp; Code)<?php echo $syncBothConnected ? '' : ' 🔒'; ?></span>
                            <span class="section-toggle-icon">▸</span>
                        </div>
                        <p class="desc" id="fullSystemLockNote" style="padding: 0 20px 16px; <?php echo $syncBothConnected ? 'display:none;' : ''; ?>">🔒 Locked until Connection Test succeeds for <b>both</b> PC and Android (see the Connection Test card above).</p>
                        <div class="full-system-body">

                            <!-- Locked view: hidden until the Main Admin password is verified -->
                            <div class="input-group full-width" id="fullSystemLockedView">
                                <label>Full System Access</label>
                                <div class="upload-wrapper" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                    <input type="password" class="input-field" id="fullSystemUnlockInput" placeholder="Enter Main Admin password" autocomplete="off" style="flex: 1; min-width: 150px;">
                                    <button type="button" class="btn-save" id="fullSystemUnlockBtn" style="white-space: nowrap;">Unlock</button>
                                </div>
                                <p class="description" id="fullSystemUnlockMsg">This section is hidden. Enter the Main Admin password to access the full database and code sync tools.</p>
                            </div>

                            <!-- Unlocked view: the three sync cards, collapsed by default, one open at a time -->
                            <div id="fullSystemUnlockedView" style="display: none;">

                                <div class="sync-card collapsible-section">
                                    <div class="section-header" role="button" tabindex="0" aria-expanded="false">
                                        <span><span class="card-icon-trigger" onclick="event.stopPropagation(); showCardInfo('Create Local ZIP', <?php echo htmlspecialchars(json_encode($syncInfoCreateZip), ENT_QUOTES); ?>);">📦</span> Create Local ZIP</span>
                                        <span class="section-toggle-icon">▸</span>
                                    </div>
                                    <div class="config-body">
                                        <button class="sync-btn" id="btnZip" onclick="runAction('create_zip', {}, this, 'statusZip')">📦 Create ZIP</button>
                                        <div class="sync-status" id="statusZip"></div>
                                        <div id="otpDisplayBox" style="display:none; margin-top:12px; text-align:center; background:#17181b; border-radius:14px; padding:16px;">
                                            <div style="font-size:11px; color:#9a9da1; letter-spacing:0.5px; margin-bottom:6px;">CONFIRMATION CODE — enter this on the OTHER device to pull from here (valid 5 min, one-time use)</div>
                                            <div id="otpDisplayCode" style="font-size:34px; font-weight:800; letter-spacing:10px; color:#7fe3f0;"></div>
                                        </div>
                                        <div id="manifestPreviewBox" style="display:none; margin-top:12px;">
                                            <div style="font-size:12px; color:#9a9da1; margin-bottom:6px;" id="manifestSummaryText"></div>
                                            <details>
                                                <summary style="cursor:pointer; font-size:12px; color:#7fe3f0;">View file tree</summary>
                                                <div id="manifestTreeBox" style="max-height:220px; overflow-y:auto; font-size:11px; color:#c9cbce; margin-top:8px; font-family:monospace; white-space:pre-wrap;"></div>
                                            </details>
                                        </div>
                                    </div>
                                </div>

                                <div class="sync-card collapsible-section" id="pullPcCard" <?php echo ($syncOwnRole === 'pc') ? 'style="display:none;"' : ''; ?>>
                                    <div class="section-header" role="button" tabindex="0" aria-expanded="false">
                                        <span><span class="card-icon-trigger" onclick="event.stopPropagation(); showCardInfo('Pull from PC', <?php echo htmlspecialchars(json_encode($syncInfoPullPc), ENT_QUOTES); ?>);">🖥️</span>⬇️ Pull from PC → install here<?php echo ($syncOwnRole === 'pc') ? ' 🚫' : ''; ?></span>
                                        <span class="section-toggle-icon">▸</span>
                                    </div>
                                    <div class="config-body">
                                        <div style="display:flex; gap:10px; align-items:center; margin-bottom:10px;">
                                            <input type="text" id="otpPullPc" maxlength="2" inputmode="numeric" placeholder="00" style="width:88px; text-align:center; font-size:22px; letter-spacing:8px; padding:8px 4px;">
                                            <span style="font-size:12px; color:#9a9da1;">2-digit code from the PC</span>
                                        </div>
                                        <button class="sync-btn warn" id="btnPullPc" onclick="confirmPull('pc', this, 'statusPullPc')" <?php echo ($syncOwnRole === 'pc' || !$syncBothConnected) ? 'disabled' : ''; ?>>⬇️ Pull from PC</button>
                                        <div class="sync-status" id="statusPullPc"></div>
                                    </div>
                                </div>

                                <div class="sync-card collapsible-section" id="pullAndroidCard" <?php echo ($syncOwnRole === 'android') ? 'style="display:none;"' : ''; ?>>
                                    <div class="section-header" role="button" tabindex="0" aria-expanded="false">
                                        <span><span class="card-icon-trigger" onclick="event.stopPropagation(); showCardInfo('Pull from Android', <?php echo htmlspecialchars(json_encode($syncInfoPullAndroid), ENT_QUOTES); ?>);">📱</span>⬇️ Pull from Android → install here<?php echo ($syncOwnRole === 'android') ? ' 🚫' : ''; ?></span>
                                        <span class="section-toggle-icon">▸</span>
                                    </div>
                                    <div class="config-body">
                                        <div style="display:flex; gap:10px; align-items:center; margin-bottom:10px;">
                                            <input type="text" id="otpPullAndroid" maxlength="2" inputmode="numeric" placeholder="00" style="width:88px; text-align:center; font-size:22px; letter-spacing:8px; padding:8px 4px;">
                                            <span style="font-size:12px; color:#9a9da1;">2-digit code from Android</span>
                                        </div>
                                        <button class="sync-btn warn" id="btnPullAndroid" onclick="confirmPull('android', this, 'statusPullAndroid')" <?php echo ($syncOwnRole === 'android' || !$syncBothConnected) ? 'disabled' : ''; ?>>⬇️ Pull from Android</button>
                                        <div class="sync-status" id="statusPullAndroid"></div>
                                    </div>
                                </div>

                                <?php if ($syncOwnRole === 'android'): ?>
                                <div class="sync-card collapsible-section" id="updateCodeCard">
                                    <div class="section-header" role="button" tabindex="0" aria-expanded="false">
                                        <span><span class="card-icon-trigger" onclick="event.stopPropagation(); showCardInfo('Update Source Code (from PC)', <?php echo htmlspecialchars(json_encode('Fetches only the code/style files (root .php/.css/.js) plus the image/, manual/, and phpqrcode/ folders from the PC, and overlays them here. Does NOT touch the database, qrcodes, main_qrcodes, or data_json.'), ENT_QUOTES); ?>);">🧩</span> Update Source Code (from PC)</span>
                                        <span class="section-toggle-icon">▸</span>
                                    </div>
                                    <div class="config-body">
                                        <p class="desc">Pulls PHP/CSS/JS code plus <code>image/</code>, <code>manual/</code>, <code>phpqrcode/</code> from the PC. No database changes.</p>
                                        <button class="sync-btn" id="btnUpdateCode" onclick="runAction('pull_code_only', {}, this, 'statusUpdateCode')">🧩 Update Source Code</button>
                                        <div class="sync-status" id="statusUpdateCode"></div>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <div class="sync-card collapsible-section" id="activityPushCard">
                                    <div class="section-header" role="button" tabindex="0" aria-expanded="false">
                                        <span><span class="card-icon-trigger" onclick="event.stopPropagation(); showCardInfo('Cross-Device Data Sync', <?php echo htmlspecialchars(json_encode('Pushes exactly what activity_log says changed on THIS device — flagged folders are sent whole; flagged tables send only the rows matching that day. On success, the pushed activity_log entries are deleted here. Only available during the update window set by Settings → db_backup_blocking_time (8 hours from that time of day).'), ENT_QUOTES); ?>);">🔄</span> Cross-Device Data Sync</span>
                                        <span class="section-toggle-icon">▸</span>
                                    </div>
                                    <div class="config-body">
                                        <p class="desc">Pending items on this device: <b><?php echo $syncPendingActivityCount; ?></b></p>
                                        <?php if (!$syncUpdateWindowOpen): ?>
                                            <p class="desc" style="color:#ff8a65;">🕒 Outside the allowed update window (opens at <?php echo htmlspecialchars($syncBlockingTimeSetting ?: '—'); ?>, for 8 hours). Button hidden until then.</p>
                                        <?php elseif ($syncOwnRole === 'android'): ?>
                                            <button class="sync-btn" id="btnPushToPc" onclick="confirmPushScoped('pc', this, 'statusPushScoped')">📤 Update PC Data</button>
                                        <?php elseif ($syncOwnRole === 'pc'): ?>
                                            <button class="sync-btn" id="btnPushToAndroid" onclick="confirmPushScoped('android', this, 'statusPushScoped')">📤 Update Android Data</button>
                                        <?php else: ?>
                                            <p class="desc">Device role could not be determined — check IP Settings.</p>
                                        <?php endif; ?>
                                        <div class="sync-status" id="statusPushScoped"></div>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>

                    <div class="sync-card collapsible-section" id="verifyFullSyncCard">
                        <div class="section-header" role="button" tabindex="0" aria-expanded="false">
                            <span><span class="card-icon-trigger" onclick="event.stopPropagation(); showCardInfo('Verify Full Sync', <?php echo htmlspecialchars(json_encode('Compares this device\'s ENTIRE source code and ENTIRE database against the other device by hashing both sides — nothing is transferred or changed, this is read-only. Confirms whether PC and Android are byte-for-byte identical right now.'), ENT_QUOTES); ?>);">🔍</span> Verify Full Sync</span>
                            <span class="section-toggle-icon">▸</span>
                        </div>
                        <div class="config-body">
                            <p class="desc">Read-only check: confirms whether PC and Android currently have identical source code and identical database content.</p>
                            <button class="sync-btn" id="btnVerifyFullSync" onclick="runAction('verify_full_sync', {}, this, 'statusVerifyFullSync')">🔍 Verify PC ⇄ Android</button>
                            <div class="sync-status" id="statusVerifyFullSync"></div>
                        </div>
                    </div>

                    <div class="sync-card collapsible-section" id="dbManagementCard">
                        <div class="section-header" role="button" tabindex="0" aria-expanded="false">
                            <span><span class="card-icon-trigger" onclick="event.stopPropagation(); showCardInfo('Database Management', <?php echo htmlspecialchars(json_encode('Manual backup and restore tools for this device\'s database — independent of Connection Test / Pull. Backup Now saves to a fixed file; Restore Manual Backup loads it back; Restore from Auto Backups picks from the automatic backups made before every Pull.'), ENT_QUOTES); ?>);">🗄️</span> Database Management</span>
                            <span class="section-toggle-icon">▸</span>
                        </div>
                        <div class="config-body">

                            <div style="font-size:13px; color:#9a9da1; font-weight:600; margin-bottom:8px;">💾 BACKUP NOW</div>
                            <button class="sync-btn" id="btnBackupNow" onclick="runAction('backup_db_now', {}, this, 'statusBackupNow')">💾 Backup Now</button>
                            <div class="sync-status" id="statusBackupNow"></div>

                            <hr style="border: none; border-top: 1px solid rgba(255,255,255,0.06); margin: 18px 0;">

                            <div style="font-size:13px; color:#9a9da1; font-weight:600; margin-bottom:8px;">♻️ RESTORE MANUAL BACKUP</div>
                            <p class="desc">Restores <code>database/optic_pos_db.sql</code> into this device's live database.</p>
                            <button class="sync-btn warn" id="btnRestoreFixed" onclick="confirmRestoreFixed(this, 'statusRestoreFixed')">♻️ Restore</button>
                            <div class="sync-status" id="statusRestoreFixed"></div>

                            <hr style="border: none; border-top: 1px solid rgba(255,255,255,0.06); margin: 18px 0;">

                            <div style="font-size:13px; color:#9a9da1; font-weight:600; margin-bottom:8px;">🗂️ RESTORE FROM AUTO BACKUPS</div>
                            <p class="desc">Browse this device's automatic pre-Pull backups and pick one to restore.</p>
                            <button class="sync-btn" id="btnBrowseBackups" onclick="openBackupList(this)">🗂️ Browse Backups</button>
                            <div class="sync-status" id="statusBrowseBackups"></div>

                        </div>
                    </div>
                </div>
            </div>
                    
            <div class="btn-group">
                <button type="button" class="neu-pill-btn" id="backBtn" onclick="handleBackClick(this)">
                    <span class="neu-pill-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="19" y1="12" x2="5" y2="12"></line>
                            <polyline points="12 19 5 12 12 5"></polyline>
                        </svg>
                    </span>
                    <span class="neu-pill-text">
                        <span class="line1">RETURN TO</span>
                        <span class="line2">PREVIOUS PAGE</span>
                    </span>
                </button>
            </div>

            <footer class="footer-container">
                <p class="footer-text"><?php echo $COPYRIGHT_FOOTER; ?></p>
            </footer>
        </div>    
        <div class="logo-backdrop" id="logoBackdrop" ondblclick="zoomOutLogo(document.getElementById('storeLogo'))"></div>

        <div class="iphelp-backdrop" id="lastUsedDeviceBackdrop">
            <div class="iphelp-modal" style="text-align:center;">
                <h2>📲 Which device did you use last?</h2>
                <p style="color:#c9cbce; font-size:13px; line-height:1.6; margin-bottom:20px;">If it was the OTHER device, that means data may have changed there since this device's last sync — Full System will highlight itself as a reminder, and Backup/Restore tools will be locked until you open it.</p>
                <div style="display:flex; gap:12px;">
                    <button type="button" class="sync-btn" style="flex:1;" onclick="answerLastUsedDevice('pc')">🖥️ PC</button>
                    <button type="button" class="sync-btn" style="flex:1;" onclick="answerLastUsedDevice('android')">📱 Android</button>
                </div>
            </div>
        </div>

        <div class="iphelp-backdrop" id="ipHelpBackdrop" onclick="if(event.target===this) closeIpHelp()">
            <div class="iphelp-modal">
                <h2>📶 How to find your IP address</h2>

                <h4>🖥️ On PC (Windows / XAMPP)</h4>
                <ol>
                    <li>Open <b>Command Prompt</b> (search "cmd" in the Start menu).</li>
                    <li>Type <code>ipconfig</code> and press Enter.</li>
                    <li>Find the section for your active WiFi adapter (e.g. "Wireless LAN adapter Wi-Fi").</li>
                    <li>Look for <b>"IPv4 Address"</b> — that's your PC's IP, e.g. <code>192.168.18.10</code>.</li>
                </ol>

                <h4>📱 On Android (Termux)</h4>
                <ol>
                    <li>In Termux, run: <code>ip -4 addr show wlan0</code></li>
                    <li>Look for the number after <code>inet</code> — that's your IP.</li>
                    <li>If that fails with a permission error, use the phone's Settings instead (next section) — it's more reliable on newer Android versions.</li>
                </ol>

                <h4>📱 On Android (Settings — easiest, no terminal)</h4>
                <ol>
                    <li>Go to <b>Settings → Wi-Fi</b>.</li>
                    <li>Tap the gear/info icon next to the WiFi network you're connected to.</li>
                    <li>Look for <b>"IP address"</b> in the details shown.</li>
                </ol>

                <h4>⚠️ Important</h4>
                <ul>
                    <li>Both devices must be on the <b>exact same WiFi network</b> (same router).</li>
                    <li>Android's IP often changes after reconnecting, because of "random MAC" privacy — consider switching to "Use device MAC" in the WiFi's Privacy setting for a more stable IP.</li>
                    <li>After finding the correct IP, come back here, update it in the fields above, and click <b>Save IP Settings</b>.</li>
                </ul>

                <button type="button" class="iphelp-close-btn" onclick="closeIpHelp()">Close</button>
            </div>
        </div>

        <!-- Generic fly window for per-card info icons (🖥️/📱/📦/ℹ️ top-right of each card) -->
        <!-- Blocking "processing" overlay: shown during any long-running action so
             other buttons can't be clicked mid-process. No close button/click-outside —
             it closes itself automatically when the request finishes. -->
        <div class="iphelp-backdrop" id="processingBackdrop">
            <div class="iphelp-modal" style="text-align:center;">
                <div class="processing-spinner"></div>
                <h2 style="margin-top:16px;">⏳ Working...</h2>
                <p id="processingText" style="color:#c9cbce; font-size:13px; line-height:1.6;">Please wait, do not close this page.</p>
            </div>
        </div>

        <div class="iphelp-backdrop" id="cardInfoBackdrop" onclick="if(event.target===this) closeCardInfo()">
            <div class="iphelp-modal">
                <h2 id="cardInfoTitle">Info</h2>
                <div id="cardInfoBody" style="color:#c9cbce; font-size:13px; line-height:1.7;"></div>
                <button type="button" class="iphelp-close-btn" onclick="closeCardInfo()">Close</button>
            </div>
        </div>

        <!-- Fly window: pick a per-IP auto backup to restore -->
        <div class="iphelp-backdrop" id="backupListBackdrop" onclick="if(event.target===this) closeBackupList()">
            <div class="iphelp-modal">
                <h2>🗂️ Auto Backups — <span id="backupListIpLabel"></span></h2>
                <div id="backupListBody" style="color:#c9cbce; font-size:13px; line-height:1.6;">Loading...</div>
                <button type="button" class="iphelp-close-btn" onclick="closeBackupList()">Close</button>
            </div>
        </div>

        <script>
            function setStatus(el, ok, message) {
                el.classList.add('show');
                el.classList.remove('ok', 'err');
                el.classList.add(ok ? 'ok' : 'err');
                el.textContent = (ok ? '✅ ' : '❌ ') + message;
            }

            // Cosmetic step messages shown while a request is in flight. The real
            // work happens in one single PHP request (no live progress from the
            // server), so these are timed guesses to keep the user informed of
            // roughly what stage a sync is likely at.
            const PROGRESS_STEPS = {
                create_zip: [
                    'Exporting database to a .sql file...',
                    'Packing the optic_pos folder into a ZIP...',
                ],
                pull: [
                    'Contacting the target device...',
                    'Target device is building a ZIP + fresh database dump...',
                    'Downloading the ZIP to this device...',
                    'Backing up the local database before overwriting...',
                    'Extracting & removing local files not present in the source...',
                    'Rewriting db_config.php for this device...',
                    'Importing the freshly downloaded database...',
                ],
            };

            // Renders a collapsible "what was updated" list under a status box —
            // used by Update Source Code and Cross-Device Data Sync so the exact
            // files/tables changed are visible, not just a summary sentence.
            // Renders exactly which files/tables differ after a "Verify Full Sync" check.
            function renderVerifyDiffs(statusEl, data) {
                const existing = statusEl.parentElement.querySelector('.verify-diffs-box');
                if (existing) existing.remove();

                const fileDiffs = Array.isArray(data.file_diffs) ? data.file_diffs : [];
                const tableDiffs = Array.isArray(data.table_diffs) ? data.table_diffs : [];
                if (fileDiffs.length === 0 && tableDiffs.length === 0) return; // everything matched, nothing to show

                const box = document.createElement('div');
                box.className = 'verify-diffs-box';
                box.style.cssText = 'margin-top:10px; font-size:12px;';

                if (fileDiffs.length > 0) {
                    const d = document.createElement('details');
                    d.open = true;
                    d.innerHTML = `<summary style="cursor:pointer; color:#ff8a65;">📄 ${fileDiffs.length} file(s) differ</summary>`;
                    const list = document.createElement('div');
                    list.style.cssText = 'max-height:220px; overflow-y:auto; font-family:monospace; color:#c9cbce; margin-top:6px; white-space:pre-wrap;';
                    list.textContent = fileDiffs.join('\n');
                    d.appendChild(list);
                    box.appendChild(d);
                }

                if (tableDiffs.length > 0) {
                    const d2 = document.createElement('details');
                    d2.open = true;
                    d2.style.marginTop = fileDiffs.length > 0 ? '8px' : '0';
                    d2.innerHTML = `<summary style="cursor:pointer; color:#ff8a65;">🗄️ ${tableDiffs.length} table(s) differ</summary>`;
                    const list2 = document.createElement('div');
                    list2.style.cssText = 'color:#c9cbce; margin-top:6px;';
                    list2.textContent = tableDiffs.join(', ');
                    d2.appendChild(list2);
                    box.appendChild(d2);
                }

                statusEl.insertAdjacentElement('afterend', box);
            }

            function renderUpdatedItemsList(statusEl, data) {
                const existing = statusEl.parentElement.querySelector('.updated-items-box');
                if (existing) existing.remove();

                const files = Array.isArray(data.updated_files) ? data.updated_files : [];
                const tables = Array.isArray(data.tables_summary) ? data.tables_summary : [];
                if (files.length === 0 && tables.length === 0) return;

                const box = document.createElement('div');
                box.className = 'updated-items-box';
                box.style.cssText = 'margin-top:10px; font-size:12px;';

                if (files.length > 0) {
                    const details = document.createElement('details');
                    details.innerHTML = `<summary style="cursor:pointer; color:#7fe3f0;">📄 ${files.length} file(s) updated</summary>`;
                    const list = document.createElement('div');
                    list.style.cssText = 'max-height:220px; overflow-y:auto; font-family:monospace; color:#c9cbce; margin-top:6px; white-space:pre-wrap;';
                    list.textContent = files.map(f => `${f.path}  (${f.size})`).join('\n');
                    details.appendChild(list);
                    box.appendChild(details);
                }

                if (tables.length > 0) {
                    const details2 = document.createElement('details');
                    details2.style.marginTop = files.length > 0 ? '8px' : '0';
                    details2.innerHTML = `<summary style="cursor:pointer; color:#7fe3f0;">🗄️ ${tables.length} table(s) synced</summary>`;
                    const list2 = document.createElement('div');
                    list2.style.cssText = 'color:#c9cbce; margin-top:6px;';
                    list2.textContent = tables.join(', ');
                    details2.appendChild(list2);
                    box.appendChild(details2);
                }

                // Verification result: did what actually landed match what was sent?
                const v = data.verification;
                if (v && v.available) {
                    const verifyLine = document.createElement('div');
                    verifyLine.style.cssText = 'margin-top:10px; font-weight:600;';
                    if (v.files_ok && v.tables_ok) {
                        verifyLine.style.color = '#7fe3a0';
                        verifyLine.textContent = `✅ Verified correct — ${v.files_checked} file(s) checksum-matched` + (v.tables_checked > 0 ? `, ${v.tables_checked} table(s) row-count-matched.` : '.');
                    } else {
                        verifyLine.style.color = '#ff8a65';
                        const problems = [...(v.files_mismatched || []), ...(v.tables_mismatched || [])];
                        verifyLine.textContent = `⚠️ Verification found problems: ${problems.join('; ')}`;
                    }
                    box.appendChild(verifyLine);
                }

                statusEl.insertAdjacentElement('afterend', box);
            }

            function runAction(action, extraParams, btn, statusId) {
                const statusEl = document.getElementById(statusId);
                btn.disabled = true;
                const originalText = btn.textContent;
                btn.textContent = '⏳ Processing...';
                statusEl.classList.add('show');
                statusEl.classList.remove('ok', 'err');

                const processingBackdrop = document.getElementById('processingBackdrop');
                const processingText = document.getElementById('processingText');
                processingBackdrop.classList.add('active');

                const steps = PROGRESS_STEPS[action] || ['Running...'];
                let stepIndex = 0;
                statusEl.textContent = steps[0];
                processingText.textContent = steps[0];
                const stepTimer = setInterval(() => {
                    stepIndex = Math.min(stepIndex + 1, steps.length - 1);
                    const stepMsg = steps[stepIndex] + ' (please wait, do not close this page)';
                    statusEl.textContent = stepMsg;
                    processingText.textContent = stepMsg;
                }, 1800);

                const params = new URLSearchParams({ action, ...extraParams });

                fetch('sync.php', { method: 'POST', body: params })
                    .then(r => r.json())
                    .then(data => {
                        clearInterval(stepTimer);
                        setStatus(statusEl, data.ok, data.message + (data.size ? ' (' + data.size + ')' : ''));

                        // Create ZIP: show the confirmation code + manifest tree
                        if (action === 'create_zip' && data.ok) {
                            const otpBox = document.getElementById('otpDisplayBox');
                            const otpCode = document.getElementById('otpDisplayCode');
                            if (otpBox && otpCode && data.otp) {
                                otpCode.textContent = data.otp;
                                otpBox.style.display = 'block';
                            }
                            const manifestBox = document.getElementById('manifestPreviewBox');
                            const summaryText = document.getElementById('manifestSummaryText');
                            const treeBox = document.getElementById('manifestTreeBox');
                            if (manifestBox && data.manifest_count !== undefined) {
                                summaryText.textContent = `${data.manifest_count} files, ${data.manifest_total_size} — manifest hash: ${(data.manifest_hash || '').slice(0, 12)}...`;
                                if (treeBox && Array.isArray(data.manifest_preview)) {
                                    treeBox.textContent = data.manifest_preview.map(f => `${f.path}  (${f.size} B)`).join('\n') +
                                        (data.manifest_truncated ? `\n... and more (full list embedded in the zip itself)` : '');
                                }
                                manifestBox.style.display = 'block';
                            }
                        }

                        // Pull: show the integrity comparison against the source's manifest
                        if (action === 'pull' && data.manifest_compare && data.manifest_compare.available) {
                            const mc = data.manifest_compare;
                            const matchText = mc.match
                                ? `✅ Integrity check passed — extracted files match the source exactly (${mc.local_count} files, ${mc.local_total_size}).`
                                : `⚠️ Integrity check MISMATCH — source had ${mc.source_count} files (${mc.source_total_size}), this device now has ${mc.local_count} files (${mc.local_total_size}). Something didn't transfer cleanly.`;
                            statusEl.textContent += '\n' + matchText;
                        }

                        // Update Source Code / Cross-Device Data Sync: show exactly what changed
                        if ((action === 'pull_code_only' || action === 'push_scoped_update') && data.ok) {
                            renderUpdatedItemsList(statusEl, data);
                        }

                        // Verify Full Sync: show exactly which files/tables differ, if any
                        if (action === 'verify_full_sync' && (data.file_diffs || data.table_diffs)) {
                            renderVerifyDiffs(statusEl, data);
                        }
                    })
                    .catch(err => {
                        clearInterval(stepTimer);
                        setStatus(statusEl, false, 'Request error: ' + err);
                    })
                    .finally(() => {
                        processingBackdrop.classList.remove('active');
                        btn.disabled = false;
                        btn.textContent = originalText;
                    });
            }

            function confirmPull(target, btn, statusId) {
                const label = target === 'pc' ? 'PC' : 'Android';
                const otpInputId = target === 'pc' ? 'otpPullPc' : 'otpPullAndroid';
                const otpInput = document.getElementById(otpInputId);
                const otp = otpInput ? otpInput.value.trim() : '';
                if (!otp) {
                    alert(`Enter the 2-digit confirmation code shown on ${label} first (run "Create Local ZIP" there).`);
                    if (otpInput) otpInput.focus();
                    return;
                }
                if (!confirm(`This will REPLACE this device's files and database with data from ${label} — any local file/folder not present on ${label} will be DELETED (mirror overwrite). A DB backup is kept locally. Continue?`)) return;
                runAction('pull', { target, otp }, btn, statusId);
            }

            // Quick connectivity check (no data moved) — hits the other device's
            // lightweight ?action=ping endpoint and reports latency + IP match.
            function testConnection(target, btn, statusId) {
                const statusEl = document.getElementById(statusId);
                btn.disabled = true;
                const originalText = btn.textContent;
                btn.textContent = '⏳ Testing...';
                statusEl.classList.add('show');
                statusEl.classList.remove('ok', 'err');
                statusEl.textContent = 'Contacting device...';

                const params = new URLSearchParams({ action: 'test_connection', target });

                fetch('sync.php', { method: 'POST', body: params })
                    .then(r => r.json())
                    .then(data => {
                        setStatus(statusEl, data.ok, data.message);
                        syncConnState[target] = data.ok;
                        syncRefreshGating();
                    })
                    .catch(err => setStatus(statusEl, false, 'Request error: ' + err))
                    .finally(() => {
                        btn.disabled = false;
                        btn.textContent = originalText;
                    });
            }

            // Saves the PC/Android IP + port fields to data_json/sync_config.json on the server.
            function saveIpConfig(btn) {
                const statusEl = document.getElementById('statusSaveConfig');
                const pc_ip = document.getElementById('cfgPcIp').value.trim();
                const pc_port = document.getElementById('cfgPcPort').value.trim();
                const android_ip = document.getElementById('cfgAndroidIp').value.trim();
                const android_port = document.getElementById('cfgAndroidPort').value.trim();

                btn.disabled = true;
                const originalText = btn.textContent;
                btn.textContent = '⏳ Saving...';
                statusEl.classList.add('show');
                statusEl.classList.remove('ok', 'err');
                statusEl.textContent = 'Saving...';

                const params = new URLSearchParams({ action: 'save_config', pc_ip, pc_port, android_ip, android_port });

                fetch('sync.php', { method: 'POST', body: params })
                    .then(r => r.json())
                    .then(data => setStatus(statusEl, data.ok, data.message))
                    .catch(err => setStatus(statusEl, false, 'Request error: ' + err))
                    .finally(() => {
                        btn.disabled = false;
                        btn.textContent = originalText;
                    });
            }

            // Fly window explaining how to find each device's IP address.
            function openIpHelp() {
                document.getElementById('ipHelpBackdrop').classList.add('active');
            }
            function closeIpHelp() {
                document.getElementById('ipHelpBackdrop').classList.remove('active');
            }

            // Generic fly window used by the small info-icon buttons on each card.
            function showCardInfo(title, html) {
                document.getElementById('cardInfoTitle').textContent = title;
                document.getElementById('cardInfoBody').innerHTML = html;
                document.getElementById('cardInfoBackdrop').classList.add('active');
            }
            function closeCardInfo() {
                document.getElementById('cardInfoBackdrop').classList.remove('active');
            }

            // ---- Restore Manual Backup (database/optic_pos_db.sql) ----
            function confirmRestoreFixed(btn, statusId) {
                if (!confirm('This will REPLACE this device\'s current database with database/optic_pos_db.sql. Continue?')) return;
                runAction('restore_fixed_backup', {}, btn, statusId);
            }

            // ---- Cross-Device Data Sync (activity_log-driven partial push) ----
            function confirmPushScoped(target, btn, statusId) {
                var label = target === 'pc' ? 'PC' : 'Android';
                if (!confirm(`This will push this device's pending activity_log changes to ${label}, and clear them here on success. Continue?`)) return;
                runAction('push_scoped_update', { target }, btn, statusId);
            }

            // ---- Restore from Auto Backups (fly window with a pickable list) ----
            function openBackupList(btn) {
                const body = document.getElementById('backupListBody');
                const ipLabel = document.getElementById('backupListIpLabel');
                body.textContent = 'Loading...';
                document.getElementById('backupListBackdrop').classList.add('active');

                fetch('sync.php', { method: 'POST', body: new URLSearchParams({ action: 'list_ip_backups' }) })
                    .then(r => r.json())
                    .then(data => {
                        if (!data.ok) { body.textContent = 'Could not load backups.'; return; }
                        ipLabel.textContent = data.ip_folder;
                        if (!data.files || data.files.length === 0) {
                            body.textContent = 'No auto backups found yet for this device — they get created automatically right before each Pull.';
                            return;
                        }
                        body.innerHTML = '';
                        data.files.forEach(f => {
                            const item = document.createElement('button');
                            item.type = 'button';
                            item.className = 'sync-btn';
                            item.style.cssText = 'margin-bottom:10px; text-align:left; display:flex; flex-direction:column; align-items:flex-start; gap:2px; font-size:13px;';
                            item.innerHTML = `<span>📄 ${f.name}</span><span style="font-weight:400; font-size:11px; opacity:0.85;">${f.modified} — ${f.size}</span>`;
                            item.onclick = () => restoreIpBackup(f.name);
                            body.appendChild(item);
                        });
                    })
                    .catch(() => { body.textContent = 'Request error while loading backups.'; });
            }
            function closeBackupList() {
                document.getElementById('backupListBackdrop').classList.remove('active');
            }
            function restoreIpBackup(fileName) {
                if (!confirm(`This will REPLACE this device's current database with "${fileName}". Continue?`)) return;
                closeBackupList();
                const statusEl = document.getElementById('statusBrowseBackups');
                statusEl.classList.add('show');
                statusEl.classList.remove('ok', 'err');
                statusEl.textContent = 'Restoring...';
                fetch('sync.php', { method: 'POST', body: new URLSearchParams({ action: 'restore_ip_backup', file: fileName }) })
                    .then(r => r.json())
                    .then(data => setStatus(statusEl, data.ok, data.message))
                    .catch(err => setStatus(statusEl, false, 'Request error: ' + err));
            }

            // ===== Accordion for the three top-level cards =====
            // Connection Test, IP Settings, and Full System: all start collapsed,
            // and opening one closes the others. This is separate from the nested
            // accordion inside Full System's unlocked view (different selector scope).
            // Sections with data-locked="1" refuse to open (gated by Connection Test).
            var syncTopLevelSections = document.querySelectorAll('.main-card > .collapsible-section');
            function syncCloseAllTopLevel() {
                syncTopLevelSections.forEach(function (other) {
                    other.classList.remove('is-open');
                    var otherHeader = other.querySelector(':scope > .section-header');
                    if (otherHeader) otherHeader.setAttribute('aria-expanded', 'false');
                });
            }
            (function () {
                syncTopLevelSections.forEach(function (section) {
                    var header = section.querySelector(':scope > .section-header');
                    if (!header) return;

                    function toggleSection() {
                        if (section.dataset.locked === '1') {
                            header.classList.add('locked-shake');
                            setTimeout(function () { header.classList.remove('locked-shake'); }, 400);
                            return;
                        }

                        var willOpen = !section.classList.contains('is-open');
                        syncCloseAllTopLevel();

                        if (willOpen) {
                            section.classList.add('is-open');
                            header.setAttribute('aria-expanded', 'true');

                            // Opening Full System satisfies the "sync first" reminder.
                            if (section.id === 'fullSystemSection' && syncNeedsSyncFirst) {
                                syncNeedsSyncFirst = false;
                                section.classList.remove('needs-sync-glow');
                                syncRefreshGating();
                            }

                            // First time Full System opens with pending activity_log
                            // items, draw attention to the push card and scroll to it.
                            if (section.id === 'fullSystemSection' && syncPendingActivityCount > 0 && !syncActivityCardHighlighted) {
                                syncActivityCardHighlighted = true;
                                setTimeout(function () {
                                    var card = document.getElementById('activityPushCard');
                                    if (card) {
                                        card.classList.add('needs-sync-glow');
                                        card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                        setTimeout(function () { card.classList.remove('needs-sync-glow'); }, 6000);
                                    }
                                }, 250); // small delay so the section-open transition finishes first
                            }
                        }
                    }

                    header.addEventListener('click', toggleSection);
                    header.addEventListener('keydown', function (evt) {
                        if (evt.key === 'Enter' || evt.key === ' ') {
                            evt.preventDefault();
                            toggleSection();
                        }
                    });
                });
            })();

            // Re-evaluate lock state on IP Settings / Full System / Pull cards after
            // a fresh Connection Test result comes back, without needing a page reload.
            var syncConnState = { pc: <?php echo json_encode($syncPcTestOk); ?>, android: <?php echo json_encode($syncAndroidTestOk); ?> };
            var syncOwnRoleJs = <?php echo json_encode($syncOwnRole); ?>;
            var syncNeedsSyncFirst = false; // set true if the user says the OTHER device was used last
            var syncPendingActivityCount = <?php echo (int) $syncPendingActivityCount; ?>;
            var syncActivityCardHighlighted = false; // only auto-highlight once per page load

            // ---- "Which device did you use last?" fly window (shown on every page load) ----
            function answerLastUsedDevice(device) {
                document.getElementById('lastUsedDeviceBackdrop').classList.remove('active');
                if (syncOwnRoleJs !== 'unknown' && device !== syncOwnRoleJs) {
                    syncNeedsSyncFirst = true;
                    var fullSection = document.getElementById('fullSystemSection');
                    if (fullSection) fullSection.classList.add('needs-sync-glow');
                    syncRefreshGating();
                }
            }
            // Auto-show on load — no auto-dismiss, the user must pick one.
            document.getElementById('lastUsedDeviceBackdrop').classList.add('active');
            function syncRefreshGating() {
                var bothOk = (syncConnState.pc === true && syncConnState.android === true);
                // IP Settings unlocks only while a device's test has actually FAILED —
                // not tested yet, or both succeeded, keeps it locked.
                var anyFailed = (syncConnState.pc === false || syncConnState.android === false);
                var ipLocked = !anyFailed;

                var ipSection = document.getElementById('ipSettingsFields');
                if (ipSection) ipSection.dataset.locked = ipLocked ? '1' : '0';

                var pcIp = document.getElementById('cfgPcIp');
                var pcPort = document.getElementById('cfgPcPort');
                var androidIp = document.getElementById('cfgAndroidIp');
                var androidPort = document.getElementById('cfgAndroidPort');
                var saveBtn = document.getElementById('btnSaveConfig');
                if (pcIp) pcIp.readOnly = (syncConnState.pc !== false);
                if (pcPort) pcPort.readOnly = (syncConnState.pc !== false);
                if (androidIp) androidIp.readOnly = (syncConnState.android !== false);
                if (androidPort) androidPort.readOnly = (syncConnState.android !== false);
                if (saveBtn) saveBtn.disabled = ipLocked;

                var fullSection = document.getElementById('fullSystemSection');
                if (fullSection) fullSection.dataset.locked = bothOk ? '0' : '1';
                var fullHeaderSpan = document.getElementById('fullSystemHeaderText');
                if (fullHeaderSpan) fullHeaderSpan.innerHTML = '<span style="font-size:1.5em;">🗄️</span> Full System (Database & Code)' + (bothOk ? '' : ' 🔒');
                var fullLockNote = document.getElementById('fullSystemLockNote');
                if (fullLockNote) fullLockNote.style.display = bothOk ? 'none' : '';

                // Pull CARDS (not just their buttons) are locked entirely when this
                // device IS that role — can't pull from itself.
                var ownRole = <?php echo json_encode($syncOwnRole); ?>;
                var pullPcCard = document.getElementById('pullPcCard');
                var pullAndroidCard = document.getElementById('pullAndroidCard');
                if (pullPcCard) pullPcCard.dataset.locked = (ownRole === 'pc') ? '1' : '0';
                if (pullAndroidCard) pullAndroidCard.dataset.locked = (ownRole === 'android') ? '1' : '0';
                var btnPullPc = document.getElementById('btnPullPc');
                var btnPullAndroid = document.getElementById('btnPullAndroid');
                if (btnPullPc) btnPullPc.disabled = !bothOk || ownRole === 'pc';
                if (btnPullAndroid) btnPullAndroid.disabled = !bothOk || ownRole === 'android';

                // Once both devices are confirmed connected, auto-close the
                // Connection Test card — its job is done for this session.
                if (bothOk) {
                    var connCard = document.getElementById('connTestCard');
                    if (connCard && connCard.classList.contains('is-open')) {
                        syncCloseAllTopLevel();
                    }
                }
            }
            syncRefreshGating();
        </script>

        <script>
            // ===== Full System card: password gate =====
            // Verifies the Main Admin password against the server before
            // revealing the database/code sync tools.
            document.getElementById('fullSystemUnlockBtn').onclick = function () {
                const inputVal = document.getElementById('fullSystemUnlockInput').value;
                const msg = document.getElementById('fullSystemUnlockMsg');
                const btn = this;

                if (inputVal === '') {
                    msg.textContent = 'Please enter the Main Admin password.';
                    msg.style.color = '#ff3131';
                    return;
                }

                btn.disabled = true;
                msg.textContent = 'Verifying...';
                msg.style.color = '';

                fetch('sync.php', {
                    method: 'POST',
                    body: new URLSearchParams({ action: 'verify_main_admin', password: inputVal })
                })
                .then(r => r.json())
                .then(data => {
                    if (data.ok) {
                        document.getElementById('fullSystemLockedView').style.display = 'none';
                        document.getElementById('fullSystemUnlockedView').style.display = 'block';
                    } else {
                        msg.textContent = data.message || 'Incorrect Main Admin password.';
                        msg.style.color = '#ff3131';
                    }
                })
                .catch(() => {
                    msg.textContent = 'Verification request failed. Please try again.';
                    msg.style.color = '#ff3131';
                })
                .finally(() => {
                    btn.disabled = false;
                });
            };

            // ===== Accordion behavior for the three sync cards =====
            // Only one card can be open at a time; all start collapsed.
            // Cards with data-locked="1" (e.g. "Pull from PC" on the PC itself)
            // cannot be expanded at all.
            (function () {
                var sections = document.querySelectorAll('#fullSystemUnlockedView .collapsible-section');
                sections.forEach(function (section) {
                    var header = section.querySelector(':scope > .section-header');
                    if (!header) return;

                    function toggleSection() {
                        if (section.dataset.locked === '1') {
                            header.classList.add('locked-shake');
                            setTimeout(function () { header.classList.remove('locked-shake'); }, 400);
                            return;
                        }

                        var willOpen = !section.classList.contains('is-open');

                        sections.forEach(function (other) {
                            other.classList.remove('is-open');
                            var otherHeader = other.querySelector(':scope > .section-header');
                            if (otherHeader) otherHeader.setAttribute('aria-expanded', 'false');
                        });

                        if (willOpen) {
                            section.classList.add('is-open');
                            header.setAttribute('aria-expanded', 'true');
                        }
                    }

                    header.addEventListener('click', toggleSection);
                    header.addEventListener('keydown', function (evt) {
                        if (evt.key === 'Enter' || evt.key === ' ') {
                            evt.preventDefault();
                            toggleSection();
                        }
                    });
                });
            })();
        </script>
        <!-- button logout, back animation for logo -->
        <script>
            // Single tap/click on the logo zooms it in (only if not already zoomed).
            function zoomInLogo(imgEl) {
                if (imgEl.classList.contains('zoomed')) return;
                imgEl.classList.add('zoomed');
                document.getElementById('logoBackdrop').classList.add('active');
            }

            // Double tap/click zooms it back out.
            function zoomOutLogo(imgEl) {
                imgEl.classList.remove('zoomed');
                document.getElementById('logoBackdrop').classList.remove('active');
            }

            // Holds the action ('back', 'logout', or 'navigate') and element/url
            // waiting to run after the affected-items confirmation modal is resolved.
            let pendingAction = null;
            let pendingElement = null;
            let pendingUrl = null;

            // Actual pill-shrink animation for the Back button, then navigate.
            function runBackAnimation(element) {
                const icon = element.querySelector('.neu-pill-icon');
                const text = element.querySelector('.neu-pill-text');

                // Make sure nothing else fights with our manual animation.
                element.style.transition = 'none';
                text.style.transition = 'none';

                const startWidth = element.offsetWidth;
                // Target: just the round icon left, with the button's own
                // left/right padding preserved (6px left, 6px right when collapsed).
                const targetWidth = icon.offsetWidth + 12;

                // Hide the text immediately so only the shrinking pill is visible.
                text.style.opacity = '0';

                const duration = 400; // ms
                const startTime = performance.now();

                function step(now) {
                    const elapsed = now - startTime;
                    const progress = Math.min(elapsed / duration, 1);
                    const eased = 1 - Math.pow(1 - progress, 3);

                    const currentWidth = startWidth - (startWidth - targetWidth) * eased;
                    element.style.width = currentWidth + 'px';

                    if (progress < 1) {
                        requestAnimationFrame(step);
                    } else {
                        // back direction
                        window.location.href = 'index.php';
                    }
                }
                requestAnimationFrame(step);
            }

            // Actual pressed animation for the Logout button, then log out.
            function runLogoutAnimation(element) {
                element.classList.add('pressed');
                setTimeout(() => {
                    window.location.href = 'logout.php';
                }, 220);
            }

            // Actual navigation logic for grid menu buttons (Frame Management,
            // Lense Price, etc.), extracted so it can run after confirmation.
            function runNavigateAction(element, targetUrl) {
                // 1. Save this URL to localStorage as the active button identity
                localStorage.setItem('activeMenuUrl', targetUrl);

                // 2. Add the active class immediately (for an instant visual effect)
                document.querySelectorAll('.neu-button').forEach(btn => btn.classList.remove('active'));
                element.classList.add('active');

                // 3. Navigate to the page
                window.location.href = targetUrl;
            }

            function openAffectedModal(items) {
                // Render one checkbox row per affected item. Each item gets its
                // own row/state so they can be selected or deselected individually.
                const tbody = document.getElementById('affectedTableBody');
                tbody.innerHTML = '';
                items.forEach((item, index) => {
                    const row = document.createElement('tr');

                    const checkboxCell = document.createElement('td');
                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.checked = true;
                    checkbox.className = 'affected-item-checkbox';
                    checkbox.setAttribute('data-item', item);
                    checkbox.id = 'affectedItemCheckbox' + index;
                    checkboxCell.appendChild(checkbox);

                    const nameCell = document.createElement('td');
                    nameCell.className = 'affected-item-name';
                    nameCell.textContent = item;

                    row.appendChild(checkboxCell);
                    row.appendChild(nameCell);
                    tbody.appendChild(row);
                });

                document.getElementById('affectedBackdrop').classList.add('active');
            }

            function closeAffectedModal() {
                document.getElementById('affectedBackdrop').classList.remove('active');
            }

            // Runs whichever action (back/logout/navigate) is currently pending.
            function executePendingAction() {
                const action = pendingAction;
                const element = pendingElement;
                const url = pendingUrl;
                pendingAction = null;
                pendingElement = null;
                pendingUrl = null;

                if (action === 'back') {
                    runBackAnimation(element);
                } else if (action === 'logout') {
                    runLogoutAnimation(element);
                } else if (action === 'navigate') {
                    runNavigateAction(element, url);
                }
            }

            // Cancel button: close the modal and abort the pending action entirely.
            function cancelAffectedModal() {
                closeAffectedModal();
                pendingAction = null;
                pendingElement = null;
                pendingUrl = null;
            }

            // Confirm button: log to activity_log only for items still checked,
            // then proceed with the pending action either way. Each checked item
            // is sent as a separate entry, so it is stored as its own row rather
            // than being combined together. Once confirmed (regardless of what
            // was checked), the accumulated visited-modules tracker is cleared
            // so it doesn't carry over into the next visit.
            function confirmAffectedModal() {
                const checkedItems = Array.from(document.querySelectorAll('.affected-item-checkbox:checked'))
                    .map(cb => cb.getAttribute('data-item'));
                closeAffectedModal();

                if (checkedItems.length === 0) {
                    clearVisitedModules();
                    executePendingAction();
                    return;
                }

                fetch('log_activity.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ list: checkedItems })
                })
                .then(() => {
                    clearVisitedModules();
                    executePendingAction();
                })
                .catch(() => {
                    clearVisitedModules();
                    executePendingAction();
                });
            }

            // ===== Universal "visitedModules" tracker =====
            // Any button with a data-affected-items attribute is a "tracked
            // module". Each tracked module pressed gets recorded here (its own
            // entry, keyed by URL) so multiple tracked buttons pressed in the
            // same visit all contribute their items - but only the ones that
            // were actually pressed, nothing assumed.
            const VISITED_MODULES_KEY = 'visitedModules';

            function getVisitedModules() {
                try {
                    const raw = localStorage.getItem(VISITED_MODULES_KEY);
                    const parsed = raw ? JSON.parse(raw) : [];
                    return Array.isArray(parsed) ? parsed : [];
                } catch (e) {
                    return [];
                }
            }

            function saveVisitedModules(modules) {
                localStorage.setItem(VISITED_MODULES_KEY, JSON.stringify(modules));
            }

            // Records/updates a tracked module's affected items. If the same
            // module is pressed again, its entry is replaced (not duplicated).
            function recordVisitedModule(moduleUrl, items) {
                const modules = getVisitedModules().filter(m => m.module !== moduleUrl);
                modules.push({ module: moduleUrl, items: items });
                saveVisitedModules(modules);
            }

            function clearVisitedModules() {
                localStorage.removeItem(VISITED_MODULES_KEY);
            }

            // Merges the items of every tracked module pressed so far into one
            // deduplicated list, to be shown in the confirmation modal.
            function getMergedAffectedItems() {
                const modules = getVisitedModules();
                const merged = [];
                modules.forEach(m => {
                    (m.items || []).forEach(item => {
                        if (!merged.includes(item)) {
                            merged.push(item);
                        }
                    });
                });
                return merged;
            }

            // Reads the comma-separated data-affected-items attribute off a
            // button and turns it into a clean array of item names.
            function getAffectedItems(element) {
                const raw = element.getAttribute('data-affected-items') || '';
                return raw.split(',').map(item => item.trim()).filter(item => item.length > 0);
            }

            // Whether the affected-items confirmation modal should be shown:
            // relevant whenever at least one tracked module has been pressed
            // since the last confirmation.
            function shouldConfirmAffectedItems() {
                return getVisitedModules().length > 0;
            }

            // Asks the server which of the merged items are NOT already
            // logged in activity_log (exact match). Only those are worth
            // asking the user to confirm - if an item's row already exists,
            // re-showing it in the modal would just re-log the same thing.
            function fetchUnloggedItems(items) {
                return fetch('check_logged_items.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ list: items })
                })
                .then(res => res.json())
                .then(data => (data && data.success) ? data.unloggedItems : items)
                .catch(() => items); // if the check fails, fall back to showing everything
            }

            // Shared logic for both Back and Logout: merge tracked items,
            // ask the server which ones are still unlogged, then either show
            // the modal with just those, or skip the modal entirely and run
            // the action directly if every tracked item is already logged.
            function proceedWithConfirmation(action, element) {
                if (!shouldConfirmAffectedItems()) {
                    if (action === 'back') runBackAnimation(element);
                    else if (action === 'logout') runLogoutAnimation(element);
                    return;
                }

                const mergedItems = getMergedAffectedItems();
                fetchUnloggedItems(mergedItems).then(unloggedItems => {
                    if (unloggedItems.length === 0) {
                        // Everything tracked is already logged - nothing new
                        // to confirm, so clear the tracker and proceed as if
                        // nothing had been pending.
                        clearVisitedModules();
                        if (action === 'back') runBackAnimation(element);
                        else if (action === 'logout') runLogoutAnimation(element);
                        return;
                    }

                    pendingAction = action;
                    pendingElement = element;
                    openAffectedModal(unloggedItems);
                });
            }

            // Animate the new pill-style Back button before navigating
            function handleBackClick(element) {
                proceedWithConfirmation('back', element);
            }

            // Animate the new pill-style Logout button before logging out
            function handleLogoutClick(element) {
                proceedWithConfirmation('logout', element);
            }

            // Function executed when a grid menu button is clicked.
            // Navigates directly - the affected-items modal is not triggered
            // here, only when leaving the page afterwards via Back/Logout.
            // If the button is a tracked module (has data-affected-items),
            // its items are recorded first so they can be shown later.
            function handleButtonClick(element) {
                const targetUrl = element.getAttribute('data-url');
                const items = getAffectedItems(element);
                if (items.length > 0) {
                    recordVisitedModule(targetUrl, items);
                }
                runNavigateAction(element, targetUrl);
            }

            // Function that runs automatically when the page is refreshed or returned to (Back)
            window.addEventListener('DOMContentLoaded', () => {
                const activeUrl = localStorage.getItem('activeMenuUrl');
                
                if (activeUrl) {
                    document.querySelectorAll('.neu-button').forEach(btn => {
                        // If the button's data-url matches the one in memory, activate it!
                        if (btn.getAttribute('data-url') === activeUrl) {
                            btn.classList.add('active');
                        }
                    });
                }
            });
        </script>
    </body>
</html>