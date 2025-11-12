<?php
require_once '../../includes/auth.php';
Auth::protect(['Admin', 'Socio', 'Diretor']);

require_once '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$action = $_POST['action'] ?? '';
$usuario_id = $_POST['usuario_id'] ?? 0;
$nucleo_id = $_POST['nucleo_id'] ?? 0;

if (!$usuario_id || !$nucleo_id || !in_array($action, ['add', 'remove'])) {
    $_SESSION['erro'] = 'Dados inválidos';
    header('Location: index.php');
    exit;
}

try {
    if ($action === 'add') {
        // Verificar se já não existe
        $sql = "SELECT id FROM usuarios_nucleos WHERE usuario_id = ? AND nucleo_id = ?";
        $stmt = executeQuery($sql, [$usuario_id, $nucleo_id]);
        
        if ($stmt->fetch()) {
            $_SESSION['erro'] = 'Núcleo já atribuído a este usuário';
        } else {
            // Adicionar núcleo
            $sql = "INSERT INTO usuarios_nucleos (usuario_id, nucleo_id) VALUES (?, ?)";
            executeQuery($sql, [$usuario_id, $nucleo_id]);
            
            // Buscar nomes para o log
            $sql = "SELECT u.nome as usuario_nome, n.nome as nucleo_nome 
                    FROM usuarios u, nucleos n 
                    WHERE u.id = ? AND n.id = ?";
            $stmt = executeQuery($sql, [$usuario_id, $nucleo_id]);
            $dados = $stmt->fetch();
            
            Auth::log('Adicionar Núcleo', "Núcleo {$dados['nucleo_nome']} adicionado ao usuário {$dados['usuario_nome']}");
            $_SESSION['sucesso'] = 'Núcleo adicionado com sucesso!';
        }
    } else {
        // Remover núcleo
        $sql = "DELETE FROM usuarios_nucleos WHERE usuario_id = ? AND nucleo_id = ?";
        $stmt = executeQuery($sql, [$usuario_id, $nucleo_id]);
        
        if ($stmt->rowCount() > 0) {
            // Buscar nomes para o log
            $sql = "SELECT u.nome as usuario_nome, n.nome as nucleo_nome 
                    FROM usuarios u, nucleos n 
                    WHERE u.id = ? AND n.id = ?";
            $stmt = executeQuery($sql, [$usuario_id, $nucleo_id]);
            $dados = $stmt->fetch();
            
            Auth::log('Remover Núcleo', "Núcleo {$dados['nucleo_nome']} removido do usuário {$dados['usuario_nome']}");
            $_SESSION['sucesso'] = 'Núcleo removido com sucesso!';
        } else {
            $_SESSION['erro'] = 'Núcleo não encontrado';
        }
    }
    
} catch (Exception $e) {
    $_SESSION['erro'] = 'Erro ao processar solicitação: ' . $e->getMessage();
}

header("Location: nucleos.php?id=$usuario_id");
exit;
?>