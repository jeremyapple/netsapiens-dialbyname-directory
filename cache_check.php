<?php
/**
 * Dial-By-Name Cache Diagnostic Tool
 * 
 * Run from command line:
 *   php cache_check.php [domain] [site] [department]
 * 
 * Or access via browser:
 *   https://your-server.com/cache_check.php?domain=example.com
 * 
 * Actions:
 *   ?action=stats    - Show cache statistics (default)
 *   ?action=clear    - Clear cache for specific domain
 *   ?action=clearall - Clear all cache files
 *   ?action=list     - List all cache files
 */

// Load configuration
$configFile = dirname(__DIR__) . '/config.ini';
if (!file_exists($configFile)) {
    die("Error: config.ini not found at $configFile\n");
}

$config = parse_ini_file($configFile, true);
$settings = $config['production2'] ?? $config['production'] ?? [];

$cacheDir = $settings['CACHE_DIR'] ?? '/tmp/dial_by_name_cache';
$cacheTtl = (int)($settings['CACHE_TTL'] ?? 300);

// Determine if CLI or web
$isCli = php_sapi_name() === 'cli';

// Get parameters
if ($isCli) {
    $domain = $argv[1] ?? '';
    $site = $argv[2] ?? null;
    $department = $argv[3] ?? null;
    $action = 'stats';
    
    // Parse --action flag
    foreach ($argv as $arg) {
        if (strpos($arg, '--action=') === 0) {
            $action = substr($arg, 9);
        }
        if ($arg === '--clear') $action = 'clear';
        if ($arg === '--clearall') $action = 'clearall';
        if ($arg === '--list') $action = 'list';
    }
} else {
    header('Content-Type: text/plain');
    $domain = $_GET['domain'] ?? '';
    $site = $_GET['site'] ?? null;
    $department = $_GET['department'] ?? null;
    $action = $_GET['action'] ?? 'stats';
}

// Build cache key (same logic as main script)
$cacheKey = "$domain|$site|$department";
$cacheHash = md5($cacheKey);
$cachePath = "$cacheDir/$cacheHash.json";

echo "=== Dial-By-Name Cache Diagnostic ===\n\n";
echo "Cache Directory: $cacheDir\n";
echo "Cache TTL: {$cacheTtl}s (" . round($cacheTtl / 60, 1) . " minutes)\n";
echo "Action: $action\n";
echo "\n";

switch ($action) {
    case 'list':
        echo "=== All Cache Files ===\n\n";
        
        if (!is_dir($cacheDir)) {
            echo "Cache directory does not exist.\n";
            break;
        }
        
        $files = glob("$cacheDir/*.json");
        if (empty($files)) {
            echo "No cache files found.\n";
            break;
        }
        
        $totalSize = 0;
        $totalUsers = 0;
        
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            $size = filesize($file);
            $totalSize += $size;
            
            $expires = $data['expires'] ?? 0;
            $userCount = is_array($data['value']) ? count($data['value']) : 0;
            $totalUsers += $userCount;
            
            $now = time();
            $status = $expires > $now ? 'VALID' : 'EXPIRED';
            $ttlRemaining = max(0, $expires - $now);
            
            echo basename($file) . "\n";
            echo "  Status: $status\n";
            echo "  Users: $userCount\n";
            echo "  Size: " . round($size / 1024, 2) . " KB\n";
            echo "  Expires: " . date('Y-m-d H:i:s', $expires) . " (in {$ttlRemaining}s)\n";
            echo "\n";
        }
        
        echo "---\n";
        echo "Total files: " . count($files) . "\n";
        echo "Total users cached: $totalUsers\n";
        echo "Total size: " . round($totalSize / 1024, 2) . " KB\n";
        break;
        
    case 'stats':
        if (empty($domain)) {
            echo "Usage: Provide a domain to check specific cache\n";
            echo "  CLI: php cache_check.php example.com [site] [department]\n";
            echo "  Web: ?domain=example.com&site=MainOffice\n\n";
            echo "Or use --list to see all cache files\n";
            break;
        }
        
        echo "=== Cache Stats for Domain ===\n\n";
        echo "Domain: $domain\n";
        echo "Site: " . ($site ?? '(all)') . "\n";
        echo "Department: " . ($department ?? '(all)') . "\n";
        echo "Cache Key: $cacheKey\n";
        echo "Cache Hash: $cacheHash\n";
        echo "Cache File: $cachePath\n";
        echo "\n";
        
        if (!file_exists($cachePath)) {
            echo "Status: NOT CACHED\n";
            echo "The cache file does not exist. Next request will fetch from API.\n";
            break;
        }
        
        $data = json_decode(file_get_contents($cachePath), true);
        $size = filesize($cachePath);
        $expires = $data['expires'] ?? 0;
        $userCount = is_array($data['value']) ? count($data['value']) : 0;
        
        $now = time();
        $isValid = $expires > $now;
        $ttlRemaining = max(0, $expires - $now);
        
        echo "Status: " . ($isValid ? 'VALID' : 'EXPIRED') . "\n";
        echo "Users Cached: $userCount\n";
        echo "File Size: " . round($size / 1024, 2) . " KB\n";
        echo "Created: " . date('Y-m-d H:i:s', $expires - $cacheTtl) . "\n";
        echo "Expires: " . date('Y-m-d H:i:s', $expires) . "\n";
        
        if ($isValid) {
            echo "TTL Remaining: {$ttlRemaining}s (" . round($ttlRemaining / 60, 1) . " minutes)\n";
        } else {
            echo "Expired: " . ($now - $expires) . "s ago\n";
        }
        
        // Show sample of cached users
        if ($userCount > 0 && is_array($data['value'])) {
            echo "\nSample Users (first 5):\n";
            $sample = array_slice($data['value'], 0, 5);
            foreach ($sample as $user) {
                $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                $ext = $user['extension'] ?? 'N/A';
                echo "  - $name (ext: $ext)\n";
            }
            if ($userCount > 5) {
                echo "  ... and " . ($userCount - 5) . " more\n";
            }
        }
        break;
        
    case 'clear':
        if (empty($domain)) {
            echo "Error: Domain required for clear action\n";
            echo "Usage: ?domain=example.com&action=clear\n";
            break;
        }
        
        echo "=== Clearing Cache ===\n\n";
        echo "Cache Key: $cacheKey\n";
        echo "Cache File: $cachePath\n\n";
        
        if (!file_exists($cachePath)) {
            echo "Result: No cache file to delete\n";
            break;
        }
        
        if (@unlink($cachePath)) {
            echo "Result: SUCCESS - Cache cleared\n";
        } else {
            echo "Result: FAILED - Could not delete cache file\n";
        }
        break;
        
    case 'clearall':
        echo "=== Clearing All Cache ===\n\n";
        
        if (!is_dir($cacheDir)) {
            echo "Cache directory does not exist.\n";
            break;
        }
        
        $files = glob("$cacheDir/*.json");
        $count = 0;
        
        foreach ($files as $file) {
            if (@unlink($file)) {
                $count++;
                echo "Deleted: " . basename($file) . "\n";
            } else {
                echo "Failed: " . basename($file) . "\n";
            }
        }
        
        echo "\nResult: Deleted $count of " . count($files) . " files\n";
        break;
        
    default:
        echo "Unknown action: $action\n";
        echo "Valid actions: stats, list, clear, clearall\n";
}

echo "\n";
