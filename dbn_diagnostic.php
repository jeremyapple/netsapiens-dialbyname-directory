<?php
/**
 * Quick diagnostic for dial-by-name configuration
 * Access: https://your-server.com/dbn_diagnostic.php
 */

header('Content-Type: text/plain');

echo "=== Dial-By-Name Diagnostic ===\n\n";

// Check config file
$configFile = dirname(__DIR__) . '/config.ini';
echo "Config file: $configFile\n";
echo "Config exists: " . (file_exists($configFile) ? 'YES' : 'NO') . "\n\n";

if (!file_exists($configFile)) {
    die("ERROR: Config file not found!\n");
}

// Parse config
$config = parse_ini_file($configFile, true);
echo "Config sections found: " . implode(', ', array_keys($config)) . "\n\n";

// Which section is used?
$settings = $config['production2'] ?? $config['production'] ?? [];
$sectionUsed = isset($config['production2']) ? 'production2' : (isset($config['production']) ? 'production' : 'NONE');
echo "Section being used: $sectionUsed\n\n";

// Show relevant settings
echo "=== Settings ===\n";
echo "CACHE_ENABLED: " . ($settings['CACHE_ENABLED'] ?? 'NOT SET') . "\n";
echo "CACHE_DIR: " . ($settings['CACHE_DIR'] ?? 'NOT SET') . "\n";
echo "CACHE_TTL: " . ($settings['CACHE_TTL'] ?? 'NOT SET') . "\n";
echo "DEBUG_MODE: " . ($settings['DEBUG_MODE'] ?? 'NOT SET') . "\n";
echo "SERVER: " . ($settings['SERVER'] ?? 'NOT SET') . "\n";
echo "\n";

// Check cache directory
$cacheDir = $settings['CACHE_DIR'] ?? '/tmp/dial_by_name_cache';
echo "=== Cache Directory Check ===\n";
echo "Path: $cacheDir\n";
echo "Exists: " . (is_dir($cacheDir) ? 'YES' : 'NO') . "\n";

if (is_dir($cacheDir)) {
    echo "Readable: " . (is_readable($cacheDir) ? 'YES' : 'NO') . "\n";
    echo "Writable: " . (is_writable($cacheDir) ? 'YES' : 'NO') . "\n";
    
    $perms = fileperms($cacheDir);
    echo "Permissions: " . substr(sprintf('%o', $perms), -4) . "\n";
    
    $owner = posix_getpwuid(fileowner($cacheDir));
    $group = posix_getgrgid(filegroup($cacheDir));
    echo "Owner: " . ($owner['name'] ?? 'unknown') . "\n";
    echo "Group: " . ($group['name'] ?? 'unknown') . "\n";
    
    // List cache files
    $files = glob("$cacheDir/*.json");
    echo "Cache files: " . count($files) . "\n";
    
    if (count($files) > 0) {
        echo "\nCache files:\n";
        foreach ($files as $file) {
            $size = filesize($file);
            echo "  - " . basename($file) . " (" . round($size/1024, 2) . " KB)\n";
        }
    }
} else {
    echo "\nAttempting to create directory...\n";
    if (@mkdir($cacheDir, 0775, true)) {
        echo "SUCCESS: Directory created\n";
        @chmod($cacheDir, 0775);
    } else {
        echo "FAILED: Could not create directory\n";
        echo "Error: " . error_get_last()['message'] . "\n";
    }
}

echo "\n=== Web Server Info ===\n";
echo "PHP User: " . get_current_user() . "\n";
echo "Process User: " . posix_getpwuid(posix_geteuid())['name'] . "\n";
echo "PHP Version: " . PHP_VERSION . "\n";

// Test write
echo "\n=== Write Test ===\n";
$testFile = "$cacheDir/test_" . time() . ".tmp";
$testData = "test";

if (@file_put_contents($testFile, $testData)) {
    echo "Write test: SUCCESS\n";
    @unlink($testFile);
    echo "Cleanup: SUCCESS\n";
} else {
    echo "Write test: FAILED\n";
    $error = error_get_last();
    echo "Error: " . ($error['message'] ?? 'unknown') . "\n";
}

echo "\n=== Recommendation ===\n";
if (is_dir($cacheDir) && is_writable($cacheDir)) {
    echo "Cache directory is properly configured.\n";
    echo "If cache still doesn't work, check your error logs for more details.\n";
} else {
    $user = posix_getpwuid(posix_geteuid())['name'];
    echo "Run these commands as root:\n";
    echo "  mkdir -p $cacheDir\n";
    echo "  chown $user:$user $cacheDir\n";
    echo "  chmod 775 $cacheDir\n";
}
