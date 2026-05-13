<?php
/**
 * =====================================================
 * CLASSE VENDA - Gerenciar Vendas
 * =====================================================
 * 
 * Responsabilidade: CRUD de vendas (finalização de pedidos)
 * Uso: $venda = new Venda(); $venda->criar(...);
 * Recebe: Dados de cliente, produtos/serviços
 * Retorna: Arrays com vendas e seus itens
 * 
 * Operações:
 * - Criar nova venda
 * - Adicionar produtos/serviços à venda
 * - Calcular totais (com desconto, lucro, custo)
 * - Gerar número único de venda
 * - Listar vendas com filtros
 * - Gerar relatórios de vendas
 */

require_once __DIR__ . '/Database.php';

class Venda {
    
    private $db;
    private $tabela = 'vendas';
    
    /**
     * Construtor
     */
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Método: criar()
     * Responsabilidade: Criar nova venda
     * Parâmetros:
     *   $cliente_id - int com ID do cliente
     *   $dados - array com dados adicionais
     * Retorna: int (ID da nova venda) ou false
     * 
     * Campos em $dados:
     *   - observacao: texto com observações
     *   - data_venda: data da venda (padrão: hoje)
     * 
     * Uso:
     *   $venda_id = $venda->criar(123, ['observacao' => 'Venda à vista']);
     */
    public function criar($cliente_id, $dados = []) {
        if (empty($cliente_id)) {
            return false;
        }
        
        // Gera número único da venda
        $numero = $this->gerar_numero_venda();
        
        // Define data
        $data_venda = $dados['data_venda'] ?? date('Y-m-d');
        
        // Prepara dados
        $dados_insert = [
            'numero' => $numero,
            'cliente_id' => $cliente_id,
            'data_venda' => $data_venda,
            'situacao' => PEDIDO_FINALIZADO, // Vendas já nascem finalizadas
            'observacao' => $dados['observacao'] ?? null,
            'desconto_percentual' => $dados['desconto_percentual'] ?? 0,
            'valor_adicional' => $dados['valor_adicional'] ?? 0,
            'valor_total' => $dados['valor_total'] ?? 0,
            'valor_custo' => $dados['valor_custo'] ?? 0,
            'valor_lucro' => $dados['valor_lucro'] ?? 0
        ];
        
        // Insere no banco
        $id = $this->db->insert($this->tabela, $dados_insert);
        
        return $id;
    }
    
    /**
     * Método: criar_de_pedido()
     * Responsabilidade: Converter um pedido em venda (finalização)
     * Parâmetros:
     *   $pedido_id - int com ID do pedido
     *   $dados - array com dados opcionais
     * Retorna: int (ID da nova venda) ou false
     * 
     * Nota: Copia todos os dados do pedido para a venda
     * 
     * Uso:
     *   $venda_id = $venda->criar_de_pedido(123);
     */
    public function criar_de_pedido($pedido_id, $dados = []) {
        if (empty($pedido_id)) {
            return false;
        }
        
        // Busca pedido
        require_once __DIR__ . '/Pedido.php';
        $pedido_obj = new Pedido();
        $pedido = $pedido_obj->obter($pedido_id);
        
        if (!$pedido) {
            return false;
        }
        
        // Prepara dados da venda
        $dados_venda = [
            'observacao' => $dados['observacao'] ?? $pedido['observacao'],
            'data_venda' => $dados['data_venda'] ?? date('Y-m-d'),
            'desconto_percentual' => $pedido['desconto_percentual'],
            'valor_adicional' => $pedido['valor_adicional'],
            'valor_total' => $pedido['valor_total'],
            'valor_custo' => $pedido['valor_custo'],
            'valor_lucro' => $pedido['valor_lucro']
        ];
        
        // Cria venda
        $venda_id = $this->criar($pedido['cliente_id'], $dados_venda);
        
        if (!$venda_id) {
            return false;
        }
        
        // Altera situação do pedido para finalizado
        $pedido_obj->alterar_situacao($pedido_id, PEDIDO_FINALIZADO);
        
        return $venda_id;
    }
    
    /**
     * Método: obter()
     * Responsabilidade: Obter dados completos de uma venda
     * Parâmetros: $id (int com ID da venda)
     * Retorna: array com dados da venda ou false
     * 
     * Uso:
     *   $venda = $venda_obj->obter(123);
     */
    public function obter($id) {
        if (empty($id)) {
            return false;
        }
        
        return $this->db->selectOne($this->tabela, ['id' => $id]);
    }
    
    /**
     * Método: listar()
     * Responsabilidade: Listar vendas com filtros e paginação
     * Parâmetros:
     *   $filtro - array com filtros
     *   $limite - int com registros por página
     *   $pagina - int com número da página
     * Retorna: array com vendas
     * 
     * Filtros disponíveis:
     *   - cliente_id: filtrar por cliente
     *   - data_inicio: data inicial
     *   - data_fim: data final
     *   - mes: número do mês (1-12)
     *   - ano: ano (ex: 2026)
     */
    public function listar($filtro = [], $limite = REGISTROS_POR_PAGINA, $pagina = 1) {
        $where = [];
        
        if (!empty($filtro['cliente_id'])) {
            $where['cliente_id'] = $filtro['cliente_id'];
        }
        
        // Filtro por mês/ano do mês atual se não informar
        if (empty($filtro['data_inicio']) && empty($filtro['data_fim'])) {
            $mes = $filtro['mes'] ?? date('m');
            $ano = $filtro['ano'] ?? date('Y');
            
            $data_inicio = "{$ano}-{$mes}-01";
            $data_fim = date('Y-m-t', strtotime($data_inicio));
            
            // Filtro customizado (será implementado no select)
        }
        
        $offset = ($pagina - 1) * $limite;
        
        return $this->db->select($this->tabela, $where, 'data_venda DESC', $limite, $offset);
    }
    
    /**
     * Método: obter_por_periodo()
     * Responsabilidade: Obter vendas de um período específico
     * Parâmetros:
     *   $data_inicio - string no formato 'Y-m-d'
     *   $data_fim - string no formato 'Y-m-d'
     * Retorna: array com vendas do período
     * 
     * Uso:
     *   $vendas = $venda_obj->obter_por_periodo('2026-01-01', '2026-01-31');
     */
    public function obter_por_periodo($data_inicio, $data_fim) {
        if (empty($data_inicio) || empty($data_fim)) {
            return [];
        }
        
        // Query customizada
        $sql = "SELECT * FROM {$this->tabela} 
                WHERE data_venda >= ? AND data_venda <= ?
                ORDER BY data_venda DESC";
        
        // Usa prepare para segurança
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->bind_param('ss', $data_inicio, $data_fim);
        $stmt->execute();
        
        $resultado = $stmt->get_result();
        $vendas = [];
        
        while ($linha = $resultado->fetch_assoc()) {
            $vendas[] = $linha;
        }
        
        $stmt->close();
        
        return $vendas;
    }
    
    /**
     * Método: obter_por_mes()
     * Responsabilidade: Obter vendas do mês especificado
     * Parâmetros:
     *   $mes - int (1-12)
     *   $ano - int (ex: 2026)
     * Retorna: array com vendas
     * 
     * Uso:
     *   $vendas = $venda_obj->obter_por_mes(2, 2026); // Fevereiro de 2026
     */
    public function obter_por_mes($mes, $ano) {
        if (empty($mes) || empty($ano) || $mes < 1 || $mes > 12) {
            return [];
        }
        
        // Cria datas
        $data_inicio = sprintf('%04d-%02d-01', $ano, $mes);
        $ultimo_dia = cal_days_in_month(CAL_GREGORIAN, $mes, $ano);
        $data_fim = sprintf('%04d-%02d-%02d', $ano, $mes, $ultimo_dia);
        
        return $this->obter_por_periodo($data_inicio, $data_fim);
    }
    
    /**
     * Método: calcular_total_periodo()
     * Responsabilidade: Calcular totais de vendas de um período
     * Parâmetros: $data_inicio, $data_fim
     * Retorna: array com totais ['valor_total', 'valor_custo', 'valor_lucro']
     * 
     * Uso:
     *   $totais = $venda_obj->calcular_total_periodo('2026-01-01', '2026-01-31');
     */
    public function calcular_total_periodo($data_inicio, $data_fim) {
        if (empty($data_inicio) || empty($data_fim)) {
            return ['valor_total' => 0, 'valor_custo' => 0, 'valor_lucro' => 0];
        }
        
        $vendas = $this->obter_por_periodo($data_inicio, $data_fim);
        
        $totais = [
            'valor_total' => 0,
            'valor_custo' => 0,
            'valor_lucro' => 0
        ];
        
        foreach ($vendas as $venda) {
            $totais['valor_total'] += $venda['valor_total'];
            $totais['valor_custo'] += $venda['valor_custo'];
            $totais['valor_lucro'] += $venda['valor_lucro'];
        }
        
        return $totais;
    }
    
    /**
     * Método: calcular_total_mes()
     * Responsabilidade: Calcular totais do mês
     * Parâmetros: $mes, $ano
     * Retorna: array com totais
     * 
     * Uso:
     *   $totais = $venda_obj->calcular_total_mes(2, 2026);
     */
    public function calcular_total_mes($mes, $ano) {
        if (empty($mes) || empty($ano)) {
            return ['valor_total' => 0, 'valor_custo' => 0, 'valor_lucro' => 0];
        }
        
        $data_inicio = sprintf('%04d-%02d-01', $ano, $mes);
        $ultimo_dia = cal_days_in_month(CAL_GREGORIAN, $mes, $ano);
        $data_fim = sprintf('%04d-%02d-%02d', $ano, $mes, $ultimo_dia);
        
        return $this->calcular_total_periodo($data_inicio, $data_fim);
    }
    
    /**
     * Método: obter_ultimas()
     * Responsabilidade: Obter últimas vendas (ex: últimos 5)
     * Parâmetros: $limite (padrão: 5)
     * Retorna: array com vendas
     * 
     * Uso:
     *   $ultimas = $venda_obj->obter_ultimas(10);
     */
    public function obter_ultimas($limite = 5) {
        if ($limite <= 0) {
            $limite = 5;
        }
        
        return $this->db->select($this->tabela, [], 'data_venda DESC', $limite, 0);
    }
    
    /**
     * Método: atualizar()
     * Responsabilidade: Atualizar dados de uma venda
     * Parâmetros: $id, $dados
     * Retorna: bool
     * 
     * Nota: Apenas campos não-críticos podem ser atualizados
     */
    public function atualizar($id, $dados) {
        if (empty($id) || empty($dados)) {
            return false;
        }
        
        // Apenas estes campos podem ser atualizados
        $campos_permitidos = ['observacao'];
        
        $dados_update = [];
        foreach ($dados as $campo => $valor) {
            if (in_array($campo, $campos_permitidos)) {
                $dados_update[$campo] = $valor;
            }
        }
        
        if (empty($dados_update)) {
            return false;
        }
        
        return $this->db->update($this->tabela, $dados_update, ['id' => $id]);
    }
    
    /**
     * Método: deletar()
     * Responsabilidade: Cancelar uma venda (soft delete)
     * Parâmetros: $id (int)
     * Retorna: bool
     * 
     * Nota: Não deleta permanentemente, apenas marca como cancelada
     */
    public function deletar($id) {
        if (empty($id)) {
            return false;
        }
        
        return $this->db->update($this->tabela, 
            ['situacao' => PEDIDO_CANCELADO],
            ['id' => $id]
        );
    }
    
    /**
     * Método: contar()
     * Responsabilidade: Contar total de vendas
     * Parâmetros: $filtro (array)
     * Retorna: int
     */
    public function contar($filtro = []) {
        $where = [];
        
        if (!empty($filtro['cliente_id'])) {
            $where['cliente_id'] = $filtro['cliente_id'];
        }
        
        return $this->db->count($this->tabela, $where);
    }
    
    /**
     * Método: obter_por_numero()
     * Responsabilidade: Buscar venda pelo número
     * Parâmetros: $numero (string)
     * Retorna: array ou false
     * 
     * Uso:
     *   $venda = $venda_obj->obter_por_numero('VND-20260214-0001');
     */
    public function obter_por_numero($numero) {
        if (empty($numero)) {
            return false;
        }
        
        return $this->db->selectOne($this->tabela, ['numero' => $numero]);
    }
    
    /**
     * Método PRIVADO: gerar_numero_venda()
     * Responsabilidade: Gerar número único para venda
     * Parâmetros: none
     * Retorna: string (formato: VND-DATA-SEQUENCIAL)
     */
    private function gerar_numero_venda() {
        $data = date('Ymd');
        $sequencial = $this->db->count($this->tabela, []) + 1;
        
        return sprintf('VND-%s-%04d', $data, $sequencial);
    }
}

?>
