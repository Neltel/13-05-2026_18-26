<?php
/**
 * Tabela de Preços - Império AR
 * Gerencia produtos e serviços com seus valores de venda
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/environment.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verifica autenticação
if (!Auth::isLogado() || !Auth::isAdmin()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$usuario = Auth::obter_usuario();
global $conexao;

if (!$conexao) {
    die("Erro de conexão com banco de dados");
}

$mensagem = '';
$tipo_mensagem = '';

// Processar toggle de visibilidade via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'toggle_exibir') {
    if (!isset($_POST['csrf_token']) || !Auth::verificar_token_csrf($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Token inválido']);
        exit;
    }
    
    $tipo = $_POST['tipo'];
    $id = intval($_POST['id']);
    $success = false;
    
    if ($tipo === 'produto') {
        $stmt = $conexao->prepare("UPDATE produtos SET exibir_cliente = NOT exibir_cliente WHERE id = ?");
        $stmt->bind_param("i", $id);
        $success = $stmt->execute();
        $stmt->close();
    } elseif ($tipo === 'servico') {
        $stmt = $conexao->prepare("UPDATE servicos SET exibir_cliente = NOT exibir_cliente WHERE id = ?");
        $stmt->bind_param("i", $id);
        $success = $stmt->execute();
        $stmt->close();
    }
    
    echo json_encode(['success' => $success]);
    exit;
}

// Buscar categorias
$categorias_produtos = $conexao->query("SELECT id, nome FROM categorias_produtos WHERE ativo = 1 ORDER BY nome")->fetch_all(MYSQLI_ASSOC);
$categorias_servicos = $conexao->query("SELECT id, nome FROM categorias_servicos WHERE ativo = 1 ORDER BY nome")->fetch_all(MYSQLI_ASSOC);

// Buscar produtos
$produtos = $conexao->query("
    SELECT p.*, c.nome as categoria_nome 
    FROM produtos p
    LEFT JOIN categorias_produtos c ON p.categoria_id = c.id
    WHERE p.ativo = 1
    ORDER BY c.nome, p.nome
")->fetch_all(MYSQLI_ASSOC);

// Buscar serviços
$servicos = $conexao->query("
    SELECT s.*, cs.nome as categoria_nome
    FROM servicos s
    LEFT JOIN categorias_servicos cs ON s.categoria_servico_id = cs.id
    WHERE s.ativo = 1
    ORDER BY cs.nome, s.nome
")->fetch_all(MYSQLI_ASSOC);

// Estatísticas
$total_produtos_visiveis = count(array_filter($produtos, fn($p) => $p['exibir_cliente']));
$total_servicos_visiveis = count(array_filter($servicos, fn($s) => $s['exibir_cliente']));
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tabela de Preços - Império AR</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <style>
        :root {
            --primary: #1e3c72;
            --primary-light: #2a5298;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
            --gray-100: #f8f9fa;
            --gray-200: #e9ecef;
            --gray-300: #dee2e6;
            --gray-600: #6c757d;
            --gray-800: #343a40;
        }

        body {
            background: linear-gradient(135deg, #f5f6fa 0%, #e9ecef 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .admin-container {
            display: flex;
            min-height: 100vh;
        }

        .main-content {
            flex: 1;
            margin-left: 300px;
            padding: 30px;
            overflow-y: auto;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding: 20px 25px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .page-header h1 {
            color: var(--primary);
            font-size: 28px;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-badge {
            background: var(--primary);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.2s, box-shadow 0.2s;
            border-left: 4px solid var(--primary);
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }

        .stat-icon {
            width: 55px;
            height: 55px;
            background: rgba(30, 60, 114, 0.1);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: var(--primary);
        }

        .stat-info h3 {
            font-size: 28px;
            font-weight: 700;
            margin: 0;
            color: var(--primary);
        }

        .stat-info p {
            margin: 5px 0 0;
            color: var(--gray-600);
            font-size: 13px;
        }

        /* Tabs */
        .nav-tabs {
            border-bottom: 2px solid var(--gray-300);
            gap: 5px;
        }

        .nav-tabs .nav-link {
            border: none;
            padding: 12px 24px;
            font-weight: 500;
            color: var(--gray-600);
            border-radius: 8px 8px 0 0;
            transition: all 0.2s;
        }

        .nav-tabs .nav-link:hover {
            color: var(--primary);
            background: rgba(30, 60, 114, 0.05);
        }

        .nav-tabs .nav-link.active {
            color: var(--primary);
            background: white;
            border-bottom: 3px solid var(--primary);
            font-weight: 600;
        }

        /* Table Container */
        .table-container {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        /* DataTables Custom */
        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter {
            margin-bottom: 20px;
        }

        .dataTables_wrapper .dataTables_length select,
        .dataTables_wrapper .dataTables_filter input {
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            padding: 8px 12px;
        }

        .dataTables_wrapper .dataTables_filter input {
            width: 280px;
            padding-left: 35px;
            background-image: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="%236c757d" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>');
            background-repeat: no-repeat;
            background-position: 12px center;
        }

        /* Paginação estilizada */
        .dataTables_wrapper .dataTables_paginate {
            margin-top: 25px;
            text-align: center !important;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button {
            padding: 8px 16px;
            margin: 0 4px;
            border-radius: 8px;
            border: 1px solid var(--gray-300);
            background: white;
            color: var(--gray-800);
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s;
            cursor: pointer;
            display: inline-block;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
            box-shadow: 0 2px 5px rgba(30, 60, 114, 0.3);
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            background: var(--primary-light);
            border-color: var(--primary-light);
            color: white;
            transform: translateY(-1px);
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            background: var(--gray-100);
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button.disabled:hover {
            transform: none;
            background: var(--gray-100);
        }

        .dataTables_wrapper .dataTables_info {
            color: var(--gray-600);
            font-size: 13px;
            margin-top: 20px;
            text-align: left;
        }

        /* Table Styles */
        .table {
            margin-bottom: 0;
        }

        .table thead th {
            background: var(--gray-100);
            border-bottom: 2px solid var(--gray-300);
            padding: 14px 15px;
            font-weight: 600;
            color: var(--gray-800);
            font-size: 13px;
        }

        .table tbody td {
            padding: 14px 15px;
            vertical-align: middle;
            border-bottom: 1px solid var(--gray-200);
        }

        .product-name {
            font-weight: 600;
            color: var(--gray-800);
        }

        .product-desc {
            font-size: 12px;
            color: var(--gray-600);
            margin-top: 4px;
        }

        .price-value {
            font-weight: 700;
            color: var(--primary);
            font-size: 15px;
        }

        /* Badges */
        .badge-margin {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }

        .badge-margin.high { background: #d1e7dd; color: #0f5132; }
        .badge-margin.medium { background: #fff3cd; color: #856404; }
        .badge-margin.low { background: #f8d7da; color: #721c24; }

        /* Toggle Switch para Visibilidade */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 52px;
            height: 26px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: 0.3s;
            border-radius: 34px;
        }

        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 20px;
            width: 20px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: 0.3s;
            border-radius: 50%;
        }

        input:checked + .toggle-slider {
            background-color: var(--success);
        }

        input:checked + .toggle-slider:before {
            transform: translateX(26px);
        }

        .toggle-label {
            margin-left: 60px;
            font-size: 12px;
            font-weight: 500;
            white-space: nowrap;
        }

        .toggle-label i {
            margin-right: 4px;
        }

        .toggle-container {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Buttons */
        .btn-action {
            padding: 6px 14px;
            font-size: 12px;
            border-radius: 6px;
            margin: 2px;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
            cursor: pointer;
            font-weight: 500;
        }

        .btn-edit {
            background: var(--primary);
            border: none;
            color: white;
        }

        .btn-edit:hover {
            background: var(--primary-light);
            color: white;
            text-decoration: none;
            transform: translateY(-1px);
        }

        /* Alert */
        .alert-custom {
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 20px;
            border: none;
        }

        .alert-success { background: #d1e7dd; color: #0f5132; }
        .alert-danger { background: #f8d7da; color: #721c24; }

        /* Toast notification */
        .toast-notify {
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--success);
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            z-index: 9999;
            display: none;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            font-size: 14px;
        }

        /* Ações column - no wrap */
        .actions-cell {
            white-space: nowrap;
            min-width: 140px;
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 15px; }
            .stats-grid { grid-template-columns: 1fr; }
            .page-header { flex-direction: column; gap: 15px; text-align: center; }
            .dataTables_wrapper .dataTables_filter input { width: 100%; }
            .actions-cell { white-space: normal; }
            .toggle-label { display: none; }
            .toggle-switch { margin: 0 auto; }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1>
                    <i class="fas fa-tags"></i>
                    Tabela de Preços
                </h1>
                <div class="user-badge">
                    <i class="fas fa-user-circle me-2"></i>
                    <?php echo htmlspecialchars($usuario['nome'] ?? 'Admin'); ?>
                </div>
            </div>
            
            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-box"></i></div>
                    <div class="stat-info">
                        <h3><?php echo count($produtos); ?></h3>
                        <p>Produtos Ativos</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-wrench"></i></div>
                    <div class="stat-info">
                        <h3><?php echo count($servicos); ?></h3>
                        <p>Serviços Ativos</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-eye"></i></div>
                    <div class="stat-info">
                        <h3><?php echo $total_produtos_visiveis; ?></h3>
                        <p>Produtos Visíveis</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-eye"></i></div>
                    <div class="stat-info">
                        <h3><?php echo $total_servicos_visiveis; ?></h3>
                        <p>Serviços Visíveis</p>
                    </div>
                </div>
            </div>
            
            <!-- Tabs -->
            <ul class="nav nav-tabs mb-4">
                <li class="nav-item">
                    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#produtos">
                        <i class="fas fa-box me-2"></i>Produtos
                        <span class="badge bg-secondary ms-2"><?php echo count($produtos); ?></span>
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#servicos">
                        <i class="fas fa-wrench me-2"></i>Serviços
                        <span class="badge bg-secondary ms-2"><?php echo count($servicos); ?></span>
                    </button>
                </li>
            </ul>
            
            <div class="tab-content">
                <!-- Produtos Tab -->
                <div class="tab-pane fade show active" id="produtos">
                    <div class="table-container">
                        <div class="table-responsive">
                            <table class="table table-hover" id="tabela-produtos">
                                <thead>
                                    <tr>
                                        <th>Produto</th>
                                        <th>Categoria</th>
                                        <th>Preço Venda</th>
                                        <th>Custo</th>
                                        <th>Margem</th>
                                        <th style="width: 100px;">Visível</th>
                                        <th style="width: 120px;">Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($produtos as $produto): 
                                        $margem = 0;
                                        $classe_margem = 'medium';
                                        if ($produto['valor_compra'] > 0) {
                                            $margem = (($produto['valor_venda'] - $produto['valor_compra']) / $produto['valor_compra']) * 100;
                                            $classe_margem = $margem >= 50 ? 'high' : ($margem < 20 ? 'low' : 'medium');
                                        } else {
                                            $classe_margem = 'high';
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="product-name"><?php echo htmlspecialchars($produto['nome']); ?></div>
                                            <?php if ($produto['descricao']): ?>
                                            <div class="product-desc"><?php echo htmlspecialchars(substr($produto['descricao'], 0, 60)); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($produto['categoria_nome'] ?? '-'); ?></td>
                                        <td><span class="price-value">R$ <?php echo number_format($produto['valor_venda'], 2, ',', '.'); ?></span></td>
                                        <td>R$ <?php echo number_format($produto['valor_compra'], 2, ',', '.'); ?></td>
                                        <td><span class="badge-margin <?php echo $classe_margem; ?>"><?php echo number_format($margem, 1); ?>%</span></td>
                                        <td>
                                            <div class="toggle-container">
                                                <label class="toggle-switch">
                                                    <input type="checkbox" class="toggle-visibility-checkbox" 
                                                           data-tipo="produto" data-id="<?php echo $produto['id']; ?>"
                                                           <?php echo $produto['exibir_cliente'] ? 'checked' : ''; ?>>
                                                    <span class="toggle-slider"></span>
                                                </label>
                                                <span class="toggle-label">
                                                    <i class="fas fa-<?php echo $produto['exibir_cliente'] ? 'eye' : 'eye-slash'; ?>"></i>
                                                    <?php echo $produto['exibir_cliente'] ? 'Visível' : 'Oculto'; ?>
                                                </span>
                                            </div>
                                        </td>
                                        <td class="actions-cell">
                                            <a href="<?php echo BASE_URL; ?>/app/admin/produtos.php?acao=editar&id=<?php echo $produto['id']; ?>" class="btn-action btn-edit">
                                                <i class="fas fa-edit"></i> Editar
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Serviços Tab -->
                <div class="tab-pane fade" id="servicos">
                    <div class="table-container">
                        <div class="table-responsive">
                            <table class="table table-hover" id="tabela-servicos">
                                <thead>
                                    <tr>
                                        <th>Serviço</th>
                                        <th>Categoria</th>
                                        <th>Valor Unitário</th>
                                        <th>Custo</th>
                                        <th>Margem</th>
                                        <th>Tempo</th>
                                        <th style="width: 100px;">Visível</th>
                                        <th style="width: 120px;">Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($servicos as $servico):
                                        $margem = 0;
                                        $classe_margem = 'medium';
                                        if ($servico['valor_custo'] > 0) {
                                            $margem = (($servico['valor_unitario'] - $servico['valor_custo']) / $servico['valor_custo']) * 100;
                                            $classe_margem = $margem >= 50 ? 'high' : ($margem < 20 ? 'low' : 'medium');
                                        } else {
                                            $classe_margem = 'high';
                                        }
                                        
                                        $tempo = '';
                                        if ($servico['tempo_execucao']) {
                                            $horas = floor($servico['tempo_execucao'] / 60);
                                            $minutos = $servico['tempo_execucao'] % 60;
                                            if ($horas > 0) $tempo = $horas . 'h';
                                            if ($minutos > 0) $tempo .= ($tempo ? ' ' : '') . $minutos . 'min';
                                        } else {
                                            $tempo = '-';
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="product-name"><?php echo htmlspecialchars($servico['nome']); ?></div>
                                            <?php if ($servico['descricao']): ?>
                                            <div class="product-desc"><?php echo htmlspecialchars(substr($servico['descricao'], 0, 60)); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($servico['categoria_nome'] ?? '-'); ?></td>
                                        <td><span class="price-value">R$ <?php echo number_format($servico['valor_unitario'], 2, ',', '.'); ?></span></td>
                                        <td>R$ <?php echo number_format($servico['valor_custo'], 2, ',', '.'); ?></td>
                                        <td><span class="badge-margin <?php echo $classe_margem; ?>"><?php echo number_format($margem, 1); ?>%</span></td>
                                        <td><?php echo $tempo; ?></td>
                                        <td>
                                            <div class="toggle-container">
                                                <label class="toggle-switch">
                                                    <input type="checkbox" class="toggle-visibility-checkbox" 
                                                           data-tipo="servico" data-id="<?php echo $servico['id']; ?>"
                                                           <?php echo $servico['exibir_cliente'] ? 'checked' : ''; ?>>
                                                    <span class="toggle-slider"></span>
                                                </label>
                                                <span class="toggle-label">
                                                    <i class="fas fa-<?php echo $servico['exibir_cliente'] ? 'eye' : 'eye-slash'; ?>"></i>
                                                    <?php echo $servico['exibir_cliente'] ? 'Visível' : 'Oculto'; ?>
                                                </span>
                                            </div>
                                        </td>
                                        <td class="actions-cell">
                                            <a href="<?php echo BASE_URL; ?>/app/admin/servicos.php?acao=editar&id=<?php echo $servico['id']; ?>" class="btn-action btn-edit">
                                                <i class="fas fa-edit"></i> Editar
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Toast de notificação -->
    <div id="toast-notify" class="toast-notify">
        <i class="fas fa-check-circle me-2"></i>
        <span id="toast-message"></span>
    </div>

    <script>
        $(document).ready(function() {
            // DataTables com paginação personalizada
            var produtosTable = $('#tabela-produtos').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-BR.json',
                    paginate: {
                        first: '««',
                        last: '»»',
                        next: 'Próximo →',
                        previous: '← Anterior'
                    }
                },
                pageLength: 25,
                order: [[0, 'asc']],
                columnDefs: [{ orderable: false, targets: [5, 6] }],
                dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
                     '<"row"<"col-sm-12"tr>>' +
                     '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>'
            });
            
            var servicosTable = $('#tabela-servicos').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-BR.json',
                    paginate: {
                        first: '««',
                        last: '»»',
                        next: 'Próximo →',
                        previous: '← Anterior'
                    }
                },
                pageLength: 25,
                order: [[0, 'asc']],
                columnDefs: [{ orderable: false, targets: [6, 7] }],
                dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
                     '<"row"<"col-sm-12"tr>>' +
                     '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>'
            });
        });
        
        // Toggle visibility via AJAX com toggle switch
        $('.toggle-visibility-checkbox').on('change', function() {
            var checkbox = $(this);
            var tipo = checkbox.data('tipo');
            var id = checkbox.data('id');
            var toggleContainer = checkbox.closest('.toggle-container');
            var labelSpan = toggleContainer.find('.toggle-label');
            var isChecked = checkbox.is(':checked');
            
            // Desabilitar durante o processamento
            checkbox.prop('disabled', true);
            
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: {
                    acao: 'toggle_exibir',
                    tipo: tipo,
                    id: id,
                    csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Atualizar o label
                        if (isChecked) {
                            labelSpan.html('<i class="fas fa-eye"></i> Visível');
                        } else {
                            labelSpan.html('<i class="fas fa-eye-slash"></i> Oculto');
                        }
                        showToast('Visibilidade alterada com sucesso!');
                    } else {
                        // Reverter o checkbox em caso de erro
                        checkbox.prop('checked', !isChecked);
                        showToast('Erro ao alterar visibilidade.', 'error');
                    }
                    checkbox.prop('disabled', false);
                },
                error: function() {
                    // Reverter o checkbox em caso de erro
                    checkbox.prop('checked', !isChecked);
                    showToast('Erro ao alterar visibilidade. Tente novamente.', 'error');
                    checkbox.prop('disabled', false);
                }
            });
        });
        
        function showToast(message, type = 'success') {
            var toast = $('#toast-notify');
            var toastMessage = $('#toast-message');
            
            toast.css('background', type === 'success' ? '#28a745' : '#dc3545');
            toastMessage.text(message);
            toast.fadeIn(300);
            
            setTimeout(function() {
                toast.fadeOut(300);
            }, 2000);
        }
    </script>
</body>
</html>