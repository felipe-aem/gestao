<?php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$atendimento_id = $_POST['atendimento_id'] ?? 0;
$data_atendimento = $_POST['data_atendimento'] ?? '';
$atendido_por = $_POST['atendido_por'] ?? '';
$cliente_nome = trim($_POST['cliente_nome'] ?? '');
$cliente_cpf_cnpj = trim($_POST['cliente_cpf_cnpj'] ?? '');
$status_contrato = $_POST['status_contrato'] ?? '';
$precisa_nova_reuniao = isset($_POST['precisa_nova_reuniao']) ? 1 : 0;
$data_nova_reuniao = $_POST['data_nova_reuniao'] ?? null;
$responsavel_nova_reuniao = $_POST['responsavel_nova_reuniao'] ?? null;
$nucleos_atendimento = $_POST['nucleos_atendimento'] ?? [];
$observacoes = trim($_POST['observacoes'] ?? '');

// Validações
$erros = [];

if (!$atendimento_id) {
    $erros[] = 'Atendimento não encontrado';
}

if (empty($data_atendimento)) {
    $erros[] = 'Data e hora do atendimento são obrigatórias';
}

if (empty($atendido_por)) {
    $erros[] = 'Responsável pelo atendimento é obrigatório';
}

if (empty($cliente_nome)) {
    $erros[] = 'Nome do cliente é obrigatório';
}

if (empty($status_contrato)) {
    $erros[] = 'Status do contrato é obrigatório';
}

if ($precisa_nova_reuniao) {
    if (empty($data_nova_reuniao)) {
        $erros[] = 'Data da nova reunião é obrigatória quando marcada como necessária';
    }
    if (empty($responsavel_nova_reuniao)) {
        $erros[] = 'Responsável pela nova reunião é obrigatório quando marcada como necessária';
    }
}

if (empty($nucleos_atendimento)) {
    $erros[] = 'Pelo menos um núcleo de atendimento deve ser selecionado';
}

// Validar se nova reunião não é no passado
if (!empty($data_nova_reuniao)) {
    $data_reuniao = new DateTime($data_nova_reuniao);
    $agora = new DateTime();
    
    if ($data_reuniao < $agora) {
        $erros[] = 'Data da nova reunião não pode ser no passado';
    }
}

if (!empty($erros)) {
    $_SESSION['erro'] = implode('<br>', $erros);
    header("Location: editar.php?id=$atendimento_id");
    exit;
}

try {
    $conn = getConnection();
    $conn->beginTransaction();
    
    // Buscar dados atuais do atendimento
    $sql = "SELECT * FROM atendimentos WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$atendimento_id]);
    $atendimento_atual = $stmt->fetch();
    
    if (!$atendimento_atual) {
        throw new Exception('Atendimento não encontrado');
    }
    
    // Atualizar atendimento
    $cpf_cnpj_limpo = !empty($cliente_cpf_cnpj) ? preg_replace('/\D/', '', $cliente_cpf_cnpj) : '';
    
    $sql = "UPDATE atendimentos SET 
            data_atendimento = ?, 
            cliente_nome = ?, 
            cliente_cpf_cnpj = ?, 
            atendido_por = ?, 
            status_contrato = ?, 
            precisa_nova_reuniao = ?, 
            data_nova_reuniao = ?, 
            responsavel_nova_reuniao = ?, 
            nucleos_atendimento = ?, 
            observacoes = ?
            WHERE id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        $data_atendimento,
        $cliente_nome,
        $cpf_cnpj_limpo,
        $atendido_por,
        $status_contrato,
        $precisa_nova_reuniao,
        $precisa_nova_reuniao ? $data_nova_reuniao : null,
        $precisa_nova_reuniao ? $responsavel_nova_reuniao : null,
        json_encode($nucleos_atendimento),
        $observacoes,
        $atendimento_id
    ]);
    
    // Gerenciar evento na agenda para nova reunião
    if ($precisa_nova_reuniao && $data_nova_reuniao && $responsavel_nova_reuniao) {
        // Verificar se já existe evento na agenda para este atendimento
        $sql_check = "SELECT id FROM agenda WHERE atendimento_id = ? AND tipo = 'Reunião'";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->execute([$atendimento_id]);
        $evento_existente = $stmt_check->fetch();
        
        $data_inicio = new DateTime($data_nova_reuniao);
        $data_fim = clone $data_inicio;
        $data_fim->add(new DateInterval('PT1H')); // Adicionar 1 hora
        
        $titulo = "Reunião de Retorno - " . $cliente_nome;
        $descricao = "Reunião de retorno referente ao atendimento #" . $atendimento_id;
        
        if ($evento_existente) {
            // Atualizar evento existente
            $sql_agenda = "UPDATE agenda SET 
                          titulo = ?, 
                          descricao = ?, 
                          data_inicio = ?, 
                          data_fim = ?, 
                          usuario_id = ?
                          WHERE id = ?";
            
            $stmt_agenda = $conn->prepare($sql_agenda);
            $stmt_agenda->execute([
                $titulo,
                $descricao,
                $data_inicio->format('Y-m-d H:i:s'),
                $data_fim->format('Y-m-d H:i:s'),
                $responsavel_nova_reuniao,
                $evento_existente['id']
            ]);
        } else {
            // Criar novo evento
            $sql_agenda = "INSERT INTO agenda (
                titulo, 
                descricao, 
                data_inicio, 
                data_fim, 
                usuario_id, 
                tipo, 
                cliente_id, 
                atendimento_id, 
                criado_por
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt_agenda = $conn->prepare($sql_agenda);
            $stmt_agenda->execute([
                $titulo,
                $descricao,
                $data_inicio->format('Y-m-d H:i:s'),
                $data_fim->format('Y-m-d H:i:s'),
                $responsavel_nova_reuniao,
                'Reunião',
                $atendimento_atual['cliente_id'],
                $atendimento_id,
                Auth::user()['usuario_id']
            ]);
        }
    } else {
        // Se não precisa mais de reunião, remover evento da agenda
        $sql_remove = "DELETE FROM agenda WHERE atendimento_id = ? AND tipo = 'Reunião'";
        $stmt_remove = $conn->prepare($sql_remove);
        $stmt_remove->execute([$atendimento_id]);
    }
    
    $conn->commit();
    
    // Log da ação
    Auth::log('Editar Atendimento', "Atendimento #{$atendimento_id} editado - Cliente: {$cliente_nome}");
    
    $_SESSION['sucesso'] = 'Atendimento atualizado com sucesso!';
    header("Location: visualizar.php?id=$atendimento_id");
    exit;
    
} catch (Exception $e) {
    $conn->rollBack();
    $_SESSION['erro'] = 'Erro ao atualizar atendimento: ' . $e->getMessage();
    header("Location: editar.php?id=$atendimento_id");
    exit;
}
?>