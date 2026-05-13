<?php
/**
 * =====================================================================
 * PÁGINA DE LOGIN
 * =====================================================================
 * 
 * Responsabilidade: Autenticar usuário (admin ou cliente)
 * Recebe: POST com email e senha
 * Retorna: Redireciona para dashboard ou exibe erro
 */

require_once __DIR__ . '/config/environment.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';

session_start();

// Variáveis de controle
$erro = '';
$email = '';

// Processa formulário POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Valida token CSRF
    if (empty($_POST['csrf_token']) || !Auth::verificar_token_csrf($_POST['csrf_token'])) {
        $erro = MSG_ACESSO_NEGADO;
    }
    // Valida dados
    elseif (empty($_POST['email']) || empty($_POST['senha'])) {
        $erro = MSG_DADOS_INVALIDOS;
    }
    else {
        // Tenta fazer login
        $email = trim($_POST['email']);
        $senha = $_POST['senha'];
        
        if (Auth::login($email, $senha)) {
            // Determina para onde redirecionar
            $usuario = Auth::obter_usuario();
            
            if ($usuario['tipo'] === TIPO_ADMIN) {
                // Redireciona para dashboard admin com domínio correto
                header('Location: ' . BASE_URL . '/app/admin/dashboard.php');
            } else {
                // Redireciona para dashboard cliente
                header('Location: ' . BASE_URL . '/app/cliente/dashboard.php');
            }
            exit;
        } else {
            $erro = 'Email ou senha inválidos';
        }
    }
}

// Gera token CSRF
$csrf_token = Auth::gerar_token_csrf();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Império AR - Sistema Integrado</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/css/style.css">
    <style>
        .login-container {
            max-width: 400px;
            margin: 50px auto;
            padding: 40px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .login-container h1 {
            text-align: center;
            margin-bottom: 30px;
            color: #333;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #555;
            font-weight: bold;
        }
        
        /* Container para o campo de senha com o botão */
        .password-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }
        
        .password-wrapper input {
            width: 100%;
            padding: 10px;
            padding-right: 40px; /* Espaço para o botão */
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .password-wrapper input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 5px rgba(102, 126, 234, 0.3);
        }
        
        /* Botão mostrar/ocultar senha */
        .toggle-password {
            position: absolute;
            right: 10px;
            cursor: pointer;
            user-select: none;
            font-size: 18px;
            background: none;
            border: none;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #666;
            transition: color 0.2s;
        }
        
        .toggle-password:hover {
            color: #667eea;
        }
        
        /* Estilo específico para o wrapper do campo senha */
        .senha-wrapper {
            position: relative;
        }
        
        .btn-login-submit {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .btn-login-submit:hover {
            transform: translateY(-2px);
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 20px;
            border: 1px solid #f5c6cb;
        }
        .links {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
        }
        .links a {
            color: #667eea;
            text-decoration: none;
        }
        .links a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="navbar-brand">
            <h1>Império AR</h1>
        </div>
        <div class="navbar-menu">
            <a href="<?php echo BASE_URL; ?>" class="nav-link">Voltar</a>
        </div>
    </nav>

    <div class="login-container">
        <h1>🔐 Login</h1>

        <?php if (!empty($erro)): ?>
        <div class="alert-error">
            <?php echo htmlspecialchars($erro); ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="">
            <!-- Token CSRF -->
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

            <!-- Email -->
            <div class="form-group">
                <label for="email">📧 Email</label>
                <input 
                    type="email" 
                    id="email" 
                    name="email" 
                    value="<?php echo htmlspecialchars($email); ?>" 
                    placeholder="seu@email.com"
                    required
                    autocomplete="email"
                >
            </div>

            <!-- Senha com botão mostrar/ocultar -->
            <div class="form-group">
                <label for="senha">🔑 Senha</label>
                <div class="password-wrapper">
                    <input 
                        type="password" 
                        id="senha" 
                        name="senha" 
                        placeholder="Sua senha"
                        required
                        autocomplete="current-password"
                    >
                    <button type="button" class="toggle-password" onclick="togglePasswordVisibility()">
                        👁️
                    </button>
                </div>
            </div>

            <!-- Botão Login -->
            <button type="submit" class="btn-login-submit">Entrar</button>
        </form>

        <div class="links">
            <p>Não tem conta? <a href="<?php echo BASE_URL; ?>/registro.php">Registre-se aqui</a></p>
            <p><a href="<?php echo BASE_URL; ?>/recuperar-senha.php">Esqueceu a senha?</a></p>
        </div>
    </div>

    <script>
        // Função para alternar a visibilidade da senha
        function togglePasswordVisibility() {
            const passwordInput = document.getElementById('senha');
            const toggleButton = document.querySelector('.toggle-password');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleButton.innerHTML = '🙈'; // Muda o ícone para olho fechado
                toggleButton.setAttribute('title', 'Ocultar senha');
            } else {
                passwordInput.type = 'password';
                toggleButton.innerHTML = '👁️'; // Muda o ícone para olho aberto
                toggleButton.setAttribute('title', 'Mostrar senha');
            }
        }
        
        // Adiciona suporte para tecla Enter nos campos
        document.addEventListener('DOMContentLoaded', function() {
            const passwordInput = document.getElementById('senha');
            const emailInput = document.getElementById('email');
            
            // Permite submeter o formulário pressionando Enter em qualquer campo
            const inputs = [emailInput, passwordInput];
            inputs.forEach(input => {
                if (input) {
                    input.addEventListener('keypress', function(e) {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            const form = this.closest('form');
                            if (form) {
                                form.submit();
                            }
                        }
                    });
                }
            });
            
            // Adiciona tooltip ao botão
            const toggleBtn = document.querySelector('.toggle-password');
            if (toggleBtn) {
                toggleBtn.setAttribute('title', 'Mostrar senha');
            }
        });
        
        // Previne que o botão de toggle submeta o formulário
        document.querySelector('.toggle-password')?.addEventListener('click', function(e) {
            e.preventDefault();
        });
    </script>
    
    <script src="<?php echo BASE_URL; ?>/public/js/main.js"></script>
</body>
</html>
