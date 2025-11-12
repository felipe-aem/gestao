<?php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';

$erros = [];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: novo.php');
    exit;
}

$usuario_logado = Auth::user();

// Receber dados do formulário
$nucleo_id = $_POST['nucleo_id'] ?? '';
$numero_processo = trim($_POST['numero_processo'] ?? '');
$tipo_processo = $_POST['tipo_processo'] ?? '';
$situacao_processual = $_POST['situacao_processual'] ?? '';
$comarca = trim($_POST['comarca'] ?? '');
$data_fechamento_contrato = $_POST['data_fechamento_contrato'] ?? null;
$data_protocolo = $_POST['data_protocolo'] ?? null;
$responsavel_id = $_POST['responsavel_id'] ?? '';
$fase_atual = trim($_POST['fase_atual'] ?? '');
$anotacoes = trim($_POST['anotacoes'] ?? '');
$partes = $_POST['partes'] ?? [];

// --- Validações ---

if (empty($nucleo_id)) {
    $erros[] = 'Núcleo é obrigatório';
}

if (empty($numero_processo)) {
    $erros[] = 'Número do processo é obrigatório';
}

if (empty($tipo_processo)) {
    $erros[] = 'Tipo de processo é obrigatório';
}

if (empty($situacao_processual)) {
    $erros[] = 'Situação processual é obrigatória';
}

if (empty($responsavel_id)) {
    $erros[] = 'Responsável pelo processo é obrigatório';
} else {
    if (!empty($nucleo_id)) {
        $sql = "SELECT COUNT(*) as count FROM usuarios_nucleos WHERE usuario_id = ? AND nucleo_id = ?";
        $stmt = executeQuery($sql, [$responsavel_id, $nucleo_id]);
        $result = $stmt->fetch();
        if ($result['count'] == 0) {
            $erros[] = 'Responsável selecionado não tem acesso ao núcleo escolhido';
        }
    }
}

// Validar partes
if (empty($partes)) {
    $erros[] = 'É necessário adicionar pelo menos uma parte ao processo';
} else {
    $tem_nosso_cliente = false;
    foreach ($partes as $key => $parte) {
        if (empty($parte['tipo_parte'])) {
            $erros[] = "Parte #{$key}: Tipo de parte é obrigatório";
        }
        if (empty($parte['nome'])) {
            $erros[] = "Parte #{$key}: Nome da parte é obrigatório";
        }
        if (isset($parte['e_nosso_cliente']) && $parte['e_nosso_cliente'] == '1') {
            $tem_nosso_cliente = true;
        }
    }
    
    if (!$tem_nosso_cliente) {
        // Apenas aviso, não bloqueia
        // $erros[] = 'Nenhuma parte foi marcada como "quem nos contratou"';
    }
}

// Verificar se número do processo já existe
if (!empty($numero_processo)) {
    $sql = "SELECT id FROM processos WHERE numero_processo = ?";
    $stmt = executeQuery($sql, [$numero_processo]);
    if ($stmt->fetch()) {
        $erros[] = 'Este número de processo já está cadastrado';
    }
}

// Validar datas
if (!empty($data_fechamento_contrato) && !empty($data_protocolo)) {
    $data_fechamento = new DateTime($data_fechamento_contrato);
    $data_prot = new DateTime($data_protocolo);
    
    if ($data_prot < $data_fechamento) {
        $erros[] = 'Data do protocolo não pode ser anterior à data de fechamento do contrato';
    }
}

// Se houver erros, redireciona
if (!empty($erros)) {
    $_SESSION['erro'] = implode('<br>', $erros);
    header('Location: novo.php');
    exit;
}

// --- Inserção no Banco de Dados ---
try {
    $conn = getConnection();
    $conn->beginTransaction();
    
    // Pegar o primeiro cliente (nosso cliente) para compatibilidade
    $primeiro_cliente_id = null;
    $primeiro_cliente_nome = '';
    foreach ($partes as $parte) {
        if (isset($parte['e_nosso_cliente']) && $parte['e_nosso_cliente'] == '1') {
            $primeiro_cliente_id = !empty($parte['cliente_id']) ? $parte['cliente_id'] : null;
            $primeiro_cliente_nome = $parte['nome'];
            break;
        }
    }
    
    // Se não tiver nenhum marcado como nosso cliente, pega o primeiro
    if (empty($primeiro_cliente_nome)) {
        $primeira_parte = reset($partes);
        $primeiro_cliente_id = !empty($primeira_parte['cliente_id']) ? $primeira_parte['cliente_id'] : null;
        $primeiro_cliente_nome = $primeira_parte['nome'];
    }
    
    // Inserir processo
    $sql = "INSERT INTO processos (
        numero_processo, 
        cliente_id, 
        cliente_nome, 
        parte_contraria, 
        comarca, 
        tipo_processo, 
        situacao_processual, 
        data_fechamento_contrato, 
        data_protocolo, 
        responsavel_id, 
        nucleo_id, 
        fase_atual, 
        anotacoes, 
        usa_partes_multiplas,
        criado_por
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    // Montar parte_contraria (todas as partes que não são nosso cliente)
    $partes_contrarias = [];
    foreach ($partes as $parte) {
        if (!isset($parte['e_nosso_cliente']) || $parte['e_nosso_cliente'] != '1') {
            $partes_contrarias[] = $parte['nome'];
        }
    }
    $parte_contraria_str = implode(', ', $partes_contrarias);
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        $numero_processo,
        $primeiro_cliente_id,
        $primeiro_cliente_nome,
        $parte_contraria_str,
        $comarca,
        $tipo_processo,
        $situacao_processual,
        !empty($data_fechamento_contrato) ? $data_fechamento_contrato : null,
        !empty($data_protocolo) ? $data_protocolo : null,
        $responsavel_id,
        $nucleo_id,
        $fase_atual,
        $anotacoes,
        1, // usa_partes_multiplas = true
        $usuario_logado['usuario_id']
    ]);
    
    $processo_id = $conn->lastInsertId();
	
	// Processar informações financeiras se fornecidas
	$forma_pagamento = $_POST['forma_pagamento'] ?? '';

	if (!empty($forma_pagamento)) {
		$valor_honorarios = !empty($_POST['valor_honorarios']) ? str_replace(',', '.', $_POST['valor_honorarios']) : null;
		$porcentagem_exito = !empty($_POST['porcentagem_exito']) ? $_POST['porcentagem_exito'] : null;
		$valor_entrada = !empty($_POST['valor_entrada']) ? str_replace(',', '.', $_POST['valor_entrada']) : null;
		$numero_parcelas = !empty($_POST['numero_parcelas']) ? $_POST['numero_parcelas'] : null;
		$valor_parcela = !empty($_POST['valor_parcela']) ? str_replace(',', '.', $_POST['valor_parcela']) : null;
		$data_vencimento_primeira_parcela = !empty($_POST['data_vencimento_primeira_parcela']) ? $_POST['data_vencimento_primeira_parcela'] : null;
		$observacoes_financeiras = trim($_POST['observacoes_financeiras'] ?? '');

		if (!empty($forma_pagamento)) {
			$sql_fin = "INSERT INTO processo_financeiro (
				processo_id, forma_pagamento, valor_honorarios, porcentagem_exito,
				valor_entrada, numero_parcelas, valor_parcela, 
				data_vencimento_primeira_parcela, observacoes_financeiras, criado_por
			) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

			$stmt_fin = $conn->prepare($sql_fin);
			$stmt_fin->execute([
				$processo_id,
				$forma_pagamento,
				$valor_honorarios,
				$porcentagem_exito,
				$valor_entrada,
				$numero_parcelas,
				$valor_parcela,
				$data_vencimento_primeira_parcela,
				$observacoes_financeiras,
				$usuario_logado['usuario_id']
			]);

			// Atualizar flag no processo
			$sql_update = "UPDATE processos SET tem_info_financeira = 1 WHERE id = ?";
			$stmt_update = $conn->prepare($sql_update);
			$stmt_update->execute([$processo_id]);
		}
	}
    
    // Inserir as partes do processo
    $sql_parte = "INSERT INTO processo_partes (
        processo_id,
        cliente_id,
        nome,
        tipo_parte,
        e_nosso_cliente,
        observacoes,
        ordem,
        criado_por
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt_parte = $conn->prepare($sql_parte);
    $ordem = 0;
    
    foreach ($partes as $parte) {
        $ordem++;
        $stmt_parte->execute([
			$processo_id,
			!empty($parte['cliente_id']) ? $parte['cliente_id'] : null,
			$parte['nome'],
			$parte['tipo_parte'],
			isset($parte['e_nosso_cliente']) && $parte['e_nosso_cliente'] == '1' ? 1 : 0,
			null, // observacoes sempre null
			$ordem,
			$usuario_logado['usuario_id']
		]);
    }
    
    // Registrar movimentação inicial
    $sql_mov = "INSERT INTO processo_movimentacoes (
        processo_id, 
        data_movimentacao, 
        descricao, 
        fase_nova, 
        responsavel_novo, 
        criado_por
    ) VALUES (?, ?, ?, ?, ?, ?)";
    
    $stmt_mov = $conn->prepare($sql_mov);
    $stmt_mov->execute([
        $processo_id,
        date('Y-m-d'),
        'Processo cadastrado no sistema',
        $fase_atual ?: 'Cadastrado',
        $responsavel_id,
        $usuario_logado['usuario_id']
    ]);
    
    $conn->commit();
    
    // Log da ação
    Auth::log('Criar Processo', "Processo #{$numero_processo} criado com " . count($partes) . " parte(s)");
    
    $_SESSION['sucesso'] = 'Processo cadastrado com sucesso!';
    header("Location: visualizar.php?id=$processo_id");
    exit;
    
} catch (Exception $e) {
    $conn->rollBack();
    $_SESSION['erro'] = 'Erro ao cadastrar processo: ' . $e->getMessage();
    header('Location: novo.php');
    exit;
}
?>