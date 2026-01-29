<?php
/**
 * NetSapiens Dial-By-Name Directory - Enhanced Version
 * 
 * ENHANCEMENTS IN THIS VERSION:
 * - Configurable language and voice (via config.ini and URL parameters)
 * - Proper exit handling with * key (can return to parent IVR)
 * - Auto-return to auto attendant (detects original DNIS)
 * - Better state management for menu navigation
 * - Configurable exit URL for returning to main menu
 * 
 * =============================================================================
 * CONFIGURATION FILE (config.ini)
 * =============================================================================
 * 
 * You can define multiple server configurations in config.ini and select
 * which one to use via the ?config= URL parameter.
 * 
 * [production]
 * SERVER = api.server1.com
 * API_KEY = your_api_key_here
 * CACHE_ENABLED = true
 * CACHE_DIR = /tmp/dial_by_name_cache
 * CACHE_TTL = 300
 * DEFAULT_LANGUAGE = en-US
 * DEFAULT_VOICE = female
 * DEFAULT_MAX_DIGITS = 4
 * DEFAULT_MAX_RESULTS = 8
 * OPERATOR_EXTENSION = 100
 * EXIT_URL = 
 * 
 * [server2]
 * SERVER = api.server2.com
 * API_KEY = different_api_key
 * ; ... other settings (inherits nothing, must specify all)
 * 
 * [server3]
 * SERVER = api.server3.com
 * API_KEY = another_api_key
 * 
 * =============================================================================
 * URL PARAMETERS
 * =============================================================================
 * 
 * CONFIG SELECTION
 * ----------------
 * config      (string)  Which config.ini section to use
 *                       Default: production
 *                       Example: ?config=server2
 * 
 * VOICE/LANGUAGE PARAMETERS
 * -------------------------
 * language    (string)  BCP-47 language code for TTS
 *                       Examples: en-US, es-ES, fr-CA, pt-BR, de-DE
 *                       Default: en-US (or config.ini value)
 * 
 * voice       (string)  Voice for TTS - can be gender (male/female) or voice ID
 *                       Examples: male, female, en-US-Wavenet-A
 *                       Default: female (or config.ini value)
 * 
 * EXIT BEHAVIOR
 * -------------
 * The * key behavior depends on where the caller is:
 * 
 * AT MAIN PROMPT (before entering digits):
 *   1. If exit_url is specified → forwards to that URL
 *   2. If AccountUser is a system-aa (auto attendant) → returns to that user
 *   3. Otherwise → restarts the directory
 * 
 * DURING SEARCH (after entering digits but before results):
 *   * returns to the main directory prompt
 * 
 * VIEWING RESULTS (page 1):
 *   * returns to the main directory prompt
 * 
 * VIEWING RESULTS (page 2+):
 *   * goes to the previous page
 * 
 * The script automatically detects if the call came from an auto attendant
 * by checking the AccountUser's service-code via API. No configuration needed!
 * 
 * exit_url    (string)  Optional: URL to forward to when user presses * at main prompt
 *                       Usually not needed - auto-return handles most cases
 * 
 * exit_action (string)  What to do on exit: 'forward' (default), 'hangup', or 'restart'
 * 
 * operator    (string)  Extension to transfer to when pressing 0 at main prompt
 *                       Overrides config.ini OPERATOR_EXTENSION
 *                       Example: ?operator=100
 * 
 * FILTERING PARAMETERS
 * --------------------
 * domain      (string)  The NetSapiens domain to query users from
 *                       (auto-detected from AccountDomain or ToDomain if not specified)
 * site        (string)  Filter users by site(s) - comma-separated for multiple
 *                       Example: site=NYC or site=NYC,LA,Chicago
 * department  (string)  Filter users by department(s) - comma-separated for multiple
 *                       Example: department=Sales or department=Sales,Support
 * mode        (string)  Search mode: lastname, firstname, or both
 * maxdigits   (int)     Maximum digits to gather (2-10)
 *                       Default: config.ini DEFAULT_MAX_DIGITS or 4
 * maxresults  (int)     Maximum results to show per page (1-9)
 *                       Default: config.ini DEFAULT_MAX_RESULTS or 8
 * 
 * MULTIPLE SITES/DEPARTMENTS
 * --------------------------
 * Use comma-separated values to include users from multiple sites or departments:
 *   site=NYC,LA,Chicago
 *   department=Sales,Support,Engineering
 * 
 * If both are specified, users matching ANY site AND ANY department are included.
 * Results are automatically deduplicated by extension.
 * 
 * URL ENCODING FOR SPACES
 * -----------------------
 * If site or department contains spaces, use %20 or + to encode them:
 *   site=Main%20Office   or   site=Main+Office
 *   site=New%20York,Los%20Angeles
 *   department=Tech%20Support
 * 
 * FORWARD BEHAVIOR
 * ----------------
 * bycaller    (string)  Controls ByCaller attribute on Forward verb
 *                       AUTO-DETECTED by default based on NmsAni/NmsDnis:
 *                       - If both are 10+ digits → omit ByCaller (external/PSTN call)
 *                       - Otherwise → ByCaller="yes" (internal extension call)
 *                       
 *                       Manual override values:
 *                       'yes'  = force ByCaller="yes"
 *                       'no'   = force ByCaller="no"  
 *                       'none' = force no ByCaller attribute
 *                       
 *                       Usually you don't need to set this - auto-detection works.
 * 
 * =============================================================================
 * EXAMPLE URLS
 * =============================================================================
 * 
 * Basic (domain auto-detected from POST data):
 *   https://your-server.com/directory.php
 * 
 * With Spanish language:
 *   https://your-server.com/directory.php?language=es-ES&voice=male
 * 
 * Filter by site:
 *   https://your-server.com/directory.php?site=MainOffice
 * 
 * Filter by multiple sites (comma-separated):
 *   https://your-server.com/directory.php?site=NYC,LA,Chicago
 * 
 * Filter by multiple departments:
 *   https://your-server.com/directory.php?department=Sales,Support,Engineering
 * 
 * Filter by site with spaces (use %20 or +):
 *   https://your-server.com/directory.php?site=Main%20Office
 *   https://your-server.com/directory.php?site=New%20York,Los%20Angeles
 * 
 * With operator transfer (press 0 to transfer to extension 100):
 *   https://your-server.com/directory.php?operator=100
 * 
 * With explicit exit URL (overrides auto-return):
 *   https://your-server.com/directory.php?exit_url=https://your-server.com/main-menu.php
 * 
 * Use different server configuration:
 *   https://your-server.com/directory.php?config=server2
 *   https://your-server.com/directory.php?config=server3&site=NYC
 * 
 * Full example:
 *   https://your-server.com/directory.php?config=production&site=NYC,LA&department=Sales&mode=both&operator=0
 * 
 * =============================================================================
 */

session_start();

// Load configuration from config.ini (one directory up)
$configFile = dirname(__DIR__) . '/config.ini';
if (!file_exists($configFile)) {
    die(json_encode(['error' => 'Configuration file not found', 'action' => 'hangup']));
}

$config = parse_ini_file($configFile, true);
if ($config === false) {
    die(json_encode(['error' => 'Configuration file parse error', 'action' => 'hangup']));
}

// Select config section via URL parameter (e.g., ?config=production2)
// Defaults to 'production' if not specified
$configSection = $_GET['config'] ?? $_POST['config'] ?? 'production';

// Validate config section exists
if (!isset($config[$configSection])) {
    // Try fallback to 'production'
    if (isset($config['production'])) {
        $configSection = 'production';
    } else {
        // Use first available section
        $configSection = array_key_first($config);
    }
}

$settings = $config[$configSection] ?? [];

// Configuration from config.ini
define('CONFIG_SECTION', $configSection);  // Store which section we're using
define('NS_API_HOST', $settings['SERVER'] ?? 'api.netsapiens.com');
define('NS_API_KEY', $settings['API_KEY'] ?? '');

define('CACHE_ENABLED', filter_var($settings['CACHE_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN));
define('CACHE_DIR', $settings['CACHE_DIR'] ?? '/tmp/dial_by_name_cache');
define('CACHE_TTL', (int)($settings['CACHE_TTL'] ?? 300));
define('CACHE_PURGE_CHANCE', (int)($settings['CACHE_PURGE_CHANCE'] ?? 100)); // 1 in X chance to purge expired files

// Voice/Language defaults from config
define('DEFAULT_LANGUAGE', $settings['DEFAULT_LANGUAGE'] ?? 'en-US');
define('DEFAULT_VOICE', $settings['DEFAULT_VOICE'] ?? 'female');
define('DEFAULT_EXIT_URL', $settings['EXIT_URL'] ?? '');

// Directory behavior defaults
define('DEFAULT_MAX_DIGITS', (int)($settings['DEFAULT_MAX_DIGITS'] ?? 4));
define('DEFAULT_MAX_RESULTS', (int)($settings['DEFAULT_MAX_RESULTS'] ?? 8));

// Operator extension - if set, pressing 0 at main prompt transfers to this extension
define('OPERATOR_EXTENSION', $settings['OPERATOR_EXTENSION'] ?? '');

// Debug/Logging - set to false in production to disable logging
define('DEBUG_MODE', filter_var($settings['DEBUG_MODE'] ?? false, FILTER_VALIDATE_BOOLEAN));

// API pagination - max users per request (API max is 1000)
define('API_PAGE_LIMIT', (int)($settings['API_PAGE_LIMIT'] ?? 1000));

/**
 * Conditional logging function - only logs if DEBUG_MODE is enabled
 */
function debug_log(string $message): void {
    if (DEBUG_MODE) {
        error_log($message);
    }
}

class NetSapiensAPI {
    private string $host;
    private string $apiKey;
    private int $pageLimit;
    
    public function __construct(string $host, string $apiKey, int $pageLimit = 1000) {
        $this->host = rtrim($host, '/');
        $this->apiKey = $apiKey;
        $this->pageLimit = min($pageLimit, 1000); // API max is 1000
    }
    
    private function request(string $endpoint, array $params = []): ?array {
        $url = "https://{$this->host}/ns-api/v2/$endpoint";
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$this->apiKey}",
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            error_log("NetSapiens API curl error: $curlError");
            return null;
        }
        
        if ($httpCode === 200) {
            return json_decode($response, true);
        }
        
        error_log("NetSapiens API request failed: HTTP $httpCode - $response");
        return null;
    }
    
    /**
     * Get all users from a domain with automatic pagination
     * API limit is 1000 per request, so we paginate for larger domains
     */
    public function getUsers(string $domain, ?string $site = null, ?string $department = null): ?array {
        $endpoint = 'domains/' . urlencode($domain) . '/users';
        $allUsers = [];
        $start = 0;
        $pageCount = 0;
        $maxPages = 100; // Safety limit: 100 pages * 1000 = 100,000 users max
        
        do {
            $params = [
                'limit' => $this->pageLimit,
                'start' => $start
            ];
            
            if ($site) {
                $params['site'] = $site;
            }
            if ($department) {
                $params['department'] = $department;
            }
            
            debug_log("Dial-by-name: Fetching users - start=$start, limit={$this->pageLimit}");
            
            $result = $this->request($endpoint, $params);
            
            if ($result === null) {
                // If first page fails, return null. Otherwise return what we have.
                if ($pageCount === 0) {
                    return null;
                }
                debug_log("Dial-by-name: API request failed on page $pageCount, returning " . count($allUsers) . " users fetched so far");
                break;
            }
            
            $resultCount = count($result);
            $allUsers = array_merge($allUsers, $result);
            $pageCount++;
            
            debug_log("Dial-by-name: Page $pageCount returned $resultCount users, total so far: " . count($allUsers));
            
            // If we got fewer results than the limit, we've reached the end
            if ($resultCount < $this->pageLimit) {
                break;
            }
            
            // Move to next page
            $start += $this->pageLimit;
            
        } while ($pageCount < $maxPages);
        
        if ($pageCount >= $maxPages) {
            debug_log("Dial-by-name: WARNING - Hit max pages limit ($maxPages), some users may be missing");
        }
        
        debug_log("Dial-by-name: Fetched total of " . count($allUsers) . " users in $pageCount page(s)");
        
        return $allUsers;
    }
    
    /**
     * Get a single user's information
     */
    public function getUser(string $domain, string $user): ?array {
        $endpoint = 'domains/' . urlencode($domain) . '/users/' . urlencode($user);
        return $this->request($endpoint);
    }
    
    /**
     * Check if a user is a system auto attendant
     */
    public function isAutoAttendant(string $domain, string $user): bool {
        $userInfo = $this->getUser($domain, $user);
        
        if ($userInfo === null) {
            debug_log("Dial-by-name: Could not fetch user info for $user@$domain");
            return false;
        }
        
        $serviceCode = $userInfo['service-code'] ?? '';
        $isAA = str_starts_with(strtolower($serviceCode), 'system-aa');
        
        debug_log("Dial-by-name: User $user@$domain service-code='$serviceCode', isAutoAttendant=" . ($isAA ? 'yes' : 'no'));
        
        return $isAA;
    }
}

class DirectoryCache {
    private string $cacheDir;
    private int $ttl;
    private int $purgeChance;  // 1 in X chance to purge on each request
    
    public function __construct(string $cacheDir, int $ttl, int $purgeChance = 100) {
        $this->cacheDir = $cacheDir;
        $this->ttl = $ttl;
        $this->purgeChance = $purgeChance;
        
        if (!is_dir($this->cacheDir)) {
            // Try to create the directory
            if (!@mkdir($this->cacheDir, 0775, true)) {
                error_log("Dial-by-name: CACHE ERROR - Failed to create directory: $cacheDir");
            } else {
                debug_log("Dial-by-name: Created cache directory: $cacheDir");
                // Try to make it writable by group (for shared hosting)
                @chmod($this->cacheDir, 0775);
            }
        }
        
        // Verify directory is usable
        if (is_dir($this->cacheDir) && !is_writable($this->cacheDir)) {
            error_log("Dial-by-name: CACHE WARNING - Directory exists but not writable: $cacheDir");
            error_log("Dial-by-name: CACHE WARNING - Run: chown www-data:www-data $cacheDir && chmod 775 $cacheDir");
        }
        
        // Probabilistic purge - 1 in X chance to clean up expired files
        if ($purgeChance > 0 && rand(1, $purgeChance) === 1) {
            $this->purgeExpired();
        }
    }
    
    /**
     * Purge all expired cache files
     */
    public function purgeExpired(): int {
        if (!is_dir($this->cacheDir)) {
            return 0;
        }
        
        $files = glob("{$this->cacheDir}/*.json");
        $now = time();
        $purged = 0;
        
        foreach ($files as $file) {
            $data = @json_decode(file_get_contents($file), true);
            $expires = $data['expires'] ?? 0;
            
            if ($expires < $now) {
                if (@unlink($file)) {
                    $purged++;
                }
            }
        }
        
        if ($purged > 0) {
            debug_log("Dial-by-name: CACHE PURGE - Removed $purged expired file(s)");
        }
        
        return $purged;
    }
    
    public function get(string $key): ?array {
        $hash = md5($key);
        $path = "{$this->cacheDir}/{$hash}.json";
        
        if (file_exists($path)) {
            $data = json_decode(file_get_contents($path), true);
            $expires = $data['expires'] ?? 0;
            $now = time();
            
            if ($data && $expires > $now) {
                $ttlRemaining = $expires - $now;
                $userCount = is_array($data['value']) ? count($data['value']) : 0;
                debug_log("Dial-by-name: CACHE HIT - key='$key', hash=$hash, users=$userCount, expires in {$ttlRemaining}s");
                return $data['value'];
            }
            
            debug_log("Dial-by-name: CACHE EXPIRED - key='$key', hash=$hash, expired " . ($now - $expires) . "s ago");
            @unlink($path);
        } else {
            debug_log("Dial-by-name: CACHE MISS - key='$key', hash=$hash, file not found");
        }
        
        return null;
    }
    
    public function set(string $key, array $value): bool {
        $hash = md5($key);
        $path = "{$this->cacheDir}/{$hash}.json";
        $expires = time() + $this->ttl;
        
        $data = json_encode([
            'expires' => $expires,
            'value' => $value
        ]);
        
        if ($data === false) {
            error_log("Dial-by-name: CACHE ERROR - JSON encode failed for key='$key'");
            return false;
        }
        
        // Check if directory exists and is writable
        if (!is_dir($this->cacheDir)) {
            error_log("Dial-by-name: CACHE ERROR - Directory does not exist: {$this->cacheDir}");
            return false;
        }
        
        if (!is_writable($this->cacheDir)) {
            error_log("Dial-by-name: CACHE ERROR - Directory not writable: {$this->cacheDir} (check permissions for web server user)");
            return false;
        }
        
        $result = file_put_contents($path, $data);
        
        if ($result === false) {
            error_log("Dial-by-name: CACHE ERROR - Failed to write file: $path");
            return false;
        }
        
        $userCount = count($value);
        debug_log("Dial-by-name: CACHE SET - key='$key', hash=$hash, users=$userCount, TTL={$this->ttl}s, bytes=$result");
        return true;
    }
    
    /**
     * Get cache statistics for a given key
     */
    public function getStats(string $key): ?array {
        $hash = md5($key);
        $path = "{$this->cacheDir}/{$hash}.json";
        
        if (!file_exists($path)) {
            return null;
        }
        
        $data = json_decode(file_get_contents($path), true);
        $fileSize = filesize($path);
        $now = time();
        $expires = $data['expires'] ?? 0;
        
        return [
            'key' => $key,
            'hash' => $hash,
            'path' => $path,
            'file_size_bytes' => $fileSize,
            'file_size_kb' => round($fileSize / 1024, 2),
            'user_count' => is_array($data['value']) ? count($data['value']) : 0,
            'expires_at' => date('Y-m-d H:i:s', $expires),
            'ttl_remaining' => max(0, $expires - $now),
            'is_valid' => $expires > $now
        ];
    }
    
    /**
     * Clear cache for a specific key
     */
    public function clear(string $key): bool {
        $hash = md5($key);
        $path = "{$this->cacheDir}/{$hash}.json";
        
        if (file_exists($path)) {
            $result = @unlink($path);
            debug_log("Dial-by-name: CACHE CLEAR - key='$key', hash=$hash, success=" . ($result ? 'yes' : 'no'));
            return $result;
        }
        return false;
    }
    
    /**
     * Clear all cache files
     */
    public function clearAll(): int {
        $count = 0;
        $files = glob("{$this->cacheDir}/*.json");
        foreach ($files as $file) {
            if (@unlink($file)) {
                $count++;
            }
        }
        debug_log("Dial-by-name: CACHE CLEAR ALL - removed $count files");
        return $count;
    }
}

class DialByNameSession {
    private string $sessionKey;
    
    public function __construct(string $callId) {
        $this->sessionKey = "dbn_$callId";
    }
    
    public function get(string $key, $default = null) {
        return $_SESSION[$this->sessionKey][$key] ?? $default;
    }
    
    public function set(string $key, $value): void {
        if (!isset($_SESSION[$this->sessionKey])) {
            $_SESSION[$this->sessionKey] = [];
        }
        $_SESSION[$this->sessionKey][$key] = $value;
    }
    
    public function clear(): void {
        unset($_SESSION[$this->sessionKey]);
    }
}

class DialByNameDirectory {
    private NetSapiensAPI $api;
    private ?DirectoryCache $cache;
    private array $users = [];
    private string $domain;
    private ?array $sites;
    private ?array $departments;
    private string $mode;
    
    public function __construct(
        NetSapiensAPI $api,
        string $domain,
        ?array $sites = null,
        ?array $departments = null,
        string $mode = 'lastname',
        ?DirectoryCache $cache = null
    ) {
        $this->api = $api;
        $this->domain = $domain;
        $this->sites = $sites;
        $this->departments = $departments;
        $this->mode = $mode;
        $this->cache = $cache;
    }
    
    public function loadUsers(): bool {
        // Build cache key from all sites and departments
        $sitesKey = $this->sites ? implode(',', $this->sites) : '';
        $deptsKey = $this->departments ? implode(',', $this->departments) : '';
        $cacheKey = "{$this->domain}|{$sitesKey}|{$deptsKey}";
        
        if ($this->cache) {
            $cached = $this->cache->get($cacheKey);
            if ($cached) {
                $this->users = $cached;
                debug_log("Dial-by-name: Loaded " . count($this->users) . " users from cache");
                return true;
            }
        }
        
        // Build list of site/department combinations to query
        $queries = $this->buildQueryCombinations();
        
        debug_log("Dial-by-name: Fetching users for " . count($queries) . " site/department combination(s)");
        
        $allUsers = [];
        $seenExtensions = []; // Track extensions to avoid duplicates
        
        foreach ($queries as $query) {
            $site = $query['site'];
            $department = $query['department'];
            
            debug_log("Dial-by-name: Querying site=" . ($site ?? 'ALL') . ", department=" . ($department ?? 'ALL'));
            
            $result = $this->api->getUsers($this->domain, $site, $department);
            if ($result === null) {
                // If any query fails, continue with others
                debug_log("Dial-by-name: Query failed for site=$site, department=$department");
                continue;
            }
            
            foreach ($result as $user) {
                $extension = $user['user'] ?? '';
                
                // Skip duplicates (same user may appear in multiple queries)
                if (isset($seenExtensions[$extension])) {
                    continue;
                }
                
                // Skip users with system service codes
                $serviceCode = $user['service-code'] ?? '';
                if (str_starts_with(strtolower($serviceCode), 'system-')) {
                    continue;
                }
                
                // Skip users not enabled for dial-by-name directory
                $inDirectory = ($user['directory-annouce-in-dial-by-name-enabled'] ?? 'no') === 'yes';
                if (!$inDirectory) {
                    continue;
                }
                
                // Use the correct API field names
                $firstName = trim($user['name-first-name'] ?? '');
                $lastName = trim($user['name-last-name'] ?? '');
                
                // Skip users without names or extensions
                if ((empty($firstName) && empty($lastName)) || empty($extension)) {
                    continue;
                }
                
                $seenExtensions[$extension] = true;
                $allUsers[] = [
                    'extension' => $extension,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'full_name' => trim("$firstName $lastName"),
                    'department' => $user['department'] ?? '',
                    'site' => $user['site'] ?? '',
                    'first_digits' => $this->nameToDigits($firstName),
                    'last_digits' => $this->nameToDigits($lastName)
                ];
            }
        }
        
        // If all queries failed, return false
        if (empty($allUsers) && !empty($queries)) {
            // Check if we got zero results vs all failures
            // If seenExtensions is empty but we had queries, might be all failures
            debug_log("Dial-by-name: No users found across all queries");
        }
        
        $this->users = $allUsers;
        
        usort($this->users, fn($a, $b) => 
            strcasecmp($a['last_name'], $b['last_name']) ?: strcasecmp($a['first_name'], $b['first_name'])
        );
        
        if ($this->cache && !empty($this->users)) {
            $this->cache->set($cacheKey, $this->users);
        }
        
        debug_log("Dial-by-name: Total unique users loaded: " . count($this->users));
        
        return true;
    }
    
    /**
     * Build list of site/department combinations to query
     * If both sites and departments are specified, queries each combination
     * If only one is specified, queries each value with null for the other
     * If neither specified, queries once with both null
     */
    private function buildQueryCombinations(): array {
        $queries = [];
        
        if ($this->sites && $this->departments) {
            // Query each site/department combination
            foreach ($this->sites as $site) {
                foreach ($this->departments as $dept) {
                    $queries[] = ['site' => $site, 'department' => $dept];
                }
            }
        } elseif ($this->sites) {
            // Query each site with no department filter
            foreach ($this->sites as $site) {
                $queries[] = ['site' => $site, 'department' => null];
            }
        } elseif ($this->departments) {
            // Query each department with no site filter
            foreach ($this->departments as $dept) {
                $queries[] = ['site' => null, 'department' => $dept];
            }
        } else {
            // No filters - query all
            $queries[] = ['site' => null, 'department' => null];
        }
        
        return $queries;
    }
    
    private function nameToDigits(string $name): string {
        static $map = [
            'A'=>'2','B'=>'2','C'=>'2','D'=>'3','E'=>'3','F'=>'3',
            'G'=>'4','H'=>'4','I'=>'4','J'=>'5','K'=>'5','L'=>'5',
            'M'=>'6','N'=>'6','O'=>'6','P'=>'7','Q'=>'7','R'=>'7','S'=>'7',
            'T'=>'8','U'=>'8','V'=>'8','W'=>'9','X'=>'9','Y'=>'9','Z'=>'9'
        ];
        
        $digits = '';
        foreach (str_split(strtoupper($name)) as $char) {
            $digits .= $map[$char] ?? '';
        }
        return $digits;
    }
    
    public function search(string $digits): array {
        $matches = [];
        
        foreach ($this->users as $user) {
            $matched = false;
            
            switch ($this->mode) {
                case 'firstname':
                    $matched = str_starts_with($user['first_digits'], $digits);
                    break;
                case 'lastname':
                    $matched = str_starts_with($user['last_digits'], $digits);
                    break;
                case 'both':
                    $matched = str_starts_with($user['first_digits'], $digits) 
                            || str_starts_with($user['last_digits'], $digits);
                    break;
            }
            
            if ($matched) {
                $matches[] = $user;
            }
        }
        
        return $matches;
    }
    
    public function getMode(): string {
        return $this->mode;
    }
    
    public function getDomain(): string {
        return $this->domain;
    }
    
    public function getUserCount(): int {
        return count($this->users);
    }
}

/**
 * Voice configuration class to manage language and voice settings
 */
class VoiceConfig {
    private string $language;
    private string $voice;
    
    public function __construct(string $language = DEFAULT_LANGUAGE, string $voice = DEFAULT_VOICE) {
        $this->language = $this->validateLanguage($language);
        $this->voice = $this->validateVoice($voice);
    }
    
    private function validateLanguage(string $language): string {
        // Common BCP-47 language codes
        $validLanguages = [
            'en-US', 'en-GB', 'en-AU', 'en-CA', 'en-IN',
            'es-ES', 'es-MX', 'es-US',
            'fr-FR', 'fr-CA',
            'de-DE', 'de-AT', 'de-CH',
            'it-IT',
            'pt-BR', 'pt-PT',
            'nl-NL',
            'ja-JP',
            'ko-KR',
            'zh-CN', 'zh-TW',
            'ru-RU',
            'pl-PL',
            'sv-SE',
            'da-DK',
            'no-NO',
            'fi-FI'
        ];
        
        // If valid, return as-is; otherwise default to en-US
        return in_array($language, $validLanguages) ? $language : 'en-US';
    }
    
    private function validateVoice(string $voice): string {
        // Accept 'male', 'female', or specific voice IDs
        $voice = strtolower(trim($voice));
        if (in_array($voice, ['male', 'female'])) {
            return $voice;
        }
        // If it looks like a voice ID (contains hyphen or numbers), accept it
        if (preg_match('/[-0-9]/', $voice)) {
            return $voice;
        }
        return 'female';
    }
    
    public function getLanguage(): string {
        return $this->language;
    }
    
    public function getVoice(): string {
        return $this->voice;
    }
    
    public function toArray(): array {
        return [
            'language' => $this->language,
            'voice' => $this->voice
        ];
    }
}

class WebResponderXML {
    private static ?VoiceConfig $voiceConfig = null;
    
    public static function setVoiceConfig(VoiceConfig $config): void {
        self::$voiceConfig = $config;
    }
    
    private static function getVoice(): string {
        return self::$voiceConfig ? self::$voiceConfig->getVoice() : DEFAULT_VOICE;
    }
    
    private static function getLanguage(): string {
        return self::$voiceConfig ? self::$voiceConfig->getLanguage() : DEFAULT_LANGUAGE;
    }
    
    public static function gather(array $sayTexts, array $options = []): string {
        header('Content-Type: application/xml');
        
        $numDigits = $options['numDigits'] ?? 4;
        $action = $options['action'] ?? '';
        $timeout = $options['timeout'] ?? 10;
        $language = $options['language'] ?? self::getLanguage();
        $voice = $options['voice'] ?? self::getVoice();
        $finishOnKey = $options['finishOnKey'] ?? '#';
        
        $combinedText = implode(' ', $sayTexts);
        
        $attrs = [];
        $attrs[] = 'numDigits="' . $numDigits . '"';
        $attrs[] = 'timeout="' . $timeout . '"';
        
        if ($action) {
            $attrs[] = 'action="' . htmlspecialchars($action) . '"';
        }
        
        $gatherAttrs = implode(' ', $attrs);
        
        return '<Gather ' . $gatherAttrs . '>' .
               '<Say voice="' . $voice . '" language="' . $language . '">' . 
               htmlspecialchars($combinedText) . 
               '</Say></Gather>';
    }
    
    public static function forward(string $destination, array $sayTexts = [], array $options = []): string {
        header('Content-Type: application/xml');
        
        $language = $options['language'] ?? self::getLanguage();
        $voice = $options['voice'] ?? self::getVoice();
        $byCaller = $options['byCaller'] ?? null;  // null = don't include attribute
        
        $xml = '<Response>';
        
        if (!empty($sayTexts)) {
            $combinedText = implode(' ', $sayTexts);
            $xml .= '<Say voice="' . $voice . '" language="' . $language . '">' . 
                    htmlspecialchars($combinedText) . '</Say>';
        }
        
        // Only include ByCaller attribute if explicitly set
        if ($byCaller !== null && $byCaller !== '') {
            $xml .= '<Forward ByCaller="' . htmlspecialchars($byCaller) . '">' . htmlspecialchars($destination) . '</Forward>';
        } else {
            $xml .= '<Forward>' . htmlspecialchars($destination) . '</Forward>';
        }
        
        $xml .= '</Response>';
        
        return $xml;
    }
    
    public static function hangup(array $sayTexts = [], array $options = []): string {
        header('Content-Type: application/xml');
        
        $language = $options['language'] ?? self::getLanguage();
        $voice = $options['voice'] ?? self::getVoice();
        
        $xml = '<Response>';
        
        if (!empty($sayTexts)) {
            $combinedText = implode(' ', $sayTexts);
            $xml .= '<Say voice="' . $voice . '" language="' . $language . '">' . 
                    htmlspecialchars($combinedText) . '</Say>';
        }
        
        $xml .= '<Hangup/>';
        $xml .= '</Response>';
        
        return $xml;
    }
    
    public static function say(array $sayTexts, array $options = []): string {
        header('Content-Type: application/xml');
        
        $language = $options['language'] ?? self::getLanguage();
        $voice = $options['voice'] ?? self::getVoice();
        $action = $options['action'] ?? '';
        
        $xml = '<Response>';
        
        $combinedText = implode(' ', $sayTexts);
        $attrs = 'voice="' . $voice . '" language="' . $language . '"';
        if ($action) {
            $attrs .= ' action="' . htmlspecialchars($action) . '"';
        }
        $xml .= '<Say ' . $attrs . '>' . htmlspecialchars($combinedText) . '</Say>';
        
        $xml .= '</Response>';
        
        return $xml;
    }
    
    /**
     * Generate a redirect/forward to an external URL (for exit to main menu)
     */
    public static function redirectToUrl(string $url, array $sayTexts = [], array $options = []): string {
        header('Content-Type: application/xml');
        
        $language = $options['language'] ?? self::getLanguage();
        $voice = $options['voice'] ?? self::getVoice();
        
        $xml = '<Response>';
        
        if (!empty($sayTexts)) {
            $combinedText = implode(' ', $sayTexts);
            $xml .= '<Say voice="' . $voice . '" language="' . $language . '">' . 
                    htmlspecialchars($combinedText) . '</Say>';
        }
        
        // Use Forward with the URL - NetSapiens will POST to this URL
        $xml .= '<Forward>' . htmlspecialchars($url) . '</Forward>';
        $xml .= '</Response>';
        
        return $xml;
    }
}

class DialByNameHandler {
    private DialByNameDirectory $directory;
    private DialByNameSession $session;
    private VoiceConfig $voiceConfig;
    private string $selfUrl;
    private int $maxDigits;
    private int $maxResults;
    private string $exitUrl;
    private string $exitAction;
    private ?string $byCaller;
    private string $returnTo;  // Original destination to return to on exit
    private string $operatorExtension;  // Extension to transfer to when pressing 0
    
    public function __construct(
        DialByNameDirectory $directory, 
        DialByNameSession $session,
        VoiceConfig $voiceConfig,
        string $selfUrl = '', 
        int $maxDigits = 4, 
        int $maxResults = 8, 
        string $exitUrl = '',
        string $exitAction = 'forward',
        ?string $byCaller = null,
        string $returnTo = '',
        string $operatorExtension = ''
    ) {
        $this->directory = $directory;
        $this->session = $session;
        $this->voiceConfig = $voiceConfig;
        $this->selfUrl = $selfUrl ?: $_SERVER['REQUEST_URI'];
        $this->maxDigits = $maxDigits;
        $this->maxResults = $maxResults;
        $this->exitUrl = $exitUrl;
        $this->exitAction = $exitAction;
        $this->byCaller = $byCaller;
        $this->returnTo = $returnTo;
        $this->operatorExtension = $operatorExtension;
        
        // Set voice config for XML generator
        WebResponderXML::setVoiceConfig($voiceConfig);
    }
    
    public function handle(array $request): string {
        $digits = $request['digits'] ?? $request['Digits'] ?? '';
        $state = $this->session->get('state', 'initial');
        $accumulatedDigits = $this->session->get('accumulated_digits', '');
        
        debug_log("Dial-by-name: handle() - state='$state', digits='$digits', accumulated='$accumulatedDigits'");
        
        // On first request (initial state), store the return destination for later
        // This captures where the call came from (e.g., auto attendant) so we can return there
        if ($state === 'initial' && !empty($this->returnTo)) {
            $this->session->set('return_to', $this->returnTo);
            debug_log("Dial-by-name: Stored return destination: {$this->returnTo}");
        }
        
        // Load users on first request
        if ($this->directory->getUserCount() === 0) {
            if (!$this->directory->loadUsers()) {
                return $this->errorResponse();
            }
            debug_log("Dial-by-name: Loaded " . $this->directory->getUserCount() . " users");
        }
        
        // Handle star key - behavior depends on current state
        // - At root (initial): exit to auto attendant
        // - During search/select: return to directory main menu
        if ($digits === '*' || str_contains($digits, '*')) {
            debug_log("Dial-by-name: Star key detected in state='$state'");
            return $this->handleStar($state);
        }
        
        // Handle 0 key - transfer to operator if configured and at main prompt
        if ($digits === '0') {
            debug_log("Dial-by-name: 0 key pressed - operatorExtension='{$this->operatorExtension}', state='$state', accumulated='$accumulatedDigits'");
            if (!empty($this->operatorExtension)) {
                // Only transfer to operator if at main prompt (no digits entered yet)
                if (empty($accumulatedDigits) && $state !== 'selecting') {
                    debug_log("Dial-by-name: Transferring to operator: {$this->operatorExtension}");
                    return $this->transferToOperator();
                } else {
                    debug_log("Dial-by-name: 0 key ignored - not at main prompt");
                }
            } else {
                debug_log("Dial-by-name: 0 key ignored - no operator extension configured");
            }
        }
        
        // Handle based on current state
        switch ($state) {
            case 'initial':
                debug_log("Dial-by-name: Routing to handleInitial");
                return $this->handleInitial($digits);
            case 'searching':
                debug_log("Dial-by-name: Routing to handleSearching");
                return $this->handleSearching($digits);
            case 'selecting':
                debug_log("Dial-by-name: Routing to handleSelecting");
                return $this->handleSelecting($digits);
            default:
                debug_log("Dial-by-name: Unknown state '$state' - resetting to initial");
                $this->session->clear();
                return $this->handleInitial('');
        }
    }
    
    /**
     * Handle star key press - behavior depends on where user is in the flow
     * - Viewing results on page 2+: go to previous page
     * - Viewing results on page 1: return to main directory prompt
     * - At main prompt (no digits entered): exit to auto attendant or restart
     */
    private function handleStar(string $currentState): string {
        $accumulatedDigits = $this->session->get('accumulated_digits', '');
        $currentPage = $this->session->get('current_page', 0);
        
        // If viewing results (selecting state), handle pagination
        if ($currentState === 'selecting') {
            if ($currentPage > 0) {
                // Go to previous page
                $allMatches = $this->session->get('all_matches', []);
                $prevPage = $currentPage - 1;
                debug_log("Dial-by-name: Star pressed on page " . ($currentPage + 1) . " - going back to page " . ($prevPage + 1));
                return $this->presentMenu($allMatches, $prevPage);
            } else {
                // On first page of results - go back to main directory
                debug_log("Dial-by-name: Star pressed on first page of results - returning to main directory");
                $this->session->set('state', 'initial');
                $this->session->set('accumulated_digits', '');
                $this->session->set('current_matches', []);
                $this->session->set('all_matches', []);
                $this->session->set('current_page', 0);
                return $this->promptForName();
            }
        }
        
        // If user has entered search digits but not yet viewing results
        if (!empty($accumulatedDigits)) {
            debug_log("Dial-by-name: Star pressed during search - returning to main directory");
            $this->session->set('state', 'initial');
            $this->session->set('accumulated_digits', '');
            $this->session->set('current_matches', []);
            $this->session->set('all_matches', []);
            $this->session->set('current_page', 0);
            return $this->promptForName();
        }
        
        // At main prompt (no search yet) - exit to auto attendant or configured exit
        debug_log("Dial-by-name: Star pressed at main prompt - exiting directory");
        return $this->handleExit();
    }
    
    /**
     * Handle exit from root - forward to auto attendant, exit URL, or restart directory
     */
    private function handleExit(): string {
        debug_log("Dial-by-name: handleExit called");
        
        // Get the stored return destination BEFORE clearing session
        $returnTo = $this->session->get('return_to', '');
        
        // Completely clear ALL session data for this call
        $this->session->clear();
        
        // Priority 1: Explicit exit URL (from config or URL parameter)
        if (!empty($this->exitUrl)) {
            debug_log("Dial-by-name: Exiting to explicit URL: {$this->exitUrl}");
            return WebResponderXML::redirectToUrl(
                $this->exitUrl,
                ["Returning to main menu."]
            );
        }
        
        // Priority 2: Return to original dialed number (auto-detected from NmsDnis)
        // This returns the caller to wherever they came from (e.g., auto attendant)
        if (!empty($returnTo)) {
            $domain = $this->directory->getDomain();
            $destination = $returnTo . '@' . $domain;
            debug_log("Dial-by-name: Returning to original destination: $destination");
            return WebResponderXML::forward(
                $destination,
                ["Returning to main menu."],
                ['byCaller' => $this->byCaller]
            );
        }
        
        // Priority 3: Hangup if requested
        if ($this->exitAction === 'hangup') {
            debug_log("Dial-by-name: Hanging up");
            return WebResponderXML::hangup(["Goodbye."]);
        }
        
        // Default: restart directory
        debug_log("Dial-by-name: Restarting directory - setting state to initial");
        $this->session->set('state', 'initial');
        $this->session->set('accumulated_digits', '');
        $this->session->set('current_matches', []);
        
        return $this->promptForName();
    }
    
    private function handleInitial(string $digits): string {
        if (empty($digits)) {
            $this->session->set('state', 'searching');
            $this->session->set('accumulated_digits', '');
            return $this->promptForName();
        }
        
        return $this->handleSearching($digits);
    }
    
    private function handleSearching(string $digits): string {
        // Clean digits - remove # (terminator) and any non-numeric
        $cleanDigits = preg_replace('/[^0-9]/', '', $digits);
        
        // Get current accumulated digits
        $previousAccumulated = $this->session->get('accumulated_digits', '');
        $accumulated = $previousAccumulated . $cleanDigits;
        $this->session->set('accumulated_digits', $accumulated);
        
        debug_log("Dial-by-name: handleSearching - input='$digits', clean='$cleanDigits', prev='$previousAccumulated', accumulated='$accumulated'");
        
        if (empty($accumulated)) {
            return $this->promptForName();
        }
        
        $matches = $this->directory->search($accumulated);
        
        // Try with prefix if no matches
        if (empty($matches) && strlen($accumulated) >= 2) {
            for ($prefix = 2; $prefix <= 9; $prefix++) {
                $tryDigits = $prefix . $accumulated;
                $tryMatches = $this->directory->search($tryDigits);
                if (!empty($tryMatches)) {
                    $matches = $tryMatches;
                    $accumulated = $tryDigits;
                    $this->session->set('accumulated_digits', $accumulated);
                    debug_log("Dial-by-name: Found matches with prefix $prefix");
                    break;
                }
            }
        }
        
        debug_log("Dial-by-name: Found " . count($matches) . " matches for '$accumulated'");
        
        if (empty($matches)) {
            // Clear accumulated digits on no match so user can start fresh
            $this->session->set('accumulated_digits', '');
            return WebResponderXML::gather(
                ["No matches were found.", "Please try again, or press star to start over."],
                [
                    'numDigits' => $this->maxDigits, 
                    'action' => $this->selfUrl
                ]
            );
        }
        
        if (count($matches) === 1) {
            return $this->transferTo($matches[0]);
        }
        
        // Multiple matches - show menu with pagination
        // No longer need to ask for more digits, pagination handles large result sets
        return $this->presentMenu($matches);
    }
    
    private function handleSelecting(string $digits): string {
        $currentMatches = $this->session->get('current_matches', []);
        $allMatches = $this->session->get('all_matches', $currentMatches);
        $currentPage = $this->session->get('current_page', 0);
        
        // With numDigits="1", we should only get a single character
        $digit = trim($digits);
        
        debug_log("Dial-by-name: handleSelecting - digit='$digit', page=$currentPage, pageMatches=" . count($currentMatches) . ", totalMatches=" . count($allMatches));
        
        // Handle repeat current page
        if ($digit === '0') {
            debug_log("Dial-by-name: Repeating current page");
            return $this->presentMenu($allMatches, $currentPage);
        }
        
        // Handle "9 for more" - next page
        if ($digit === '9') {
            $perPage = $this->maxResults - 1;
            if ($perPage < 1) $perPage = 1;
            $totalPages = ceil(count($allMatches) / $perPage);
            $nextPage = $currentPage + 1;
            
            if ($nextPage < $totalPages) {
                debug_log("Dial-by-name: Going to next page $nextPage");
                return $this->presentMenu($allMatches, $nextPage);
            } else {
                // No more pages, treat as invalid
                debug_log("Dial-by-name: No more pages available");
                return WebResponderXML::gather(
                    ["No more options. Please make a selection, or press star to start over."],
                    ['numDigits' => 1, 'action' => $this->selfUrl]
                );
            }
        }
        
        // Handle numeric selection (1-8)
        if (is_numeric($digit)) {
            $selection = (int)$digit;
            if ($selection >= 1 && $selection <= count($currentMatches)) {
                debug_log("Dial-by-name: Selected option $selection - transferring to {$currentMatches[$selection - 1]['full_name']}");
                return $this->transferTo($currentMatches[$selection - 1]);
            }
        }
        
        // Invalid selection
        debug_log("Dial-by-name: Invalid selection '$digit'");
        return WebResponderXML::gather(
            ["Invalid selection. Please try again, or press star to start over."],
            ['numDigits' => 1, 'action' => $this->selfUrl]
        );
    }
    
    private function promptForName(): string {
        $mode = $this->directory->getMode();
        
        // Build prompt based on mode
        $nameType = match($mode) {
            'firstname' => "first name",
            'lastname' => "last name",
            'both' => "first or last name",
            default => "name"
        };
        
        $prompt = "Using your telephone keypad, enter up to {$this->maxDigits} letters of the person's $nameType, then press pound.";
        
        // Add operator option if configured
        $operatorPrompt = '';
        if (!empty($this->operatorExtension)) {
            $operatorPrompt = "Press 0 for the operator.";
        }
        
        // At the main prompt, * exits to auto attendant (if available)
        $returnTo = $this->session->get('return_to', '');
        if (!empty($this->exitUrl) || !empty($returnTo)) {
            $exitPrompt = "Press star to return to the main menu.";
        } else {
            $exitPrompt = "Press star to start over.";
        }
        
        // Ensure state is properly set for searching
        $this->session->set('state', 'searching');
        $this->session->set('accumulated_digits', '');
        $this->session->set('current_matches', []);
        
        debug_log("Dial-by-name: promptForName - state set to 'searching', accumulated_digits cleared");
        
        // Build prompts array
        $prompts = [
            "Welcome to the dial by name directory.",
            $prompt
        ];
        if ($operatorPrompt) {
            $prompts[] = $operatorPrompt;
        }
        $prompts[] = $exitPrompt;
        
        return WebResponderXML::gather(
            $prompts,
            [
                'numDigits' => $this->maxDigits, 
                'action' => $this->selfUrl
            ]
        );
    }
    
    private function presentMenu(array $matches, int $page = 0): string {
        // Clear accumulated digits when entering menu - menu is a fresh state
        $this->session->set('state', 'selecting');
        $this->session->set('all_matches', $matches); // Store ALL matches for pagination
        $this->session->set('current_page', $page);
        $this->session->set('accumulated_digits', ''); // Clear search digits
        
        // Calculate pagination - reserve slot for "9 = more" if needed
        $perPage = $this->maxResults - 1; // Show up to 8 per page, keep 9 for "more"
        if ($perPage < 1) $perPage = 1;
        
        $totalMatches = count($matches);
        $totalPages = ceil($totalMatches / $perPage);
        $startIndex = $page * $perPage;
        $pageMatches = array_slice($matches, $startIndex, $perPage);
        $hasMorePages = ($page + 1) < $totalPages;
        
        // Store current page matches for selection
        $this->session->set('current_matches', $pageMatches);
        
        debug_log("Dial-by-name: presentMenu - page " . ($page + 1) . " of $totalPages, showing " . count($pageMatches) . " of $totalMatches matches");
        
        $options = [];
        foreach ($pageMatches as $i => $match) {
            $num = $i + 1;
            $options[] = "$num, {$match['full_name']}";
        }
        
        // Build prompt
        $prompt = implode('. ', $options) . ".";
        
        // Add pagination option if more pages exist
        if ($hasMorePages) {
            $remaining = $totalMatches - $startIndex - count($pageMatches);
            $prompt .= " 9 for $remaining more options.";
        }
        
        // Add repeat and navigation options
        // Star behavior depends on page: page 1 goes to main menu, page 2+ goes back
        $prompt .= " 0 to repeat.";
        if ($page > 0) {
            $prompt .= " Star for previous page.";
        } else {
            $prompt .= " Star to start over.";
        }
        
        // Add page indicator if paginated
        if ($totalPages > 1) {
            $pageNum = $page + 1;
            $prompt = "Page $pageNum of $totalPages. " . $prompt;
        }
        
        // Build menu URL for action callbacks
        $menuUrl = $this->buildMenuUrl();
        
        debug_log("Dial-by-name: Menu URL: $menuUrl");
        
        // Use numDigits="1" for IMMEDIATE response on single digit selection
        $xml = '<Response>';
        $xml .= '<Gather input="dtmf" numDigits="1" action="' . htmlspecialchars($menuUrl) . '">';
        $xml .= '<Say voice="' . $this->voiceConfig->getVoice() . '" language="' . $this->voiceConfig->getLanguage() . '">';
        $xml .= htmlspecialchars($prompt);
        $xml .= '</Say></Gather>';
        $xml .= '</Response>';
        
        header('Content-Type: application/xml');
        return $xml;
    }
    
    private function buildMenuUrl(): string {
        $baseUrl = strtok($this->selfUrl, '?');
        $queryParams = [];
        
        // Parse existing params
        $existingQuery = parse_url($this->selfUrl, PHP_URL_QUERY);
        if ($existingQuery) {
            parse_str($existingQuery, $queryParams);
        }
        
        // Remove input param to force DTMF-only for menu
        unset($queryParams['input']);
        
        if (!empty($queryParams)) {
            return $baseUrl . '?' . http_build_query($queryParams);
        }
        return $baseUrl;
    }
    
    private function transferTo(array $user): string {
        $this->session->clear();
        $domain = $this->directory->getDomain();
        $destination = $user['extension'] . '@' . $domain;
        return WebResponderXML::forward(
            $destination,
            ["Transferring to {$user['full_name']}. Please hold."],
            ['byCaller' => $this->byCaller]
        );
    }
    
    private function transferToOperator(): string {
        $this->session->clear();
        $domain = $this->directory->getDomain();
        $destination = $this->operatorExtension . '@' . $domain;
        debug_log("Dial-by-name: Transferring to operator at $destination");
        return WebResponderXML::forward(
            $destination,
            ["Transferring to the operator. Please hold."],
            ['byCaller' => $this->byCaller]
        );
    }
    
    private function errorResponse(): string {
        return WebResponderXML::hangup(
            ["We're sorry, the directory is temporarily unavailable. Please try again later."]
        );
    }
}

// =============================================================================
// Main Entry Point
// =============================================================================

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (stripos($contentType, 'application/json') !== false) {
    $requestBody = file_get_contents('php://input');
    $request = json_decode($requestBody, true) ?? [];
} else {
    $request = $_POST;
}

$request = array_merge($request, $_GET);

// Map NetSapiens CDT field names
$domain = $request['domain'] ?? $request['ToDomain'] ?? $request['AccountDomain'] ?? '';

// Parse site and department - support comma-separated values for multiple
$siteParam = $request['site'] ?? null;
$departmentParam = $request['department'] ?? null;

// Convert to arrays if comma-separated, null if empty
$sites = null;
if ($siteParam) {
    $sites = array_map('trim', explode(',', $siteParam));
    $sites = array_filter($sites); // Remove empty values
    if (empty($sites)) $sites = null;
}

$departments = null;
if ($departmentParam) {
    $departments = array_map('trim', explode(',', $departmentParam));
    $departments = array_filter($departments);
    if (empty($departments)) $departments = null;
}

$mode = $request['mode'] ?? 'lastname';
$digits = $request['digits'] ?? $request['Digits'] ?? '';
$callId = $request['call_id'] ?? $request['OrigCallID'] ?? $request['TermCallID'] ?? session_id();
$maxDigits = (int)($request['maxdigits'] ?? DEFAULT_MAX_DIGITS);
$maxResults = (int)($request['maxresults'] ?? DEFAULT_MAX_RESULTS);

// Voice/Language settings - URL params override config
$language = $request['language'] ?? DEFAULT_LANGUAGE;
$voice = $request['voice'] ?? DEFAULT_VOICE;

// Exit behavior settings
$exitUrl = $request['exit_url'] ?? DEFAULT_EXIT_URL;
$exitAction = $request['exit_action'] ?? 'forward';

// Operator extension - URL param overrides config
$operatorExtension = $request['operator'] ?? OPERATOR_EXTENSION;
debug_log("Dial-by-name: Operator config - URL param='" . ($request['operator'] ?? 'not set') . "', config='" . OPERATOR_EXTENSION . "', using='$operatorExtension'");

// Forward behavior - ByCaller attribute
// Auto-detect based on ANI/DNIS:
// - If both are 10+ digits (external/PSTN) → omit ByCaller (auto attendant scenario)
// - Otherwise (internal extensions) → use ByCaller="yes"
$nmsAni = $request['NmsAni'] ?? '';
$nmsDnis = $request['NmsDnis'] ?? '';
$accountUser = $request['AccountUser'] ?? '';
$accountDomain = $request['AccountDomain'] ?? $domain;

// Strip any non-digit characters for length check
$aniDigits = preg_replace('/\D/', '', $nmsAni);
$dnisDigits = preg_replace('/\D/', '', $nmsDnis);

// Auto-detect: if both ANI and DNIS are 10+ digits, it's likely external/PSTN
if (strlen($aniDigits) >= 10 && strlen($dnisDigits) >= 10) {
    $byCaller = null;  // Omit ByCaller attribute
    debug_log("Dial-by-name: Auto-detect ByCaller - ANI='$nmsAni', DNIS='$nmsDnis' - EXTERNAL (no ByCaller)");
} else {
    $byCaller = 'yes';  // Internal call, use caller's dial plan
    debug_log("Dial-by-name: Auto-detect ByCaller - ANI='$nmsAni', DNIS='$nmsDnis' - INTERNAL (ByCaller=yes)");
}

// Auto-detect return destination for * key exit
// Check if AccountUser is a system auto attendant - if so, return to it on exit
// Only do API lookup on first request (when session doesn't have return_to yet)
$session = new DialByNameSession($callId);
$returnTo = '';
$existingReturnTo = $session->get('return_to', '');

if (!empty($existingReturnTo)) {
    // Already determined on previous request - use cached value
    $returnTo = $existingReturnTo;
    debug_log("Dial-by-name: Using cached return destination: $returnTo");
} elseif (!empty($accountUser) && !empty($accountDomain)) {
    // First request - check if source is auto attendant via API
    $apiForCheck = new NetSapiensAPI(NS_API_HOST, NS_API_KEY, API_PAGE_LIMIT);
    
    if ($apiForCheck->isAutoAttendant($accountDomain, $accountUser)) {
        $returnTo = $accountUser;
        debug_log("Dial-by-name: AccountUser '$accountUser' is auto attendant - will return here on exit");
    } else {
        debug_log("Dial-by-name: AccountUser '$accountUser' is not auto attendant - will restart directory on exit");
    }
} else {
    debug_log("Dial-by-name: No AccountUser provided - will restart directory on exit");
}

// Allow manual override via URL parameter if needed
if (isset($request['bycaller'])) {
    $byCallerOverride = strtolower(trim($request['bycaller']));
    if ($byCallerOverride === 'yes' || $byCallerOverride === 'no') {
        $byCaller = $byCallerOverride;
        debug_log("Dial-by-name: ByCaller manually overridden to '$byCaller'");
    } elseif ($byCallerOverride === 'none' || $byCallerOverride === '') {
        $byCaller = null;
        debug_log("Dial-by-name: ByCaller manually disabled");
    }
}

// Validate parameters
if ($maxDigits < 2) $maxDigits = 2;
if ($maxDigits > 10) $maxDigits = 10;
if ($maxResults < 1) $maxResults = 1;
if ($maxResults > 9) $maxResults = 9;
if (!in_array($mode, ['firstname', 'lastname', 'both'])) $mode = 'lastname';
if (!in_array($exitAction, ['forward', 'hangup', 'restart'])) $exitAction = 'forward';

// Create voice config
$voiceConfig = new VoiceConfig($language, $voice);

// Build self URL for action callbacks
$selfUrl = strtok($_SERVER['REQUEST_URI'], '?');
$queryParams = [];
// Preserve config section if not default
if (CONFIG_SECTION !== 'production') $queryParams['config'] = CONFIG_SECTION;
// Convert arrays back to comma-separated for URL
if ($sites) $queryParams['site'] = implode(',', $sites);
if ($departments) $queryParams['department'] = implode(',', $departments);
if ($mode !== 'lastname') $queryParams['mode'] = $mode;
if ($maxDigits !== DEFAULT_MAX_DIGITS) $queryParams['maxdigits'] = $maxDigits;
if ($maxResults !== DEFAULT_MAX_RESULTS) $queryParams['maxresults'] = $maxResults;
if ($language !== DEFAULT_LANGUAGE) $queryParams['language'] = $language;
if ($voice !== DEFAULT_VOICE) $queryParams['voice'] = $voice;
// Always preserve operator if set (important for callback URLs)
if (!empty($operatorExtension)) $queryParams['operator'] = $operatorExtension;
if (!empty($exitUrl)) $queryParams['exit_url'] = $exitUrl;
if ($exitAction !== 'forward') $queryParams['exit_action'] = $exitAction;
// Note: bycaller is auto-detected per request from NmsAni/NmsDnis, not preserved in URL

if (!empty($queryParams)) {
    $selfUrl .= '?' . http_build_query($queryParams);
}

debug_log("Dial-by-name: selfUrl='$selfUrl'");

if (empty($domain)) {
    WebResponderXML::setVoiceConfig($voiceConfig);
    echo WebResponderXML::hangup(["System configuration error. Domain is required."]);
    exit;
}

try {
    $api = new NetSapiensAPI(NS_API_HOST, NS_API_KEY, API_PAGE_LIMIT);
    $cache = CACHE_ENABLED ? new DirectoryCache(CACHE_DIR, CACHE_TTL, CACHE_PURGE_CHANCE) : null;
    $directory = new DialByNameDirectory($api, $domain, $sites, $departments, $mode, $cache);
    // Note: $session was created earlier for the return_to check
    
    $handler = new DialByNameHandler(
        $directory, 
        $session, 
        $voiceConfig,
        $selfUrl, 
        $maxDigits, 
        $maxResults, 
        $exitUrl,
        $exitAction,
        $byCaller,
        $returnTo,
        $operatorExtension
    );
    
    echo $handler->handle($request);
    
} catch (Throwable $e) {
    error_log("Dial-by-name error: " . $e->getMessage());
    WebResponderXML::setVoiceConfig($voiceConfig);
    echo WebResponderXML::hangup(["An error occurred. Please try again later."]);
}
