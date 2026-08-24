<?php
// <Jakub Śledzikowski | jsledzikowski.web@gmail.com | jsle.eu | MIT>
$configFile = __DIR__ . '/config/config.json';
$installConfig = is_file($configFile) ? json_decode((string)@file_get_contents($configFile), true) : null;
if (!is_array($installConfig) || ($installConfig['installed'] ?? false) !== true) {
 require __DIR__ . '/config/install/index.php';
 exit;
}

require_once __DIR__ . '/fnc.php';
require_once __DIR__ . '/ai.php';

function serve_template(string $name, array $replacements = []): void {
 $template = __DIR__ . '/public/' . $name . '.html';
 $html = @file_get_contents($template);
 if ($html === false) {
  http_response_code(500);
  echo 'Missing frontend template.';
  exit;
 }
 $assetBase = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\') . '/public';
 $assetVersion = max((int)@filemtime(__DIR__ . '/public/style.css'), (int)@filemtime(__DIR__ . '/public/app.js'));
 $replacements['@@ASSET_BASE@@'] = htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8');
 $replacements['@@ASSET_VERSION@@'] = (string)$assetVersion;
 echo strtr($html, $replacements);
 exit;
}

function serve_login_page(): void {
 header('Content-Type: text/html; charset=utf-8');
 agent_session_start();
 $error = '';
 if (!empty($_SESSION['phpilot_login_error'])) {
  $error = '<div class="err">' . htmlspecialchars($_SESSION['phpilot_login_error'], ENT_QUOTES, 'UTF-8') . '</div>';
  unset($_SESSION['phpilot_login_error']);
 }
 serve_template('login', ['@@LOGIN_ERROR@@' => $error]);
}

function serve_frontend(): void {
 header('Content-Type: text/html; charset=utf-8');
 serve_template('app', ['@@PHPILOT_CSRF@@' => htmlspecialchars(agent_csrf_token(), ENT_QUOTES, 'UTF-8')]);
}

agent_require_auth();
require_once __DIR__ . '/endpoints.php';
