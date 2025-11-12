<?php
/**
 * ProcessoHistoricoHelper - Sistema de Auditoria Detalhado
 * 
 * Registra TODAS as alterações em processos com detalhes completos:
 * - Qual campo foi alterado
 * - Valor anterior
 * - Valor novo
 * - Quem alterou
 * - Quando alterou
 * - De onde alterou (IP + User Agent)
 */

class ProcessoHistoricoDetalhado {
    
    /**
     * Registrar alteração detalhada de processo
     * 
     * @param int $processo_id ID do processo
     * @param string $acao Descrição da ação (ex: "Processo Editado")
     * @param array $alteracoes Array com as alterações [campo => [anterior, novo]]
     * @param int $usuario_id ID do usuário que fez a alteração
     * @return bool
     */
    public static function registrarEdicao($processo_id, $acao, $alteracoes, $usuario_id = null) {
        if (!$usuario_id && isset($_SESSION['usuario_id'])) {
            $usuario_id = $_SESSION['usuario_id'];
        }
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'N/A';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'N/A';
        
        // Labels legíveis para os campos
        $labels = self::getLabels();
        
        // Registrar cada alteração individualmente
        foreach ($alteracoes as $campo => $valores) {
            $valor_anterior = $valores[0];
            $valor_novo = $valores[1];
            
            // Pular se não houve mudança
            if ($valor_anterior === $valor_novo) {
                continue;
            }
            
            $label = $labels[$campo] ?? ucfirst(str_replace('_', ' ', $campo));
            
            // Formatar valores para exibição
            $valor_anterior_formatado = self::formatarValor($campo, $valor_anterior);
            $valor_novo_formatado = self::formatarValor($campo, $valor_novo);
            
            // Descrição legível
            $descricao = "Campo \"{$label}\" alterado:\n";
            $descricao .= "De: {$valor_anterior_formatado}\n";
            $descricao .= "Para: {$valor_novo_formatado}";
            
            $sql = "INSERT INTO processo_historico 
                    (processo_id, tipo, acao, descricao, campo_alterado, valor_anterior, valor_novo, 
                     data_acao, usuario_id, ip_usuario, user_agent, data_registro) 
                    VALUES (?, 'edicao', ?, ?, ?, ?, ?, NOW(), ?, ?, ?, NOW())";
            
            try {
                executeQuery($sql, [
                    $processo_id,
                    $acao,
                    $descricao,
                    $campo,
                    $valor_anterior,
                    $valor_novo,
                    $usuario_id,
                    $ip,
                    substr($user_agent, 0, 255)
                ]);
            } catch (Exception $e) {
                error_log("Erro ao registrar histórico: " . $e->getMessage());
            }
        }
        
        return true;
    }
    
    /**
     * Detectar alterações entre dados antigos e novos
     * 
     * @param array $dados_antigos Dados antes da edição
     * @param array $dados_novos Dados após a edição
     * @return array Array de alterações
     */
    public static function detectarAlteracoes($dados_antigos, $dados_novos) {
        $alteracoes = [];
        
        // Campos a monitorar
        $campos_monitorados = [
            'numero_processo',
            'tipo_processo',
            'situacao_processual',
            'cliente_nome',
            'responsavel_id',
            'nucleo_id',
            'valor_causa',
            'observacoes',
            'anotacoes',
            'status',
            'data_fechamento_contrato',
            'data_protocolo',
            'comarca',
            'vara',
            'polo_ativo',
            'polo_passivo'
        ];
        
        foreach ($campos_monitorados as $campo) {
            $valor_antigo = $dados_antigos[$campo] ?? null;
            $valor_novo = $dados_novos[$campo] ?? null;
            
            // Normalizar valores vazios
            if ($valor_antigo === '' || $valor_antigo === null) $valor_antigo = null;
            if ($valor_novo === '' || $valor_novo === null) $valor_novo = null;
            
            // Se houve alteração
            if ($valor_antigo != $valor_novo) {
                $alteracoes[$campo] = [$valor_antigo, $valor_novo];
            }
        }
        
        return $alteracoes;
    }
    
    /**
     * Registrar ação simples (sem comparação de valores)
     */
    public static function registrarAcao($processo_id, $tipo, $acao, $descricao, $usuario_id = null) {
        if (!$usuario_id && isset($_SESSION['usuario_id'])) {
            $usuario_id = $_SESSION['usuario_id'];
        }
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'N/A';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'N/A';
        
        $sql = "INSERT INTO processo_historico 
                (processo_id, tipo, acao, descricao, data_acao, usuario_id, ip_usuario, user_agent, data_registro) 
                VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, NOW())";
        
        try {
            executeQuery($sql, [
                $processo_id,
                $tipo,
                $acao,
                $descricao,
                $usuario_id,
                $ip,
                substr($user_agent, 0, 255)
            ]);
            return true;
        } catch (Exception $e) {
            error_log("Erro ao registrar histórico: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obter labels legíveis para os campos
     */
    private static function getLabels() {
        return [
            'numero_processo' => 'Número do Processo',
            'tipo_processo' => 'Tipo de Processo',
            'situacao_processual' => 'Situação Processual',
            'cliente_nome' => 'Cliente',
            'responsavel_id' => 'Responsável',
            'nucleo_id' => 'Núcleo',
            'valor_causa' => 'Valor da Causa',
            'observacoes' => 'Observações',
            'anotacoes' => 'Anotações',
            'status' => 'Status',
            'data_fechamento_contrato' => 'Data de Fechamento',
            'data_protocolo' => 'Data do Protocolo',
            'comarca' => 'Comarca',
            'vara' => 'Vara',
            'polo_ativo' => 'Polo Ativo',
            'polo_passivo' => 'Polo Passivo'
        ];
    }
    
    /**
     * Formatar valor para exibição
     */
    private static function formatarValor($campo, $valor) {
        // Valor vazio
        if ($valor === null || $valor === '') {
            return '(vazio)';
        }
        
        // Datas
        if (in_array($campo, ['data_fechamento_contrato', 'data_protocolo', 'data_criacao'])) {
            try {
                return date('d/m/Y', strtotime($valor));
            } catch (Exception $e) {
                return $valor;
            }
        }
        
        // Valores monetários
        if ($campo === 'valor_causa') {
            return 'R$ ' . number_format($valor, 2, ',', '.');
        }
        
        // IDs - buscar nomes
        if ($campo === 'responsavel_id') {
            return self::getNomeUsuario($valor);
        }
        
        if ($campo === 'nucleo_id') {
            return self::getNomeNucleo($valor);
        }
        
        // Textos longos
        if (strlen($valor) > 100) {
            return substr($valor, 0, 100) . '...';
        }
        
        return $valor;
    }
    
    /**
     * Buscar nome do usuário
     */
    private static function getNomeUsuario($usuario_id) {
        if (!$usuario_id) return '(vazio)';
        
        try {
            $sql = "SELECT nome FROM usuarios WHERE id = ?";
            $stmt = executeQuery($sql, [$usuario_id]);
            $user = $stmt->fetch();
            return $user ? $user['nome'] : "Usuário #{$usuario_id}";
        } catch (Exception $e) {
            return "Usuário #{$usuario_id}";
        }
    }
    
    /**
     * Buscar nome do núcleo
     */
    private static function getNomeNucleo($nucleo_id) {
        if (!$nucleo_id) return '(vazio)';
        
        try {
            $sql = "SELECT nome FROM nucleos WHERE id = ?";
            $stmt = executeQuery($sql, [$nucleo_id]);
            $nucleo = $stmt->fetch();
            return $nucleo ? $nucleo['nome'] : "Núcleo #{$nucleo_id}";
        } catch (Exception $e) {
            return "Núcleo #{$nucleo_id}";
        }
    }
    
    /**
     * Buscar histórico detalhado para exibição
     */
    public static function buscarHistorico($processo_id, $limit = 100) {
        $sql = "SELECT h.*, u.nome as usuario_nome
                FROM processo_historico h
                LEFT JOIN usuarios u ON h.usuario_id = u.id
                WHERE h.processo_id = ?
                ORDER BY h.data_acao DESC
                LIMIT ?";
        
        $stmt = executeQuery($sql, [$processo_id, $limit]);
        return $stmt->fetchAll();
    }
}