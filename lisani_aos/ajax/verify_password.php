<?php
// lisani_aos/ajax/verify_password.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Session tidak valid.']);
    exit;
}

require_once __DIR__ . '/../db_config.php'; // -> $lisani_conn

$password = $_POST['password'] ?? '';
if ($password === '') {
    echo json_encode(['ok' => false, 'message' => 'Password wajib diisi.']);
    exit;
}

$stmt = $lisani_conn->prepare('SELECT password_hash FROM users WHERE user_id = ? LIMIT 1');
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();

if (!$row || !password_verify($password, $row['password_hash'])) {
    // Sengaja delay kecil untuk menghambat brute force sederhana.
    usleep(400000);
    echo json_encode(['ok' => false, 'message' => 'Password salah.']);
    exit;
}

// Tandai bahwa user sudah re-verify, dipakai create_activity_code.php sebagai
// guard tambahan (berlaku singkat, bukan pengganti session login).
$_SESSION['aos_reverify_activity_code'] = time();

// Flag generik (tidak spesifik ke satu fitur) supaya endpoint lain yang perlu
// gate "user baru saja masukkan password lagi" tinggal cek ini, tanpa harus
// terima ulang field password + password_verify() sendiri-sendiri. Dipakai
// pertama kali oleh fitur Settings (Company Documents & Bank Accounts) —
// lihat ajax/_require_reverify.php. Key lama di atas TETAP dipertahankan
// supaya create_activity_code.php tidak perlu diubah.
$_SESSION['aos_reverify_at'] = time();

echo json_encode(['ok' => true]);