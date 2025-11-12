<?php
require_once __DIR__ . '/../../includes/auth.php';
Auth::protect();

require_once __DIR__ . '/../../config/database.php';

$usuario_logado = Auth::user();

// VERIFICAR ACESSO COMPLETO (apenas Diretores/Sócios podem deletar)
$acesso_financeiro = $usuario_logado['acesso_financeiro'] ?? 'Nenhum';

if ($acesso_financeiro !== 'Completo') {
    $_SESSION['erro'] = 'Apenas usuários com acesso completo podem deletar receitas';
    header('Location: index.php');
    exit;
}

$receita_id = $_GET['id'] ?? 0;

if (!$receita_id) {
    $_SESSION['erro'] = 'Receita não especificada';
    header('Location: index.php');
    exit;
}

try {
    // Buscar dados da receita antes de deletar (para o log)
    $sql = "SELECT pr.*, p.numero_processo 
            FROM processo_receitas pr
            INNER JOIN processos p ON pr.processo_id = p.id
            WHERE pr.id = ?";
    $stmt = executeQuery($sql, [$receita_id]);
    $receita = $stmt->fetch();
    
    if (!$receita) {
        $_SESSION['erro'] = 'Receita não encontrada';
        header('Location: index.php');
        exit;
    }
    
    // Deletar receita
    $sql_delete = "DELETE FROM processo_receitas WHERE id = ?";
    executeQuery($sql_delete, [$receita_id]);
    
    // Log da ação
    Auth::log(
        'Deletar Receita', 
        "Receita #$receita_id deletada - Processo: {$receita['numero_processo']} - " .
        "Tipo: {$receita['tipo_receita']} - Valor: R$ " . 
        number_format($receita['valor'], 2, ',', '.')
    );
    
    $_SESSION['sucesso'] = 'Receita deletada com sucesso!';
    header('Location: index.php');
    exit;
    
} catch (Exception $e) {
    $_SESSION['erro'] = 'Erro ao deletar receita: ' . $e->getMessage();
    header('Location: index.php');
    exit;
}
?>