<?php
/**
 * Script de Teste de Sincronização
 * Simula a sincronização para capturar o erro exato
 * 
 * Execute: php test_sincronizacao.php
 * Ou acesse via browser: gestao.alencarmartinazzo.adv.br/test_sincronizacao.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h1>🔍 Teste de Sincronização - Debug</h1>";
echo "<style>
    body { font-family: monospace; background: #1e1e1e; color: #d4d4d4; padding: 20px; }
    .success { color: #4ec9b0; }
    .error { color: #f48771; font-weight: bold; }
    .warning { color: #dcdcaa; }
    .info { color: #569cd6; }
    pre { background: #2d2d2d; padding: 15px; border-left: 3px solid #007acc; overflow-x: auto; }
    h2 { color: #4ec9b0; border-bottom: 1px solid #555; padding-bottom: 5px; }
</style>";

echo "<p class='info'>⏰ Iniciado em: " . date('d/m/Y H:i:s') . "</p>";
echo "<hr>";

// Passo 1: Carregar configurações
echo "<h2>Passo 1: Carregando Configurações</h2>";

try {
    if (!file_exists(__DIR__ . '/config/database.php')) {
        throw new Exception('❌ config/database.php não encontrado');
    }
    require_once __DIR__ . '/config/database.php';
    echo "<p class='success'>✅ database.php carregado</p>";
    
    if (!file_exists(__DIR__ . '/config/api.php')) {
        throw new Exception('❌ config/api.php não encontrado');
    }
    require_once __DIR__ . '/config/api.php';
    echo "<p class='success'>✅ config/api.php carregado</p>";
    
} catch (Exception $e) {
    echo "<p class='error'>" . $e->getMessage() . "</p>";
    exit;
}

// Passo 2: Verificar funções
echo "<h2>Passo 2: Verificando Funções Necessárias</h2>";

$funcoes = [
    'buscarPublicacoesRecentes',
    'buscarPublicacoesPorData', 
    'apiDisponivel',
    'validarHashCliente',
    'marcarComoProcessadaAPI',
    'vincularProcesso'
];

$erro_funcao = false;
foreach ($funcoes as $f) {
    if (function_exists($f)) {
        echo "<p class='success'>✅ $f() existe</p>";
    } else {
        echo "<p class='error'>❌ $f() NÃO EXISTE!</p>";
        $erro_funcao = true;
    }
}

if ($erro_funcao) {
    echo "<p class='error'>⚠️ Funções faltando! Sincronização não funcionará.</p>";
    exit;
}

// Passo 3: Verificar função errada
echo "<h2>Passo 3: Verificando Função Problemática</h2>";

if (function_exists('buscarPublicacoesAPI')) {
    echo "<p class='warning'>⚠️ buscarPublicacoesAPI() existe (inesperado)</p>";
} else {
    echo "<p class='error'>❌ buscarPublicacoesAPI() NÃO EXISTE</p>";
    echo "<p class='info'>📌 Esta é a função que api.php está tentando chamar na linha 196!</p>";
    echo "<pre>// Código atual (ERRADO):\n\$resultado_api = buscarPublicacoesAPI('0', 3);\n\n// Deve ser (CORRETO):\n\$resultado_api = buscarPublicacoesRecentes(3);</pre>";
}

// Passo 4: Validar hash
echo "<h2>Passo 4: Validando Hash do Cliente</h2>";

if (defined('PUBLICACOES_HASH_CLIENTE')) {
    $hash = PUBLICACOES_HASH_CLIENTE;
    $hash_exibir = substr($hash, 0, 8) . '...' . substr($hash, -8);
    echo "<p class='info'>Hash: $hash_exibir</p>";
    echo "<p class='info'>Tamanho: " . strlen($hash) . " caracteres</p>";
    
    if (validarHashCliente()) {
        echo "<p class='success'>✅ Hash válido (32 caracteres)</p>";
    } else {
        echo "<p class='error'>❌ Hash inválido</p>";
    }
} else {
    echo "<p class='error'>❌ PUBLICACOES_HASH_CLIENTE não definida</p>";
}

// Passo 5: Verificar disponibilidade da API
echo "<h2>Passo 5: Verificando Disponibilidade da API</h2>";

$disp = apiDisponivel();
if ($disp['disponivel']) {
    echo "<p class='success'>✅ API disponível</p>";
    echo "<p class='info'>Dia da semana: " . date('l') . " (" . date('w') . ")</p>";
    echo "<p class='info'>Horário: " . date('H:i') . "</p>";
} else {
    echo "<p class='error'>❌ API indisponível: " . $disp['motivo'] . "</p>";
    echo "<p class='warning'>A sincronização não funcionará neste momento!</p>";
}

// Passo 6: Simular chamada da função
echo "<h2>Passo 6: Simulando Sincronização</h2>";

echo "<p class='info'>🔄 Tentando chamar buscarPublicacoesRecentes(3)...</p>";

try {
    // Verificar se pode fazer requisição
    if (!$disp['disponivel']) {
        throw new Exception("API não disponível: " . $disp['motivo']);
    }
    
    // Tentar buscar publicações
    $resultado = buscarPublicacoesRecentes(3);
    
    echo "<pre>";
    echo "Resultado:\n";
    echo "  success: " . ($resultado['success'] ? 'true' : 'false') . "\n";
    echo "  total: " . ($resultado['total'] ?? 0) . "\n";
    echo "  message: " . ($resultado['message'] ?? 'N/A') . "\n";
    
    if (!empty($resultado['por_data'])) {
        echo "  por_data:\n";
        foreach ($resultado['por_data'] as $data => $qtd) {
            echo "    $data: $qtd publicações\n";
        }
    }
    echo "</pre>";
    
    if ($resultado['success']) {
        echo "<p class='success'>✅ Função executou com sucesso!</p>";
        
        if ($resultado['total'] > 0) {
            echo "<p class='success'>🎉 {$resultado['total']} publicações encontradas!</p>";
            
            // Mostrar primeira publicação como exemplo
            if (!empty($resultado['data'][0])) {
                echo "<h3>📄 Exemplo de Publicação:</h3>";
                echo "<pre>";
                $pub = $resultado['data'][0];
                echo "ID WS: " . ($pub['idWs'] ?? 'N/A') . "\n";
                echo "Número CNJ: " . ($pub['numeroProcessoCNJ'] ?? 'N/A') . "\n";
                echo "Tribunal: " . ($pub['orgao'] ?? 'N/A') . "\n";
                echo "Data: " . ($pub['dataPublicacao'] ?? 'N/A') . "\n";
                echo "</pre>";
            }
        } else {
            echo "<p class='warning'>⚠️ Nenhuma publicação nova encontrada (normal se já sincronizou recentemente)</p>";
        }
    } else {
        echo "<p class='error'>❌ Erro na função: " . ($resultado['message'] ?? 'Erro desconhecido') . "</p>";
    }
    
} catch (Exception $e) {
    echo "<p class='error'>❌ ERRO CAPTURADO:</p>";
    echo "<pre class='error'>" . $e->getMessage() . "</pre>";
    echo "<pre class='error'>Stack trace:\n" . $e->getTraceAsString() . "</pre>";
}

// Passo 7: Resumo do problema
echo "<h2>Passo 7: 🎯 Resumo do Problema</h2>";

echo "<div style='background: #2d2d2d; padding: 20px; border-left: 4px solid #f48771;'>";
echo "<h3 class='error'>🔴 Problema Identificado:</h3>";
echo "<p>O arquivo <code>modules/publicacoes/api.php</code> está chamando:</p>";
echo "<pre>buscarPublicacoesAPI('0', 3)  // ❌ Esta função NÃO EXISTE</pre>";
echo "<p>Mas deveria estar chamando:</p>";
echo "<pre>buscarPublicacoesRecentes(3)  // ✅ Esta função EXISTE e funciona</pre>";

echo "<h3 class='success'>✅ Solução:</h3>";
echo "<ol>";
echo "<li>Abra o arquivo: <code>modules/publicacoes/api.php</code></li>";
echo "<li>Vá até a linha <strong>196</strong></li>";
echo "<li>Substitua o código conforme acima</li>";
echo "<li>Salve e teste novamente</li>";
echo "</ol>";
echo "</div>";

// Passo 8: Teste de conectividade com API
echo "<h2>Passo 8: Testando Conectividade com API Externa</h2>";

if ($disp['disponivel']) {
    echo "<p class='info'>🌐 Testando conexão com publicacoesonline.com.br...</p>";
    
    try {
        $test_url = PUBLICACOES_API_BASE_URL . 'index_pe.php?hashCliente=' . PUBLICACOES_HASH_CLIENTE . '&data=' . date('Y-m-d') . '&processadas=T&retorno=JSON';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $test_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        echo "<p class='info'>HTTP Status: $http_code</p>";
        
        if ($curl_error) {
            echo "<p class='error'>❌ Erro cURL: $curl_error</p>";
        } else if ($http_code == 200) {
            echo "<p class='success'>✅ Conexão com API OK</p>";
            
            $data = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                echo "<p class='success'>✅ JSON válido recebido</p>";
                
                if (isset($data['codigo'])) {
                    echo "<p class='info'>Código API: {$data['codigo']}</p>";
                    echo "<p class='info'>Mensagem: " . ($data['mensagem'] ?? 'N/A') . "</p>";
                }
            } else {
                echo "<p class='error'>❌ Erro ao decodificar JSON: " . json_last_error_msg() . "</p>";
            }
        } else {
            echo "<p class='error'>❌ HTTP $http_code (esperado 200)</p>";
        }
        
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro no teste: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p class='warning'>⏭️ Teste de conectividade pulado (API indisponível no momento)</p>";
}

// Final
echo "<hr>";
echo "<p class='info'>✅ Diagnóstico concluído em: " . date('d/m/Y H:i:s') . "</p>";
echo "<p class='info'>📧 Se precisar de ajuda, envie este relatório completo.</p>";
?>