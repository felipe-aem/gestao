<?php
/**
 * Script de Teste - API Publicações Online - VERSÃO DEFINITIVA
 * Testa usando index_pe.php conforme recomendação do SUPORTE
 * 
 * Uso: php test_api_definitivo.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Carregar configurações
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/api.php';

echo "\n";
echo "╔════════════════════════════════════════════════════════╗\n";
echo "║  TESTE DE CONEXÃO - API PUBLICAÇÕES ONLINE v2.0       ║\n";
echo "║  Usando index_pe.php (recomendado pelo suporte)       ║\n";
echo "╚════════════════════════════════════════════════════════╝\n";
echo "\n";

$testes_passou = 0;
$testes_falhou = 0;

// ==========================================
// TESTE 1: Verificar Hash do Cliente
// ==========================================
echo "┌─ TESTE 1: Hash do Cliente\n";

if (defined('PUBLICACOES_HASH_CLIENTE')) {
    $hash = PUBLICACOES_HASH_CLIENTE;
    $hash_preview = substr($hash, 0, 10) . '...' . substr($hash, -10);
    $hash_length = strlen($hash);
    
    echo "│  Hash: $hash_preview\n";
    echo "│  Tamanho: $hash_length caracteres\n";
    
    if ($hash_length === 32) {
        echo "│  ✅ PASSOU - Hash válido (32 caracteres)\n";
        $testes_passou++;
    } else {
        echo "│  ❌ FALHOU - Hash deve ter 32 caracteres\n";
        $testes_falhou++;
    }
} else {
    echo "│  ❌ FALHOU - Hash não configurado\n";
    $testes_falhou++;
}
echo "└─────────────────────────────────────────────\n\n";

// ==========================================
// TESTE 2: Verificar Disponibilidade
// ==========================================
echo "┌─ TESTE 2: Disponibilidade da API\n";

$dia_semana = date('w');
$dia_nome = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'][$dia_semana];
$hora_atual = date('H:i:s');

echo "│  Dia: $dia_nome\n";
echo "│  Hora: $hora_atual\n";

if ($dia_semana == 0) {
    echo "│  ⚠️  AVISO - API indisponível aos domingos\n";
    echo "│  Teste interrompido (executar em dia útil)\n";
    echo "└─────────────────────────────────────────────\n\n";
    exit(0);
} else {
    echo "│  ✅ PASSOU - Dia útil (API disponível)\n";
    $testes_passou++;
}

$hora = (int)date('H');
$minuto = (int)date('i');

if ($hora == 0 && $minuto < 10) {
    echo "│  ⚠️  AVISO - API disponível apenas após 00:10\n";
    $testes_falhou++;
} else {
    echo "│  ✅ PASSOU - Horário adequado\n";
    $testes_passou++;
}

echo "└─────────────────────────────────────────────\n\n";

// ==========================================
// TESTE 3: Verificar Endpoint
// ==========================================
echo "┌─ TESTE 3: Endpoint Configurado\n";

echo "│  Endpoint: " . PUBLICACOES_ENDPOINT_PUBLICACOES . "\n";

if (filter_var(PUBLICACOES_ENDPOINT_PUBLICACOES, FILTER_VALIDATE_URL)) {
    echo "│  ✅ URL válida\n";
    $testes_passou++;
} else {
    echo "│  ❌ URL inválida\n";
    $testes_falhou++;
}

echo "└─────────────────────────────────────────────\n\n";

// ==========================================
// TESTE 4: Teste de Conexão - Data HOJE
// ==========================================
echo "┌─ TESTE 4: Teste com Data de HOJE\n";

$data_hoje = date('Y-m-d');
echo "│  Data: $data_hoje\n";
echo "│  Fazendo requisição...\n";

$params = [
    'hashCliente' => PUBLICACOES_HASH_CLIENTE,
    'data' => $data_hoje,
    'processadas' => 'T', // T = todas (para teste)
    'retorno' => 'JSON'
];

$url = PUBLICACOES_ENDPOINT_PUBLICACOES . '?' . http_build_query($params);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json'
]);

$response_hoje = curl_exec($ch);
$http_code_hoje = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

echo "│  HTTP Code: $http_code_hoje\n";

if ($curl_error) {
    echo "│  ❌ ERRO cURL: $curl_error\n";
    $testes_falhou++;
} elseif ($http_code_hoje !== 200) {
    echo "│  ❌ FALHOU - HTTP Code inesperado\n";
    echo "│  Resposta: " . substr($response_hoje, 0, 150) . "...\n";
    $testes_falhou++;
} else {
    echo "│  ✅ PASSOU - Conexão estabelecida\n";
    $testes_passou++;
}

echo "└─────────────────────────────────────────────\n\n";

// ==========================================
// TESTE 5: Teste com Data ANTERIOR (7 dias atrás)
// ==========================================
echo "┌─ TESTE 5: Teste com Data ANTERIOR (7 dias atrás)\n";

$data_anterior = date('Y-m-d', strtotime('-7 days'));
echo "│  Data: $data_anterior\n";
echo "│  Fazendo requisição...\n";

$params_anterior = [
    'hashCliente' => PUBLICACOES_HASH_CLIENTE,
    'data' => $data_anterior,
    'processadas' => 'N', // N = não processadas
    'retorno' => 'JSON'
];

$url_anterior = PUBLICACOES_ENDPOINT_PUBLICACOES . '?' . http_build_query($params_anterior);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url_anterior);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$response_anterior = curl_exec($ch);
$http_code_anterior = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "│  HTTP Code: $http_code_anterior\n";

if ($http_code_anterior !== 200) {
    echo "│  ⚠️  HTTP inesperado\n";
} else {
    echo "│  ✅ Conexão OK\n";
    $testes_passou++;
}

echo "└─────────────────────────────────────────────\n\n";

// ==========================================
// TESTE 6: Analisar Respostas JSON
// ==========================================
echo "┌─ TESTE 6: Análise das Respostas JSON\n";

// Analisar resposta de HOJE
if ($http_code_hoje === 200 && !empty($response_hoje)) {
    echo "│\n│  === RESPOSTA HOJE ($data_hoje) ===\n";
    
    $data_hoje_json = json_decode($response_hoje, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "│  ❌ JSON inválido: " . json_last_error_msg() . "\n";
        $testes_falhou++;
    } else {
        echo "│  ✅ JSON válido\n";
        $testes_passou++;
        
        // Verificar estrutura
        if (isset($data_hoje_json['codigo'])) {
            $codigo = $data_hoje_json['codigo'];
            $mensagem = $data_hoje_json['mensagem'] ?? 'Sem mensagem';
            
            echo "│  Código: $codigo\n";
            echo "│  Mensagem: $mensagem\n";
            
            if ($codigo == 912) {
                echo "│  ✅ Normal - Sem publicações hoje\n";
            } elseif (in_array($codigo, [100, 101, 102])) {
                echo "│  ❌ ERRO - Problema de autenticação!\n";
                echo "│  → Verificar hash com o suporte\n";
            } elseif ($codigo == 910) {
                echo "│  ⚠️  AVISO - Rate limit excedido\n";
            }
            
        } elseif (is_array($data_hoje_json)) {
            $total = count($data_hoje_json);
            echo "│  Total de registros: $total\n";
            
            if ($total > 0) {
                echo "│  ✅ Publicações encontradas!\n";
                echo "│\n│  Exemplo do primeiro registro:\n";
                $primeiro = $data_hoje_json[0];
                foreach ($primeiro as $campo => $valor) {
                    $valor_preview = is_string($valor) ? substr($valor, 0, 50) : $valor;
                    echo "│    - $campo: $valor_preview\n";
                }
            } else {
                echo "│  ✅ Array vazio (sem publicações hoje)\n";
            }
        }
    }
}

// Analisar resposta de 7 dias atrás
if ($http_code_anterior === 200 && !empty($response_anterior)) {
    echo "│\n│  === RESPOSTA 7 DIAS ATRÁS ($data_anterior) ===\n";
    
    $data_anterior_json = json_decode($response_anterior, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "│  ❌ JSON inválido\n";
    } else {
        if (isset($data_anterior_json['codigo'])) {
            $codigo = $data_anterior_json['codigo'];
            echo "│  Código: $codigo\n";
            
            if ($codigo == 912) {
                echo "│  Sem publicações nesta data\n";
            }
        } elseif (is_array($data_anterior_json)) {
            $total = count($data_anterior_json);
            echo "│  Total: $total publicações\n";
            
            if ($total > 0) {
                echo "│  🎉 SUCESSO - Encontrou publicações antigas!\n";
            }
        }
    }
}

echo "└─────────────────────────────────────────────\n\n";

// ==========================================
// TESTE 7: Verificar Banco de Dados
// ==========================================
echo "┌─ TESTE 7: Verificação do Banco de Dados\n";

try {
    $pdo = getConnection();
    echo "│  ✅ Conexão com banco OK\n";
    $testes_passou++;
    
    // Verificar tabela publicacoes
    $sql = "SHOW TABLES LIKE 'publicacoes'";
    $stmt = $pdo->query($sql);
    
    if ($stmt->rowCount() > 0) {
        echo "│  ✅ Tabela 'publicacoes' existe\n";
        $testes_passou++;
        
        // Verificar se tem index único no id_ws
        $sql_index = "SHOW INDEX FROM publicacoes WHERE Column_name = 'id_ws'";
        $stmt_index = $pdo->query($sql_index);
        $indices = $stmt_index->fetchAll();
        
        $tem_unique = false;
        foreach ($indices as $index) {
            if ($index['Non_unique'] == 0) {
                $tem_unique = true;
                break;
            }
        }
        
        if ($tem_unique) {
            echo "│  ✅ Índice UNIQUE no id_ws existe\n";
            $testes_passou++;
        } else {
            echo "│  ⚠️  Recomendado: Criar índice UNIQUE no id_ws\n";
            echo "│    SQL: ALTER TABLE publicacoes ADD UNIQUE INDEX idx_id_ws (id_ws);\n";
        }
        
    } else {
        echo "│  ❌ Tabela 'publicacoes' não existe\n";
        $testes_falhou++;
    }
    
} catch (Exception $e) {
    echo "│  ❌ Erro: " . $e->getMessage() . "\n";
    $testes_falhou++;
}

echo "└─────────────────────────────────────────────\n\n";

// ==========================================
// RESUMO FINAL
// ==========================================
echo "╔════════════════════════════════════════════════════════╗\n";
echo "║  RESUMO DOS TESTES                                     ║\n";
echo "╚════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "  ✅ Testes Passou: $testes_passou\n";
echo "  ❌ Testes Falhou: $testes_falhou\n";
echo "\n";

if ($testes_falhou == 0) {
    echo "  🎉 TUDO OK! Sistema configurado corretamente.\n";
    echo "\n";
    echo "  Próximos passos:\n";
    echo "  1. Executar sincronização: php cli/process_publications.php\n";
    echo "  2. Verificar logs em: logs/sincronizacao_publicacoes.log\n";
    echo "  3. Se não houver publicações, contatar suporte\n";
} else {
    echo "  ⚠️  ATENÇÃO! Alguns testes falharam.\n";
    echo "\n";
    echo "  Corrija os problemas antes de continuar.\n";
}

echo "\n";
echo "╔════════════════════════════════════════════════════════╗\n";
echo "║  INFORMAÇÕES PARA O SUPORTE (se necessário)           ║\n";
echo "╚════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "Hash do Cliente:\n";
echo "  " . PUBLICACOES_HASH_CLIENTE . "\n";
echo "\n";
echo "Endpoint Utilizado:\n";
echo "  " . PUBLICACOES_ENDPOINT_PUBLICACOES . "\n";
echo "\n";
echo "Parâmetros Testados:\n";
echo "  - hashCliente: [seu_hash]\n";
echo "  - data: $data_hoje (hoje)\n";
echo "  - data: $data_anterior (7 dias atrás)\n";
echo "  - processadas: N (não processadas)\n";
echo "  - retorno: JSON\n";
echo "\n";
echo "Data/Hora do Teste:\n";
echo "  " . date('d/m/Y H:i:s') . "\n";
echo "\n";

if ($http_code_hoje === 200 && !empty($response_hoje)) {
    echo "Resposta de HOJE (primeiros 300 caracteres):\n";
    echo "  " . substr($response_hoje, 0, 300) . "...\n";
    echo "\n";
}

if ($http_code_anterior === 200 && !empty($response_anterior)) {
    echo "Resposta de 7 DIAS ATRÁS (primeiros 300 caracteres):\n";
    echo "  " . substr($response_anterior, 0, 300) . "...\n";
    echo "\n";
}

echo "\n";
?>