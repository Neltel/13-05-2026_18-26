<?php
/**
 * =====================================================
 * CLASSE COBRANCA - Gerenciar Cobranças
 * =====================================================
 * 
 * Responsabilidade: CRUD de cobranças, controle de recebimentos
 * Uso: $cobranca = new Cobranca(); $cobranca->criar(...);
 * Recebe: Dados de cliente, valores, datas
 * Retorna: Arrays com cobranças e status de pagamento
 * 
 * Operações:
 * - Criar nova cobrança
 * - Registrar pagamento
 * - Listar cobranças por status
 * - Calcular vencidos
 * - Gerar relatório de cobranças
 * - Enviar notificações de vencimento
 */

require_once __DIR__ . '/Database.php';

class Cobranca {
    
    private $db;
    private $tabela = 'cobrancas';
    
    /**
     * Construtor
     */
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Método: criar()
     * Responsabilidade: Criar nova cobrança
     * Parâmetros:
     *   $cliente_id - int com ID do cliente
     *   $valor - float com valor a cobrar
     *   $dados - array com dados adicionais
     * Retorna: int (ID da nova cobrança) ou false
     * 
     * Campos em $dados:
     *   - data_vencimento: data de vencimento (padrão: hoje + 30 dias)
     *   - pedido_id, orcamento_id, venda_id: referência a documento
     *   - tipo_pagamento: 'dinheiro', 'debito', 'credito', 'pix', 'cheque'
     * 
     * Exemplo:
     *   $cobranca_id = $cobranca->criar(123, 500, [
     *       'data_vencimento' => '2026-03-15',
     *       'venda_id' => 10,
     *       'tipo_pagamento' => 'pix'
     *   ]);
     */
    public function criar($cliente_id, $valor, $dados = []) {
        if (empty($cliente_id) || $valor <= 0) {
            return false;
        }
        
        // Gera número único da cobrança
        $numero = $this->gerar_numero_cobranca();
        
        // Define data de vencimento
        $data_vencimento = $dados['data_vencimento'] ?? date('Y-m-d', strtotime('+30 days'));
        
        // Prepara dados
        $dados_insert = [
            'numero' => $numero,
            'cliente_id' => $cliente_id,
            'valor' => $valor,
            'data_vencimento' => $data_vencimento,
            'status' => COBRANCA_PENDENTE,
            'tipo_pagamento' => $dados['tipo_pagamento'] ?? PAGAMENTO_PIX
        ];
        
        // Referências opcionais
        if (!empty($dados['pedido_id'])) {
            $dados_insert['pedido_id'] = $dados['pedido_id'];
        }
        if (!empty($dados['orcamento_id'])) {
            $dados_insert['orcamento_id'] = $dados['orcamento_id'];
        }
        if (!empty($dados['venda_id'])) {
            $dados_insert['venda_id'] = $dados['venda_id'];
        }
        
        // Insere no banco
        $id = $this->db->insert($this->tabela, $dados_insert);
        
        return $id;
    }
    
    /**
     * Método: obter()
     * Responsabilidade: Obter dados completos de uma cobrança
     * Parâmetros: $id (int)
     * Retorna: array com dados da cobrança ou false
     * 
     * Uso:
     *   $cobranca = $cobranca_obj->obter(123);
     */
    public function obter($id) {
        if (empty($id)) {
            return false;
        }
        
        return $this->db->selectOne($this->tabela, ['id' => $id]);
    }
    
    /**
     * Método: listar()
     * Responsabilidade: Listar cobranças com filtros
     * Parâmetros:
     *   $filtro - array com filtros
     *   $limite - int com registros por página
     *   $pagina - int com número da página
     * Retorna: array com cobranças
     * 
     * Filtros:
     *   - cliente_id: filtrar por cliente
     *   - status: 'pendente', 'vencida', 'a_receber', 'recebida'
     */
    public function listar($filtro = [], $limite = REGISTROS_POR_PAGINA, $pagina = 1) {
        $where = [];
        
        if (!empty($filtro['cliente_id'])) {
            $where['cliente_id'] = $filtro['cliente_id'];
        }
        
        if (!empty($filtro['status'])) {
            $where['status'] = $filtro['status'];
        }
        
        $offset = ($pagina - 1) * $limite;
        
        return $this->db->select($this->tabela, $where, 'data_vencimento ASC', $limite, $offset);
    }
    
    /**
     * Método: obter_pendentes()
     * Responsabilidade: Obter todas as cobranças pendentes
     * Parâmetros: none
     * Retorna: array com cobranças pendentes
     * 
     * Nota: Automaticamente atualiza status 'pendente' para 'vencida' se passou data
     * 
     * Uso:
     *   $pendentes = $cobranca_obj->obter_pendentes();
     */
    public function obter_pendentes() {
        // Busca cobranças pendentes
        $cobracas = $this->db->select($this->tabela, 
            ['status' => COBRANCA_PENDENTE],
            'data_vencimento ASC'
        );
        
        // Verifica e atualiza vencidas
        foreach ($cobracas as $cobranca) {
            if (strtotime($cobranca['data_vencimento']) < strtotime('today')) {
                $this->db->update($this->tabela, 
                    ['status' => COBRANCA_VENCIDA],
                    ['id' => $cobranca['id']]
                );
            }
        }
        
        // Retorna novamente após atualização
        return $this->db->select($this->tabela, 
            ['status' => COBRANCA_PENDENTE],
            'data_vencimento ASC'
        );
    }
    
    /**
     * Método: obter_vencidas()
     * Responsabilidade: Obter cobranças vencidas
     * Parâmetros: none
     * Retorna: array com cobranças vencidas
     * 
     * Uso:
     *   $vencidas = $cobranca_obj->obter_vencidas();
     */
    public function obter_vencidas() {
        return $this->db->select($this->tabela, 
            ['status' => COBRANCA_VENCIDA],
            'data_vencimento ASC'
        );
    }
    
    /**
     * Método: obter_a_receber()
     * Responsabilidade: Obter cobranças confirmadas a receber
     * Parâmetros: none
     * Retorna: array
     * 
     * Uso:
     *   $a_receber = $cobranca_obj->obter_a_receber();
     */
    public function obter_a_receber() {
        return $this->db->select($this->tabela, 
            ['status' => COBRANCA_A_RECEBER],
            'data_vencimento ASC'
        );
    }
    
    /**
     * Método: obter_recebidas()
     * Responsabilidade: Obter cobranças já recebidas
     * Parâmetros:
     *   $mes - int (opcional, mês específico)
     *   $ano - int (opcional, ano específico)
     * Retorna: array com cobranças recebidas
     * 
     * Uso:
     *   $recebidas = $cobranca_obj->obter_recebidas(2, 2026); // Fevereiro 2026
     */
    public function obter_recebidas($mes = null, $ano = null) {
        $cobracas = $this->db->select($this->tabela, 
            ['status' => COBRANCA_RECEBIDA],
            'data_recebimento DESC'
        );
        
        // Filtra por mês/ano se informado
        if (!empty($mes) && !empty($ano)) {
            $cobracas = array_filter($cobracas, function($cobranca) use ($mes, $ano) {
                if (empty($cobranca['data_recebimento'])) {
                    return false;
                }
                
                $data = new DateTime($cobranca['data_recebimento']);
                return $data->format('m') == $mes && $data->format('Y') == $ano;
            });
        }
        
        return $cobracas;
    }
    
    /**
     * Método: registrar_pagamento()
     * Responsabilidade: Registrar pagamento de uma cobrança
     * Parâmetros:
     *   $id - int com ID da cobrança
     *   $dados - array com dados do pagamento
     * Retorna: bool (sucesso ou falha)
     * 
     * Campos em $dados:
     *   - data_recebimento: data que recebeu (padrão: hoje)
     *   - tipo_pagamento: forma de pagamento
     *   - observacao: observações
     * 
     * Exemplo:
     *   $cobranca_obj->registrar_pagamento(123, [
     *       'data_recebimento' => '2026-02-14',
     *       'tipo_pagamento' => 'pix'
     *   ]);
     */
    public function registrar_pagamento($id, $dados = []) {
        if (empty($id)) {
            return false;
        }
        
        // Busca cobrança
        $cobranca = $this->obter($id);
        if (!$cobranca) {
            return false;
        }
        
        // Prepara dados de atualização
        $dados_update = [
            'status' => COBRANCA_RECEBIDA,
            'data_recebimento' => $dados['data_recebimento'] ?? date('Y-m-d')
        ];
        
        if (!empty($dados['tipo_pagamento'])) {
            $dados_update['tipo_pagamento'] = $dados['tipo_pagamento'];
        }
        
        // Atualiza
        return $this->db->update($this->tabela, $dados_update, ['id' => $id]);
    }
    
    /**
     * Método: cancelar()
     * Responsabilidade: Cancelar uma cobrança
     * Parâmetros: $id (int)
     * Retorna: bool
     * 
     * Nota: Apenas cobranças pendentes podem ser canceladas
     */
    public function cancelar($id) {
        if (empty($id)) {
            return false;
        }
        
        // Busca cobrança
        $cobranca = $this->obter($id);
        if (!$cobranca) {
            return false;
        }
        
        // Apenas pendentes podem ser canceladas
        if ($cobranca['status'] == COBRANCA_RECEBIDA) {
            return false;
        }
        
        // Deleta a cobrança
        return $this->db->delete($this->tabela, ['id' => $id]);
    }
    
    /**
     * Método: calcular_total_pendente()
     * Responsabilidade: Calcular total de cobranças pendentes
     * Parâmetros: none
     * Retorna: float com total
     * 
     * Uso:
     *   $total = $cobranca_obj->calcular_total_pendente();
     */
    public function calcular_total_pendente() {
        $pendentes = $this->obter_pendentes();
        
        $total = 0;
        foreach ($pendentes as $cobranca) {
            $total += $cobranca['valor'];
        }
        
        return round($total, 2);
    }
    
    /**
     * Método: calcular_total_vencida()
     * Responsabilidade: Calcular total de cobranças vencidas
     * Parâmetros: none
     * Retorna: float com total
     * 
     * Uso:
     *   $total = $cobranca_obj->calcular_total_vencida();
     */
    public function calcular_total_vencida() {
        $vencidas = $this->obter_vencidas();
        
        $total = 0;
        foreach ($vencidas as $cobranca) {
            $total += $cobranca['valor'];
        }
        
        return round($total, 2);
    }
    
    /**
     * Método: calcular_total_recebida()
     * Responsabilidade: Calcular total de cobranças recebidas
     * Parâmetros:
     *   $mes - int (opcional)
     *   $ano - int (opcional)
     * Retorna: float com total
     * 
     * Uso:
     *   $total = $cobranca_obj->calcular_total_recebida(2, 2026);
     */
    public function calcular_total_recebida($mes = null, $ano = null) {
        $recebidas = $this->obter_recebidas($mes, $ano);
        
        $total = 0;
        foreach ($recebidas as $cobranca) {
            $total += $cobranca['valor'];
        }
        
        return round($total, 2);
    }
    
    /**
     * Método: contar()
     * Responsabilidade: Contar cobranças por status
     * Parâmetros: $status (string)
     * Retorna: int
     * 
     * Uso:
     *   $pendentes = $cobranca_obj->contar(COBRANCA_PENDENTE);
     */
    public function contar($status = null) {
        $where = [];
        
        if (!empty($status)) {
            $where['status'] = $status;
        }
        
        return $this->db->count($this->tabela, $where);
    }
    
    /**
     * Método: obter_por_numero()
     * Responsabilidade: Buscar cobrança pelo número
     * Parâmetros: $numero (string)
     * Retorna: array ou false
     * 
     * Uso:
     *   $cobranca = $cobranca_obj->obter_por_numero('COB-20260214-0001');
     */
    public function obter_por_numero($numero) {
        if (empty($numero)) {
            return false;
        }
        
        return $this->db->selectOne($this->tabela, ['numero' => $numero]);
    }
    
    /**
     * Método PRIVADO: gerar_numero_cobranca()
     * Responsabilidade: Gerar número único para cobrança
     * Parâmetros: none
     * Retorna: string (formato: COB-DATA-SEQUENCIAL)
     */
    private function gerar_numero_cobranca() {
        $data = date('Ymd');
        $sequencial = $this->db->count($this->tabela, []) + 1;
        
        return sprintf('COB-%s-%04d', $data, $sequencial);
    }
}

?>
