<?php
/**
 * =====================================================
 * CLASSE ORCAMENTO - Gerenciar Orçamentos
 * =====================================================
 * 
 * Responsabilidade: CRUD de orçamentos, cálculos de valores
 * Uso: $orcamento = new Orcamento(); $orcamento->criar(...);
 * Recebe: Dados de cliente, produtos/serviços, descontos
 * Retorna: Arrays com orçamentos e cálculos financeiros
 * 
 * Operações:
 * - Criar novo orçamento
 * - Adicionar produtos/serviços ao orçamento
 * - Calcular totais (com desconto, adicional, lucro, custo)
 * - Gerar número único de orçamento
 * - Alterar situação de orçamento
 * - Gerar PDF
 * - Enviar por WhatsApp
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Produto.php';
require_once __DIR__ . '/Servico.php';

class Orcamento {
    
    private $db;
    private $tabela = 'orcamentos';
    private $tabela_produtos = 'orcamento_produtos';
    private $tabela_servicos = 'orcamento_servicos';
    
    private $produto_obj;
    private $servico_obj;
    
    /**
     * Construtor
     */
    public function __construct() {
        $this->db = Database::getInstance();
        $this->produto_obj = new Produto();
        $this->servico_obj = new Servico();
    }
    
    /**
     * Método: criar()
     * Responsabilidade: Criar novo orçamento
     * Parâmetros:
     *   $cliente_id - int com ID do cliente
     *   $dados - array com dados adicionais (opcional)
     * Retorna: int (ID do novo orçamento) ou false
     * 
     * Campos opcionais em $dados:
     *   - observacao: texto com observações
     *   - data_emissao: data do orçamento (padrão: hoje)
     *   - data_validade: data de validade (padrão: hoje + 30 dias)
     * 
     * Exemplo:
     *   $dados = [
     *       'observacao' => 'Cliente solicitou orçamento...',
     *       'data_validade' => date('Y-m-d', strtotime('+15 days'))
     *   ];
     *   $orcamento_id = $orcamento->criar(123, $dados);
     */
    public function criar($cliente_id, $dados = []) {
        if (empty($cliente_id)) {
            return false;
        }
        
        // Gera número único do orçamento
        $numero = $this->gerar_numero_orcamento();
        
        // Define datas
        $data_emissao = $dados['data_emissao'] ?? date('Y-m-d');
        $data_validade = $dados['data_validade'] ?? date('Y-m-d', strtotime('+' . DIAS_VALIDADE_ORCAMENTO . ' days'));
        
        // Prepara dados
        $dados_insert = [
            'numero' => $numero,
            'cliente_id' => $cliente_id,
            'data_emissao' => $data_emissao,
            'data_validade' => $data_validade,
            'situacao' => ORCAMENTO_PENDENTE,
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
     * Método: obter()
     * Responsabilidade: Obter dados completos de um orçamento
     * Parâmetros: $id (int com ID do orçamento)
     * Retorna: array com dados do orçamento ou false
     * 
     * Nota: Inclui informações agregadas (produtos, serviços, totais)
     * 
     * Uso:
     *   $orcamento = $orcamento_obj->obter(123);
     */
    public function obter($id) {
        if (empty($id)) {
            return false;
        }
        
        $orcamento = $this->db->selectOne($this->tabela, ['id' => $id]);
        
        if (!$orcamento) {
            return false;
        }
        
        // Adiciona produtos e serviços
        $orcamento['produtos'] = $this->obter_produtos($id);
        $orcamento['servicos'] = $this->obter_servicos($id);
        
        return $orcamento;
    }
    
    /**
     * Método: listar()
     * Responsabilidade: Listar orçamentos com filtros e paginação
     * Parâmetros:
     *   $filtro - array com filtros
     *   $limite - int com registros por página
     *   $pagina - int com número da página
     * Retorna: array com orçamentos
     * 
     * Filtros disponíveis:
     *   - cliente_id: filtrar por cliente
     *   - situacao: 'pendente', 'aprovado', 'rejeitado', 'convertido'
     *   - data_inicio: data inicial
     *   - data_fim: data final
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
        
        return $this->db->select($this->tabela, $where, 'data_emissao DESC', $limite, $offset);
    }
    
    /**
     * Método: adicionar_produto()
     * Responsabilidade: Adicionar produto ao orçamento
     * Parâmetros:
     *   $orcamento_id - int com ID do orçamento
     *   $produto_id - int com ID do produto
     *   $quantidade - int com quantidade
     *   $valor_unitario - float com valor unitário (opcional, usa padrão se não informar)
     * Retorna: int (ID do item) ou false
     * 
     * Nota: Ao adicionar, recalcula os totais do orçamento
     * 
     * Uso:
     *   $orcamento_obj->adicionar_produto(123, 5, 2, 500);
     */
    public function adicionar_produto($orcamento_id, $produto_id, $quantidade, $valor_unitario = null) {
        if (empty($orcamento_id) || empty($produto_id) || $quantidade <= 0) {
            return false;
        }
        
        // Se não informou valor, busca do produto
        if ($valor_unitario === null) {
            $produto = $this->produto_obj->obter($produto_id);
            if (!$produto) {
                return false;
            }
            $valor_unitario = $produto['valor_venda'];
        }
        
        // Calcula subtotal
        $subtotal = $quantidade * $valor_unitario;
        
        // Insere produto no orçamento
        $id = $this->db->insert($this->tabela_produtos, [
            'orcamento_id' => $orcamento_id,
            'produto_id' => $produto_id,
            'quantidade' => $quantidade,
            'valor_unitario' => $valor_unitario,
            'subtotal' => $subtotal
        ]);
        
        if ($id) {
            // Recalcula totais do orçamento
            $this->recalcular_totais($orcamento_id);
        }
        
        return $id;
    }
    
    /**
     * Método: adicionar_servico()
     * Responsabilidade: Adicionar serviço ao orçamento
     * Parâmetros:
     *   $orcamento_id - int com ID do orçamento
     *   $servico_id - int com ID do serviço
     *   $quantidade - int com quantidade
     *   $valor_unitario - float com valor unitário (opcional)
     * Retorna: int (ID do item) ou false
     * 
     * Nota: Também calcula custo de materiais necessários
     * 
     * Uso:
     *   $orcamento_obj->adicionar_servico(123, 10, 2, 500);
     */
    public function adicionar_servico($orcamento_id, $servico_id, $quantidade, $valor_unitario = null) {
        if (empty($orcamento_id) || empty($servico_id) || $quantidade <= 0) {
            return false;
        }
        
        // Se não informou valor, busca do serviço
        if ($valor_unitario === null) {
            $servico = $this->servico_obj->obter($servico_id);
            if (!$servico) {
                return false;
            }
            $valor_unitario = $servico['valor_unitario'];
        }
        
        // Calcula subtotal
        $subtotal = $quantidade * $valor_unitario;
        
        // Insere serviço no orçamento
        $id = $this->db->insert($this->tabela_servicos, [
            'orcamento_id' => $orcamento_id,
            'servico_id' => $servico_id,
            'quantidade' => $quantidade,
            'valor_unitario' => $valor_unitario,
            'subtotal' => $subtotal
        ]);
        
        if ($id) {
            // Recalcula totais
            $this->recalcular_totais($orcamento_id);
        }
        
        return $id;
    }
    
    /**
     * Método: obter_produtos()
     * Responsabilidade: Obter todos os produtos de um orçamento
     * Parâmetros: $orcamento_id (int)
     * Retorna: array com produtos
     * 
     * Uso:
     *   $produtos = $orcamento_obj->obter_produtos(123);
     */
    public function obter_produtos($orcamento_id) {
        if (empty($orcamento_id)) {
            return [];
        }
        
        return $this->db->select($this->tabela_produtos, 
            ['orcamento_id' => $orcamento_id],
            'id ASC'
        );
    }
    
    /**
     * Método: obter_servicos()
     * Responsabilidade: Obter todos os serviços de um orçamento
     * Parâmetros: $orcamento_id (int)
     * Retorna: array com serviços
     * 
     * Uso:
     *   $servicos = $orcamento_obj->obter_servicos(123);
     */
    public function obter_servicos($orcamento_id) {
        if (empty($orcamento_id)) {
            return [];
        }
        
        return $this->db->select($this->tabela_servicos, 
            ['orcamento_id' => $orcamento_id],
            'id ASC'
        );
    }
    
    /**
     * Método: remover_produto()
     * Responsabilidade: Remover um produto do orçamento
     * Parâmetros: $item_id (int)
     * Retorna: bool (sucesso ou falha)
     * 
     * Uso:
     *   $orcamento_obj->remover_produto(456);
     */
    public function remover_produto($item_id) {
        if (empty($item_id)) {
            return false;
        }
        
        // Busca item para obter orcamento_id
        $item = $this->db->selectOne($this->tabela_produtos, ['id' => $item_id]);
        
        if (!$item) {
            return false;
        }
        
        // Deleta item
        $resultado = $this->db->delete($this->tabela_produtos, ['id' => $item_id]);
        
        if ($resultado) {
            // Recalcula totais
            $this->recalcular_totais($item['orcamento_id']);
        }
        
        return $resultado;
    }
    
    /**
     * Método: remover_servico()
     * Responsabilidade: Remover um serviço do orçamento
     * Parâmetros: $item_id (int)
     * Retorna: bool (sucesso ou falha)
     */
    public function remover_servico($item_id) {
        if (empty($item_id)) {
            return false;
        }
        
        $item = $this->db->selectOne($this->tabela_servicos, ['id' => $item_id]);
        
        if (!$item) {
            return false;
        }
        
        $resultado = $this->db->delete($this->tabela_servicos, ['id' => $item_id]);
        
        if ($resultado) {
            $this->recalcular_totais($item['orcamento_id']);
        }
        
        return $resultado;
    }
    
    /**
     * Método: aplicar_desconto()
     * Responsabilidade: Aplicar desconto percentual ao orçamento
     * Parâmetros:
     *   $orcamento_id - int
     *   $percentual - float com percentual (ex: 5 para 5%)
     * Retorna: bool (sucesso ou falha)
     * 
     * Uso:
     *   $orcamento_obj->aplicar_desconto(123, 10); // Aplica 10% desconto
     */
    public function aplicar_desconto($orcamento_id, $percentual) {
        if (empty($orcamento_id) || $percentual < 0 || $percentual > 100) {
            return false;
        }
        
        // Atualiza desconto
        $resultado = $this->db->update($this->tabela, 
            ['desconto_percentual' => $percentual],
            ['id' => $orcamento_id]
        );
        
        if ($resultado) {
            // Recalcula totais
            $this->recalcular_totais($orcamento_id);
        }
        
        return $resultado;
    }
    
    /**
     * Método: aplicar_valor_adicional()
     * Responsabilidade: Aplicar valor adicional ao orçamento
     * Parâmetros:
     *   $orcamento_id - int
     *   $valor - float com valor a adicionar
     * Retorna: bool (sucesso ou falha)
     * 
     * Uso:
     *   $orcamento_obj->aplicar_valor_adicional(123, 50); // Adiciona 50 reais
     */
    public function aplicar_valor_adicional($orcamento_id, $valor) {
        if (empty($orcamento_id) || $valor < 0) {
            return false;
        }
        
        $resultado = $this->db->update($this->tabela, 
            ['valor_adicional' => $valor],
            ['id' => $orcamento_id]
        );
        
        if ($resultado) {
            $this->recalcular_totais($orcamento_id);
        }
        
        return $resultado;
    }
    
    /**
     * Método: alterar_situacao()
     * Responsabilidade: Alterar situação do orçamento
     * Parâmetros:
     *   $orcamento_id - int
     *   $situacao - string ('pendente', 'aprovado', 'rejeitado', 'convertido')
     * Retorna: bool (sucesso ou falha)
     * 
     * Uso:
     *   $orcamento_obj->alterar_situacao(123, ORCAMENTO_APROVADO);
     */
    public function alterar_situacao($orcamento_id, $situacao) {
        if (empty($orcamento_id)) {
            return false;
        }
        
        // Valida situação
        $situacoes_validas = [ORCAMENTO_PENDENTE, ORCAMENTO_APROVADO, ORCAMENTO_REJEITADO, ORCAMENTO_CONVERTIDO];
        
        if (!in_array($situacao, $situacoes_validas)) {
            return false;
        }
        
        return $this->db->update($this->tabela, 
            ['situacao' => $situacao],
            ['id' => $orcamento_id]
        );
    }
    
    /**
     * Método PRIVADO: recalcular_totais()
     * Responsabilidade: Recalcular valores totais, custo e lucro
     * Parâmetros: $orcamento_id (int)
     * Retorna: bool (sucesso ou falha)
     * 
     * Cálculos:
     * - Subtotal = sum(produtos + serviços)
     * - Desconto = subtotal * (desconto_percentual / 100)
     * - Total com desconto = subtotal - desconto + valor_adicional
     * - Custo = sum(custo de materiais dos serviços)
     * - Lucro = total com desconto - custo
     */
    private function recalcular_totais($orcamento_id) {
        if (empty($orcamento_id)) {
            return false;
        }
        
        // Busca orçamento
        $orcamento = $this->db->selectOne($this->tabela, ['id' => $orcamento_id]);
        if (!$orcamento) {
            return false;
        }
        
        // Soma produtos
        $produtos = $this->obter_produtos($orcamento_id);
        $subtotal_produtos = 0;
        foreach ($produtos as $produto) {
            $subtotal_produtos += $produto['subtotal'];
        }
        
        // Soma serviços
        $servicos = $this->obter_servicos($orcamento_id);
        $subtotal_servicos = 0;
        $custo_materiais = 0;
        foreach ($servicos as $servico) {
            $subtotal_servicos += $servico['subtotal'];
            
            // Calcula custo de materiais
            $custo_materiais += $this->servico_obj->calcular_custo_materiais(
                $servico['servico_id'],
                $servico['quantidade']
            );
        }
        
        // Subtotal geral
        $subtotal = $subtotal_produtos + $subtotal_servicos;
        
        // Calcula desconto
        $valor_desconto = $subtotal * ($orcamento['desconto_percentual'] / 100);
        
        // Valor total final
        $valor_total = $subtotal - $valor_desconto + $orcamento['valor_adicional'];
        
        // Custo total (apenas materiais dos serviços)
        $valor_custo = $custo_materiais;
        
        // Lucro
        $valor_lucro = $valor_total - $valor_custo;
        
        // Atualiza no banco
        return $this->db->update($this->tabela, [
            'valor_total' => round($valor_total, 2),
            'valor_custo' => round($valor_custo, 2),
            'valor_lucro' => round($valor_lucro, 2)
        ], ['id' => $orcamento_id]);
    }
    
    /**
     * Método PRIVADO: gerar_numero_orcamento()
     * Responsabilidade: Gerar número único para orçamento
     * Parâmetros: none
     * Retorna: string com número (formato: ORC-DATA-SEQUENCIAL)
     * 
     * Exemplo: ORC-20260214-0001
     */
    private function gerar_numero_orcamento() {
        $data = date('Ymd');
        $sequencial = $this->db->count($this->tabela, ['data_emissao' => ['operador' => '>=', 'valor' => date('Y-m-d')]]) + 1;
        
        return sprintf('ORC-%s-%04d', $data, $sequencial);
    }
    
    /**
     * Método: contar()
     * Responsabilidade: Contar total de orçamentos
     * Parâmetros: $filtro (array opcional)
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
     * Responsabilidade: Buscar orçamento pelo número
     * Parâmetros: $numero (string)
     * Retorna: array com orçamento ou false
     * 
     * Uso:
     *   $orcamento = $orcamento_obj->obter_por_numero('ORC-20260214-0001');
     */
    public function obter_por_numero($numero) {
        if (empty($numero)) {
            return false;
        }
        
        return $this->db->selectOne($this->tabela, ['numero' => $numero]);
    }
}

?>
