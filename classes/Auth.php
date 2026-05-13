<?php
/**
 * =====================================================
 * CLASSE AUTH - Autenticação e Gerenciamento de Sessões
 * =====================================================
 * 
 * Responsabilidade: Autenticar usuários e gerenciar sessões
 * Uso: Auth::login(), Auth::isLogado(), Auth::logout()
 * Recebe: Email e senha do usuário
 * Retorna: Status de autenticação e dados do usuário
 * 
 * Segurança:
 * - Senhas hasheadas com bcrypt
 * - Proteção contra força bruta
 * - Tokens CSRF
 * - Timeout de sessão
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/Database.php';

class Auth {
    
    private static $db = null;
    private static $usuario_atual = null;
    
    /**
     * Método: __construct privado
     * Responsabilidade: Evitar instanciação (apenas métodos estáticos)
     */
    private function __construct() {}
    
    /**
     * Método: obter_db()
     * Responsabilidade: Obter instância do banco de dados
     * Parâmetros: none
     * Retorna: Database
     */
    private static function obter_db() {
        if (self::$db === null) {
            self::$db = Database::getInstance();
        }
        return self::$db;
    }
    
    /**
     * Método: login()
     * Responsabilidade: Autenticar usuário com email e senha
     * Parâmetros:
     *   $email - string com email do usuário
     *   $senha - string com senha em texto plano
     * Retorna: bool (true se login bem-sucedido, false se falhou)
     * Erros: Registra tentativas falhadas de login
     * 
     * Segurança:
     * - Verifica limite de tentativas de login
     * - Usa password_verify para comparar senhas
     * - Cria token de sessão
     * 
     * Uso:
     *   if (Auth::login('admin@email.com', '123456')) {
     *       header('Location: dashboard.php');
     *   } else {
     *       echo "Email ou senha inválidos";
     *   }
     */
    public static function login($email, $senha) {
        $db = self::obter_db();
        
        // Validação básica
        if (empty($email) || empty($senha)) {
            return false;
        }
        
        // Verifica limite de tentativas (proteção contra força bruta)
        if (self::verificar_limite_tentativas($email)) {
            registrar_log_erro("Login bloqueado por muitas tentativas: {$email}");
            return false;
        }
        
        // Busca usuário no banco
        $usuario = $db->selectOne('usuarios', ['email' => $email]);
        
        // Se usuário não encontrado
        if (!$usuario) {
            self::registrar_tentativa_falha($email);
            return false;
        }
        
        // Se usuário inativo
        if (!$usuario['ativo']) {
            self::registrar_tentativa_falha($email);
            return false;
        }
        
        // Verifica senha com bcrypt
        if (!password_verify($senha, $usuario['senha'])) {
            self::registrar_tentativa_falha($email);
            return false;
        }
        
        // Login bem-sucedido
        // Inicia sessão
        session_start();
        
        // Armazena dados do usuário na sessão
        $_SESSION['usuario_id'] = $usuario['id'];
        $_SESSION['usuario_nome'] = $usuario['nome'];
        $_SESSION['usuario_email'] = $usuario['email'];
        $_SESSION['usuario_tipo'] = $usuario['tipo'];
        $_SESSION['login_timestamp'] = time();
        
        // Gera token CSRF para formulários
        $_SESSION['csrf_token'] = bin2hex(random_bytes(CSRF_TOKEN_LENGTH / 2));
        
        // Atualiza último acesso
        $db->update('usuarios', 
            ['ultimo_acesso' => date('Y-m-d H:i:s')],
            ['id' => $usuario['id']]
        );
        
        // Limpa tentativas falhadas
        self::limpar_tentativas_falhas($email);
        
        // Registra login no log
        self::registrar_log('LOGIN', 'usuarios', $usuario['id']);
        
        self::$usuario_atual = $usuario;
        
        return true;
    }
    
    /**
     * Método: logout()
     * Responsabilidade: Desconectar usuário
     * Parâmetros: none
     * Retorna: bool (sucesso)
     * 
     * Uso:
     *   Auth::logout();
     *   header('Location: login.php');
     */
    public static function logout() {
        session_start();
        
        // Registra logout no log
        if (isset($_SESSION['usuario_id'])) {
            self::registrar_log('LOGOUT', 'usuarios', $_SESSION['usuario_id']);
        }
        
        // Destroi sessão
        $_SESSION = [];
        session_destroy();
        
        return true;
    }
    
    /**
     * Método: isLogado()
     * Responsabilidade: Verificar se usuário está autenticado
     * Parâmetros: none
     * Retorna: bool (true se logado, false se não)
     * 
     * Uso:
     *   if (!Auth::isLogado()) {
     *       header('Location: login.php');
     *   }
     */
    public static function isLogado() {
        // Se sessão não iniciou, inicia
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Verifica se usuario_id existe na sessão
        if (!isset($_SESSION['usuario_id'])) {
            return false;
        }
        
        // Verifica timeout de sessão
        if (self::verificar_timeout_sessao()) {
            self::logout();
            return false;
        }
        
        return true;
    }
    
    /**
     * Método: obter_usuario()
     * Responsabilidade: Obter dados do usuário logado
     * Parâmetros: none
     * Retorna: array com dados do usuário ou false
     * 
     * Uso:
     *   $usuario = Auth::obter_usuario();
     *   echo $usuario['nome'];
     */
    public static function obter_usuario() {
        if (!self::isLogado()) {
            return false;
        }
        
        return [
            'id' => $_SESSION['usuario_id'],
            'nome' => $_SESSION['usuario_nome'],
            'email' => $_SESSION['usuario_email'],
            'tipo' => $_SESSION['usuario_tipo']
        ];
    }
    
    /**
     * Método: obter_usuario_id()
     * Responsabilidade: Obter ID do usuário logado
     * Parâmetros: none
     * Retorna: int (ID) ou false
     */
    public static function obter_usuario_id() {
        if (!self::isLogado()) {
            return false;
        }
        
        return $_SESSION['usuario_id'];
    }
    
    /**
     * Método: obter_tipo_usuario()
     * Responsabilidade: Obter tipo de usuário (admin ou cliente)
     * Parâmetros: none
     * Retorna: string ('admin' ou 'cliente') ou false
     */
    public static function obter_tipo_usuario() {
        if (!self::isLogado()) {
            return false;
        }
        
        return $_SESSION['usuario_tipo'];
    }
    
    /**
     * Método: isAdmin()
     * Responsabilidade: Verificar se usuário é administrador
     * Parâmetros: none
     * Retorna: bool
     * 
     * Uso:
     *   if (!Auth::isAdmin()) {
     *       die("Acesso restrito a administradores");
     *   }
     */
    public static function isAdmin() {
        if (!self::isLogado()) {
            return false;
        }
        
        return $_SESSION['usuario_tipo'] === TIPO_ADMIN;
    }
    
    /**
     * Método: isCliente()
     * Responsabilidade: Verificar se usuário é cliente
     * Parâmetros: none
     * Retorna: bool
     */
    public static function isCliente() {
        if (!self::isLogado()) {
            return false;
        }
        
        return $_SESSION['usuario_tipo'] === TIPO_CLIENTE;
    }
    
    /**
     * Método: gerar_token_csrf()
     * Responsabilidade: Gerar token CSRF para formulários
     * Parâmetros: none
     * Retorna: string com token
     * 
     * Uso em formulário:
     *   <input type="hidden" name="csrf_token" value="<?php echo Auth::gerar_token_csrf(); ?>">
     */
    public static function gerar_token_csrf() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Se não existe token, cria
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(CSRF_TOKEN_LENGTH / 2));
        }
        
        return $_SESSION['csrf_token'];
    }
    
    /**
     * Método: verificar_token_csrf()
     * Responsabilidade: Verificar validade de token CSRF
     * Parâmetros: $token (string a verificar)
     * Retorna: bool (true se válido)
     * 
     * Uso:
     *   if (!Auth::verificar_token_csrf($_POST['csrf_token'])) {
     *       die("Token inválido");
     *   }
     */
    public static function verificar_token_csrf($token) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Verifica se token existe e corresponde
        if (!isset($_SESSION['csrf_token'])) {
            return false;
        }
        
        // Usa hash_equals para evitar timing attack
        return hash_equals($_SESSION['csrf_token'], $token);
    }
    
    /**
     * Método: criar_usuario()
     * Responsabilidade: Criar novo usuário no sistema
     * Parâmetros:
     *   $nome - string com nome completo
     *   $email - string com email
     *   $senha - string com senha em texto plano
     *   $tipo - string ('admin' ou 'cliente')
     * Retorna: int (ID do novo usuário) ou false
     * 
     * Uso:
     *   $id = Auth::criar_usuario('João Silva', 'joao@email.com', 'senha123', 'cliente');
     */
    public static function criar_usuario($nome, $email, $senha, $tipo = TIPO_CLIENTE) {
        $db = self::obter_db();
        
        // Validações
        if (empty($nome) || empty($email) || empty($senha)) {
            return false;
        }
        
        // Valida email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        
        // Verifica se email já existe
        $existe = $db->selectOne('usuarios', ['email' => $email]);
        if ($existe) {
            return false;
        }
        
        // Hash da senha com bcrypt
        $senha_hash = password_hash($senha, PASSWORD_BCRYPT, ['cost' => 12]);
        
        // Insere novo usuário
        $id = $db->insert('usuarios', [
            'nome' => $nome,
            'email' => $email,
            'senha' => $senha_hash,
            'tipo' => $tipo,
            'ativo' => true
        ]);
        
        if ($id) {
            self::registrar_log('CREATE_USER', 'usuarios', $id);
        }
        
        return $id;
    }
    
    /**
     * Método: alterar_senha()
     * Responsabilidade: Alterar senha de um usuário
     * Parâmetros:
     *   $usuario_id - int com ID do usuário
     *   $senha_atual - string com senha atual (para validação)
     *   $senha_nova - string com nova senha
     * Retorna: bool (sucesso ou falha)
     * 
     * Uso:
     *   if (Auth::alterar_senha(123, 'senha_atual', 'senha_nova')) {
     *       echo "Senha alterada com sucesso";
     *   }
     */
    public static function alterar_senha($usuario_id, $senha_atual, $senha_nova) {
        $db = self::obter_db();
        
        // Validações
        if (empty($usuario_id) || empty($senha_atual) || empty($senha_nova)) {
            return false;
        }
        
        // Busca usuário
        $usuario = $db->selectOne('usuarios', ['id' => $usuario_id]);
        if (!$usuario) {
            return false;
        }
        
        // Verifica senha atual
        if (!password_verify($senha_atual, $usuario['senha'])) {
            return false;
        }
        
        // Hash da nova senha
        $senha_hash = password_hash($senha_nova, PASSWORD_BCRYPT, ['cost' => 12]);
        
        // Atualiza no banco
        $resultado = $db->update('usuarios', 
            ['senha' => $senha_hash],
            ['id' => $usuario_id]
        );
        
        if ($resultado) {
            self::registrar_log('CHANGE_PASSWORD', 'usuarios', $usuario_id);
        }
        
        return $resultado;
    }
    
    /**
     * Método PRIVADO: verificar_limite_tentativas()
     * Responsabilidade: Verificar se usuário excedeu tentativas de login
     * Parâmetros: $email
     * Retorna: bool (true se bloqueado, false se pode tentar)
     * Uso interno apenas
     */
    private static function verificar_limite_tentativas($email) {
        // Usa sessão para armazenar tentativas (em produção, usar banco)
        session_start();
        
        $chave = "tentativas_" . md5($email);
        
        // Se não existe contador, cria
        if (!isset($_SESSION[$chave])) {
            $_SESSION[$chave] = [
                'tentativas' => 0,
                'bloqueado_ate' => 0
            ];
        }
        
        // Verifica se está bloqueado
        if ($_SESSION[$chave]['bloqueado_ate'] > time()) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Método PRIVADO: registrar_tentativa_falha()
     * Responsabilidade: Registrar tentativa falhada de login
     * Parâmetros: $email
     * Retorna: void
     */
    private static function registrar_tentativa_falha($email) {
        session_start();
        
        $chave = "tentativas_" . md5($email);
        
        if (!isset($_SESSION[$chave])) {
            $_SESSION[$chave] = [
                'tentativas' => 0,
                'bloqueado_ate' => 0
            ];
        }
        
        $_SESSION[$chave]['tentativas']++;
        
        // Se excedeu limite, bloqueia por 30 minutos
        if ($_SESSION[$chave]['tentativas'] >= MAX_LOGIN_ATTEMPTS) {
            $_SESSION[$chave]['bloqueado_ate'] = time() + (LOGIN_LOCK_TIME * 60);
        }
    }
    
    /**
     * Método PRIVADO: limpar_tentativas_falhas()
     * Responsabilidade: Limpar contador de tentativas após login bem-sucedido
     * Parâmetros: $email
     * Retorna: void
     */
    private static function limpar_tentativas_falhas($email) {
        session_start();
        
        $chave = "tentativas_" . md5($email);
        unset($_SESSION[$chave]);
    }
    
    /**
     * Método PRIVADO: verificar_timeout_sessao()
     * Responsabilidade: Verificar se sessão expirou por inatividade
     * Parâmetros: none
     * Retorna: bool (true se expirou, false se ainda ativa)
     */
    private static function verificar_timeout_sessao() {
        // Se não tem timestamp, é uma sessão nova
        if (!isset($_SESSION['login_timestamp'])) {
            return true;
        }
        
        // Verifica tempo desde o login
        $tempo_decorrido = time() - $_SESSION['login_timestamp'];
        $timeout_segundos = SESSION_TIMEOUT * 60;
        
        // Se expirou
        if ($tempo_decorrido > $timeout_segundos) {
            return true;
        }
        
        // Atualiza timestamp (renova a sessão)
        $_SESSION['login_timestamp'] = time();
        
        return false;
    }
    
    /**
     * Método PRIVADO: registrar_log()
     * Responsabilidade: Registrar ações no banco de dados
     * Parâmetros: $acao, $tabela, $registro_id
     * Retorna: bool (sucesso)
     */
    private static function registrar_log($acao, $tabela, $registro_id) {
        $db = self::obter_db();
        
        // Obtém IP do usuário
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'desconhecido';
        
        // Obtém User Agent
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'desconhecido';
        
        // ID do usuário (se logado)
        $usuario_id = isset($_SESSION['usuario_id']) ? $_SESSION['usuario_id'] : null;
        
        // Insere log
        return $db->insert('logs_acesso', [
            'usuario_id' => $usuario_id,
            'acao' => $acao,
            'tabela' => $tabela,
            'registro_id' => $registro_id,
            'ip' => $ip,
            'user_agent' => $user_agent
        ]);
    }
}

?>
