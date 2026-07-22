<?php
// file_list.php
// Letakkan di C:\xampp\htdocs\optic_pos\
// Menghasilkan daftar semua file untuk didownload HP

$base = __DIR__;
$files = [];

$skip_dirs = ['backup', '00', 'database', 'guidance', 'manual', 'main_qrcodes', 'qrcodes', 'phpqrcode', '.git', 'invoice'];
$skip_ext  = ['sql', 'zip', 'rar', 'log', 'bak'];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if ($file->isDir()) continue;
    
    $path = str_replace($base . DIRECTORY_SEPARATOR, '', $file->getPathname());
    $path = str_replace('\\', '/', $path);
    
    // Skip folder tertentu
    $parts = explode('/', $path);
    if (count($parts) > 1 && in_array($parts[0], $skip_dirs)) continue;
    
    // Skip ekstensi tertentu
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (in_array($ext, $skip_ext)) continue;
    
    // Skip file terlalu besar (>2MB)
    if ($file->getSize() > 2 * 1024 * 1024) continue;
    
    $files[] = $path;
}

sort($files);
header('Content-Type: text/plain');
echo implode("\n", $files);
?>
