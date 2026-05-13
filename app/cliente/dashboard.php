<?php
/**
 * =====================================================================
 * DASHBOARD CLIENTE
 * =====================================================================
 * 
 * Responsabilidade: Exibir dashboard do cliente
 * Verifica: Se usuário é cliente autenticado
 * Exibe: Seus agendamentos, histórico, opções para agendar
 */

require_once __DIR__ . '/../../config/environment.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';

session_start();

// Verifica autenticação
if (!Auth::isLogado() || !Auth::isCliente()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Obtém dados do usuário
$usuario = Auth::obter_usuario();

// Carrega classes necessárias
require_once __DIR__ . '/../../classes/Cliente.php';
require_once __DIR__ . '/../../classes/Agendamento.php';

// Instancia objetos
$cliente_obj = new Cliente();
$agendamento_obj = new Agendamento();

// Busca cliente associado ao usuário
$cliente = $cliente_obj->obter($usuario['id']);

// Se não encontrar cliente, cria um novo
if (!$cliente) {
    // Redireciona para completar perfil
    header('Location: ' . BASE_URL . '/app/cliente/meu-perfil.php');
    exit;
}

// Obtém agendamentos do cliente
$agendamentos = $agendamento_obj->listar(['cliente_id' => $cliente['id']]);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Minha Área - Sistema Integrado</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/css/cliente.css">
</head>
<body>
    <nav class="navbar navbar-cliente">
        <div class="navbar-brand">
            <h1>Minha Área</h1>
        </div>
        <div class="navbar-menu">
            <a href="<?php echo BASE_URL; ?>/app/cliente/agendamentos.php" class="nav-link">Agendar</a>
            <a href="<?php echo BASE_URL; ?>/app/cliente/meu-perfil.php" class="nav-link">Perfil</a>
            <a href="<?php echo BASE_URL; ?>/logout.php" class="nav-link btn-logout">Sair</a>
        </div>
    </nav>

    <div class="cliente-container">
        <div class="client-header">
            <div class="client-info">
                <h1>👋 Bem-vindo, <?php echo htmlspecialchars($cliente['nome']); ?>!</h1>
                <p>Acompanhe seus agendamentos e gerencie seus serviços</p>
            </div>
            <a href="<?php echo BASE_URL; ?>/app/cliente/agendamentos.php" class="btn btn-primary btn-large">
                ➕ Novo Agendamento
            </a>
        </div>

        <!-- Cards de Status -->
        <div class="status-cards">
            <div class="status-card">
                <h3>📅 Agendamentos</h3>
                <p class="status-number"><?php echo count($agendamentos); ?></p>
            </div>
            <div class="status-card">
                <h3>✅ Concluídos</h3>
                <p class="status-number"><?php echo count(array_filter($agendamentos, function($a) { return $a['status'] === AGENDAMENTO_FINALIZADO; })); ?></p>
            </div>
            <div class="status-card">
                <h3>⏳ Pendentes</h3>
                <p class="status-number"><?php echo count(array_filter($agendamentos, function($a) { return $a['status'] === AGENDAMENTO_AGENDADO; })); ?></p>
            </div>
        </div>

        <!-- Agendamentos -->
        <section class="agendamentos-section">
            <h2>Seus Agendamentos</h2>
            
            <?php if (!empty($agendamentos)): ?>
            <div class="agendamentos-list">
                <?php foreach ($agendamentos as $agend): ?>
                <div class="agendamento-card">
                    <div class="agendamento-header">
                        <h3><?php echo $agend['servico_id'] ?? 'Serviço'; ?></h3>
                        <span class="badge badge-<?php echo $agend['status']; ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $agend['status'])); ?>
                        </span>
                    </div>
                    <div class="agendamento-info">
                        <p><strong>📅 Data:</strong> <?php echo date('d/m/Y', strtotime($agend['data_agendamento'])); ?></p>
                        <p><strong>🕐 Horário:</strong> <?php echo $agend['horario_inicio']; ?></p>
                        <?php if (!empty($agend['observacao'])): ?>
                        <p><strong>📝 Observações:</strong> <?php echo htmlspecialchars($agend['observacao']); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="agendamento-actions">
                        <a href="#" class="btn btn-small">Ver Detalhes</a>
                        <?php if ($agend['status'] === AGENDAMENTO_AGENDADO): ?>
                        <a href="#" class="btn btn-small btn-danger">Cancelar</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-message">
                <p>Você ainda não tem agendamentos</p>
                <a href="<?php echo BASE_URL; ?>/app/cliente/agendamentos.php" class="btn btn-primary">
                    Fazer um Agendamento
                </a>
            </div>
            <?php endif; ?>
        </section>

        <!-- Seção de Serviços Disponíveis -->
        <section class="servicos-section">
            <h2>Serviços Disponíveis</h2>
            <p>Escolha um serviço e agende agora mesmo!</p>
            <a href="<?php echo BASE_URL; ?>/app/cliente/agendamentos.php" class="btn btn-primary">
                Ver Serviços
            </a>
        </section>
    </div>

    <script src="<?php echo BASE_URL; ?>/public/js/main.js"></script>
    <script src="<?php echo BASE_URL; ?>/public/js/cliente.js"></script>
</body>
</html>
