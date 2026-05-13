<?php
/**
 * =====================================================
 * CLASSE DATABASE - Gerenciador do Banco de Dados
 * =====================================================
 * 
 * Responsabilidade: Encapsular operações de banco de dados
 * Uso: $db = new Database(); $db->query(...)
 * Recebe: Conexão MySQLi
 * Retorna: Arrays de resultados
 * 
 * Padrão: Singleton (uma única instância em toda aplicação)
 */

require_once __DIR__ . '/../config/database.php';

class Database {
    
    // Instância singleton
    private static $instancia = null;
    
    // Conexão MySQLi
    private $conexao;
    
    /**
     * Construtor privado (padrão singleton)
     * Responsabilidade: Inicializar conexão com banco
     * Parâmetros: none
     * Retorna: void
     */
    private function __construct() {
        global $conexao;
        $this->conexao = $conexao;
    }
    
    /**
     * Método: getInstance()
     * Responsabilidade: Obter instância única da classe
     * Parâmetros: none
     * Retorna: Database (instância única)
     * 
     * Uso:
     *   $db = Database::getInstance();
     */
    public static function getInstance() {
        // Se ainda não existe instância, cria
        if (self::$instancia === null) {
            self::$instancia = new self();
        }
        
        return self::$instancia;
    }
    
    /**
     * Método: insert()
     * Responsabilidade: Inserir novo registro
     * Parâmetros: 
     *   $tabela - nome da tabela
     *   $dados - array associativo [coluna => valor]
     * Retorna: int (ID inserido) ou false
     * 
     * Uso:
     *   $id = $db->insert('clientes', [
     *       'nome' => 'João Silva',
     *       'email' => 'joao@email.com'
     *   ]);
     */
    public function insert($tabela, $dados) {
        // Validação básica
        if (empty($dados) || !is_array($dados)) {
            registrar_log_erro("Insert inválido em {$tabela}");
            return false;
        }
        
        // Extrai nomes das colunas
        $colunas = array_keys($dados);
        $colunas_str = implode(', ', $colunas);
        
        // Cria placeholders (?)
        $placeholders = implode(', ', array_fill(0, count($dados), '?'));
        
        // Extrai valores
        $valores = array_values($dados);
        
        // Cria tipos para bind
        $tipos = $this->obter_tipos($valores);
        
        // Monta SQL
        $sql = "INSERT INTO {$tabela} ({$colunas_str}) VALUES ({$placeholders})";
        
        // Executa
        $stmt = $this->conexao->prepare($sql);
        if (!$stmt) {
            registrar_log_erro("Erro prepare: " . $this->conexao->error);
            return false;
        }
        
        // Bind dos parâmetros
        $bind_params = [$tipos];
        foreach ($valores as &$valor) {
            $bind_params[] = &$valor;
        }
        call_user_func_array([$stmt, 'bind_param'], $bind_params);
        
        // Executa
        if (!$stmt->execute()) {
            registrar_log_erro("Erro execute: " . $stmt->error);
            $stmt->close();
            return false;
        }
        
        // Obtém ID inserido
        $id = $stmt->insert_id;
        $stmt->close();
        
        return $id;
    }
    
    /**
     * Método: update()
     * Responsabilidade: Atualizar registro existente
     * Parâmetros:
     *   $tabela - nome da tabela
     *   $dados - array com dados a atualizar
     *   $condicao - array com condição WHERE [coluna => valor]
     * Retorna: bool (sucesso ou falha)
     * 
     * Uso:
     *   $db->update('clientes', 
     *       ['nome' => 'João Silva'],
     *       ['id' => 123]
     *   );
     */
    public function update($tabela, $dados, $condicao) {
        // Validação
        if (empty($dados) || empty($condicao)) {
            return false;
        }
        
        // Monta SET
        $set_parts = [];
        $valores = [];
        
        foreach ($dados as $coluna => $valor) {
            $set_parts[] = "{$coluna} = ?";
            $valores[] = $valor;
        }
        $set_str = implode(', ', $set_parts);
        
        // Monta WHERE
        $where_parts = [];
        foreach ($condicao as $coluna => $valor) {
            $where_parts[] = "{$coluna} = ?";
            $valores[] = $valor;
        }
        $where_str = implode(' AND ', $where_parts);
        
        // Tipos para bind
        $tipos = $this->obter_tipos($valores);
        
        // Monta SQL
        $sql = "UPDATE {$tabela} SET {$set_str} WHERE {$where_str}";
        
        // Prepara e executa
        $stmt = $this->conexao->prepare($sql);
        if (!$stmt) {
            registrar_log_erro("Erro prepare update: " . $this->conexao->error);
            return false;
        }
        
        // Bind
        $bind_params = [$tipos];
        foreach ($valores as &$valor) {
            $bind_params[] = &$valor;
        }
        call_user_func_array([$stmt, 'bind_param'], $bind_params);
        
        // Executa
        if (!$stmt->execute()) {
            registrar_log_erro("Erro execute update: " . $stmt->error);
            $stmt->close();
            return false;
        }
        
        $stmt->close();
        return true;
    }
    
    /**
     * Método: delete()
     * Responsabilidade: Deletar registro
     * Parâmetros:
     *   $tabela - nome da tabela
     *   $condicao - array com condição WHERE
     * Retorna: bool (sucesso ou falha)
     * 
     * Uso:
     *   $db->delete('clientes', ['id' => 123]);
     */
    public function delete($tabela, $condicao) {
        if (empty($condicao)) {
            registrar_log_erro("Delete sem condição em {$tabela}");
            return false;
        }
        
        // Monta WHERE
        $where_parts = [];
        $valores = [];
        
        foreach ($condicao as $coluna => $valor) {
            $where_parts[] = "{$coluna} = ?";
            $valores[] = $valor;
        }
        $where_str = implode(' AND ', $where_parts);
        
        // Tipos
        $tipos = $this->obter_tipos($valores);
        
        // SQL
        $sql = "DELETE FROM {$tabela} WHERE {$where_str}";
        
        // Prepara e executa
        $stmt = $this->conexao->prepare($sql);
        if (!$stmt) {
            registrar_log_erro("Erro prepare delete: " . $this->conexao->error);
            return false;
        }
        
        // Bind
        $bind_params = [$tipos];
        foreach ($valores as &$valor) {
            $bind_params[] = &$valor;
        }
        call_user_func_array([$stmt, 'bind_param'], $bind_params);
        
        // Executa
        if (!$stmt->execute()) {
            registrar_log_erro("Erro execute delete: " . $stmt->error);
            $stmt->close();
            return false;
        }
        
        $stmt->close();
        return true;
    }
    
    /**
     * Método: select()
     * Responsabilidade: Obter registros com filtros avançados
     * Parâmetros:
     *   $tabela - nome da tabela
     *   $where - array com filtros (opcional)
     *   $ordem - string com ORDER BY (opcional)
     *   $limite - int com limite de registros (opcional)
     *   $offset - int para paginação (opcional)
     * Retorna: array de resultados
     * 
     * Uso:
     *   $clientes = $db->select('clientes', 
     *       ['ativo' => 1],
     *       'nome ASC',
     *       10,
     *       0
     *   );
     */
    public function select($tabela, $where = [], $ordem = 'id DESC', $limite = null, $offset = 0) {
        // Monta SQL base
        $sql = "SELECT * FROM {$tabela}";
        
        // Adiciona WHERE
        if (!empty($where)) {
            $where_parts = [];
            $valores = [];
            
            foreach ($where as $coluna => $valor) {
                // Suporta operadores
                if (is_array($valor)) {
                    $operador = $valor['operador'] ?? '=';
                    $valor_real = $valor['valor'];
                    $where_parts[] = "{$coluna} {$operador} ?";
                    $valores[] = $valor_real;
                } else {
                    $where_parts[] = "{$coluna} = ?";
                    $valores[] = $valor;
                }
            }
            
            $where_str = implode(' AND ', $where_parts);
            $sql .= " WHERE {$where_str}";
        }
        
        // Adiciona ORDER BY
        $sql .= " ORDER BY {$ordem}";
        
        // Adiciona LIMIT
        if ($limite !== null) {
            $sql .= " LIMIT {$limite} OFFSET {$offset}";
        }
        
        // Executa
        if (empty($where)) {
            $resultado = $this->conexao->query($sql);
        } else {
            $tipos = $this->obter_tipos($valores);
            $stmt = $this->conexao->prepare($sql);
            
            if (!$stmt) {
                registrar_log_erro("Erro prepare select: " . $this->conexao->error);
                return [];
            }
            
            $bind_params = [$tipos];
            foreach ($valores as &$valor) {
                $bind_params[] = &$valor;
            }
            call_user_func_array([$stmt, 'bind_param'], $bind_params);
            $stmt->execute();
            $resultado = $stmt->get_result();
            $stmt->close();
        }
        
        if (!$resultado) {
            registrar_log_erro("Erro select: " . $this->conexao->error);
            return [];
        }
        
        // Coleta resultados
        $registros = [];
        while ($linha = $resultado->fetch_assoc()) {
            $registros[] = $linha;
        }
        
        return $registros;
    }
    
    /**
     * Método: selectOne()
     * Responsabilidade: Obter um único registro
     * Parâmetros: mesmo que select()
     * Retorna: array com um registro ou false
     * 
     * Uso:
     *   $cliente = $db->selectOne('clientes', ['id' => 123]);
     */
    public function selectOne($tabela, $where = []) {
        $resultados = $this->select($tabela, $where, 'id DESC', 1);
        return !empty($resultados) ? $resultados[0] : false;
    }
    
    /**
     * Método: count()
     * Responsabilidade: Contar registros
     * Parâmetros: $tabela, $where (opcional)
     * Retorna: int com quantidade
     * 
     * Uso:
     *   $total = $db->count('clientes', ['ativo' => 1]);
     */
    public function count($tabela, $where = []) {
        $sql = "SELECT COUNT(*) as total FROM {$tabela}";
        $valores = [];
        
        if (!empty($where)) {
            $where_parts = [];
            foreach ($where as $coluna => $valor) {
                $where_parts[] = "{$coluna} = ?";
                $valores[] = $valor;
            }
            $sql .= " WHERE " . implode(' AND ', $where_parts);
        }
        
        if (empty($valores)) {
            $resultado = $this->conexao->query($sql);
        } else {
            $tipos = $this->obter_tipos($valores);
            $stmt = $this->conexao->prepare($sql);
            
            if (!$stmt) {
                return 0;
            }
            
            $bind_params = [$tipos];
            foreach ($valores as &$valor) {
                $bind_params[] = &$valor;
            }
            call_user_func_array([$stmt, 'bind_param'], $bind_params);
            $stmt->execute();
            $resultado = $stmt->get_result();
            $stmt->close();
        }
        
        $linha = $resultado->fetch_assoc();
        return (int)$linha['total'];
    }
    
    /**
     * Método: lastInsertId()
     * Responsabilidade: Obter último ID inserido
     * Parâmetros: none
     * Retorna: int
     * 
     * Uso:
     *   $id = $db->lastInsertId();
     */
    public function lastInsertId() {
        return $this->conexao->insert_id;
    }
    
    /**
     * Método: beginTransaction()
     * Responsabilidade: Iniciar transação (para múltiplas operações)
     * Parâmetros: none
     * Retorna: bool
     * 
     * Uso:
     *   $db->beginTransaction();
     *   $db->insert(...);
     *   $db->update(...);
     *   $db->commit();
     */
    public function beginTransaction() {
        return $this->conexao->begin_transaction();
    }
    
    /**
     * Método: commit()
     * Responsabilidade: Confirmar transação
     * Parâmetros: none
     * Retorna: bool
     */
    public function commit() {
        return $this->conexao->commit();
    }
    
    /**
     * Método: rollback()
     * Responsabilidade: Desfazer transação
     * Parâmetros: none
     * Retorna: bool
     */
    public function rollback() {
        return $this->conexao->rollback();
    }
    
    /**
     * Método: getError()
     * Responsabilidade: Obter última mensagem de erro
     * Parâmetros: none
     * Retorna: string
     */
    public function getError() {
        return $this->conexao->error;
    }
    
    /**
     * Método PRIVADO: obter_tipos()
     * Responsabilidade: Determinar tipos de dados para bind
     * Parâmetros: $valores (array)
     * Retorna: string com tipos (i, s, d, b)
     * Uso interno apenas
     */
    private function obter_tipos($valores) {
        $tipos = '';
        foreach ($valores as $valor) {
            if (is_int($valor)) {
                $tipos .= 'i';
            } elseif (is_float($valor)) {
                $tipos .= 'd';
            } elseif (is_string($valor)) {
                $tipos .= 's';
            } else {
                $tipos .= 'b'; // blob
            }
        }
        return $tipos;
    }
    
    /**
     * Método: escape()
     * Responsabilidade: Escapar string para SQL (método alternativo)
     * Parâmetros: $string
     * Retorna: string escapada
     */
    public function escape($string) {
        return $this->conexao->real_escape_string($string);
    }
}

?>
