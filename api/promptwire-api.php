<?php
/**
 * PromptWire Remote API Endpoint
 *
 * Deploy this single file to your remote ProcessWire site root.
 * It provides an authenticated HTTP API that mirrors the local CLI —
 * all the same commands work identically, just over HTTPS.
 *
 * DEPLOYMENT
 * ----------
 * 1. Copy this file to your remote PW site root (same level as index.php)
 * 2. Create site/config-promptwire.php with your API key (see below)
 * 3. Add to your local mcp.json:
 *
 *    "ProcessWire MCP: example.com (production)": {
 *      "command": "node",
 *      "args": ["/path/to/mcp-server/dist/index.js"],
 *      "env": {
 *        "PW_REMOTE_URL": "https://www.example.com/your-endpoint.php",
 *        "PW_REMOTE_KEY": "your-api-key-here"
 *      }
 *    }
 *
 * CONFIGURATION (site/config-promptwire.php)
 * ---------------------------------------
 * <?php
 * define('PROMPTWIRE_API_KEY', 'your-secret-key-here');
 * // Optional: restrict to your Mac's IP (curl ifconfig.me to find it)
 * // define('PROMPTWIRE_ALLOWED_IPS', '1.2.3.4');
 *
 * SECURITY
 * --------
 * - Rename this file to something unique (e.g. pw-xyz8k3m.php) so the
 *   endpoint URL is not guessable from the public documentation. Update
 *   PW_REMOTE_URL in your mcp.json to match. The MCP server uses that
 *   env var as-is — it does not assume any specific filename.
 * - API key authentication via X-PromptWire-Key header
 * - HTTPS strongly recommended (key is sent in header)
 * - Optional IP allowlist for extra protection
 * - Error details suppressed in production (no info leakage)
 * - Read/write operations mirror local CLI permissions
 *
 * @package     PromptWire
 * @author      Peter Knight <https://www.peterknight.digital>
 * @license     MIT
 */

namespace ProcessWire;

// ============================================================================
// CONFIGURATION
// ============================================================================

// Load API key from config file (recommended — keep out of webroot if possible)
// Place at: site/config-promptwire.php
$configFile = __DIR__ . '/site/config-promptwire.php';
if (file_exists($configFile)) {
    require_once $configFile;
}

// Fallback: check environment variable (useful for Docker/Kubernetes setups)
if (!defined('PROMPTWIRE_API_KEY')) {
    $envKey = getenv('PROMPTWIRE_API_KEY');
    if ($envKey) {
        define('PROMPTWIRE_API_KEY', $envKey);
    }
}

// ============================================================================
// OUTPUT HEADERS
// ============================================================================

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow');
header('X-PromptWire-Version: 1.0');

// Suppress PHP errors from leaking into JSON output
error_reporting(0);
ini_set('display_errors', '0');

// Increase limits for file upload operations (base64 payloads can be large)
ini_set('post_max_size', '64M');
ini_set('memory_limit', '256M');

// ============================================================================
// SECURITY: METHOD CHECK
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed — use POST']);
    exit;
}

// ============================================================================
// SECURITY: API KEY AUTHENTICATION
// ============================================================================

if (!defined('PROMPTWIRE_API_KEY') || empty(PROMPTWIRE_API_KEY)) {
    http_response_code(500);
    echo json_encode([
        'error' => 'API key not configured on this server — create site/config-promptwire.php with: define(\'PROMPTWIRE_API_KEY\', \'your-key\');'
    ]);
    exit;
}

$providedKey = $_SERVER['HTTP_X_PROMPTWIRE_KEY'] ?? '';

// Use hash_equals to prevent timing attacks
if (!hash_equals(PROMPTWIRE_API_KEY, $providedKey)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized — invalid API key']);
    exit;
}

// ============================================================================
// SECURITY: OPTIONAL IP ALLOWLIST
// ============================================================================

if (defined('PROMPTWIRE_ALLOWED_IPS') && PROMPTWIRE_ALLOWED_IPS) {
    $allowedIps = array_map('trim', explode(',', PROMPTWIRE_ALLOWED_IPS));
    // Support proxied IPs (Cloudflare, load balancers, etc.)
    $forwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    $clientIp = $forwardedFor
        ? trim(explode(',', $forwardedFor)[0])
        : ($_SERVER['REMOTE_ADDR'] ?? '');

    if (!in_array($clientIp, $allowedIps, true)) {
        http_response_code(403);
        echo json_encode(['error' => "IP not allowed: $clientIp"]);
        exit;
    }
}

// ============================================================================
// PARSE REQUEST BODY
// ============================================================================

$rawBody = file_get_contents('php://input');
if (!$rawBody) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty request body']);
    exit;
}

$request = json_decode($rawBody, true);
if (!is_array($request) || !isset($request['command'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body — expected: {"command": "...", "args": [...]}']);
    exit;
}

$command = $request['command'];
$args = $request['args'] ?? [];

if (!is_string($command) || empty(trim($command))) {
    http_response_code(400);
    echo json_encode(['error' => 'command must be a non-empty string']);
    exit;
}

if (!is_array($args)) {
    http_response_code(400);
    echo json_encode(['error' => 'args must be an array']);
    exit;
}

// ============================================================================
// BOOTSTRAP PROCESSWIRE
// ============================================================================

$rootPath = __DIR__;

if (!file_exists($rootPath . '/wire/core/ProcessWire.php')) {
    http_response_code(500);
    echo json_encode(['error' => 'ProcessWire core not found — ensure this file is in the PW site root']);
    exit;
}

// Restore normal error reporting for PW bootstrap, then suppress PHP notices/warnings
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED & ~E_WARNING);

// Load Composer autoloader if present
$composerAutoloader = $rootPath . '/vendor/autoload.php';
if (file_exists($composerAutoloader)) {
    require_once $composerAutoloader;
}

// Buffer output during bootstrap — PW may emit stray notices on PHP 8.x
ob_start();

if (!defined('PROCESSWIRE')) {
    define('PROCESSWIRE', 300);
}

require_once "$rootPath/wire/core/ProcessWire.php";

$config = ProcessWire::buildConfig($rootPath);
if (!$config->dbName) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['error' => 'ProcessWire database not configured']);
    exit;
}

$wire = new ProcessWire($config);
ob_end_clean();

// Re-assert error suppression after PW resets it internally
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED & ~E_WARNING);

if (!$wire) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to bootstrap ProcessWire']);
    exit;
}

// ============================================================================
// HANDLE INLINE SCHEMA DATA (for schema:apply on remote sites)
// If the request includes a 'schemaData' key, write it to a temp file
// and inject the path as the first positional argument so CommandRouter
// can read it via the normal schema:apply file-path mechanism.
// ============================================================================

if ($command === 'schema:apply' && isset($request['schemaData']) && is_array($request['schemaData'])) {
    $tmpFile = sys_get_temp_dir() . '/promptwire-schema-' . time() . '.json';
    file_put_contents($tmpFile, json_encode($request['schemaData']));
    // Prepend the temp file path as the first positional argument
    array_unshift($args, $tmpFile);
    // Register shutdown to clean up temp file
    register_shutdown_function(function() use ($tmpFile) {
        if (file_exists($tmpFile)) {
            @unlink($tmpFile);
        }
    });
}

// Parse args early so $flags is available to both the special cases and CommandRouter
$flags = parseRemoteArgs($args);

// ============================================================================
// PAGE-REF RESOLUTION HELPERS
// Resolves a _pageRef object { _pageRef, id, path, _comment } to a ProcessWire
// page ID, using path-first lookup so cross-environment pushes find the correct
// page even when database IDs differ between local and remote.
// ============================================================================

function pwMcpResolvePageRef(\ProcessWire\Wire $wire, array $ref): ?int {
    // Path-first: works across environments that share the same URL structure.
    $path = $ref['path'] ?? null;
    if (!$path && isset($ref['_comment'])) {
        // Parse legacy "Title @ /path/" comment format.
        $parts = explode(' @ ', $ref['_comment'], 2);
        $path  = trim($parts[1] ?? '');
    }
    if ($path) {
        $page = $wire->pages->get($path);
        if ($page && $page->id) return (int) $page->id;
    }
    // ID fallback: works when pushing within the same environment.
    $id = (int) ($ref['id'] ?? 0);
    if ($id) {
        $page = $wire->pages->get($id);
        if ($page && $page->id) return (int) $page->id;
    }
    return null;
}

/**
 * Resolve a field value before passing to $page->set().
 * Handles _pageRef objects (single and array) transparently; all other values
 * are returned unchanged.
 */
function pwMcpResolveFieldValue(\ProcessWire\Wire $wire, $value) {
    if (!is_array($value)) return $value;
    // Single page reference
    if (isset($value['_pageRef']) && $value['_pageRef'] === true) {
        return pwMcpResolvePageRef($wire, $value);
    }
    // Array of page references
    if (!empty($value) && isset($value[0]['_pageRef'])) {
        $ids = [];
        foreach ($value as $ref) {
            $id = pwMcpResolvePageRef($wire, $ref);
            if ($id) $ids[] = $id;
        }
        return $ids ?: null;
    }
    return $value;
}

// ============================================================================
// SPECIAL CASE: page:create — create a new page on a remote site
// Accepts { command, args: [template, parentPath, pageName], pageData: { fields, published } }
// ============================================================================

if ($command === 'page:create') {
    $template   = $flags['_positional'][0] ?? null;
    $parentPath = $flags['_positional'][1] ?? null;
    $pageName   = $flags['_positional'][2] ?? null;
    $pageData   = $request['pageData'] ?? [];
    $fields     = $pageData['fields'] ?? [];
    $published  = !empty($pageData['published']);
    $dryRun     = !isset($flags['dry-run']) || $flags['dry-run'] !== '0';

    if (!$template || !$parentPath || !$pageName) {
        http_response_code(400);
        echo json_encode(['error' => 'page:create requires template, parentPath, pageName as positional args']);
        exit;
    }

    // Validate template exists
    $tpl = $wire->templates->get($template);
    if (!$tpl) {
        http_response_code(400);
        echo json_encode(['error' => "Template not found: $template"]);
        exit;
    }

    // Validate parent exists
    $parent = $wire->pages->get($parentPath);
    if (!$parent || !$parent->id) {
        http_response_code(400);
        echo json_encode(['error' => "Parent page not found: $parentPath"]);
        exit;
    }

    // Check for name collision
    $existing = $wire->pages->get("parent=$parent, name=$pageName");
    if ($existing && $existing->id) {
        http_response_code(409);
        echo json_encode([
            'error'    => "Page already exists at $parentPath$pageName/",
            'existingId' => $existing->id,
        ]);
        exit;
    }

    if ($dryRun) {
        echo json_encode([
            'success'    => true,
            'dryRun'     => true,
            'template'   => $template,
            'parentPath' => $parentPath,
            'pageName'   => $pageName,
            'title'      => $fields['title'] ?? ucwords(str_replace(['-', '_'], ' ', $pageName)),
            'published'  => $published,
            'fields'     => array_keys($fields),
            'willCreate' => "$parentPath$pageName/",
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    try {
        $page            = new \ProcessWire\Page();
        $page->template  = $tpl;
        $page->parent    = $parent;
        $page->name      = $pageName;
        $page->title     = $fields['title'] ?? ucwords(str_replace(['-', '_'], ' ', $pageName));

        // Apply field values (skip title — already set above).
        // Page reference fields (_pageRef objects) are resolved by path first so
        // the correct remote ID is used even when it differs from the local ID.
        foreach ($fields as $fieldName => $value) {
            if ($fieldName === 'title') continue;
            if (strpos($fieldName, '_') === 0) continue;
            if (!$wire->fields->get($fieldName)) continue;
            $resolved = pwMcpResolveFieldValue($wire, $value);
            if ($resolved !== null) {
                $page->set($fieldName, $resolved);
            }
        }

        if ($published) {
            $wire->pages->save($page);
        } else {
            $page->addStatus(\ProcessWire\Page::statusUnpublished);
            $wire->pages->save($page);
        }

        echo json_encode([
            'success'    => true,
            'dryRun'     => false,
            'pageId'     => $page->id,
            'pagePath'   => $page->path,
            'title'      => $page->title,
            'published'  => $published,
            'status'     => $published ? 'published' : 'unpublished',
            'createdAt'  => date('c'),
        ], JSON_UNESCAPED_SLASHES);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Page creation failed: ' . $e->getMessage(),
            'file'  => basename($e->getFile()),
            'line'  => $e->getLine(),
        ]);
    }
    exit;
}

// ============================================================================
// SPECIAL CASE: page:exists — batch check whether page paths exist
// Accepts { command, args: [], pageData: { paths: ["/path/", ...] } }
// Returns { results: { "/path/": { exists, id, published } } }
// ============================================================================

if ($command === 'page:exists') {
    $pageData = $request['pageData'] ?? null;
    $paths    = is_array($pageData['paths'] ?? null) ? $pageData['paths'] : [];

    if (empty($paths)) {
        http_response_code(400);
        echo json_encode(['error' => 'page:exists requires pageData.paths array']);
        exit;
    }

    $results = [];
    foreach ($paths as $path) {
        $path = (string) $path;
        $page = $wire->pages->get($path);
        if ($page && $page->id) {
            $results[$path] = [
                'exists'      => true,
                'id'          => $page->id,
                'title'       => (string) $page->title,
                'published'   => !$page->isUnpublished(),
                'template'    => (string) $page->template,
            ];
        } else {
            $results[$path] = ['exists' => false];
        }
    }

    echo json_encode(['results' => $results], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// SPECIAL CASE: page:update — apply field values to a remote page directly
// Accepts { command, args: ["/path/"], pageData: { fields: { field: value } } }
// ============================================================================

if ($command === 'page:update') {
    $pagePath = $flags['_positional'][0] ?? null;
    $pageData = $request['pageData'] ?? null;

    if (!$pagePath || !is_array($pageData)) {
        http_response_code(400);
        echo json_encode(['error' => 'page:update requires a page path arg and pageData object']);
        exit;
    }

    $page = $wire->pages->get($pagePath);
    if (!$page || !$page->id) {
        http_response_code(404);
        echo json_encode(['error' => "Page not found: $pagePath"]);
        exit;
    }

    $dryRun  = !isset($flags['dry-run']) || $flags['dry-run'] !== '0';
    $fields  = $pageData['fields'] ?? [];
    $changes = [];

    // Collect changes and resolve page references before applying.
    // _pageRef values are resolved to remote IDs via path-first lookup so
    // cross-environment pushes don't write the wrong page ID.
    $fieldValues = []; // resolved values keyed by field name
    foreach ($fields as $fieldName => $newValue) {
        if (strpos($fieldName, '_') === 0) continue;
        if (!$wire->fields->get($fieldName)) continue;

        $resolved = pwMcpResolveFieldValue($wire, $newValue);

        $oldValue = (string) $page->get($fieldName);
        // For display in the diff, use path(s) if it's a page ref; otherwise raw.
        if (is_array($newValue) && (isset($newValue['_pageRef']) || isset($newValue[0]['_pageRef']))) {
            if (is_array($resolved)) {
                $newStr = implode(', ', $resolved);
            } elseif ($resolved !== null) {
                $newStr = (string) $resolved;
            } else {
                $newStr = '(unresolved)';
            }
        } else {
            $newStr = is_array($newValue) ? json_encode($newValue) : (string) $newValue;
        }

        if ($oldValue !== $newStr && !(empty($oldValue) && empty($newStr))) {
            $changes[$fieldName] = ['from' => $oldValue, 'to' => $newStr];
            if ($resolved !== null) {
                $fieldValues[$fieldName] = $resolved;
            }
        }
    }

    $publish = !empty($pageData['publish']);

    if (!$dryRun) {
        // Apply resolved field values
        foreach ($fieldValues as $fieldName => $resolvedValue) {
            $page->set($fieldName, $resolvedValue);
        }
        // Publish if requested
        if ($publish && $page->isUnpublished()) {
            $page->removeStatus(\ProcessWire\Page::statusUnpublished);
        }
        if (!empty($fieldValues) || $publish) {
            $wire->pages->save($page);
        }
    }

    echo json_encode([
        'success'     => true,
        'dryRun'      => $dryRun,
        'pageId'      => $page->id,
        'pagePath'    => $page->path,
        'changes'     => $changes,
        'changeCount' => count($changes),
        'published'   => $publish ? true : !$page->isUnpublished(),
        'pushedAt'    => $dryRun ? null : date('c'),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// SPECIAL CASE: file:inventory — list files on a page's file/image fields
// Returns { fields: { fieldName: { type, files: [{ filename, size, md5, ... }] } } }
// ============================================================================

if ($command === 'file:inventory') {
    $pagePath = $flags['_positional'][0] ?? null;
    if (!$pagePath) {
        http_response_code(400);
        echo json_encode(['error' => 'file:inventory requires a page path']);
        exit;
    }

    try {
        $page = $wire->pages->get($pagePath);
        if (!$page || !$page->id) {
            http_response_code(404);
            echo json_encode(['error' => "Page not found: $pagePath"]);
            exit;
        }

        $inventory = [];
        foreach ($page->template->fieldgroup as $field) {
            $typeName = $field->type->className();
            $isFile  = strpos($typeName, 'FieldtypeFile') !== false;
            $isImage = strpos($typeName, 'FieldtypeImage') !== false || strpos($typeName, 'FieldtypeCroppable') !== false;
            if (!$isFile && !$isImage) continue;

            $fieldName = $field->name;
            $value = $page->getUnformatted($fieldName);
            if (!$value || !($value instanceof \ProcessWire\Pagefiles)) continue;

            $files = [];
            foreach ($value as $f) {
                $filePath = $f->filename;
                if (!is_file($filePath)) continue;

                // PW only returns originals when iterating Pageimages/Pagefiles;
                // variations are internal and not yielded by the iterator.
                $entry = [
                    'filename'    => $f->name,
                    'size'        => (int) filesize($filePath),
                    'md5'         => md5_file($filePath),
                    'description' => $f->description ?: null,
                ];
                if ($f instanceof \ProcessWire\Pageimage) {
                    $entry['width']  = (int) $f->width;
                    $entry['height'] = (int) $f->height;
                }
                $files[] = $entry;
            }

            $inventory[$fieldName] = [
                'type'  => $isImage ? 'image' : 'file',
                'count' => count($files),
                'files' => $files,
            ];
        }

        echo json_encode([
            'success'   => true,
            'pageId'    => $page->id,
            'pagePath'  => $page->path,
            'fields'    => $inventory,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'file:inventory failed: ' . $e->getMessage(),
            'file'  => basename($e->getFile()),
            'line'  => $e->getLine(),
        ]);
    }
    exit;
}

// ============================================================================
// SPECIAL CASE: file:upload — add a file to a page's file/image field
// Accepts { command, args: ["/path/"], fileData: { fieldName, filename, data (base64), description? } }
// ============================================================================

if ($command === 'file:upload') {
    $pagePath = $flags['_positional'][0] ?? null;
    $fileData = $request['fileData'] ?? null;

    if (!$pagePath || !is_array($fileData)) {
        http_response_code(400);
        echo json_encode(['error' => 'file:upload requires a page path and fileData object']);
        exit;
    }

    $fieldName = $fileData['fieldName'] ?? null;
    $filename  = $fileData['filename']  ?? null;
    $base64    = $fileData['data']       ?? null;
    $desc      = $fileData['description'] ?? null;
    $dryRun    = !isset($flags['dry-run']) || $flags['dry-run'] !== '0';

    if (!$fieldName || !$filename || !$base64) {
        http_response_code(400);
        echo json_encode(['error' => 'fileData requires fieldName, filename, and data (base64)']);
        exit;
    }

    $page = $wire->pages->get($pagePath);
    if (!$page || !$page->id) {
        http_response_code(404);
        echo json_encode(['error' => "Page not found: $pagePath"]);
        exit;
    }

    $field = $wire->fields->get($fieldName);
    if (!$field) {
        http_response_code(400);
        echo json_encode(['error' => "Field not found: $fieldName"]);
        exit;
    }

    if ($dryRun) {
        $decoded = base64_decode($base64, true);
        echo json_encode([
            'success'   => true,
            'dryRun'    => true,
            'pagePath'  => $page->path,
            'fieldName' => $fieldName,
            'filename'  => $filename,
            'size'      => $decoded !== false ? strlen($decoded) : 0,
            'action'    => 'would_upload',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $decoded = base64_decode($base64, true);
    if ($decoded === false) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid base64 data']);
        exit;
    }

    $tmpDir  = sys_get_temp_dir() . '/promptwire-uploads';
    if (!is_dir($tmpDir)) mkdir($tmpDir, 0755, true);
    $tmpFile = $tmpDir . '/' . $filename;
    file_put_contents($tmpFile, $decoded);

    register_shutdown_function(function() use ($tmpFile) {
        if (file_exists($tmpFile)) @unlink($tmpFile);
    });

    try {
        $page->of(false);
        $fieldValue = $page->get($fieldName);

        if (!($fieldValue instanceof \ProcessWire\Pagefiles)) {
            http_response_code(400);
            echo json_encode(['error' => "Field $fieldName is not a file/image field"]);
            exit;
        }

        // Remove existing file with same name to allow replacement
        $existing = $fieldValue->get("name=$filename");
        if ($existing) $fieldValue->delete($existing);

        $fieldValue->add($tmpFile);
        if ($desc !== null) {
            $added = $fieldValue->get("name=$filename");
            if ($added) $added->description = $desc;
        }
        $page->save($fieldName);

        echo json_encode([
            'success'   => true,
            'dryRun'    => false,
            'pagePath'  => $page->path,
            'fieldName' => $fieldName,
            'filename'  => $filename,
            'size'      => strlen($decoded),
            'action'    => 'uploaded',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'file:upload failed: ' . $e->getMessage(),
            'file'  => basename($e->getFile()),
            'line'  => $e->getLine(),
        ]);
    }
    exit;
}

// ============================================================================
// SPECIAL CASE: file:delete — remove a file from a page's file/image field
// Accepts { command, args: ["/path/"], fileData: { fieldName, filename } }
// ============================================================================

if ($command === 'file:delete') {
    $pagePath = $flags['_positional'][0] ?? null;
    $fileData = $request['fileData'] ?? null;

    if (!$pagePath || !is_array($fileData)) {
        http_response_code(400);
        echo json_encode(['error' => 'file:delete requires a page path and fileData object']);
        exit;
    }

    $fieldName = $fileData['fieldName'] ?? null;
    $filename  = $fileData['filename']  ?? null;
    $dryRun    = !isset($flags['dry-run']) || $flags['dry-run'] !== '0';

    if (!$fieldName || !$filename) {
        http_response_code(400);
        echo json_encode(['error' => 'fileData requires fieldName and filename']);
        exit;
    }

    $page = $wire->pages->get($pagePath);
    if (!$page || !$page->id) {
        http_response_code(404);
        echo json_encode(['error' => "Page not found: $pagePath"]);
        exit;
    }

    $fieldValue = $page->getUnformatted($fieldName);
    if (!$fieldValue || !($fieldValue instanceof \ProcessWire\Pagefiles)) {
        http_response_code(400);
        echo json_encode(['error' => "Field $fieldName is not a file/image field"]);
        exit;
    }

    $existing = $fieldValue->get("name=$filename");
    if (!$existing) {
        echo json_encode([
            'success'  => true,
            'dryRun'   => $dryRun,
            'action'   => 'not_found',
            'message'  => "File $filename does not exist on remote — nothing to delete",
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($dryRun) {
        echo json_encode([
            'success'   => true,
            'dryRun'    => true,
            'pagePath'  => $page->path,
            'fieldName' => $fieldName,
            'filename'  => $filename,
            'action'    => 'would_delete',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $page->of(false);
    $fieldValue->delete($existing);
    $page->save($fieldName);

    echo json_encode([
        'success'   => true,
        'dryRun'    => false,
        'pagePath'  => $page->path,
        'fieldName' => $fieldName,
        'filename'  => $filename,
        'action'    => 'deleted',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// SPECIAL CASE: schema:apply — fully self-contained, no external dependencies
// ============================================================================

if ($command === 'schema:apply') {
    // $flags['_positional'][0] is the temp file written above from schemaData
    $schemaFile = $flags['_positional'][0] ?? ($args[0] ?? null);
    if (!$schemaFile || !file_exists($schemaFile)) {
        http_response_code(400);
        echo json_encode(['error' => 'schema:apply requires schemaData in the request body']);
        exit;
    }

    $schema = json_decode(file_get_contents($schemaFile), true);
    if (!is_array($schema)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid schema JSON']);
        exit;
    }

    $dryRun = !isset($flags['dry-run']) || $flags['dry-run'] !== '0';

    try {
        $result = pwMcpApplySchema($wire, $schema, $dryRun);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'error'  => 'Schema apply failed: ' . $e->getMessage(),
            'file'   => basename($e->getFile()),
            'line'   => $e->getLine(),
            'dryRun' => $dryRun,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// LOAD COMMAND ROUTER AND EXECUTE
// ============================================================================

$routerPath = $rootPath . '/site/modules/PromptWire/src/Cli/CommandRouter.php';
if (!file_exists($routerPath)) {
    http_response_code(500);
    echo json_encode([
        'error' => 'PromptWire module not found on this site — install the PromptWire module first',
        'expectedPath' => $routerPath,
    ]);
    exit;
}

require_once $routerPath;

// Also load SyncManager and Schema classes that CommandRouter lazy-loads
// (their paths are relative to the CLI, so we set a base path flag)
// CommandRouter uses require_once with __DIR__ paths, which work correctly
// as long as this file is in the PW root.

$router = new \PromptWire\Cli\CommandRouter($wire);

// Capture any stray output from command execution
ob_start();
$result = $router->run($command, $flags);
ob_end_clean();

// Output result as JSON
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// ============================================================================
// SCHEMA APPLY — self-contained implementation (no SchemaImporter dependency)
// ============================================================================

function pwMcpApplySchema($wire, array $schema, bool $dryRun): array
{
    $fieldResults    = [];
    $templateResults = [];

    // Fields first — templates reference fields by name
    foreach ($schema['fields'] ?? [] as $name => $def) {
        if (strpos($name, '_') === 0) continue;
        $existing = $wire->fields->get($name);
        if (!$existing) {
            $fieldResults[$name] = pwMcpCreateField($wire, $name, $def, $dryRun);
            $fieldResults[$name]['action'] = 'create';
        } else {
            $fieldResults[$name] = pwMcpUpdateField($wire, $existing, $def, $dryRun);
            $fieldResults[$name]['action'] = $fieldResults[$name]['changed'] ? 'update' : 'unchanged';
        }
    }

    // Templates second
    foreach ($schema['templates'] ?? [] as $name => $def) {
        if (strpos($name, '_') === 0) continue;
        $existing = $wire->templates->get($name);
        if (!$existing) {
            $templateResults[$name] = pwMcpCreateTemplate($wire, $name, $def, $dryRun);
            $templateResults[$name]['action'] = 'create';
        } else {
            $templateResults[$name] = pwMcpUpdateTemplate($wire, $existing, $def, $dryRun);
            $templateResults[$name]['action'] = $templateResults[$name]['changed'] ? 'update' : 'unchanged';
        }
    }

    // Tally summary
    $tally = function (array $items): array {
        $c = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => 0];
        foreach ($items as $item) {
            if (!empty($item['error'])) $c['errors']++;
            elseif (($item['action'] ?? '') === 'create') $c['created']++;
            elseif (($item['action'] ?? '') === 'update')  $c['updated']++;
            else $c['unchanged']++;
        }
        return $c;
    };

    return [
        'dryRun'    => $dryRun,
        'fields'    => $fieldResults,
        'templates' => $templateResults,
        'summary'   => ['fields' => $tally($fieldResults), 'templates' => $tally($templateResults)],
    ];
}

function pwMcpCreateField($wire, string $name, array $def, bool $dryRun): array
{
    $typeName  = $def['type'] ?? null;
    if (!$typeName) return ['success' => false, 'error' => 'Missing field type'];

    $fieldtype = $wire->fieldtypes->get($typeName);
    if (!$fieldtype) return ['success' => false, 'error' => "Unknown field type: $typeName"];

    if ($dryRun) return ['success' => true, 'type' => $typeName, 'label' => $def['label'] ?? null];

    $field            = new \ProcessWire\Field();
    $field->name      = $name;
    $field->type      = $fieldtype;
    $field->label     = $def['label'] ?? '';
    if (!empty($def['description'])) $field->description = $def['description'];
    if (!empty($def['required']))    $field->required    = (bool) $def['required'];
    if (!empty($def['inputfield']))  $field->inputfieldClass = $def['inputfield'];

    foreach ($def['settings'] ?? [] as $k => $v) {
        if ($k !== 'options') $field->set($k, $v);
    }

    $wire->fields->save($field);
    return ['success' => true, 'id' => $field->id, 'type' => $typeName, 'label' => $field->label];
}

function pwMcpUpdateField($wire, $field, array $def, bool $dryRun): array
{
    $changes  = [];
    $typeName = $def['type'] ?? null;

    if ($typeName && $field->type->className() !== $typeName) {
        return ['success' => false, 'changed' => false,
            'warning' => "Type change {$field->type->className()} → $typeName must be done manually"];
    }

    $newLabel = $def['label'] ?? null;
    if ($newLabel !== null && $field->label !== $newLabel) {
        $changes['label'] = ['from' => $field->label, 'to' => $newLabel];
    }
    $newDesc = $def['description'] ?? null;
    if ($newDesc !== null && $field->description !== $newDesc) {
        $changes['description'] = ['from' => $field->description, 'to' => $newDesc];
    }

    if (empty($changes)) return ['success' => true, 'changed' => false];
    if ($dryRun)         return ['success' => true, 'changed' => true, 'changes' => $changes];

    foreach ($changes as $key => $change) $field->set($key, $change['to']);
    $wire->fields->save($field);
    return ['success' => true, 'changed' => true, 'changes' => $changes];
}

function pwMcpCreateTemplate($wire, string $name, array $def, bool $dryRun): array
{
    $desiredFields = $def['fields'] ?? [];
    $missing       = array_values(array_filter($desiredFields, fn($f) => !$wire->fields->get($f)));

    if ($dryRun) {
        $r = ['success' => true, 'label' => $def['label'] ?? null, 'fields' => $desiredFields];
        if ($missing) { $r['warning'] = 'Some fields do not exist yet'; $r['missingFields'] = $missing; }
        return $r;
    }

    // Create fieldgroup first — required by ProcessWire before saving a template
    $fg       = new \ProcessWire\Fieldgroup();
    $fg->name = $name;
    $titleField = $wire->fields->get('title');
    if ($titleField && !in_array('title', $desiredFields, true)) $fg->add($titleField);
    $wire->fieldgroups->save($fg);

    $template             = new \ProcessWire\Template();
    $template->name       = $name;
    $template->label      = $def['label'] ?? '';
    $template->fieldgroup = $fg;

    if (!empty($def['family'])) {
        $fam = $def['family'];
        if (isset($fam['allowChildren'])) $template->noChildren = $fam['allowChildren'] ? 0 : 1;
        if (isset($fam['allowParents']))  $template->noParents  = $fam['allowParents']  ? 0 : 1;
        if (isset($fam['allowPageNum']))  $template->allowPageNum = (int) $fam['allowPageNum'];
        if (isset($fam['urlSegments']))   $template->urlSegments  = (int) $fam['urlSegments'];
    }

    $wire->templates->save($template);

    $added = [];
    foreach ($desiredFields as $fn) {
        if (in_array($fn, $missing, true)) continue;
        $f = $wire->fields->get($fn);
        if ($f) { $template->fieldgroup->add($f); $added[] = $fn; }
    }
    $template->fieldgroup->save();

    $r = ['success' => true, 'id' => $template->id, 'label' => $template->label, 'fieldsAdded' => $added];
    if ($missing) $r['missingFields'] = $missing;
    return $r;
}

function pwMcpUpdateTemplate($wire, $template, array $def, bool $dryRun): array
{
    $changes = [];
    $newLabel = $def['label'] ?? null;
    if ($newLabel !== null && $template->label !== $newLabel) {
        $changes['label'] = ['from' => $template->label, 'to' => $newLabel];
    }

    $desiredFields  = $def['fields'] ?? [];
    $currentFields  = array_map(fn($f) => $f->name, iterator_to_array($template->fields));
    $toAdd          = array_values(array_diff($desiredFields, $currentFields));
    $missing        = array_values(array_filter($toAdd, fn($f) => !$wire->fields->get($f)));

    if ($toAdd) $changes['fieldsToAdd'] = $toAdd;
    if (empty($changes)) return ['success' => true, 'changed' => false];
    if ($dryRun) {
        $r = ['success' => true, 'changed' => true, 'changes' => $changes];
        if ($missing) $r['missingFields'] = $missing;
        return $r;
    }

    if (isset($changes['label'])) $template->label = $changes['label']['to'];

    $added = [];
    foreach ($toAdd as $fn) {
        if (in_array($fn, $missing, true)) continue;
        $f = $wire->fields->get($fn);
        if ($f) { $template->fields->add($f); $added[] = $fn; }
    }
    if ($added) { $template->fields->save(); $changes['fieldsAdded'] = $added; }

    $wire->templates->save($template);
    $r = ['success' => true, 'changed' => true, 'changes' => $changes];
    if ($missing) $r['missingFields'] = $missing;
    return $r;
}

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Parse request args array into the flags format CommandRouter expects.
 *
 * The CLI receives args like: ['--pretty', '--include=files', '/about/']
 * This function converts them to the same associative array the CLI produces.
 *
 * @param array $args Array of CLI-style arguments and flags
 * @return array Parsed flags with _positional key for non-flag args
 */
function parseRemoteArgs(array $args): array
{
    $flags = [
        'format'  => 'json',
        'pretty'  => false,
        'include' => [],
    ];
    $positional = [];

    foreach ($args as $arg) {
        if (!is_string($arg)) {
            continue;
        }

        if (strpos($arg, '--') === 0) {
            $arg = substr($arg, 2);

            if (strpos($arg, '=') !== false) {
                [$key, $value] = explode('=', $arg, 2);
                if ($key === 'include') {
                    $flags['include'][] = $value;
                } else {
                    $flags[$key] = $value;
                }
            } else {
                $flags[$arg] = true;
            }
        } else {
            $positional[] = $arg;
        }
    }

    $flags['_positional'] = $positional;
    return $flags;
}
