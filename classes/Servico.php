<?php
/**
 * =====================================================
 * CLASSE SERVICO - Gerenciar Serviços
 * =====================================================
 * 
 * Responsabilidade: CRUD de serviços e materiais necessários
 * Uso: $servico = new Servico(); $servico->criar(...);
 * Recebe: Dados de serviço (nome, valor, tempo, materiais)
 * Retorna: Arrays com informações dos serviços
 * 
 * Operações:
 * - Criar novo serviço
 * - Buscar serviço por ID
 * - Listar serviços com filtros
 * - Atualizar dados de serviço
 * - Deletar serviço
 * - Gerenciar materiais do serviço
 * - Calcular custo total do serviço
 * - Upload de fotos
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Upload.php';

class Servico {
    
    private $db;
    private $tabela = 'servicos';
    private $tabela_materiais = 'servico_materiais';
    
    /**
     * Construtor
     */
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Método: criar()
     * Responsabilidade: Criar novo serviço
     * Parâmetros: $dados (array com dados do serviço)
     * Retorna: int (ID do novo serviço) ou false
     * 
     * Campos obrigatórios: nome, valor_unitario, tempo_execucao
     * 
     * Exemplo:
     *   $dados = [
     *       'nome' => 'Instalação Ar Condicionado 12000 BTU',
     *       'descricao' => 'Instalação completa...',
     *       'valor_unitario' => 500,
     *       'tempo_execucao' => 240, // em minutos
     *       'exibir_cliente' => true
     *   ];
     *   $id = $servico->criar($dados);
     */
    public function criar($dados) {
        // Validações
        if (empty($dados['nome']) || empty($dados['valor_unitario']) || empty($dados['tempo_execucao'])) {
            return false;
        }
        
        // Prepara dados
        $dados_insert = [
            'nome' => trim($dados['nome']),
            'descricao' => $dados['descricao'] ?? null,
            'valor_unitario' => floatval($dados['valor_unitario']),
            'tempo_execucao' => intval($dados['tempo_execucao']), // em minutos
            'exibir_cliente' => $dados['exibir_cliente'] ?? true,
            'ativo' => true
        ];
        
        // Insere no banco
        $id = $this->db->insert($this->tabela, $dados_insert);
        
        return $id;
    }
    
    /**
     * Método: obter()
     * Responsabilidade: Obter dados completos de um serviço
     * Parâmetros: $id (int)
     * Retorna: array com dados do serviço ou false
     * 
     * Uso:
     *   $servico = $servico_obj->obter(123);
     */
    public function obter($id) {
        if (empty($id)) {
            return false;
        }
        
        return $this->db->selectOne($this->tabela, ['id' => $id]);
    }
    
    /**
     * Método: listar()
     * Responsabilidade: Listar serviços com filtros e paginação
     * Parâmetros:
     *   $filtro - array com filtros
     *   $limite - int com registros por página
     *   $pagina - int com número da página
     * Retorna: array com serviços
     * 
     * Filtros disponíveis:
     *   - nome: busca por nome
     *   - ativo: filtrar por status
     *   - exibir_cliente: apenas exibição para cliente
     */
    public function listar($filtro = [], $limite = REGISTROS_POR_PAGINA, $pagina = 1) {
        $where = [];
        
        // Filtro por nome
        if (!empty($filtro['nome'])) {
            $offset = ($pagina - 1) * $limite;
            
            return $this->db->select($this->tabela, 
                ['nome' => ['operador' => 'LIKE', 'valor' => '%' . $filtro['nome'] . '%']],
                'nome ASC',
                $limite,
                $offset
            );
        }
        
        // Filtros simples
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
     * Responsabilidade: Atualizar dados de um serviço
     * Parâmetros:
     *   $id - int com ID do serviço
     *   $dados - array com dados a atualizar
     * Retorna: bool (sucesso ou falha)
     * 
     * Uso:
     *   $sucesso = $servico_obj->atualizar(123, ['valor_unitario' => 600]);
     */
    public function atualizar($id, $dados) {
        if (empty($id) || empty($dados)) {
            return false;
        }
        
        // Campos permitidos
        $campos_permitidos = ['nome', 'descricao', 'valor_unitario', 'tempo_execucao', 'exibir_cliente', 'ativo'];
        
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
     * Responsabilidade: Deletar um serviço (soft delete)
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
     * Método: adicionar_material()
     * Responsabilidade: Adicionar material necessário para executar o serviço
     * Parâmetros:
     *   $servico_id - int com ID do serviço
     *   $produto_id - int com ID do produto (material)
     *   $quantidade - float com quantidade necessária
     *   $valor_unitario - float com valor do material
     * Retorna: int (ID do registro) ou false
     * 
     * Uso:
     *   $servico_obj->adicionar_material(123, 5, 2, 50);
     *   // Serviço 123 precisa de 2 unidades do produto 5, cada uma custando 50
     */
    public function adicionar_material($servico_id, $produto_id, $quantidade, $valor_unitario) {
        if (empty($servico_id) || empty($produto_id) || $quantidade <= 0 || $valor_unitario < 0) {
            return false;
        }
        
        // Verifica se já existe este material neste serviço
        $existe = $this->db->selectOne($this->tabela_materiais, 
            ['servico_id' => $servico_id, 'produto_id' => $produto_id]
        );
        
        if ($existe) {
            // Atualiza ao invés de inserir
            return $this->db->update($this->tabela_materiais,
                ['quantidade' => $quantidade, 'valor_unitario' => $valor_unitario],
                ['servico_id' => $servico_id, 'produto_id' => $produto_id]
            );
        }
        
        // Insere novo material
        return $this->db->insert($this->tabela_materiais, [
            'servico_id' => $servico_id,
            'produto_id' => $produto_id,
            'quantidade' => $quantidade,
            'valor_unitario' => $valor_unitario
        ]);
    }
    
    /**
     * Método: obter_materiais()
     * Responsabilidade: Obter todos os materiais necessários para um serviço
     * Parâmetros: $servico_id (int)
     * Retorna: array com materiais
     * 
     * Uso:
     *   $materiais = $servico_obj->obter_materiais(123);
     */
    public function obter_materiais($servico_id) {
        if (empty($servico_id)) {
            return [];
        }
        
        return $this->db->select($this->tabela_materiais, 
            ['servico_id' => $servico_id]
        );
    }
    
    /**
     * Método: calcular_custo_materiais()
     * Responsabilidade: Calcular custo total de materiais para um serviço
     * Parâmetros:
     *   $servico_id - int com ID do serviço
     *   $quantidade_servicos - int com quantidade de serviços (padrão: 1)
     * Retorna: float com custo total
     * 
     * Uso:
     *   $custo = $servico_obj->calcular_custo_materiais(123, 2);
     *   // Se serviço precisa de 1 un de material de 50, com 2 serviços = 100
     */
    public function calcular_custo_materiais($servico_id, $quantidade_servicos = 1) {
        if (empty($servico_id) || $quantidade_servicos <= 0) {
            return 0;
        }
        
        // Busca materiais do serviço
        $materiais = $this->obter_materiais($servico_id);
        
        $custo_total = 0;
        foreach ($materiais as $material) {
            // Custo = (quantidade por serviço * valor unitário) * quantidade de serviços
            $custo_material = ($material['quantidade'] * $material['valor_unitario']) * $quantidade_servicos;
            $custo_total += $custo_material;
        }
        
        return round($custo_total, 2);
    }
    
    /**
     * Método: calcular_lucro()
     * Responsabilidade: Calcular lucro estimado de um serviço
     * Parâmetros:
     *   $servico_id - int com ID do serviço
     *   $quantidade_servicos - int com quantidade (padrão: 1)
     * Retorna: float com lucro estimado
     * 
     * Uso:
     *   $lucro = $servico_obj->calcular_lucro(123, 2);
     *   // Lucro = (valor_unitario * quantidade) - custo_materiais
     */
    public function calcular_lucro($servico_id, $quantidade_servicos = 1) {
        if (empty($servico_id) || $quantidade_servicos <= 0) {
            return 0;
        }
        
        // Busca serviço
        $servico = $this->obter($servico_id);
        if (!$servico) {
            return 0;
        }
        
        // Valor total do serviço
        $valor_total = $servico['valor_unitario'] * $quantidade_servicos;
        
        // Custo de materiais
        $custo_materiais = $this->calcular_custo_materiais($servico_id, $quantidade_servicos);
        
        // Lucro = valor total - custo materiais
        return round($valor_total - $custo_materiais, 2);
    }
    
    /**
     * Método: deletar_material()
     * Responsabilidade: Remover um material de um serviço
     * Parâmetros: $material_id (int)
     * Retorna: bool
     */
    public function deletar_material($material_id) {
        if (empty($material_id)) {
            return false;
        }
        
        return $this->db->delete($this->tabela_materiais, ['id' => $material_id]);
    }
    
    /**
     * Método: adicionar_foto()
     * Responsabilidade: Fazer upload de foto do serviço
     * Parâmetros:
     *   $id - int com ID do serviço
     *   $arquivo - array $_FILES com foto
     * Retorna: string (caminho da foto) ou false
     */
    public function adicionar_foto($id, $arquivo) {
        if (empty($id) || empty($arquivo)) {
            return false;
        }
        
        $upload = new Upload();
        $upload->definir_diretorio(DIR_UPLOADS . '/servicos');
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
     * Responsabilidade: Contar total de serviços
     * Parâmetros: $filtro (array opcional)
     * Retorna: int
     */
    public function contar($filtro = []) {
        $where = [];
        
        if (isset($filtro['ativo'])) {
            $where['ativo'] = $filtro['ativo'];
        }
        
        return $this->db->count($this->tabela, $where);
    }
    
    /**
     * Método: obter_disponiveis()
     * Responsabilidade: Obter serviços disponíveis para clientes
     * Parâmetros: none
     * Retorna: array com serviços visíveis para clientes
     * 
     * Uso:
     *   $servicos_cliente = $servico_obj->obter_disponiveis();
     */
    public function obter_disponiveis() {
        return $this->db->select($this->tabela, 
            ['ativo' => true, 'exibir_cliente' => true],
            'nome ASC'
        );
    }
}

?>
