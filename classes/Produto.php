<?php
/**
 * =====================================================
 * CLASSE PRODUTO - Gerenciar Produtos
 * =====================================================
 * 
 * Responsabilidade: CRUD de produtos, estoque e preços
 * Uso: $produto = new Produto(); $produto->criar(...);
 * Recebe: Dados de produto (nome, preço, estoque, etc)
 * Retorna: Arrays com informações dos produtos
 * 
 * Operações:
 * - Criar novo produto
 * - Buscar produto por ID
 * - Listar produtos com filtros
 * - Atualizar dados de produto
 * - Deletar produto
 * - Gerenciar estoque (ajustar, repor)
 * - Calcular margem de lucro
 * - Gerenciar categorias
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Upload.php';

class Produto {
    
    private $db;
    private $tabela = 'produtos';
    private $tabela_categorias = 'categorias_produtos';
    
    /**
     * Construtor
     */
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Método: criar()
     * Responsabilidade: Criar novo produto
     * Parâmetros: $dados (array com dados do produto)
     * Retorna: int (ID do novo produto) ou false
     * 
     * Campos obrigatórios: nome, valor_venda, categoria_id
     * 
     * Exemplo:
     *   $dados = [
     *       'nome' => 'Ar Condicionado 12000 BTU',
     *       'descricao' => 'AC Inverter...',
     *       'categoria_id' => 1,
     *       'valor_compra' => 1500,
     *       'valor_venda' => 2500,
     *       'estoque_atual' => 5,
     *       'exibir_cliente' => true
     *   ];
     *   $id = $produto->criar($dados);
     */
    public function criar($dados) {
        // Validações
        if (empty($dados['nome']) || empty($dados['valor_venda'])) {
            return false;
        }
        
        // Prepara dados
        $dados_insert = [
            'nome' => trim($dados['nome']),
            'descricao' => $dados['descricao'] ?? null,
            'categoria_id' => $dados['categoria_id'] ?? null,
            'valor_compra' => floatval($dados['valor_compra'] ?? 0),
            'valor_venda' => floatval($dados['valor_venda']),
            'estoque_atual' => intval($dados['estoque_atual'] ?? 0),
            'estoque_minimo' => intval($dados['estoque_minimo'] ?? 0),
            'exibir_cliente' => $dados['exibir_cliente'] ?? true,
            'ativo' => true
        ];
        
        // Calcula margem de lucro
        if (!empty($dados_insert['valor_compra']) && $dados_insert['valor_compra'] > 0) {
            $lucro = (($dados_insert['valor_venda'] - $dados_insert['valor_compra']) / $dados_insert['valor_compra']) * 100;
            $dados_insert['margem_lucro'] = round($lucro, 2);
        }
        
        // Insere no banco
        $id = $this->db->insert($this->tabela, $dados_insert);
        
        return $id;
    }
    
    /**
     * Método: obter()
     * Responsabilidade: Obter dados completos de um produto
     * Parâmetros: $id (int)
     * Retorna: array com dados do produto ou false
     * 
     * Uso:
     *   $produto = $produto_obj->obter(123);
     */
    public function obter($id) {
        if (empty($id)) {
            return false;
        }
        
        return $this->db->selectOne($this->tabela, ['id' => $id]);
    }
    
    /**
     * Método: listar()
     * Responsabilidade: Listar produtos com filtros e paginação
     * Parâmetros:
     *   $filtro - array com filtros
     *   $limite - int com registros por página
     *   $pagina - int com número da página
     * Retorna: array com produtos
     * 
     * Filtros disponíveis:
     *   - nome: busca por nome
     *   - categoria_id: filtrar por categoria
     *   - ativo: filtrar por status
     *   - exibir_cliente: apenas exibição para cliente
     */
    public function listar($filtro = [], $limite = REGISTROS_POR_PAGINA, $pagina = 1) {
        $where = [];
        
        // Filtro por nome
        if (!empty($filtro['nome'])) {
            // Para LIKE, busca customizada
            $offset = ($pagina - 1) * $limite;
            
            $sql = "SELECT * FROM {$this->tabela} 
                    WHERE nome LIKE '%{$this->db->escape($filtro['nome'])}%'
                    ORDER BY nome ASC
                    LIMIT {$limite} OFFSET {$offset}";
            
            // Retorna resultado direto
            return $this->db->select($this->tabela, 
                ['nome' => ['operador' => 'LIKE', 'valor' => '%' . $filtro['nome'] . '%']],
                'nome ASC',
                $limite,
                ($pagina - 1) * $limite
            );
        }
        
        // Filtros simples
        if (!empty($filtro['categoria_id'])) {
            $where['categoria_id'] = $filtro['categoria_id'];
        }
        
        if (isset($filtro['ativo'])) {
            $where['ativo'] = $filtro['ativo'];
        }
        
        if (isset($filtro['exibir_cliente'])) {
            $where['exibir_cliente'] = $filtro['exibir_cliente'];
        }
        
        $offset = ($pagina - 1) * $limite;
        
        return $this->db->select($this->tabela, $where, 'nome ASC', $limite, $offset);
    }
    
    /**
     * Método: atualizar()
     * Responsabilidade: Atualizar dados de um produto
     * Parâmetros:
     *   $id - int com ID do produto
     *   $dados - array com dados a atualizar
     * Retorna: bool (sucesso ou falha)
     * 
     * Uso:
     *   $sucesso = $produto_obj->atualizar(123, ['nome' => 'Novo Nome']);
     */
    public function atualizar($id, $dados) {
        if (empty($id) || empty($dados)) {
            return false;
        }
        
        // Campos permitidos para atualização
        $campos_permitidos = ['nome', 'descricao', 'categoria_id', 'valor_compra', 
                             'valor_venda', 'estoque_atual', 'estoque_minimo', 
                             'exibir_cliente', 'ativo'];
        
        $dados_update = [];
        foreach ($dados as $campo => $valor) {
            if (in_array($campo, $campos_permitidos)) {
                $dados_update[$campo] = $valor;
            }
        }
        
        if (empty($dados_update)) {
            return false;
        }
        
        // Recalcula margem de lucro se alterou preços
        if (!empty($dados['valor_compra']) || !empty($dados['valor_venda'])) {
            // Busca produto atual
            $produto = $this->obter($id);
            
            $valor_compra = $dados['valor_compra'] ?? $produto['valor_compra'];
            $valor_venda = $dados['valor_venda'] ?? $produto['valor_venda'];
            
            if ($valor_compra > 0) {
                $lucro = (($valor_venda - $valor_compra) / $valor_compra) * 100;
                $dados_update['margem_lucro'] = round($lucro, 2);
            }
        }
        
        return $this->db->update($this->tabela, $dados_update, ['id' => $id]);
    }
    
    /**
     * Método: deletar()
     * Responsabilidade: Deletar um produto (soft delete)
     * Parâmetros: $id (int)
     * Retorna: bool (sucesso ou falha)
     */
    public function deletar($id) {
        if (empty($id)) {
            return false;
        }
        
        return $this->db->update($this->tabela, ['ativo' => false], ['id' => $id]);
    }
    
    /**
     * Método: ajustar_estoque()
     * Responsabilidade: Ajustar quantidade em estoque (aumentar ou diminuir)
     * Parâmetros:
     *   $id - int com ID do produto
     *   $quantidade - int (positivo = aumenta, negativo = diminui)
     *   $motivo - string com motivo do ajuste (opcional)
     * Retorna: bool (sucesso ou falha)
     * 
     * Uso:
     *   $produto_obj->ajustar_estoque(123, 5); // Adiciona 5
     *   $produto_obj->ajustar_estoque(123, -2); // Remove 2
     */
    public function ajustar_estoque($id, $quantidade, $motivo = '') {
        if (empty($id) || $quantidade == 0) {
            return false;
        }
        
        // Busca produto
        $produto = $this->obter($id);
        if (!$produto) {
            return false;
        }
        
        // Calcula novo estoque
        $novo_estoque = $produto['estoque_atual'] + $quantidade;
        
        // Não permite estoque negativo
        if ($novo_estoque < 0) {
            return false;
        }
        
        // Atualiza estoque
        return $this->db->update($this->tabela, 
            ['estoque_atual' => $novo_estoque],
            ['id' => $id]
        );
    }
    
    /**
     * Método: repor_estoque()
     * Responsabilidade: Repor estoque de um produto
     * Parâmetros:
     *   $id - int com ID do produto
     *   $quantidade - int com quantidade a adicionar
     *   $valor_compra - float com novo valor de compra (opcional)
     * Retorna: bool (sucesso ou falha)
     * 
     * Uso:
     *   $produto_obj->repor_estoque(123, 10, 1500);
     */
    public function repor_estoque($id, $quantidade, $valor_compra = null) {
        if (empty($id) || empty($quantidade)) {
            return false;
        }
        
        // Ajusta estoque
        if (!$this->ajustar_estoque($id, $quantidade)) {
            return false;
        }
        
        // Atualiza valor de compra se informado
        if (!empty($valor_compra)) {
            // Recalcula margem de lucro
            $produto = $this->obter($id);
            if ($produto && $valor_compra > 0) {
                $lucro = (($produto['valor_venda'] - $valor_compra) / $valor_compra) * 100;
                
                $this->db->update($this->tabela, 
                    ['valor_compra' => $valor_compra, 'margem_lucro' => round($lucro, 2)],
                    ['id' => $id]
                );
            }
        }
        
        return true;
    }
    
    /**
     * Método: obter_estoque_critico()
     * Responsabilidade: Obter produtos com estoque abaixo do mínimo
     * Parâmetros: none
     * Retorna: array com produtos em estoque crítico
     * 
     * Uso:
     *   $criticos = $produto_obj->obter_estoque_critico();
     */
    public function obter_estoque_critico() {
        // SQL customizado para comparar estoque_atual com estoque_minimo
        $sql = "SELECT * FROM {$this->tabela} 
                WHERE estoque_atual < estoque_minimo 
                AND ativo = true
                ORDER BY estoque_atual ASC";
        
        return $this->db->select($this->tabela, 
            ['ativo' => true],
            'estoque_atual ASC'
        );
    }
    
    /**
     * Método: adicionar_foto()
     * Responsabilidade: Fazer upload de foto do produto
     * Parâmetros:
     *   $id - int com ID do produto
     *   $arquivo - array $_FILES com foto
     * Retorna: string (caminho da foto) ou false
     * 
     * Uso:
     *   $foto = $produto_obj->adicionar_foto(123, $_FILES['foto']);
     */
    public function adicionar_foto($id, $arquivo) {
        if (empty($id) || empty($arquivo)) {
            return false;
        }
        
        $upload = new Upload();
        $upload->definir_diretorio(DIR_UPLOADS . '/produtos');
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
     * Método: contar()
     * Responsabilidade: Contar total de produtos
     * Parâmetros: $filtro (array opcional)
     * Retorna: int
     * 
     * Uso:
     *   $total = $produto_obj->contar(['ativo' => true]);
     */
    public function contar($filtro = []) {
        $where = [];
        
        if (isset($filtro['ativo'])) {
            $where['ativo'] = $filtro['ativo'];
        }
        
        if (!empty($filtro['categoria_id'])) {
            $where['categoria_id'] = $filtro['categoria_id'];
        }
        
        return $this->db->count($this->tabela, $where);
    }
    
    /**
     * Método: criar_categoria()
     * Responsabilidade: Criar nova categoria de produtos
     * Parâmetros:
     *   $nome - string com nome da categoria
     *   $descricao - string com descrição (opcional)
     * Retorna: int (ID da categoria) ou false
     * 
     * Uso:
     *   $categoria_id = $produto_obj->criar_categoria('Ar Condicionado');
     */
    public function criar_categoria($nome, $descricao = '') {
        if (empty($nome)) {
            return false;
        }
        
        return $this->db->insert($this->tabela_categorias, [
            'nome' => trim($nome),
            'descricao' => $descricao,
            'ativo' => true
        ]);
    }
    
    /**
     * Método: obter_categorias()
     * Responsabilidade: Obter todas as categorias
     * Parâmetros: none
     * Retorna: array com categorias
     * 
     * Uso:
     *   $categorias = $produto_obj->obter_categorias();
     */
    public function obter_categorias() {
        return $this->db->select($this->tabela_categorias, 
            ['ativo' => true],
            'nome ASC'
        );
    }
    
    /**
     * Método: atualizar_categoria()
     * Responsabilidade: Atualizar categoria
     * Parâmetros: $id, $dados
     * Retorna: bool
     */
    public function atualizar_categoria($id, $dados) {
        if (empty($id) || empty($dados)) {
            return false;
        }
        
        $dados_update = [];
        if (!empty($dados['nome'])) {
            $dados_update['nome'] = trim($dados['nome']);
        }
        if (isset($dados['descricao'])) {
            $dados_update['descricao'] = $dados['descricao'];
        }
        
        if (empty($dados_update)) {
            return false;
        }
        
        return $this->db->update($this->tabela_categorias, $dados_update, ['id' => $id]);
    }
    
    /**
     * Método: deletar_categoria()
     * Responsabilidade: Deletar categoria
     * Parâmetros: $id (int)
     * Retorna: bool
     */
    public function deletar_categoria($id) {
        if (empty($id)) {
            return false;
        }
        
        return $this->db->update($this->tabela_categorias, ['ativo' => false], ['id' => $id]);
    }
}

?>
