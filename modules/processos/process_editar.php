<?php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';

$erros = [];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$usuario_logado = Auth::user();

// Receber dados do formulário
$processo_id = $_POST['processo_id'] ?? 0;
$nucleo_id_original = $_POST['nucleo_id_original'] ?? '';
$responsavel_id_original = $_POST['responsavel_id_original'] ?? '';
$fase_anterior = $_POST['fase_anterior'] ?? '';

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
$observacoes_edicao = trim($_POST['observacoes_edicao'] ?? '');
$partes = $_POST['partes'] ?? [];
$partes_remover = $_POST['partes_remover'] ?? [];

// --- Validações ---

if (!$processo_id) {
    $erros[] = 'Processo não encontrado';
}

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
}

if (empty($observacoes_edicao)) {
    $erros[] = 'Motivo da edição é obrigatório';
}

// Validar partes
if (empty($partes)) {
    $erros[] = 'É necessário manter pelo menos uma parte no processo';
} else {
    foreach ($partes as $key => $parte) {
        if (empty($parte['tipo_parte'])) {
            $erros[] = "Parte #{$key}: Tipo de parte é obrigatório";
        }
        if (empty($parte['nome'])) {
            $erros[] = "Parte #{$key}: Nome da parte é obrigatório";
        }
    }
}

// Verificar se número do processo já existe (exceto o atual)
if (!empty($numero_processo)) {
    $sql = "SELECT id FROM processos WHERE numero_processo = ? AND id != ?";
    $stmt = executeQuery($sql, [$numero_processo, $processo_id]);
    if ($stmt->fetch()) {
        $erros[] = 'Este número de processo já está cadastrado em outro processo';
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
    header("Location: editar.php?id=$processo_id");
    exit;
}

// --- Atualização no Banco de Dados ---
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
    
    // Montar parte_contraria
    $partes_contrarias = [];
    foreach ($partes as $parte) {
        if (!isset($parte['e_nosso_cliente']) || $parte['e_nosso_cliente'] != '1') {
            $partes_contrarias[] = $parte['nome'];
        }
    }
    $parte_contraria_str = implode(', ', $partes_contrarias);
    
    // Atualizar processo
    $sql = "UPDATE processos SET 
        numero_processo = ?,
        cliente_id = ?,
        cliente_nome = ?,
        parte_contraria = ?,
        comarca = ?,
        tipo_processo = ?,
        situacao_processual = ?,
        data_fechamento_contrato = ?,
        data_protocolo = ?,
        responsavel_id = ?,
        nucleo_id = ?,
        fase_atual = ?,
        anotacoes = ?,
        usa_partes_multiplas = 1
        WHERE id = ?";
    
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
        $processo_id
    ]);
    
	// Processar informações financeiras
	$forma_pagamento = $_POST['forma_pagamento'] ?? '';

	if (!empty($forma_pagamento)) {
		$valor_honorarios = !empty($_POST['valor_honorarios']) ? str_replace(',', '.', $_POST['valor_honorarios']) : null;
		$porcentagem_exito = !empty($_POST['porcentagem_exito']) ? $_POST['porcentagem_exito'] : null;
		$valor_entrada = !empty($_POST['valor_entrada']) ? str_replace(',', '.', $_POST['valor_entrada']) : null;
		$numero_parcelas = !empty($_POST['numero_parcelas']) ? $_POST['numero_parcelas'] : null;
		$valor_parcela = !empty($_POST['valor_parcela']) ? str_replace(',', '.', $_POST['valor_parcela']) : null;
		$data_vencimento_primeira_parcela = !empty($_POST['data_vencimento_primeira_parcela']) ? $_POST['data_vencimento_primeira_parcela'] : null;
		$observacoes_financeiras = trim($_POST['observacoes_financeiras'] ?? '');

		// Verificar se já existe registro financeiro
		$sql_check_fin = "SELECT id FROM processo_financeiro WHERE processo_id = ?";
		$stmt_check_fin = $conn->prepare($sql_check_fin);
		$stmt_check_fin->execute([$processo_id]);
		$existe_financeiro = $stmt_check_fin->fetch();

		if ($existe_financeiro) {
			// Atualizar
			$sql_fin = "UPDATE processo_financeiro SET 
				forma_pagamento = ?,
				valor_honorarios = ?,
				porcentagem_exito = ?,
				valor_entrada = ?,
				numero_parcelas = ?,
				valor_parcela = ?,
				data_vencimento_primeira_parcela = ?,
				observacoes_financeiras = ?,
				atualizado_por = ?
				WHERE processo_id = ?";

			$stmt_fin = $conn->prepare($sql_fin);
			$stmt_fin->execute([
				$forma_pagamento,
				$valor_honorarios,
				$porcentagem_exito,
				$valor_entrada,
				$numero_parcelas,
				$valor_parcela,
				$data_vencimento_primeira_parcela,
				$observacoes_financeiras,
				$usuario_logado['usuario_id'],
				$processo_id
			]);
		} else {
			// Inserir
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
		}

		// Atualizar flag no processo
		$sql_update_flag = "UPDATE processos SET tem_info_financeira = 1 WHERE id = ?";
		$stmt_update_flag = $conn->prepare($sql_update_flag);
		$stmt_update_flag->execute([$processo_id]);
	} else {
		// Se removeu a forma de pagamento, deletar informações financeiras
		$sql_delete_fin = "DELETE FROM processo_financeiro WHERE processo_id = ?";
		$stmt_delete_fin = $conn->prepare($sql_delete_fin);
		$stmt_delete_fin->execute([$processo_id]);

		// Atualizar flag no processo
		$sql_update_flag = "UPDATE processos SET tem_info_financeira = 0 WHERE id = ?";
		$stmt_update_flag = $conn->prepare($sql_update_flag);
		$stmt_update_flag->execute([$processo_id]);
	}
	
    // Remover partes marcadas para exclusão
    if (!empty($partes_remover)) {
        $placeholders = str_repeat('?,', count($partes_remover) - 1) . '?';
        $sql_delete = "DELETE FROM processo_partes WHERE id IN ($placeholders) AND processo_id = ?";
        $stmt_delete = $conn->prepare($sql_delete);
        $params = array_merge($partes_remover, [$processo_id]);
        $stmt_delete->execute($params);
    }
    
    // Processar partes (atualizar existentes e inserir novas)
    $sql_update_parte = "UPDATE processo_partes SET 
        cliente_id = ?,
        nome = ?,
        tipo_parte = ?,
        e_nosso_cliente = ?,
        observacoes = ?,
        ordem = ?
        WHERE id = ? AND processo_id = ?";
    
    $sql_insert_parte = "INSERT INTO processo_partes (
        processo_id,
        cliente_id,
        nome,
        tipo_parte,
        e_nosso_cliente,
        observacoes,
        ordem,
        criado_por
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt_update_parte = $conn->prepare($sql_update_parte);
    $stmt_insert_parte = $conn->prepare($sql_insert_parte);
    $ordem = 0;
    
    foreach ($partes as $parte) {
        $ordem++;
        $cliente_id = !empty($parte['cliente_id']) ? $parte['cliente_id'] : null;
        $nome = $parte['nome'];
        $tipo_parte = $parte['tipo_parte'];
        $e_nosso_cliente = isset($parte['e_nosso_cliente']) && $parte['e_nosso_cliente'] == '1' ? 1 : 0;
        $observacoes = null; // SEMPRE NULL (removido campo)
        
        if (!empty($parte['id']) && $parte['id'] !== 'new') {
            // Atualizar parte existente
            $stmt_update_parte->execute([
                $cliente_id,
                $nome,
                $tipo_parte,
                $e_nosso_cliente,
                $observacoes,
                $ordem,
                $parte['id'],
                $processo_id
            ]);
        } else {
            // Inserir nova parte
            $stmt_insert_parte->execute([
                $processo_id,
                $cliente_id,
                $nome,
                $tipo_parte,
                $e_nosso_cliente,
                $observacoes,
                $ordem,
                $usuario_logado['usuario_id']
            ]);
        }
    }
    
    // Registrar movimentação
    $mudancas = [];
    
    // Verificar mudança de responsável
    if ($responsavel_id != $responsavel_id_original) {
        $sql_resp_ant = "SELECT nome FROM usuarios WHERE id = ?";
        $stmt_resp_ant = $conn->prepare($sql_resp_ant);
        $stmt_resp_ant->execute([$responsavel_id_original]);
        $resp_ant = $stmt_resp_ant->fetch();
        
        $sql_resp_novo = "SELECT nome FROM usuarios WHERE id = ?";
        $stmt_resp_novo = $conn->prepare($sql_resp_novo);
        $stmt_resp_novo->execute([$responsavel_id]);
        $resp_novo = $stmt_resp_novo->fetch();
        
        if ($resp_ant && $resp_novo) {
            $mudancas[] = "Responsável alterado de {$resp_ant['nome']} para {$resp_novo['nome']}";
        }
    }
    
    // Verificar mudança de fase
    if ($fase_atual != $fase_anterior) {
        if (empty($fase_anterior) && !empty($fase_atual)) {
            $mudancas[] = "Fase definida como: {$fase_atual}";
        } elseif (!empty($fase_anterior) && !empty($fase_atual)) {
            $mudancas[] = "Fase alterada de '{$fase_anterior}' para '{$fase_atual}'";
        } elseif (!empty($fase_anterior) && empty($fase_atual)) {
            $mudancas[] = "Fase '{$fase_anterior}' foi removida";
        }
    }
    
    // Montar descrição da movimentação
    $descricao_movimentacao = "Processo editado";
    if (!empty($mudancas)) {
        $descricao_movimentacao .= ": " . implode("; ", $mudancas);
    }
    
    // SEMPRE registrar a movimentação
    $sql_mov = "INSERT INTO processo_movimentacoes (
        processo_id,
        data_movimentacao,
        descricao,
        fase_anterior,
        fase_nova,
        responsavel_anterior,
        responsavel_novo,
        observacoes,
        criado_por
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt_mov = $conn->prepare($sql_mov);
    $stmt_mov->execute([
        $processo_id,
        date('Y-m-d'),
        $descricao_movimentacao,
        !empty($fase_anterior) ? $fase_anterior : null,
        !empty($fase_atual) ? $fase_atual : null,
        $responsavel_id != $responsavel_id_original ? $responsavel_id_original : null,
        $responsavel_id != $responsavel_id_original ? $responsavel_id : null,
        $observacoes_edicao,
        $usuario_logado['usuario_id']
    ]);
    
    $conn->commit();
    
    // Log da ação
    Auth::log('Editar Processo', "Processo #{$numero_processo} editado - {$observacoes_edicao}");
    
    $_SESSION['sucesso'] = 'Processo atualizado com sucesso!';
    header("Location: visualizar.php?id=$processo_id");
    exit;
    
} catch (Exception $e) {
    $conn->rollBack();
    $_SESSION['erro'] = 'Erro ao atualizar processo: ' . $e->getMessage();
    header("Location: editar.php?id=$processo_id");
    exit;
}
?>