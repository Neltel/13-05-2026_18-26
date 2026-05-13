<?php
/**
 * =====================================================================
 * DASHBOARD ADMIN - VERSÃO COMPLETA CORRIGIDA
 * =====================================================================
 */

require_once __DIR__ . '/../../config/environment.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';

session_start();

// Verifica autenticação
if (!Auth::isLogado() || !Auth::isAdmin()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$usuario = Auth::obter_usuario();
global $conexao;

if (!$conexao) {
    die("Erro de conexão com banco de dados");
}

// ===== FUNÇÕES AUXILIARES =====
function formatarMoeda($valor) {
    if (empty($valor) || !is_numeric($valor)) $valor = 0;
    return 'R$ ' . number_format(floatval($valor), 2, ',', '.');
}

function formatarData($data) {
    if (empty($data)) return '';
    return date('d/m/Y', strtotime($data));
}

// Inicializa variáveis
$erro = null;
$stats = [];
$alertas = [];
$vendas_por_mes = [];
$cobrancas_stats = [];

try {
    // ===== ESTATÍSTICAS GERAIS =====
    
    // Total de clientes ativos
    $result = $conexao->query("SELECT COUNT(*) as total FROM clientes WHERE ativo = 1");
    $stats['clientes'] = $result ? ($result->fetch_assoc()['total'] ?? 0) : 0;

    // Orçamentos pendentes
    $result = $conexao->query("SELECT COUNT(*) as total FROM orcamentos WHERE situacao = 'pendente'");
    $stats['orcamentos_pendentes'] = $result ? ($result->fetch_assoc()['total'] ?? 0) : 0;

    // Pedidos em andamento
    $result = $conexao->query("SELECT COUNT(*) as total FROM pedidos WHERE situacao = 'em_andamento'");
    $stats['pedidos_andamento'] = $result ? ($result->fetch_assoc()['total'] ?? 0) : 0;

    // Agendamentos hoje
    $hoje = date('Y-m-d');
    $result = $conexao->query("SELECT COUNT(*) as total FROM agendamentos WHERE data_agendamento = '$hoje' AND status != 'cancelado'");
    $stats['agendamentos_hoje'] = $result ? ($result->fetch_assoc()['total'] ?? 0) : 0;

    // Vendas do mês
    $mes_atual = date('m');
    $ano_atual = date('Y');
    $result = $conexao->query("SELECT COALESCE(SUM(valor_total), 0) as total FROM vendas WHERE MONTH(data_venda) = $mes_atual AND YEAR(data_venda) = $ano_atual");
    $stats['vendas_mes'] = $result ? ($result->fetch_assoc()['total'] ?? 0) : 0;

    // Total a receber (cobranças pendentes + vencidas)
    $result = $conexao->query("SELECT COALESCE(SUM(valor), 0) as total FROM cobrancas WHERE status IN ('pendente', 'vencida')");
    $stats['a_receber'] = $result ? ($result->fetch_assoc()['total'] ?? 0) : 0;

// ===== ALERTAS CORRIGIDOS =====
$alertas = [];

// 1. Agendamentos de hoje
$sql_agend_hoje = "SELECT a.*, c.nome as cliente_nome, s.nome as servico_nome
                   FROM agendamentos a
                   LEFT JOIN clientes c ON a.cliente_id = c.id
                   LEFT JOIN servicos s ON a.servico_id = s.id
                   WHERE a.data_agendamento = CURDATE() 
                     AND a.status NOT IN ('cancelado', 'finalizado')
                   ORDER BY a.horario_inicio ASC";
$result = $conexao->query($sql_agend_hoje);
$alertas['agendamentos_hoje'] = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

// 2. Orçamentos aprovados que NÃO geraram pedido (ou pedidos sem agendamento)
$sql_orc_sem_agend = "
    SELECT o.id, o.numero, c.nome as cliente_nome, o.data_emissao, o.valor_total
    FROM orcamentos o
    LEFT JOIN clientes c ON o.cliente_id = c.id
    LEFT JOIN pedidos p ON o.id = p.orcamento_origem_id
    WHERE o.situacao = 'aprovado' 
      AND (p.id IS NULL OR NOT EXISTS (
          SELECT 1 FROM agendamentos a WHERE a.pedido_id = p.id
      ))
    ORDER BY o.data_emissao DESC
    LIMIT 10";
$result = $conexao->query($sql_orc_sem_agend);
$alertas['orcamentos_sem_agendamento'] = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

// 3. Cobranças em atraso (data_vencimento < hoje)
$sql_cob_atraso = "
    SELECT c.*, cl.nome as cliente_nome
    FROM cobrancas c
    LEFT JOIN clientes cl ON c.cliente_id = cl.id
    WHERE c.status IN ('pendente', 'vencida') 
      AND c.data_vencimento < CURDATE()
    ORDER BY c.data_vencimento ASC
    LIMIT 10";
$result = $conexao->query($sql_cob_atraso);
$alertas['cobrancas_vencidas'] = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

// 4. Vendas finalizadas sem cobrança recebida (últimos 30 dias)
$sql_serv_nao_pagos = "
    SELECT v.id, v.numero, v.data_venda, v.valor_total, c.nome as cliente_nome,
           DATEDIFF(CURDATE(), v.data_venda) as dias_desde_conclusao
    FROM vendas v
    LEFT JOIN clientes c ON v.cliente_id = c.id
    LEFT JOIN cobrancas cob ON (v.id = cob.venda_id OR v.orcamento_origem_id = cob.orcamento_id)
    WHERE v.situacao = 'finalizado'
      AND v.data_venda >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
      AND (cob.id IS NULL OR cob.status != 'recebida')
    GROUP BY v.id
    ORDER BY v.data_venda DESC
    LIMIT 10";
$result = $conexao->query($sql_serv_nao_pagos);
$alertas['servicos_nao_pagos'] = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

    // ===== GRÁFICOS =====
    
    // Vendas por mês (últimos 6 meses)
    for ($i = 5; $i >= 0; $i--) {
        $mes = date('m', strtotime("-$i months"));
        $ano = date('Y', strtotime("-$i months"));
        $nome_mes = strftime('%b', strtotime("-$i months"));
        
        $result = $conexao->query("SELECT COALESCE(SUM(valor_total), 0) as total FROM vendas WHERE MONTH(data_venda) = $mes AND YEAR(data_venda) = $ano");
        $total = $result ? ($result->fetch_assoc()['total'] ?? 0) : 0;
        
        $vendas_por_mes[] = [
            'mes' => $nome_mes,
            'total' => $total
        ];
    }

    // Status de cobranças
    $result = $conexao->query("
        SELECT status, COUNT(*) as total, COALESCE(SUM(valor), 0) as valor_total 
        FROM cobrancas 
        GROUP BY status");
    $cobrancas_stats = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

    // Últimos logs de acesso
    $logs_acesso = [];
    $result = $conexao->query("
        SELECT l.*, u.nome 
        FROM logs_acesso l 
        LEFT JOIN usuarios u ON l.usuario_id = u.id 
        ORDER BY l.created_at DESC 
        LIMIT 5");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $logs_acesso[] = $row;
        }
    }

} catch (Exception $e) {
    $erro = $e->getMessage();
    error_log("Erro no dashboard: " . $e->getMessage());
}

// Mapeamento de meses em português
$meses_pt = [
    'Jan' => 'Jan',
    'Feb' => 'Fev',
    'Mar' => 'Mar',
    'Apr' => 'Abr',
    'May' => 'Mai',
    'Jun' => 'Jun',
    'Jul' => 'Jul',
    'Aug' => 'Ago',
    'Sep' => 'Set',
    'Oct' => 'Out',
    'Nov' => 'Nov',
    'Dec' => 'Dez'
];

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin - Império AR</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #1e3c72;
            --secondary: #2a5298;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f6fa 0%, #e9ecef 100%);
        }

        .admin-container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 300px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 20px;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
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
            padding: 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .page-header h1 {
            color: var(--primary);
            font-size: 28px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            gap: 20px;
            transition: transform 0.3s;
            border-left: 5px solid var(--primary);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            background: rgba(30, 60, 114, 0.1);
            color: var(--primary);
        }

        .stat-info h3 {
            font-size: 28px;
            font-weight: bold;
            margin: 0;
            color: var(--primary);
        }

        .stat-info p {
            margin: 5px 0 0;
            color: #666;
            font-size: 14px;
        }

        .alerts-section {
            margin-top: 40px;
        }

        .alerts-section h2 {
            color: var(--primary);
            margin-bottom: 20px;
            font-size: 24px;
        }

        .alert-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(380px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .alert-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        .alert-header {
            padding: 15px 20px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 16px;
        }

        .alert-header.warning { background: #fff3cd; color: #856404; border-left: 5px solid #ffc107; }
        .alert-header.danger { background: #f8d7da; color: #721c24; border-left: 5px solid #dc3545; }
        .alert-header.info { background: #cce5ff; color: #004085; border-left: 5px solid #17a2b8; }
        .alert-header.success { background: #d4edda; color: #155724; border-left: 5px solid #28a745; }

        .alert-body {
            padding: 15px 20px;
            max-height: 300px;
            overflow-y: auto;
        }

        .alert-item {
            padding: 12px 0;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .alert-item:last-child {
            border-bottom: none;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
            border-radius: 4px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            border: none;
            cursor: pointer;
        }

        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--secondary); }
        .btn-success { background: var(--success); color: white; }
        .btn-warning { background: var(--warning); color: #333; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-info { background: var(--info); color: white; }

        .charts-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }

        .chart-container {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        .chart-container h3 {
            margin-bottom: 20px;
            color: var(--primary);
            font-size: 18px;
        }

        .empty-message {
            color: #999;
            text-align: center;
            padding: 30px;
            font-style: italic;
        }

        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: normal;
        }

        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-info { background: #cce5ff; color: #004085; }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #f5c6cb;
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }
            .main-content {
                margin-left: 0;
            }
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .alert-grid {
                grid-template-columns: 1fr;
            }
            .charts-section {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <main class="main-content">
            
            <?php if ($erro): ?>
            <div class="alert-error">
                <i class="fas fa-exclamation-circle"></i>
                Erro ao carregar dados: <?php echo htmlspecialchars($erro); ?>
            </div>
            <?php endif; ?>

            <div class="page-header">
                <h1>
                    <i class="fas fa-chart-line"></i>
                    Dashboard - <?php echo date('d/m/Y'); ?>
                </h1>
                <div>
                    <span style="background: var(--primary); color: white; padding: 10px 20px; border-radius: 8px;">
                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($usuario['nome'] ?? 'Admin'); ?>
                    </span>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo number_format($stats['clientes'] ?? 0); ?></h3>
                        <p>Clientes Ativos</p>
                    </div>
                </div>

                <div class="stat-card" style="border-left-color: #ffc107;">
                    <div class="stat-icon" style="color: #ffc107;">
                        <i class="fas fa-file-invoice"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['orcamentos_pendentes'] ?? 0; ?></h3>
                        <p>Orçamentos Pendentes</p>
                    </div>
                </div>

                <div class="stat-card" style="border-left-color: #17a2b8;">
                    <div class="stat-icon" style="color: #17a2b8;">
                        <i class="fas fa-spinner"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['pedidos_andamento'] ?? 0; ?></h3>
                        <p>Pedidos em Andamento</p>
                    </div>
                </div>

                <div class="stat-card" style="border-left-color: #28a745;">
                    <div class="stat-icon" style="color: #28a745;">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['agendamentos_hoje'] ?? 0; ?></h3>
                        <p>Agendamentos Hoje</p>
                    </div>
                </div>

                <div class="stat-card" style="border-left-color: #28a745;">
                    <div class="stat-icon" style="color: #28a745;">
                        <i class="fas fa-dollar-sign"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo formatarMoeda($stats['vendas_mes'] ?? 0); ?></h3>
                        <p>Vendas do Mês</p>
                    </div>
                </div>

                <div class="stat-card" style="border-left-color: #dc3545;">
                    <div class="stat-icon" style="color: #dc3545;">
                        <i class="fas fa-credit-card"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo formatarMoeda($stats['a_receber'] ?? 0); ?></h3>
                        <p>A Receber</p>
                    </div>
                </div>
            </div>

            <!-- Alertas Section -->
            <div class="alerts-section">
                <h2><i class="fas fa-bell"></i> Alertas e Pendências</h2>

                <div class="alert-grid">
                    <!-- Agendamentos de Hoje -->
                    <div class="alert-card">
                        <div class="alert-header success">
                            <i class="fas fa-calendar-day"></i>
                            <span>Agendamentos de Hoje (<?php echo count($alertas['agendamentos_hoje'] ?? []); ?>)</span>
                        </div>
                        <div class="alert-body">
                            <?php if (empty($alertas['agendamentos_hoje'])): ?>
                                <p class="empty-message">Nenhum agendamento para hoje</p>
                            <?php else: ?>
                                <?php foreach ($alertas['agendamentos_hoje'] as $ag): ?>
                                <div class="alert-item">
                                    <div>
                                        <strong><?php echo substr($ag['horario_inicio'] ?? '00:00', 0, 5); ?></strong> - 
                                        <?php echo htmlspecialchars($ag['cliente_nome'] ?? 'N/I'); ?><br>
                                        <small><?php echo htmlspecialchars($ag['servico_nome'] ?? 'Serviço não especificado'); ?></small>
                                    </div>
                                    <a href="agendamentos.php?acao=editar&id=<?php echo $ag['id']; ?>" class="btn-sm btn-primary">
                                        <i class="fas fa-eye"></i> Ver
                                    </a>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Orçamentos sem Agendamento -->
                    <div class="alert-card">
                        <div class="alert-header warning">
                            <i class="fas fa-calendar-times"></i>
                            <span>Orçamentos sem Agendamento (<?php echo count($alertas['orcamentos_sem_agendamento'] ?? []); ?>)</span>
                        </div>
                        <div class="alert-body">
                            <?php if (empty($alertas['orcamentos_sem_agendamento'])): ?>
                                <p class="empty-message">Nenhum orçamento pendente de agendamento</p>
                            <?php else: ?>
                                <?php foreach ($alertas['orcamentos_sem_agendamento'] as $item): ?>
                                <div class="alert-item">
                                    <div>
                                        <strong><?php echo htmlspecialchars($item['numero'] ?? '#' . $item['id']); ?></strong> - 
                                        <?php echo htmlspecialchars($item['cliente_nome'] ?? 'N/I'); ?><br>
                                        <small>Emissão: <?php echo formatarData($item['data_emissao'] ?? ''); ?></small>
                                    </div>
                                    <a href="pedidos.php?acao=novo&orcamento_id=<?php echo $item['id']; ?>" class="btn-sm btn-warning">
                                        <i class="fas fa-calendar-plus"></i> Agendar
                                    </a>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Cobranças em Atraso -->
                    <div class="alert-card">
                        <div class="alert-header danger">
                            <i class="fas fa-exclamation-triangle"></i>
                            <span>Cobranças em Atraso (<?php echo count($alertas['cobrancas_vencidas'] ?? []); ?>)</span>
                        </div>
                        <div class="alert-body">
                            <?php if (empty($alertas['cobrancas_vencidas'])): ?>
                                <p class="empty-message">Nenhuma cobrança em atraso</p>
                            <?php else: ?>
                                <?php foreach ($alertas['cobrancas_vencidas'] as $cob): ?>
                                <div class="alert-item">
                                    <div>
                                        <strong><?php echo formatarMoeda($cob['valor'] ?? 0); ?></strong> - 
                                        <?php echo htmlspecialchars($cob['cliente_nome'] ?? 'N/I'); ?><br>
                                        <small>Vencimento: <?php echo formatarData($cob['data_vencimento'] ?? ''); ?></small>
                                    </div>
                                    <div>
                                        <a href="cobrancas.php?acao=baixar&id=<?php echo $cob['id']; ?>" class="btn-sm btn-success" title="Receber">
                                            <i class="fas fa-check"></i>
                                        </a>
                                        <a href="cobrancas.php?acao=editar&id=<?php echo $cob['id']; ?>" class="btn-sm btn-primary" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Serviços Concluídos Não Pagos -->
                    <div class="alert-card">
                        <div class="alert-header info">
                            <i class="fas fa-clock"></i>
                            <span>Serviços Concluídos Não Pagos (<?php echo count($alertas['servicos_nao_pagos'] ?? []); ?>)</span>
                        </div>
                        <div class="alert-body">
                            <?php if (empty($alertas['servicos_nao_pagos'])): ?>
                                <p class="empty-message">Nenhum serviço concluído aguardando pagamento</p>
                            <?php else: ?>
                                <?php foreach ($alertas['servicos_nao_pagos'] as $venda): ?>
                                <div class="alert-item">
                                    <div>
                                        <strong><?php echo formatarMoeda($venda['valor_total'] ?? 0); ?></strong> - 
                                        <?php echo htmlspecialchars($venda['cliente_nome'] ?? 'N/I'); ?><br>
                                        <small>Concluído: <?php echo formatarData($venda['data_venda'] ?? ''); ?> (há <?php echo $venda['dias_desde_conclusao'] ?? 0; ?> dias)</small>
                                    </div>
                                    <a href="cobrancas.php?acao=novo&venda_id=<?php echo $venda['id']; ?>" class="btn-sm btn-success">
                                        <i class="fas fa-credit-card"></i> Cobrar
                                    </a>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Gráficos -->
            <div class="charts-section">
                <div class="chart-container">
                    <h3><i class="fas fa-chart-line"></i> Vendas dos Últimos 6 Meses</h3>
                    <canvas id="chartVendas" style="width:100%; max-height:300px;"></canvas>
                </div>

                <div class="chart-container">
                    <h3><i class="fas fa-chart-pie"></i> Status das Cobranças</h3>
                    <canvas id="chartCobrancas" style="width:100%; max-height:300px;"></canvas>
                </div>
            </div>

            <!-- Últimas Atividades -->
            <div class="alert-card" style="margin-top: 30px;">
                <div class="alert-header info">
                    <i class="fas fa-history"></i>
                    <span>Últimos Acessos</span>
                </div>
                <div class="alert-body">
                    <?php if (empty($logs_acesso)): ?>
                        <p class="empty-message">Nenhum log encontrado</p>
                    <?php else: ?>
                        <?php foreach ($logs_acesso as $log): ?>
                        <div class="alert-item">
                            <div>
                                <strong><?php echo htmlspecialchars($log['nome'] ?? 'Sistema'); ?></strong>
                                <br>
                                <small>
                                    <i class="fas fa-clock"></i> <?php echo date('d/m/Y H:i', strtotime($log['created_at'] ?? 'now')); ?> - 
                                    <?php echo $log['acao'] ?? 'Acesso'; ?>
                                </small>
                            </div>
                            <span class="badge badge-info">IP: <?php echo $log['ip'] ?? '0.0.0.0'; ?></span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Gráfico de Vendas
            const ctxVendas = document.getElementById('chartVendas');
            if (ctxVendas) {
                const vendasData = <?php echo json_encode($vendas_por_mes); ?>;
                const labels = vendasData.map(item => {
                    // Traduz meses
                    const mesMap = {
                        'Jan': 'Jan', 'Feb': 'Fev', 'Mar': 'Mar', 'Apr': 'Abr',
                        'May': 'Mai', 'Jun': 'Jun', 'Jul': 'Jul', 'Aug': 'Ago',
                        'Sep': 'Set', 'Oct': 'Out', 'Nov': 'Nov', 'Dec': 'Dez'
                    };
                    return mesMap[item.mes] || item.mes;
                });
                const values = vendasData.map(item => item.total);

                new Chart(ctxVendas.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Vendas (R$)',
                            data: values,
                            borderColor: '#1e3c72',
                            backgroundColor: 'rgba(30, 60, 114, 0.1)',
                            tension: 0.4,
                            fill: true
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return 'R$ ' + context.raw.toFixed(2).replace('.', ',');
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Gráfico de Cobranças
            const ctxCobrancas = document.getElementById('chartCobrancas');
            if (ctxCobrancas) {
                const cobrancasData = <?php echo json_encode($cobrancas_stats); ?>;
                const statusMap = {
                    'pendente': 'Pendente',
                    'vencida': 'Vencida',
                    'recebida': 'Recebida',
                    'cancelada': 'Cancelada',
                    'a_receber': 'A Receber'
                };
                
                const labels = cobrancasData.map(item => statusMap[item.status] || item.status);
                const values = cobrancasData.map(item => parseInt(item.total));

                new Chart(ctxCobrancas.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: labels,
                        datasets: [{
                            data: values,
                            backgroundColor: [
                                '#ffc107', // pendente
                                '#dc3545', // vencida
                                '#28a745', // recebida
                                '#6c757d', // cancelada
                                '#17a2b8'  // a_receber
                            ]
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom'
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return context.raw + ' cobrança(s)';
                                    }
                                }
                            }
                        }
                    }
                });
            }
        });
    </script>
</body>
</html>
