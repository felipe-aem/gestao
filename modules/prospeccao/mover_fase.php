<?php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';

header('Content-Type: application/json');

$usuario_logado = Auth::user();
$nivel_acesso_logado = $usuario_logado['nivel_acesso'];
$usuario_id = $usuario_logado['usuario_id'] ?? $usuario_logado['user_id'] ?? $usuario_logado['id'];

// Verificar permissão
$pode_editar = in_array($nivel_acesso_logado, ['Admin', 'Socio', 'Diretor', 'Gestor', 'Advogado']) || $usuario_id == 28;
if (!$pode_editar) {
    echo json_encode(['success' => false, 'message' => 'Sem permissão']);
    exit;
}

// Obter dados do POST
$data = json_decode(file_get_contents('php://input'), true);

// LOG: Debug dos dados recebidos
error_log("=== MOVER_FASE DEBUG ===");
error_log("Dados recebidos: " . json_encode($data));

$prospecto_id = $data['prospecto_id'] ?? null;
$nova_fase = $data['nova_fase'] ?? null;
$manter_valores = $data['manter_valores'] ?? true;
$valor_proposta = $data['valor_proposta'] ?? null;
$percentual_exito = $data['percentual_exito'] ?? null;
$estimativa_ganho = $data['estimativa_ganho'] ?? null;
$observacao = $data['observacao'] ?? '';

// NOVO: Campos para Visita Semanal e Revisitar
$data_primeira_visita = $data['data_primeira_visita'] ?? null;
$hora_visita = $data['hora_visita'] ?? null;
$periodicidade = $data['periodicidade'] ?? null;
$data_revisita = $data['data_revisita'] ?? null;

error_log("Prospecto ID: $prospecto_id");
error_log("Nova Fase: $nova_fase");
error_log("Data Primeira Visita: $data_primeira_visita");
error_log("Data Revisita: $data_revisita");

if (!$prospecto_id || !$nova_fase) {
    error_log("ERRO: Dados inválidos - prospecto_id ou nova_fase vazios");
    echo json_encode(['success' => false, 'message' => 'Dados inválidos']);
    exit;
}

try {
    $pdo = getConnection();
    
    // Buscar dados atuais do prospecto primeiro para pegar o módulo
    $sql_check_initial = "SELECT modulo_codigo FROM prospeccoes WHERE id = ? AND ativo = 1";
    $stmt_check_initial = $pdo->prepare($sql_check_initial);
    $stmt_check_initial->execute([$prospecto_id]);
    $prospecto_temp = $stmt_check_initial->fetch();
    
    if (!$prospecto_temp) {
        echo json_encode(['success' => false, 'message' => 'Prospecto não encontrado']);
        exit;
    }
    
    $modulo_codigo = $prospecto_temp['modulo_codigo'];
    
    // Buscar fases válidas do módulo no banco de dados
    $sql_fases = "SELECT fase FROM prospeccao_fases_modulos 
                  WHERE modulo_codigo = ? AND ativo = 1";
    $stmt_fases = $pdo->prepare($sql_fases);
    $stmt_fases->execute([$modulo_codigo]);
    $fases_validas = $stmt_fases->fetchAll(PDO::FETCH_COLUMN);
    
    // Validar fase (agora usando fases dinâmicas do banco)
    if (!in_array($nova_fase, $fases_validas)) {
        error_log("ERRO: Fase '$nova_fase' não está nas fases válidas");
        error_log("Fases válidas: " . json_encode($fases_validas));
        echo json_encode([
            'success' => false, 
            'message' => 'Fase inválida para este módulo',
            'fases_validas' => $fases_validas
        ]);
        exit;
    }
    
    error_log("✓ Fase válida! Iniciando transação...");
    $pdo->beginTransaction();
    
    // Buscar dados atuais do prospecto
    $sql_check = "SELECT id, nome, fase, valor_proposta, percentual_exito, estimativa_ganho FROM prospeccoes WHERE id = ? AND ativo = 1";
    $stmt_check = $pdo->prepare($sql_check);
    $stmt_check->execute([$prospecto_id]);
    $prospecto = $stmt_check->fetch();
    
    if (!$prospecto) {
        throw new Exception('Prospecto não encontrado');
    }
    
    $fase_anterior = $prospecto['fase'];
    
    // Se já está na fase, não faz nada
    if ($fase_anterior === $nova_fase) {
        echo json_encode([
            'success' => true, 
            'message' => 'Já está nesta fase',
            'fase_anterior' => $fase_anterior
        ]);
        $pdo->rollBack();
        exit;
    }
    
    // Se está movendo para "Fechados" e não tem confirmação de manter valores
    // retorna os valores atuais para o frontend decidir
    if ($nova_fase === 'Fechados' && !isset($data['confirmado'])) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'requires_confirmation' => true,
            'message' => 'Necessário confirmar valores',
            'valores_atuais' => [
                'valor_proposta' => $prospecto['valor_proposta'],
                'percentual_exito' => $prospecto['percentual_exito'],
                'estimativa_ganho' => $prospecto['estimativa_ganho']  // ← ADICIONAR
            ]
        ]);
        exit;
    }
    
    // Atualizar fase
    $sql_update = "UPDATE prospeccoes SET fase = ?";
    $params = [$nova_fase];
    
    // Se estiver saindo da fase "Prospecção", remover flag "em_analise"
    if ($fase_anterior === 'Prospecção' && $nova_fase !== 'Prospecção') {
        $sql_update .= ", em_analise = 0";
        error_log("✓ Removendo flag 'em_analise' ao sair da fase Prospecção");
    }
    
    // Se estiver movendo para Fechados e não quiser manter valores, atualizar
    if ($nova_fase === 'Fechados' && !$manter_valores) {
        if ($valor_proposta !== null) {
            $sql_update .= ", valor_proposta = ?";
            $params[] = $valor_proposta;
        }
        
        if ($percentual_exito !== null) {
            $sql_update .= ", percentual_exito = ?";
            $params[] = $percentual_exito;
        }
        
        if ($estimativa_ganho !== null) {
            $sql_update .= ", estimativa_ganho = ?";
            $params[] = $estimativa_ganho;
            error_log("✓ Atualizando estimativa_ganho: $estimativa_ganho");
        }
    }
    
    // NOVO: Se for Visita Semanal, salvar data da primeira visita e periodicidade
    if ($nova_fase === 'Visita Semanal' && $data_primeira_visita) {
        $sql_update .= ", data_revisita = ?, data_primeira_visita = ?";
        $params[] = $data_primeira_visita;
        $params[] = $data_primeira_visita;
        error_log("✓ Adicionando data_primeira_visita para Visita Semanal: $data_primeira_visita");
        
        if ($periodicidade) {
            $sql_update .= ", periodicidade = ?";
            $params[] = $periodicidade;
            error_log("✓ Adicionando periodicidade: $periodicidade");
        }
    }
    
    // NOVO: Se for Revisitar, salvar data da revisita
    if ($nova_fase === 'Revisitar' && $data_revisita) {
        $sql_update .= ", data_revisita = ?";
        $params[] = $data_revisita;
        error_log("✓ Adicionando data_revisita para Revisitar: $data_revisita");
    }
    
    $sql_update .= " WHERE id = ?";
    $params[] = $prospecto_id;
    
    error_log("SQL UPDATE: $sql_update");
    error_log("Params: " . json_encode($params));
    
    $stmt_update = $pdo->prepare($sql_update);
    $stmt_update->execute($params);
    
    error_log("✓ UPDATE executado com sucesso!");
    
    // Registrar no histórico
    $valor_final = (!$manter_valores && $valor_proposta !== null) ? $valor_proposta : $prospecto['valor_proposta'];
    
    $sql_hist = "INSERT INTO prospeccoes_historico 
                 (prospeccao_id, fase_anterior, fase_nova, valor_informado, observacao, usuario_id)
                 VALUES (?, ?, ?, ?, ?, ?)";
    
    $stmt_hist = $pdo->prepare($sql_hist);
    $stmt_hist->execute([
        $prospecto_id,
        $fase_anterior,
        $nova_fase,
        $valor_final,
        $observacao ?: "Movido de {$fase_anterior} para {$nova_fase}",
        $usuario_id
    ]);
    
    error_log("✓ Histórico inserido com sucesso!");
    error_log("Executando COMMIT...");
    
    $pdo->commit();
    
    error_log("✓✓✓ COMMIT EXECUTADO COM SUCESSO! ✓✓✓");
    error_log("Resposta: success=true, fase_nova=$nova_fase");
    
    echo json_encode([
        'success' => true,
        'message' => "Prospecto movido de {$fase_anterior} para {$nova_fase}",
        'fase_anterior' => $fase_anterior,
        'fase_nova' => $nova_fase,
        'prospecto_nome' => $prospecto['nome']
    ]);
    
} catch (Exception $e) {
    if (isset($pdo)) {
        error_log("❌ ERRO CAPTURADO - Fazendo ROLLBACK");
        $pdo->rollBack();
    }
    
    error_log("❌❌❌ ERRO ao mover fase: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    echo json_encode([
        'success' => false,
        'message' => 'Erro ao mover prospecto: ' . $e->getMessage()
    ]);
}
?>