<?php
// <Jakub Śledzikowski | jsledzikowski.web@gmail.com | jsle.eu | MIT>
function agent_config(): array {
 static $config = null;
 if ($config !== null) return $config;
 $raw = @file_get_contents(__DIR__ . '/config/config.json');
 $config = json_decode($raw ?: '', true);
 if (!is_array($config)) throw new RuntimeException('Invalid config/config.json.');
 return $config;
}

function config_value(string $path, mixed $default = null): mixed {
 $value = agent_config();
 foreach (explode('.', $path) as $key) {
  if (!is_array($value) || !array_key_exists($key, $value)) return $default;
  $value = $value[$key];
 }
 return $value;
}

function server_env(string $name): string {
 $envFile = __DIR__ . '/.env';
 if (is_file($envFile) && is_readable($envFile)) {
  foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
   $line = trim($line);
   if ($line === '' || $line[0] === '#') continue;
   $parts = explode('=', $line, 2);
   if (count($parts) !== 2 || trim($parts[0]) !== $name) continue;
   return trim(trim($parts[1]), " \t\"'");
  }
 }
 return trim((string)($_SERVER[$name] ?? getenv($name) ?: ''));
}

function request_is_https(): bool {
 if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
 $proxy = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
 $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
 return $proxy === 'https' && filter_var($remote, FILTER_VALIDATE_IP) !== false && filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

$config = agent_config();
$projectsDir = __DIR__ . '/' . ($config['paths']['projects'] ?? 'projects');
$convDir = __DIR__ . '/' . ($config['paths']['conversations'] ?? 'conversations');
$maxToolRounds = (int)($config['limits']['max_tool_rounds'] ?? 12);
$publicHtml = realpath(__DIR__ . '/' . ($config['paths']['public_html'] ?? '..')) ?: __DIR__;
$baseUrl = (string)($config['urls']['base'] ?? '');
$agentBase = (string)($config['urls']['agent'] ?? '');

if (!is_dir($projectsDir)) mkdir($projectsDir, 0755, true);
if (!is_dir($convDir)) mkdir($convDir, 0755, true);

function json_out($d) { header("Content-Type: application/json; charset=utf-8"); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function json_err($m, $c = 400) { http_response_code($c); json_out(["error" => $m]); }

function sanitize_name($n) {
    $n = preg_replace("/[^a-zA-Z0-9_.-]/", "", trim($n));
    if ($n === "." || $n === "..") return "";
    return substr($n, 0, 64);
}

function normalize_rel($rel) {
    $rel = str_replace("\\", "/", (string)$rel);
    $rel = preg_replace("#/+#", "/", $rel);
    $parts = [];
    foreach (explode("/", $rel) as $seg) {
        if ($seg === "" || $seg === "." || $seg === "..") continue;
        $parts[] = $seg;
    }
    return implode("/", $parts);
}

function path_in_base($base, $path) {
    $base = rtrim($base, DIRECTORY_SEPARATOR);
    if ($base === "") $base = DIRECTORY_SEPARATOR;
    return $path === $base || str_starts_with($path, $base . ( $base === DIRECTORY_SEPARATOR ? "" : DIRECTORY_SEPARATOR));
}

function conv_key($project, $extpath) {
    $extpath = normalize_rel($extpath);
    if ($extpath !== "") return "e:" . $extpath;
    return "p:" . sanitize_name((string)$project);
}

function conv_file($convDir, $key) {
    return $convDir . "/" . sha1($key) . ".json";
}

function conv_load($convDir, $key) {
    $f = conv_file($convDir, $key);
    if (!is_file($f)) return [];
    $raw = @file_get_contents($f);
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function conversation_options(array $options = []): array {
 $limits = config_value('limits', []);
 $maxTools = max(1, min((int)($options['max_tools_per_round'] ?? ($limits['max_tools_per_round'] ?? 8)), (int)($limits['max_tools_per_round_cap'] ?? 32)));
 $maxTokens = max(1, min((int)($options['max_tokens'] ?? ($limits['max_tokens'] ?? 16384)), (int)($limits['max_tokens_cap'] ?? 32768)));
 $temperature = $options['temperature'] ?? ($limits['temperature'] ?? null);
 $temperature = is_numeric($temperature) ? max(0, min((float)$temperature, 2)) : null;
 $instructions = trim((string)($options['instructions'] ?? ''));
 if (mb_strlen($instructions) > 8000) $instructions = mb_substr($instructions, 0, 8000);
 return ['max_tools_per_round' => $maxTools, 'max_tokens' => $maxTokens, 'temperature' => $temperature, 'instructions' => $instructions];
}

function usage_totals(array $turns): array {
 $total = ['input' => 0, 'output' => 0, 'cached' => 0];
 foreach ($turns as $turn) foreach (($turn['rounds'] ?? []) as $round) {
  $usage = $round['usage'] ?? [];
  $total['input'] += (int)($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
  $total['output'] += (int)($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
  $details = $usage['prompt_tokens_details'] ?? $usage['input_tokens_details'] ?? [];
  $total['cached'] += (int)($details['cached_tokens'] ?? $usage['prompt_cache_hit_tokens'] ?? $usage['cached_tokens'] ?? 0);
 }
 return $total;
}

function conv_save($convDir, $key, $turns) {
    $f = conv_file($convDir, $key);
    $tmp = $f . ".tmp." . getmypid();
    $raw = json_encode($turns, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($raw === false) return;
    if (file_put_contents($tmp, $raw) === false) return;
    if (is_file($f)) @rename($f, $f . ".bak");
    if (!@rename($tmp, $f)) {
        @unlink($tmp);
        if (is_file($f . ".bak")) @rename($f . ".bak", $f);
    } else {
        @unlink($f . ".bak");
    }
}

function resolve_project($name, $dir) {
    $name = sanitize_name($name);
    if ($name === "") json_err("no project");
    $path = $dir . "/" . $name;
    if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) json_err("cannot create project");
    $base = realpath($dir) ?: $dir;
    $real = realpath($path);
    if ($real === false || ($real !== $base && strpos($real, $base . "/") !== 0)) json_err("invalid project");
    return $real;
}

function resolve_workdir($project, $extpath, $projectsDir, $publicHtml) {
    if ($extpath !== "") {
        $extpath = normalize_rel($extpath);
        if ($extpath === "") json_err("invalid external path");
        $publicReal = realpath($publicHtml);
        $real = $publicReal === false ? false : realpath($publicReal . "/" . $extpath);
        if ($real === false || !path_in_base($publicReal, $real) || !is_dir($real)) json_err("invalid external path");
        return $real;
    }
    return resolve_project($project, $projectsDir);
}

function safe_path($base, $rel, $createParent = false) {
    $rel = normalize_rel($rel);
    if ($rel === "" || $rel === ".") return null;
    $baseReal = realpath($base);
    if ($baseReal === false || !is_dir($baseReal) || is_link($base)) return null;
    $parts = explode("/", $rel);
    $name = array_pop($parts);
    $cursor = $baseReal;
    foreach ($parts as $segment) {
        $next = $cursor . "/" . $segment;
        $stat = @lstat($next);
        if ($stat !== false) {
            if ((($stat["mode"] & 0170000) === 0120000) || !is_dir($next)) return null;
        } elseif ($createParent) {
            if (!mkdir($next, 0755) && !is_dir($next)) return null;
        } else return null;
        $resolved = realpath($next);
        if ($resolved === false || !path_in_base($baseReal, $resolved) || is_link($next)) return null;
        $cursor = $resolved;
    }
    $target = $cursor . "/" . $name;
    $stat = @lstat($target);
    if ($stat !== false && (($stat["mode"] & 0170000) === 0120000)) return null;
    return $target;
}

function scan_tree($dir, $base) {
    $out = [];
    $items = @scandir($dir);
    if (!$items) return $out;
    foreach ($items as $i) {
        if ($i[0] === ".") continue;
        $full = $dir . "/" . $i;
        if (is_link($full)) continue;
        $rel = ltrim(substr($full, strlen($base)), "/");
        if (is_dir($full)) {
            $out[] = ["path" => $rel, "type" => "dir", "children" => scan_tree($full, $base)];
        } elseif (is_file($full)) {
            $out[] = ["path" => $rel, "type" => "file", "size" => @filesize($full) ?: 0];
        }
    }
    return $out;
}

function scan_tree_limited($dir, $base, int $maxEntries, int $maxDepth): array {
    $state = ['entries' => 0, 'truncated' => false, 'omitted_directories' => []];
    $walk = function ($current, $depth) use (&$walk, $base, $maxEntries, $maxDepth, &$state) {
        if ($depth > $maxDepth) {
            $state['truncated'] = true;
            $state['omitted_directories'][] = ltrim(substr($current, strlen($base)), '/');
            return [];
        }
        $out = [];
        $items = @scandir($current);
        if ($items === false) return $out;
        foreach ($items as $item) {
            if ($item[0] === '.') continue;
            if ($state['entries'] >= $maxEntries) {
                $state['truncated'] = true;
                $state['omitted_directories'][] = ltrim(substr($current, strlen($base)), '/');
                break;
            }
            $full = $current . '/' . $item;
            if (is_link($full)) continue;
            $rel = ltrim(substr($full, strlen($base)), '/');
            $state['entries']++;
            if (is_dir($full)) {
                $out[] = ['path' => $rel, 'type' => 'dir', 'children' => $walk($full, $depth + 1)];
            } elseif (is_file($full)) {
                $out[] = ['path' => $rel, 'type' => 'file', 'size' => @filesize($full) ?: 0];
            }
        }
        return $out;
    };
    $entries = $walk($dir, 0);
    $state['omitted_directories'] = array_values(array_unique(array_filter($state['omitted_directories'], fn($path) => $path !== '')));
    return ['entries' => $entries, 'returned_entries' => $state['entries'], 'max_entries' => $maxEntries, 'max_depth' => $maxDepth, 'truncated' => $state['truncated'], 'omitted_directories' => $state['omitted_directories']];
}

function tool_search_dir($base, $rel) {
    if ($rel === null || $rel === '') return $base;
    $dir = safe_path($base, $rel);
    return $dir && is_dir($dir) ? $dir : null;
}

function scan_files($dir, $base, $callback) {
    $items = @scandir($dir);
    if ($items === false) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || $item[0] === '.') continue;
        $file = $dir . '/' . $item;
        if (is_link($file)) continue;
        if (is_dir($file)) {
            scan_files($file, $base, $callback);
            continue;
        }
        if (is_file($file)) $callback($file, ltrim(substr($file, strlen($base)), '/'));
    }
}

function tool_result_json($data) {
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

function tool_limit_bytes(): int {
    return max(1024, min(1024 * 1024, (int)config_value('limits.max_tool_result_bytes', 24576)));
}

function multi_read_limit_bytes(): int {
    return max(4096, min(4 * 1024 * 1024, (int)config_value('limits.max_multi_read_result_bytes', 524288)));
}

function utf8_prefix(string $value, int $bytes): string {
    $value = substr($value, 0, $bytes);
    while ($value !== '' && !preg_match('//u', $value)) $value = substr($value, 0, -1);
    return $value;
}

function tool_text_result(string $path, string $content, int $totalBytes, bool $truncated, array $next = []): string {
    return tool_result_json(['path' => $path, 'content' => $content, 'returned_bytes' => strlen($content), 'total_bytes' => $totalBytes, 'truncated' => $truncated, 'next' => $next ?: null]);
}

function tool_collection_result(string $key, array $items, array $meta, string $nextAction): string {
    $total = count($items);
    do {
        $data = array_merge([$key => $items, 'returned' => count($items), 'truncated' => count($items) < $total], $meta);
        if ($data['truncated']) $data['next_action'] = $nextAction;
        $encoded = tool_result_json($data);
        if (strlen($encoded) <= tool_limit_bytes() || !$items) return $encoded;
        array_pop($items);
    } while (true);
}

function multi_read_files_result(string $base, mixed $paths): string {
    $all = $paths === 'all';
    if (!$all && (!is_array($paths) || count($paths) < 1 || count($paths) > 24)) {
        return 'error: paths must be the string all or an array containing 1–24 paths';
    }

    $requested = [];
    $invalid = [];
    if ($all) {
        scan_files($base, $base, function ($file, $rel) use (&$requested) { $requested[] = $rel; });
    } else {
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') { $invalid[] = (string)$path; continue; }
            $rel = normalize_rel($path);
            if ($rel === '') { $invalid[] = $path; continue; }
            $requested[] = $rel;
        }
        $requested = array_values(array_unique($requested));
    }
    sort($requested, SORT_STRING);

    $budget = multi_read_limit_bytes();
    $used = 0;
    $files = [];
    $omitted = [];
    $skipped = [];
    foreach ($requested as $rel) {
        $file = safe_path($base, $rel);
        if (!$file || !is_file($file)) { $invalid[] = $rel; continue; }
        $size = @filesize($file);
        if ($size === false) { $skipped[] = ['path' => $rel, 'reason' => 'cannot stat file']; continue; }
        if ($size > 5 * 1024 * 1024) { $skipped[] = ['path' => $rel, 'reason' => 'file exceeds 5 MiB']; continue; }
        $content = @file_get_contents($file);
        if ($content === false) { $skipped[] = ['path' => $rel, 'reason' => 'cannot read file']; continue; }
        if (!preg_match('//u', $content)) { $skipped[] = ['path' => $rel, 'reason' => 'not valid UTF-8 text']; continue; }
        $encodedSize = strlen(tool_result_json(['path' => $rel, 'content' => $content])) + 1;
        if ($used + $encodedSize > $budget) { $omitted[] = $rel; continue; }
        $files[] = ['path' => $rel, 'content' => $content, 'bytes' => $size];
        $used += $encodedSize;
    }

    $omittedCount = count($omitted);
    $skippedCount = count($skipped);
    if ($omittedCount > 500) $omitted = array_slice($omitted, 0, 500);
    if ($skippedCount > 100) $skipped = array_slice($skipped, 0, 100);
    return tool_result_json([
        'mode' => $all ? 'all' : 'paths',
        'files' => $files,
        'returned_files' => count($files),
        'requested_files' => count($requested),
        'returned_bytes' => $used,
        'snapshot_budget_bytes' => $budget,
        'truncated' => $omitted !== [],
        'omitted_paths' => $omitted,
        'omitted_count' => $omittedCount,
        'invalid_paths' => array_values(array_unique($invalid)),
        'skipped_files' => $skipped,
        'skipped_count' => $skippedCount,
        'next_action' => $omitted !== [] ? 'Use multi_read_files with up to 24 omitted paths, or read_file_fragment for a large file.' : null,
    ]);
}

function get_project_list($dir) {
    $out = [];
    $items = @scandir($dir);
    if ($items === false) return [];
    foreach ($items as $i) { if ($i[0] !== "." && is_dir($dir . "/" . $i)) $out[] = $i; }
    sort($out, SORT_STRING);
    return $out;
}

function rm_rf($dir) {
    $dir = rtrim($dir, "/\\");
    if ($dir === "" || !is_dir($dir)) return false;
    $items = @scandir($dir);
    if ($items === false) return false;
    $ok = true;
    foreach ($items as $i) {
        if ($i === "." || $i === "..") continue;
        $p = $dir . "/" . $i;
        if (is_link($p) || is_file($p)) $ok = @unlink($p) && $ok;
        elseif (is_dir($p)) $ok = rm_rf($p) && $ok;
    }
    return @rmdir($dir) && $ok;
}

function tool_defs() {
 $raw = @file_get_contents(__DIR__ . '/config/tools.json');
 $tools = json_decode($raw ?: '', true);
 if (!is_array($tools)) return [];
 foreach ($tools as &$tool) {
  if (!is_array($tool['function'] ?? null)) continue;
  $parameters = $tool['function']['parameters'] ?? null;
  if (!is_array($parameters) || ($parameters['type'] ?? null) !== 'object') {
   $parameters = ['type' => 'object', 'properties' => (object)[]];
  }
  if (!isset($parameters['properties']) || !is_array($parameters['properties'])) $parameters['properties'] = (object)[];
  elseif ($parameters['properties'] === []) $parameters['properties'] = (object)[];
  if (!isset($parameters['required']) || !is_array($parameters['required'])) $parameters['required'] = [];
  $tool['function']['parameters'] = $parameters;
 }
 unset($tool);
 return $tools;
}

function run_tool($base, $name, $a) {
    switch ($name) {
        case "list_files":
            $maxEntries = max(10, min(10000, (int)config_value('limits.max_list_files_entries', 500)));
            $maxDepth = max(1, min(64, (int)config_value('limits.max_list_files_depth', 8)));
            while (true) {
                $tree = scan_tree_limited($base, $base, $maxEntries, $maxDepth);
                $encoded = tool_result_json($tree);
                if (strlen($encoded) <= tool_limit_bytes() || $maxEntries <= 10) return $encoded;
                $maxEntries = max(10, intdiv($maxEntries, 2));
            }
        case "find_files":
            $pattern = trim((string)($a['pattern'] ?? ''));
            if ($pattern === '' || strlen($pattern) > 500) return 'error: pattern must contain 1–500 characters';
            $dir = tool_search_dir($base, $a['path'] ?? '');
            if (!$dir) return 'error: invalid search path';
            $limit = max(1, min(200, (int)($a['max_results'] ?? 100)));
            $matches = [];
            scan_files($dir, $base, function ($file, $rel) use (&$matches, $pattern, $limit) {
                if (count($matches) >= $limit) return;
                if (fnmatch($pattern, $rel, FNM_PATHNAME) || fnmatch($pattern, basename($rel))) $matches[] = $rel;
            });
            sort($matches, SORT_STRING);
            return tool_collection_result('matches', $matches, ['limit' => $limit], 'Narrow the path or pattern before requesting more files.');
        case "search_files":
            $query = (string)($a['query'] ?? '');
            if ($query === '' || strlen($query) > 500) return 'error: query must contain 1–500 characters';
            $dir = tool_search_dir($base, $a['path'] ?? '');
            if (!$dir) return 'error: invalid search path';
            $regex = !empty($a['regex']);
            $pattern = null;
            if ($regex) {
                $pattern = '~(*LIMIT_MATCH=100000)(*LIMIT_DEPTH=10000)' . str_replace('~', '\\~', $query) . '~';
                if (@preg_match($pattern, '') === false) return 'error: invalid regular expression';
            }
            $limit = max(1, min(200, (int)($a['max_results'] ?? 100)));
            $matches = [];
            scan_files($dir, $base, function ($file, $rel) use (&$matches, $query, $regex, $pattern, $limit) {
                if (count($matches) >= $limit || @filesize($file) > 5 * 1024 * 1024) return;
                $handle = @fopen($file, 'rb');
                if (!$handle) return;
                $lineNumber = 0;
                while (!feof($handle) && count($matches) < $limit) {
                    $line = fgets($handle);
                    if ($line === false) break;
                    $lineNumber++;
                    if (str_contains($line, "\0")) break;
                    $found = $regex ? @preg_match($pattern, $line) === 1 : str_contains($line, $query);
                    if (!$found) continue;
                    $excerpt = rtrim($line, "\r\n");
                    if (strlen($excerpt) > 500) $excerpt = substr($excerpt, 0, 500) . '…';
                    $matches[] = ['path' => $rel, 'line' => $lineNumber, 'text' => $excerpt];
                }
                fclose($handle);
            });
            return tool_collection_result('matches', $matches, ['limit' => $limit], 'Narrow the path or query, then use read_file_fragment for a matching location.');
        case "read_file":
            $f = safe_path($base, $a["path"] ?? "");
            if (!$f || !is_file($f)) return "error: not found: " . ($a["path"] ?? "");
            $c = file_get_contents($f);
            if ($c === false) return "error: cannot read: " . ($a["path"] ?? "");
            $len = strlen($c);
            if (!preg_match('//u', $c)) return 'error: file is not valid UTF-8 text';
            $limit = tool_limit_bytes();
            $truncated = $len > $limit;
            $head = $truncated ? utf8_prefix($c, $limit) : $c;
            return tool_text_result((string)$a['path'], $head, $len, $truncated, $truncated ? ['tool' => 'read_file_fragment', 'path' => (string)$a['path'], 'start_byte' => strlen($head), 'end_byte' => min($len - 1, strlen($head) + $limit - 1)] : []);
        case "multi_read_files":
            return multi_read_files_result($base, $a['paths'] ?? null);
        case "read_file_fragment":
            $f = safe_path($base, $a['path'] ?? '');
            if (!$f || !is_file($f)) return 'error: not found: ' . ($a['path'] ?? '');
            $hasLines = isset($a['start_line']) || isset($a['end_line']);
            $hasBytes = isset($a['start_byte']) || isset($a['end_byte']);
            if ($hasLines === $hasBytes) return 'error: provide exactly one complete line range or byte range';
            if ($hasLines) {
                $start = (int)($a['start_line'] ?? 0);
                $end = (int)($a['end_line'] ?? 0);
                if ($start < 1 || $end < $start || $end - $start >= 10000) return 'error: invalid line range';
                $handle = @fopen($f, 'rb');
                if (!$handle) return 'error: cannot read: ' . ($a['path'] ?? '');
                $lineNumber = 0;
                $content = '';
                $truncated = false;
                while (!feof($handle)) {
                    $line = fgets($handle);
                    if ($line === false) break;
                    $lineNumber++;
                    if ($lineNumber < $start) continue;
                    if ($lineNumber > $end) break;
                    $remaining = tool_limit_bytes() - strlen($content);
                    if ($remaining <= 0) { $truncated = true; break; }
                    if (strlen($line) > $remaining) { $content .= substr($line, 0, $remaining); $truncated = true; break; }
                    $content .= $line;
                }
                fclose($handle);
                if (!preg_match('//u', $content)) return 'error: file fragment is not valid UTF-8 text';
                return tool_text_result((string)$a['path'], $content, @filesize($f) ?: 0, $truncated, $truncated ? ['tool' => 'read_file_fragment', 'path' => (string)$a['path'], 'start_line' => $lineNumber, 'end_line' => $end] : []);
            }
            $start = (int)($a['start_byte'] ?? -1);
            $end = (int)($a['end_byte'] ?? -1);
            $limit = tool_limit_bytes();
            if ($start < 0 || $end < $start || $end - $start >= $limit) return 'error: invalid byte range';
            $handle = @fopen($f, 'rb');
            if (!$handle || fseek($handle, $start) !== 0) return 'error: cannot read: ' . ($a['path'] ?? '');
            $content = fread($handle, $end - $start + 1);
            fclose($handle);
            if ($content === false) return 'error: cannot read: ' . ($a['path'] ?? '');
            if (!preg_match('//u', $content)) return 'error: file fragment is not valid UTF-8 text';
            $total = @filesize($f) ?: 0;
            $truncated = $end < $total - 1;
            return tool_text_result((string)$a['path'], $content, $total, $truncated, $truncated ? ['tool' => 'read_file_fragment', 'path' => (string)$a['path'], 'start_byte' => $end + 1, 'end_byte' => min($total - 1, $end + $limit)] : []);
        case "write_file":
            $f = safe_path($base, $a["path"] ?? "", true);
            if (!$f) return "error: invalid path";
            if (file_put_contents($f, $a["content"] ?? "") === false) return "error: cannot write: " . ($a["path"] ?? "");
            return "ok: " . strlen($a["content"] ?? "") . " bytes → " . $a["path"];
        case "delete_file":
            $f = safe_path($base, $a["path"] ?? "");
            if (!$f || !file_exists($f)) return "error: not found";
            if (is_dir($f)) { if (!rm_rf($f)) return "error: cannot delete dir"; return "ok: deleted dir " . $a["path"]; }
            if (!unlink($f)) return "error: cannot delete: " . ($a["path"] ?? "");
            return "ok: deleted " . $a["path"];
        case "create_directory":
            $rel = normalize_rel($a["path"] ?? "");
            if ($rel === "" || $rel === ".") return "error: invalid path";
            $target = safe_path($base, $rel, true);
            if (!$target || (!is_dir($target) && !mkdir($target, 0755))) return "error: invalid path";
            return "ok: " . $a["path"];
        case "rename_file":
            $old = safe_path($base, $a["old_path"] ?? "");
            $new = safe_path($base, $a["new_path"] ?? "", true);
            if (!$old || !$new || !file_exists($old)) return "error: invalid";
            if (!rename($old, $new)) return "error: cannot rename";
            return "ok: " . $a["old_path"] . " → " . $a["new_path"];
        case "replace_in_file":
            $f = safe_path($base, $a["path"] ?? "");
            if (!$f || !is_file($f)) return "error: not found: " . ($a["path"] ?? "");
            $old = $a["old_str"] ?? "";
            $new = $a["new_str"] ?? "";
            if ($old === "") return "error: old_str cannot be empty";
            $c = file_get_contents($f);
            $count = substr_count($c, $old);
            if ($count === 0) return "error: old_str not found in file. Make sure it matches exactly (whitespace, indentation, newlines).";
            if ($count > 1) return "error: old_str appears " . $count . " times — must be unique. Include more surrounding context.";
            $c = substr_replace($c, $new, strpos($c, $old), strlen($old));
            if (file_put_contents($f, $c) === false) return "error: cannot write: " . ($a["path"] ?? "");
            return "ok: " . strlen($old) . " → " . strlen($new) . " bytes in " . $a["path"];
        default:
            return "error: unknown tool: " . $name;
    }
}

function sse($ev, $d) {
    echo "event: " . $ev . "\ndata: " . json_encode($d, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level() > 0) ob_flush();
    flush();
}

function serve_icon($size, $maskable) {
    $size = max(48, min(1024, $size));
    header("Content-Type: image/png");
    header("Cache-Control: public, max-age=31536000, immutable");

    if (!function_exists("imagecreatetruecolor")) {
        echo base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=");
        return;
    }

    $bg = [0x14, 0x14, 0x13];
    $accent = [0xd9, 0x77, 0x57];

    $glyph = imagecreatetruecolor(64, 64);
    $bgc = imagecolorallocate($glyph, $bg[0], $bg[1], $bg[2]);
    $fgc = imagecolorallocate($glyph, $accent[0], $accent[1], $accent[2]);
    imagefill($glyph, 0, 0, $bgc);
    $text = "P";
    $fw = imagefontwidth(5) * strlen($text);
    $fh = imagefontheight(5);
    imagestring($glyph, 5, (int)((64 - $fw) / 2), (int)((64 - $fh) / 2) - 1, $text, $fgc);

    $img = imagecreatetruecolor($size, $size);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    $pad = $maskable ? (int)($size * 0.30) : (int)($size * 0.12);
    $bgFill = imagecolorallocate($img, $bg[0], $bg[1], $bg[2]);
    imagefilledrectangle($img, 0, 0, $size, $size, $bgFill);
    imagecopyresized($img, $glyph, $pad, $pad, 0, 0, $size - 2 * $pad, $size - 2 * $pad, 64, 64);
    imagedestroy($glyph);

    imagepng($img);
    imagedestroy($img);
}

function serve_service_worker() {
    header("Content-Type: application/javascript; charset=utf-8");
    header("Cache-Control: no-cache");
    echo <<<'ENDSW'
var CACHE = "phpilot-assets-v2";
var MANIFEST_URL = self.registration.scope + "?action=manifest";

self.addEventListener("install", function (e) {
    self.skipWaiting();
    e.waitUntil(
        caches.open(CACHE).then(function (c) {
            return c.add(MANIFEST_URL);
        }).catch(function () {})
    );
});

self.addEventListener("activate", function (e) {
    e.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(keys.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); }));
        }).then(function () { return self.clients.claim(); })
    );
});

ENDSW;
    exit;
}

function agent_session_start() {
    if (session_status() === PHP_SESSION_NONE) {
        $dir = dirname($_SERVER["SCRIPT_NAME"] ?? "/");
        $path = ($dir === "/" || $dir === "" || $dir === "\\") ? "/" : rtrim($dir, "/") . "/";
        session_name("phpilot_sid");
        session_set_cookie_params([
            "lifetime" => 0,
            "path" => $path,
            "secure" => request_is_https(),
            "httponly" => true,
            "samesite" => "Strict"
        ]);
        session_start();
    }
}

function agent_authenticated(): bool {
    return !empty($_SESSION["phpilot_auth"]) && $_SESSION["phpilot_auth"] === true;
}

function agent_public_action($action): bool {
    return in_array($action, ["manifest", "icon", "sw"], true);
}

function agent_csrf_token(): string {
    if (empty($_SESSION["phpilot_csrf"])) $_SESSION["phpilot_csrf"] = bin2hex(random_bytes(24));
    return $_SESSION["phpilot_csrf"];
}

function agent_csrf_verify($token): bool {
    return is_string($token) && $token !== "" && hash_equals($_SESSION["phpilot_csrf"] ?? "", $token);
}

function agent_require_csrf() {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") return;
    if (($_GET["action"] ?? "") === "login") return;
    $token = $_POST["csrf"] ?? ($_SERVER["HTTP_X_CSRF_TOKEN"] ?? "");
    if (!agent_csrf_verify($token)) json_err("invalid csrf token", 403);
}

function agent_require_auth() {
    agent_session_start();
    $action = $_GET["action"] ?? "";
    if ($action === "login" || agent_public_action($action)) {
        return;
    }
    if (!agent_authenticated()) {
        if ($action !== "") {
            json_err("unauthorized", 401);
        }
        serve_login_page();
        exit;
    }
}
