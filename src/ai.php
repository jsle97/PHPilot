<?php
// <Jakub Śledzikowski | jsledzikowski.web@gmail.com | jsle.eu | MIT>
$MODELS = config_value("models", []);
$DEFAULT_MODEL = (string)config_value("default_model", "");

function image_message_content(string $text, array $images): array|string {
    if (!$images) return $text;
    $content = [['type' => 'text', 'text' => $text]];
    foreach ($images as $image) $content[] = ['type' => 'image_url', 'image_url' => ['url' => $image['data_url'], 'detail' => 'low']];
    return $content;
}

function validate_images($input): array {
    if ($input === null) return [];
    if (!is_array($input) || count($input) > 2) throw new InvalidArgumentException('a message can contain at most 2 images');
    $images = [];
    foreach ($input as $image) {
        if (!is_array($image) || !is_string($image['data_url'] ?? null)) throw new InvalidArgumentException('invalid image');
        if (!preg_match('#^data:(image/(?:jpeg|png|gif|webp));base64,([A-Za-z0-9+/]+={0,2})$#D', $image['data_url'], $match)) throw new InvalidArgumentException('unsupported image format');
        $bytes = base64_decode($match[2], true);
        if ($bytes === false || $bytes === '') throw new InvalidArgumentException('invalid image data');
        $images[] = ['data_url' => $image['data_url'], 'bytes' => strlen($bytes), 'type' => $match[1]];
    }
    return $images;
}

function conversation_image_bytes(array $turns): int {
    $total = 0;
    foreach ($turns as $turn) foreach (($turn['images'] ?? []) as $image) $total += (int)($image['bytes'] ?? 0);
    return $total;
}

function vision_model_key(array $models): string {
    foreach ($models as $key => $model) if (($model['model'] ?? '') === 'deepseek-v4-flash-vision-exp') return (string)$key;
    return '';
}

function agent_limit(string $name, int $default, int $minimum, int $maximum): int {
    return max($minimum, min($maximum, (int)config_value('limits.' . $name, $default)));
}

function agent_utf8_prefix(string $value, int $bytes): string {
    $value = substr($value, 0, max(0, $bytes));
    while ($value !== '' && !preg_match('//u', $value)) $value = substr($value, 0, -1);
    return $value;
}

function bound_tool_result(string $result, string $tool, int $limit): string {
    if ($limit < 64) return '{}';
    if (strlen($result) <= $limit) return $result;
    $content = agent_utf8_prefix($result, max(0, $limit - 512));
    return json_encode(['tool' => $tool, 'content' => $content, 'truncated' => true, 'returned_bytes' => strlen($content), 'total_bytes' => strlen($result), 'next_action' => 'Narrow the path, query, or requested range before reading more.'], JSON_UNESCAPED_UNICODE);
}

function tool_result_limit(string $tool, int $remaining): int {
    $configured = $tool === 'multi_read_files'
        ? agent_limit('max_multi_read_result_bytes', 524288, 4096, 4 * 1024 * 1024)
        : agent_limit('max_tool_result_bytes', 24576, 1024, 1024 * 1024);
    return min($configured, $remaining);
}

function tool_read_path(string $tool, array $arguments): string {
    if (!in_array($tool, ['read_file', 'read_file_fragment'], true)) return '';
    return normalize_rel((string)($arguments['path'] ?? ''));
}

function superseded_read_result(string $path): string {
    return json_encode(['path' => $path, 'superseded' => true, 'truncated' => true, 'message' => 'A newer read of this file is present. Use the newest result as the current file state.'], JSON_UNESCAPED_UNICODE);
}

function supersede_read_results(array &$rounds, string $path): void {
    if ($path === '') return;
    foreach ($rounds as &$round) {
        foreach (($round['tools'] ?? []) as &$tool) {
            if (tool_read_path((string)($tool['name'] ?? ''), is_array($tool['arguments'] ?? null) ? $tool['arguments'] : []) === $path) $tool['result'] = superseded_read_result($path);
        }
        unset($tool);
    }
    unset($round);
}

function saved_api_messages(array $messages): array {
    $saved = [];
    foreach ($messages as $message) {
        if (!is_array($message) || !in_array($message['role'] ?? '', ['assistant', 'tool'], true)) continue;
        unset($message['reasoning_content']);
        $saved[] = $message;
    }
    return $saved;
}

function compact_tool_journal(array $rounds, int $limit): string {
    $items = [];
    foreach ($rounds as $round) foreach (($round['tools'] ?? []) as $tool) {
        $arguments = json_encode($tool['arguments'] ?? [], JSON_UNESCAPED_UNICODE);
        $result = (string)($tool['result'] ?? '');
        $items[] = ['tool' => (string)($tool['name'] ?? ''), 'arguments' => agent_utf8_prefix($arguments ?: '{}', 500), 'result' => agent_utf8_prefix($result, 1500)];
    }
    $journal = json_encode($items, JSON_UNESCAPED_UNICODE);
    return agent_utf8_prefix($journal ?: '[]', $limit);
}

function turn_history_messages(array $turn): array {
    $final = (string)($turn['final_content'] ?? '');
    foreach (($turn['api'] ?? []) as $message) {
        if (($message['role'] ?? '') === 'assistant' && empty($message['tool_calls']) && is_string($message['content'] ?? null)) $final = $message['content'];
    }
    if ($final === '' && is_array($turn['history'] ?? null)) {
        foreach ($turn['history'] as $message) if (($message['role'] ?? '') === 'assistant' && is_string($message['content'] ?? null)) $final = $message['content'];
    }
    $journal = compact_tool_journal(is_array($turn['rounds'] ?? null) ? $turn['rounds'] : [], agent_limit('max_tool_history_bytes_per_turn', 98304, 1024, 1024 * 1024));
    $content = $final;
    if ($journal !== '[]') $content .= ($content === '' ? '' : "\n\n") . '[Tool activity from this completed turn: ' . $journal . ']';
    return $content === '' ? [] : [['role' => 'assistant', 'content' => $content]];
}

function conversation_messages(array $turns): array {
    $messages = [];
    foreach ($turns as $turn) {
        $images = is_array($turn['images'] ?? null) ? $turn['images'] : [];
        $messages[] = ['role' => 'user', 'content' => image_message_content((string)($turn['user'] ?? ''), $images)];
        $api = saved_api_messages(is_array($turn['api'] ?? null) ? $turn['api'] : []);
        foreach ($api ?: turn_history_messages($turn) as $message) $messages[] = $message;
    }
    return $messages;
}

function request_payload(array $modelCfg, array $messages, string $thinkMode, array $options): array {
    $payload = [
        'model' => $modelCfg['model'],
        'messages' => $messages,
        'tools' => tool_defs(),
        'tool_choice' => 'auto',
        'stream' => true,
        'stream_options' => ['include_usage' => true],
        'max_tokens' => $options['max_tokens']
    ];
    $payload['thinking'] = ['type' => $thinkMode === 'off' ? 'disabled' : 'enabled'];
    if ($thinkMode !== 'off') $payload['reasoning_effort'] = $thinkMode;
    if ($options['temperature'] !== null) $payload['temperature'] = $options['temperature'];
    return $payload;
}

function payload_text_bytes(array $payload): int {
    $textPayload = $payload;
    if (is_array($textPayload['messages'] ?? null)) {
        foreach ($textPayload['messages'] as &$message) {
            if (!is_array($message['content'] ?? null)) continue;
            foreach ($message['content'] as &$part) {
                if (is_array($part) && ($part['type'] ?? '') === 'image_url') $part = ['type' => 'image_url'];
            }
            unset($part);
        }
        unset($message);
    }
    $encoded = json_encode($textPayload, JSON_UNESCAPED_UNICODE);
    return is_string($encoded) ? strlen($encoded) : PHP_INT_MAX;
}

function system_project_tree(string $base): string {
    $limit = agent_limit('max_tool_result_bytes', 24576, 1024, 1024 * 1024);
    $entries = agent_limit('max_list_files_entries', 500, 10, 10000);
    $depth = agent_limit('max_list_files_depth', 8, 1, 64);
    while (true) {
        $tree = scan_tree_limited($base, $base, $entries, $depth);
        $encoded = json_encode($tree, JSON_UNESCAPED_UNICODE);
        if (is_string($encoded) && strlen($encoded) <= $limit) return $encoded;
        if ($entries <= 10) return json_encode(['entries' => [], 'truncated' => true, 'message' => 'Project tree omitted due to its size. Use find_files or search_files.'], JSON_UNESCAPED_UNICODE);
        $entries = max(10, intdiv($entries, 2));
    }
}

function run_agent($MODELS, $defaultModel, $projectsDir, $publicHtml, $convDir, $maxRounds) {
    @set_time_limit((int)config_value("limits.agent_timeout_seconds", 300));
    header("Content-Type: text/event-stream; charset=utf-8");
    header("Cache-Control: no-cache");
    header("X-Accel-Buffering: no");
    header("Connection: keep-alive");
    while (ob_get_level() > 0) ob_end_flush();
    @ini_set("zlib.output_compression", "0");
    ignore_user_abort(false);

    $raw = file_get_contents("php://input");
    $body = json_decode($raw, true);
    if (!is_array($body)) { sse("error", ["message" => "invalid request"]); sse("done", []); return; }

    $project = sanitize_name($body["project"] ?? "");
    $extpath = normalize_rel($body["extpath"] ?? "");
    $userText = trim((string)($body["text"] ?? ""));
    $modelKey = $body["model"] ?? "";
    if (!isset($MODELS[$modelKey])) $modelKey = $defaultModel;
    $modelCfg = $MODELS[$modelKey];
    $thinkMode = $body["thinking"] ?? "off";
    if (!in_array($thinkMode, ["off", "low", "high", "max"], true)) $thinkMode = "off";
    $options = conversation_options(is_array($body['options'] ?? null) ? $body['options'] : []);

    if ($extpath !== "") {
        $publicReal = realpath($publicHtml);
        $base = $publicReal === false ? false : realpath($publicReal . "/" . $extpath);
        if ($base === false || !path_in_base($publicReal, $base) || !is_dir($base)) {
            sse("error", ["message" => "invalid external path"]); sse("done", []); return;
        }
    } else {
        $base = resolve_project($project, $projectsDir);
    }

    $convKey = conv_key($project, $extpath);
    $turns = conv_load($convDir, $convKey);
    try {
        $images = validate_images($body['images'] ?? null);
    } catch (InvalidArgumentException $e) {
        sse('error', ['message' => $e->getMessage()]); sse('done', []); return;
    }
    if ($userText === '' && !$images) { sse("error", ["message" => "empty message"]); sse("done", []); return; }
    $newImageBytes = array_sum(array_column($images, 'bytes'));
    if (conversation_image_bytes($turns) + $newImageBytes > 28 * 1024 * 1024) {
        sse('error', ['message' => 'images in this conversation exceed the 28 MiB limit']); sse('done', []); return;
    }
    if ($images || conversation_image_bytes($turns) > 0) {
        $visionKey = vision_model_key($MODELS);
        if ($visionKey === '') { sse('error', ['message' => 'vision model is not configured']); sse('done', []); return; }
        $modelKey = $visionKey;
        $modelCfg = $MODELS[$modelKey];
        $thinkMode = 'off';
    }

    $messages = conversation_messages($turns);
    $messages[] = ["role" => "user", "content" => image_message_content($userText, $images)];

    $tree = system_project_tree($base);

    $sys = @file_get_contents(__DIR__ . "/config/system_prompt.md");
    if ($sys === false) { sse("error", ["message" => "system prompt unavailable"]); sse("done", []); return; }
    $sys = str_replace('{{PROJECT_TREE}}', $tree, $sys);
    if ($options['instructions'] !== '') $sys .= "\n\nAdditional user instructions for this conversation:\n" . $options['instructions'];

    $apiMessages = array_merge([["role" => "system", "content" => $sys]], $messages);

    $turnId = uniqid("t", true);
    $turnApi = [];
    $turnRounds = [];
    $maxRoundResultBytes = agent_limit('max_tool_results_per_round_bytes', 524288, 4096, 4 * 1024 * 1024);

    sse("turn_start", ["turn_id" => $turnId]);

    for ($round = 0; $round < $maxRounds; $round++) {
        $roundResultBytes = 0;
        sse("round", ["n" => $round + 1]);
        $payload = request_payload($modelCfg, $apiMessages, $thinkMode, $options);
        $textBytes = payload_text_bytes($payload);
        $inputEstimate = (int)ceil($textBytes / 4);
        $contextLimit = 512000;
        sse('context', ['tokens_estimate' => $inputEstimate, 'payload_text_bytes' => $textBytes, 'limit_tokens' => $contextLimit]);
        if ($inputEstimate > $contextLimit) {
            sse('context_limit', ['tokens_estimate' => $inputEstimate, 'limit_tokens' => $contextLimit]);
            break;
        }
        $result = stream_call($modelCfg, $payload);
        if ($result === null) {
            sse("error", ["message" => "API failed"]);
            break;
        }

        $apiMessages[] = $result["msg"];
        $turnApi[] = $result["msg"];

        $roundData = ["reasoning" => $result["reasoning"], "content" => $result["content"], "tools" => [], "usage" => $result["usage"]];

        if (empty($result["tools"])) {
            $turnRounds[] = $roundData;
            break;
        }

        $toolCalls = array_slice($result["tools"], 0, $options['max_tools_per_round']);
        if (count($result['tools']) > count($toolCalls)) {
            $result['msg']['tool_calls'] = $toolCalls;
            $apiMessages[count($apiMessages) - 1] = $result['msg'];
            $turnApi[count($turnApi) - 1] = $result['msg'];
            sse('notice', ['message' => 'The number of tools in this round was limited to ' . $options['max_tools_per_round'] . '.']);
        }
        foreach ($toolCalls as $tc) {
            $fn = $tc["function"]["name"];
            $args = @json_decode($tc["function"]["arguments"] ?? "{}", true) ?: [];
            sse("tool_call", ["id" => $tc["id"], "name" => $fn, "arguments" => $args]);
            $rawOut = run_tool($base, $fn, $args);
            $remaining = max(2, $maxRoundResultBytes - $roundResultBytes);
            $out = bound_tool_result($rawOut, $fn, tool_result_limit($fn, $remaining));
            $roundResultBytes += strlen($out);
            $path = tool_read_path($fn, $args);
            if ($path !== '') {
                supersede_read_results($turns, $path);
                supersede_read_results($turnRounds, $path);
                foreach ($roundData['tools'] as &$previousTool) {
                    if (tool_read_path((string)($previousTool['name'] ?? ''), is_array($previousTool['arguments'] ?? null) ? $previousTool['arguments'] : []) === $path) $previousTool['result'] = superseded_read_result($path);
                }
                unset($previousTool);
            }
            $outTrunc = mb_substr($out, 0, 6000);
            sse("tool_result", ["id" => $tc["id"], "name" => $fn, "result" => $outTrunc]);
            $apiMessages[] = ["role" => "tool", "tool_call_id" => $tc["id"], "content" => $out];
            $turnApi[] = ["role" => "tool", "tool_call_id" => $tc["id"], "content" => $out];
            $roundData["tools"][] = ["id" => $tc["id"], "name" => $fn, "arguments" => $args, "result" => $outTrunc];
        }

        $turnRounds[] = $roundData;
    }

    if (count($turnRounds) === $maxRounds && !empty($turnRounds[$maxRounds - 1]['tools'])) {
        sse('notice', ['message' => 'The agent reached the tool-round limit. Send a continuation message to resume from the saved tool history.']);
    }

    if (!empty($turnApi)) {
        $finalContent = '';
        foreach ($turnRounds as $round) if (($round['content'] ?? '') !== '') $finalContent = (string)$round['content'];
        $journal = compact_tool_journal($turnRounds, agent_limit('max_tool_history_bytes_per_turn', 98304, 1024, 1024 * 1024));
        $historyContent = $finalContent;
        if ($journal !== '[]') $historyContent .= ($historyContent === '' ? '' : "\n\n") . '[Tool activity from this completed turn: ' . $journal . ']';
        $history = $historyContent === '' ? [] : [['role' => 'assistant', 'content' => $historyContent]];
        $turns[] = ["id" => $turnId, "user" => $userText, "images" => $images, "rounds" => $turnRounds, "final_content" => $finalContent, "history" => $history, "api" => saved_api_messages($turnApi)];
        conv_save($convDir, $convKey, $turns);
    }

    sse("done", ["turn_id" => $turnId]);
}

function stream_call($modelCfg, array $payload) {
    $apiKey = server_env("DEEPSEEK_API_KEY");
    if ($apiKey === "") {
        sse("error", ["message" => "missing DEEPSEEK_API_KEY in server environment"]);
        return null;
    }
    $content = ""; $reasoning = ""; $toolCalls = []; $buf = ""; $usage = null; $errorBody = "";
    $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($encodedPayload === false) {
        sse("error", ["message" => "invalid API payload: " . json_last_error_msg()]);
        return null;
    }
    $endpoint = (string)config_value('providers.' . ($modelCfg['provider'] ?? '') . '.endpoint', '');
    if ($endpoint === '') { sse('error', ['message' => 'missing endpoint for provider']); return null; }
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer " . $apiKey, "Content-Type: application/json"],
        CURLOPT_POSTFIELDS => $encodedPayload,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_TIMEOUT => (int)config_value("limits.request_timeout_seconds", 240),
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$buf, &$content, &$reasoning, &$toolCalls, &$usage, &$errorBody) {
            if (connection_aborted()) return 0;
            if (strlen($errorBody) < 8000) $errorBody .= substr($chunk, 0, 8000 - strlen($errorBody));
            $buf .= $chunk;
            while (($pos = strpos($buf, "\n")) !== false) {
                $line = trim(substr($buf, 0, $pos));
                $buf = substr($buf, $pos + 1);
                if ($line === "" || $line === "data: [DONE]") continue;
                if (strpos($line, "data: ") !== 0) continue;
                $d = json_decode(substr($line, 6), true);
                if (!is_array($d)) continue;
                if (!empty($d["usage"])) $usage = $d["usage"];
                if (empty($d["choices"]) || !is_array($d["choices"])) continue;
                $delta = $d["choices"][0]["delta"] ?? null;
                if (!is_array($delta)) continue;
                $reasonDelta = $delta["reasoning_content"] ?? "";
                if ($reasonDelta !== "") {
                    $reasoning .= $reasonDelta;
                    sse("reasoning", ["t" => $reasonDelta]);
                }
                if (isset($delta["content"]) && $delta["content"] !== "" && $delta["content"] !== null) {
                    $content .= $delta["content"];
                    sse("content", ["t" => $delta["content"]]);
                }
                if (!empty($delta["tool_calls"])) {
                    foreach ($delta["tool_calls"] as $tc) {
                        $idx = $tc["index"];
                        if (!isset($toolCalls[$idx])) $toolCalls[$idx] = ["id" => "", "type" => "function", "function" => ["name" => "", "arguments" => ""]];
                        if (!empty($tc["id"])) $toolCalls[$idx]["id"] = $tc["id"];
                        if (isset($tc["function"]["name"])) $toolCalls[$idx]["function"]["name"] .= $tc["function"]["name"];
                        if (isset($tc["function"]["arguments"])) $toolCalls[$idx]["function"]["arguments"] .= $tc["function"]["arguments"];
                    }
                }
            }
            return strlen($chunk);
        }
    ]);

    $ok = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($ok === false) { sse("error", ["message" => "curl: " . $err]); return null; }
    if ($code >= 400) {
        $detail = trim($errorBody);
        $decoded = json_decode($detail, true);
        if (is_array($decoded["error"] ?? null)) $detail = (string)($decoded["error"]["message"] ?? $detail);
        $detail = preg_replace('/\s+/', ' ', $detail);
        if ($detail === "") $detail = "no response details available";
        sse("error", ["message" => "HTTP " . $code . " from " . $modelCfg["provider"] . ": " . mb_substr($detail, 0, 500)]);
        return null;
    }

    $toolCalls = array_values($toolCalls);
    if ($usage) sse("usage", $usage);

    $msg = ["role" => "assistant", "content" => $content !== "" ? $content : null];
    if ($reasoning !== "") $msg["reasoning_content"] = $reasoning;
    if (!empty($toolCalls)) $msg["tool_calls"] = $toolCalls;

    return ["msg" => $msg, "tools" => $toolCalls, "content" => $content, "reasoning" => $reasoning, "usage" => $usage];
}
