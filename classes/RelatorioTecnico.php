<?php
/**
 * =====================================================================
 * CLASSE RELATORIOTECNICO - Gerenciar Relatórios Técnicos
 * =====================================================================
 * 
 * Responsabilidade: CRUD de relatórios técnicos de serviços
 * Uso: $relatorio = new RelatorioTecnico(); $relatorio->criar(...);
 * Recebe: Dados de diagnóstico, manutenção, reparo
 * Retorna: Arrays com relatórios e descrições melhoradas por IA
 * 
 * Operações:
 * - Criar novo relatório
 * - Melhorar descrição com IA
 * - Adicionar fotos do serviço
 * - Gerar PDF
 * - Enviar por WhatsApp
 * - Listar relatórios
 */

require_once __DIR__ . '/Database.php';

class RelatorioTecnico {
    
    private $db;
    private $tabela = 'relatorios_tecnicos';
    
    /**
     * Construtor
     */
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Método: criar()
     * Responsabilidade: Criar novo relatório técnico
     * Parâmetros:
     *   $cliente_id - int com ID do cliente
     *   $dados - array com dados do relatório
     * Retorna: int (ID do novo relatório) ou false
     * 
     * Campos em $dados:
     *   - data_servico: data do serviço (padrão: hoje)
     *   - tipo: 'diagnostico', 'manutencao', 'instalacao', 'reparo'
     *   - conteudo: descrição do problema/serviço (obrigatório)
     *   - foto: arquivo de imagem (opcional)
     * 
     * Exemplo:
     *   $relatorio_id = $relatorio->criar(123, [
     *       'data_servico' => '2026-02-14',
     *       'tipo' => 'diagnostico',
     *       'conteudo' => 'Cliente relata que ar não esfria...'
     *   ]);
     */
    public function criar($cliente_id, $dados = []) {
        if (empty($cliente_id) || empty($dados['conteudo'])) {
            return false;
        }
        
        // Gera número único
        $numero = $this->gerar_numero_relatorio();
        
        // Define data
        $data_servico = $dados['data_servico'] ?? date('Y-m-d');
        
        // Prepara dados
        $dados_insert = [
            'numero' => $numero,
            'cliente_id' => $cliente_id,
            'data_servico' => $data_servico,
            'tipo' => $dados['tipo'] ?? 'diagnostico',
            'conteudo' => $dados['conteudo'],
            'status' => 'rascunho'
        ];
        
        // Insere no banco
        $id = $this->db->insert($this->tabela, $dados_insert);
        
        return $id;
    }
    
    /**
     * Método: obter()
     * Responsabilidade: Obter dados completos de um relatório
     * Parâmetros: $id (int)
     * Retorna: array com dados do relatório ou false
     * 
     * Uso:
     *   $relatorio = $relatorio_obj->obter(123);
     */
    public function obter($id) {
        if (empty($id)) {
            return false;
        }
        
        return $this->db->selectOne($this->tabela, ['id' => $id]);
    }
    
    /**
     * Método: listar()
     * Responsabilidade: Listar relatórios com filtros
     * Parâmetros:
     *   $filtro - array com filtros
     *   $limite - int com registros por página
     *   $pagina - int com número da página
     * Retorna: array com relatórios
     * 
     * Filtros:
     *   - cliente_id: filtrar por cliente
     *   - tipo: tipo de relatório
     *   - status: 'rascunho', 'enviado', 'visualizado'
     */
    public function listar($filtro = [], $limite = REGISTROS_POR_PAGINA, $pagina = 1) {
        $where = [];
        
        if (!empty($filtro['cliente_id'])) {
            $where['cliente_id'] = $filtro['cliente_id'];
        }
        
        if (!empty($filtro['tipo'])) {
            $where['tipo'] = $filtro['tipo'];
        }
        
        if (!empty($filtro['status'])) {
            $where['status'] = $filtro['status'];
        }
        
        $offset = ($pagina - 1) * $limite;
        
        return $this->db->select($this->tabela, $where, 'data_servico DESC', $limite, $offset);
    }
    
    /**
     * Método: obter_por_cliente()
     * Responsabilidade: Obter todos os relatórios de um cliente
     * Parâmetros: $cliente_id (int)
     * Retorna: array com relatórios
     * 
     * Uso:
     *   $relatorios = $relatorio_obj->obter_por_cliente(123);
     */
    public function obter_por_cliente($cliente_id) {
        if (empty($cliente_id)) {
            return [];
        }
        
        return $this->db->select($this->tabela, 
            ['cliente_id' => $cliente_id],
            'data_servico DESC'
        );
    }
    
    /**
     * Método: melhorar_com_ia()
     * Responsabilidade: Melhorar descrição do relatório usando IA
     * Parâmetros:
     *   $id - int com ID do relatório
     *   $api_provider - string ('openai', 'gemini', 'local')
     * Retorna: string com descrição melhorada ou false
     * 
     * Nota: Requer configuração de API em variáveis de ambiente
     * 
     * Uso:
     *   $descricao_ia = $relatorio_obj->melhorar_com_ia(123, 'openai');
     */
    public function melhorar_com_ia($id, $api_provider = 'openai') {
        if (empty($id)) {
            return false;
        }
        
        // Busca relatório
        $relatorio = $this->obter($id);
        if (!$relatorio) {
            return false;
        }
        
        // Monta prompt para IA
        $prompt = $this->gerar_prompt_ia($relatorio);
        
        // Chama IA baseado no provider
        $resposta_ia = null;
        
        switch ($api_provider) {
            case 'openai':
                $resposta_ia = $this->chamar_openai($prompt);
                break;
            case 'gemini':
                $resposta_ia = $this->chamar_gemini($prompt);
                break;
            case 'local':
                // Usa IA local se disponível
                $resposta_ia = $this->chamar_ia_local($prompt);
                break;
        }
        
        if (!$resposta_ia) {
            return false;
        }
        
        // Atualiza relatório com conteúdo da IA
        $this->db->update($this->tabela, 
            ['conteudo_ia' => $resposta_ia],
            ['id' => $id]
        );
        
        return $resposta_ia;
    }
    
    /**
     * Método PRIVADO: gerar_prompt_ia()
     * Responsabilidade: Gerar prompt para IA baseado no relatório
     * Parâmetros: $relatorio (array)
     * Retorna: string com prompt
     */
    private function gerar_prompt_ia($relatorio) {
        $tipo = $relatorio['tipo'];
        
        $prompt = "Como especialista técnico em ar condicionado e refrigeração, ";
        $prompt .= "melhore e padronize o seguinte relatório " . $tipo . " de forma técnica ";
        $prompt .= "e profissional, deixando claro e objetivo:\n\n";
        $prompt .= $relatorio['conteudo'] . "\n\n";
        $prompt .= "Melhorias desejadas:\n";
        $prompt .= "1. Use terminologia técnica apropriada\n";
        $prompt .= "2. Organize em tópicos claros\n";
        $prompt .= "3. Seja direto e objetivo\n";
        $prompt .= "4. Inclua recomendações se aplicável\n";
        $prompt .= "5. Mantenha tom profissional\n";
        
        return $prompt;
    }
    
    /**
     * Método PRIVADO: chamar_openai()
     * Responsabilidade: Chamar API OpenAI (GPT)
     * Parâmetros: $prompt (string)
     * Retorna: string com resposta ou false
     * 
     * Nota: Requer OPENAI_API_KEY configurado
     */
    private function chamar_openai($prompt) {
        // Verifica se tem configuração
        if (empty(IA_API_KEY) || strpos(IA_API_URL, 'openai') === false) {
            return false;
        }
        
        // Prepara requisição
        $dados = [
            'model' => IA_MODEL ?? 'gpt-3.5-turbo',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.7,
            'max_tokens' => 1000
        ];
        
        // Faz requisição
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . IA_API_KEY
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dados));
        
        $resposta = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code !== 200) {
            registrar_log_erro("Erro OpenAI: HTTP {$http_code}");
            return false;
        }
        
        // Parse da resposta
        $resultado = json_decode($resposta, true);
        
        if (isset($resultado['choices'][0]['message']['content'])) {
            return $resultado['choices'][0]['message']['content'];
        }
        
        return false;
    }
    
    /**
     * Método PRIVADO: chamar_gemini()
     * Responsabilidade: Chamar API Google Gemini
     * Parâmetros: $prompt (string)
     * Retorna: string com resposta ou false
     */
    private function chamar_gemini($prompt) {
        // Verifica se tem configuração
        if (empty(IA_API_KEY)) {
            return false;
        }
        
        // Prepara requisição
        $url = 'https://generativelanguage.googleapis.com/v1/models/gemini-pro:generateContent';
        
        $dados = [
            'contents' => [
                [
                    'parts' => [
                        [
                            'text' => $prompt
                        ]
                    ]
                ]
            ]
        ];
        
        // Faz requisição
        $ch = curl_init($url . '?key=' . IA_API_KEY);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dados));
        
        $resposta = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code !== 200) {
            registrar_log_erro("Erro Gemini: HTTP {$http_code}");
            return false;
        }
        
        // Parse da resposta
        $resultado = json_decode($resposta, true);
        
        if (isset($resultado['candidates'][0]['content']['parts'][0]['text'])) {
            return $resultado['candidates'][0]['content']['parts'][0]['text'];
        }
        
        return false;
    }
    
    /**
     * Método PRIVADO: chamar_ia_local()
     * Responsabilidade: Chamar IA local (placeholder)
     * Parâmetros: $prompt (string)
     * Retorna: string com resposta
     * 
     * Nota: Implementação futura com modelos locais (Ollama, etc)
     */
    private function chamar_ia_local($prompt) {
        // Placeholder para IA local
        // Em produção, integraria com Ollama ou similar
        
        // Por enquanto, retorna uma formatação simples
        $resposta = "**RELATÓRIO TÉCNICO - ANÁLISE PROFISSIONAL**\n\n";
        $resposta .= "Conforme análise realizada:\n\n";
        $resposta .= $prompt . "\n\n";
        $resposta .= "Recomendações:\n";
        $resposta .= "- Realizar inspeção periódica\n";
        $resposta .= "- Manter manutenção preventiva\n";
        $resposta .= "- Seguir normas técnicas do fabricante\n";
        
        return $resposta;
    }
    
    /**
     * Método: adicionar_foto()
     * Responsabilidade: Adicionar foto ao relatório
     * Parâmetros:
     *   $id - int com ID do relatório
     *   $arquivo - array $_FILES com foto
     * Retorna: string (caminho da foto) ou false
     * 
     * Uso:
     *   $foto = $relatorio_obj->adicionar_foto(123, $_FILES['foto']);
     */
    public function adicionar_foto($id, $arquivo) {
        if (empty($id) || empty($arquivo)) {
            return false;
        }
        
        require_once __DIR__ . '/Upload.php';
        $upload = new Upload();
        $upload->definir_diretorio(DIR_UPLOADS . '/relatorios');
        $upload->definir_tipos_permitidos(ALLOWED_IMAGE_TYPES);
        
        $resultado = $upload->enviar($arquivo);
        
        if (!$resultado) {
            return false;
        }
        
        // Atualiza no banco
        $this->db->update($this->tabela, 
            ['foto_url' => $resultado],
            ['id' => $id]
        );
        
        return $resultado;
    }
    
    /**
     * Método: atualizar_status()
     * Responsabilidade: Atualizar status do relatório
     * Parâmetros:
     *   $id - int
     *   $status - string ('rascunho', 'enviado', 'visualizado')
     * Retorna: bool
     * 
     * Uso:
     *   $relatorio_obj->atualizar_status(123, 'enviado');
     */
    public function atualizar_status($id, $status) {
        if (empty($id)) {
            return false;
        }
        
        $status_validos = ['rascunho', 'enviado', 'visualizado'];
        
        if (!in_array($status, $status_validos)) {
            return false;
        }
        
        return $this->db->update($this->tabela, 
            ['status' => $status],
            ['id' => $id]
        );
    }
    
    /**
     * Método: contar()
     * Responsabilidade: Contar total de relatórios
     * Parâmetros: $filtro (array)
     * Retorna: int
     */
    public function contar($filtro = []) {
        $where = [];
        
        if (!empty($filtro['cliente_id'])) {
            $where['cliente_id'] = $filtro['cliente_id'];
        }
        
        if (!empty($filtro['tipo'])) {
            $where['tipo'] = $filtro['tipo'];
        }
        
        return $this->db->count($this->tabela, $where);
    }
    
    /**
     * Método PRIVADO: gerar_numero_relatorio()
     * Responsabilidade: Gerar número único para relatório
     * Parâmetros: none
     * Retorna: string (formato: REL-DATA-SEQUENCIAL)
     */
    private function gerar_numero_relatorio() {
        $data = date('Ymd');
        $sequencial = $this->db->count($this->tabela, []) + 1;
        
        return sprintf('REL-%s-%04d', $data, $sequencial);
    }
}

?>
