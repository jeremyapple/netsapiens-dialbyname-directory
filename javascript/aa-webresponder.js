/**
 * NetSapiens Auto Attendant Extension v3
 * Adds Web Responder and Custom Directory options to Auto Attendant configuration
 * Uses inline forms (like native portal) instead of modals
 * 
 * Usage: Add this script via PORTAL_EXTRA_JS in NetSapiens portal configuration
 */

(function() {
    'use strict';

    const CONFIG = {
        // Set to true to enable console logging for debugging
        debug: false,
        // Your directory web responder URL - UPDATE THIS
        directoryBaseUrl: 'https://your-server.com/directory.php'
    };

    function log(...args) {
        if (CONFIG.debug) {
            console.log('[AA Extension]', ...args);
        }
    }

    // ============================================
    // API FUNCTIONS
    // ============================================
    
    function getAAInfo() {
        // Get domain
        const domain = getDomainFromPage();
        
        // Get prompt/attendant ID from tier_1_prompt or attendant_tier_1_1_hiddenvalue
        const promptField = document.getElementById('tier_1_prompt') || 
                           document.querySelector('input[id*="attendant_tier"][id*="hiddenvalue"]');
        const promptId = promptField ? promptField.value : null;
        
        // Try to get dialplan from various sources
        let dialplan = null;
        let aaExtension = null;
        
        // Debug: log the full URL info
        log('URL debug:', { 
            pathname: window.location.pathname, 
            href: window.location.href,
            hash: window.location.hash 
        });
        
        // Method 1: Parse URL path for extension@domain pattern
        const pathMatch = window.location.pathname.match(/\/edit\/(\d+)@([^\/]+)/);
        log('Path match result:', pathMatch);
        if (pathMatch) {
            aaExtension = pathMatch[1];
            const urlDomain = pathMatch[2];
            dialplan = `${urlDomain}_${aaExtension}`;
            log('Found AA info in URL path:', { aaExtension, urlDomain, dialplan });
        }
        
        // Method 2: Try matching from hash (SPA routing)
        if (!dialplan && window.location.hash) {
            const hashMatch = window.location.hash.match(/\/edit\/(\d+)@([^\/]+)/);
            log('Hash match result:', hashMatch);
            if (hashMatch) {
                aaExtension = hashMatch[1];
                const urlDomain = hashMatch[2];
                dialplan = `${urlDomain}_${aaExtension}`;
                log('Found AA info in URL hash:', { aaExtension, urlDomain, dialplan });
            }
        }
        
        // Method 3: Look for extension@domain pattern in any link on the page
        if (!dialplan) {
            const links = document.querySelectorAll('a[href*="@"]');
            for (const link of links) {
                const linkMatch = link.href.match(/\/(\d+)@([^\/]+)/);
                if (linkMatch) {
                    aaExtension = linkMatch[1];
                    const urlDomain = linkMatch[2];
                    dialplan = `${urlDomain}_${aaExtension}`;
                    log('Found AA info in page link:', { aaExtension, urlDomain, dialplan, href: link.href });
                    break;
                }
            }
        }
        
        // Method 4: Look for hidden input with extension info
        if (!dialplan) {
            // Check for common hidden fields that might contain extension
            const extField = document.querySelector('input[name="extension"], input[name="user"], input[name="aa_extension"], input[id*="extension"]');
            if (extField && extField.value && domain) {
                aaExtension = extField.value.split('@')[0]; // Handle "ext@domain" format
                dialplan = `${domain}_${aaExtension}`;
                log('Found extension in hidden field:', { aaExtension, dialplan });
            }
        }
        
        // Method 5: Look in breadcrumb or page title
        if (!dialplan) {
            const breadcrumb = document.querySelector('.breadcrumb, .page-title, h1, h2');
            if (breadcrumb) {
                // Look for pattern like "9001" or "Edit 9001" 
                const bcMatch = breadcrumb.textContent.match(/\b(\d{2,6})\b/);
                if (bcMatch && domain) {
                    aaExtension = bcMatch[1];
                    dialplan = `${domain}_${aaExtension}`;
                    log('Found extension in breadcrumb/title:', { aaExtension, dialplan, text: breadcrumb.textContent });
                }
            }
        }
        
        // Method 6: Derive from promptId - look for pattern in prompt IDs
        // Format appears to be: {extension}{tier} where tier is 01, 02, etc. or 001, 002, etc.
        if (!dialplan && promptId && domain) {
            // Try removing last 2 digits first (common pattern: 900101 -> 9001)
            let possibleExt = promptId.slice(0, -2);
            // Verify it makes sense (at least 2 digits)
            if (possibleExt.length >= 2) {
                aaExtension = possibleExt;
                dialplan = `${domain}_${aaExtension}`;
                log('Derived extension from promptId (remove last 2):', { promptId, aaExtension, dialplan });
            }
        }
        
        // Method 7: Check URL parameters as fallback
        if (!dialplan) {
            const urlParams = new URLSearchParams(window.location.search);
            const aaParam = urlParams.get('auto_attendant') || urlParams.get('aa') || urlParams.get('extension');
            if (aaParam && domain) {
                aaExtension = aaParam;
                dialplan = `${domain}_${aaParam}`;
                log('Found AA info in URL params:', { aaExtension, domain, dialplan });
            }
        }
        
        log('AA Info:', { domain, promptId, dialplan, aaExtension });
        return { domain, promptId, dialplan, aaExtension };
    }
    
    function getDestinationForButton(promptId, tier, digit) {
        // Destination format: Prompt_{promptId}.Case_{digit}
        // For tier 1 digit 7: Prompt_400001.Case_7
        const digitValue = digit === 'star' ? '*' : digit;
        return `Prompt_${promptId}.Case_${digitValue}`;
    }
    
    function getDialRuleMatchingUri(promptId, tier, digit) {
        // Matching URI format: typically just the destination
        const dest = getDestinationForButton(promptId, tier, digit);
        return dest;
    }
    
    async function createDialRule(config) {
        const token = localStorage.getItem('ns_t');
        if (!token) {
            log('No auth token found');
            return { success: false, error: 'No auth token' };
        }
        
        const { domain, dialplan } = getAAInfo();
        if (!domain || !dialplan) {
            log('Missing domain or dialplan');
            return { success: false, error: 'Missing domain or dialplan' };
        }
        
        const url = `/ns-api/v2/domains/${encodeURIComponent(domain)}/dialplans/${encodeURIComponent(dialplan)}/dialrules`;
        
        log('Creating dial rule:', url, config);
        
        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${token}`
                },
                body: JSON.stringify(config)
            });
            
            const data = await response.json();
            log('Create dial rule response:', response.status, data);
            
            if (response.ok || response.status === 202) {
                return { success: true, data };
            } else {
                return { success: false, error: data.message || 'API error', data };
            }
        } catch (e) {
            log('Create dial rule error:', e);
            return { success: false, error: e.message };
        }
    }
    
    async function updateDialRule(dialruleId, config) {
        const token = localStorage.getItem('ns_t');
        if (!token) {
            log('No auth token found');
            return { success: false, error: 'No auth token' };
        }
        
        const { domain, dialplan } = getAAInfo();
        if (!domain || !dialplan) {
            log('Missing domain or dialplan');
            return { success: false, error: 'Missing domain or dialplan' };
        }
        
        const url = `/ns-api/v2/domains/${encodeURIComponent(domain)}/dialplans/${encodeURIComponent(dialplan)}/dialrules/${encodeURIComponent(dialruleId)}`;
        
        log('Updating dial rule:', url, config);
        
        try {
            const response = await fetch(url, {
                method: 'PUT',
                credentials: 'include',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${token}`
                },
                body: JSON.stringify(config)
            });
            
            const data = await response.json();
            log('Update dial rule response:', response.status, data);
            
            if (response.ok || response.status === 202) {
                return { success: true, data };
            } else {
                return { success: false, error: data.message || 'API error', data };
            }
        } catch (e) {
            log('Update dial rule error:', e);
            return { success: false, error: e.message };
        }
    }
    
    async function deleteDialRule(dialruleId) {
        const token = localStorage.getItem('ns_t');
        if (!token) {
            log('No auth token found');
            return { success: false, error: 'No auth token' };
        }
        
        const { domain, dialplan } = getAAInfo();
        if (!domain || !dialplan) {
            log('Missing domain or dialplan');
            return { success: false, error: 'Missing domain or dialplan' };
        }
        
        const url = `/ns-api/v2/domains/${encodeURIComponent(domain)}/dialplans/${encodeURIComponent(dialplan)}/dialrules/${encodeURIComponent(dialruleId)}`;
        
        log('Deleting dial rule:', url);
        
        try {
            const response = await fetch(url, {
                method: 'DELETE',
                credentials: 'include',
                headers: {
                    'Accept': 'application/json',
                    'Authorization': `Bearer ${token}`
                }
            });
            
            log('Delete dial rule response:', response.status);
            
            if (response.ok || response.status === 202) {
                return { success: true };
            } else {
                const data = await response.json();
                return { success: false, error: data.message || 'API error', data };
            }
        } catch (e) {
            log('Delete dial rule error:', e);
            return { success: false, error: e.message };
        }
    }
    
    async function fetchExistingDialRules() {
        const token = localStorage.getItem('ns_t');
        if (!token) {
            log('No auth token found');
            return [];
        }
        
        const { domain, dialplan } = getAAInfo();
        if (!domain || !dialplan) {
            log('Missing domain or dialplan');
            return [];
        }
        
        const url = `/ns-api/v2/domains/${encodeURIComponent(domain)}/dialplans/${encodeURIComponent(dialplan)}/dialrules`;
        
        log('Fetching dial rules:', url);
        
        try {
            const response = await fetch(url, {
                method: 'GET',
                credentials: 'include',
                headers: {
                    'Accept': 'application/json',
                    'Authorization': `Bearer ${token}`
                }
            });
            
            if (response.ok) {
                const data = await response.json();
                log('Fetched dial rules:', data);
                return Array.isArray(data) ? data : [];
            } else {
                log('Fetch dial rules error:', response.status);
                return [];
            }
        } catch (e) {
            log('Fetch dial rules error:', e);
            return [];
        }
    }
    
    function buildDialRuleConfig(tier, digit, appParam, description) {
        const { domain, promptId } = getAAInfo();
        const destination = getDestinationForButton(promptId, tier, digit);
        
        return {
            'domain': domain,
            'dial-rule-matching-to-uri': destination,
            'dial-rule-matching-from-uri': '*',
            'dial-rule-matching-day-of-week': '*',
            'dial-rule-matching-start-date': '*',
            'dial-rule-matching-end-date': '*',
            'dial-rule-matching-start-time': '*',
            'dial-rule-matching-end-time': '*',
            'enabled': 'yes',
            'dial-rule-application': 'sip:0@Web-Main',
            'dial-rule-parameter': appParam,
            'dial-rule-translation-destination-scheme': '[*]',
            'dial-rule-translation-destination-user': '[*]',
            'dial-rule-translation-destination-host': domain,
            'dial-rule-translation-source-name': '[*]',
            'dial-rule-translation-source-scheme': 'sip:',
            'dial-rule-translation-source-user': '[*]',
            'dial-rule-translation-source-host': '[*]',
            'dial-rule-description': description || 'AA Extension: Web Responder'
        };
    }

    // ============================================
    // STYLES
    // ============================================
    const STYLES = `
        /* Custom App Icons - matching portal style */
        /* Large icons for the application selection menu */
        .app-icon-lg.icon-webresponder,
        .app-icon-lg.icon-customdir {
            width: 32px;
            height: 32px;
            display: block;
            margin: 0 auto;
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center;
        }
        
        /* Small icons for dial pad buttons - ONLY set background, inherit all other styles from .app-icon */
        .app-icon.icon-webresponder,
        .app-icon.icon-customdir {
            background-size: 18px 18px;
            background-repeat: no-repeat;
            background-position: center 1px;
        }

        /* Web Responder icon - globe/network style */
        .app-icon-lg.icon-webresponder,
        .app-icon.icon-webresponder {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23555'%3E%3Cpath d='M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z'/%3E%3C/svg%3E");
        }

        /* Custom Directory icon - address book style */
        .app-icon-lg.icon-customdir,
        .app-icon.icon-customdir {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23555'%3E%3Cpath d='M18 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM9 4h2v5l-1-.75L9 9V4zm9 16H6V4h1v9l3-2.25L13 13V4h5v16z'/%3E%3Cpath d='M12 14c-1.66 0-3 1.34-3 3h6c0-1.66-1.34-3-3-3zm0-2c.83 0 1.5-.67 1.5-1.5S12.83 9 12 9s-1.5.67-1.5 1.5.67 1.5 1.5 1.5z'/%3E%3C/svg%3E");
        }

        /* Preview box */
        .aa-ext-preview {
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 10px;
            font-family: monospace;
            font-size: 11px;
            white-space: pre-wrap;
            word-break: break-all;
            margin-top: 8px;
        }

        /* Inline form styling */
        .aa-ext-inline-form {
            padding: 10px 0;
            min-width: 450px;
        }
        
        .aa-ext-inline-form > div {
            flex-shrink: 0;
        }
        
        .aa-ext-inline-form label {
            font-weight: 600;
        }
        
        .aa-ext-inline-form input[type="text"],
        .aa-ext-inline-form input[type="url"],
        .aa-ext-inline-form input[type="number"] {
            padding: 6px 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 13px;
        }
        
        .aa-ext-inline-form input[type="text"]:focus,
        .aa-ext-inline-form input[type="url"]:focus,
        .aa-ext-inline-form input[type="number"]:focus {
            border-color: #0066cc;
            outline: none;
            box-shadow: 0 0 0 2px rgba(0,102,204,0.1);
        }

        /* Application details panel for our custom apps */
        .application-webresponder .pull-right,
        .application-customdir .pull-right {
            display: block !important;
            min-width: 450px;
        }
        .application-webresponder .pull-left,
        .application-customdir .pull-left {
            display: block !important;
        }
    `;

    function injectStyles() {
        if (document.getElementById('aa-ext-styles')) return;
        const styleEl = document.createElement('style');
        styleEl.id = 'aa-ext-styles';
        styleEl.textContent = STYLES;
        document.head.appendChild(styleEl);
        log('Styles injected');
    }

    // ============================================
    // FETCH SITES AND DEPARTMENTS FROM API
    // ============================================
    async function fetchSites() {
        try {
            const domain = getDomainFromPage();
            if (!domain) {
                log('No domain found, skipping sites fetch');
                return [];
            }
            
            const token = localStorage.getItem('ns_t');
            if (!token) {
                log('No auth token found, skipping sites fetch');
                return [];
            }
            
            // Use v2 API with Bearer token
            const url = `/ns-api/v2/domains/${encodeURIComponent(domain)}/sites/list`;
            log('Fetching sites from:', url);
            
            const response = await fetch(url, {
                credentials: 'include',
                headers: {
                    'Accept': 'application/json',
                    'Authorization': `Bearer ${token}`
                }
            });
            
            if (response.ok) {
                const data = await response.json();
                log('Sites API response:', data);
                
                if (Array.isArray(data)) {
                    // Handle array of strings
                    if (data.length > 0 && typeof data[0] === 'string') {
                        return data.filter(s => s.length > 0);
                    }
                    // Handle array of objects - extract site name
                    if (data.length > 0 && typeof data[0] === 'object') {
                        const sites = data.map(s => s.site || s.name || s['site-name'] || s.Site).filter(Boolean);
                        log('Parsed sites from objects:', sites);
                        return sites;
                    }
                }
            } else {
                log('Sites API error:', response.status, response.statusText);
            }
        } catch (e) {
            log('Failed to fetch sites:', e);
        }
        return [];
    }

    async function fetchDepartments() {
        try {
            const domain = getDomainFromPage();
            if (!domain) {
                log('No domain found, skipping departments fetch');
                return [];
            }
            
            const token = localStorage.getItem('ns_t');
            if (!token) {
                log('No auth token found, skipping departments fetch');
                return [];
            }
            
            // Use v2 API with Bearer token
            const url = `/ns-api/v2/domains/${encodeURIComponent(domain)}/departments/list`;
            log('Fetching departments from:', url);
            
            const response = await fetch(url, {
                credentials: 'include',
                headers: {
                    'Accept': 'application/json',
                    'Authorization': `Bearer ${token}`
                }
            });
            
            if (response.ok) {
                const data = await response.json();
                log('Departments API response:', data);
                
                if (Array.isArray(data)) {
                    // Handle array of strings
                    if (data.length > 0 && typeof data[0] === 'string') {
                        return data.filter(d => d.length > 0);
                    }
                    // Handle array of objects - extract department name
                    if (data.length > 0 && typeof data[0] === 'object') {
                        const depts = data.map(d => d.department || d.name || d['department-name'] || d.Department).filter(Boolean);
                        log('Parsed departments from objects:', depts);
                        return depts;
                    }
                }
            } else {
                log('Departments API error:', response.status, response.statusText);
            }
        } catch (e) {
            log('Failed to fetch departments:', e);
        }
        return [];
    }

    function getDomainFromPage() {
        // Try to extract domain from the page URL query params
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('domain')) {
            log('Found domain in URL params:', urlParams.get('domain'));
            return urlParams.get('domain');
        }
        
        // Try to find domain in hidden form fields
        const domainField = document.querySelector('input[name="domain"]') || 
                           document.querySelector('input[id="domain"]') ||
                           document.querySelector('input[id*="_domain"]') ||
                           document.querySelector('[data-domain]');
        if (domainField) {
            const val = domainField.value || domainField.dataset.domain;
            if (val) {
                log('Found domain in form field:', val);
                return val;
            }
        }
        
        // Try to extract from URL path
        const pathMatch = window.location.pathname.match(/domain[\/=]([^\/&]+)/i);
        if (pathMatch) {
            log('Found domain in URL path:', pathMatch[1]);
            return pathMatch[1];
        }
        
        // Look for domain in breadcrumbs or page elements
        const breadcrumb = document.querySelector('.breadcrumb a[href*="domain="]');
        if (breadcrumb) {
            const match = breadcrumb.href.match(/domain=([^&]+)/);
            if (match) {
                log('Found domain in breadcrumb:', decodeURIComponent(match[1]));
                return decodeURIComponent(match[1]);
            }
        }
        
        // Try to get domain from any link on the page that contains domain=
        const domainLink = document.querySelector('a[href*="domain="]');
        if (domainLink) {
            const match = domainLink.href.match(/domain=([^&]+)/);
            if (match) {
                log('Found domain in page link:', decodeURIComponent(match[1]));
                return decodeURIComponent(match[1]);
            }
        }
        
        // Try to get from window/global variables that NetSapiens might set
        if (typeof window !== 'undefined') {
            if (window.current_domain && typeof window.current_domain === 'string' && !window.current_domain.includes('\n')) {
                log('Found domain in window.current_domain:', window.current_domain);
                return window.current_domain;
            }
            if (window.domain && typeof window.domain === 'string' && !window.domain.includes('\n')) {
                log('Found domain in window.domain:', window.domain);
                return window.domain;
            }
            if (window.NsPortal && window.NsPortal.domain) {
                log('Found domain in NsPortal.domain:', window.NsPortal.domain);
                return window.NsPortal.domain;
            }
            if (window.ns && window.ns.domain) {
                log('Found domain in ns.domain:', window.ns.domain);
                return window.ns.domain;
            }
        }
        
        // Try localStorage
        const storedDomain = localStorage.getItem('ns_domain') || localStorage.getItem('domain');
        if (storedDomain) {
            log('Found domain in localStorage:', storedDomain);
            return storedDomain;
        }
        
        // Try to extract domain from page text pattern like "(domain.name) domain"
        const pageText = document.body?.innerText || '';
        const domainPattern = pageText.match(/\(([a-zA-Z0-9._-]+\.[a-zA-Z0-9._-]+)\)\s*domain/i);
        if (domainPattern) {
            log('Found domain in page text pattern:', domainPattern[1]);
            return domainPattern[1];
        }
        
        log('No domain found on page');
        return null;
    }

    // ============================================
    // HELPER FUNCTIONS
    // ============================================
    
    function buildDirectoryUrl(baseUrl, opts) {
        const params = new URLSearchParams();
        
        if (opts.nameMode) {
            params.set('mode', opts.nameMode);
        }
        if (opts.selectedSites && opts.selectedSites.length) {
            params.set('site', opts.selectedSites.join(','));
        }
        if (opts.selectedDepts && opts.selectedDepts.length) {
            params.set('department', opts.selectedDepts.join(','));
        }
        if (opts.operator) params.set('operator', opts.operator);
        if (opts.timeout) params.set('timeout', opts.timeout);
        if (opts.maxDigits) params.set('maxdigits', opts.maxDigits);
        if (opts.language) params.set('language', opts.language);
        if (opts.voice) params.set('voice', opts.voice);

        const qs = params.toString();
        return qs ? `${baseUrl}?${qs}` : baseUrl;
    }

    // Apply config to hidden form fields
    // Store pending configs that need to be saved via API
    const pendingConfigs = new Map();
    
    function applyConfigToFields(btnId, config) {
        // Extract tier and digit from btnId (e.g., "_1_7" -> tier=1, digit=7)
        const match = btnId.match(/_(\d+)_(\d+|star)/);
        if (!match) {
            log('Could not parse btnId:', btnId);
            return;
        }
        const tier = match[1];
        const digit = match[2];
        
        // Store config for API save
        pendingConfigs.set(btnId, {
            tier,
            digit,
            type: config.type,
            dialTranslation: config.dialTranslation,
            title: config.title || (config.type === 'customdir' ? 'Custom Directory' : 'Web Responder'),
            url: config.url
        });
        
        // Also update form fields for visual feedback and fallback
        const respField = document.getElementById(`button_resp${btnId}`);
        const destField = document.getElementById(`button_dest${btnId}`);
        const titleField = document.getElementById(`button_title${btnId}`);
        const changedField = document.getElementById(`button_changed${btnId}`);

        if (respField) respField.value = 'sip:0@Web-Main';
        if (destField) destField.value = config.dialTranslation;
        if (titleField) titleField.value = config.title || '';
        if (changedField) changedField.value = '1';

        log('Applied config to fields:', btnId, config);
    }
    
    async function saveWebResponderConfig(btnId) {
        const config = pendingConfigs.get(btnId);
        if (!config) {
            log('No pending config for:', btnId);
            return { success: false, error: 'No config found' };
        }
        
        const { tier, digit, dialTranslation, title } = config;
        
        // Build the dial rule config
        const dialRuleConfig = buildDialRuleConfig(tier, digit, dialTranslation, title);
        
        log('Saving web responder config via API:', dialRuleConfig);
        
        // Check if there's an existing dial rule for this button
        const existingRules = await fetchExistingDialRules();
        const { promptId } = getAAInfo();
        const destination = getDestinationForButton(promptId, tier, digit);
        
        // Find existing rule matching this destination
        const existingRule = existingRules.find(rule => 
            rule['dial-rule-matching-to-uri'] === destination ||
            rule['dial-rule-matching-to-uri']?.includes(destination)
        );
        
        let result;
        if (existingRule && existingRule.dialrule) {
            log('Updating existing dial rule:', existingRule.dialrule);
            result = await updateDialRule(existingRule.dialrule, dialRuleConfig);
        } else {
            log('Creating new dial rule');
            result = await createDialRule(dialRuleConfig);
        }
        
        if (result.success) {
            log('Successfully saved web responder config');
            pendingConfigs.delete(btnId);
        } else {
            log('Failed to save web responder config:', result.error);
        }
        
        return result;
    }
    
    async function saveAllPendingConfigs() {
        const results = [];
        for (const [btnId, config] of pendingConfigs) {
            const result = await saveWebResponderConfig(btnId);
            results.push({ btnId, ...result });
        }
        return results;
    }
    
    function interceptSaveButton() {
        // Find the AA Designer save button specifically
        const saveBtn = document.getElementById('aa_submit');
        
        if (!saveBtn) {
            log('AA save button not found, will retry...');
            setTimeout(interceptSaveButton, 1000);
            return;
        }
        
        if (saveBtn.dataset.aaExtIntercepted) {
            log('Save button already intercepted');
            return;
        }
        
        log('Found AA save button, intercepting...');
        
        saveBtn.addEventListener('click', async (e) => {
            // If we have pending configs, save them via API first
            if (pendingConfigs.size > 0) {
                log('Intercepted save, saving', pendingConfigs.size, 'pending configs via API...');
                
                // Prevent the form from submitting immediately
                e.preventDefault();
                e.stopPropagation();
                
                // Show loading indicator
                const originalValue = saveBtn.value;
                saveBtn.value = 'Saving...';
                saveBtn.disabled = true;
                
                try {
                    const results = await saveAllPendingConfigs();
                    const failures = results.filter(r => !r.success);
                    
                    if (failures.length > 0) {
                        log('Some configs failed to save:', failures);
                        alert('Warning: Some web responder configurations may not have saved correctly. Check the console for details.');
                    } else {
                        log('All configs saved successfully via API');
                    }
                } catch (error) {
                    log('Error saving configs:', error);
                }
                
                // Restore button
                saveBtn.value = originalValue;
                saveBtn.disabled = false;
                
                // Now let the form submit normally (for any other AA Designer changes)
                // Find the form and submit it
                const form = saveBtn.closest('form');
                if (form) {
                    log('Submitting form after API save...');
                    form.submit();
                }
            }
        }, true); // Use capture to run before other handlers
        
        saveBtn.dataset.aaExtIntercepted = 'true';
        log('Successfully intercepted AA save button');
    }

    // Setup button click handler for custom config
    function setupButtonForCustomConfig(tier, digit, btnId, configType, panelId) {
        const btn = document.getElementById(`btn${btnId}`);
        if (!btn) return;

        // Store original onclick
        if (!btn.dataset.aaExtOriginalOnclick) {
            btn.dataset.aaExtOriginalOnclick = btn.getAttribute('onclick') || '';
        }
        
        btn.onclick = function(e) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            showPanelForButton(tier, btnId, panelId);
            return false;
        };
        
        btn.dataset.aaExtCustomConfig = configType;
    }

    // Helper: Reset all dial pad button visual states (removes selected class and hides arrows)
    function resetAllButtonStates() {
        // Remove 'open' class from all dial pad li elements and hide their arrows
        document.querySelectorAll('.dialpad ul li').forEach(li => {
            li.classList.remove('open');
            
            // Hide arrows - they're inside the li
            const arrows = li.querySelectorAll('.arrow, .arrow-border');
            arrows.forEach(arrow => {
                arrow.style.display = 'none';
            });
        });
        
        // Remove 'selected' class from all key divs
        document.querySelectorAll('.dialpad .aa-action-button').forEach(btn => {
            const keyDiv = btn.querySelector('[class*="key-"]');
            if (keyDiv) {
                keyDiv.classList.remove('selected');
            }
        });
    }
    
    // Helper: Set button as active (shows arrows, adds open class, and adds selected to key)
    function setButtonActive(btn) {
        if (!btn) return;
        
        const li = btn.closest('li');
        if (li) {
            li.classList.add('open');
        }
        
        // Add 'selected' class to the key div (this gives the "lit" appearance)
        const keyDiv = btn.querySelector('[class*="key-"]');
        if (keyDiv) {
            keyDiv.classList.add('selected');
        }
        
        // Show arrows - look for them in the li, not just the btn
        // (arrows are siblings of the button link, inside the li)
        const container = li || btn;
        const arrows = container.querySelectorAll('.arrow, .arrow-border');
        arrows.forEach(arrow => {
            arrow.style.display = 'block';
        });
        
        log('Set button active:', btn.id, 'keyDiv:', keyDiv?.className, 'arrows found:', arrows.length);
    }

    // Show panel when button is clicked
    function showPanelForButton(tier, btnId, panelId) {
        log('showPanelForButton called:', { tier, btnId, panelId });
        
        // Hide all application panels for this tier
        document.querySelectorAll(`.application-details.level${tier}`).forEach(p => {
            p.classList.add('hide');
            p.style.display = 'none';
        });
        document.querySelectorAll(`[id^="application_select_${tier}_"]`).forEach(p => {
            p.classList.add('hide');
            p.style.display = 'none';
        });
        
        // Reset ALL button visual states (removes open, selected, hides arrows)
        resetAllButtonStates();
        
        // Show our panel
        const panel = document.getElementById(panelId);
        log('Looking for panel:', panelId, 'found:', !!panel);
        if (panel) {
            panel.classList.remove('hide');
            panel.style.display = 'block';
            panel.querySelectorAll('.pull-left, .pull-right').forEach(el => {
                el.classList.remove('hide');
                el.style.display = 'block';
            });
        }
        
        // Find and activate THIS button
        const btnSelector = `btn${btnId}`;
        let btn = document.getElementById(btnSelector);
        
        if (!btn) {
            // Try alternate ID format without underscore prefix
            const altBtnSelector = `btn${btnId.replace(/^_/, '')}`;
            btn = document.getElementById(altBtnSelector);
            log('Trying alternate selector:', altBtnSelector, 'found:', !!btn);
        }
        
        if (btn) {
            setButtonActive(btn);
        }
    }

    // ============================================
    // WEB RESPONDER INLINE FORM
    // ============================================
    function showWebResponderForm(tier, digit, btnId, existingValues = null) {
        log('Showing inline Web Responder form for', btnId, existingValues ? '(editing)' : '(new)');
        
        const selectPanel = document.querySelector(`[id^="application_select${btnId}"]`);
        if (!selectPanel) {
            log('Could not find select panel for', btnId);
            return;
        }

        // Remove any existing custom panels
        const existingPanel = document.getElementById(`application_webresponder${btnId}`) || 
                              document.getElementById(`application_customdir${btnId}`);
        if (existingPanel) existingPanel.remove();

        const digitDisplay = digit === 'star' ? '*' : digit;
        
        // Pre-fill values if editing
        const urlValue = existingValues?.url || '';
        const descValue = existingValues?.description || '';
        const methodValue = existingValues?.method || 'POST';

        // Create inline form panel
        const panel = document.createElement('div');
        panel.id = `application_webresponder${btnId}`;
        panel.className = `application-details application-webresponder form-actions level${tier}`;
        panel.dataset.btnId = btnId;
        panel.innerHTML = `
            <div class="remove-app">
                <span class="close helpsy" title="Remove" data-aa-ext-remove="${btnId}">Remove</span>
            </div>
            <div class="pull-left">
                <div class="app-icon-lg icon-webresponder helpsy" title="Change Application" 
                    data-aa-ext-change="${btnId}"></div>
            </div>
            <div class="pull-right">
                <div class="aa-ext-inline-form">
                    <div style="margin-bottom:12px;">
                        <label style="display:block; margin-bottom:4px;">Web Responder URL <span style="color:red">*</span></label>
                        <input type="text" id="aa-ext-wr-url${btnId}" style="width:400px; box-sizing:border-box;" 
                            placeholder="https://your-server.com/webresponder.php" value="${urlValue}">
                        <div style="font-size:11px; color:#666; margin-top:4px;">The URL of your web responder application</div>
                    </div>
                    <div style="margin-bottom:12px;">
                        <label style="display:block; margin-bottom:4px;">Description</label>
                        <input type="text" id="aa-ext-wr-desc${btnId}" style="width:400px; box-sizing:border-box;" 
                            placeholder="e.g., Custom IVR" value="${descValue}">
                        <div style="font-size:11px; color:#666; margin-top:4px;">A friendly name (shown in button title)</div>
                    </div>
                    <div style="margin-bottom:12px;">
                        <label style="display:block; margin-bottom:4px;">HTTP Method</label>
                        <div>
                            <label style="margin-right:15px; font-weight:normal;">
                                <input type="radio" name="aa-ext-wr-method${btnId}" value="GET" ${methodValue === 'GET' ? 'checked' : ''}> GET
                            </label>
                            <label style="font-weight:normal;">
                                <input type="radio" name="aa-ext-wr-method${btnId}" value="POST" ${methodValue === 'POST' ? 'checked' : ''}> POST
                            </label>
                        </div>
                    </div>
                    <div>
                        <label style="display:block; margin-bottom:4px; font-size:11px; text-transform:uppercase; color:#666;">Generated Dial Translation</label>
                        <div id="aa-ext-wr-preview${btnId}" class="aa-ext-preview">Enter a URL to see the dial translation</div>
                    </div>
                </div>
            </div>
        `;

        selectPanel.after(panel);

        // Add event listeners
        panel.querySelector('[data-aa-ext-remove]').addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            removeCustomConfig(tier, digit, btnId);
        });

        panel.querySelector('[data-aa-ext-change]').addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            changeCustomConfig(tier, digit, btnId);
        });

        const urlInput = panel.querySelector(`#aa-ext-wr-url${btnId}`);
        const descInput = panel.querySelector(`#aa-ext-wr-desc${btnId}`);
        const preview = panel.querySelector(`#aa-ext-wr-preview${btnId}`);
        const methodRadios = panel.querySelectorAll(`input[name="aa-ext-wr-method${btnId}"]`);

        const updatePreview = () => {
            const url = urlInput.value.trim();
            const method = panel.querySelector(`input[name="aa-ext-wr-method${btnId}"]:checked`).value;
            if (url) {
                let trans = url;
                if (method === 'POST') trans = `<HttpMethod=POST>${url}`;
                preview.textContent = trans;
                
                const description = descInput.value.trim() || 'Web Responder';
                applyConfigToFields(btnId, {
                    type: 'webresponder',
                    url: url,
                    dialTranslation: trans,
                    title: description
                });
            } else {
                preview.textContent = 'Enter a URL to see the dial translation';
            }
        };

        urlInput.addEventListener('input', updatePreview);
        descInput.addEventListener('input', updatePreview);
        methodRadios.forEach(r => r.addEventListener('change', updatePreview));

        // Show panel, hide select panel
        panel.classList.remove('hide');
        panel.style.display = 'block';
        panel.querySelectorAll('.pull-left, .pull-right').forEach(el => {
            el.classList.remove('hide');
            el.style.display = 'block';
        });

        selectPanel.classList.add('hide');
        selectPanel.style.display = 'none';

        // Reset all button states then activate this one
        resetAllButtonStates();
        
        // Update button
        const btn = document.getElementById(`btn${btnId}`);
        if (btn) {
            const appIcon = btn.querySelector('.app-icon');
            if (appIcon) appIcon.className = 'app-icon icon-webresponder';
            
            setupButtonForCustomConfig(tier, digit, btnId, 'webresponder', `application_webresponder${btnId}`);
            setButtonActive(btn);
        }

        // Trigger preview update (especially important when editing)
        updatePreview();
        
        urlInput.focus();
    }

    // ============================================
    // CUSTOM DIRECTORY INLINE FORM
    // ============================================
    async function showDirectoryForm(tier, digit, btnId, existingValues = null) {
        log('Showing inline Directory form for', btnId, existingValues ? '(editing)' : '(new)');
        
        const [sites, departments] = await Promise.all([fetchSites(), fetchDepartments()]);
        
        const selectPanel = document.querySelector(`[id^="application_select${btnId}"]`);
        if (!selectPanel) {
            log('Could not find select panel for', btnId);
            return;
        }

        // Remove any existing custom panels
        const existingPanel = document.getElementById(`application_webresponder${btnId}`) || 
                              document.getElementById(`application_customdir${btnId}`);
        if (existingPanel) existingPanel.remove();

        const digitDisplay = digit === 'star' ? '*' : digit;
        
        // Pre-fill values if editing
        const urlValue = existingValues?.baseUrl || CONFIG.directoryBaseUrl;
        const modeValue = existingValues?.mode || 'both';
        const operatorValue = existingValues?.operator || '';
        const timeoutValue = existingValues?.timeout || '5';
        const maxDigitsValue = existingValues?.maxDigits || '4';
        const languageValue = existingValues?.language || '';
        const voiceValue = existingValues?.voice || '';
        const selectedSites = existingValues?.sites || [];
        const selectedDepts = existingValues?.departments || [];

        // Build sites HTML - checkboxes if API returned sites, otherwise text input for manual entry
        const sitesHtml = sites.length > 0 ? `
            <div style="margin-bottom:12px;">
                <label style="display:block; margin-bottom:4px;">Filter by Sites</label>
                <div id="aa-ext-dir-sites${btnId}" style="max-height:80px; overflow-y:auto; border:1px solid #ddd; border-radius:4px; padding:5px; background:#fff;">
                    ${sites.map(site => `
                        <label style="display:block; font-weight:normal; margin:2px 0; cursor:pointer;">
                            <input type="checkbox" value="${site}" ${selectedSites.includes(site) ? 'checked' : ''}> ${site}
                        </label>
                    `).join('')}
                </div>
                <div style="font-size:11px; color:#666; margin-top:4px;">Leave empty to include all sites</div>
            </div>
        ` : `
            <div style="margin-bottom:12px;">
                <label style="display:block; margin-bottom:4px;">Filter by Sites</label>
                <input type="text" id="aa-ext-dir-sites-manual${btnId}" style="width:400px; box-sizing:border-box;" 
                    placeholder="e.g., Site1, Site2, Site3" value="${selectedSites.join(', ')}">
                <div style="font-size:11px; color:#666; margin-top:4px;">Comma-separated list of sites (leave empty for all)</div>
            </div>
        `;

        // Build departments HTML - checkboxes if API returned depts, otherwise text input for manual entry
        const deptsHtml = departments.length > 0 ? `
            <div style="margin-bottom:12px;">
                <label style="display:block; margin-bottom:4px;">Filter by Departments</label>
                <div id="aa-ext-dir-depts${btnId}" style="max-height:80px; overflow-y:auto; border:1px solid #ddd; border-radius:4px; padding:5px; background:#fff;">
                    ${departments.map(dept => `
                        <label style="display:block; font-weight:normal; margin:2px 0; cursor:pointer;">
                            <input type="checkbox" value="${dept}" ${selectedDepts.includes(dept) ? 'checked' : ''}> ${dept}
                        </label>
                    `).join('')}
                </div>
                <div style="font-size:11px; color:#666; margin-top:4px;">Leave empty to include all departments</div>
            </div>
        ` : `
            <div style="margin-bottom:12px;">
                <label style="display:block; margin-bottom:4px;">Filter by Departments</label>
                <input type="text" id="aa-ext-dir-depts-manual${btnId}" style="width:400px; box-sizing:border-box;" 
                    placeholder="e.g., Sales, Support, Engineering" value="${selectedDepts.join(', ')}">
                <div style="font-size:11px; color:#666; margin-top:4px;">Comma-separated list of departments (leave empty for all)</div>
            </div>
        `;

        // Create inline form panel
        const panel = document.createElement('div');
        panel.id = `application_customdir${btnId}`;
        panel.className = `application-details application-customdir form-actions level${tier}`;
        panel.dataset.btnId = btnId;
        panel.innerHTML = `
            <div class="remove-app">
                <span class="close helpsy" title="Remove" data-aa-ext-remove="${btnId}">Remove</span>
            </div>
            <div class="pull-left">
                <div class="app-icon-lg icon-customdir helpsy" title="Change Application" 
                    data-aa-ext-change="${btnId}"></div>
            </div>
            <div class="pull-right">
                <div class="aa-ext-inline-form">
                    <div style="margin-bottom:12px;">
                        <label style="display:block; margin-bottom:4px;">Directory URL <span style="color:red">*</span></label>
                        <input type="text" id="aa-ext-dir-url${btnId}" style="width:400px; box-sizing:border-box;" 
                            value="${urlValue}">
                        <div style="font-size:11px; color:#666; margin-top:4px;">The URL of your directory web responder</div>
                    </div>
                    <div style="margin-bottom:12px;">
                        <label style="display:block; margin-bottom:4px;">Name Matching Mode</label>
                        <div>
                            <label style="margin-right:15px; font-weight:normal;">
                                <input type="radio" name="aa-ext-dir-mode${btnId}" value="firstname" ${modeValue === 'firstname' ? 'checked' : ''}> First Name
                            </label>
                            <label style="margin-right:15px; font-weight:normal;">
                                <input type="radio" name="aa-ext-dir-mode${btnId}" value="lastname" ${modeValue === 'lastname' ? 'checked' : ''}> Last Name
                            </label>
                            <label style="font-weight:normal;">
                                <input type="radio" name="aa-ext-dir-mode${btnId}" value="both" ${modeValue === 'both' ? 'checked' : ''}> First + Last
                            </label>
                        </div>
                    </div>
                    ${sitesHtml}
                    ${deptsHtml}
                    <div style="margin-bottom:12px;">
                        <label style="display:block; margin-bottom:4px;">Operator Extension</label>
                        <input type="text" id="aa-ext-dir-operator${btnId}" style="width:100px;" 
                            placeholder="e.g., 0" value="${operatorValue}">
                        <span style="font-size:11px; color:#666; margin-left:8px;">Transfer when 0 is pressed</span>
                    </div>
                    <div style="margin-bottom:12px;">
                        <div style="display:inline-block; margin-right:20px; vertical-align:top;">
                            <label style="display:block; margin-bottom:4px;">Input Timeout (seconds)</label>
                            <input type="number" id="aa-ext-dir-timeout${btnId}" style="width:80px;" 
                                value="${timeoutValue}" min="1" max="30">
                        </div>
                        <div style="display:inline-block; vertical-align:top;">
                            <label style="display:block; margin-bottom:4px;">Max Digits</label>
                            <input type="number" id="aa-ext-dir-maxdigits${btnId}" style="width:80px;" 
                                value="${maxDigitsValue}" min="1" max="10">
                        </div>
                    </div>
                    <div style="margin-bottom:12px;">
                        <div style="display:inline-block; margin-right:20px; vertical-align:top;">
                            <label style="display:block; margin-bottom:4px;">Language</label>
                            <select id="aa-ext-dir-language${btnId}" style="width:150px;">
                                <option value="" ${languageValue === '' ? 'selected' : ''}>Default</option>
                                <option value="en-US" ${languageValue === 'en-US' ? 'selected' : ''}>English (US)</option>
                                <option value="en-GB" ${languageValue === 'en-GB' ? 'selected' : ''}>English (UK)</option>
                                <option value="en-AU" ${languageValue === 'en-AU' ? 'selected' : ''}>English (AU)</option>
                                <option value="es-ES" ${languageValue === 'es-ES' ? 'selected' : ''}>Spanish (Spain)</option>
                                <option value="es-MX" ${languageValue === 'es-MX' ? 'selected' : ''}>Spanish (Mexico)</option>
                                <option value="fr-FR" ${languageValue === 'fr-FR' ? 'selected' : ''}>French (France)</option>
                                <option value="fr-CA" ${languageValue === 'fr-CA' ? 'selected' : ''}>French (Canada)</option>
                                <option value="de-DE" ${languageValue === 'de-DE' ? 'selected' : ''}>German</option>
                                <option value="it-IT" ${languageValue === 'it-IT' ? 'selected' : ''}>Italian</option>
                                <option value="pt-BR" ${languageValue === 'pt-BR' ? 'selected' : ''}>Portuguese (Brazil)</option>
                                <option value="pt-PT" ${languageValue === 'pt-PT' ? 'selected' : ''}>Portuguese (Portugal)</option>
                                <option value="nl-NL" ${languageValue === 'nl-NL' ? 'selected' : ''}>Dutch</option>
                                <option value="ja-JP" ${languageValue === 'ja-JP' ? 'selected' : ''}>Japanese</option>
                                <option value="zh-CN" ${languageValue === 'zh-CN' ? 'selected' : ''}>Chinese (Mandarin)</option>
                                <option value="ko-KR" ${languageValue === 'ko-KR' ? 'selected' : ''}>Korean</option>
                            </select>
                        </div>
                        <div style="display:inline-block; vertical-align:top;">
                            <label style="display:block; margin-bottom:4px;">Voice</label>
                            <select id="aa-ext-dir-voice${btnId}" style="width:150px;">
                                <option value="" ${voiceValue === '' ? 'selected' : ''}>Default</option>
                                <option value="male" ${voiceValue === 'male' ? 'selected' : ''}>Male</option>
                                <option value="female" ${voiceValue === 'female' ? 'selected' : ''}>Female</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label style="display:block; margin-bottom:4px; font-size:11px; text-transform:uppercase; color:#666;">Generated Dial Translation</label>
                        <div id="aa-ext-dir-preview${btnId}" class="aa-ext-preview">Configure options to see the dial translation</div>
                    </div>
                </div>
            </div>
        `;

        selectPanel.after(panel);

        // Add event listeners
        panel.querySelector('[data-aa-ext-remove]').addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            removeCustomConfig(tier, digit, btnId);
        });

        panel.querySelector('[data-aa-ext-change]').addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            changeCustomConfig(tier, digit, btnId);
        });

        const urlInput = panel.querySelector(`#aa-ext-dir-url${btnId}`);
        const operatorInput = panel.querySelector(`#aa-ext-dir-operator${btnId}`);
        const timeoutInput = panel.querySelector(`#aa-ext-dir-timeout${btnId}`);
        const maxDigitsInput = panel.querySelector(`#aa-ext-dir-maxdigits${btnId}`);
        const languageSelect = panel.querySelector(`#aa-ext-dir-language${btnId}`);
        const voiceSelect = panel.querySelector(`#aa-ext-dir-voice${btnId}`);
        const preview = panel.querySelector(`#aa-ext-dir-preview${btnId}`);
        const modeRadios = panel.querySelectorAll(`input[name="aa-ext-dir-mode${btnId}"]`);
        
        // Sites - either checkbox container or manual text input
        const sitesContainer = panel.querySelector(`#aa-ext-dir-sites${btnId}`);
        const sitesManualInput = panel.querySelector(`#aa-ext-dir-sites-manual${btnId}`);
        
        // Departments - either checkbox container or manual text input
        const deptsContainer = panel.querySelector(`#aa-ext-dir-depts${btnId}`);
        const deptsManualInput = panel.querySelector(`#aa-ext-dir-depts-manual${btnId}`);

        const updatePreview = () => {
            const url = urlInput.value.trim();
            const nameMode = panel.querySelector(`input[name="aa-ext-dir-mode${btnId}"]:checked`)?.value || 'lastname';
            const operator = operatorInput.value.trim();
            const timeout = timeoutInput.value;
            const maxDigits = maxDigitsInput.value;
            const language = languageSelect.value;
            const voice = voiceSelect.value;
            
            // Get sites - from checkboxes or manual input
            let selectedSites = [];
            if (sitesContainer) {
                selectedSites = Array.from(sitesContainer.querySelectorAll('input:checked')).map(cb => cb.value);
            } else if (sitesManualInput && sitesManualInput.value.trim()) {
                selectedSites = sitesManualInput.value.split(',').map(s => s.trim()).filter(Boolean);
            }
            
            // Get departments - from checkboxes or manual input
            let selectedDepts = [];
            if (deptsContainer) {
                selectedDepts = Array.from(deptsContainer.querySelectorAll('input:checked')).map(cb => cb.value);
            } else if (deptsManualInput && deptsManualInput.value.trim()) {
                selectedDepts = deptsManualInput.value.split(',').map(d => d.trim()).filter(Boolean);
            }

            if (url) {
                const fullUrl = buildDirectoryUrl(url, {
                    nameMode, selectedSites, selectedDepts, operator, timeout, maxDigits, language, voice
                });
                const dialTranslation = `<HttpMethod=POST>${fullUrl}`;
                preview.textContent = dialTranslation;
                
                applyConfigToFields(btnId, {
                    type: 'customdir',
                    url: fullUrl,
                    dialTranslation: dialTranslation,
                    title: 'Custom Directory'
                });
            } else {
                preview.textContent = 'Enter a URL to see the dial translation';
            }
        };

        urlInput.addEventListener('input', updatePreview);
        operatorInput.addEventListener('input', updatePreview);
        timeoutInput.addEventListener('input', updatePreview);
        maxDigitsInput.addEventListener('input', updatePreview);
        languageSelect.addEventListener('change', updatePreview);
        voiceSelect.addEventListener('change', updatePreview);
        modeRadios.forEach(r => r.addEventListener('change', updatePreview));
        
        // Add listeners for sites - checkbox or manual input
        if (sitesContainer) {
            sitesContainer.querySelectorAll('input').forEach(cb => cb.addEventListener('change', updatePreview));
        }
        if (sitesManualInput) {
            sitesManualInput.addEventListener('input', updatePreview);
        }
        
        // Add listeners for departments - checkbox or manual input
        if (deptsContainer) {
            deptsContainer.querySelectorAll('input').forEach(cb => cb.addEventListener('change', updatePreview));
        }
        if (deptsManualInput) {
            deptsManualInput.addEventListener('input', updatePreview);
        }

        // Show panel, hide select panel
        panel.classList.remove('hide');
        panel.style.display = 'block';
        panel.querySelectorAll('.pull-left, .pull-right').forEach(el => {
            el.classList.remove('hide');
            el.style.display = 'block';
        });

        selectPanel.classList.add('hide');
        selectPanel.style.display = 'none';

        // Reset all button states then activate this one
        resetAllButtonStates();
        
        // Update button
        const btn = document.getElementById(`btn${btnId}`);
        if (btn) {
            const appIcon = btn.querySelector('.app-icon');
            if (appIcon) appIcon.className = 'app-icon icon-customdir';
            
            setupButtonForCustomConfig(tier, digit, btnId, 'customdir', `application_customdir${btnId}`);
            setButtonActive(btn);
        }

        // Trigger initial preview
        updatePreview();
    }

    // ============================================
    // REMOVE / CHANGE CUSTOM CONFIG
    // ============================================
    function removeCustomConfig(tier, digit, btnId) {
        // Clear hidden fields
        const respField = document.getElementById(`button_resp${btnId}`);
        const destField = document.getElementById(`button_dest${btnId}`);
        const titleField = document.getElementById(`button_title${btnId}`);
        const changedField = document.getElementById(`button_changed${btnId}`);

        if (respField) respField.value = '';
        if (destField) destField.value = '';
        if (titleField) titleField.value = '';
        if (changedField) changedField.value = '1';

        // Remove our custom panel
        const customPanel = document.getElementById(`application_webresponder${btnId}`) || 
                           document.getElementById(`application_customdir${btnId}`);
        if (customPanel) customPanel.remove();

        // Reset button
        const btn = document.getElementById(`btn${btnId}`);
        if (btn) {
            const appIcon = btn.querySelector('.app-icon');
            if (appIcon) appIcon.className = 'app-icon';
            
            // Remove open state from parent li and hide arrows
            const li = btn.closest('li');
            if (li) {
                li.classList.remove('open');
                
                // Hide arrows - they're in the li
                const arrows = li.querySelectorAll('.arrow, .arrow-border');
                arrows.forEach(arrow => {
                    arrow.style.display = 'none';
                });
            }
            
            if (btn.dataset.aaExtOriginalOnclick) {
                btn.setAttribute('onclick', btn.dataset.aaExtOriginalOnclick);
                btn.onclick = null;
                delete btn.dataset.aaExtOriginalOnclick;
            }
            delete btn.dataset.aaExtCustomConfig;
        }

        // Show the select panel
        const selectPanel = document.querySelector(`[id^="application_select${btnId}"]`);
        if (selectPanel) {
            selectPanel.classList.remove('hide');
            selectPanel.style.display = 'block';
            selectPanel.querySelectorAll('.pull-left, .pull-right, .application-text, .application-choices').forEach(el => {
                el.classList.remove('hide');
                el.style.display = 'block';
            });
        }
    }

    function changeCustomConfig(tier, digit, btnId) {
        // Hide our custom panel
        const customPanel = document.getElementById(`application_webresponder${btnId}`) || 
                           document.getElementById(`application_customdir${btnId}`);
        if (customPanel) {
            customPanel.classList.add('hide');
            customPanel.style.display = 'none';
        }

        // Show the select panel
        const selectPanel = document.querySelector(`[id^="application_select${btnId}"]`);
        if (selectPanel) {
            selectPanel.classList.remove('hide');
            selectPanel.style.display = 'block';
            selectPanel.querySelectorAll('.pull-left, .pull-right, .application-text, .application-choices').forEach(el => {
                el.classList.remove('hide');
                el.style.display = 'block';
            });
        }
    }

    function editExistingConfig(tier, digit, btnId, type, dialTranslation) {
        log('Editing existing config:', { tier, digit, btnId, type, dialTranslation });
        
        if (type === 'webresponder') {
            // Parse existing web responder config
            let method = 'GET';
            let actualUrl = dialTranslation;
            
            if (dialTranslation.startsWith('<HttpMethod=POST>')) {
                method = 'POST';
                actualUrl = dialTranslation.replace('<HttpMethod=POST>', '');
            }
            
            // Get description from title field if available
            const titleField = document.getElementById(`button_title${btnId}`);
            const description = titleField ? titleField.value : '';
            
            showWebResponderForm(tier, digit, btnId, {
                url: actualUrl,
                method: method,
                description: description
            });
        } else if (type === 'customdir') {
            // Parse existing directory config
            let actualUrl = dialTranslation;
            if (dialTranslation.startsWith('<HttpMethod=POST>')) {
                actualUrl = dialTranslation.replace('<HttpMethod=POST>', '');
            }
            
            // Parse URL parameters
            let existingValues = {
                baseUrl: CONFIG.directoryBaseUrl,
                mode: 'lastname',
                operator: '',
                timeout: '5',
                maxDigits: '4',
                language: '',
                voice: '',
                sites: [],
                departments: []
            };
            
            try {
                const urlObj = new URL(actualUrl);
                existingValues.baseUrl = urlObj.origin + urlObj.pathname;
                
                if (urlObj.searchParams.get('mode')) {
                    existingValues.mode = urlObj.searchParams.get('mode');
                }
                if (urlObj.searchParams.get('operator')) {
                    existingValues.operator = urlObj.searchParams.get('operator');
                }
                if (urlObj.searchParams.get('timeout')) {
                    existingValues.timeout = urlObj.searchParams.get('timeout');
                }
                if (urlObj.searchParams.get('maxdigits')) {
                    existingValues.maxDigits = urlObj.searchParams.get('maxdigits');
                }
                if (urlObj.searchParams.get('language')) {
                    existingValues.language = urlObj.searchParams.get('language');
                }
                if (urlObj.searchParams.get('voice')) {
                    existingValues.voice = urlObj.searchParams.get('voice');
                }
                if (urlObj.searchParams.get('site')) {
                    existingValues.sites = urlObj.searchParams.get('site').split(',');
                }
                if (urlObj.searchParams.get('department')) {
                    existingValues.departments = urlObj.searchParams.get('department').split(',');
                }
            } catch (e) {
                log('Error parsing directory URL:', e);
            }
            
            showDirectoryForm(tier, digit, btnId, existingValues);
        }
    }

    // ============================================
    // LOAD EXISTING CONFIGURATIONS
    // ============================================
    async function loadExistingConfigurations() {
        log('Scanning for existing web responder configurations...');
        
        // First, scan form fields for any existing configs
        loadExistingFromFormFields();
        
        // Then, fetch from API to find To-Web dial rules
        await loadExistingFromAPI();
    }
    
    function loadExistingFromFormFields() {
        const destFields = document.querySelectorAll('input[id^="button_dest_"]');
        log(`Found ${destFields.length} button_dest fields to scan`);
        
        destFields.forEach(destField => {
            const value = destField.value;
            if (!value) return;
            
            const match = destField.id.match(/button_dest(_\d+_\d+|_\d+_star)/);
            if (!match) return;
            
            const btnId = match[1];
            
            // Check if the application is "To-Web" or "sip:0@Web-Main"
            const respField = document.getElementById(`button_resp${btnId}`);
            const isToWeb = respField && (respField.value === 'To-Web' || respField.value === 'To Web' || respField.value === 'sip:0@Web-Main');
            
            // Check if value contains a URL (either direct URL or with HttpMethod token)
            const isWebResponder = value.includes('http://') || value.includes('https://');
            
            if (!isToWeb && !isWebResponder) return;
            
            // If it's To-Web but doesn't have http in dest, it might be configured via API
            if (isToWeb && !isWebResponder) {
                log(`Button ${btnId} is To-Web but dest doesn't contain URL (may be API-configured):`, value);
                return;
            }

            const tierDigitMatch = btnId.match(/_(\d+)_(\d+|star)/);
            if (!tierDigitMatch) return;
            
            const tier = tierDigitMatch[1];
            const digit = tierDigitMatch[2];

            log(`Found existing config in form for button ${btnId}:`, value);

            // Store the full dial translation value
            const dialTranslation = value;
            
            // Extract URL for display
            let url = value;
            if (value.startsWith('<HttpMethod=POST>')) {
                url = value.replace('<HttpMethod=POST>', '');
            }

            const isDirectory = url.includes('directory') || url.includes('mode=');
            const type = isDirectory ? 'customdir' : 'webresponder';

            // Update button icon
            const btn = document.getElementById(`btn${btnId}`);
            log(`Looking for button btn${btnId}:`, btn ? 'found' : 'NOT FOUND');
            if (btn) {
                const appIcon = btn.querySelector('.app-icon');
                log(`Looking for .app-icon in btn${btnId}:`, appIcon ? 'found' : 'NOT FOUND');
                if (appIcon) {
                    appIcon.className = 'app-icon ' + (type === 'customdir' ? 'icon-customdir' : 'icon-webresponder');
                    log(`Updated icon class for btn${btnId} to:`, appIcon.className);
                }
            }

            // Create detail panel (hidden initially)
            showExistingConfigPanel(tier, digit, btnId, type, url, dialTranslation);
        });
    }
    
    async function loadExistingFromAPI() {
        try {
            const { promptId } = getAAInfo();
            if (!promptId) {
                log('No promptId found, skipping API load');
                return;
            }
            
            const dialRules = await fetchExistingDialRules();
            log(`Found ${dialRules.length} dial rules from API`);
            
            // Look for To-Web rules matching our pattern: Prompt_XXXXX.Case_Y
            const casePattern = new RegExp(`^Prompt_${promptId}\\.Case_(\\d+|\\*)$`);
            
            dialRules.forEach(rule => {
                const matchingUri = rule['dial-rule-matching-to-uri'];
                const application = rule['dial-rule-application'];
                const parameter = rule['dial-rule-parameter'];
                
                // Check if it's a To-Web rule with a URL parameter
                if (!application || (application.toLowerCase() !== 'to-web' && application !== 'To Web' && application !== 'sip:0@Web-Main')) return;
                if (!parameter || (!parameter.includes('http://') && !parameter.includes('https://'))) return;
                
                // Check if it matches our Case pattern
                const caseMatch = matchingUri?.match(casePattern);
                if (!caseMatch) return;
                
                // Convert * to star for button ID (buttons use "star" not "*")
                const digitRaw = caseMatch[1];
                const digit = digitRaw === '*' ? 'star' : digitRaw;
                const tier = '1'; // Assuming tier 1 for now
                const btnId = `_${tier}_${digit}`;
                
                log(`Found matching dial rule: Case_${digitRaw} -> btnId ${btnId}`);
                
                // Check if we already loaded this from form fields
                const existingPanel = document.getElementById(`application_webresponder${btnId}`) || 
                                      document.getElementById(`application_customdir${btnId}`);
                if (existingPanel) {
                    log(`Button ${btnId} already loaded from form, skipping API result`);
                    return;
                }
                
                log(`Found existing config in API for button ${btnId}:`, parameter);
                
                const dialTranslation = parameter;
                let url = parameter;
                if (parameter.startsWith('<HttpMethod=POST>')) {
                    url = parameter.replace('<HttpMethod=POST>', '');
                }
                
                const isDirectory = url.includes('directory') || url.includes('mode=');
                const type = isDirectory ? 'customdir' : 'webresponder';
                
                // Update button icon
                const btn = document.getElementById(`btn${btnId}`);
                log(`API: Looking for button btn${btnId}:`, btn ? 'found' : 'NOT FOUND');
                if (btn) {
                    const appIcon = btn.querySelector('.app-icon');
                    log(`API: Looking for .app-icon in btn${btnId}:`, appIcon ? 'found' : 'NOT FOUND');
                    if (appIcon) {
                        appIcon.className = 'app-icon ' + (type === 'customdir' ? 'icon-customdir' : 'icon-webresponder');
                        log(`API: Updated icon class for btn${btnId} to:`, appIcon.className);
                    }
                    
                    // Also update the form fields so the AA Designer knows about it
                    const respField = document.getElementById(`button_resp${btnId}`);
                    const destField = document.getElementById(`button_dest${btnId}`);
                    if (respField) respField.value = 'sip:0@Web-Main';
                    if (destField) destField.value = parameter;
                }
                
                // Create detail panel (hidden initially)
                showExistingConfigPanel(tier, digit, btnId, type, url, dialTranslation);
            });
        } catch (e) {
            log('Error loading existing configs from API:', e);
        }
    }

    function showExistingConfigPanel(tier, digit, btnId, type, url, dialTranslation) {
        log(`showExistingConfigPanel called for ${type} btn${btnId}`);
        const selectPanel = document.querySelector(`[id^="application_select${btnId}"]`);
        if (!selectPanel) {
            log(`selectPanel not found for application_select${btnId}`);
            return;
        }
        log(`selectPanel found for ${btnId}`);

        // Remove existing panel
        const existing = document.getElementById(`application_${type}${btnId}`);
        if (existing) existing.remove();

        const digitDisplay = digit === 'star' ? '*' : digit;
        const iconClass = type === 'webresponder' ? 'icon-webresponder' : 'icon-customdir';
        const typeName = type === 'webresponder' ? 'Web Responder' : 'Custom Directory';

        // Parse URL params for display
        let detailsHtml = '';
        try {
            const urlObj = new URL(url);
            const params = urlObj.searchParams;
            const details = [];
            if (params.get('mode')) details.push(`Mode: ${params.get('mode')}`);
            if (params.get('operator')) details.push(`Operator: ${params.get('operator')}`);
            if (params.get('timeout')) details.push(`Timeout: ${params.get('timeout')}s`);
            if (params.get('maxdigits')) details.push(`Max Digits: ${params.get('maxdigits')}`);
            if (params.get('language')) details.push(`Language: ${params.get('language')}`);
            if (params.get('voice')) details.push(`Voice: ${params.get('voice')}`);
            if (params.get('site')) details.push(`Sites: ${params.get('site')}`);
            if (params.get('department')) details.push(`Depts: ${params.get('department')}`);
            
            if (details.length > 0) {
                detailsHtml = `<div style="margin-top:8px; font-size:11px; color:#666;">${details.join(' | ')}</div>`;
            }
        } catch (e) {}

        const panel = document.createElement('div');
        panel.id = `application_${type}${btnId}`;
        panel.className = `application-details application-${type} form-actions level${tier} hide`;
        panel.dataset.btnId = btnId;
        panel.dataset.configType = type;
        panel.dataset.configUrl = url;
        panel.dataset.dialTranslation = dialTranslation;
        panel.style.display = 'none';
        panel.innerHTML = `
            <div class="remove-app">
                <span class="close helpsy" title="Edit" data-aa-ext-edit="${btnId}" style="margin-right:10px; color:#337ab7; cursor:pointer;">Edit</span>
                <span class="close helpsy" title="Remove" data-aa-ext-remove="${btnId}">Remove</span>
            </div>
            <div class="pull-left">
                <div class="app-icon-lg ${iconClass} helpsy" title="Change Application" 
                    data-aa-ext-change="${btnId}"></div>
            </div>
            <div class="pull-right">
                <span><strong>${typeName}</strong> will be invoked when <strong>${digitDisplay}</strong> is pressed.</span>
                <div style="margin-top:10px; padding:10px; background:#f5f5f5; border-radius:4px; font-size:11px;">
                    <strong>URL:</strong><br>
                    <code style="word-break:break-all; font-size:10px;">${url}</code>
                </div>
                ${detailsHtml}
            </div>
        `;

        selectPanel.after(panel);

        // Event listeners
        panel.querySelector('[data-aa-ext-edit]').addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            editExistingConfig(tier, digit, btnId, type, dialTranslation);
        });

        panel.querySelector('[data-aa-ext-remove]').addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            removeCustomConfig(tier, digit, btnId);
        });

        panel.querySelector('[data-aa-ext-change]').addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            changeCustomConfig(tier, digit, btnId);
        });

        // Setup button click handler (but don't show panel yet)
        setupButtonForCustomConfig(tier, digit, btnId, type, `application_${type}${btnId}`);
    }

    // ============================================
    // INJECT APPLICATION OPTIONS
    // ============================================
    function injectApplicationOptions() {
        const appChoicesLists = document.querySelectorAll('.application-choices ul');
        if (appChoicesLists.length === 0) {
            log('No application choice lists found');
            return false;
        }

        let injected = 0;
        appChoicesLists.forEach(ul => {
            // Skip if already injected (check for our custom icons)
            if (ul.querySelector('.icon-webresponder') || ul.querySelector('.icon-customdir')) return;

            // Find parent to get tier/digit info
            const appSelect = ul.closest('.application-select');
            if (!appSelect || !appSelect.dataset.btnId) return;

            const btnId = appSelect.dataset.btnId;
            const match = btnId.match(/_(\d+)_(\d+|star)/);
            if (!match) return;

            const tier = match[1];
            const digit = match[2];

            // Find insertion point (before "Add Tier" if it exists)
            const addTierLi = Array.from(ul.querySelectorAll('li')).find(li => 
                li.textContent.includes('Add Tier'));

            // Create Web Responder option
            const wrLi = document.createElement('li');
            wrLi.innerHTML = `
                <a href="javascript:void(0);">
                    <div class="app-icon-lg icon-webresponder"></div>
                    <span>Web<br>Responder</span>
                </a>
            `;
            wrLi.querySelector('a').addEventListener('click', (e) => {
                e.preventDefault();
                showWebResponderForm(tier, digit, btnId);
            });
            
            if (addTierLi) {
                ul.insertBefore(wrLi, addTierLi);
            } else {
                ul.appendChild(wrLi);
            }

            // Create Custom Directory option
            const dirLi = document.createElement('li');
            dirLi.innerHTML = `
                <a href="javascript:void(0);">
                    <div class="app-icon-lg icon-customdir"></div>
                    <span>Custom<br>Directory</span>
                </a>
            `;
            dirLi.querySelector('a').addEventListener('click', (e) => {
                e.preventDefault();
                showDirectoryForm(tier, digit, btnId);
            });
            
            if (addTierLi) {
                ul.insertBefore(dirLi, addTierLi);
            } else {
                ul.appendChild(dirLi);
            }

            injected++;
        });

        if (injected > 0) {
            log(`Injected options into ${injected} lists`);
        }
        return injected > 0;
    }

    // ============================================
    // INITIALIZATION
    // ============================================
    function init() {
        log('Initializing v3...');
        
        injectStyles();
        
        // Intercept save button to trigger API saves
        setTimeout(interceptSaveButton, 1000);

        // Track if we've successfully loaded existing configs
        let existingConfigsLoaded = false;
        
        const tryLoadExisting = () => {
            // Check if buttons exist on the page
            const buttons = document.querySelectorAll('[id^="btn_"]');
            log(`tryLoadExisting: found ${buttons.length} buttons, existingConfigsLoaded=${existingConfigsLoaded}`);
            if (buttons.length > 0 && !existingConfigsLoaded) {
                log('Buttons found, loading existing configurations...');
                loadExistingConfigurations();
                existingConfigsLoaded = true;
            }
        };

        if (!injectApplicationOptions()) {
            const observer = new MutationObserver(() => {
                injectApplicationOptions();
                tryLoadExisting();
            });

            observer.observe(document.body, {
                childList: true,
                subtree: true
            });

            let attempts = 0;
            const interval = setInterval(() => {
                attempts++;
                tryLoadExisting();
                if (injectApplicationOptions() || attempts > 30) {
                    if (attempts > 30) log('Giving up after 30 attempts');
                    clearInterval(interval);
                }
            }, 500);
        } else {
            // If injection worked immediately, also try loading existing
            setTimeout(tryLoadExisting, 500);
        }

        window.AAExtension = {
            showWebResponderForm,
            showDirectoryForm,
            injectApplicationOptions,
            loadExistingConfigurations,
            removeCustomConfig,
            changeCustomConfig,
            config: CONFIG,
            // Enable/disable debug logging at runtime
            enableDebug: (enabled = true) => { CONFIG.debug = enabled; console.log('[AA Extension] Debug', enabled ? 'enabled' : 'disabled'); },
            // API functions
            saveWebResponderConfig,
            saveAllPendingConfigs,
            fetchExistingDialRules,
            createDialRule,
            updateDialRule,
            deleteDialRule,
            getAAInfo,
            pendingConfigs
        };

        log('Initialized v3. Use window.AAExtension.enableDebug(true) to enable logging.');
    }

    // Only run on auto attendant edit page
    if (window.location.pathname.includes('/portal/attendants/edit')) {
        // Run when DOM is ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
        } else {
            init();
        }
    }
})();
