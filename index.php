<?php
/**
 * =====================================================================
 * PÁGINA INICIAL - Index
 * =====================================================================
 * 
 * Responsabilidade: Redirecionar para página apropriada baseado em autenticação
 * Uso: Acessar a raiz do site
 * Fluxo:
 *   - Se autenticado como admin → Dashboard admin
 *   - Se autenticado como cliente → Dashboard cliente
 *   - Se não autenticado → Página pública do cliente
 */

// Carrega configurações
require_once __DIR__ . '/config/environment.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';

// Inicia sessão
session_start();

// Verifica se usuário está autenticado
if (Auth::isLogado()) {
    // Se é admin, redireciona para dashboard admin
    if (Auth::isAdmin()) {
        header('Location: ' . BASE_URL . '/app/admin/dashboard.php');
        exit;
    }
    // Se é cliente, redireciona para dashboard cliente
    elseif (Auth::isCliente()) {
        header('Location: ' . BASE_URL . '/app/cliente/dashboard.php');
        exit;
    }
}

// Usuário não autenticado - exibe página pública
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Império AR - NM Refrigeração - Sistema Integrado</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/css/style.css">
</head>
<body>
    <div class="container-index">
        <nav class="navbar">
            <div class="navbar-brand">
                <h1>Império AR</h1>
            </div>
            <div class="navbar-menu">
                <a href="#inicio" class="nav-link">Início</a>
                <a href="#servicos" class="nav-link">Serviços</a>
                <a href="#contato" class="nav-link">Contato</a>
                <a href="<?php echo BASE_URL; ?>/login.php" class="nav-link btn-login">Login</a>
            </div>
        </nav>

        <section id="inicio" class="hero">
            <div class="hero-content">
                <h1>Bem-vindo ao Sistema Integrado</h1>
                <p>Agende seus serviços de refrigeração com facilidade</p>
                <a href="<?php echo BASE_URL; ?>/app/cliente/agendamentos.php" class="btn btn-primary btn-large">
                    Agendar Agora
                </a>
            </div>
        </section>

        <section id="servicos" class="servicos">
            <h2>Nossos Serviços</h2>
            <div class="servicos-grid">
                <!-- Carrega serviços do banco -->
                <?php
                // Carrega serviços disponíveis
                require_once __DIR__ . '/classes/Servico.php';
                $servico_obj = new Servico();
                $servicos = $servico_obj->obter_disponiveis();
                
                foreach ($servicos as $servico):
                ?>
                <div class="servico-card">
                    <?php if (!empty($servico['foto_url'])): ?>
                        <img src="<?php echo BASE_URL . $servico['foto_url']; ?>" alt="<?php echo $servico['nome']; ?>">
                    <?php endif; ?>
                    <h3><?php echo $servico['nome']; ?></h3>
                    <p><?php echo $servico['descricao']; ?></p>
                    <p class="preco">A partir de <strong>R$ <?php echo number_format($servico['valor_unitario'], 2, ',', '.'); ?></strong></p>
                    <a href="<?php echo BASE_URL; ?>/app/cliente/agendamentos.php?servico=<?php echo $servico['id']; ?>" class="btn btn-outline">
                        Agendar
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section id="contato" class="contato">
            <h2>Entre em Contato</h2>
            <div class="contato-info">
                <div class="info-item">
                    <h3>📞 Telefone</h3>
                    <p>(11) 9999-9999</p>
                </div>
                <div class="info-item">
                    <h3>💬 WhatsApp</h3>
                    <p>(11) 99999-9999</p>
                </div>
                <div class="info-item">
                    <h3>📍 Endereço</h3>
                    <p>São Paulo, SP</p>
                </div>
            </div>
        </section>

        <footer class="footer">
            <p>&copy; <?php echo date('Y'); ?> Império AR - NM Refrigeração. Todos os direitos reservados.</p>
        </footer>
    </div>

    <script src="<?php echo BASE_URL; ?>/public/js/main.js"></script>
</body>
</html>
