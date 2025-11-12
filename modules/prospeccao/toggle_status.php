<?php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';

$usuario_logado = Auth::user();
$nivel_acesso_logado = $usuario_logado['nivel_acesso'];
$usuario_id = $usuario_logado['usuario_id'] ?? $usuario_logado['user_id'] ?? $usuario_logado['id'];

// Verificar permissão
$pode_editar = in_array($nivel_acesso_logado, ['Admin', 'Socio', 'Diretor', 'Gestor', 'Advogado']);
if (!$pode_editar) {
    header('Location: index.php?erro=permissao');
    exit;
}

// Obter ID
$id = $_GET['id'] ?? null;
if (!$id) {
    header('Location: index.php?erro=id_invalido');
    exit;
}

try {
    $pdo = getConnection();
    
    // Buscar prospecto atual
    $sql_check = "SELECT id, nome, ativo FROM prospeccoes WHERE id = ?";
    $stmt_check = $pdo->prepare($sql_check);
    $stmt_check->execute([$id]);
    $prospecto = $stmt_check->fetch();
    
    if (!$prospecto) {
        header('Location: index.php?erro=nao_encontrado');
        exit;
    }
    
    // Inverter status
    $novo_status = $prospecto['ativo'] ? 0 : 1;
    
    // Atualizar
    $sql_update = "UPDATE prospeccoes SET ativo = ? WHERE id = ?";
    $stmt_update = $pdo->prepare($sql_update);
    $stmt_update->execute([$novo_status, $id]);
    
    // Registrar interação
    $acao = $novo_status ? 'reativado' : 'desativado';
    $sql_int = "INSERT INTO prospeccoes_interacoes (prospeccao_id, tipo, descricao, usuario_id)
                VALUES (?, 'Observação', ?, ?)";
    
    $stmt_int = $pdo->prepare($sql_int);
    $stmt_int->execute([$id, "Prospecto {$acao}", $usuario_id]);
    
    $mensagem = $novo_status ? 'ativado' : 'desativado';
    header('Location: index.php?sucesso=' . $mensagem);
    exit;
    
} catch (Exception $e) {
    error_log("Erro ao alterar status do prospecto: " . $e->getMessage());
    header('Location: index.php?erro=status');
    exit;
}
?>