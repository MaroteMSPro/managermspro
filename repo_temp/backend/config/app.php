<?php
<?php
/**
 * Application Configuration - MSPro Config AI
 * CONFIGURACIÓN OLLAMA
 */
define('APP_CONFIG', [
    // Configuración AI (Ollama)
    'ai_api' => [
        'type' => 'ollama',
        'url' => 'http://148.230.77.214:11434',
        'default_model' => 'qwen3:8b',
        'available_models' => [
            'qwen3:8b' => 'Qwen 3 8B (Recomendado - Rápido)',
            'qwen3.5:27b' => 'Qwen 3.5 27B (Lento - Mejor calidad)',
        ],
        'timeout' => 120,
        'temperature' => 0.7,
        'max_tokens' => 2500
    ],
    
    // JWT y otros
    'jwt_secret' => 'mspro_mgr_8f3k2j5h7g9d1s4a6p0w',
    'jwt_expiry' => 86400,
    'app_name' => 'MSPro Config AI',
    'version' => '1.0.0'
]);

/**
 * Obtener configuración de AI
 */
function getAIConfig(string $key = null) {
    $config = APP_CONFIG['ai_api'];
    return $key ? ($config[$key] ?? null) : $config;
}

/**
 * Obtener modelo actual
 */
function getCurrentModel(string $token = null): string {
    return getAIConfig('default_model');
}
?>
?>