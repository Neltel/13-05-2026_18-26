<?php
/**
 * =====================================================
 * CLASSE CLIENTE - Gerenciar Clientes
 * =====================================================
 * 
 * Responsabilidade: CRUD de clientes e suas informações
 * Uso: $cliente = new Cliente(); $cliente->criar(...);
 * Recebe: Dados de cliente (nome, email, telefone, etc)
 * Retorna: Arrays com informações dos clientes
 * 
 * Operações:
 * - Criar novo cliente
 * - Buscar cliente por ID
 * - Listar clientes com filtros
 * - Atualizar dados de cliente
 * - Deletar cliente
 * - Adicionar anotações
 * - Upload de documentos e fotos
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Upload.php';

class Cliente {
    
    private $db;
    private $tabela = 'clientes';
    private $tabela_anotacoes = 'cliente_anotacoes';
    
    /**
     * Construtor
     * Responsabilidade: Inicializar instância com banco de dados
     */
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Método: criar()
     * Responsabilidade: Criar novo cliente
     * Parâmetros: $dados (array associativo com dados do cliente)
     * Retorna: int (ID do novo cliente) ou false em caso de erro
     * 
     * Campos obrigatórios: nome, pessoa_tipo, cpf_cnpj
     * 
     * Exemplo:
     *   $dados = [
     *       'nome' => 'João Silva',
     *       'pessoa_tipo' => 'fisica',
     *       'cpf_cnpj' => '12345678901',
     *       'telefone' => '11999999999',
     *       'email' => 'joao@email.com',
     *       'endereco_rua' => 'Rua A',
     *       'endereco_numero' => '100',
     *       'endereco_bairro' => 'Centro',
     *       'endereco_cidade' => 'São Paulo',
     *       'endereco_estado' => 'SP',
     *       'endereco_cep' => '01310100'
     *   ];
     *   $id = $cliente->criar($dados);
     */
    public function criar($dados) {
        // Validações básicas
        if (empty($dados['nome']) || empty($dados['cpf_cnpj'])) {
            return false;
        }
        
        // Valida CPF/CNPJ
        if (!$this->validar_cpf_cnpj($dados['cpf_cnpj'])) {
            return false;
        }
        
        // Verifica se CPF/CNPJ já existe
        $existe = $this->db->selectOne($this->tabela, ['cpf_cnpj' => $dados['cpf_cnpj']]);
        if ($existe) {
            return false; // Cliente já cadastrado
        }
        
        // Prepara dados para inserção
        $dados_insert = [
            'nome' => trim($dados['nome']),
            'pessoa_tipo' => $dados['pessoa_tipo'] ?? 'fisica',
            'cpf_cnpj' => preg_replace('/\D/', '', $dados['cpf_cnpj']),
            'telefone' => $dados['telefone'] ?? null,
            'whatsapp' => $dados['whatsapp'] ?? null,
            'email' => $dados['email'] ?? null,
            'endereco_rua' => $dados['endereco_rua'] ?? null,
            'endereco_numero' => $dados['endereco_numero'] ?? null,
            'endereco_complemento' => $dados['endereco_complemento'] ?? null,
            'endereco_bairro' => $dados['endereco_bairro'] ?? null,
            'endereco_cidade' => $dados['endereco_cidade'] ?? null,
            'endereco_estado' => $dados['endereco_estado'] ?? null,
            'endereco_cep' => preg_replace('/\D/', '', $dados['endereco_cep'] ?? ''),
            'ativo' => true
        ];
        
        // Se houver usuario_id (cliente logado criando seu próprio perfil)
        if (!empty($dados['usuario_id'])) {
            $dados_insert['usuario_id'] = $dados['usuario_id'];
        }
        
        // Insere no banco
        $id = $this->db->insert($this->tabela, $dados_insert);
        
        return $id;
    }
    
    /**
     * Método: obter()
     * Responsabilidade: Obter dados completos de um cliente
     * Parâmetros: $id (int com ID do cliente)
     * Retorna: array com dados do cliente ou false
     * 
     * Uso:
     *   $cliente = $cliente_obj->obter(123);
     *   echo $cliente['nome'];
     */
    public function obter($id) {
        if (empty($id)) {
            return false;
        }
        
        return $this->db->selectOne($this->tabela, ['id' => $id]);
    }
    
    /**
     * Método: listar()
     * Responsabilidade: Listar clientes com filtros e paginação
     * Parâmetros:
     *   $filtro - array com filtros (opcional)
     *   $limite - int com registros por página (padrão: 50)
     *   $pagina - int com número da página (padrão: 1)
     * Retorna: array com clientes
     * 
     * Filtros disponíveis:
     *   - nome: busca por nome (LIKE)
     *   - ativo: filtrar por status
     *   - pessoa_tipo: 'fisica' ou 'juridica'
     * 
     * Uso:
     *   $clientes = $cliente_obj->listar(['ativo' => true], 20, 1);
     */
    public function listar($filtro = [], $limite = REGISTROS_POR_PAGINA, $pagina = 1) {
        // Prepara filtros WHERE
        $where = [];
        
        // Filtro por nome (busca parcial)
        if (!empty($filtro['nome'])) {
            // Para LIKE, precisa fazer query customizada
            $sql = "SELECT * FROM {$this->tabela} WHERE nome LIKE ?";
            $offset = ($pagina - 1) * $limite;
            $sql .= " ORDER BY nome ASC LIMIT {$limite} OFFSET {$offset}";
            
            $stmt = $this->db->select($this->tabela, 
                ['nome' => ['operador' => 'LIKE', 'valor' => '%' . $filtro['nome'] . '%']], 
                'nome ASC', 
                $limite, 
                ($pagina - 1) * $limite
            );
            
            return $stmt;
        }
        
        // Filtros simples
        if (isset($filtro['ativo'])) {
            $where['ativo'] = $filtro['ativo'];
        }
        
        if (!empty($filtro['pessoa_tipo'])) {
            $where['pessoa_tipo'] = $filtro['pessoa_tipo'];
        }
        
        // Calcula offset para paginação
        $offset = ($pagina - 1) * $limite;
        
        // Busca clientes
        return $this->db->select($this->tabela, $where, 'nome ASC', $limite, $offset);
    }
    
    /**
     * Método: atualizar()
     * Responsabilidade: Atualizar dados de um cliente
     * Parâmetros:
     *   $id - int com ID do cliente
     *   $dados - array com dados a atualizar
     * Retorna: bool (sucesso ou falha)
     * 
     * Uso:
     *   $sucesso = $cliente_obj->atualizar(123, ['nome' => 'Novo Nome']);
     */
    public function atualizar($id, $dados) {
        if (empty($id) || empty($dados)) {
            return false;
        }
        
        // Valida CPF/CNPJ se for atualizar
        if (!empty($dados['cpf_cnpj']) && !$this->validar_cpf_cnpj($dados['cpf_cnpj'])) {
            return false;
        }
        
        // Verifica se CPF/CNPJ já existe em outro cliente
        if (!empty($dados['cpf_cnpj'])) {
            $existe = $this->db->selectOne($this->tabela, ['cpf_cnpj' => $dados['cpf_cnpj']]);
            if ($existe && $existe['id'] != $id) {
                return false;
            }
        }
        
        // Prepara dados
        $dados_update = [];
        foreach ($dados as $campo => $valor) {
            if (in_array($campo, ['nome', 'pessoa_tipo', 'cpf_cnpj', 'telefone', 'whatsapp', 'email',
                                  'endereco_rua', 'endereco_numero', 'endereco_complemento', 
                                  'endereco_bairro', 'endereco_cidade', 'endereco_estado', 
                                  'endereco_cep', 'ativo'])) {
                $dados_update[$campo] = $valor;
            }
        }
        
        if (empty($dados_update)) {
            return false;
        }
        
        // Atualiza no banco
        return $this->db->update($this->tabela, $dados_update, ['id' => $id]);
    }
    
    /**
     * Método: deletar()
     * Responsabilidade: Deletar um cliente (soft delete - marca como inativo)
     * Parâmetros: $id (int com ID do cliente)
     * Retorna: bool (sucesso ou falha)
     * 
     * Nota: Usa soft delete (ativo = false) para manter integridade referencial
     * 
     * Uso:
     *   $cliente_obj->deletar(123);
     */
    public function deletar($id) {
        if (empty($id)) {
            return false;
        }
        
        // Soft delete - apenas marca como inativo
        return $this->db->update($this->tabela, 
            ['ativo' => false], 
            ['id' => $id]
        );
    }
    
    /**
     * Método: contar()
     * Responsabilidade: Contar total de clientes
     * Parâmetros: $filtro (array opcional)
     * Retorna: int com quantidade
     * 
     * Uso:
     *   $total = $cliente_obj->contar(['ativo' => true]);
     */
    public function contar($filtro = []) {
        $where = [];
        
        if (isset($filtro['ativo'])) {
            $where['ativo'] = $filtro['ativo'];
        }
        
        return $this->db->count($this->tabela, $where);
    }
    
    /**
     * Método: buscar()
     * Responsabilidade: Buscar cliente por CPF/CNPJ ou email
     * Parâmetros: $valor (CPF/CNPJ/email)
     * Retorna: array com dados do cliente ou false
     * 
     * Uso:
     *   $cliente = $cliente_obj->buscar('12345678901');
     */
    public function buscar($valor) {
        if (empty($valor)) {
            return false;
        }
        
        // Tenta buscar por CPF/CNPJ
        $valor_limpo = preg_replace('/\D/', '', $valor);
        
        $cliente = $this->db->selectOne($this->tabela, ['cpf_cnpj' => $valor_limpo]);
        
        // Se não encontrou, tenta por email
        if (!$cliente) {
            $cliente = $this->db->selectOne($this->tabela, ['email' => $valor]);
        }
        
        return $cliente;
    }
    
    /**
     * Método: adicionar_foto()
     * Responsabilidade: Fazer upload de foto do cliente
     * Parâmetros:
     *   $id - int com ID do cliente
     *   $arquivo - array $_FILES com foto
     * Retorna: string (caminho da foto) ou false
     * 
     * Uso:
     *   $foto = $cliente_obj->adicionar_foto(123, $_FILES['foto']);
     */
    public function adicionar_foto($id, $arquivo) {
        if (empty($id) || empty($arquivo)) {
            return false;
        }
        
        // Usa classe Upload para gerenciar arquivo
        $upload = new Upload();
        $upload->definir_diretorio(DIR_UPLOADS . '/clientes');
        $upload->definir_tipos_permitidos(ALLOWED_IMAGE_TYPES);
        
        // Faz upload
        $resultado = $upload->enviar($arquivo);
        
        if (!$resultado) {
            return false;
        }
        
        // Atualiza caminho no banco
        $this->db->update($this->tabela, 
            ['foto_url' => $resultado], 
            ['id' => $id]
        );
        
        return $resultado;
    }
    
    /**
     * Método: adicionar_documento()
     * Responsabilidade: Fazer upload de documento do cliente (RG, CNH, etc)
     * Parâmetros:
     *   $id - int com ID do cliente
     *   $arquivo - array $_FILES com documento
     * Retorna: string (caminho do documento) ou false
     */
    public function adicionar_documento($id, $arquivo) {
        if (empty($id) || empty($arquivo)) {
            return false;
        }
        
        // Tipos permitidos: imagens e PDF
        $tipos = array_merge(ALLOWED_IMAGE_TYPES, ALLOWED_PDF_TYPES);
        
        $upload = new Upload();
        $upload->definir_diretorio(DIR_UPLOADS . '/clientes');
        $upload->definir_tipos_permitidos($tipos);
        
        $resultado = $upload->enviar($arquivo);
        
        if (!$resultado) {
            return false;
        }
        
        // Atualiza no banco
        $this->db->update($this->tabela, 
            ['documento_url' => $resultado], 
            ['id' => $id]
        );
        
        return $resultado;
    }
    
    /**
     * Método: adicionar_anotacao()
     * Responsabilidade: Adicionar anotação/observação sobre cliente
     * Parâmetros:
     *   $cliente_id - int com ID do cliente
     *   $titulo - string com título da anotação
     *   $descricao - string com descrição/conteúdo
     *   $foto - array $_FILES (opcional)
     * Retorna: int (ID da anotação) ou false
     * 
     * Uso:
     *   $anotacao_id = $cliente_obj->adicionar_anotacao(123, 'Problema no AC', 'Máquina barulhenta...');
     */
    public function adicionar_anotacao($cliente_id, $titulo, $descricao, $foto = null) {
        if (empty($cliente_id) || empty($titulo) || empty($descricao)) {
            return false;
        }
        
        // Processa foto se houver
        $foto_url = null;
        if (!empty($foto)) {
            $upload = new Upload();
            $upload->definir_diretorio(DIR_UPLOADS . '/clientes');
            $upload->definir_tipos_permitidos(ALLOWED_IMAGE_TYPES);
            
            $foto_url = $upload->enviar($foto);
        }
        
        // Insere anotação
        $id = $this->db->insert($this->tabela_anotacoes, [
            'cliente_id' => $cliente_id,
            'titulo' => $titulo,
            'descricao' => $descricao,
            'foto_url' => $foto_url
        ]);
        
        return $id;
    }
    
    /**
     * Método: obter_anotacoes()
     * Responsabilidade: Obter todas as anotações de um cliente
     * Parâmetros: $cliente_id (int)
     * Retorna: array com anotações
     * 
     * Uso:
     *   $anotacoes = $cliente_obj->obter_anotacoes(123);
     */
    public function obter_anotacoes($cliente_id) {
        if (empty($cliente_id)) {
            return [];
        }
        
        return $this->db->select($this->tabela_anotacoes, 
            ['cliente_id' => $cliente_id],
            'created_at DESC'
        );
    }
    
    /**
     * Método: deletar_anotacao()
     * Responsabilidade: Deletar uma anotação
     * Parâmetros: $anotacao_id (int)
     * Retorna: bool (sucesso ou falha)
     */
    public function deletar_anotacao($anotacao_id) {
        if (empty($anotacao_id)) {
            return false;
        }
        
        return $this->db->delete($this->tabela_anotacoes, ['id' => $anotacao_id]);
    }
    
    /**
     * Método PRIVADO: validar_cpf_cnpj()
     * Responsabilidade: Validar CPF ou CNPJ
     * Parâmetros: $valor (string com CPF ou CNPJ)
     * Retorna: bool (válido ou inválido)
     * 
     * Algoritmo: Calcula dígito verificador
     */
    private function validar_cpf_cnpj($valor) {
        // Remove caracteres não numéricos
        $valor = preg_replace('/\D/', '', $valor);
        
        // Se tem 11 dígitos, é CPF
        if (strlen($valor) == 11) {
            return $this->validar_cpf($valor);
        }
        // Se tem 14 dígitos, é CNPJ
        elseif (strlen($valor) == 14) {
            return $this->validar_cnpj($valor);
        }
        
        return false;
    }
    
    /**
     * Método PRIVADO: validar_cpf()
     * Responsabilidade: Validar CPF
     * Parâmetros: $cpf (string com 11 dígitos)
     * Retorna: bool
     */
    private function validar_cpf($cpf) {
        // Verifica se todos os dígitos são iguais
        if (preg_match('/(\d)\1{10}/', $cpf)) {
            return false;
        }
        
        // Calcula primeiro dígito verificador
        $soma = 0;
        for ($i = 0; $i < 9; $i++) {
            $soma += intval($cpf[$i]) * (10 - $i);
        }
        $digito1 = 11 - ($soma % 11);
        if ($digito1 > 9) $digito1 = 0;
        
        if (intval($cpf[9]) != $digito1) {
            return false;
        }
        
        // Calcula segundo dígito verificador
        $soma = 0;
        for ($i = 0; $i < 10; $i++) {
            $soma += intval($cpf[$i]) * (11 - $i);
        }
        $digito2 = 11 - ($soma % 11);
        if ($digito2 > 9) $digito2 = 0;
        
        if (intval($cpf[10]) != $digito2) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Método PRIVADO: validar_cnpj()
     * Responsabilidade: Validar CNPJ
     * Parâmetros: $cnpj (string com 14 dígitos)
     * Retorna: bool
     */
    private function validar_cnpj($cnpj) {
        // Verifica se todos os dígitos são iguais
        if (preg_match('/(\d)\1{13}/', $cnpj)) {
            return false;
        }
        
        // Calcula primeiro dígito verificador
        $tamanho = strlen($cnpj) - 2;
        $numeros = substr($cnpj, 0, $tamanho);
        $digitos = substr($cnpj, $tamanho);
        
        $soma = 0;
        $pos = $tamanho - 7;
        for ($i = $tamanho; $i >= 1; $i--) {
            $soma += $numeros[$tamanho - $i] * $pos;
            $pos--;
            if ($pos < 2) {
                $pos = 9;
            }
        }
        
        $resultado = $soma % 11 < 2 ? 0 : 11 - $soma % 11;
        if ($resultado != $digitos[0]) {
            return false;
        }
        
        // Calcula segundo dígito verificador
        $tamanho = strlen($cnpj) - 1;
        $numeros = substr($cnpj, 0, $tamanho);
        $digitos = substr($cnpj, $tamanho);
        
        $soma = 0;
        $pos = $tamanho - 7;
        for ($i = $tamanho; $i >= 1; $i--) {
            $soma += $numeros[$tamanho - $i] * $pos;
            $pos--;
            if ($pos < 2) {
                $pos = 9;
            }
        }
        
        $resultado = $soma % 11 < 2 ? 0 : 11 - $soma % 11;
        if ($resultado != $digitos[0]) {
            return false;
        }
        
        return true;
    }
}

?>
