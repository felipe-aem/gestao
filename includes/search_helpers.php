<?php
// includes/search_helpers.php

// Cada função está envolvida em if (!function_exists()) para evitar redeclarações

if (!function_exists('normalizarParaBusca')) {
    function normalizarParaBusca($texto) {
        // Remove pontos, traços, barras e espaços
        // Transforma "0001234-56.2025.8.24.0045" em "00012345620258240045"
    }
}

if (!function_exists('criarCondicaoBuscaNormalizada')) {
    function criarCondicaoBuscaNormalizada($campo, $valor, $pdo = null) {
        // Retorna SQL e parâmetros para busca normalizada
    }
}

if (!function_exists('gerarWhereBuscaNormalizada')) {
    function gerarWhereBuscaNormalizada($campo, $termo) {
        // Versão simplificada para WHERE
    }
}

if (!function_exists('buscarProcessosNormalizado')) {
    function buscarProcessosNormalizado($pdo, $termo, $filtros = []) {
        // Busca processos usando normalização
    }
}

if (!function_exists('buscarClientesNormalizado')) {
    function buscarClientesNormalizado($pdo, $termo, $limite = 50) {
        // Busca clientes usando normalização em CPF/CNPJ
    }
}

if (!function_exists('buscaGlobalNormalizada')) {
    function buscaGlobalNormalizada($pdo, $termo, $limite = 10) {
        // Busca global em processos, clientes, publicações e tarefas
    }
}

if (!function_exists('formatarNumeroProcesso')) {
    function formatarNumeroProcesso($numero) {
        // Formata número de processo no padrão CNJ
    }
}

if (!function_exists('formatarCPF')) {
    function formatarCPF($cpf) {
        // Formata CPF: 123.456.789-00
    }
}

if (!function_exists('formatarCNPJ')) {
    function formatarCNPJ($cnpj) {
        // Formata CNPJ: 12.345.678/0001-99
    }
}

/**
 * Remove pontuações e caracteres especiais para normalizar a busca
 * Útil para números de processo, CPF, CNPJ, etc.
 */
function normalizarParaBusca($texto) {
    if (empty($texto)) {
        return '';
    }
    
    // Remove pontos, traços, barras e espaços
    $normalizado = preg_replace('/[.\-\/\s]/', '', $texto);
    
    // Remove outros caracteres especiais, mantendo apenas letras e números
    $normalizado = preg_replace('/[^a-zA-Z0-9]/', '', $normalizado);
    
    return $normalizado;
}

/**
 * Cria uma condição SQL que busca tanto o valor original quanto o normalizado
 * 
 * @param string $campo Nome do campo no banco de dados
 * @param string $valor Valor a ser buscado
 * @param PDO $pdo Conexão PDO para usar prepared statements
 * @return array ['sql' => string, 'params' => array]
 */
function criarCondicaoBuscaNormalizada($campo, $valor, $pdo = null) {
    $valorNormalizado = normalizarParaBusca($valor);
    
    if (empty($valorNormalizado)) {
        return ['sql' => '1=0', 'params' => []];
    }
    
    // Busca tanto no campo original quanto na versão normalizada
    $sql = "(
        REPLACE(REPLACE(REPLACE(REPLACE($campo, '.', ''), '-', ''), '/', ''), ' ', '') LIKE :valor_norm
        OR $campo LIKE :valor_orig
    )";
    
    $params = [
        ':valor_norm' => '%' . $valorNormalizado . '%',
        ':valor_orig' => '%' . $valor . '%'
    ];
    
    return ['sql' => $sql, 'params' => $params];
}

/**
 * Versão simplificada para usar em queries WHERE simples
 */
function gerarWhereBuscaNormalizada($campo, $termo) {
    $termoNormalizado = normalizarParaBusca($termo);
    $termoEscapado = str_replace("'", "''", $termo);
    $termoNormalizadoEscapado = str_replace("'", "''", $termoNormalizado);
    
    return "(
        REPLACE(REPLACE(REPLACE(REPLACE($campo, '.', ''), '-', ''), '/', ''), ' ', '') LIKE '%{$termoNormalizadoEscapado}%'
        OR $campo LIKE '%{$termoEscapado}%'
    )";
}

/**
 * Função para buscar processos com normalização
 * 
 * @param PDO $pdo Conexão com banco de dados
 * @param string $termo Termo de busca
 * @param array $filtros Filtros adicionais opcionais
 * @return array Resultados da busca
 */
function buscarProcessosNormalizado($pdo, $termo, $filtros = []) {
    $where = ["1=1"];
    $params = [];
    
    // Busca no número do processo (normalizada)
    if (!empty($termo)) {
        $busca = criarCondicaoBuscaNormalizada('p.numero_processo', $termo);
        $where[] = "({$busca['sql']} OR c.nome LIKE :termo_cliente)";
        $params = array_merge($params, $busca['params']);
        $params[':termo_cliente'] = '%' . $termo . '%';
    }
    
    // Filtros adicionais
    if (!empty($filtros['status'])) {
        $where[] = "p.status = :status";
        $params[':status'] = $filtros['status'];
    }
    
    if (!empty($filtros['cliente_id'])) {
        $where[] = "p.cliente_id = :cliente_id";
        $params[':cliente_id'] = $filtros['cliente_id'];
    }
    
    $sql = "
        SELECT 
            p.*,
            c.nome as cliente_nome,
            c.cpf_cnpj
        FROM processos p
        LEFT JOIN clientes c ON p.cliente_id = c.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY p.data_criacao DESC
        LIMIT 50
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Função para buscar clientes com normalização de CPF/CNPJ
 */
function buscarClientesNormalizado($pdo, $termo, $limite = 50) {
    $where = ["1=1"];
    $params = [];
    
    if (!empty($termo)) {
        // Busca em nome
        $where[] = "(c.nome LIKE :termo_nome";
        $params[':termo_nome'] = '%' . $termo . '%';
        
        // Busca normalizada em CPF/CNPJ
        $buscaDoc = criarCondicaoBuscaNormalizada('c.cpf_cnpj', $termo);
        $where[] = "OR {$buscaDoc['sql']})";
        $params = array_merge($params, $buscaDoc['params']);
    }
    
    $sql = "
        SELECT 
            c.*,
            COUNT(DISTINCT p.id) as total_processos
        FROM clientes c
        LEFT JOIN processos p ON c.id = p.cliente_id
        WHERE " . implode(' AND ', $where) . "
        GROUP BY c.id
        ORDER BY c.nome
        LIMIT :limite
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limite', (int)$limite, PDO::PARAM_INT);
    
    // Bind dos demais parâmetros
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Função para busca global (usada na API de busca global)
 */
function buscaGlobalNormalizada($pdo, $termo, $limite = 10) {
    $resultado = [
        'processos' => [],
        'clientes' => [],
        'publicacoes' => [],
        'tarefas' => [],
        'total' => 0
    ];
    
    if (empty($termo) || strlen($termo) < 2) {
        return $resultado;
    }
    
    // PROCESSOS
    $buscaProcesso = criarCondicaoBuscaNormalizada('p.numero_processo', $termo);
    $sqlProcessos = "
        SELECT 
            p.id,
            p.numero_processo,
            c.nome as cliente_nome
        FROM processos p
        LEFT JOIN clientes c ON p.cliente_id = c.id
        WHERE {$buscaProcesso['sql']} OR c.nome LIKE :termo_cliente
        ORDER BY p.data_criacao DESC
        LIMIT :limite
    ";
    
    $stmt = $pdo->prepare($sqlProcessos);
    $stmt->bindValue(':limite', (int)$limite, PDO::PARAM_INT);
    $stmt->bindValue(':termo_cliente', '%' . $termo . '%');
    foreach ($buscaProcesso['params'] as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $resultado['processos'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // CLIENTES
    $buscaCliente = criarCondicaoBuscaNormalizada('c.cpf_cnpj', $termo);
    $sqlClientes = "
        SELECT 
            c.id,
            c.nome,
            c.cpf_cnpj
        FROM clientes c
        WHERE c.nome LIKE :termo_nome OR {$buscaCliente['sql']}
        ORDER BY c.nome
        LIMIT :limite
    ";
    
    $stmt = $pdo->prepare($sqlClientes);
    $stmt->bindValue(':limite', (int)$limite, PDO::PARAM_INT);
    $stmt->bindValue(':termo_nome', '%' . $termo . '%');
    foreach ($buscaCliente['params'] as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $resultado['clientes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // PUBLICAÇÕES
    $buscaPub = criarCondicaoBuscaNormalizada('pub.numero_processo_cnj', $termo);
    $sqlPublicacoes = "
        SELECT 
            pub.id,
            pub.numero_processo_cnj,
            pub.tipo_documento,
            pub.titulo
        FROM publicacoes pub
        WHERE {$buscaPub['sql']} 
           OR pub.titulo LIKE :termo_titulo
           OR pub.tipo_documento LIKE :termo_tipo
        ORDER BY pub.data_publicacao DESC
        LIMIT :limite
    ";
    
    $stmt = $pdo->prepare($sqlPublicacoes);
    $stmt->bindValue(':limite', (int)$limite, PDO::PARAM_INT);
    $stmt->bindValue(':termo_titulo', '%' . $termo . '%');
    $stmt->bindValue(':termo_tipo', '%' . $termo . '%');
    foreach ($buscaPub['params'] as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $resultado['publicacoes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // TAREFAS
    $sqlTarefas = "
        SELECT 
            t.id,
            t.titulo,
            t.status
        FROM tarefas t
        WHERE t.titulo LIKE :termo
        ORDER BY t.data_criacao DESC
        LIMIT :limite
    ";
    
    $stmt = $pdo->prepare($sqlTarefas);
    $stmt->bindValue(':limite', (int)$limite, PDO::PARAM_INT);
    $stmt->bindValue(':termo', '%' . $termo . '%');
    $stmt->execute();
    $resultado['tarefas'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Total
    $resultado['total'] = count($resultado['processos']) + 
                          count($resultado['clientes']) + 
                          count($resultado['publicacoes']) + 
                          count($resultado['tarefas']);
    
    return $resultado;
}

/**
 * Formatar número de processo para exibição
 */
function formatarNumeroProcesso($numero) {
    // Remove tudo que não é número
    $apenas_numeros = preg_replace('/[^0-9]/', '', $numero);
    
    // Se tem 20 dígitos, formata no padrão CNJ
    if (strlen($apenas_numeros) == 20) {
        return substr($apenas_numeros, 0, 7) . '-' . 
               substr($apenas_numeros, 7, 2) . '.' . 
               substr($apenas_numeros, 9, 4) . '.' . 
               substr($apenas_numeros, 13, 1) . '.' . 
               substr($apenas_numeros, 14, 2) . '.' . 
               substr($apenas_numeros, 16, 4);
    }
    
    // Se não, retorna como está
    return $numero;
}

/**
 * Formatar CPF
 */
function formatarCPF($cpf) {
    $apenas_numeros = preg_replace('/[^0-9]/', '', $cpf);
    
    if (strlen($apenas_numeros) == 11) {
        return substr($apenas_numeros, 0, 3) . '.' . 
               substr($apenas_numeros, 3, 3) . '.' . 
               substr($apenas_numeros, 6, 3) . '-' . 
               substr($apenas_numeros, 9, 2);
    }
    
    return $cpf;
}

/**
 * Formatar CNPJ
 */
function formatarCNPJ($cnpj) {
    $apenas_numeros = preg_replace('/[^0-9]/', '', $cnpj);
    
    if (strlen($apenas_numeros) == 14) {
        return substr($apenas_numeros, 0, 2) . '.' . 
               substr($apenas_numeros, 2, 3) . '.' . 
               substr($apenas_numeros, 5, 3) . '/' . 
               substr($apenas_numeros, 8, 4) . '-' . 
               substr($apenas_numeros, 12, 2);
    }
    
    return $cnpj;
}