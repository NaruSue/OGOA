<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Migration file not found.\n");
    exit(1);
}

$db = app_db();
if ($db === null) {
    fwrite(STDERR, "Database is not available.\n");
    exit(1);
}

$sql = file_get_contents($file);
if ($sql === false) {
    fwrite(STDERR, "Failed to read migration file.\n");
    exit(1);
}

$db->exec($sql);
echo "MIGRATED\n";
