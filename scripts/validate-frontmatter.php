<?php

declare(strict_types=1);

/**
 * Per-connector README frontmatter validator — pure-PHP, no symfony/yaml dep.
 *
 * Mirrors location-php's `scripts/validate-frontmatter.php` with a
 * notifications-scope schema (channel instead of operation, novuProviderId,
 * tier, tokenCacheHookSupported). Reads every `src/Providers/<Provider>/README.md`,
 * parses the single leading YAML frontmatter block, validates against the schema
 * in `schemas/connector-readme-schema.yaml`. Exits 0 on success, 1 with
 * line-prefixed errors on any failure.
 *
 * Pure-PHP — no Composer dev-deps beyond what the project already wires (no
 * symfony/yaml). The minimal parser handles the frontmatter shape this scope
 * accepts: scalar keys, nested objects (2-space indent), arrays of scalars,
 * quoted/unquoted strings, booleans, integers, null/~, and YAML literal-block
 * scalar (`|`) for `notes_passthrough`.
 *
 * Usage:
 *   php scripts/validate-frontmatter.php
 *   php scripts/validate-frontmatter.php --expected-providers 34
 */

$repoRoot = dirname(__DIR__);
$providersDir = $repoRoot . '/src/Providers';

$args = array_slice($argv, 1);

/** Read a `--flag <int>` CLI arg with a fallback. */
function readArg(array $args, string $name, int $fallback): int
{
    $idx = array_search($name, $args, true);
    if ($idx === false || !isset($args[$idx + 1])) {
        return $fallback;
    }
    $value = $args[$idx + 1];
    return is_numeric($value) ? (int) $value : $fallback;
}

$EXPECTED_PROVIDERS = readArg($args, '--expected-providers', 35);

// --- minimal YAML parser scoped to the frontmatter shape we accept ---

/** @return mixed */
function coerceScalar(string $s): mixed
{
    if ($s === '' || $s === 'null' || $s === '~') {
        return null;
    }
    if ($s === 'true') {
        return true;
    }
    if ($s === 'false') {
        return false;
    }
    if (preg_match('/^-?\d+$/', $s) === 1) {
        return (int) $s;
    }
    if (strlen($s) >= 2) {
        $first = $s[0];
        $last = $s[strlen($s) - 1];
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            return substr($s, 1, -1);
        }
    }
    return $s;
}

/**
 * @return array{0: array<string, mixed>, 1: int}
 */
function parseFrontmatter(string $text): array
{
    /** @var list<string> $lines */
    $lines = explode("\n", $text);
    $i = 0;
    $root = [];

    /**
     * @return array<string, mixed>
     */
    $parseBlock = function (int $indent) use (&$lines, &$i, &$parseBlock, &$parseArray): array {
        $obj = [];
        while ($i < count($lines)) {
            $raw = $lines[$i];
            if (trim($raw) === '' || str_starts_with(trim($raw), '#')) {
                $i++;
                continue;
            }
            preg_match('/^ */', $raw, $m);
            $currentIndent = strlen($m[0]);
            if ($currentIndent < $indent) {
                return $obj;
            }
            if ($currentIndent > $indent) {
                throw new RuntimeException(sprintf('Unexpected indent at line %d: "%s"', $i + 1, $raw));
            }
            $line = substr($raw, $indent);
            if (str_starts_with($line, '- ')) {
                return $obj;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                throw new RuntimeException(sprintf('Expected key:value at line %d: "%s"', $i + 1, $raw));
            }
            $key = trim(substr($line, 0, $colon));
            $rest = trim(substr($line, $colon + 1));
            $i++;

            if ($rest === '') {
                $j = $i;
                while ($j < count($lines) && trim($lines[$j]) === '') {
                    $j++;
                }
                if ($j >= count($lines)) {
                    $obj[$key] = null;
                    continue;
                }
                $peek = $lines[$j];
                preg_match('/^ */', $peek, $pm);
                $peekIndent = strlen($pm[0]);
                if ($peekIndent <= $indent) {
                    $obj[$key] = null;
                    continue;
                }
                $peekLine = substr($peek, $peekIndent);
                if (str_starts_with($peekLine, '- ')) {
                    $obj[$key] = $parseArray($peekIndent);
                } else {
                    $obj[$key] = $parseBlock($peekIndent);
                }
            } elseif ($rest === '|') {
                $blockIndent = $indent + 2;
                $collected = [];
                while ($i < count($lines)) {
                    $bl = $lines[$i];
                    if (trim($bl) === '') {
                        $collected[] = '';
                        $i++;
                        continue;
                    }
                    preg_match('/^ */', $bl, $bm);
                    $blIndent = strlen($bm[0]);
                    if ($blIndent < $blockIndent) {
                        break;
                    }
                    $collected[] = substr($bl, $blockIndent);
                    $i++;
                }
                $obj[$key] = rtrim(implode("\n", $collected), "\n");
            } elseif (str_starts_with($rest, '[') && str_ends_with($rest, ']')) {
                $inner = trim(substr($rest, 1, -1));
                if ($inner === '') {
                    $obj[$key] = [];
                } else {
                    $parts = explode(',', $inner);
                    $obj[$key] = array_map(fn(string $s) => coerceScalar(trim($s)), $parts);
                }
            } else {
                $obj[$key] = coerceScalar($rest);
            }
        }
        return $obj;
    };

    /**
     * @return list<mixed>
     */
    $parseArray = function (int $indent) use (&$lines, &$i): array {
        $arr = [];
        while ($i < count($lines)) {
            $raw = $lines[$i];
            if (trim($raw) === '' || str_starts_with(trim($raw), '#')) {
                $i++;
                continue;
            }
            preg_match('/^ */', $raw, $m);
            $currentIndent = strlen($m[0]);
            if ($currentIndent < $indent) {
                return $arr;
            }
            $line = substr($raw, $indent);
            if (!str_starts_with($line, '- ')) {
                return $arr;
            }
            $item = trim(substr($line, 2));
            $arr[] = coerceScalar($item);
            $i++;
        }
        return $arr;
    };

    while ($i < count($lines)) {
        $raw = $lines[$i];
        if (trim($raw) === '' || str_starts_with(trim($raw), '#')) {
            $i++;
            continue;
        }
        preg_match('/^ */', $raw, $m);
        $indent = strlen($m[0]);
        $root = array_merge($root, $parseBlock($indent));
        break;
    }
    while ($i < count($lines)) {
        $before = $i;
        $root = array_merge($root, $parseBlock(0));
        if ($i === $before) {
            $i++;
        }
    }
    return [$root, $i];
}

/**
 * Notifications-scope per-connector README has ONE frontmatter block at the top.
 * Extract it (between the leading `---` and the next `---`).
 *
 * @return array{startLine: int, meta: array<string, mixed>}|null
 */
function extractLeadingFrontmatter(string $content, string $file): ?array
{
    /** @var list<string> $lines */
    $lines = explode("\n", $content);
    $i = 0;
    // Skip blank lines / leading title.
    while ($i < count($lines) && trim($lines[$i]) !== '---') {
        // Allow markdown content (# heading, prose) BEFORE the frontmatter block.
        $i++;
    }
    if ($i >= count($lines)) {
        return null;
    }
    $startLine = $i + 1;
    $j = $i + 1;
    while ($j < count($lines) && trim($lines[$j]) !== '---') {
        $j++;
    }
    if ($j >= count($lines)) {
        throw new RuntimeException(sprintf('%s: unterminated frontmatter block starting at line %d', $file, $startLine));
    }
    $yamlText = implode("\n", array_slice($lines, $i + 1, $j - $i - 1));
    [$meta, ] = parseFrontmatter($yamlText);
    return ['startLine' => $startLine, 'meta' => $meta];
}

// --- schema validation (mirrors schemas/connector-readme-schema.yaml) ---
const AUTH_METHODS = [
    'bearer',
    'basic',
    'header',
    'webhook-url',
    'jwt-rs256',
    'jwt-es256',
    'aws-sigv4',
    'custom',
];
const TOKEN_LIFECYCLES = ['opaque-static', 'opaque-rotates', 'jwt-signed', 'sigv4-derived'];
const CHANNELS = ['email', 'sms', 'push', 'chat'];
const REQUIRED_TOP = [
    'providerId',
    'channel',
    'auth',
    'endpoint',
    'versioning',
    'notes_passthrough',
];
// Schema `additionalProperties: false` — every key the schema declares at each
// level. Any key NOT listed is rejected (catches typo'd keys).
const ALLOWED_TOP = [
    'providerId',
    'channel',
    'novuProviderId',
    'auth',
    'endpoint',
    'regionalEndpoints',
    'versioning',
    'tier',
    'rateLimitDocsUrl',
    'retryAfterSurfaced',
    'notes_passthrough',
];
const ALLOWED_AUTH = ['method', 'tokenLifecycle', 'tokenCacheHookSupported'];
const ALLOWED_ENDPOINT = ['default', 'regional'];
const ALLOWED_VERSIONING = ['vendorApiVersion', 'lastVerified'];

/**
 * @param array<string, mixed> $meta
 * @return list<string>
 */
function validate(array $meta, string $file, int $blockLine): array
{
    $errors = [];
    $prefix = $file . ':' . $blockLine;
    foreach (REQUIRED_TOP as $key) {
        if (!array_key_exists($key, $meta) || $meta[$key] === null) {
            $errors[] = sprintf("%s: missing required key '%s'", $prefix, $key);
        }
    }
    // Schema additionalProperties:false — reject unknown top-level keys.
    foreach (array_keys($meta) as $key) {
        if (!in_array($key, ALLOWED_TOP, true)) {
            $errors[] = sprintf("%s: unknown key '%s' (schema forbids additional properties)", $prefix, $key);
        }
    }
    if (array_key_exists('novuProviderId', $meta)
        && $meta['novuProviderId'] !== null
        && !is_string($meta['novuProviderId'])
    ) {
        $errors[] = sprintf('%s: novuProviderId must be a string or null', $prefix);
    }
    if (array_key_exists('rateLimitDocsUrl', $meta)
        && $meta['rateLimitDocsUrl'] !== null
        && !is_string($meta['rateLimitDocsUrl'])
    ) {
        $errors[] = sprintf('%s: rateLimitDocsUrl must be a string or null', $prefix);
    }
    if (array_key_exists('regionalEndpoints', $meta) && $meta['regionalEndpoints'] !== null) {
        if (!is_array($meta['regionalEndpoints'])) {
            $errors[] = sprintf('%s: regionalEndpoints must be an array of strings', $prefix);
        } else {
            foreach ($meta['regionalEndpoints'] as $item) {
                if (!is_string($item)) {
                    $errors[] = sprintf('%s: regionalEndpoints must contain only strings', $prefix);
                    break;
                }
            }
        }
    }
    if (isset($meta['providerId']) && is_string($meta['providerId'])) {
        if (preg_match('/^[a-z][a-z0-9-]*$/', $meta['providerId']) !== 1) {
            $errors[] = sprintf("%s: providerId '%s' must match /^[a-z][a-z0-9-]*$/", $prefix, $meta['providerId']);
        }
    }
    if (isset($meta['channel']) && is_string($meta['channel'])) {
        if (!in_array($meta['channel'], CHANNELS, true)) {
            $errors[] = sprintf("%s: channel '%s' must be one of %s", $prefix, $meta['channel'], implode(', ', CHANNELS));
        }
    }
    if (isset($meta['auth']) && is_array($meta['auth'])) {
        foreach (['method', 'tokenLifecycle'] as $k) {
            if (!array_key_exists($k, $meta['auth'])) {
                $errors[] = sprintf('%s: auth.%s is required', $prefix, $k);
            }
        }
        foreach (array_keys($meta['auth']) as $k) {
            if (!in_array($k, ALLOWED_AUTH, true)) {
                $errors[] = sprintf("%s: unknown key 'auth.%s' (schema forbids additional properties)", $prefix, $k);
            }
        }
        if (isset($meta['auth']['method']) && is_string($meta['auth']['method'])) {
            if (!in_array($meta['auth']['method'], AUTH_METHODS, true)) {
                $errors[] = sprintf("%s: auth.method '%s' must be one of %s", $prefix, $meta['auth']['method'], implode(', ', AUTH_METHODS));
            }
        }
        if (isset($meta['auth']['tokenLifecycle']) && is_string($meta['auth']['tokenLifecycle'])) {
            if (!in_array($meta['auth']['tokenLifecycle'], TOKEN_LIFECYCLES, true)) {
                $errors[] = sprintf("%s: auth.tokenLifecycle '%s' must be one of %s", $prefix, $meta['auth']['tokenLifecycle'], implode(', ', TOKEN_LIFECYCLES));
            }
        }
        if (array_key_exists('tokenCacheHookSupported', $meta['auth']) && !is_bool($meta['auth']['tokenCacheHookSupported'])) {
            $errors[] = sprintf('%s: auth.tokenCacheHookSupported must be a boolean', $prefix);
        }
    }
    if (isset($meta['endpoint']) && is_array($meta['endpoint'])) {
        foreach (array_keys($meta['endpoint']) as $k) {
            if (!in_array($k, ALLOWED_ENDPOINT, true)) {
                $errors[] = sprintf("%s: unknown key 'endpoint.%s' (schema forbids additional properties)", $prefix, $k);
            }
        }
        if (!isset($meta['endpoint']['default'])) {
            $errors[] = sprintf('%s: endpoint.default is required', $prefix);
        } else {
            $default = $meta['endpoint']['default'];
            if (!is_string($default) || preg_match('#^https?://#', $default) !== 1) {
                $errors[] = sprintf("%s: endpoint.default must be an http(s) URL (got '%s')", $prefix, is_scalar($default) ? (string) $default : gettype($default));
            }
        }
        if (array_key_exists('regional', $meta['endpoint']) && $meta['endpoint']['regional'] !== null) {
            if (!is_array($meta['endpoint']['regional'])) {
                $errors[] = sprintf('%s: endpoint.regional must be an array of strings', $prefix);
            } else {
                foreach ($meta['endpoint']['regional'] as $item) {
                    if (!is_string($item)) {
                        $errors[] = sprintf('%s: endpoint.regional must contain only strings', $prefix);
                        break;
                    }
                }
            }
        }
    }
    if (isset($meta['versioning']) && is_array($meta['versioning'])) {
        foreach (array_keys($meta['versioning']) as $k) {
            if (!in_array($k, ALLOWED_VERSIONING, true)) {
                $errors[] = sprintf("%s: unknown key 'versioning.%s' (schema forbids additional properties)", $prefix, $k);
            }
        }
        if (!isset($meta['versioning']['vendorApiVersion'])) {
            $errors[] = sprintf('%s: versioning.vendorApiVersion is required', $prefix);
        } elseif (!is_string($meta['versioning']['vendorApiVersion'])) {
            // Schema declares versioning.vendorApiVersion as a string.
            $errors[] = sprintf('%s: versioning.vendorApiVersion must be a string', $prefix);
        }
        if (!isset($meta['versioning']['lastVerified'])) {
            $errors[] = sprintf('%s: versioning.lastVerified is required', $prefix);
        } else {
            $lv = (string) $meta['versioning']['lastVerified'];
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $lv) !== 1) {
                $errors[] = sprintf("%s: versioning.lastVerified '%s' must be ISO date YYYY-MM-DD", $prefix, $lv);
            }
        }
    }
    if (array_key_exists('tier', $meta) && (!is_int($meta['tier']) || !in_array($meta['tier'], [1, 2], true))) {
        $errors[] = sprintf('%s: tier must be 1 or 2', $prefix);
    }
    if (array_key_exists('retryAfterSurfaced', $meta) && !is_bool($meta['retryAfterSurfaced'])) {
        $errors[] = sprintf('%s: retryAfterSurfaced must be a boolean', $prefix);
    }
    if (array_key_exists('notes_passthrough', $meta) && $meta['notes_passthrough'] !== null && !is_string($meta['notes_passthrough'])) {
        $errors[] = sprintf('%s: notes_passthrough must be a string', $prefix);
    }
    return $errors;
}

/** Map PascalCase directory name to canonical kebab-case provider ID. */
function dirNameToProviderId(string $dir): string
{
    // Hand-coded map for the cases where directory PascalCase doesn't trivially
    // lowercase to the canonical kebab-case providerId.
    $special = [
        'OneSignal'        => 'one-signal',
        'PusherBeams'      => 'pusher-beams',
        'WhatsappBusiness' => 'whatsapp-business',
        'GoogleChat'       => 'google-chat',
        'RocketChat'       => 'rocket-chat',
        'Msteams'          => 'ms-teams',
    ];
    return $special[$dir] ?? strtolower($dir);
}

/**
 * @return list<array{id: string, dir: string, path: string, missing?: bool}>
 */
function listProviderReadmes(string $providersDir): array
{
    $readmes = [];
    if (!is_dir($providersDir)) {
        return $readmes;
    }
    $entries = scandir($providersDir);
    if ($entries === false) {
        return $readmes;
    }
    sort($entries);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $full = $providersDir . '/' . $entry;
        if (!is_dir($full)) {
            continue;
        }
        $readme = $full . '/README.md';
        $id = dirNameToProviderId($entry);
        if (is_file($readme)) {
            $readmes[] = ['id' => $id, 'dir' => $entry, 'path' => $readme];
        } else {
            $readmes[] = ['id' => $id, 'dir' => $entry, 'path' => $readme, 'missing' => true];
        }
    }
    return $readmes;
}

$readmes = listProviderReadmes($providersDir);
$errors = [];

$present = array_values(array_filter($readmes, fn(array $r) => !($r['missing'] ?? false)));
$missing = array_values(array_filter($readmes, fn(array $r) => $r['missing'] ?? false));
foreach ($missing as $m) {
    $errors[] = $m['path'] . ': missing per-connector README';
}

foreach ($present as $r) {
    $content = file_get_contents($r['path']);
    if ($content === false) {
        $errors[] = $r['path'] . ': could not read file';
        continue;
    }
    try {
        $block = extractLeadingFrontmatter($content, $r['path']);
        if ($block === null) {
            $errors[] = $r['path'] . ': contains no YAML frontmatter block';
            continue;
        }
        $errs = validate($block['meta'], $r['path'], $block['startLine']);
        $errors = array_merge($errors, $errs);
        if (isset($block['meta']['providerId']) && is_string($block['meta']['providerId']) && $block['meta']['providerId'] !== $r['id']) {
            $errors[] = sprintf(
                "%s:%d: providerId '%s' does not match directory '%s' (expected '%s')",
                $r['path'],
                $block['startLine'],
                $block['meta']['providerId'],
                $r['dir'],
                $r['id'],
            );
        }
    } catch (Throwable $e) {
        $errors[] = $r['path'] . ': ' . $e->getMessage();
    }
}

$totalProviders = count($present);
if ($totalProviders !== $EXPECTED_PROVIDERS) {
    $errors[] = sprintf('coverage: found %d per-connector README(s); expected %d', $totalProviders, $EXPECTED_PROVIDERS);
}

if (count($errors) > 0) {
    foreach ($errors as $e) {
        fwrite(STDERR, $e . "\n");
    }
    fwrite(STDERR, sprintf("\n%d error(s) — frontmatter validation failed.\n", count($errors)));
    exit(1);
}

printf(
    "OK — %d per-connector README(s) validated against schemas/connector-readme-schema.yaml\n",
    $totalProviders,
);
