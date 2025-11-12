<?php
/**
 * API de Documentos
 * Gerenciamento de uploads, downloads e listagem de arquivos
 * 
 * Endpoints:
 * POST /upload - Upload de arquivo(s)
 * GET /listar - Listar documentos de uma entidade
 * GET /download/{id} - Download de arquivo
 * DELETE /{id} - Excluir documento (soft delete)
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/DocumentoHelper.php';

// Verificar autenticação
Auth::protect();
$usuario_logado = Auth::user();
$usuario_id = $usuario_logado['usuario_id'];

// Determinar método HTTP
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        
        // ========================================
        // POST - Upload de arquivo(s)
        // ========================================
        case 'POST':
            if (!isset($_POST['action']) || $_POST['action'] !== 'upload') {
                throw new Exception('Action inválida');
            }
            
            // Validar parâmetros obrigatórios
            $tipo_vinculo = $_POST['tipo_vinculo'] ?? null;
            $vinculo_id = $_POST['vinculo_id'] ?? null;
            $contexto = $_POST['contexto'] ?? 'geral';
            $descricao = $_POST['descricao'] ?? null;
            
            if (!$tipo_vinculo || !$vinculo_id) {
                throw new Exception('Parâmetros obrigatórios ausentes: tipo_vinculo, vinculo_id');
            }
            
            // Validar tipo_vinculo
            $tipos_validos = ['tarefa', 'prazo', 'audiencia', 'publicacao', 'processo'];
            if (!in_array($tipo_vinculo, $tipos_validos)) {
                throw new Exception('Tipo de vínculo inválido');
            }
            
            // Verificar se há arquivos
            if (empty($_FILES['arquivos'])) {
                throw new Exception('Nenhum arquivo enviado');
            }
            
            // Upload múltiplo
            $resultado = DocumentoHelper::uploadMultiplos(
                $_FILES['arquivos'],
                $tipo_vinculo,
                $vinculo_id,
                $contexto,
                $usuario_id
            );
            
            echo json_encode([
                'success' => $resultado['success'],
                'message' => count($resultado['uploads']) . ' arquivo(s) enviado(s) com sucesso',
                'uploads' => $resultado['uploads'],
                'errors' => $resultado['errors']
            ]);
            break;
        
        // ========================================
        // GET - Listar ou Download
        // ========================================
        case 'GET':
            
            // Download de arquivo específico
            if (isset($_GET['download']) && isset($_GET['id'])) {
                $documento_id = intval($_GET['id']);
                
                // Buscar documento
                $documento = DocumentoHelper::buscarDocumento($documento_id);
                
                if (!$documento) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Documento não encontrado']);
                    exit;
                }
                
                // Verificar se arquivo existe
                if (!file_exists($documento['caminho_arquivo'])) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Arquivo não encontrado no servidor']);
                    exit;
                }
                
                // TODO: Validar permissão do usuário para baixar o arquivo
                
                // Enviar arquivo para download
                header('Content-Type: ' . $documento['mime_type']);
                header('Content-Disposition: attachment; filename="' . $documento['nome_original'] . '"');
                header('Content-Length: ' . $documento['tamanho_bytes']);
                header('Cache-Control: no-cache, must-revalidate');
                header('Expires: 0');
                
                readfile($documento['caminho_arquivo']);
                exit;
            }
            
            // Listar documentos
            else if (isset($_GET['tipo_vinculo']) && isset($_GET['vinculo_id'])) {
                $tipo_vinculo = $_GET['tipo_vinculo'];
                $vinculo_id = intval($_GET['vinculo_id']);
                $contexto = $_GET['contexto'] ?? null;
                
                $documentos = DocumentoHelper::listarDocumentos($tipo_vinculo, $vinculo_id, $contexto);
                
                // Adicionar informações formatadas
                foreach ($documentos as &$doc) {
                    $doc['tamanho_formatado'] = DocumentoHelper::formatarTamanho($doc['tamanho_bytes']);
                    $doc['data_upload_formatada'] = date('d/m/Y H:i', strtotime($doc['data_upload']));
                    $doc['download_url'] = '?download=1&id=' . $doc['id'];
                    
                    // Ícone baseado na extensão
                    $extensao = strtolower(pathinfo($doc['nome_original'], PATHINFO_EXTENSION));
                    $icone_map = [
                        'pdf' => 'fa-file-pdf text-danger',
                        'doc' => 'fa-file-word text-primary',
                        'docx' => 'fa-file-word text-primary',
                        'xls' => 'fa-file-excel text-success',
                        'xlsx' => 'fa-file-excel text-success',
                        'jpg' => 'fa-file-image text-info',
                        'jpeg' => 'fa-file-image text-info',
                        'png' => 'fa-file-image text-info',
                        'zip' => 'fa-file-archive text-warning',
                        'msg' => 'fa-envelope text-secondary'
                    ];
                    $doc['icone'] = $icone_map[$extensao] ?? 'fa-file text-muted';
                }
                
                echo json_encode([
                    'success' => true,
                    'documentos' => $documentos,
                    'total' => count($documentos)
                ]);
            }
            
            else {
                throw new Exception('Parâmetros inválidos para listagem');
            }
            break;
        
        // ========================================
        // DELETE - Excluir documento
        // ========================================
        case 'DELETE':
            // Obter ID do documento
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (!isset($data['id'])) {
                // Tentar obter do query string
                parse_str($_SERVER['QUERY_STRING'], $query);
                $documento_id = $query['id'] ?? null;
            } else {
                $documento_id = $data['id'];
            }
            
            if (!$documento_id) {
                throw new Exception('ID do documento não informado');
            }
            
            $resultado = DocumentoHelper::excluirDocumento($documento_id, $usuario_id);
            
            echo json_encode($resultado);
            break;
        
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Método não permitido']);
            break;
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}