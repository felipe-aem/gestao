<?php
/**
 * RELATÓRIO TÉCNICO DETALHADO - API PUBLICAÇÕES ONLINE
 * 
 * Este script gera um relatório completo para envio ao suporte técnico
 * Mostra toda a comunicação com a API e possíveis problemas
 * 
 * Desenvolvido para: Alencar Martinazzo Advocacia
 * Data: 24/10/2025
 */

// Configurações de exibição de erros
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Configuração da API
define('PUBLICACOES_HASH_CLIENTE', '1b4e3b1061eaa3182c5d2f08fdaaa346');
define('API_BASE_URL', 'https://www.publicacoesonline.com.br/');

// Função para logar informações
function logInfo($titulo, $conteudo, $tipo = 'info') {
    $icones = [
        'info' => '📋',
        'success' => '✅',
        'error' => '❌',
        'warning' => '⚠️',
        'data' => '📊'
    ];
    
    echo "\n" . str_repeat("=", 80) . "\n";
    echo $icones[$tipo] . " " . strtoupper($titulo) . "\n";
    echo str_repeat("=", 80) . "\n";
    echo $conteudo . "\n";
}

// Função para fazer requisição detalhada
function fazerRequisicao($nome_teste, $endpoint, $params, $metodo = 'GET', $post_data = null) {
    logInfo("TESTE: $nome_teste", "", 'info');
    
    $url_completa = API_BASE_URL . $endpoint;
    if (!empty($params)) {
        $url_completa .= '?' . http_build_query($params);
    }
    
    echo "🌐 URL: $url_completa\n";
    echo "📤 Método: $metodo\n";
    echo "⏰ Timestamp: " . date('Y-m-d H:i:s') . "\n";
    
    if ($metodo === 'POST' && $post_data) {
        echo "📦 POST Data: " . json_encode($post_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }
    
    echo "\n🔄 Iniciando requisição...\n";
    
    // Configurar cURL
    $ch = curl_init($url_completa);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_VERBOSE, false);
    
    // Headers
    $headers = [
        'Accept: application/json',
        'Accept-Charset: UTF-8, ISO-8859-1',
        'User-Agent: Sistema-Gestao-Alencar-Martinazzo/1.0 PHP/' . phpversion(),
        'Cache-Control: no-cache'
    ];
    
    if ($metodo === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($post_data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
    }
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    // Capturar informações da requisição
    $tempo_inicio = microtime(true);
    $response = curl_exec($ch);
    $tempo_fim = microtime(true);
    $tempo_decorrido = round(($tempo_fim - $tempo_inicio) * 1000, 2); // em ms
    
    // Informações da resposta
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $size_download = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
    $curl_errno = curl_errno($ch);
    $curl_error = curl_error($ch);
    $effective_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $redirect_count = curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);
    
    curl_close($ch);
    
    // Resultado da requisição
    logInfo("RESULTADO DA REQUISIÇÃO", "", 'data');
    echo "⏱️  Tempo de resposta: {$tempo_decorrido}ms\n";
    echo "📡 HTTP Status Code: $http_code\n";
    echo "📄 Content-Type: $content_type\n";
    echo "📦 Tamanho da resposta: " . number_format($size_download) . " bytes\n";
    echo "🔀 Redirecionamentos: $redirect_count\n";
    
    if ($effective_url !== $url_completa) {
        echo "🔗 URL efetiva (após redirecionamentos): $effective_url\n";
    }
    
    if ($curl_errno) {
        echo "❌ Erro cURL #$curl_errno: $curl_error\n";
        return [
            'sucesso' => false,
            'erro' => "Erro cURL: $curl_error",
            'codigo' => $curl_errno
        ];
    }
    
    if ($http_code !== 200) {
        logInfo("ERRO HTTP", "Status HTTP diferente de 200", 'error');
        echo "Resposta (primeiros 1000 caracteres):\n";
        echo "---\n";
        echo substr($response, 0, 1000) . "\n";
        echo "---\n";
        
        return [
            'sucesso' => false,
            'erro' => "HTTP Status $http_code",
            'resposta_raw' => $response
        ];
    }
    
    // Mostrar resposta bruta
    logInfo("RESPOSTA BRUTA", "", 'data');
    echo "Encoding original: ISO-8859-1 (conforme documentação)\n";
    echo "Tamanho: " . strlen($response) . " bytes\n\n";
    echo "Primeiros 2000 caracteres:\n";
    echo "---\n";
    echo substr($response, 0, 2000) . "\n";
    echo "---\n";
    
    if (strlen($response) > 2000) {
        echo "\n(...) Resposta truncada. Total: " . strlen($response) . " bytes\n";
    }
    
    // Conversão de encoding
    logInfo("CONVERSÃO DE ENCODING", "", 'info');
    $response_utf8 = @iconv("ISO-8859-1", "UTF-8//TRANSLIT//IGNORE", $response);
    
    if ($response_utf8 === false) {
        echo "❌ FALHA na conversão ISO-8859-1 para UTF-8\n";
        echo "Tentando usar a resposta original...\n";
        $response_utf8 = $response;
    } else {
        echo "✅ Conversão de encoding bem-sucedida\n";
    }
    
    // Decodificação JSON
    logInfo("DECODIFICAÇÃO JSON", "", 'info');
    $data = @json_decode($response_utf8, true);
    $json_error = json_last_error();
    $json_error_msg = json_last_error_msg();
    
    if ($json_error !== JSON_ERROR_NONE) {
        echo "❌ ERRO na decodificação JSON\n";
        echo "Código do erro: $json_error\n";
        echo "Mensagem: $json_error_msg\n\n";
        
        echo "Possíveis causas:\n";
        echo "1. Resposta não é JSON válido\n";
        echo "2. Problema de encoding não resolvido\n";
        echo "3. API retornando HTML ou outro formato\n\n";
        
        echo "Resposta UTF-8 (primeiros 500 chars):\n";
        echo "---\n";
        echo substr($response_utf8, 0, 500) . "\n";
        echo "---\n";
        
        return [
            'sucesso' => false,
            'erro' => "Erro JSON: $json_error_msg",
            'resposta_raw' => $response_utf8
        ];
    }
    
    echo "✅ JSON decodificado com sucesso\n";
    
    // Análise da estrutura
    logInfo("ANÁLISE DA ESTRUTURA JSON", "", 'data');
    
    echo "📌 Tipo de dado: " . gettype($data) . "\n";
    
    if (is_array($data)) {
        echo "📊 Total de elementos no primeiro nível: " . count($data) . "\n";
        
        if (!empty($data)) {
            echo "🔑 Chaves/índices no primeiro nível:\n";
            foreach (array_keys($data) as $key) {
                $tipo_valor = gettype($data[$key]);
                $count = is_array($data[$key]) ? count($data[$key]) : strlen((string)$data[$key]);
                echo "   - [$key] ($tipo_valor" . ($tipo_valor === 'array' ? ", $count itens" : ", $count chars") . ")\n";
            }
        }
        
        // Verificar códigos de erro da API
        if (isset($data['codigo']) || isset($data['erros'])) {
            logInfo("CÓDIGOS DE RESPOSTA DA API", "", 'warning');
            
            if (isset($data['codigo'])) {
                $codigo = $data['codigo'];
                $mensagem = $data['mensagem'] ?? 'Sem mensagem';
                
                echo "📟 Código: $codigo\n";
                echo "💬 Mensagem: $mensagem\n\n";
                
                // Documentação dos códigos
                $codigos_doc = [
                    '100' => '❌ CRÍTICO: Login ou senha inválidos - Verificar hash do cliente',
                    '101' => '❌ CRÍTICO: Cadastro Inativo - Contatar suporte',
                    '102' => '❌ CRÍTICO: Cliente Inadimplente - Regularizar pagamento',
                    '110' => '❌ ERRO: Autenticação falhou',
                    '111' => '❌ ERRO: Tipo de Exportação não configurada',
                    '112' => '❌ ERRO: Tipo de Exportação INATIVADO',
                    '150' => '❌ ERRO: Log Publicação',
                    '500' => '❌ ERRO: Erro na autenticação - Repetir ou contatar suporte',
                    '900' => '❌ CRÍTICO: Erro na autenticação por inadimplência',
                    '901' => '❌ CRÍTICO: Conta suspensa',
                    '902' => '❌ ERRO: Parâmetro hashCliente não informado',
                    '903' => '❌ ERRO: Parâmetro processadas inválido (use S, N, R, L ou T)',
                    '904' => '❌ ERRO: Parâmetro data não informado',
                    '905' => '❌ ERRO: Formato de data inválido (use YYYY-MM-DD)',
                    '906' => '❌ ERRO: Parâmetro data não pode conter hora',
                    '907' => '❌ ERRO: dataInicio e dataFim devem ter a mesma data',
                    '908' => '❌ ERRO: Hora de dataInicio deve ser menor que dataFim',
                    '910' => '🔥 LIMITE EXCEDIDO: Máximo de 50 consultas por hora. Aguardar 10 minutos',
                    '911' => '❌ ERRO: Parâmetro processadas exige especificação de data',
                    '912' => 'ℹ️  NORMAL: Nenhuma Publicação disponível nos parâmetros informados',
                    '920' => '❌ ERRO: Lista de retorno inválida',
                    '921' => '❌ ERRO: Lista de retorno vazia',
                    '1000' => '✅ SUCESSO: Lista de retorno processada com sucesso'
                ];
                
                if (isset($codigos_doc[$codigo])) {
                    echo "📖 Explicação: {$codigos_doc[$codigo]}\n";
                } else {
                    echo "⚠️  Código não documentado\n";
                }
            }
            
            if (isset($data['erros'])) {
                echo "\n🔴 ERROS ADICIONAIS:\n";
                echo json_encode($data['erros'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            }
        }
        
        // Procurar publicações
        logInfo("BUSCA POR PUBLICAÇÕES NA RESPOSTA", "", 'data');
        
        $publicacoes = [];
        $origem = '';
        
        // Tentar diferentes estruturas
        $tentativas = [
            'array_direto' => function($d) { return isset($d[0]) && is_array($d[0]) ? $d : null; },
            'publicacoes' => function($d) { return $d['publicacoes'] ?? null; },
            'dados' => function($d) { return $d['dados'] ?? null; },
            'data' => function($d) { return $d['data'] ?? null; },
            'intimacoes' => function($d) { return $d['intimacoes'] ?? null; },
            'result' => function($d) { return $d['result'] ?? null; },
            'results' => function($d) { return $d['results'] ?? null; },
            'items' => function($d) { return $d['items'] ?? null; }
        ];
        
        foreach ($tentativas as $nome => $extrator) {
            $resultado = $extrator($data);
            if ($resultado && is_array($resultado) && !empty($resultado)) {
                $publicacoes = $resultado;
                $origem = $nome;
                echo "✅ Publicações encontradas em: '$origem'\n";
                break;
            }
        }
        
        if (empty($publicacoes)) {
            echo "❌ NÃO foram encontradas publicações em nenhuma estrutura conhecida\n\n";
            echo "🔍 DUMP COMPLETO DA RESPOSTA (para análise):\n";
            echo "---\n";
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            echo "---\n";
        } else {
            $total = count($publicacoes);
            echo "📊 TOTAL DE PUBLICAÇÕES ENCONTRADAS: $total\n";
            
            if ($total > 0) {
                logInfo("ANÁLISE DA PRIMEIRA PUBLICAÇÃO", "", 'data');
                
                $primeira = $publicacoes[0];
                
                echo "📋 Campos disponíveis na publicação:\n";
                echo json_encode(array_keys($primeira), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
                
                echo "📌 Campos importantes:\n";
                $campos_importantes = [
                    'ID' => ['idWs', 'idWS', 'id'],
                    'Número CNJ' => ['numeroProcessoCNJ', 'numeroCNJ', 'numero_cnj'],
                    'Número Processo' => ['numeroProcesso', 'numero_processo', 'numeroPrincipal'],
                    'Tipo/Layout' => ['layout', 'tipo', 'tipo_documento'],
                    'Órgão/Tribunal' => ['orgao', 'tribunal', 'vara'],
                    'Comarca/Cidade' => ['comarca', 'cidade'],
                    'Data Publicação' => ['dataPublicacao', 'data', 'data_publicacao'],
                    'Data Disponibilização' => ['dataDisponibilizacao', 'dataDisponibilizacaoWebservice', 'data_disponibilizacao'],
                    'Polo Ativo' => ['parte_autora', 'poloAtivo', 'autor'],
                    'Polo Passivo' => ['parte_reu', 'poloPassivo', 'reu'],
                    'Conteúdo' => ['conteudo', 'texto', 'content'],
                    'MD5' => ['md5', 'hash']
                ];
                
                foreach ($campos_importantes as $label => $possiveis_campos) {
                    foreach ($possiveis_campos as $campo) {
                        if (isset($primeira[$campo])) {
                            $valor = $primeira[$campo];
                            if (is_string($valor) && strlen($valor) > 150) {
                                $valor = substr($valor, 0, 150) . '...';
                            }
                            echo "   $label [$campo]: $valor\n";
                            break;
                        }
                    }
                }
                
                echo "\n📄 DUMP COMPLETO DA PRIMEIRA PUBLICAÇÃO:\n";
                echo "---\n";
                echo json_encode($primeira, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
                echo "---\n";
                
                if ($total > 1) {
                    logInfo("ANÁLISE DA SEGUNDA PUBLICAÇÃO (para comparação)", "", 'data');
                    echo json_encode($publicacoes[1], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
                }
            }
        }
        
    } else {
        echo "❌ Resposta não é um array!\n";
        echo "Tipo: " . gettype($data) . "\n";
        echo "Conteúdo:\n";
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }
    
    return [
        'sucesso' => true,
        'data' => $data,
        'publicacoes' => $publicacoes ?? [],
        'total' => count($publicacoes ?? []),
        'tempo_ms' => $tempo_decorrido,
        'http_code' => $http_code
    ];
}

// ====================================================================================
// INÍCIO DO RELATÓRIO
// ====================================================================================

echo "\n";
echo "████████████████████████████████████████████████████████████████████████████████\n";
echo "█                                                                              █\n";
echo "█          RELATÓRIO TÉCNICO COMPLETO - API PUBLICAÇÕES ONLINE                █\n";
echo "█                                                                              █\n";
echo "████████████████████████████████████████████████████████████████████████████████\n";
echo "\n";

logInfo("INFORMAÇÕES DO SISTEMA", "", 'info');
echo "📅 Data/Hora do relatório: " . date('Y-m-d H:i:s (T)') . "\n";
echo "🏢 Cliente: Alencar Martinazzo Advocacia\n";
echo "🔑 Hash do Cliente: " . substr(PUBLICACOES_HASH_CLIENTE, 0, 10) . "..." . substr(PUBLICACOES_HASH_CLIENTE, -10) . "\n";
echo "🌐 URL Base da API: " . API_BASE_URL . "\n";
echo "💻 Versão PHP: " . phpversion() . "\n";
echo "🖥️  Sistema Operacional: " . php_uname() . "\n";
echo "🔧 cURL versão: " . curl_version()['version'] . "\n";
echo "🔐 OpenSSL versão: " . (curl_version()['ssl_version'] ?? 'N/A') . "\n";

logInfo("CONTEXTO DO PROBLEMA", "", 'warning');
echo "📋 SITUAÇÃO REPORTADA:\n";
echo "   • Sistema retorna 0 publicações na sincronização\n";
echo "   • Portal Publicações Online mostra 100+ publicações não lidas\n";
echo "   • E-mails de notificação sendo recebidos normalmente\n";
echo "   • Hash do cliente validado e correto\n\n";
echo "🎯 OBJETIVO DO RELATÓRIO:\n";
echo "   • Identificar por que a API não retorna as publicações\n";
echo "   • Verificar estrutura exata da resposta da API\n";
echo "   • Validar endpoints e parâmetros utilizados\n";
echo "   • Fornecer informações para o suporte técnico\n";

// ====================================================================================
// TESTE 1: ENDPOINT DE PUBLICAÇÕES NÃO LIDAS
// ====================================================================================

echo "\n\n";
$resultado1 = fazerRequisicao(
    "ENDPOINT DE PUBLICAÇÕES NÃO LIDAS (Recomendado pela documentação)",
    "index_data_publicacao_nao_lida.php",
    [
        'hashCliente' => PUBLICACOES_HASH_CLIENTE,
        'status' => '0', // 0 = não lidas
        'retorno' => 'JSON'
    ]
);

sleep(8); // Aguardar para respeitar rate limit

// ====================================================================================
// TESTE 2: ENDPOINT DE CONSUMO POR DATA (HOJE)
// ====================================================================================

echo "\n\n";
$data_hoje = date('Y-m-d');
$resultado2 = fazerRequisicao(
    "ENDPOINT DE CONSUMO POR DATA (hoje: $data_hoje)",
    "index_dist.php",
    [
        'hashCliente' => PUBLICACOES_HASH_CLIENTE,
        'data' => $data_hoje,
        'processadas' => 'T', // T = Todas
        'retorno' => 'JSON'
    ]
);

sleep(8); // Aguardar para respeitar rate limit

// ====================================================================================
// TESTE 3: ENDPOINT DE CONSUMO POR DATA (ONTEM)
// ====================================================================================

echo "\n\n";
$data_ontem = date('Y-m-d', strtotime('-1 day'));
$resultado3 = fazerRequisicao(
    "ENDPOINT DE CONSUMO POR DATA (ontem: $data_ontem)",
    "index_dist.php",
    [
        'hashCliente' => PUBLICACOES_HASH_CLIENTE,
        'data' => $data_ontem,
        'processadas' => 'T',
        'retorno' => 'JSON'
    ]
);

// ====================================================================================
// RESUMO EXECUTIVO
// ====================================================================================

logInfo("RESUMO EXECUTIVO", "", 'data');

$total_geral = ($resultado1['total'] ?? 0) + ($resultado2['total'] ?? 0) + ($resultado3['total'] ?? 0);

echo "📊 ESTATÍSTICAS GERAIS:\n";
echo "   • Endpoint Não Lidas: " . ($resultado1['total'] ?? 0) . " publicações\n";
echo "   • Endpoint Consumo (hoje): " . ($resultado2['total'] ?? 0) . " publicações\n";
echo "   • Endpoint Consumo (ontem): " . ($resultado3['total'] ?? 0) . " publicações\n";
echo "   • TOTAL: $total_geral publicações retornadas pela API\n\n";

if ($total_geral == 0) {
    echo "❌ PROBLEMA CONFIRMADO: API não retorna publicações\n\n";
    echo "🔍 POSSÍVEIS CAUSAS:\n";
    echo "   1. Limite de requisições excedido (código 910)\n";
    echo "   2. Publicações já foram marcadas como 'lidas' ou 'processadas'\n";
    echo "   3. Hash do cliente sem permissão ou inativo\n";
    echo "   4. Problema no servidor da API\n";
    echo "   5. As 100+ publicações são de outro hash/conta\n";
    echo "   6. Filtro de datas não está capturando as publicações\n\n";
    
    echo "💡 RECOMENDAÇÕES:\n";
    echo "   1. Aguardar 15 minutos e tentar novamente (rate limit)\n";
    echo "   2. Verificar no portal se as publicações estão marcadas como 'não lidas'\n";
    echo "   3. Confirmar que o hash usado é o mesmo do portal\n";
    echo "   4. Contatar suporte da Publicações Online com este relatório\n";
    echo "   5. Solicitar teste com datas específicas onde há publicações\n";
} else {
    echo "✅ API ESTÁ RETORNANDO PUBLICAÇÕES!\n\n";
    echo "🎯 PRÓXIMOS PASSOS:\n";
    echo "   1. Verificar se o código está processando corretamente\n";
    echo "   2. Confirmar mapeamento dos campos da resposta\n";
    echo "   3. Validar inserção no banco de dados\n";
}

logInfo("INFORMAÇÕES PARA O SUPORTE", "", 'warning');
echo "📧 AO CONTATAR O SUPORTE, INFORME:\n\n";
echo "1. DADOS DO CLIENTE:\n";
echo "   • Empresa: Alencar Martinazzo Advocacia\n";
echo "   • Hash: " . PUBLICACOES_HASH_CLIENTE . "\n";
echo "   • Problema: API retorna 0 publicações mas portal mostra 100+ não lidas\n\n";

echo "2. ENDPOINTS TESTADOS:\n";
echo "   • index_data_publicacao_nao_lida.php?status=0\n";
echo "   • index_dist.php?data=" . date('Y-m-d') . "&processadas=T\n\n";

echo "3. COMPORTAMENTO ESPERADO:\n";
echo "   • API deveria retornar as mesmas 100+ publicações visíveis no portal\n\n";

echo "4. COMPORTAMENTO ATUAL:\n";
echo "   • API retorna 0 publicações em todos os testes\n";
echo "   • Ou retorna código de erro (verificar acima)\n\n";

echo "5. PERGUNTAS PARA O SUPORTE:\n";
echo "   • Qual o status correto das publicações no meu hash?\n";
echo "   • Existe algum filtro adicional que devemos usar?\n";
echo "   • As publicações estão marcadas como 'lidas' mas não 'processadas'?\n";
echo "   • Há algum período de sincronização específico?\n";
echo "   • O hash está configurado corretamente na conta?\n";

echo "\n";
echo "████████████████████████████████████████████████████████████████████████████████\n";
echo "█                       FIM DO RELATÓRIO TÉCNICO                              █\n";
echo "████████████████████████████████████████████████████████████████████████████████\n";
echo "\n";
echo "📄 Este relatório foi gerado automaticamente e contém todas as informações\n";
echo "   técnicas necessárias para análise do problema.\n";
echo "\n";
echo "💾 RECOMENDAÇÃO: Salve este relatório completo e envie ao suporte técnico\n";
echo "   da Publicações Online junto com sua solicitação.\n";
echo "\n";
?>