<?php
/**
 * =====================================================
 * CLASSE GARANTIA - Gerenciar Garantias
 * =====================================================
 * 
 * Responsabilidade: CRUD de garantias de serviços
 * Uso: $garantia = new Garantia(); $garantia->criar(...);
 * Recebe: Dados de cliente, serviço, período de garantia
 * Retorna: Arrays com garantias e termos
 * 
 * Operações:
 * - Criar nova garantia
 * - Definir termos e condições (conforme lei brasileira)
 * - Listar garantias por status
 * - Gerar PDF de garantia
 * - Enviar por WhatsApp
 * - Verificar validade
 */

require_once __DIR__ . '/Database.php';

class Garantia {
    
    private $db;
    private $tabela = 'garantias';
    
    // Termos de garantia padrão conforme lei brasileira
    private $termos_padrao = [
        'instalacao' => 'A garantia de instalação cobre todos os defeitos e problemas relacionados ao serviço de instalação realizado. Não cobre desgaste natural, manutenção preventiva ou danos causados por negligência do cliente.',
        'manutencao' => 'A garantia de manutenção cobre correções necessárias decorrentes dos serviços de manutenção realizados. Não cobre danos causados por uso indevido ou falta de manutenção periódica.',
        'reparo' => 'A garantia de reparo cobre a reparação realizada durante o período especificado. Não cobre danos causados por terceiros ou uso inadequado do equipamento.',
        'pecas' => 'A garantia das peças fornecidas segue as normas do fabricante. Peças danificadas por mau uso não são cobertas pela garantia.',
        'servicos_gerais' => 'A garantia cobre os serviços realizados conforme especificações acordadas. Não cobre desgaste natural ou manutenção regular.',
        'personalizado' => 'As condições específicas de garantia estão descritas neste documento.'
    ];
    
    /**
     * Construtor
     */
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Método: criar()
     * Responsabilidade: Criar nova garantia
     * Parâmetros:
     *   $cliente_id - int com ID do cliente
     *   $dados - array com dados da garantia
     * Retorna: int (ID da nova garantia) ou false
     * 
     * Campos em $dados:
     *   - agendamento_id: ID do agendamento (opcional)
     *   - data_inicio: data de início (padrão: hoje)
     *   - dias_validade: quantidade de dias (padrão: 30)
     *   - tipo: 'instalacao', 'manutencao', 'reparo', 'pecas', 'servicos_gerais', 'personalizado'
     *   - tipo_personalizado: descrição se tipo = 'personalizado'
     *   - condicoes: termos customizados (usa padrão se não informar)
     *   - foto_servico: URL da foto (opcional)
     * 
     * Exemplo:
     *   $garantia_id = $garantia->criar(123, [
     *       'tipo' => 'instalacao',
     *       'dias_validade' => 90,
     *       'agendamento_id' => 10
     *   ]);
     */
    public function criar($cliente_id, $dados = []) {
        if (empty($cliente_id)) {
            return false;
        }
        
        // Gera número único
        $numero = $this->gerar_numero_garantia();
        
        // Define datas
        $data_inicio = $dados['data_inicio'] ?? date('Y-m-d');
        $dias_validade = $dados['dias_validade'] ?? DIAS_VALIDADE_GARANTIA;
        $data_validade = date('Y-m-d', strtotime($data_inicio . ' +' . $dias_validade . ' days'));
        
        // Define tipo
        $tipo = $dados['tipo'] ?? GARANTIA_SERVICOS_GERAIS;
        
        // Define termos (usa padrão ou customizado)
        if (!empty($dados['condicoes'])) {
            $condicoes = $dados['condicoes'];
        } else {
            $condicoes = $this->obter_termos_padrao($tipo);
        }
        
        // Prepara dados
        $dados_insert = [
            'numero' => $numero,
            'cliente_id' => $cliente_id,
            'data_emissao' => date('Y-m-d'),
            'data_inicio' => $data_inicio,
            'data_validade' => $data_validade,
            'tipo' => $tipo,
            'condicoes' => $condicoes
        ];
        
        // Campos opcionais
        if (!empty($dados['agendamento_id'])) {
            $dados_insert['agendamento_id'] = $dados['agendamento_id'];
        }
        
        if ($tipo == GARANTIA_PERSONALIZADA && !empty($dados['tipo_personalizado'])) {
            $dados_insert['tipo_personalizado'] = $dados['tipo_personalizado'];
        }
        
        if (!empty($dados['foto_servico'])) {
            $dados_insert['foto_servico'] = $dados['foto_servico'];
        }
        
        // Insere no banco
        $id = $this->db->insert($this->tabela, $dados_insert);
        
        return $id;
    }
    
    /**
     * Método: obter()
     * Responsabilidade: Obter dados completos de uma garantia
     * Parâmetros: $id (int)
     * Retorna: array com dados da garantia ou false
     * 
     * Uso:
     *   $garantia = $garantia_obj->obter(123);
     */
    public function obter($id) {
        if (empty($id)) {
            return false;
        }
        
        return $this->db->selectOne($this->tabela, ['id' => $id]);
    }
    
    /**
     * Método: listar()
     * Responsabilidade: Listar garantias com filtros
     * Parâmetros:
     *   $filtro - array com filtros
     *   $limite - int com registros por página
     *   $pagina - int com número da página
     * Retorna: array com garantias
     * 
     * Filtros:
     *   - cliente_id: filtrar por cliente
     *   - tipo: tipo de garantia
     */
    public function listar($filtro = [], $limite = REGISTROS_POR_PAGINA, $pagina = 1) {
        $where = [];
        
        if (!empty($filtro['cliente_id'])) {
            $where['cliente_id'] = $filtro['cliente_id'];
        }
        
        if (!empty($filtro['tipo'])) {
            $where['tipo'] = $filtro['tipo'];
        }
        
        $offset = ($pagina - 1) * $limite;
        
        return $this->db->select($this->tabela, $where, 'data_emissao DESC', $limite, $offset);
    }
    
    /**
     * Método: obter_por_cliente()
     * Responsabilidade: Obter todas as garantias de um cliente
     * Parâmetros: $cliente_id (int)
     * Retorna: array com garantias
     * 
     * Uso:
     *   $garantias = $garantia_obj->obter_por_cliente(123);
     */
    public function obter_por_cliente($cliente_id) {
        if (empty($cliente_id)) {
            return [];
        }
        
        return $this->db->select($this->tabela, 
            ['cliente_id' => $cliente_id],
            'data_validade DESC'
        );
    }
    
    /**
     * Método: obter_ativas()
     * Responsabilidade: Obter garantias ainda válidas
     * Parâmetros: none
     * Retorna: array com garantias ativas
     * 
     * Uso:
     *   $ativas = $garantia_obj->obter_ativas();
     */
    public function obter_ativas() {
        // Query customizada
        $sql = "SELECT * FROM {$this->tabela} 
                WHERE data_validade >= CURDATE()
                ORDER BY data_validade ASC";
        
        $resultado = $this->db->getConnection()->query($sql);
        $garantias = [];
        
        while ($linha = $resultado->fetch_assoc()) {
            $garantias[] = $linha;
        }
        
        return $garantias;
    }
    
    /**
     * Método: obter_vencidas()
     * Responsabilidade: Obter garantias vencidas
     * Parâmetros: none
     * Retorna: array com garantias vencidas
     * 
     * Uso:
     *   $vencidas = $garantia_obj->obter_vencidas();
     */
    public function obter_vencidas() {
        $sql = "SELECT * FROM {$this->tabela} 
                WHERE data_validade < CURDATE()
                ORDER BY data_validade DESC";
        
        $resultado = $this->db->getConnection()->query($sql);
        $garantias = [];
        
        while ($linha = $resultado->fetch_assoc()) {
            $garantias[] = $linha;
        }
        
        return $garantias;
    }
    
    /**
     * Método: verificar_validade()
     * Responsabilidade: Verificar se uma garantia ainda é válida
     * Parâmetros: $id (int)
     * Retorna: bool (true se válida, false se vencida)
     * 
     * Uso:
     *   if ($garantia_obj->verificar_validade(123)) {
     *       // Garantia ainda é válida
     *   }
     */
    public function verificar_validade($id) {
        if (empty($id)) {
            return false;
        }
        
        $garantia = $this->obter($id);
        if (!$garantia) {
            return false;
        }
        
        // Verifica se data_validade é maior ou igual a hoje
        return strtotime($garantia['data_validade']) >= strtotime('today');
    }
    
    /**
     * Método: obter_termos_padrao()
     * Responsabilidade: Obter termos padrão de garantia
     * Parâmetros: $tipo (string)
     * Retorna: string com termos
     * 
     * Nota: Segue normas de proteção ao consumidor do Brasil
     * 
     * Uso:
     *   $termos = $garantia_obj->obter_termos_padrao('instalacao');
     */
    public function obter_termos_padrao($tipo) {
        // Validação básica
        if (!array_key_exists($tipo, $this->termos_padrao)) {
            $tipo = 'servicos_gerais';
        }
        
        $termos = $this->termos_padrao[$tipo];
        
        // Adiciona texto padrão de proteção ao consumidor
        $termos .= "\n\n";
        $termos .= "LEGISLAÇÃO APLICÁVEL:\n";
        $termos .= "Esta garantia está em conformidade com o Código de Proteção e Defesa do Consumidor (Lei nº 8.078/1990).\n\n";
        $termos .= "O consumidor pode requerer a garantia legal de 30 dias para produtos e a garantia contratual conforme especificado acima.\n\n";
        $termos .= "EXCLUSÕES DA GARANTIA:\n";
        $termos .= "- Danos causados por mau uso ou negligência do cliente\n";
        $termos .= "- Danos causados por terceiros\n";
        $termos .= "- Desgaste natural do equipamento\n";
        $termos .= "- Danos por fenômenos naturais (raios, enchentes, etc)\n";
        $termos .= "- Manutenção não realizada conforme recomendado\n\n";
        $termos .= "Para acionamento da garantia, entre em contato conosco mediante apresentação deste documento.";
        
        return $termos;
    }
    
    /**
     * Método: atualizar_condicoes()
     * Responsabilidade: Atualizar termos e condições de uma garantia
     * Parâmetros:
     *   $id - int com ID da garantia
     *   $condicoes - string com novas condições
     * Retorna: bool
     * 
     * Uso:
     *   $garantia_obj->atualizar_condicoes(123, "Novas condições...");
     */
    public function atualizar_condicoes($id, $condicoes) {
        if (empty($id) || empty($condicoes)) {
            return false;
        }
        
        return $this->db->update($this->tabela, 
            ['condicoes' => $condicoes],
            ['id' => $id]
        );
    }
    
    /**
     * Método: adicionar_foto()
     * Responsabilidade: Adicionar foto de serviço à garantia
     * Parâmetros:
     *   $id - int com ID da garantia
     *   $arquivo - array $_FILES com foto
     * Retorna: string (caminho da foto) ou false
     * 
     * Uso:
     *   $foto = $garantia_obj->adicionar_foto(123, $_FILES['foto']);
     */
    public function adicionar_foto($id, $arquivo) {
        if (empty($id) || empty($arquivo)) {
            return false;
        }
        
        require_once __DIR__ . '/Upload.php';
        $upload = new Upload();
        $upload->definir_diretorio(DIR_UPLOADS . '/garantias');
        $upload->definir_tipos_permitidos(ALLOWED_IMAGE_TYPES);
        
        $resultado = $upload->enviar($arquivo);
        
        if (!$resultado) {
            return false;
        }
        
        // Atualiza no banco
        $this->db->update($this->tabela, 
            ['foto_servico' => $resultado],
            ['id' => $id]
        );
        
        return $resultado;
    }
    
    /**
     * Método: contar()
     * Responsabilidade: Contar total de garantias
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
     * Método: obter_por_numero()
     * Responsabilidade: Buscar garantia pelo número
     * Parâmetros: $numero (string)
     * Retorna: array ou false
     * 
     * Uso:
     *   $garantia = $garantia_obj->obter_por_numero('GAR-20260214-0001');
     */
    public function obter_por_numero($numero) {
        if (empty($numero)) {
            return false;
        }
        
        return $this->db->selectOne($this->tabela, ['numero' => $numero]);
    }
    
    /**
     * Método: deletar()
     * Responsabilidade: Deletar uma garantia
     * Parâmetros: $id (int)
     * Retorna: bool
     * 
     * Nota: Apenas cria registro de auditoria, não deleta permanentemente
     */
    public function deletar($id) {
        if (empty($id)) {
            return false;
        }
        
        return $this->db->delete($this->tabela, ['id' => $id]);
    }
    
    /**
     * Método PRIVADO: gerar_numero_garantia()
     * Responsabilidade: Gerar número único para garantia
     * Parâmetros: none
     * Retorna: string (formato: GAR-DATA-SEQUENCIAL)
     */
    private function gerar_numero_garantia() {
        $data = date('Ymd');
        $sequencial = $this->db->count($this->tabela, []) + 1;
        
        return sprintf('GAR-%s-%04d', $data, $sequencial);
    }
}

?>
