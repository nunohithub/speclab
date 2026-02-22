<?php
/**
 * SpecLab - Database Backup Script
 * Usage: php scripts/backup.php
 * Cron: 0 3 * * * php /path/to/scripts/backup.php
 */

require_once __DIR__ . '/../config/database.php';

$backupDir = __DIR__ . '/../backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

$date = date('Y-m-d_H-i-s');
$filename = "backup_{$date}.sql";
$filepath = "$backupDir/$filename";

// Parse DB_HOST to extract host and port
$host = DB_HOST;
$port = '';
if (strpos($host, ':') !== false) {
    [$host, $port] = explode(':', $host, 2);
}

// Find mysqldump binary (MAMP or system)
$mysqldump = 'mysqldump';
$mampPaths = [
    '/Applications/MAMP/Library/bin/mysql80/bin/mysqldump',
    '/Applications/MAMP/Library/bin/mysql57/bin/mysqldump',
    '/Applications/MAMP/Library/bin/mysqldump',
];
foreach ($mampPaths as $path) {
    if (is_file($path)) {
        $mysqldump = $path;
        break;
    }
}

// Build mysqldump command
$cmd = sprintf(
    '%s -h %s %s -u %s -p%s %s > %s 2>&1',
    escapeshellarg($mysqldump),
    escapeshellarg($host),
    $port ? '-P ' . escapeshellarg($port) : '',
    escapeshellarg(DB_USER),
    escapeshellarg(DB_PASS),
    escapeshellarg(DB_NAME),
    escapeshellarg($filepath)
);

exec($cmd, $output, $returnCode);

if ($returnCode === 0) {
    // Compress
    $gzFile = "$filepath.gz";
    $fp = fopen($filepath, 'rb');
    $gz = gzopen($gzFile, 'wb9');
    while (!feof($fp)) {
        gzwrite($gz, fread($fp, 1024 * 512));
    }
    fclose($fp);
    gzclose($gz);
    unlink($filepath); // Remove uncompressed

    // Cleanup old backups (keep last 30)
    $backups = glob("$backupDir/backup_*.sql.gz");
    usort($backups, fn($a, $b) => filemtime($b) - filemtime($a));
    foreach (array_slice($backups, 30) as $old) {
        unlink($old);
    }

    echo "Backup criado: $gzFile (" . round(filesize($gzFile) / 1024, 1) . " KB)\n";
} else {
    echo "ERRO no backup: " . implode("\n", $output) . "\n";
    exit(1);
}
