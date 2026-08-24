<?php
// <Jakub Śledzikowski | jsledzikowski.web@gmail.com | jsle.eu | MIT>
declare(strict_types=1);

$configDir = dirname(__DIR__);
$root = dirname($configDir);
$existing = is_file($configDir . '/config.json') ? json_decode((string)@file_get_contents($configDir . '/config.json'), true) : null;
if (is_array($existing) && ($existing['installed'] ?? false) === true) { http_response_code(404); exit('Installer is disabled.'); }
function ih(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function is_subdir(string $path, string $base): bool { $path = rtrim(realpath($path) ?: '', DIRECTORY_SEPARATOR); $base = rtrim(realpath($base) ?: '', DIRECTORY_SEPARATOR); return $path !== '' && $base !== '' && ($path === $base || str_starts_with($path, $base . DIRECTORY_SEPARATOR)); }
function normalize_public_url(string $value): string {
 $url = filter_var(trim($value), FILTER_VALIDATE_URL);
 if ($url === false || !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null || parse_url($url, PHP_URL_QUERY) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null) return '';
 return rtrim($url, '/') . '/';
}
function atomic_install_write(string $target, string $content, int $mode): bool { $tmp = $target . '.install.' . bin2hex(random_bytes(8)); if (@file_put_contents($tmp, $content, LOCK_EX) === false || !@chmod($tmp, $mode) || !@rename($tmp, $target)) { @unlink($tmp); return false; } return true; }
function atomic_install_pair(string $envTarget, string $envContent, string $configTarget, string $configContent): bool {
 $envTmp = $envTarget . '.install.' . bin2hex(random_bytes(8)); $configTmp = $configTarget . '.install.' . bin2hex(random_bytes(8));
 if (@file_put_contents($envTmp, $envContent, LOCK_EX) === false || !@chmod($envTmp, 0600) || @file_put_contents($configTmp, $configContent, LOCK_EX) === false || !@chmod($configTmp, 0644)) { @unlink($envTmp); @unlink($configTmp); return false; }
 $envBackup = is_file($envTarget) ? $envTarget . '.bak.' . bin2hex(random_bytes(8)) : ''; $configBackup = is_file($configTarget) ? $configTarget . '.bak.' . bin2hex(random_bytes(8)) : '';
 if (($envBackup !== '' && !@rename($envTarget, $envBackup)) || ($configBackup !== '' && !@rename($configTarget, $configBackup)) || !@rename($envTmp, $envTarget) || !@rename($configTmp, $configTarget)) {
  @unlink($envTmp); @unlink($configTmp); @unlink($envTarget); @unlink($configTarget);
  if ($envBackup !== '') @rename($envBackup, $envTarget); if ($configBackup !== '') @rename($configBackup, $configTarget); return false;
 }
 if ($envBackup !== '') @unlink($envBackup); if ($configBackup !== '') @unlink($configBackup); return true;
}
$checks = [['PHP 8.1+', PHP_VERSION_ID >= 80100, PHP_VERSION], ['cURL extension', function_exists('curl_init'), 'required for the API'], ['mbstring extension', function_exists('mb_strlen') && function_exists('mb_substr'), 'required for UTF-8 support'], ['JSON extension', function_exists('json_encode'), ''], ['config/ is writable', is_writable($configDir), $configDir], ['application directory is writable', is_writable($root), $root]];
$error = '';
$proxyHttps = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https' && filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
$scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $proxyHttps) ? 'https' : 'http';
$host = preg_replace('/[^A-Za-z0-9.:-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
$requestPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/config/install/index.php'));
$installScript = is_string($requestPath) && $requestPath !== '' ? $requestPath : $script;
$agentPath = rtrim(dirname(dirname(dirname('/' . ltrim($installScript, '/')))), '/') . '/';
if ($agentPath === '//') $agentPath = '/';
$detectedBaseUrl = $scheme . '://' . $host . '/';
$detectedAgentUrl = $scheme . '://' . $host . $agentPath;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 $password = (string)($_POST['password'] ?? ''); $apiKey = trim((string)($_POST['api_key'] ?? '')); $publicRel = trim((string)($_POST['public_html'] ?? '..')); $baseUrl = normalize_public_url((string)($_POST['base_url'] ?? '')); $agentUrl = normalize_public_url((string)($_POST['agent_url'] ?? ''));
 $publicPath = realpath($root . '/' . $publicRel);
 if (strlen($password) <= 4) $error = 'The password must contain at least 5 characters.';
 elseif ($apiKey === '' || preg_match('/[\r\n]/', $apiKey)) $error = 'Enter a valid DeepSeek API key.';
 elseif ($baseUrl === '' || $agentUrl === '') $error = 'Enter valid HTTP or HTTPS URLs without credentials, queries, or fragments.';
 elseif ($publicPath === false || !is_dir($publicPath) || !is_subdir($publicPath, dirname($root))) $error = 'public_html must point to an existing directory below the application parent directory.';
 elseif (!function_exists('mb_strlen') || !function_exists('mb_substr')) $error = 'The required mbstring extension is missing.';
 elseif (!is_writable($configDir) || !is_writable($root)) $error = 'PHP cannot write to the required directories.';
 else {
  $config = ['installed' => true, 'paths' => ['projects' => 'projects', 'conversations' => 'conversations', 'public_html' => $publicRel], 'limits' => ['max_tool_rounds' => 12, 'max_tools_per_round' => 8, 'max_tools_per_round_cap' => 32, 'max_tokens' => 16384, 'max_tokens_cap' => 32768, 'request_timeout_seconds' => 240, 'agent_timeout_seconds' => 300, 'max_tool_result_bytes' => 24576, 'max_multi_read_result_bytes' => 524288, 'max_tool_results_per_round_bytes' => 524288, 'max_tool_history_bytes_per_turn' => 98304, 'max_api_history_bytes' => 524288, 'max_list_files_entries' => 500, 'max_list_files_depth' => 8], 'urls' => ['base' => $baseUrl, 'agent' => $agentUrl], 'providers' => ['deepseek' => ['endpoint' => 'https://api.deepseek.com/chat/completions']], 'models' => ['deepseek-v4-flash' => ['label' => 'v4-flash', 'provider' => 'deepseek', 'model' => 'deepseek-v4-flash'], 'deepseek-v4-pro' => ['label' => 'v4-pro', 'provider' => 'deepseek', 'model' => 'deepseek-v4-pro'], 'deepseek-v4-flash-vision' => ['label' => 'v4-flash-vision', 'provider' => 'deepseek', 'model' => 'deepseek-v4-flash-vision-exp']], 'default_model' => 'deepseek-v4-flash'];
  $passwordHash = password_hash($password, PASSWORD_DEFAULT);
  if ($passwordHash === false) $error = 'Unable to secure the administrator password.';
  else {
  $configJson = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); $envFile = "DEEPSEEK_API_KEY=" . $apiKey . "\nAGENT_ADMIN_PASSWORD=" . $passwordHash . "\n";
  if (!is_file($configDir . '/tools.json')) $error = 'config/tools.json is missing.';
  elseif (!@mkdir($root . '/projects', 0755, true) && !is_dir($root . '/projects')) $error = 'Unable to create projects/.';
  elseif (!@mkdir($root . '/conversations', 0755, true) && !is_dir($root . '/conversations')) $error = 'Unable to create conversations/.';
  elseif (!atomic_install_pair($root . '/.env', $envFile, $configDir . '/config.json', $configJson)) $error = 'Unable to atomically write and secure the configuration.';
  else { header('Location: ' . $agentPath); exit; }
  }
 }
}
?>
<!doctype html>
<html lang="en">
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Install PHPilot</title>
  <style>
    body {
      max-width: 700px;
      margin: 3rem auto;
      padding: 0 1rem;
      background: #141413;
      color: #f1efe8;
      font: 16px system-ui
    }

    main {
      background: #20201e;
      border: 1px solid #444441;
      border-radius: 12px;
      padding: 24px
    }

    input {
      box-sizing: border-box;
      width: 100%;
      padding: 10px;
      margin: 5px 0 16px;
      background: #141413;
      border: 1px solid #666;
      color: #fff;
      border-radius: 6px
    }

    button {
      padding: 11px 16px;
      background: #d97757;
      border: 0;
      border-radius: 6px;
      cursor: pointer
    }

    .ok {
      color: #8ccf9a
    }

    .bad,
    .error {
      color: #f09595
    }

    small {
      color: #aaa
    }
  </style>
  <main>
    <h1>PHPilot — installation</h1>
    <p>The installer writes <code>config/config.json</code>; tool definitions remain in <code>config/tools.json</code>.</p> <?php if ($error): ?><p class="error"> <?=ih($error)?></p> <?php endif; ?><h2>Hosting checks</h2>
    <ul> <?php foreach ($checks as [$label,$ok,$detail]): ?><li class="<?=$ok ? 'ok' : 'bad'?>"> <?=ih($label)?>: <?=$ok ? 'OK' : 'missing'?><small> <?=ih($detail)?></small></li> <?php endforeach; ?></ul>
    <form method="post"><label>Administrator password<input type="password" name="password" minlength="5" required autocomplete="new-password"></label><label>DeepSeek API key<input type="password" name="api_key" required autocomplete="off"></label><label>Base URL for external projects<input type="url" name="base_url" value="<?=ih((string)($_POST['base_url'] ?? $detectedBaseUrl))?>" required></label><label>PHPilot URL<input type="url" name="agent_url" value="<?=ih((string)($_POST['agent_url'] ?? $detectedAgentUrl))?>" required></label><label>public_html relative to the application directory<input name="public_html" value="<?=ih((string)($_POST['public_html'] ?? '..'))?>" required></label><button type="submit">Install PHPilot</button></form>
  </main>
</html>
