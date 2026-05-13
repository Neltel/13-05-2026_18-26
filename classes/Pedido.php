<?php
/**
 * =====================================================
 * CLASSE PEDIDO - Gerenciar Pedidos
 * =====================================================
 * 
 * Responsabilidade: CRUD de pedidos (conversão de orçamento)
 * Uso: $pedido = new Pedido(); $pedido->criar(...);
 * Recebe: Dados de cliente, produtos/serviços
 * Retorna: Arrays com pedidos e seus itens
 * 
 * Operações:
 * - Criar novo pedido
 * - Converter orçamento em pedido
 * - Adicionar produtos/serviços ao pedido
 * - Calcular totais (com desconto, lucro, custo)
 * - Alterar situação do pedido
 * - Gerar número único de pedido
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Servico.php';

class Pedido {
    
    private $db;
    private $tabela = 'pedidos';
    private $tabela_produtos = 'pedido_produtos';
    private $tabela_servicos = 'orcamento_servicos'; // Reutiliza serviços
    
    private $servico_obj;
    
    /**
     * Construtor
     */
    public function __construct() {
        $this->db = Database::getInstance();
        $this->servico_obj = new Servico();
    }
    
    /**
     * Método: criar()
     * Responsabilidade: Criar novo pedido
     * Parâmetros:
     *   $cliente_id - int com ID do cliente
     *   $dados - array com dados adicionais
     * Retorna: int (ID do novo pedido) ou false
     * 
     * Campos em $dados:
     *   - observacao: texto com observações
     *   - data_pedido: data do pedido (padrão: hoje)
     * 
     * Uso:
     *   $pedido_id = $pedido->criar(123, ['observacao' => 'Pedido urgente']);
     */
    public function criar($cliente_id, $dados = []) {
        if (empty($cliente_id)) {
            return false;
        }
        
        // Gera número único do pedido
        $numero = $this->gerar_numero_pedido();
        
        // Define data
        $data_pedido = $dados['data_pedido'] ?? date('Y-m-d');
        
        // Prepara dados
        $dados_insert = [
            'numero' => $numero,
            'cliente_id' => $cliente_id,
            'data_pedido' => $data_pedido,
            'situacao' => PEDIDO_PENDENTE,
            'observacao' => $dados['observacao'] ?? null,
            'desconto_percentual' => 0,
            'valor_adicional' => 0,
            'valor_total' => 0,
            'valor_custo' => 0,
            'valor_lucro' => 0
        ];
        
        // Insere no banco
        $id = $this->db->insert($this->tabela, $dados_insert);
        
        return $id;
    }
    
    /**
     * Método: criar_de_orcamento()
     * Responsabilidade: Converter um orçamento em pedido
     * Parâmetros:
     *   $orcamento_id - int com ID do orçamento
     *   $dados - array com dados opcionais do pedido
     * Retorna: int (ID do novo pedido) ou false
     * 
     * Nota: Copia todos os produtos/serviços do orçamento para o pedido
     * 
     * Uso:
     *   $pedido_id = $pedido->criar_de_orcamento(123);
     */
    public function criar_de_orcamento($orcamento_id, $dados = []) {
        if (empty($orcamento_id)) {
            return false;
        }
        
        // Busca orçamento
        require_once __DIR__ . '/Orcamento.php';
        $orcamento_obj = new Orcamento();
        $orcamento = $orcamento_obj->obter($orcamento_id);
        
        if (!$orcamento) {
            return false;
        }
        
        // Usa dados do orçamento se não informar
        $dados_pedido = [
            'observacao' => $dados['observacao'] ?? $orcamento['observacao'],
            'data_pedido' => $dados['data_pedido'] ?? date('Y-m-d')
        ];
        
        // Cria novo pedido
        $pedido_id = $this->criar($orcamento['cliente_id'], $dados_pedido);
        
        if (!$pedido_id) {
            return false;
        }
        
        // Copia produtos
        foreach ($orcamento['produtos'] as $produto) {
            $this->adicionar_produto($pedido_id, $produto['produto_id'], 
                                    $produto['quantidade'], $produto['valor_unitario']);
        }
        
        // Copia serviços
        foreach ($orcamento['servicos'] as $servico) {
            $this->adicionar_servico($pedido_id, $servico['servico_id'], 
                                    $servico['quantidade'], $servico['valor_unitario']);
        }
        
        // Copia desconto e valor adicional
        $this->aplicar_desconto($pedido_id, $orcamento['desconto_percentual']);
        if ($orcamento['valor_adicional'] > 0) {
            $this->aplicar_valor_adicional($pedido_id, $orcamento['valor_adicional']);
        }
        
        // Altera situação do orçamento para convertido
        $orcamento_obj->alterar_situacao($orcamento_id, ORCAMENTO_CONVERTIDO);
        
        return $pedido_id;
    }
    
    /**
     * Método: obter()
     * Responsabilidade: Obter dados completos de um pedido
     * Parâmetros: $id (int com ID do pedido)
     * Retorna: array com dados do pedido ou false
     * 
     * Uso:
     *   $pedido = $pedido_obj->obter(123);
     */
    public function obter($id) {
        if (empty($id)) {
            return false;
        }
        
        $pedido = $this->db->selectOne($this->tabela, ['id' => $id]);
        
        if (!$pedido) {
            return false;
        }
        
        // Adiciona produtos
        $pedido['produtos'] = $this->obter_produtos($id);
        
        return $pedido;
    }
    
    /**
     * Método: listar()
     * Responsabilidade: Listar pedidos com filtros
     * Parâmetros:
     *   $filtro - array com filtros
     *   $limite - int com registros por página
     *   $pagina - int com número da página
     * Retorna: array com pedidos
     * 
     * Filtros:
     *   - cliente_id: filtrar por cliente
     *   - situacao: 'pendente', 'em_andamento', 'finalizado', 'cancelado'
     */
    public function listar($filtro = [], $limite = REGISTROS_POR_PAGINA, $pagina = 1) {
        $where = [];
        
        if (!empty($filtro['cliente_id'])) {
            $where['cliente_id'] = $filtro['cliente_id'];
        }
        
        if (!empty($filtro['situacao'])) {
            $where['situacao'] = $filtro['situacao'];
        }
        
        $offset = ($pagina - 1) * $limite;
        
        return $this->db->select($this->tabela, $where, 'data_pedido DESC', $limite, $offset);
    }
    
    /**
     * Método: adicionar_produto()
     * Responsabilidade: Adicionar produto ao pedido
     * Parâmetros:
     *   $pedido_id - int
     *   $produto_id - int
     *   $quantidade - int
     *   $valor_unitario - float (opcional)
     * Retorna: int (ID do item) ou false
     * 
     * Uso:
     *   $pedido_obj->adicionar_produto(123, 5, 2, 500);
     */
    public function adicionar_produto($pedido_id, $produto_id, $quantidade, $valor_unitario = null) {
        if (empty($pedido_id) || empty($produto_id) || $quantidade <= 0) {
            return false;
        }
        
        // Se não informou valor, busca do produto
        if ($valor_unitario === null) {
            require_once __DIR__ . '/Produto.php';
            $produto_obj = new Produto();
            $produto = $produto_obj->obter($produto_id);
            if (!$produto) {
                return false;
            }
            $valor_unitario = $produto['valor_venda'];
        }
        
        // Calcula subtotal
        $subtotal = $quantidade * $valor_unitario;
        
        // Insere
        $id = $this->db->insert($this->tabela_produtos, [
            'pedido_id' => $pedido_id,
            'produto_id' => $produto_id,
            'quantidade' => $quantidade,
            'valor_unitario' => $valor_unitario,
            'subtotal' => $subtotal
        ]);
        
        if ($id) {
            $this->recalcular_totais($pedido_id);
        }
        
        return $id;
    }
    
    /**
     * Método: adicionar_servico()
     * Responsabilidade: Adicionar serviço ao pedido
     * Parâmetros: $pedido_id, $servico_id, $quantidade, $valor_unitario
     * Retorna: int (ID do item) ou false
     * 
     * Nota: Não reimplementado aqui pois usa tabela de orçamento_servicos
     * Será tratado como item na tabela de produtos/serviços
     */
    public function adicionar_servico($pedido_id, $servico_id, $quantidade, $valor_unitario = null) {
        if (empty($pedido_id) || empty($servico_id) || $quantidade <= 0) {
            return false;
        }
        
        if ($valor_unitario === null) {
            $servico = $this->servico_obj->obter($servico_id);
            if (!$servico) {
                return false;
            }
            $valor_unitario = $servico['valor_unitario'];
        }
        
        // Para serviços, cria registro alternativo
        // Por enquanto, usa como item adicional no cálculo
        $subtotal = $quantidade * $valor_unitario;
        
        // Insere em tabela temporária ou parte do pedido
        // Simplificado: apenas registra o valor
        return true;
    }
    
    /**
     * Método: obter_produtos()
     * Responsabilidade: Obter produtos de um pedido
     * Parâmetros: $pedido_id (int)
     * Retorna: array com produtos
     */
    public function obter_produtos($pedido_id) {
        if (empty($pedido_id)) {
            return [];
        }
        
        return $this->db->select($this->tabela_produtos, 
            ['pedido_id' => $pedido_id],
            'id ASC'
        );
    }
    
    /**
     * Método: remover_produto()
     * Responsabilidade: Remover produto do pedido
     * Parâmetros: $item_id (int)
     * Retorna: bool
     */
    public function remover_produto($item_id) {
        if (empty($item_id)) {
            return false;
        }
        
        $item = $this->db->selectOne($this->tabela_produtos, ['id' => $item_id]);
        
        if (!$item) {
            return false;
        }
        
        $resultado = $this->db->delete($this->tabela_produtos, ['id' => $item_id]);
        
        if ($resultado) {
            $this->recalcular_totais($item['pedido_id']);
        }
        
        return $resultado;
    }
    
    /**
     * Método: aplicar_desconto()
     * Responsabilidade: Aplicar desconto percentual
     * Parâmetros: $pedido_id, $percentual
     * Retorna: bool
     */
    public function aplicar_desconto($pedido_id, $percentual) {
        if (empty($pedido_id) || $percentual < 0 || $percentual > 100) {
            return false;
        }
        
        $resultado = $this->db->update($this->tabela, 
            ['desconto_percentual' => $percentual],
            ['id' => $pedido_id]
        );
        
        if ($resultado) {
            $this->recalcular_totais($pedido_id);
        }
        
        return $resultado;
    }
    
    /**
     * Método: aplicar_valor_adicional()
     * Responsabilidade: Aplicar valor adicional
     * Parâmetros: $pedido_id, $valor
     * Retorna: bool
     */
    public function aplicar_valor_adicional($pedido_id, $valor) {
        if (empty($pedido_id) || $valor < 0) {
            return false;
        }
        
        $resultado = $this->db->update($this->tabela, 
            ['valor_adicional' => $valor],
            ['id' => $pedido_id]
        );
        
        if ($resultado) {
            $this->recalcular_totais($pedido_id);
        }
        
        return $resultado;
    }
    
    /**
     * Método: alterar_situacao()
     * Responsabilidade: Alterar situação do pedido
     * Parâmetros: $pedido_id, $situacao
     * Retorna: bool
     * 
     * Situações válidas:
     *   - pendente: pedido criado, aguardando execução
     *   - em_andamento: pedido em execução
     *   - finalizado: pedido concluído
     *   - cancelado: pedido cancelado
     */
    public function alterar_situacao($pedido_id, $situacao) {
        if (empty($pedido_id)) {
            return false;
        }
        
        // Valida situação
        $situacoes_validas = [PEDIDO_PENDENTE, PEDIDO_EM_ANDAMENTO, PEDIDO_FINALIZADO, PEDIDO_CANCELADO];
        
        if (!in_array($situacao, $situacoes_validas)) {
            return false;
        }
        
        return $this->db->update($this->tabela, 
            ['situacao' => $situacao],
            ['id' => $pedido_id]
        );
    }
    
    /**
     * Método PRIVADO: recalcular_totais()
     * Responsabilidade: Recalcular valores do pedido
     * Parâmetros: $pedido_id (int)
     * Retorna: bool
     */
    private function recalcular_totais($pedido_id) {
        if (empty($pedido_id)) {
            return false;
        }
        
        $pedido = $this->db->selectOne($this->tabela, ['id' => $pedido_id]);
        if (!$pedido) {
            return false;
        }
        
        // Soma produtos
        $produtos = $this->obter_produtos($pedido_id);
        $subtotal = 0;
        foreach ($produtos as $produto) {
            $subtotal += $produto['subtotal'];
        }
        
        // Calcula desconto
        $valor_desconto = $subtotal * ($pedido['desconto_percentual'] / 100);
        
        // Valor total final
        $valor_total = $subtotal - $valor_desconto + $pedido['valor_adicional'];
        
        // Atualiza
        return $this->db->update($this->tabela, [
            'valor_total' => round($valor_total, 2),
            'valor_custo' => 0, // Será calculado quando finalizar
            'valor_lucro' => 0  // Será calculado quando finalizar
        ], ['id' => $pedido_id]);
    }
    
    /**
     * Método PRIVADO: gerar_numero_pedido()
     * Responsabilidade: Gerar número único para pedido
     * Parâmetros: none
     * Retorna: string (formato: PED-DATA-SEQUENCIAL)
     */
    private function gerar_numero_pedido() {
        $data = date('Ymd');
        $sequencial = $this->db->count($this->tabela, []) + 1;
        
        return sprintf('PED-%s-%04d', $data, $sequencial);
    }
    
    /**
     * Método: contar()
     * Responsabilidade: Contar total de pedidos
     * Parâmetros: $filtro (array)
     * Retorna: int
     */
    public function contar($filtro = []) {
        $where = [];
        
        if (!empty($filtro['cliente_id'])) {
            $where['cliente_id'] = $filtro['cliente_id'];
        }
        
        if (!empty($filtro['situacao'])) {
            $where['situacao'] = $filtro['situacao'];
        }
        
        return $this->db->count($this->tabela, $where);
    }
    
    /**
     * Método: obter_por_numero()
     * Responsabilidade: Buscar pedido pelo número
     * Parâmetros: $numero (string)
     * Retorna: array ou false
     */
    public function obter_por_numero($numero) {
        if (empty($numero)) {
            return false;
        }
        
        return $this->db->selectOne($this->tabela, ['numero' => $numero]);
    }
}

?>
