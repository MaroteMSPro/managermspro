<?php
/**
 * MSPro Config AI - API Router
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../classes/ResetSystemParser.php';
require_once __DIR__ . '/../classes/BonusPartyParser.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../middleware/auth.php';

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = str_replace('/api/', '', $uri);
$method = $_SERVER['REQUEST_METHOD'];

// Simple router
try {
    match (true) {
        // ==================== PUBLIC (Token-based) ====================
        // Parse uploaded file
        $uri === 'parse' && $method === 'POST'
            => handleParse(),

        // Chat with AI
        $uri === 'chat' && $method === 'POST'
            => handleChat(),

        // Generate/download modified file
        $uri === 'generate' && $method === 'POST'
            => handleGenerate(),

        // Validate token
        $uri === 'validate-token' && $method === 'POST'
            => handleValidateToken(),

        // ==================== ADMIN (Auth required) ====================
        // Admin login
        $uri === 'admin/login' && $method === 'POST'
            => handleAdminLogin(),

        // Admin - Get config rules
        $uri === 'admin/rules' && $method === 'GET'
            => withAuth(fn() => handleGetRules()),

        // Admin - Save config rules
        $uri === 'admin/rules' && $method === 'POST'
            => withAuth(fn() => handleSaveRules()),

        str_starts_with($uri, 'admin/rules/') && $method === 'DELETE'
            => withAuth(fn() => handleDeleteRule(basename($uri))),

        // Admin - Manage tokens
        $uri === 'admin/tokens' && $method === 'GET'
            => withAuth(fn() => handleGetTokens()),

        $uri === 'admin/tokens' && $method === 'POST'
            => withAuth(fn() => handleCreateToken()),

        str_starts_with($uri, 'admin/tokens/') && $method === 'DELETE'
            => withAuth(fn() => handleDeleteToken(basename($uri))),

        // Admin - Get presets/templates
        $uri === 'admin/presets' && $method === 'GET'
            => withAuth(fn() => handleGetPresets()),

        $uri === 'admin/presets' && $method === 'POST'
            => withAuth(fn() => handleSavePreset()),

        // Admin - Stats
        $uri === 'admin/stats' && $method === 'GET'
            => withAuth(fn() => handleGetStats()),

        // Admin - Wiki KB
        $uri === 'admin/wiki-kb' && $method === 'GET'
            => withAuth(fn() => handleGetWikiKb()),

        $uri === 'admin/wiki-kb' && $method === 'POST'
            => withAuth(fn() => handleSaveWikiKb()),

        default => jsonResponse(['error' => 'Not Found'], 404)
    };
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}

// ==================== HANDLER FUNCTIONS ====================

function handleParse()
{
    $token = validateUserToken();

    if (!isset($_FILES['file']) && !isset($_POST['content'])) {
        jsonResponse(['error' => 'No file or content provided'], 400);
    }

    $content = '';
    if (isset($_FILES['file'])) {
        $content = file_get_contents($_FILES['file']['tmp_name']);
    } else {
        $content = $_POST['content'];
    }

    $parser = new ResetSystemParser();
    $sections = $parser->parse($content);
    $humanReadable = $parser->toHumanReadable();

    // Log usage
    logUsage($token, 'parse');

    jsonResponse([
        'success' => true,
        'sections' => $sections,
        'summary' => $humanReadable,
        'rawContent' => $content
    ]);
}

function handleChat()
{
    $token = validateUserToken();
    $input = getJsonInput();

    if (empty($input['message'])) {
        jsonResponse(['error' => 'Message is required'], 400);
    }

    $rules = getAdminRules($token);
    $module = $input['module'] ?? 'reset-system';

    if ($module === 'bonus-party') {
        handleChatBonusParty($token, $input, $rules);
        return;
    }

    if ($module === 'wiki') {
        handleChatWiki($token, $input);
        return;
    }

    // Reset System module (default)
    $parser = new ResetSystemParser();
    $hasOriginal = false;
    if (!empty($input['currentConfig'])) {
        $parser->parse($input['currentConfig']);
        $hasOriginal = true;
    } elseif (!empty($input['rawConfig'])) {
        $parser->parse($input['rawConfig']);
        $hasOriginal = true;
    } elseif (!empty($input['sections'])) {
        $parser->setHumanReadable($input['sections']);
    }

    $systemPrompt = $parser->buildAISystemPrompt($rules);
    $messages = buildConversationMessages($input);

    $response = callClaudeAPI($systemPrompt, $messages);
    $updates = extractUpdates($response['content']);
    $cleanedResponse = cleanAIResponse($response['content']);

    $result = [
        'success' => true,
        'response' => $cleanedResponse,
        'hasUpdates' => $updates !== null,
    ];

    if ($updates && isset($updates['sections'])) {
        foreach ($updates['sections'] as $sectionId => $sectionData) {
            if (isset($sectionData['rows'])) {
                $parser->updateSection((int)$sectionId, $sectionData['rows']);
            }
        }
        $result['updatedTxt'] = $parser->generate();
        $result['updatedSummary'] = $parser->toHumanReadable();
        $result['updatedSections'] = $updates['sections'];
    }

    $usage = $response['usage'] ?? [];
    $tokensIn = $usage['input_tokens'] ?? 0;
    $tokensOut = $usage['output_tokens'] ?? 0;
    logUsage($token, 'chat', $tokensIn, $tokensOut);

    $result['usage'] = [
        'input_tokens' => $tokensIn,
        'output_tokens' => $tokensOut,
        'cost_usd' => 0 // Ollama es gratis
    ];

    jsonResponse($result);
}

function handleChatBonusParty(string $token, array $input, array $rules)
{
    $parser = new BonusPartyParser();

    // Load current values if provided
    if (!empty($input['rawConfig'])) {
        $parser->parse($input['rawConfig']);
    } elseif (!empty($input['partyConfig'])) {
        $general = $input['partyConfig']['general'] ?? [];
        $special = $input['partyConfig']['special'] ?? [];
        if (count($general) === 10 && count($special) === 10) {
            $parser->updateValues($general, $special);
        }
    }

    $serverConfig = $input['serverConfig'] ?? [];
    $systemPrompt = $parser->buildAISystemPrompt($rules, $serverConfig);
    $messages = buildConversationMessages($input);

    $response = callClaudeAPI($systemPrompt, $messages);

    // Extract party_update block
    $partyUpdate = null;
    if (preg_match('/```party_update\s*\n([\s\S]*?)\n```/', $response['content'], $m)) {
        $partyUpdate = json_decode(trim($m[1]), true);
    }

    // Clean response
    $cleaned = preg_replace('/\s*```party_update\s*\n[\s\S]*?\n```\s*/', '', $response['content']);
    $cleaned = trim($cleaned);

    $result = [
        'success' => true,
        'response' => $cleaned,
        'hasUpdates' => $partyUpdate !== null,
    ];

    if ($partyUpdate) {
        $gen = $partyUpdate['general'] ?? [];
        $spe = $partyUpdate['special'] ?? [];
        if (count($gen) === 10 && count($spe) === 10) {
            $parser->updateValues($gen, $spe);
            $result['updatedPartyConfig'] = ['general' => $gen, 'special' => $spe];
            $result['updatedTxt'] = $parser->generate();
        }
    }

    $usage = $response['usage'] ?? [];
    $tokensIn = $usage['input_tokens'] ?? 0;
    $tokensOut = $usage['output_tokens'] ?? 0;
    logUsage($token, 'chat', $tokensIn, $tokensOut);

    $result['usage'] = [
        'input_tokens' => $tokensIn,
        'output_tokens' => $tokensOut,
        'cost_usd' => 0 // Ollama es gratis
    ];

    jsonResponse($result);
}

function buildConversationMessages(array $input): array
{
    $messages = [];
    if (!empty($input['history'])) {
        foreach ($input['history'] as $msg) {
            $role = $msg['role'];
            if ($role === 'ai') $role = 'assistant';
            $messages[] = ['role' => $role, 'content' => $msg['content']];
        }
    }
    $messages[] = ['role' => 'user', 'content' => $input['message']];
    return $messages;
}

function handleGenerate()
{
    $token = validateUserToken();
    $input = getJsonInput();

    $parser = new ResetSystemParser();

    // Parse original content if provided
    if (!empty($input['originalContent'])) {
        $parser->parse($input['originalContent']);
    }

    // Apply section updates from summary if provided
    if (!empty($input['sections']) && is_array($input['sections'])) {
        // Check if sections has direct row data (from AI updates)
        foreach ($input['sections'] as $sectionId => $sectionData) {
            if (is_numeric($sectionId) && isset($sectionData['rows'])) {
                $parser->updateSection((int)$sectionId, $sectionData['rows']);
            }
        }
    }

    $output = $parser->generate();

    // Log usage
    logUsage($token, 'generate');

    jsonResponse([
        'success' => true,
        'content' => $output,
        'filename' => 'ResetSystem.txt'
    ]);
}

function handleValidateToken()
{
    $input = getJsonInput();
    $token = $input['token'] ?? '';

    $db = getDB();
    $stmt = $db->prepare("SELECT id, label, section, expires_at, is_active FROM tokens WHERE token = ? AND is_active = 1");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        jsonResponse(['valid' => false, 'error' => 'Token inválido o expirado']);
    }

    if ($row['expires_at'] && strtotime($row['expires_at']) < time()) {
        jsonResponse(['valid' => false, 'error' => 'Token expirado']);
    }

    jsonResponse([
        'valid' => true,
        'label' => $row['label'],
        'section' => $row['section']
    ]);
}

function handleAdminLogin()
{
    $input = getJsonInput();
    $username = $input['username'] ?? '';
    $password = $input['password'] ?? '';

    $db = getDB();
    $stmt = $db->prepare("SELECT id, username, password_hash FROM admins WHERE username = ?");
    $stmt->execute([$username]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin || !password_verify($password, $admin['password_hash'])) {
        jsonResponse(['error' => 'Credenciales inválidas'], 401);
    }

    $jwt = generateJWT($admin['id'], $admin['username']);

    jsonResponse([
        'success' => true,
        'token' => $jwt,
        'username' => $admin['username']
    ]);
}

function handleGetRules()
{
    $db = getDB();
    $rules = $db->query("SELECT * FROM admin_rules ORDER BY section, id")->fetchAll(PDO::FETCH_ASSOC);
    jsonResponse(['success' => true, 'rules' => $rules]);
}

function handleSaveRules()
{
    $input = getJsonInput();
    $db = getDB();

    // Single rule add from frontend
    if (isset($input['rule_value'])) {
        $stmt = $db->prepare("INSERT INTO admin_rules (section, rule_key, rule_value) VALUES (?, ?, ?)");
        $stmt->execute([
            $input['section'] ?? 'global',
            'custom_' . time(),
            $input['rule_value']
        ]);
        jsonResponse(['success' => true, 'id' => $db->lastInsertId()]);
        return;
    }

    // Bulk rules update
    $db->beginTransaction();
    try {
        if (isset($input['section'])) {
            $stmt = $db->prepare("DELETE FROM admin_rules WHERE section = ?");
            $stmt->execute([$input['section']]);
        }

        $stmt = $db->prepare("INSERT INTO admin_rules (section, rule_key, rule_value, description) VALUES (?, ?, ?, ?)");
        foreach ($input['rules'] as $rule) {
            $stmt->execute([
                $rule['section'], $rule['rule_key'],
                $rule['rule_value'], $rule['description'] ?? ''
            ]);
        }

        $db->commit();
        jsonResponse(['success' => true]);
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

function handleGetTokens()
{
    $db = getDB();
    $tokens = $db->query("SELECT id, token, label, section, created_at, expires_at, is_active, usage_count FROM tokens ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    jsonResponse(['success' => true, 'tokens' => $tokens]);
}

function handleCreateToken()
{
    $input = getJsonInput();
    $db = getDB();

    $token = bin2hex(random_bytes(16));
    $expires = !empty($input['expires']) ? $input['expires'] : (!empty($input['expires_at']) ? $input['expires_at'] : null);
    $stmt = $db->prepare("INSERT INTO tokens (token, label, section, expires_at, is_active) VALUES (?, ?, ?, ?, 1)");
    $stmt->execute([
        $token,
        $input['label'] ?? 'Sin nombre',
        $input['section'] ?? 'general',
        $expires
    ]);

    jsonResponse([
        'success' => true,
        'token' => $token,
        'id' => $db->lastInsertId()
    ]);
}

function handleDeleteToken(string $id)
{
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM tokens WHERE id = ?");
    $stmt->execute([$id]);
    jsonResponse(['success' => true]);
}

function handleDeleteRule(string $id)
{
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM admin_rules WHERE id = ?");
    $stmt->execute([$id]);
    jsonResponse(['success' => true]);
}

function handleGetPresets()
{
    $db = getDB();
    $presets = $db->query("SELECT * FROM presets ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    jsonResponse(['presets' => $presets]);
}

function handleSavePreset()
{
    $input = getJsonInput();
    $db = getDB();

    $stmt = $db->prepare("INSERT INTO presets (name, description, config_data) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE config_data = VALUES(config_data), description = VALUES(description)");
    $stmt->execute([
        $input['name'],
        $input['description'] ?? '',
        json_encode($input['config_data'])
    ]);

    jsonResponse(['success' => true, 'id' => $db->lastInsertId()]);
}

function handleGetStats()
{
    $db = getDB();

    // Check if cost columns exist
    $hasCost = false;
    try {
        $db->query("SELECT tokens_in FROM usage_log LIMIT 1");
        $hasCost = true;
    } catch (Exception $e) {
        // Columns don't exist yet
    }

    $stats = [
        'success' => true,
        'activeTokens' => (int)$db->query("SELECT COUNT(*) FROM tokens WHERE is_active = 1")->fetchColumn(),
        'totalUsage' => (int)$db->query("SELECT COUNT(*) FROM usage_log")->fetchColumn(),
        'todayUsage' => (int)$db->query("SELECT COUNT(*) FROM usage_log WHERE DATE(created_at) = CURDATE()")->fetchColumn(),
        'recentActivity' => $db->query("SELECT * FROM usage_log ORDER BY created_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC),
    ];

    if ($hasCost) {
        // Total tokens & cost
        $totals = $db->query("SELECT COALESCE(SUM(tokens_in),0) as total_in, COALESCE(SUM(tokens_out),0) as total_out, COALESCE(SUM(cost_usd),0) as total_cost FROM usage_log")->fetch(PDO::FETCH_ASSOC);
        $stats['totalTokensIn'] = (int)$totals['total_in'];
        $stats['totalTokensOut'] = (int)$totals['total_out'];
        $stats['totalCostUsd'] = round((float)$totals['total_cost'], 4);

        // Today's cost
        $today = $db->query("SELECT COALESCE(SUM(tokens_in),0) as t_in, COALESCE(SUM(tokens_out),0) as t_out, COALESCE(SUM(cost_usd),0) as t_cost FROM usage_log WHERE DATE(created_at) = CURDATE()")->fetch(PDO::FETCH_ASSOC);
        $stats['todayTokensIn'] = (int)$today['t_in'];
        $stats['todayTokensOut'] = (int)$today['t_out'];
        $stats['todayCostUsd'] = round((float)$today['t_cost'], 4);

        // Chat-only stats
        $chatStats = $db->query("SELECT COUNT(*) as chats, COALESCE(SUM(tokens_in),0) as c_in, COALESCE(SUM(tokens_out),0) as c_out, COALESCE(SUM(cost_usd),0) as c_cost FROM usage_log WHERE action='chat'")->fetch(PDO::FETCH_ASSOC);
        $stats['totalChats'] = (int)$chatStats['chats'];
        $stats['chatCostUsd'] = round((float)$chatStats['c_cost'], 4);
        
        // Avg cost per chat
        $stats['avgCostPerChat'] = $stats['totalChats'] > 0 ? round($stats['chatCostUsd'] / $stats['totalChats'], 6) : 0;
    }

    jsonResponse($stats);
}

// ==================== HELPER FUNCTIONS ====================

function handleChatWiki(string $token, array $input): void
{
    // Cargar KB desde archivo
    $kbPath = __DIR__ . '/../../wiki_kb.json';
    if (!file_exists($kbPath)) {
        // Intentar path alternativo (raíz de la wiki)
        $kbPath = '/home/wvjmxock/wiki.muserverpro.com/wiki_kb.json';
    }
    if (!file_exists($kbPath)) {
        jsonResponse(['error' => 'wiki_kb.json no encontrado. Subilo desde el panel Admin → Wiki KB.'], 500);
    }

    $kb = json_decode(file_get_contents($kbPath), true);
    if (!$kb || empty($kb['articles'])) {
        jsonResponse(['error' => 'KB inválida o vacía'], 500);
    }

    // Armar contenido wiki para el system prompt
    $wikiContent = '';
    foreach ($kb['articles'] as $a) {
        $wikiContent .= "\n\n---\n## {$a['title']}\nCategoría: {$a['category']}\nURL: {$a['url']}\n\n{$a['content']}";
    }

    $systemPrompt = "Eres un asistente experto en MSPro, el emulador de MU Online.
Tenés acceso completo a la Wiki oficial de MSPro con {$kb['total_articles']} artículos.

Tu objetivo es responder preguntas técnicas sobre configuración, archivos, sistemas y características de MSPro de forma clara y precisa.

Reglas:
- Basá tus respuestas SIEMPRE en la información de la wiki
- Si la información no está en la wiki, decilo claramente
- Cuando menciones un archivo de configuración, explicá brevemente su función
- Usá ejemplos concretos cuando sea posible
- Respondé siempre en español

─────────────────────────────────────────────────────────
KNOWLEDGE BASE - MSPro Wiki ({$kb['total_articles']} artículos)
─────────────────────────────────────────────────────────
{$wikiContent}
─────────────────────────────────────────────────────────";

    $messages = buildConversationMessages($input);
    $response = callClaudeAPI($systemPrompt, $messages);
    $cleaned = trim($response['content']);

    $usage = $response['usage'] ?? [];
    $tokensIn = $usage['input_tokens'] ?? 0;
    $tokensOut = $usage['output_tokens'] ?? 0;
    logUsage($token, 'chat', $tokensIn, $tokensOut);

    jsonResponse([
        'success' => true,
        'response' => $cleaned,
        'hasUpdates' => false,
        'usage' => [
            'input_tokens' => $tokensIn,
            'output_tokens' => $tokensOut,
            'cost_usd' => 0 // Ollama es gratis
        ]
    ]);
}

function handleGetWikiKb(): void
{
    $paths = [
        __DIR__ . '/../../wiki_kb.json',
        '/home/wvjmxock/wiki.muserverpro.com/wiki_kb.json',
    ];
    foreach ($paths as $p) {
        if (file_exists($p)) {
            $kb = json_decode(file_get_contents($p), true);
            jsonResponse([
                'success' => true,
                'stats' => [
                    'total_articles'     => $kb['total_articles'] ?? 0,
                    'total_tokens_est'   => $kb['total_tokens_est'] ?? 0,
                    'total_chars'        => $kb['total_chars'] ?? 0,
                    'strategy_recommended' => $kb['strategy_recommended'] ?? '—',
                    'generated_at'       => $kb['generated_at'] ?? null,
                    'source'             => $kb['source'] ?? null,
                ]
            ]);
        }
    }
    jsonResponse(['success' => true, 'stats' => [], 'msg' => 'KB no encontrada aún']);
}

function handleSaveWikiKb(): void
{
    $input = getJsonInput();
    $kb = $input['kb'] ?? null;

    if (!$kb || !isset($kb['articles']) || !is_array($kb['articles'])) {
        jsonResponse(['error' => 'JSON inválido: debe tener campo articles[]'], 400);
    }

    $savePath = '/home/wvjmxock/wiki.muserverpro.com/wiki_kb.json';
    $saved = file_put_contents($savePath, json_encode($kb, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    if ($saved === false) {
        jsonResponse(['error' => 'No se pudo guardar el archivo en el servidor'], 500);
    }

    jsonResponse([
        'success' => true,
        'articles' => count($kb['articles']),
        'tokens'   => $kb['total_tokens_est'] ?? 0,
        'stats'    => [
            'total_articles'       => $kb['total_articles'] ?? count($kb['articles']),
            'total_tokens_est'     => $kb['total_tokens_est'] ?? 0,
            'strategy_recommended' => $kb['strategy_recommended'] ?? '—',
            'generated_at'         => $kb['generated_at'] ?? date('c'),
        ]
    ]);
}

function callClaudeAPI(string $systemPrompt, array $messages): array
{
    // ⭐⭐ CONFIGURACIÓN OLLAMA - NO CLAUDE ⭐⭐
    $aiConfig = getAIConfig();
    $url = $aiConfig['url'] . '/api/chat';
    $model = $aiConfig['default_model'];
    
    // Construir payload
    $messages_for_api = [
        ['role' => 'system', 'content' => $systemPrompt]
    ];
    foreach ($messages as $msg) {
        $messages_for_api[] = [
            'role' => $msg['role'] ?? 'user',
            'content' => $msg['content'] ?? ''
        ];
    }
    
    $payload = json_encode([
        'model' => $model,
        'messages' => $messages_for_api,
        'stream' => false,
        'options' => [
            'temperature' => $aiConfig['temperature'] ?? 0.7,
            'num_predict' => $aiConfig['max_tokens'] ?? 2500
        ]
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $aiConfig['timeout'] ?? 300,  // Usar timeout de config
        CURLOPT_CONNECTTIMEOUT => 30  // 30s para conectar
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception("Ollama API error: HTTP {$httpCode} - {$error} - Response: {$response}");
    }

    if (empty($response)) {
        throw new Exception("Ollama API: respuesta vacía");
    }

    $data = json_decode($response, true);
    
    if (!$data) {
        throw new Exception("Ollama API: JSON inválido - {$response}");
    }

    // Ollama devuelve estructura: { "message": { "role": "assistant", "content": "..." } }
    $content = $data['message']['content'] ?? $data['response'] ?? '';

    return [
        'content' => $content,
        'usage' => [
            'input_tokens' => 0,
            'output_tokens' => 0
        ]
    ];
}

function extractUpdates(string $aiResponse): ?array
{
    // Method 1: Try to extract config_update JSON block
    if (preg_match('/```config_update\s*\n([\s\S]*?)\n```/', $aiResponse, $matches)) {
        $json = json_decode(trim($matches[1]), true);
        if ($json && isset($json['sections'])) {
            return $json;
        }
    }
    
    // Method 2: Try to find {"sections":{...}} JSON block
    if (preg_match('/```(?:json)?\s*\n(\{[\s\S]*?"sections"[\s\S]*?\})\s*\n```/', $aiResponse, $matches)) {
        $json = json_decode(trim($matches[1]), true);
        if ($json && isset($json['sections'])) {
            return $json;
        }
    }

    // Method 3: Parse raw section data from AI text (most common case)
    // The AI often outputs sections like: "0\n0 0 -1 ...\nend"
    return extractRawSections($aiResponse);
}

/**
 * Extract raw section data from AI text response
 * Parses patterns like: "0\n<data rows>\nend" for each section
 */
function extractRawSections(string $text): ?array
{
    $sectionDefs = [
        0 => ['Index','Type','AccountLevel','SettingIndex','RequiredLevelResets','RangeStart','RangeEnd','ReqWCCoins','ReqWPCoins','ReqGoblinPoints','ZenType','ZenValue','ItemReqIndex','RewardIndex'],
        1 => ['SettingIndex','Stats','CommandDL','LevelUpPoints','Inventory','Skills','Quests','SkillTree','GoblinPoints','ToBaseMap'],
        2 => ['ItemReqIndex','Type','Index','ItemLevel','LifeOption','Skill','Luck','Excellent','Durability','Count'],
        3 => ['RewardIndex','PointsType','LevelUpPoints','WCCoins','WPCoins','GoblinPoints','BagIndex'],
        4 => ['Type','Count','AccountLevel','BagIndex','BagCount','LevelUpPoints','WCCoins','WPCoins','GoblinPoints','Zen'],
        5 => ['Type','Limit_AL0','Limit_AL1','Limit_AL2','Limit_AL3'],
        6 => ['Type','StartMonth','StartDay','StartDoW','StartHour','StartMin'],
        7 => ['Index','DW','DK','FE','MG','DL','SU','RF'],
        8 => ['Type','CountMin','CountMax','AccountLevel','AddLevel','AddMasterLevel','AddMLPoints','AddResets','ResetLevel'],
    ];

    $sections = [];
    
    // Find all section blocks: starts with a single digit on its own line, ends with "end"
    // Pattern: number\n<rows>\nend
    if (preg_match_all('/(?:^|\n)\s*(\d)\s*\n([\s\S]*?)\n\s*end\b/m', $text, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $secId = (int)$match[1];
            $body = trim($match[2]);
            
            if (!isset($sectionDefs[$secId]) || empty($body)) continue;
            
            $fields = $sectionDefs[$secId];
            $rows = [];
            
            foreach (explode("\n", $body) as $line) {
                $line = trim($line);
                // Skip comments and empty
                if (empty($line) || $line[0] === '/' || $line[0] === '#') continue;
                // Remove inline comments
                $line = preg_replace('/\s*\/\/.*$/', '', $line);
                $line = trim($line);
                if (empty($line)) continue;
                
                $values = preg_split('/\s+/', $line);
                if (count($values) < 2) continue; // Need at least 2 values
                
                $row = [];
                foreach ($fields as $i => $field) {
                    $row[$field] = $values[$i] ?? '0';
                }
                $rows[] = $row;
            }
            
            if (!empty($rows)) {
                $sections[(string)$secId] = ['rows' => $rows];
            }
        }
    }
    
    if (empty($sections)) return null;
    
    return ['sections' => $sections];
}

/**
 * Remove the config_update JSON block from the AI response text (don't show it to user)
 */
function cleanAIResponse(string $response): string
{
    // Remove ```config_update ... ``` blocks
    $cleaned = preg_replace('/\s*```config_update\s*\n[\s\S]*?\n```\s*/', '', $response);
    // Remove ```json blocks that contain sections
    $cleaned = preg_replace('/\s*```json\s*\n\{[\s\S]*?"sections"[\s\S]*?\}\s*\n```\s*/', '', $cleaned);
    // Remove raw section blocks (0\n...\nend) that the AI outputs
    // Only remove if they look like section data (digit followed by tabular data)
    $cleaned = preg_replace('/\n?\s*(?:```[a-z]*\s*\n)?\s*\d\s*\n(?:\s*\/\/[^\n]*\n)*(?:\s*[-\d\*]+(?:\s+[-\d\*]+)+\s*\n)+\s*end\s*(?:\n\s*```)?/m', '', $cleaned);
    return trim($cleaned);
}

function validateUserToken(): string
{
    $token = $_SERVER['HTTP_X_TOKEN'] ?? $_GET['token'] ?? '';

    if (empty($token)) {
        jsonResponse(['error' => 'Token required'], 401);
    }

    // Accept simple password access
    if ($token === 'MSProTeam') {
        logUsage('public', 'access');
        return 'public';
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT id, is_active, expires_at FROM tokens WHERE token = ?");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || !$row['is_active']) {
        jsonResponse(['error' => 'Invalid token'], 401);
    }

    if ($row['expires_at'] && strtotime($row['expires_at']) < time()) {
        jsonResponse(['error' => 'Token expired'], 401);
    }

    // Update usage count
    $stmt = $db->prepare("UPDATE tokens SET usage_count = usage_count + 1 WHERE token = ?");
    $stmt->execute([$token]);

    return $token;
}

function getAdminRules(string $token): array
{
    $db = getDB();
    $section = 'general';

    if ($token !== 'public') {
        // Get token's section
        $stmt = $db->prepare("SELECT section FROM tokens WHERE token = ?");
        $stmt->execute([$token]);
        $found = $stmt->fetchColumn();
        if ($found) $section = $found;
    }

    // Get rules for that section + global
    $stmt = $db->prepare("SELECT rule_value FROM admin_rules WHERE section = ? OR section = 'global'");
    $stmt->execute([$section]);

    return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'rule_value');
}

function logUsage(string $token, string $action, int $tokensIn = 0, int $tokensOut = 0): void
{
    try {
        $db = getDB();
        
        // Ensure columns exist (auto-migrate)
        try {
            $db->exec("ALTER TABLE usage_log ADD COLUMN IF NOT EXISTS tokens_in INT DEFAULT 0");
            $db->exec("ALTER TABLE usage_log ADD COLUMN IF NOT EXISTS tokens_out INT DEFAULT 0");
            $db->exec("ALTER TABLE usage_log ADD COLUMN IF NOT EXISTS cost_usd DECIMAL(10,6) DEFAULT 0");
        } catch (Exception $e) {
            // Columns might already exist
        }
        
        // Ollama es gratis
        $costUsd = 0;
        
        $stmt = $db->prepare("INSERT INTO usage_log (token, action, ip_address, tokens_in, tokens_out, cost_usd) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$token, $action, $_SERVER['REMOTE_ADDR'] ?? '', $tokensIn, $tokensOut, $costUsd]);
    } catch (Exception $e) {
        // Silent fail for logging
    }
}

function getJsonInput(): array
{
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?: [];
}

function jsonResponse(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}