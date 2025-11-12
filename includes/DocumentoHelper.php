<?php
/**
 * DocumentoHelper.php
 * Helper para gerenciamento de documentos anexados
 * 
 * Funções:
 * - Upload de arquivos
 * - Download de arquivos
 * - Listagem de documentos
 * - Exclusão lógica (soft delete)
 * - Validação de tipos e tamanhos
 */

class DocumentoHelper {
    
    // Configurações
    const MAX_FILE_SIZE = 10485760; // 10MB em bytes
    const ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'msg', 'zip'];
    const ALLOWED_MIMES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'image/jpeg',
        'image/jpg',
        'image/png',
        'application/vnd.ms-outlook',
        'application/zip',
        'application/x-zip-compressed'
    ];
    
    /**
     * Upload de arquivo
     * 
     * @param array $file Arquivo do $_FILES
     * @param string $tipo_vinculo tarefa|prazo|audiencia|publicacao|processo
     * @param int $vinculo_id ID da entidade vinculada
     * @param string $contexto revisao|correcao|protocolo|geral
     * @param int $usuario_id ID do usuário que está fazendo upload
     * @param string $descricao Descrição opcional
     * @return array ['success' => bool, 'documento_id' => int, 'message' => string]
     */
    public static function uploadDocumento($file, $tipo_vinculo, $vinculo_id, $contexto, $usuario_id, $descricao = null) {
        try {
            // Validar arquivo
            $validacao = self::validarArquivo($file);
            if (!$validacao['success']) {
                return $validacao;
            }
            
            // Gerar nome único
            $extensao = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $nome_arquivo = self::gerarNomeUnico($extensao);
            
            // Definir diretório
            $diretorio = self::getDiretorioUpload($tipo_vinculo, $vinculo_id);
            if (!file_exists($diretorio)) {
                mkdir($diretorio, 0755, true);
            }
            
            // Caminho completo
            $caminho_completo = $diretorio . '/' . $nome_arquivo;
            
            // Mover arquivo
            if (!move_uploaded_file($file['tmp_name'], $caminho_completo)) {
                return [
                    'success' => false,
                    'message' => 'Erro ao mover arquivo para o servidor'
                ];
            }
            
            // Inserir no banco
            $pdo = getConnection();
            $sql = "INSERT INTO documentos 
                    (tipo_vinculo, vinculo_id, nome_arquivo, nome_original, caminho_arquivo, 
                     tamanho_bytes, mime_type, contexto, usuario_upload_id, descricao)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $tipo_vinculo,
                $vinculo_id,
                $nome_arquivo,
                $file['name'],
                $caminho_completo,
                $file['size'],
                $file['type'],
                $contexto,
                $usuario_id,
                $descricao
            ]);
            
            $documento_id = $pdo->lastInsertId();
            
            return [
                'success' => true,
                'documento_id' => $documento_id,
                'message' => 'Arquivo enviado com sucesso',
                'nome_arquivo' => $nome_arquivo,
                'nome_original' => $file['name']
            ];
            
        } catch (Exception $e) {
            error_log("Erro no upload de documento: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Erro ao processar upload: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Upload múltiplo de arquivos
     * 
     * @param array $files Array de arquivos do $_FILES
     * @param string $tipo_vinculo
     * @param int $vinculo_id
     * @param string $contexto
     * @param int $usuario_id
     * @return array ['success' => bool, 'uploads' => array, 'errors' => array]
     */
    public static function uploadMultiplos($files, $tipo_vinculo, $vinculo_id, $contexto, $usuario_id) {
        $resultados = [
            'success' => true,
            'uploads' => [],
            'errors' => []
        ];
        
        // Reorganizar array de arquivos múltiplos
        $files_array = [];
        if (isset($files['name']) && is_array($files['name'])) {
            foreach ($files['name'] as $key => $name) {
                if (empty($name)) continue;
                
                $files_array[] = [
                    'name' => $files['name'][$key],
                    'type' => $files['type'][$key],
                    'tmp_name' => $files['tmp_name'][$key],
                    'error' => $files['error'][$key],
                    'size' => $files['size'][$key]
                ];
            }
        }
        
        foreach ($files_array as $file) {
            $resultado = self::uploadDocumento($file, $tipo_vinculo, $vinculo_id, $contexto, $usuario_id);
            
            if ($resultado['success']) {
                $resultados['uploads'][] = $resultado;
            } else {
                $resultados['errors'][] = [
                    'arquivo' => $file['name'],
                    'erro' => $resultado['message']
                ];
                $resultados['success'] = false;
            }
        }
        
        return $resultados;
    }
    
    /**
     * Listar documentos de uma entidade
     * 
     * @param string $tipo_vinculo
     * @param int $vinculo_id
     * @param string $contexto (opcional)
     * @return array
     */
    public static function listarDocumentos($tipo_vinculo, $vinculo_id, $contexto = null) {
        try {
            $pdo = getConnection();
            
            $sql = "SELECT d.*, u.nome as usuario_nome
                    FROM documentos d
                    LEFT JOIN usuarios u ON d.usuario_upload_id = u.id
                    WHERE d.tipo_vinculo = ? 
                    AND d.vinculo_id = ?
                    AND d.deleted_at IS NULL";
            
            $params = [$tipo_vinculo, $vinculo_id];
            
            if ($contexto !== null) {
                $sql .= " AND d.contexto = ?";
                $params[] = $contexto;
            }
            
            $sql .= " ORDER BY d.data_upload DESC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Erro ao listar documentos: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Buscar documento por ID
     * 
     * @param int $documento_id
     * @return array|false
     */
    public static function buscarDocumento($documento_id) {
        try {
            $pdo = getConnection();
            $sql = "SELECT * FROM documentos WHERE id = ? AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$documento_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Erro ao buscar documento: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Excluir documento (soft delete)
     * 
     * @param int $documento_id
     * @param int $usuario_id (para validação de permissão)
     * @return array ['success' => bool, 'message' => string]
     */
    public static function excluirDocumento($documento_id, $usuario_id) {
        try {
            $pdo = getConnection();
            
            // Buscar documento
            $doc = self::buscarDocumento($documento_id);
            if (!$doc) {
                return ['success' => false, 'message' => 'Documento não encontrado'];
            }
            
            // Validar permissão (apenas quem fez upload ou admin pode excluir)
            // TODO: Adicionar validação de admin
            if ($doc['usuario_upload_id'] != $usuario_id) {
                return ['success' => false, 'message' => 'Sem permissão para excluir este documento'];
            }
            
            // Soft delete
            $sql = "UPDATE documentos SET deleted_at = NOW() WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$documento_id]);
            
            return ['success' => true, 'message' => 'Documento excluído com sucesso'];
            
        } catch (Exception $e) {
            error_log("Erro ao excluir documento: " . $e->getMessage());
            return ['success' => false, 'message' => 'Erro ao excluir documento'];
        }
    }
    
    /**
     * Validar arquivo antes do upload
     * 
     * @param array $file
     * @return array ['success' => bool, 'message' => string]
     */
    private static function validarArquivo($file) {
        // Verificar erros de upload
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return [
                'success' => false,
                'message' => 'Erro no upload: ' . self::getUploadError($file['error'])
            ];
        }
        
        // Verificar tamanho
        if ($file['size'] > self::MAX_FILE_SIZE) {
            return [
                'success' => false,
                'message' => 'Arquivo muito grande. Tamanho máximo: ' . (self::MAX_FILE_SIZE / 1048576) . 'MB'
            ];
        }
        
        // Verificar extensão
        $extensao = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extensao, self::ALLOWED_EXTENSIONS)) {
            return [
                'success' => false,
                'message' => 'Tipo de arquivo não permitido. Extensões permitidas: ' . implode(', ', self::ALLOWED_EXTENSIONS)
            ];
        }
        
        // Verificar MIME type
        if (!in_array($file['type'], self::ALLOWED_MIMES)) {
            return [
                'success' => false,
                'message' => 'Tipo MIME não permitido'
            ];
        }
        
        return ['success' => true];
    }
    
    /**
     * Gerar nome único para arquivo
     * 
     * @param string $extensao
     * @return string
     */
    private static function gerarNomeUnico($extensao) {
        return uniqid('doc_', true) . '_' . time() . '.' . $extensao;
    }
    
    /**
     * Obter diretório de upload baseado no tipo
     * 
     * @param string $tipo_vinculo
     * @param int $vinculo_id
     * @return string
     */
    private static function getDiretorioUpload($tipo_vinculo, $vinculo_id) {
        $base_dir = $_SERVER['DOCUMENT_ROOT'] . '/documentos';
        return $base_dir . '/' . $tipo_vinculo . 's/' . $vinculo_id;
    }
    
    /**
     * Obter mensagem de erro de upload
     * 
     * @param int $error_code
     * @return string
     */
    private static function getUploadError($error_code) {
        switch ($error_code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'Arquivo excede o tamanho máximo permitido';
            case UPLOAD_ERR_PARTIAL:
                return 'Upload incompleto';
            case UPLOAD_ERR_NO_FILE:
                return 'Nenhum arquivo foi enviado';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Diretório temporário não encontrado';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Falha ao escrever arquivo no disco';
            case UPLOAD_ERR_EXTENSION:
                return 'Upload bloqueado por extensão PHP';
            default:
                return 'Erro desconhecido no upload';
        }
    }
    
    /**
     * Formatar tamanho de arquivo
     * 
     * @param int $bytes
     * @return string
     */
    public static function formatarTamanho($bytes) {
        $unidades = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < 3) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $unidades[$i];
    }
}