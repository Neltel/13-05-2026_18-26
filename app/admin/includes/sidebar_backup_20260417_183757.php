<?php
/**
 * =====================================================================
 * SIDEBAR UNIFICADA - SISTEMA IMPÉRIO AR
 * =====================================================================
 * 
 * Responsabilidade: Sidebar com 20 botões para navegação no admin
 * Uso: include __DIR__ . '/includes/sidebar.php';
 * 
 * Funcionalidades:
 * - 20 links organizados por categorias
 * - Destaque da página atual
 * - Informações do usuário logado
 * - Botão de logout
 * - Responsivo
 */

// Determina a página atual para highlight
$pagina_atual = basename($_SERVER['PHP_SELF']);
?>
<aside class="sidebar">
    <div class="sidebar-header">
        <h2>🏢 Império AR</h2>
        <p><?php echo htmlspecialchars($usuario['nome'] ?? 'Administrador'); ?></p>
        <p style="font-size: 12px; opacity: 0.7;">Sistema Unificado</p>
    </div>

    <!-- GRUPO 1: MÓDULOS PRINCIPAIS (9 botões - App-1) -->
    <div class="sidebar-group">
        <div class="sidebar-group-title">
            <i class="fas fa-star"></i> PRINCIPAL
        </div>
        <nav class="sidebar-nav">
            <a href="<?php echo BASE_URL; ?>/app/admin/dashboard.php" 
               class="nav-item <?php echo $pagina_atual == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-chart-line"></i> Dashboard
            </a>
            <a href="<?php echo BASE_URL; ?>/app/admin/clientes.php" 
               class="nav-item <?php echo $pagina_atual == 'clientes.php' ? 'active' : ''; ?>">
                <i class="fas fa-users"></i> Clientes
            </a>
            <a href="<?php echo BASE_URL; ?>/app/admin/produtos.php" 
               class="nav-item <?php echo $pagina_atual == 'produtos.php' ? 'active' : ''; ?>">
                <i class="fas fa-box"></i> Produtos
            </a>
            <a href="<?php echo BASE_URL; ?>/app/admin/servicos.php" 
               class="nav-item <?php echo $pagina_atual == 'servicos.php' ? 'active' : ''; ?>">
                <i class="fas fa-tools"></i> Serviços
            </a>
            <a href="<?php echo BASE_URL; ?>/app/admin/orcamentos.php" 
               class="nav-item <?php echo $pagina_atual == 'orcamentos.php' ? 'active' : ''; ?>">
                <i class="fas fa-file-invoice"></i> Orçamentos
            </a>
            <a href="<?php echo BASE_URL; ?>/app/admin/pedidos.php" 
               class="nav-item <?php echo $pagina_atual == 'pedidos.php' ? 'active' : ''; ?>">
                <i class="fas fa-shopping-cart"></i> Pedidos
            </a>
            <a href="<?php echo BASE_URL; ?>/app/admin/vendas.php" 
               class="nav-item <?php echo $pagina_atual == 'vendas.php' ? 'active' : ''; ?>">
                <i class="fas fa-dollar-sign"></i> Vendas
            </a>
            <a href="<?php echo BASE_URL; ?>/app/admin/agendamentos.php" 
               class="nav-item <?php echo $pagina_atual == 'agendamentos.php' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-alt"></i> Agendamentos
            </a>
            <a href="<?php echo BASE_URL; ?>/app/admin/cobrancas.php" 
               class="nav-item <?php echo $pagina_atual == 'cobrancas.php' ? 'active' : ''; ?>">
                <i class="fas fa-credit-card"></i> Cobranças
            </a>
        </nav>
    </div>

    <!-- GRUPO 2: MÓDULOS SECUNDÁRIOS (11 botões - App-2) -->
    <div class="sidebar-group">
        <div class="sidebar-group-title">
            <i class="fas fa-cog"></i> OPERACIONAL
        </div>
        <nav class="sidebar-nav">
            <a href="<?php echo BASE_URL; ?>/app/admin/tabela_precos.php" 
               class="nav-item <?php echo $pagina_atual == 'tabela_precos.php' ? 'active' : ''; ?>">
                <i class="fas fa-table"></i> Tabela de Preços
            </a>
            <a href="<?php echo BASE_URL; ?>/app/admin/historico.php" 
               class="nav-item <?php echo $pagina_atual == 'historico.php' ? 'active' : ''; ?>">
                <i class="fas fa-history"></i> Histórico
            </a>
            <a href="<?php echo BASE_URL; ?>/app/admin/garantias.php" 
               class="nav-item <?php echo $pagina_atual == 'garantias.php' ? 'active' : ''; ?>">
                <i class="fas fa-shield-alt"></i> Garantias
            </a>
            <a href="<?php echo BASE_URL; ?>/app/admin/preventivas.php" 
               class="nav-item <?php echo $pagina_atual == 'preventivas.php' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-check"></i> Preventivas
            </a>
            <a href="<?php echo BASE_URL; ?>/app/admin/relatorios_tecnicos.php" 
               class="nav-item <?php echo $pagina_atual == 'relatorios_tecnicos.php' ? 'active' : ''; ?>">
                <i class="fas fa-file-alt"></i> Relatórios Técnicos
            </a>
            <a href="<?php echo BASE_URL; ?>/app/admin/financeiro.php" 
               class="nav-item <?php echo $pagina_atual == 'financeiro.php' ? 'active' : ''; ?>">
                <i class="fas fa-chart-pie"></i> Financeiro
            </a>
            <a href="<?php echo BASE_URL; ?>/app/admin/pmp.php" 
               class="nav-item <?php echo $pagina_atual == 'pmp.php' ? 'active' : ''; ?>">
                <i class="fas fa-clipboard-list"></i> PMP
            </a>
            <a href="<?php echo BASE_URL; ?>/app/admin/configuracoes.php" 
               class="nav-item <?php echo $pagina_atual == 'configuracoes.php' ? 'active' : ''; ?>">
                <i class="fas fa-cog"></i> Configurações
            </a>
        </nav>
    </div>

    <!-- GRUPO 3: FERRAMENTAS ESPECIAIS -->
    <div class="sidebar-group">
        <div class="sidebar-group-title">
            <i class="fas fa-robot"></i> FERRAMENTAS
        </div>
        <nav class="sidebar-nav">
            <a href="<?php echo BASE_URL; ?>/app/admin/ia_assistente.php" 
               class="nav-item <?php echo $pagina_atual == 'ia_assistente.php' ? 'active' : ''; ?>">
                <i class="fas fa-robot"></i> Assistente IA
            </a>
            <a href="<?php echo BASE_URL; ?>/app/admin/calculadora_termica.php" 
               class="nav-item <?php echo $pagina_atual == 'calculadora_termica.php' ? 'active' : ''; ?>">
                <i class="fas fa-calculator"></i> Carga Térmica
            </a>
            <a href="<?php echo BASE_URL; ?>/app/admin/especificacoes.php" 
               class="nav-item <?php echo $pagina_atual == 'especificacoes.php' ? 'active' : ''; ?>">
                <i class="fas fa-microchip"></i> Especificações
            </a>
        </nav>
    </div>

    <!-- Rodapé da Sidebar com Logout -->
    <div class="sidebar-footer">
        <a href="<?php echo BASE_URL; ?>/logout.php" class="btn-logout">
            <i class="fas fa-sign-out-alt"></i> Sair
        </a>
    </div>
</aside>

<style>
/* Estilos específicos da sidebar (podem ser movidos para admin.css) */
.sidebar-group {
    margin-bottom: 20px;
}

.sidebar-group-title {
    padding: 8px 16px;
    margin-bottom: 5px;
    font-size: 12px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: rgba(255,255,255,0.5);
    border-bottom: 1px solid rgba(255,255,255,0.1);
}

.sidebar-group-title i {
    margin-right: 5px;
    font-size: 10px;
}

/* Indicador de página ativa */
.nav-item.active {
    background: rgba(255,255,255,0.2);
    border-left: 3px solid #ffc107;
}

/* Responsividade */
@media (max-width: 768px) {
    .sidebar-group-title {
        margin-top: 15px;
    }
    
    .sidebar-group:first-child .sidebar-group-title {
        margin-top: 0;
    }
}
</style>
