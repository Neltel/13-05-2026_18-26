<?php
/**
 * =====================================================================
 * CLASSE PREVENTIVA - Gerenciar Preventivas/PMP (Plano de Manutenção Programada)
 * =====================================================================
 * 
 * Responsabilidade: CRUD de contratos de manutenção preventiva
 * Uso: $preventiva = new Preventiva(); $preventiva->criar(...);
 * Recebe: Dados de cliente, equipamentos, frequência de manutenção
 * Retorna: Arrays com planos e checklists
 * 
 * Operações:
 * - Criar novo plano de manutenção
 * - Adicionar equipamentos ao plano
 * - Gerar checklist automático com IA
 * - Registrar execução de manutenção
 * - Enviar notificações de próxima manutenção
 * - Listar planos ativos/inativos
 */

require_once __DIR__ . '/Database.php';

class Preventiva {
    
    private $db;
    private $tabela = 'preventivas';
    private $tabela_equipamentos = 'pmp_equipamentos';
    private $tabela_checklist = 'pmp_checklist';
    
    /**
     * Construtor
     */
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Método: criar()
     * Responsabilidade: Criar novo plano de manutenção preventiva
     * Parâmetros:
     *   $cliente_id - int com ID do cliente
     *   $dados - array com dados do plano
     * Retorna: int (ID do novo plano) ou false
     * 
     * Campos em $dados:
     *   - tipo: 'preventiva' ou 'corretiva' (padrão: preventiva)
     *   - frequencia: 'semanal', 'quinzenal', 'mensal', 'trimestral', 'semestral', 'anual'
     *   - notificar_whatsapp: boolean (padrão: true)
     *   - notificacao_1_dia: boolean para notificar 1 dia antes (padrão: true)
     *   - notificacao_1_hora: boolean para notificar 1 hora antes (padrão: true)
     * 
     * Exemplo:
     *   $preventiva_id = $preventiva->criar(123, [
     *       'tipo' => 'preventiva',
     *       'frequencia' => 'mensal',
     *       'notificar_whatsapp' => true
     *   ]);
     */
    public function criar($cliente_id, $dados = []) {
        if (empty($cliente_id)) {
            return false;
        }
        
        // Gera número único
        $numero = $this->gerar_numero_preventiva();
        
        // Define frequência (padrão: mensal)
        $frequencia = $dados['frequencia'] ?? FREQ_MENSAL;
        
        // Calcula próxima data de manutenção
        $proxima_data = $this->calcular_proxima_data($frequencia);
        
        // Prepara dados
        $dados_insert = [
            'numero' => $numero,
            'cliente_id' => $cliente_id,
            'tipo' => $dados['tipo'] ?? 'preventiva',
            'frequencia' => $frequencia,
            'notificar_whatsapp' => $dados['notificar_whatsapp'] ?? true,
            'status' => PREVENTIVA_ATIVA,
            'proxima_data' => $proxima_data,
            'notificacao_1_dia' => $dados['notificacao_1_dia'] ?? true,
            'notificacao_1_hora' => $dados['notificacao_1_hora'] ?? true
        ];
        
        // Insere no banco
        $id = $this->db->insert($this->tabela, $dados_insert);
        
        return $id;
    }
    
    /**
     * Método: obter()
     * Responsabilidade: Obter dados completos de um plano
     * Parâmetros: $id (int)
     * Retorna: array com dados do plano ou false
     * 
     * Nota: Inclui equipamentos e checklist
     * 
     * Uso:
     *   $plano = $preventiva_obj->obter(123);
     */
    public function obter($id) {
        if (empty($id)) {
            return false;
        }
        
        $plano = $this->db->selectOne($this->tabela, ['id' => $id]);
        
        if (!$plano) {
            return false;
        }
        
        // Adiciona equipamentos
        $plano['equipamentos'] = $this->obter_equipamentos($id);
        
        return $plano;
    }
    
    /**
     * Método: listar()
     * Responsabilidade: Listar planos com filtros
     * Parâmetros:
     *   $filtro - array com filtros
     *   $limite - int com registros por página
     *   $pagina - int com número da página
     * Retorna: array com planos
     * 
     * Filtros:
     *   - cliente_id: filtrar por cliente
     *   - status: 'ativo', 'inativo', 'pausado'
     *   - tipo: 'preventiva', 'corretiva'
     */
    public function listar($filtro = [], $limite = REGISTROS_POR_PAGINA, $pagina = 1) {
        $where = [];
        
        if (!empty($filtro['cliente_id'])) {
            $where['cliente_id'] = $filtro['cliente_id'];
        }
        
        if (!empty($filtro['status'])) {
            $where['status'] = $filtro['status'];
        }
        
        if (!empty($filtro['tipo'])) {
            $where['tipo'] = $filtro['tipo'];
        }
        
        $offset = ($pagina - 1) * $limite;
        
        return $this->db->select($this->tabela, $where, 'proxima_data ASC', $limite, $offset);
    }
    
    /**
     * Método: obter_ativos()
     * Responsabilidade: Obter todos os planos ativos
     * Parâmetros: none
     * Retorna: array com planos ativos
     * 
     * Uso:
     *   $ativos = $preventiva_obj->obter_ativos();
     */
    public function obter_ativos() {
        return $this->db->select($this->tabela, 
            ['status' => PREVENTIVA_ATIVA],
            'proxima_data ASC'
        );
    }
    
    /**
     * Método: obter_proximas_manutencoes()
     * Responsabilidade: Obter planos com manutenção próxima (próximos 3 dias)
     * Parâmetros: none
     * Retorna: array com planos
     * 
     * Uso:
     *   $proximas = $preventiva_obj->obter_proximas_manutencoes();
     */
    public function obter_proximas_manutencoes() {
        $sql = "SELECT * FROM {$this->tabela} 
                WHERE status = 'ativo' 
                AND proxima_data <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
                AND proxima_data >= CURDATE()
                ORDER BY proxima_data ASC";
        
        $resultado = $this->db->getConnection()->query($sql);
        $planos = [];
        
        while ($linha = $resultado->fetch_assoc()) {
            $planos[] = $linha;
        }
        
        return $planos;
    }
    
    /**
     * Método: adicionar_equipamento()
     * Responsabilidade: Adicionar equipamento ao plano de manutenção
     * Parâmetros:
     *   $preventiva_id - int
     *   $dados - array com dados do equipamento
     * Retorna: int (ID do equipamento) ou false
     * 
     * Campos em $dados:
     *   - marca: marca do equipamento
     *   - modelo: modelo do equipamento
     *   - potencia: potência em BTU (para ACs)
     *   - gas_refrigerante: tipo de gás
     *   - carga_gas: quantidade de gás em gramas
     *   - ambiente: 'limpo', 'pouco_sujo', 'medio_sujo', 'muito_sujo'
     * 
     * Exemplo:
     *   $eq_id = $preventiva_obj->adicionar_equipamento(123, [
     *       'marca' => 'LG',
     *       'modelo' => 'Dual Inverter',
     *       'potencia' => '12000 BTU',
     *       'gas_refrigerante' => 'R410a',
     *       'carga_gas' => 1150,
     *       'ambiente' => 'pouco_sujo'
     *   ]);
     */
    public function adicionar_equipamento($preventiva_id, $dados = []) {
        if (empty($preventiva_id)) {
            return false;
        }
        
        // Prepara dados
        $dados_insert = [
            'preventiva_id' => $preventiva_id,
            'marca' => $dados['marca'] ?? null,
            'modelo' => $dados['modelo'] ?? null,
            'potencia' => $dados['potencia'] ?? null,
            'gas_refrigerante' => $dados['gas_refrigerante'] ?? null,
            'carga_gas' => $dados['carga_gas'] ?? null,
            'ambiente' => $dados['ambiente'] ?? 'limpo'
        ];
        
        // Insere equipamento
        $eq_id = $this->db->insert($this->tabela_equipamentos, $dados_insert);
        
        if ($eq_id) {
            // Gera checklist automaticamente
            $this->gerar_checklist($eq_id, $dados);
        }
        
        return $eq_id;
    }
    
    /**
     * Método: obter_equipamentos()
     * Responsabilidade: Obter todos os equipamentos de um plano
     * Parâmetros: $preventiva_id (int)
     * Retorna: array com equipamentos
     * 
     * Uso:
     *   $equipamentos = $preventiva_obj->obter_equipamentos(123);
     */
    public function obter_equipamentos($preventiva_id) {
        if (empty($preventiva_id)) {
            return [];
        }
        
        return $this->db->select($this->tabela_equipamentos, 
            ['preventiva_id' => $preventiva_id],
            'id ASC'
        );
    }
    
    /**
     * Método PRIVADO: gerar_checklist()
     * Responsabilidade: Gerar checklist automaticamente para um equipamento
     * Parâmetros:
     *   $equipamento_id - int
     *   $dados_equipamento - array com dados do equipamento
     * Retorna: bool (sucesso ou falha)
     * 
     * Nota: Cria itens de checklist padrão baseado no equipamento
     * Em um sistema real, utilizaria IA (GPT, Gemini) para gerar dinamicamente
     * 
     * Uso interno apenas
     */
    private function gerar_checklist($equipamento_id, $dados_equipamento = []) {
        if (empty($equipamento_id)) {
            return false;
        }
        
        // Checklist padrão para ar condicionado
        $items_checklist = [
            'Verificar pressão de entrada',
            'Verificar pressão de saída',
            'Medir tensão do capacitor',
            'Inspecionar filtro de ar',
            'Verificar limpeza da evaporadora',
            'Verificar limpeza da condensadora',
            'Testar funcionamento do compressor',
            'Verificar isolamento elétrico',
            'Testar refrigeração',
            'Verificar vazamentos de gás',
            'Limpar filtro do compressor',
            'Verificar conexões elétricas',
            'Testar sensor de temperatura',
            'Inspecionar tubulações',
            'Verificar contato elétrico'
        ];
        
        // Insere cada item
        foreach ($items_checklist as $item) {
            $this->db->insert($this->tabela_checklist, [
                'pmp_equipamento_id' => $equipamento_id,
                'item' => $item,
                'resultado' => 'pendente'
            ]);
        }
        
        return true;
    }
    
    /**
     * Método: obter_checklist()
     * Responsabilidade: Obter checklist de um equipamento
     * Parâmetros: $equipamento_id (int)
     * Retorna: array com itens do checklist
     * 
     * Uso:
     *   $checklist = $preventiva_obj->obter_checklist(10);
     */
    public function obter_checklist($equipamento_id) {
        if (empty($equipamento_id)) {
            return [];
        }
        
        return $this->db->select($this->tabela_checklist, 
            ['pmp_equipamento_id' => $equipamento_id],
            'id ASC'
        );
    }
    
    /**
     * Método: registrar_manutencao()
     * Responsabilidade: Registrar execução de manutenção
     * Parâmetros:
     *   $preventiva_id - int
     *   $dados - array com dados da manutenção
     * Retorna: bool (sucesso ou falha)
     * 
     * Campos em $dados:
     *   - data_manutencao: data da execução
     *   - checklist_items: array com status de cada item
     *       ex: [1 => 'ok', 2 => 'problema', 3 => 'ok', ...]
     * 
     * Exemplo:
     *   $preventiva_obj->registrar_manutencao(123, [
     *       'data_manutencao' => '2026-02-14',
     *       'checklist_items' => [1 => 'ok', 2 => 'ok', 3 => 'problema']
     *   ]);
     */
    public function registrar_manutencao($preventiva_id, $dados = []) {
        if (empty($preventiva_id)) {
            return false;
        }
        
        // Busca plano
        $plano = $this->obter($preventiva_id);
        if (!$plano) {
            return false;
        }
        
        // Registra data da última manutenção
        $data_manutencao = $dados['data_manutencao'] ?? date('Y-m-d');
        
        // Atualiza checklist com resultados
        if (!empty($dados['checklist_items'])) {
            foreach ($dados['checklist_items'] as $item_id => $resultado) {
                $this->db->update($this->tabela_checklist,
                    ['resultado' => $resultado, 'data_manutencao' => $data_manutencao],
                    ['id' => $item_id]
                );
            }
        }
        
        // Calcula próxima data
        $proxima_data = $this->calcular_proxima_data($plano['frequencia'], $data_manutencao);
        
        // Atualiza plano
        return $this->db->update($this->tabela, 
            ['ultima_manutencao' => $data_manutencao, 'proxima_data' => $proxima_data],
            ['id' => $preventiva_id]
        );
    }
    
    /**
     * Método: alterar_status()
     * Responsabilidade: Alterar status do plano
     * Parâmetros:
     *   $id - int
     *   $status - string ('ativo', 'inativo', 'pausado')
     * Retorna: bool
     * 
     * Uso:
     *   $preventiva_obj->alterar_status(123, PREVENTIVA_ATIVA);
     */
    public function alterar_status($id, $status) {
        if (empty($id)) {
            return false;
        }
        
        // Valida status
        $status_validos = [PREVENTIVA_ATIVA, PREVENTIVA_INATIVA, PREVENTIVA_PAUSADA];
        
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
     * Responsabilidade: Contar total de planos
     * Parâmetros: $filtro (array)
     * Retorna: int
     */
    public function contar($filtro = []) {
        $where = [];
        
        if (!empty($filtro['cliente_id'])) {
            $where['cliente_id'] = $filtro['cliente_id'];
        }
        
        if (!empty($filtro['status'])) {
            $where['status'] = $filtro['status'];
        }
        
        return $this->db->count($this->tabela, $where);
    }
    
    /**
     * Método PRIVADO: calcular_proxima_data()
     * Responsabilidade: Calcular próxima data de manutenção
     * Parâmetros:
     *   $frequencia - string ('semanal', 'quinzenal', 'mensal', etc)
     *   $data_referencia - string (data base para cálculo, padrão: hoje)
     * Retorna: string no formato 'Y-m-d'
     */
    private function calcular_proxima_data($frequencia, $data_referencia = null) {
        if ($data_referencia === null) {
            $data_referencia = date('Y-m-d');
        }
        
        $proxima_data = new DateTime($data_referencia);
        
        switch ($frequencia) {
            case FREQ_SEMANAL:
                $proxima_data->modify('+7 days');
                break;
            case FREQ_QUINZENAL:
                $proxima_data->modify('+15 days');
                break;
            case FREQ_MENSAL:
                $proxima_data->modify('+1 month');
                break;
            case FREQ_TRIMESTRAL:
                $proxima_data->modify('+3 months');
                break;
            case FREQ_SEMESTRAL:
                $proxima_data->modify('+6 months');
                break;
            case FREQ_ANUAL:
                $proxima_data->modify('+1 year');
                break;
            default:
                $proxima_data->modify('+1 month');
        }
        
        return $proxima_data->format('Y-m-d');
    }
    
    /**
     * Método PRIVADO: gerar_numero_preventiva()
     * Responsabilidade: Gerar número único para preventiva
     * Parâmetros: none
     * Retorna: string (formato: PMP-DATA-SEQUENCIAL)
     */
    private function gerar_numero_preventiva() {
        $data = date('Ymd');
        $sequencial = $this->db->count($this->tabela, []) + 1;
        
        return sprintf('PMP-%s-%04d', $data, $sequencial);
    }
}

?>
