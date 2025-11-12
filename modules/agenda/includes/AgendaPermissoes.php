<?php
/**
 * Funções de verificação de permissão para módulo de agenda
 */

class AgendaPermissoes {
    
    /**
     * Verifica se o usuário é gestor (pode editar e excluir qualquer item)
     */
    public static function ehGestor($usuario = null) {
        if (!$usuario) {
            $usuario = Auth::user();
        }
        
        $nivel_acesso = $usuario['nivel_acesso'] ?? '';
        $niveis_permitidos = ['Admin', 'Gestor', 'Gerente'];
        
        return in_array($nivel_acesso, $niveis_permitidos);
    }
    
    /**
     * Verifica se o usuário pode editar um item
     * Regra: Apenas gestores podem editar tarefas
     */
    public static function podeEditar($item, $tipo, $usuario = null) {
        if (!$usuario) {
            $usuario = Auth::user();
        }
        
        // Gestores podem editar tudo
        if (self::ehGestor($usuario)) {
            return true;
        }
        
        // Para TAREFAS: apenas gestores podem editar
        if ($tipo === 'tarefa') {
            return false;
        }
        
        // Para outros tipos (prazo, audiência, evento): 
        // Pode editar se for o criador ou responsável (regra antiga)
        $eh_criador = ($item['criado_por'] ?? null) == $usuario['usuario_id'];
        $eh_responsavel = ($item['responsavel_id'] ?? null) == $usuario['usuario_id'];
        
        return $eh_criador || $eh_responsavel;
    }
    
    /**
     * Verifica se o usuário pode excluir um item
     * Regra: Apenas gestores podem excluir
     */
    public static function podeExcluir($item, $tipo, $usuario = null) {
        if (!$usuario) {
            $usuario = Auth::user();
        }
        
        // Apenas gestores podem excluir
        return self::ehGestor($usuario);
    }
    
    /**
     * Verifica se item está concluído
     */
    public static function estaConcluido($item) {
        $status = strtolower($item['status'] ?? '');
        return in_array($status, ['concluida', 'concluído', 'cumprido', 'realizado']);
    }
    
    /**
     * Retorna mensagem de erro para falta de permissão
     */
    public static function getMensagemErro($acao, $tipo) {
        $mensagens = [
            'editar' => [
                'tarefa' => 'Apenas gestores podem editar tarefas',
                'prazo' => 'Você não tem permissão para editar este prazo',
                'audiencia' => 'Você não tem permissão para editar esta audiência',
                'evento' => 'Você não tem permissão para editar este evento',
                'compromisso' => 'Você não tem permissão para editar este compromisso'
            ],
            'excluir' => [
                'default' => 'Apenas gestores podem excluir itens da agenda'
            ]
        ];
        
        if ($acao === 'excluir') {
            return $mensagens['excluir']['default'];
        }
        
        return $mensagens[$acao][$tipo] ?? 'Você não tem permissão para realizar esta ação';
    }
}
