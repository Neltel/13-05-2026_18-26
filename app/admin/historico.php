<?php
/**
 * Histórico - Império AR
 * Registro separado por categorias
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

if (!Auth::isLogado() || !Auth::isAdmin()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$usuario = Auth::obter_usuario();
global $conexao;

if (!$conexao) {
    die("Erro de conexão com banco de dados");
}

function formatarMoeda($valor) {
    if (empty($valor) || !is_numeric($valor)) $valor = 0;
    return 'R$ ' . number_format(floatval($valor), 2, ',', '.');
}

function formatarData($data, $hora = false) {
    if (empty($data)) return '-';
    if ($hora) {
        return date('d/m/Y H:i', strtotime($data));
    }
    return date('d/m/Y', strtotime($data));
}

function getStatusBadge($status, $tipo) {
    $classes = [
        'pendente' => 'warning',
        'aprovado' => 'success',
        'rejeitado' => 'danger',
        'convertido' => 'info',
        'em_andamento' => 'info',
        'finalizado' => 'success',
        'cancelado' => 'danger',
        'agendado' => 'warning',
        'confirmado' => 'success',
        'em_execucao' => 'info',
        'vencida' => 'danger',
        'recebida' => 'success'
    ];
    
    $classe = $classes[$status] ?? 'secondary';
    $labels = [
        'pendente' => 'Pendente',
        'aprovado' => 'Aprovado',
        'rejeitado' => 'Rejeitado',
        'convertido' => 'Convertido',
        'em_andamento' => 'Em Andamento',
        'finalizado' => 'Finalizado',
        'cancelado' => 'Cancelado',
        'agendado' => 'Agendado',
        'confirmado' => 'Confirmado',
        'em_execucao' => 'Em Execução',
        'vencida' => 'Vencida',
        'recebida' => 'Recebida'
    ];
    
    $label = $labels[$status] ?? ucfirst($status);
    return '<span class="badge-margin ' . $classe . '">' . $label . '</span>';
}

// Buscar dados
$orcamentos = $conexao->query("SELECT o.id, o.numero, o.data_emissao, o.valor_total, o.situacao, o.assinado, c.nome as cliente_nome FROM orcamentos o LEFT JOIN clientes c ON o.cliente_id = c.id ORDER BY o.data_emissao DESC LIMIT 200")->fetch_all(MYSQLI_ASSOC);
$pedidos = $conexao->query("SELECT p.id, p.numero, p.data_pedido, p.valor_total, p.situacao, c.nome as cliente_nome FROM pedidos p LEFT JOIN clientes c ON p.cliente_id = c.id ORDER BY p.data_pedido DESC LIMIT 200")->fetch_all(MYSQLI_ASSOC);
$vendas = $conexao->query("SELECT v.id, v.numero, v.data_venda, v.valor_total, v.situacao, c.nome as cliente_nome FROM vendas v LEFT JOIN clientes c ON v.cliente_id = c.id ORDER BY v.data_venda DESC LIMIT 200")->fetch_all(MYSQLI_ASSOC);
$agendamentos = $conexao->query("SELECT a.id, a.data_agendamento, a.horario_inicio, a.horario_fim, a.status, c.nome as cliente_nome FROM agendamentos a LEFT JOIN clientes c ON a.cliente_id = c.id ORDER BY a.data_agendamento DESC LIMIT 200")->fetch_all(MYSQLI_ASSOC);
$cobrancas = $conexao->query("SELECT c.id, c.numero, c.data_vencimento, c.valor, c.status, c.data_recebimento, cl.nome as cliente_nome FROM cobrancas c LEFT JOIN clientes cl ON c.cliente_id = cl.id ORDER BY c.data_vencimento DESC LIMIT 200")->fetch_all(MYSQLI_ASSOC);
$contratos = $conexao->query("SELECT o.id, o.numero, o.data_assinatura, o.valor_total, c.nome as cliente_nome FROM orcamentos o LEFT JOIN clientes c ON o.cliente_id = c.id WHERE o.assinado = 1 AND o.data_assinatura IS NOT NULL ORDER BY o.data_assinatura DESC LIMIT 200")->fetch_all(MYSQLI_ASSOC);
$logs = $conexao->query("SELECT l.id, l.acao, l.created_at, l.ip, l.tabela, u.nome as usuario_nome FROM logs_acesso l LEFT JOIN usuarios u ON l.usuario_id = u.id ORDER BY l.created_at DESC LIMIT 200")->fetch_all(MYSQLI_ASSOC);

$stats = [
    'orcamentos' => count($orcamentos),
    'pedidos' => count($pedidos),
    'vendas' => count($vendas),
    'agendamentos' => count($agendamentos),
    'cobrancas' => count($cobrancas),
    'contratos' => count($contratos),
    'logs' => count($logs)
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Histórico - Império AR</title>
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
            grid-template-columns: repeat(7, 1fr);
            gap: 15px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.2s;
            cursor: pointer;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }

        .stat-card.active {
            border: 2px solid var(--primary);
            background: rgba(30, 60, 114, 0.05);
        }

        .stat-icon {
            font-size: 28px;
            color: var(--primary);
            margin-bottom: 10px;
        }

        .stat-number {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
        }

        .stat-label {
            font-size: 11px;
            color: var(--gray-600);
            margin-top: 5px;
        }

        /* Tabs - CORRIGIDO */
        .nav-tabs {
            border-bottom: 2px solid var(--gray-300);
            gap: 5px;
            margin-bottom: 25px;
            display: flex;
            flex-wrap: wrap;
        }

        .nav-tabs .nav-link {
            border: none;
            padding: 12px 24px;
            font-weight: 500;
            color: var(--gray-600);
            border-radius: 8px 8px 0 0;
            transition: all 0.2s;
            background: transparent;
            cursor: pointer;
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
            padding: 12px 15px;
            vertical-align: middle;
            border-bottom: 1px solid var(--gray-200);
        }

        .table tbody tr:hover {
            background: var(--gray-100);
        }

        /* Badges */
        .badge-margin {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        .badge-margin.success { background: #d1e7dd; color: #0f5132; }
        .badge-margin.warning { background: #fff3cd; color: #856404; }
        .badge-margin.danger { background: #f8d7da; color: #721c24; }
        .badge-margin.info { background: #cfe2ff; color: #084298; }
        .badge-margin.secondary { background: #e9ecef; color: #495057; }

        .badge-assinado {
            background: var(--success);
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
        }

        .badge-nao-assinado {
            background: var(--gray-600);
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
        }

        /* Buttons */
        .btn-view {
            background: var(--primary);
            color: white;
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 12px;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s;
        }

        .btn-view:hover {
            background: var(--primary-light);
            color: white;
        }

        /* DataTables Custom - MESMO DO TABELA_PRECOS */
        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter {
            margin-bottom: 15px;
        }

        .dataTables_wrapper .dataTables_length select,
        .dataTables_wrapper .dataTables_filter input {
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            padding: 6px 12px;
        }

        .dataTables_wrapper .dataTables_filter input {
            width: 250px;
        }

        .dataTables_wrapper .dataTables_paginate {
            margin-top: 20px;
            text-align: center !important;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button {
            padding: 8px 14px;
            margin: 0 4px;
            border-radius: 8px;
            border: 1px solid var(--gray-300);
            background: white;
            color: var(--gray-800);
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s;
            cursor: pointer;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            background: var(--primary-light);
            border-color: var(--primary-light);
            color: white;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .dataTables_wrapper .dataTables_info {
            color: var(--gray-600);
            font-size: 13px;
            margin-top: 15px;
            text-align: left;
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .stats-grid { grid-template-columns: repeat(4, 1fr); }
        }

        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 15px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .page-header { flex-direction: column; gap: 15px; text-align: center; }
            .table-container { overflow-x: auto; }
            .nav-tabs .nav-link { padding: 8px 12px; font-size: 12px; }
            .dataTables_wrapper .dataTables_filter input { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1>
                    <i class="fas fa-history"></i>
                    Histórico do Sistema
                </h1>
                <div class="user-badge">
                    <i class="fas fa-user-circle me-2"></i>
                    <?php echo htmlspecialchars($usuario['nome'] ?? 'Admin'); ?>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card" onclick="$('#tab-orcamentos').click()">
                    <div class="stat-icon"><i class="fas fa-file-invoice"></i></div>
                    <div class="stat-number"><?php echo $stats['orcamentos']; ?></div>
                    <div class="stat-label">Orçamentos</div>
                </div>
                <div class="stat-card" onclick="$('#tab-pedidos').click()">
                    <div class="stat-icon"><i class="fas fa-shopping-cart"></i></div>
                    <div class="stat-number"><?php echo $stats['pedidos']; ?></div>
                    <div class="stat-label">Pedidos</div>
                </div>
                <div class="stat-card" onclick="$('#tab-vendas').click()">
                    <div class="stat-icon"><i class="fas fa-dollar-sign"></i></div>
                    <div class="stat-number"><?php echo $stats['vendas']; ?></div>
                    <div class="stat-label">Vendas</div>
                </div>
                <div class="stat-card" onclick="$('#tab-agendamentos').click()">
                    <div class="stat-icon"><i class="fas fa-calendar"></i></div>
                    <div class="stat-number"><?php echo $stats['agendamentos']; ?></div>
                    <div class="stat-label">Agendamentos</div>
                </div>
                <div class="stat-card" onclick="$('#tab-cobrancas').click()">
                    <div class="stat-icon"><i class="fas fa-credit-card"></i></div>
                    <div class="stat-number"><?php echo $stats['cobrancas']; ?></div>
                    <div class="stat-label">Cobranças</div>
                </div>
                <div class="stat-card" onclick="$('#tab-contratos').click()">
                    <div class="stat-icon"><i class="fas fa-file-signature"></i></div>
                    <div class="stat-number"><?php echo $stats['contratos']; ?></div>
                    <div class="stat-label">Contratos</div>
                </div>
                <div class="stat-card" onclick="$('#tab-logs').click()">
                    <div class="stat-icon"><i class="fas fa-history"></i></div>
                    <div class="stat-number"><?php echo $stats['logs']; ?></div>
                    <div class="stat-label">Logs</div>
                </div>
            </div>

            <!-- Tabs - CORRIGIDO com role e data-bs-toggle -->
            <ul class="nav nav-tabs" id="historicoTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="tab-orcamentos" data-bs-toggle="tab" data-bs-target="#orcamentos" type="button" role="tab">
                        <i class="fas fa-file-invoice me-2"></i>Orçamentos
                        <span class="badge bg-secondary ms-2"><?php echo $stats['orcamentos']; ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-pedidos" data-bs-toggle="tab" data-bs-target="#pedidos" type="button" role="tab">
                        <i class="fas fa-shopping-cart me-2"></i>Pedidos
                        <span class="badge bg-secondary ms-2"><?php echo $stats['pedidos']; ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-vendas" data-bs-toggle="tab" data-bs-target="#vendas" type="button" role="tab">
                        <i class="fas fa-dollar-sign me-2"></i>Vendas
                        <span class="badge bg-secondary ms-2"><?php echo $stats['vendas']; ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-agendamentos" data-bs-toggle="tab" data-bs-target="#agendamentos" type="button" role="tab">
                        <i class="fas fa-calendar me-2"></i>Agendamentos
                        <span class="badge bg-secondary ms-2"><?php echo $stats['agendamentos']; ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-cobrancas" data-bs-toggle="tab" data-bs-target="#cobrancas" type="button" role="tab">
                        <i class="fas fa-credit-card me-2"></i>Cobranças
                        <span class="badge bg-secondary ms-2"><?php echo $stats['cobrancas']; ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-contratos" data-bs-toggle="tab" data-bs-target="#contratos" type="button" role="tab">
                        <i class="fas fa-file-signature me-2"></i>Contratos
                        <span class="badge bg-secondary ms-2"><?php echo $stats['contratos']; ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-logs" data-bs-toggle="tab" data-bs-target="#logs" type="button" role="tab">
                        <i class="fas fa-history me-2"></i>Logs de Acesso
                        <span class="badge bg-secondary ms-2"><?php echo $stats['logs']; ?></span>
                    </button>
                </li>
            </ul>

            <div class="tab-content">
                <!-- ORÇAMENTOS -->
                <div class="tab-pane fade show active" id="orcamentos" role="tabpanel">
                    <div class="table-container">
                        <div class="table-responsive">
                            <table class="table table-hover" id="table-orcamentos">
                                <thead>
                                    <tr>
                                        <th>Número</th>
                                        <th>Cliente</th>
                                        <th>Data Emissão</th>
                                        <th>Valor</th>
                                        <th>Situação</th>
                                        <th>Assinado</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($orcamentos as $item): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($item['numero']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($item['cliente_nome'] ?? '-'); ?></td>
                                        <td><?php echo formatarData($item['data_emissao']); ?></td>
                                        <td><?php echo formatarMoeda($item['valor_total']); ?></td>
                                        <td><?php echo getStatusBadge($item['situacao'], 'orcamento'); ?></td>
                                        <td>
                                            <?php if ($item['assinado']): ?>
                                            <span class="badge-assinado"><i class="fas fa-check-circle me-1"></i>Assinado</span>
                                            <?php else: ?>
                                            <span class="badge-nao-assinado"><i class="fas fa-clock me-1"></i>Pendente</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="<?php echo BASE_URL; ?>/app/admin/orcamentos.php?acao=editar&id=<?php echo $item['id']; ?>" class="btn-view">
                                                <i class="fas fa-eye"></i> Ver
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- PEDIDOS -->
                <div class="tab-pane fade" id="pedidos" role="tabpanel">
                    <div class="table-container">
                        <div class="table-responsive">
                            <table class="table table-hover" id="table-pedidos">
                                <thead>
                                    <tr>
                                        <th>Número</th>
                                        <th>Cliente</th>
                                        <th>Data Pedido</th>
                                        <th>Valor</th>
                                        <th>Situação</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pedidos as $item): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($item['numero']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($item['cliente_nome'] ?? '-'); ?></td>
                                        <td><?php echo formatarData($item['data_pedido']); ?></td>
                                        <td><?php echo formatarMoeda($item['valor_total']); ?></td>
                                        <td><?php echo getStatusBadge($item['situacao'], 'pedido'); ?></td>
                                        <td>
                                            <a href="<?php echo BASE_URL; ?>/app/admin/pedidos.php?acao=editar&id=<?php echo $item['id']; ?>" class="btn-view">
                                                <i class="fas fa-eye"></i> Ver
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- VENDAS -->
                <div class="tab-pane fade" id="vendas" role="tabpanel">
                    <div class="table-container">
                        <div class="table-responsive">
                            <table class="table table-hover" id="table-vendas">
                                <thead>
                                    <tr>
                                        <th>Número</th>
                                        <th>Cliente</th>
                                        <th>Data Venda</th>
                                        <th>Valor</th>
                                        <th>Situação</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($vendas as $item): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($item['numero']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($item['cliente_nome'] ?? '-'); ?></td>
                                        <td><?php echo formatarData($item['data_venda']); ?></td>
                                        <td><?php echo formatarMoeda($item['valor_total']); ?></td>
                                        <td><?php echo getStatusBadge($item['situacao'], 'pedido'); ?></td>
                                        <td>
                                            <a href="<?php echo BASE_URL; ?>/app/admin/vendas.php?acao=editar&id=<?php echo $item['id']; ?>" class="btn-view">
                                                <i class="fas fa-eye"></i> Ver
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- AGENDAMENTOS -->
                <div class="tab-pane fade" id="agendamentos" role="tabpanel">
                    <div class="table-container">
                        <div class="table-responsive">
                            <table class="table table-hover" id="table-agendamentos">
                                <thead>
                                    <tr>
                                        <th>Cliente</th>
                                        <th>Data</th>
                                        <th>Horário</th>
                                        <th>Status</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($agendamentos as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['cliente_nome'] ?? '-'); ?></td>
                                        <td><?php echo formatarData($item['data_agendamento']); ?></td>
                                        <td><?php echo substr($item['horario_inicio'] ?? '00:00', 0, 5); ?> - <?php echo substr($item['horario_fim'] ?? '00:00', 0, 5); ?></td>
                                        <td><?php echo getStatusBadge($item['status'], 'agendamento'); ?></td>
                                        <td>
                                            <a href="<?php echo BASE_URL; ?>/app/admin/agendamentos.php?acao=editar&id=<?php echo $item['id']; ?>" class="btn-view">
                                                <i class="fas fa-eye"></i> Ver
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- COBRANÇAS -->
                <div class="tab-pane fade" id="cobrancas" role="tabpanel">
                    <div class="table-container">
                        <div class="table-responsive">
                            <table class="table table-hover" id="table-cobrancas">
                                <thead>
                                    <tr>
                                        <th>Número</th>
                                        <th>Cliente</th>
                                        <th>Vencimento</th>
                                        <th>Valor</th>
                                        <th>Status</th>
                                        <th>Recebimento</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cobrancas as $item): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($item['numero']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($item['cliente_nome'] ?? '-'); ?></td>
                                        <td><?php echo formatarData($item['data_vencimento']); ?></td>
                                        <td><?php echo formatarMoeda($item['valor']); ?></td>
                                        <td><?php echo getStatusBadge($item['status'], 'cobranca'); ?></td>
                                        <td><?php echo $item['data_recebimento'] ? formatarData($item['data_recebimento']) : '-'; ?></td>
                                        <td>
                                            <a href="<?php echo BASE_URL; ?>/app/admin/cobrancas.php?acao=editar&id=<?php echo $item['id']; ?>" class="btn-view">
                                                <i class="fas fa-eye"></i> Ver
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- CONTRATOS ASSINADOS -->
                <div class="tab-pane fade" id="contratos" role="tabpanel">
                    <div class="table-container">
                        <div class="table-responsive">
                            <table class="table table-hover" id="table-contratos">
                                <thead>
                                    <tr>
                                        <th>Orçamento</th>
                                        <th>Cliente</th>
                                        <th>Data Assinatura</th>
                                        <th>Valor</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($contratos as $item): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($item['numero']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($item['cliente_nome'] ?? '-'); ?></td>
                                        <td><?php echo formatarData($item['data_assinatura'], true); ?></td>
                                        <td><?php echo formatarMoeda($item['valor_total']); ?></td>
                                        <td>
                                            <a href="<?php echo BASE_URL; ?>/app/admin/orcamentos.php?acao=editar&id=<?php echo $item['id']; ?>" class="btn-view">
                                                <i class="fas fa-eye"></i> Ver
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- LOGS DE ACESSO -->
                <div class="tab-pane fade" id="logs" role="tabpanel">
                    <div class="table-container">
                        <div class="table-responsive">
                            <table class="table table-hover" id="table-logs">
                                <thead>
                                    <tr>
                                        <th>Usuário</th>
                                        <th>Ação</th>
                                        <th>Tabela</th>
                                        <th>Data/Hora</th>
                                        <th>IP</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($logs as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['usuario_nome'] ?? 'Sistema'); ?></td>
                                        <td><span class="badge-margin info"><?php echo htmlspecialchars($item['acao']); ?></span></td>
                                        <td><?php echo htmlspecialchars($item['tabela'] ?? '-'); ?></td>
                                        <td><?php echo formatarData($item['created_at'], true); ?></td>
                                        <td><code><?php echo htmlspecialchars($item['ip']); ?></code></td>
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

    <script>
        $(document).ready(function() {
            // Configuração padrão para todas as DataTables (MESMA DO TABELA_PRECOS)
            var tableConfig = {
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-BR.json'
                },
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
                columnDefs: [{ orderable: false, targets: [-1] }],
                dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
                     '<"row"<"col-sm-12"tr>>' +
                     '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>'
            };
            
            // Inicializar cada tabela
            $('#table-orcamentos').DataTable(tableConfig);
            $('#table-pedidos').DataTable(tableConfig);
            $('#table-vendas').DataTable(tableConfig);
            $('#table-agendamentos').DataTable(tableConfig);
            $('#table-cobrancas').DataTable(tableConfig);
            $('#table-contratos').DataTable(tableConfig);
            $('#table-logs').DataTable(tableConfig);
        });
    </script>
</body>
</html>