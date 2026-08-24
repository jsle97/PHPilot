<?php
// <Jakub Śledzikowski | jsledzikowski.web@gmail.com | jsle.eu | MIT>
$action = $_GET["action"] ?? "";

agent_require_csrf();

if ($action === "login") {
    agent_session_start();
    $pw = (string)($_POST["password"] ?? "");
    if (password_verify($pw, server_env('AGENT_ADMIN_PASSWORD'))) {
        session_regenerate_id(true);
        $_SESSION["phpilot_auth"] = true;
        header("Location: " . ($_SERVER["SCRIPT_NAME"] ?? "./"));
        exit;
    }
    $_SESSION["phpilot_login_error"] = "Invalid password.";
    header("Location: " . ($_SERVER["SCRIPT_NAME"] ?? "./"));
    exit;
}

if ($action === "logout") {
    agent_session_start();
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $p = session_get_cookie_params();
        setcookie(session_name(), "", time() - 42000, $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
    }
    session_destroy();
    header("Location: " . ($_SERVER["SCRIPT_NAME"] ?? "./"));
    exit;
}

if ($action === "manifest") {
    header("Content-Type: application/manifest+json; charset=utf-8");
    echo json_encode([
        "name" => "PHPilot — coding agent",
        "short_name" => "PHPilot",
        "description" => "PHP coding agent — projects, files, DeepSeek",
        "start_url" => "./",
        "scope" => "./",
        "display" => "standalone",
        "orientation" => "any",
        "background_color" => "#141413",
        "theme_color" => "#141413",
        "icons" => [
            ["src" => "?action=icon&size=192", "sizes" => "192x192", "type" => "image/png", "purpose" => "any"],
            ["src" => "?action=icon&size=512", "sizes" => "512x512", "type" => "image/png", "purpose" => "any"],
            ["src" => "?action=icon&size=192&mask=1", "sizes" => "192x192", "type" => "image/png", "purpose" => "maskable"],
            ["src" => "?action=icon&size=512&mask=1", "sizes" => "512x512", "type" => "image/png", "purpose" => "maskable"]
        ]
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === "icon") {
    serve_icon((int)($_GET["size"] ?? 192), isset($_GET["mask"]));
    exit;
}

if ($action === "sw") {
    serve_service_worker();
    exit;
}

if ($action === "models") {
    $out = [];
    foreach ($MODELS as $key => $cfg) {
        $out[] = ["id" => $key, "label" => $cfg["label"], "provider" => $cfg["provider"], "model" => $cfg["model"] ?? ""];
    }
    json_out(["models" => $out, "default" => $DEFAULT_MODEL]);
}

if ($action === "projects") {
    $list = get_project_list($projectsDir);
    $out = [];
    foreach ($list as $name) {
        $out[] = ["name" => $name, "type" => "project", "url" => $agentBase . "projects/" . $name . "/"];
    }
    json_out($out);
}

if ($action === "create_project") {
    $name = sanitize_name($_POST["name"] ?? "");
    if ($name === "") json_err("invalid name");
    $path = $projectsDir . "/" . $name;
    if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) json_err("cannot create project");
    json_out(["ok" => true, "name" => $name, "type" => "project", "url" => $agentBase . "projects/" . $name . "/"]);
}

if ($action === "delete_project") {
    $name = sanitize_name($_POST["name"] ?? "");
    if ($name === "") json_err("invalid name");
    $path = $projectsDir . "/" . $name;
    $real = realpath($path);
    $realBase = realpath($projectsDir);
    if ($real === false || $real === $realBase || strpos($real, $realBase . "/") !== 0) json_err("invalid project");
    if (is_dir($real)) rm_rf($real);
    conv_save($convDir, conv_key($name, ""), []);
    json_out(["ok" => true]);
}

if ($action === "browse_dirs") {
    $rel = normalize_rel($_GET["path"] ?? "");
    $target = $rel === "" ? $publicHtml : $publicHtml . "/" . $rel;
    $real = realpath($target);
    if ($real === false || !path_in_base($publicHtml, $real) || !is_dir($real)) json_err("invalid path");
    $items = [];
    $entries = @scandir($real);
    if ($entries !== false) {
        foreach ($entries as $i) {
            if ($i === "" || $i[0] === ".") continue;
            if (!is_link($real . "/" . $i) && is_dir($real . "/" . $i)) $items[] = $i;
        }
    }
    sort($items, SORT_STRING);
    json_out(["path" => $rel, "dirs" => $items]);
}

if ($action === "open_dir") {
    $rel = normalize_rel($_POST["path"] ?? "");
    if ($rel === "") json_err("cannot open root");
    $target = $publicHtml . "/" . $rel;
    $real = realpath($target);
    if ($real === false || !path_in_base($publicHtml, $real) || !is_dir($real)) json_err("invalid path");
    $url = $baseUrl . $rel . "/";
    json_out(["ok" => true, "abs" => $real, "rel" => $rel, "type" => "external", "url" => $url]);
}

if ($action === "files") {
    $base = resolve_workdir($_GET["project"] ?? "", $_GET["extpath"] ?? "", $projectsDir, $publicHtml);
    json_out(scan_tree($base, $base));
}

if ($action === "file") {
    $base = resolve_workdir($_GET["project"] ?? "", $_GET["extpath"] ?? "", $projectsDir, $publicHtml);
    $full = safe_path($base, $_GET["path"] ?? "");
    if ($full === null || !is_file($full)) json_err("not found", 404);
    json_out(["path" => $_GET["path"], "content" => file_get_contents($full), "size" => filesize($full)]);
}

if ($action === "update_file") {
    $base = resolve_workdir($_POST["project"] ?? "", $_POST["extpath"] ?? "", $projectsDir, $publicHtml);
    $path = (string)($_POST['path'] ?? '');
    $content = (string)($_POST['content'] ?? '');
    if (strlen($content) > 2 * 1024 * 1024) json_err('file content exceeds the 2 MiB editor limit');
    $full = safe_path($base, $path);
    if ($full === null || !is_file($full)) json_err('not found', 404);
    if (file_put_contents($full, $content) === false) json_err('cannot write file', 500);
    json_out(['ok' => true, 'path' => $path, 'size' => strlen($content)]);
}

if ($action === "delete_file") {
    $base = resolve_workdir($_POST["project"] ?? "", $_POST["extpath"] ?? "", $projectsDir, $publicHtml);
    $path = (string)($_POST['path'] ?? '');
    $full = safe_path($base, $path);
    if ($full === null || !is_file($full)) json_err('not found', 404);
    if (!@unlink($full)) json_err('cannot delete file', 500);
    json_out(['ok' => true, 'path' => $path]);
}

if ($action === "conversation") {
    $key = conv_key($_GET["project"] ?? "", $_GET["extpath"] ?? "");
    $turns = conv_load($convDir, $key);
    json_out(["turns" => $turns, "usage" => usage_totals($turns)]);
}

if ($action === "delete_turn") {
    $key = conv_key($_POST["project"] ?? "", $_POST["extpath"] ?? "");
    $turnId = $_POST["turn_id"] ?? "";
    if ($turnId === "") json_err("no turn_id");
    $turns = conv_load($convDir, $key);
    $turns = array_values(array_filter($turns, function ($t) use ($turnId) { return $t["id"] !== $turnId; }));
    conv_save($convDir, $key, $turns);
    json_out(["ok" => true]);
}

if ($action === "clear_conversation") {
    $key = conv_key($_POST["project"] ?? "", $_POST["extpath"] ?? "");
    conv_save($convDir, $key, []);
    json_out(["ok" => true]);
}

if ($action === "chat") {
    run_agent($MODELS, $DEFAULT_MODEL, $projectsDir, $publicHtml, $convDir, $maxToolRounds);
    exit;
}

serve_frontend();
