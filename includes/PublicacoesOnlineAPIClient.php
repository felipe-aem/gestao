<?php

// includes/PublicacoesOnlineAPIClient.php

// Garante que o arquivo de configuração da API foi carregado
require_once dirname(__DIR__) . '/config/api.php';

class PublicacoesOnlineAPIClient {
    // Propriedades privadas para armazenar configurações e controlar o rate limit
    private string $hashCliente;
    private string $apiBaseUrl;
    private int $minIntervalSeconds = 300; // 5 minutos = 300 segundos (conforme documentação da API)
    private string $lastRequestTimeFilePath; // Caminho para o arquivo onde guardamos o timestamp da última requisição
    private float $lastRequestTime; // Timestamp da última requisição bem-sucedida

    /**
     * Construtor da classe PublicacoesOnlineAPIClient.
     * Inicializa as configurações da API e carrega o timestamp da última requisição.
     *
     * @throws Exception Se as configurações da API não estiverem definidas.
     */
    public function __construct() {
        // Verifica se as constantes de configuração foram definidas em config/api.php
        if (!defined('PUBLICACOES_HASH_CLIENTE') || !defined('PUBLICACOES_API_BASE_URL')) {
            throw new Exception("As configurações da API de Publicações Online (PUBLICACOES_HASH_CLIENTE ou PUBLICACOES_API_BASE_URL) não estão definidas. Verifique o arquivo config/api.php.");
        }
        $this->hashCliente = PUBLICACOES_HASH_CLIENTE;
        $this->apiBaseUrl = PUBLICACOES_API_BASE_URL;

        // Define o caminho para o arquivo de controle de tempo.
        // sys_get_temp_dir() retorna o diretório de arquivos temporários do sistema operacional.
        // Assegure-se que este diretório seja gravável pelo usuário que executa o processo PHP.
        $this->lastRequestTimeFilePath = sys_get_temp_dir() . '/publicacoes_online_last_request_time.txt';
        $this->loadLastRequestTime();
    }

    /**
     * Carrega o timestamp da última requisição do arquivo de controle persistente.
     * Se o arquivo não existir, assume 0 (nenhuma requisição anterior).
     */
    private function loadLastRequestTime(): void {
        if (file_exists($this->lastRequestTimeFilePath)) {
            // Garante que o conteúdo é tratado como float para cálculos precisos de tempo
            $this->lastRequestTime = (float)file_get_contents($this->lastRequestTimeFilePath);
        } else {
            $this->lastRequestTime = 0.0; // Inicializa com 0 se não houver registro anterior
        }
    }

    /**
     * Salva o timestamp da requisição atual no arquivo de controle.
     * Isso é feito APÓS uma requisição bem-sucedida para registrar o último momento válido de acesso.
     */
    private function saveLastRequestTime(): void {
        // microtime(true) retorna o timestamp atual com precisão de microssegundos
        file_put_contents($this->lastRequestTimeFilePath, microtime(true));
        $this->lastRequestTime = microtime(true);
    }

    /**
     * Faz uma requisição à API de Publicações Online, aplicando o controle de rate limit e tratamento de encoding.
     *
     * @param string $endpoint O caminho do endpoint (e.g., 'index_pe.php').
     * @param array $params Parâmetros da query string. O hashCliente e o tipo de retorno serão adicionados automaticamente.
     * @param string $method Método HTTP ('GET' ou 'POST').
     * @param array $postFields Dados para requisições POST, no formato chave-valor.
     * @return array A resposta decodificada da API como um array associativo.
     * @throws Exception Em caso de erro na requisição HTTP, na resposta da API, rate limit ou problemas de decodificação.
     */
    private function makeRequest(string $endpoint, array $params = [], string $method = 'GET', array $postFields = []): array {
        // --- Controle de Rate Limit ---
        $currentTime = microtime(true);
        $timeSinceLastRequest = $currentTime - $this->lastRequestTime;

        if ($timeSinceLastRequest < $this->minIntervalSeconds) {
            $remainingTime = $this->minIntervalSeconds - $timeSinceLastRequest;
            // Para um cron job, é preferível lançar uma exceção e deixar o agendador decidir
            // se tenta novamente mais tarde, em vez de fazer o script esperar.
            throw new Exception(
                "Rate limit excedido: Intervalo mínimo de " . $this->minIntervalSeconds .
                " segundos entre requisições não atingido. Por favor, aguarde aproximadamente " .
                ceil($remainingTime) . " segundos antes de tentar novamente."
            );
        }

        // Adiciona o hashCliente e o tipo de retorno aos parâmetros da query string (GET ou POST URL)
        $params['hashCliente'] = $this->hashCliente;
        $params['retorno'] = 'JSON'; // Conforme a documentação, forçamos o retorno JSON

        $url = $this->apiBaseUrl . $endpoint;
        // Para requisições GET, todos os parâmetros vão na URL
        if ($method === 'GET' && !empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Retorna a transferência como string
        curl_setopt($ch, CURLOPT_TIMEOUT, 60); // Tempo máximo em segundos que a operação cURL pode levar
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // Tempo máximo em segundos para tentar conectar
        // curl_setopt($ch, CURLOPT_ENCODING, "ISO-8859-1"); // Não é recomendado, pois depende do Content-Type do servidor.
                                                         // Faremos a conversão manual com iconv.

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            // Para POST, os parâmetros de query string (como hashCliente) também vão na URL.
            // Os dados específicos do POST (e.g., listaIdsRetorno) vão no corpo da requisição.
            // A documentação sugere que o conteúdo seja passado via PARÂMETRO, o que geralmente
            // implica em 'application/x-www-form-urlencoded' para o corpo.
            if (!empty($params)) { // Adiciona parâmetros como hashCliente na URL mesmo em POST
                $url .= '?' . http_build_query($params);
                curl_setopt($ch, CURLOPT_URL, $url); // Atualiza a URL com query params
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // Verifica por erros de rede ou cURL
        if (curl_errno($ch)) {
            $errorMsg = curl_error($ch);
            curl_close($ch);
            throw new Exception("Erro cURL ao conectar à API de Publicações Online ({$endpoint}): {$errorMsg}");
        }

        curl_close($ch);
        // Salva o tempo da requisição AGORA, após a execução bem-sucedida do cURL,
        // para que a próxima requisição possa respeitar o intervalo mínimo.
        $this->saveLastRequestTime();

        // --- Tratamento de Encoding ---
        // A API retorna ISO-8859-1. Precisamos converter para UTF-8 antes de json_decode.
        // '//TRANSLIT//IGNORE' tenta transliterar caracteres que não podem ser representados em UTF-8
        // e ignora aqueles que não podem ser transliterados, evitando erros fatais na conversão.
        $response_utf8 = iconv("ISO-8859-1", "UTF-8//TRANSLIT//IGNORE", $response);

        // --- Decodificação JSON ---
        $responseData = json_decode($response_utf8, true);

        // Verifica se a decodificação JSON falhou
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception(
                "Erro ao decodificar a resposta JSON da API ({$endpoint}): " . json_last_error_msg() .
                " - Resposta bruta (primeiros 200 caracteres): " . htmlspecialchars(substr($response_utf8, 0, 200))
            );
        }

        // --- Tratamento de Erros Específicos da API ---
        // A documentação lista códigos de erro e mensagens.
        // O código '912' ("Nenhuma Publicação disponivel") e '1000' ("Sucesso") não são erros fatais.
        if (isset($responseData['codigo'])) {
            if ($responseData['codigo'] === '910') { // Limite de consultas excedido
                throw new Exception("Erro da API ({$endpoint}, Cod: {$responseData['codigo']}): Limite de consultas excedido. Por favor, aguarde e tente novamente.");
            }
            if ($responseData['codigo'] === '100') { // Login inválido (hashCliente incorreto)
                throw new Exception("Erro da API ({$endpoint}, Cod: {$responseData['codigo']}): Hash Cliente inválido. Verifique suas credenciais.");
            }
            if ($responseData['codigo'] !== '912' && $responseData['codigo'] !== '1000') {
                 throw new Exception("Erro da API ({$endpoint}, Cod: {$responseData['codigo']}): {$responseData['mensagem']}");
            }
        }
        // Alguns erros podem vir apenas na chave 'mensagem' sem um código 'codigo' explícito
        if (isset($responseData['mensagem']) && strpos(strtoupper($responseData['mensagem']), 'ERRO') !== false) {
             throw new Exception("Erro da API ({$endpoint}): {$responseData['mensagem']}");
        }

        return $responseData;
    }

    /**
     * Busca publicações usando o endpoint index_pe.php.
     * Este é o método principal para obter intimações.
     *
     * @param string $dateString Data para busca no formato YYYY-MM-DD.
     * @param string $processadas Status de processamento ('N' para Não processadas, 'S' para Sim, 'L' para Lidas, 'T' para Todas). Padrão 'N'.
     * @param int|null $limit Limite de publicações a serem retornadas. Se usado, é OBRIGATÓRIO marcar essas publicações como processadas.
     * @return array Um array de publicações. Retorna um array vazio em caso de erro ou sem resultados.
     * @throws InvalidArgumentException Se o formato da data for inválido.
     */
    public function fetchPublications(string $dateString, string $processadas = 'N', ?int $limit = null): array {
        // Validação básica do formato da data
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateString)) {
            throw new InvalidArgumentException("Formato de data inválido para fetchPublications. Use YYYY-MM-DD.");
        }

        $params = [
            'data' => $dateString,
            'processadas' => $processadas,
            'quebraLinha' => 'true', // Manter quebras de linha no conteúdo da publicação para preservar formatação
        ];

        if ($limit !== null) {
            $params['limit'] = $limit;
        }

        $endpoint = 'index_pe.php';

        try {
            $response = $this->makeRequest($endpoint, $params);
            // A API retorna um array direto de publicações. Se não houver publicações,
            // pode retornar um objeto com "mensagem" e "codigo": "912".
            if (isset($response['codigo']) && $response['codigo'] === '912') {
                return []; // Nenhuma publicação disponível, não é um erro fatal, apenas ausência de dados
            }
            // Garante que a resposta é um array antes de retorná-la.
            return is_array($response) ? $response : [];
        } catch (Exception $e) {
            // Loga o erro para depuração em ambientes de produção
            error_log("Erro ao buscar publicações em index_pe.php para data {$dateString} (status: {$processadas}): " . $e->getMessage());
            return []; // Retorna um array vazio para que o processo possa continuar sem dados
        }
    }

    /**
     * Marca uma lista de publicações como processadas usando o endpoint index_pe_processadas.php.
     * Este método é crucial para garantir que as publicações não sejam retornadas novamente em buscas futuras com 'processadas=N'.
     *
     * @param array $idWsList Lista de IDs (idWs) das publicações a serem marcadas como processadas.
     * @return bool True em caso de sucesso na marcação, false caso contrário.
     */
    public function markPublicationsAsProcessed(array $idWsList): bool {
        if (empty($idWsList)) {
            return true; // Nada para processar, considera como sucesso (operação nula).
        }

        $endpoint = 'index_pe_processadas.php';
        $postFields = [
            'listaIdsRetorno' => implode(',', $idWsList), // Converte o array de IDs em uma string separada por vírgulas
        ];

        try {
            $response = $this->makeRequest($endpoint, [], 'POST', $postFields);
            // A documentação indica que o sucesso é sinalizado pela mensagem específica:
            // "mensagem": "Lista de retorno processada com sucesso!".
            return isset($response['mensagem']) && $response['mensagem'] === 'Lista de retorno processada com sucesso!';
        } catch (Exception $e) {
            error_log("Erro ao marcar publicações como processadas (IDs: " . implode(',', $idWsList) . "): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Busca distribuições usando o endpoint index_dist.php.
     * Este método pode ser usado se o sistema também precisar processar distribuições.
     *
     * @param string $dateString Data para busca no formato YYYY-MM-DD.
     * @param string $processadas Status de processamento ('N' para Não processadas, 'S' para Sim, 'L' para Lidas, 'T' para Todas). Padrão 'N'.
     * @return array Um array de distribuições. Retorna um array vazio em caso de erro ou sem resultados.
     * @throws InvalidArgumentException Se o formato da data for inválido.
     */
    public function fetchDistributions(string $dateString, string $processadas = 'N'): array {
        // Validação básica do formato da data
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateString)) {
            throw new InvalidArgumentException("Formato de data inválido para fetchDistributions. Use YYYY-MM-DD.");
        }

        $params = [
            'data' => $dateString,
            'processadas' => $processadas,
        ];

        $endpoint = 'index_dist.php';

        try {
            $response = $this->makeRequest($endpoint, $params);
            if (isset($response['codigo']) && $response['codigo'] === '912') {
                return []; // Nenhuma distribuição disponível, não é um erro fatal
            }
            return is_array($response) ? $response : [];
        } catch (Exception $e) {
            error_log("Erro ao buscar distribuições em index_dist.php para data {$dateString} (status: {$processadas}): " . $e->getMessage());
            return [];
        }
    }

    // Outros métodos para interagir com a API (cadastro, histórico, etc.) podem ser adicionados aqui se necessário.
}

?>