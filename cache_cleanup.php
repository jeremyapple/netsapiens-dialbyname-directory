#!/usr/bin/env php
<?php
/**
 * Dial-By-Name Cache Cleanup Script
 * 
 * Run via cron to purge expired cache files.
 * 
 * Example crontab entries:
 *   # Every 5 minutes
 *   */5 * * * * /usr/bin/php /path/to/cache_cleanup.php
 * 
 *   # Every hour
 *   0 * * * * /usr/bin/php /path/to/cache_cleanup.php
 * 
 *   # Daily at 3am
 *   0 3 * * * /usr/bin/php /path/to/cache_cleanup.php
 * 
 * Options:
 *   --verbose    Show detailed output
 *   --dry-run    Show what would be deleted without deleting
 *   --force      Delete all cache files (not just expired)
 */

// Parse command line options
$verbose = in_array('--verbose', $argv) || in_array('-v', $argv);
$dryRun = in_array('--dry-run', $argv) || in_array('-n', $argv);
$force = in_array('--force', $argv) || in_array('-f', $argv);

// Load configuration
$configFile = dirname(__DIR__) . '/config.ini';

if (!file_exists($configFile)) {
    fwrite(STDERR, "Error: config.ini not found at $configFile\n");
    exit(1);
}

$config = parse_ini_file($configFile, true);
if ($config === false) {
    fwrite(STDERR, "Error: Failed to parse config.ini\n");
    exit(1);
}

// Get settings (same logic as main script)
$settings = $config['production2'] ?? $config['production'] ?? [];
$cacheDir = $settings['CACHE_DIR'] ?? '/tmp/dial_by_name_cache';

if (!is_dir($cacheDir)) {
    if ($verbose) {
        echo "Cache directory does not exist: $cacheDir\n";
    }
    exit(0);
}

// Find cache files
$files = glob("$cacheDir/*.json");
$now = time();
$purged = 0;
$kept = 0;
$errors = 0;
$totalSize = 0;

if ($verbose) {
    echo "=== Dial-By-Name Cache Cleanup ===\n";
    echo "Directory: $cacheDir\n";
    echo "Mode: " . ($dryRun ? "DRY RUN" : ($force ? "FORCE DELETE ALL" : "Normal")) . "\n";
    echo "Files found: " . count($files) . "\n\n";
}

foreach ($files as $file) {
    $size = filesize($file);
    $data = @json_decode(file_get_contents($file), true);
    $expires = $data['expires'] ?? 0;
    $isExpired = $expires < $now;
    
    $shouldDelete = $force || $isExpired;
    
    if ($verbose) {
        $status = $isExpired ? 'EXPIRED' : 'VALID';
        $action = $shouldDelete ? ($dryRun ? 'WOULD DELETE' : 'DELETING') : 'KEEPING';
        $ttl = $isExpired ? 'expired ' . ($now - $expires) . 's ago' : 'expires in ' . ($expires - $now) . 's';
        
        echo basename($file) . " - $status ($ttl) - $action\n";
    }
    
    if ($shouldDelete) {
        if (!$dryRun) {
            if (@unlink($file)) {
                $purged++;
                $totalSize += $size;
            } else {
                $errors++;
                if ($verbose) {
                    echo "  ERROR: Failed to delete\n";
                }
            }
        } else {
            $purged++;
            $totalSize += $size;
        }
    } else {
        $kept++;
    }
}

// Summary
if ($verbose) {
    echo "\n=== Summary ===\n";
}

$action = $dryRun ? "Would purge" : "Purged";
echo "$action: $purged file(s), " . round($totalSize / 1024, 2) . " KB\n";

if ($kept > 0) {
    echo "Kept: $kept valid file(s)\n";
}

if ($errors > 0) {
    echo "Errors: $errors\n";
    exit(1);
}

exit(0);
