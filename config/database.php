<?php
/**
 * =====================================================
 * CONEXÃO COM BANCO DE DADOS
 * =====================================================
 * 
 * Responsabilidade: Gerenciar conexão com MySQL/MariaDB
 * Uso: Incluir em todos os arquivos que precisam banco de dados
 * Recebe: Constantes definidas em environment.php
 * Retorna: Conexão MySQLi pronta para uso
 * 
 * Credenciais atualizadas para:
 * - Host: localhost
 * - Banco: nmrefrig_imperioar
 * - Usuário: nmrefrig_imperioar
 * - Senha: 6UfKMXQv6durXm9ZfRyv
 * 
 * Exemplo de uso:
 *   require_once __DIR__ . '/../config/database.php';
 *   $resultado = $conexao->query("SELECT * FROM clientes");
 */

// Evita múltiplas inclusões
if (defined('DB_CONNECTED')) {
    return;
}
define('DB_CONNECTED', true);

// Carrega variáveis de ambiente
require_once __DIR__ . '/environment.php';

/**
 * Função: testar_conexao_banco
 * Responsabilidade: Verificar se consegue conectar ao banco de dados
 * Parâmetros: $host, $user, $pass, $db
 * Retorna: boolean (true se conectado, false se erro)
 */
function testar_conexao_banco($host, $user, $pass, $db) {
    try {
        // Tenta conectar via MySQLi
        $conexao = new mysqli($host, $user, $pass, $db);
        
        // Verifica se houve erro
        if ($conexao->connect_error) {
            error_log("Erro de conexão ao banco: " . $conexao->connect_error);
            return false;
        }
        
        // Define charset para UTF-8
        $conexao->set_charset("utf8mb4");
        
        // Fecha conexão
        $conexao->close();
        
        return true;
    } catch (Exception $e) {
        error_log("Exceção ao testar conexão: " . $e->getMessage());
        return false;
    }
}

/**
 * Função: registrar_log_erro
 * Responsabilidade: Registrar erros em arquivo de log
 * Parâmetros: $mensagem (string com o erro)
 * Retorna: boolean (sucesso ou falha)
 * Arquivo: /logs/errors.log
 */
function registrar_log_erro($mensagem) {
    // Define caminho do arquivo de log
    $arquivo_log = __DIR__ . '/../logs/errors.log';
    
    // Cria diretório de logs se não existir
    if (!is_dir(dirname($arquivo_log))) {
        mkdir(dirname($arquivo_log), 0755, true);
    }
    
    // Formata mensagem com data e hora
    $timestamp = date('Y-m-d H:i:s');
    $mensagem_formatada = "[{$timestamp}] {$mensagem}\n";
    
    // Escreve no arquivo de log
    return file_put_contents($arquivo_log, $mensagem_formatada, FILE_APPEND);
}

/**
 * Função: preparar_query
 * Responsabilidade: Proteger contra SQL Injection usando prepared statements
 * Parâmetros: $conexao (mysqli), $sql (string SQL com placeholders ?), $tipos (string de tipos), $parametros (valores)
 * Retorna: resultado da query
 * Exemplo: preparar_query($conexao, "SELECT * FROM clientes WHERE id = ?", "i", [123])
 * 
 * Tipos de dados:
 *   i = integer
 *   d = double
 *   s = string
 *   b = blob
 */
function preparar_query($conexao, $sql, $tipos, $parametros) {
    try {
        // Prepara statement (evita SQL Injection)
        $stmt = $conexao->prepare($sql);
        
        if (!$stmt) {
            throw new Exception("Erro na preparação: " . $conexao->error);
        }
        
        // Se houver parâmetros, faz bind (liga os valores)
        if (!empty($parametros)) {
            // Cria array para bind_param (precisa de referências)
            $bind_params = [$tipos];
            foreach ($parametros as &$param) {
                $bind_params[] = &$param;
            }
            
            // Executa bind dos parâmetros
            call_user_func_array([$stmt, 'bind_param'], $bind_params);
        }
        
        // Executa a query
        if (!$stmt->execute()) {
            throw new Exception("Erro na execução: " . $stmt->error);
        }
        
        return $stmt;
    } catch (Exception $e) {
        registrar_log_erro($e->getMessage());
        return false;
    }
}

// ===== INICIALIZA CONEXÃO GLOBAL =====

// Conecta ao banco de dados
global $conexao;

try {
    $conexao = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    // Verifica se houve erro na conexão
    if ($conexao->connect_error) {
        $erro = "Erro ao conectar ao banco: " . $conexao->connect_error;
        registrar_log_erro($erro);
        
        // Em produção, não mostra erro sensível
        if (!DEBUG_MODE) {
            die("Erro ao conectar ao sistema. Contate o administrador.");
        } else {
            die("ERRO: " . $erro);
        }
    }
    
    // Define charset UTF-8
    $conexao->set_charset("utf8mb4");
    
    // Define timezone para Brasil
    $conexao->query("SET time_zone = '-03:00'");
    
} catch (Exception $e) {
    registrar_log_erro("Exceção ao conectar: " . $e->getMessage());
    die("Erro ao conectar ao sistema.");
}

/**
 * Funções auxiliares para queries comuns
 */

/**
 * Função: obter_um_registro
 * Responsabilidade: Obter um único registro do banco
 * Parâmetros: $conexao, $tabela, $condicao (ex: "id = ?"), $tipos, $parametros
 * Retorna: array com dados ou false
 * Exemplo: obter_um_registro($conexao, 'clientes', 'id = ?', 'i', [123])
 */
function obter_um_registro($conexao, $tabela, $condicao = '', $tipos = '', $parametros = []) {
    // Monta SQL básico
    $sql = "SELECT * FROM {$tabela}";
    
    // Adiciona WHERE se houver condição
    if (!empty($condicao)) {
        $sql .= " WHERE {$condicao}";
    }
    
    // Executa query preparada
    $stmt = preparar_query($conexao, $sql, $tipos, $parametros);
    
    if (!$stmt) {
        return false;
    }
    
    // Obtém resultado
    $resultado = $stmt->get_result();
    $linha = $resultado->fetch_assoc();
    
    $stmt->close();
    
    return $linha;
}

/**
 * Função: obter_registros
 * Responsabilidade: Obter múltiplos registros com paginação
 * Parâmetros: $conexao, $tabela, $limite (padrão 50), $offset (padrão 0), $ordem, $condicao
 * Retorna: array com registros
 * Exemplo: obter_registros($conexao, 'clientes', 10, 0, 'nome ASC')
 */
function obter_registros($conexao, $tabela, $limite = 50, $offset = 0, $ordem = 'id DESC', $condicao = '') {
    // Monta SQL
    $sql = "SELECT * FROM {$tabela}";
    
    // Adiciona WHERE
    if (!empty($condicao)) {
        $sql .= " WHERE {$condicao}";
    }
    
    // Adiciona ORDER BY
    $sql .= " ORDER BY {$ordem}";
    
    // Adiciona LIMIT e OFFSET (para paginação)
    $sql .= " LIMIT {$limite} OFFSET {$offset}";
    
    // Executa query
    $resultado = $conexao->query($sql);
    
    if (!$resultado) {
        registrar_log_erro("Erro ao obter registros: " . $conexao->error);
        return [];
    }
    
    // Coleta todos os registros em um array
    $registros = [];
    while ($linha = $resultado->fetch_assoc()) {
        $registros[] = $linha;
    }
    
    return $registros;
}

/**
 * Função: contar_registros
 * Responsabilidade: Contar total de registros em uma tabela
 * Parâmetros: $conexao, $tabela, $condicao (opcional)
 * Retorna: int com quantidade
 * Exemplo: contar_registros($conexao, 'clientes', 'ativo = 1')
 */
function contar_registros($conexao, $tabela, $condicao = '') {
    // Monta SQL de contagem
    $sql = "SELECT COUNT(*) as total FROM {$tabela}";
    
    // Adiciona WHERE
    if (!empty($condicao)) {
        $sql .= " WHERE {$condicao}";
    }
    
    // Executa
    $resultado = $conexao->query($sql);
    
    if (!$resultado) {
        return 0;
    }
    
    // Obtém resultado
    $linha = $resultado->fetch_assoc();
    return (int)$linha['total'];
}

?>
