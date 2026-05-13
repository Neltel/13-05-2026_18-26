<?php
/**
 * =====================================================================
 * FINANCEIRO - SISTEMA INTEGRADO IMPÉRIO AR
 * =====================================================================
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

// ===== VERIFICAÇÃO DE ACESSO =====
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

// ===== VARIÁVEIS GLOBAIS =====
$acao = $_GET['acao'] ?? 'dashboard';
$id = isset($_GET['id']) ? intval($_GET['id']) : null;
$mensagem = '';
$erro = '';
$transacoes = [];
$transacao = [];
$pagina_atual = isset($_GET['pagina']) ? intval($_GET['pagina']) : 1;
$por_pagina = 50;
$total_paginas = 0;

// ===== FUNÇÕES AUXILIARES =====
function formatarMoeda($valor) {
    if (empty($valor) || !is_numeric($valor)) $valor = 0;
    return 'R$ ' . number_format(floatval($valor), 2, ',', '.');
}

function formatarData($data) {
    if (empty($data)) return '';
    return date('d/m/Y', strtotime($data));
}

function verificarCSRF($token) {
    return isset($_SESSION['csrf_token']) && $token === $_SESSION['csrf_token'];
}

function getTipoBadge($tipo) {
    if ($tipo == 'entrada') {
        return '<span class="badge badge-success"><i class="fas fa-arrow-up"></i> Entrada</span>';
    } else {
        return '<span class="badge badge-danger"><i class="fas fa-arrow-down"></i> Saída</span>';
    }
}

// ===== VERIFICAR E AJUSTAR TABELA FINANCEIRO =====
$check_coluna = $conexao->query("SHOW COLUMNS FROM financeiro LIKE 'data_transacao'");
if ($check_coluna && $check_coluna->num_rows == 0) {
    $check_data = $conexao->query("SHOW COLUMNS FROM financeiro LIKE 'data'");
    if ($check_data && $check_data->num_rows > 0) {
        $conexao->query("ALTER TABLE financeiro CHANGE COLUMN data data_transacao DATE NOT NULL");
    } else {
        $conexao->query("ALTER TABLE financeiro ADD COLUMN data_transacao DATE NOT NULL DEFAULT '2024-01-01'");
    }
}

// Verificar outras colunas
$colunas = [];
$result = $conexao->query("SHOW COLUMNS FROM financeiro");
if ($result) {
    while ($col = $result->fetch_assoc()) {
        $colunas[] = $col['Field'];
    }
}

if (!in_array('categoria', $colunas)) {
    $conexao->query("ALTER TABLE financeiro ADD COLUMN categoria VARCHAR(100) DEFAULT NULL");
}
if (!in_array('forma_pagamento', $colunas)) {
    $conexao->query("ALTER TABLE financeiro ADD COLUMN forma_pagamento VARCHAR(50) DEFAULT NULL");
}
if (!in_array('observacao', $colunas)) {
    $conexao->query("ALTER TABLE financeiro ADD COLUMN observacao TEXT DEFAULT NULL");
}

// ===== CATEGORIAS =====
$categorias_entrada = [
    'vendas' => '💰 Vendas',
    'servicos' => '🔧 Serviços',
    'cobrancas' => '📄 Cobranças',
    'cliente' => '👤 Pagamento Cliente',
    'outras_entradas' => '📌 Outras Entradas'
];

$categorias_saida = [
    'materiais' => '🔧 Materiais/Insumos',
    'fornecedores' => '🏭 Fornecedores',
    'funcionarios' => '👨‍💼 Funcionários',
    'impostos' => '📊 Impostos/Taxas',
    'aluguel' => '🏠 Aluguel',
    'energia' => '⚡ Energia Elétrica',
    'agua' => '💧 Água',
    'internet' => '🌐 Internet/Telefone',
    'marketing' => '📢 Marketing',
    'outras_saidas' => '📌 Outras Saídas'
];

$formas_pagamento = [
    'dinheiro' => 'Dinheiro',
    'pix' => 'PIX',
    'debito' => 'Cartão Débito',
    'credito' => 'Cartão Crédito',
    'boleto' => 'Boleto',
    'transferencia' => 'Transferência',
    'cheque' => 'Cheque'
];

// Carregar clientes
$clientes = [];
$sql_clientes = "SELECT id, nome FROM clientes WHERE ativo = 1 ORDER BY nome ASC";
$result_clientes = $conexao->query($sql_clientes);
if ($result_clientes && $result_clientes->num_rows > 0) {
    while ($row = $result_clientes->fetch_assoc()) {
        $clientes[] = $row;
    }
}

// ===== PROCESSAR AÇÕES POST =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verificarCSRF($_POST['csrf_token'])) {
        $erro = "Token de segurança inválido.";
    } else {
        $acao_post = $_POST['acao'] ?? '';
        
        // ===== LANÇAMENTO VIA IA =====
        if ($acao_post === 'ia_lancamento') {
            $texto_ia = trim($_POST['texto_ia'] ?? '');
            
            if (empty($texto_ia)) {
                $erro = "Digite ou fale a descrição do lançamento";
            } else {
                $texto_lower = strtolower($texto_ia);
                $tipo = 'entrada';
                $categoria = 'outras_entradas';
                $descricao = addslashes($texto_ia);
                $valor = 0;
                
                // Extrair valor
                if (preg_match('/(?:R?\$?\s*)?(\d+(?:[.,]\d{2})?)/', $texto_ia, $matches)) {
                    $valor_str = str_replace(',', '.', $matches[1]);
                    $valor = floatval($valor_str);
                }
                
                // Determinar tipo
                $palavras_saida = ['comprei', 'comprar', 'paguei', 'pagar', 'gastei', 'gastar', 'despesa', 'custo', 'material'];
                foreach ($palavras_saida as $palavra) {
                    if (strpos($texto_lower, $palavra) !== false) {
                        $tipo = 'saida';
                        break;
                    }
                }
                
                // Mapear categorias
                if ($tipo == 'saida') {
                    if (strpos($texto_lower, 'material') !== false) {
                        $categoria = 'materiais';
                    } elseif (strpos($texto_lower, 'funcionario') !== false) {
                        $categoria = 'funcionarios';
                    } elseif (strpos($texto_lower, 'imposto') !== false) {
                        $categoria = 'impostos';
                    } elseif (strpos($texto_lower, 'aluguel') !== false) {
                        $categoria = 'aluguel';
                    } elseif (strpos($texto_lower, 'energia') !== false) {
                        $categoria = 'energia';
                    } else {
                        $categoria = 'outras_saidas';
                    }
                } else {
                    if (strpos($texto_lower, 'venda') !== false) {
                        $categoria = 'vendas';
                    } elseif (strpos($texto_lower, 'cobranca') !== false) {
                        $categoria = 'cobrancas';
                    } elseif (strpos($texto_lower, 'cliente') !== false) {
                        $categoria = 'cliente';
                    } else {
                        $categoria = 'outras_entradas';
                    }
                }
                
                if ($valor <= 0) {
                    $erro = "Não foi possível identificar o valor. Ex: 'Gastei R$ 100,00'";
                } else {
                    $data_hoje = date('Y-m-d');
                    $sql = "INSERT INTO financeiro (tipo, valor, descricao, categoria, data_transacao, forma_pagamento) 
                            VALUES ('$tipo', $valor, '$descricao', '$categoria', '$data_hoje', 'dinheiro')";
                    
                    if ($conexao->query($sql)) {
                        $mensagem = "✓ Lançamento registrado com sucesso via IA!";
                    } else {
                        $erro = "Erro ao registrar: " . $conexao->error;
                    }
                }
            }
        }
        
        // ===== REGISTRAR TRANSAÇÃO MANUAL =====
        if ($acao_post === 'salvar') {
            $tipo = $_POST['tipo'] ?? 'entrada';
            $valor_str = $_POST['valor'] ?? '0';
            $valor = floatval(str_replace(',', '.', str_replace('.', '', $valor_str)));
            $descricao = addslashes($_POST['descricao'] ?? '');
            $categoria = addslashes($_POST['categoria'] ?? '');
            $forma_pagamento = !empty($_POST['forma_pagamento']) ? "'" . addslashes($_POST['forma_pagamento']) . "'" : "NULL";
            $data_transacao = $_POST['data_transacao'] ?? date('Y-m-d');
            $observacao = addslashes($_POST['observacao'] ?? '');
            $cliente_id = !empty($_POST['cliente_id']) ? intval($_POST['cliente_id']) : 'NULL';
            
            $id_editar = isset($_POST['id']) ? intval($_POST['id']) : null;
            
            if ($valor <= 0) {
                $erro = "Valor deve ser maior que zero";
            } elseif (empty($descricao)) {
                $erro = "Descrição é obrigatória";
            } else {
                if ($id_editar) {
                    $sql = "UPDATE financeiro SET 
                            tipo = '$tipo', valor = $valor, descricao = '$descricao', categoria = '$categoria',
                            forma_pagamento = $forma_pagamento, data_transacao = '$data_transacao', 
                            observacao = '$observacao', cliente_id = $cliente_id
                            WHERE id = $id_editar";
                    
                    if ($conexao->query($sql)) {
                        header('Location: ' . BASE_URL . '/app/admin/financeiro.php?acao=listar&mensagem=atualizado');
                        exit;
                    } else {
                        $erro = "Erro ao atualizar: " . $conexao->error;
                    }
                } else {
                    $sql = "INSERT INTO financeiro (tipo, valor, descricao, categoria, forma_pagamento, data_transacao, observacao, cliente_id) 
                            VALUES ('$tipo', $valor, '$descricao', '$categoria', $forma_pagamento, '$data_transacao', '$observacao', $cliente_id)";
                    
                    if ($conexao->query($sql)) {
                        header('Location: ' . BASE_URL . '/app/admin/financeiro.php?acao=listar&mensagem=criado');
                        exit;
                    } else {
                        $erro = "Erro ao registrar: " . $conexao->error;
                    }
                }
            }
        }
        
        // ===== DELETAR TRANSAÇÃO =====
        if ($acao_post === 'deletar' && isset($_POST['id'])) {
            $transacao_id = intval($_POST['id']);
            $sql = "DELETE FROM financeiro WHERE id = $transacao_id";
            
            if ($conexao->query($sql)) {
                header('Location: ' . BASE_URL . '/app/admin/financeiro.php?acao=listar&mensagem=deletado');
                exit;
            } else {
                $erro = "Erro ao deletar transação";
            }
        }
    }
}

// ===== PROCESSAR AÇÕES GET =====
if ($acao === 'deletar' && $id) {
    $sql = "DELETE FROM financeiro WHERE id = $id";
    if ($conexao->query($sql)) {
        header('Location: ' . BASE_URL . '/app/admin/financeiro.php?acao=listar&mensagem=deletado');
        exit;
    }
}

if ($acao === 'editar' && $id) {
    $sql = "SELECT * FROM financeiro WHERE id = $id";
    $result = $conexao->query($sql);
    if ($result && $result->num_rows > 0) {
        $transacao = $result->fetch_assoc();
    } else {
        header('Location: ' . BASE_URL . '/app/admin/financeiro.php?acao=listar');
        exit;
    }
}

if ($acao === 'novo') {
    $transacao = [
        'id' => '', 'tipo' => 'entrada', 'valor' => 0, 'descricao' => '',
        'categoria' => '', 'forma_pagamento' => '', 'data_transacao' => date('Y-m-d'),
        'observacao' => '', 'cliente_id' => ''
    ];
}

// ===== DASHBOARD FINANCEIRO =====
if ($acao === 'dashboard') {
    $data_inicio = $_GET['data_inicio'] ?? date('Y-m-01');
    $data_fim = $_GET['data_fim'] ?? date('Y-m-t');
    
    // Totais do período
    $sql_entradas = "SELECT COALESCE(SUM(valor), 0) as total FROM financeiro 
                     WHERE tipo = 'entrada' AND data_transacao BETWEEN '$data_inicio' AND '$data_fim'";
    $result = $conexao->query($sql_entradas);
    $total_entradas = $result ? $result->fetch_assoc()['total'] : 0;
    
    $sql_saidas = "SELECT COALESCE(SUM(valor), 0) as total FROM financeiro 
                   WHERE tipo = 'saida' AND data_transacao BETWEEN '$data_inicio' AND '$data_fim'";
    $result = $conexao->query($sql_saidas);
    $total_saidas = $result ? $result->fetch_assoc()['total'] : 0;
    $saldo = $total_entradas - $total_saidas;
    
    // Entradas por categoria
    $sql_entradas_cat = "SELECT categoria, COALESCE(SUM(valor), 0) as total 
                         FROM financeiro WHERE tipo = 'entrada' AND data_transacao BETWEEN '$data_inicio' AND '$data_fim' 
                         GROUP BY categoria ORDER BY total DESC";
    $result = $conexao->query($sql_entradas_cat);
    $entradas_por_categoria = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    
    // Saídas por categoria
    $sql_saidas_cat = "SELECT categoria, COALESCE(SUM(valor), 0) as total 
                       FROM financeiro WHERE tipo = 'saida' AND data_transacao BETWEEN '$data_inicio' AND '$data_fim' 
                       GROUP BY categoria ORDER BY total DESC";
    $result = $conexao->query($sql_saidas_cat);
    $saidas_por_categoria = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    
    // Últimas transações
    $sql_ultimas = "SELECT f.*, c.nome as cliente_nome 
                    FROM financeiro f LEFT JOIN clientes c ON f.cliente_id = c.id 
                    ORDER BY f.data_transacao DESC, f.id DESC LIMIT 10";
    $result = $conexao->query($sql_ultimas);
    $ultimas_transacoes = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    
    // Gráfico mensal
    $grafico_mensal = [];
    for ($i = 11; $i >= 0; $i--) {
        $ano_mes = date('Y', strtotime("-$i months"));
        $num_mes = date('m', strtotime("-$i months"));
        $nome_mes = date('M/Y', strtotime("-$i months"));
        
        $sql_mes = "SELECT COALESCE(SUM(CASE WHEN tipo = 'entrada' THEN valor ELSE 0 END), 0) as entradas,
                           COALESCE(SUM(CASE WHEN tipo = 'saida' THEN valor ELSE 0 END), 0) as saidas
                    FROM financeiro WHERE YEAR(data_transacao) = $ano_mes AND MONTH(data_transacao) = $num_mes";
        $result = $conexao->query($sql_mes);
        $row = $result ? $result->fetch_assoc() : ['entradas' => 0, 'saidas' => 0];
        $grafico_mensal[] = ['mes' => $nome_mes, 'entradas' => $row['entradas'], 'saidas' => $row['saidas']];
    }
    
    if (isset($_GET['mensagem'])) {
        $mapa = ['criado' => "✓ Transação registrada!", 'atualizado' => "✓ Transação atualizada!", 'deletado' => "✓ Transação deletada!"];
        $mensagem = $mapa[$_GET['mensagem']] ?? '';
    }
}

// ===== LISTAR TRANSAÇÕES =====
if ($acao === 'listar') {
    $filtro_tipo = $_GET['tipo'] ?? '';
    $filtro_categoria = $_GET['categoria'] ?? '';
    $filtro_data_inicio = $_GET['data_inicio'] ?? date('Y-m-01');
    $filtro_data_fim = $_GET['data_fim'] ?? date('Y-m-d');
    $offset = ($pagina_atual - 1) * $por_pagina;
    
    $sql = "SELECT f.*, c.nome as cliente_nome FROM financeiro f LEFT JOIN clientes c ON f.cliente_id = c.id 
            WHERE f.data_transacao BETWEEN '$filtro_data_inicio' AND '$filtro_data_fim'";
    if ($filtro_tipo) $sql .= " AND f.tipo = '$filtro_tipo'";
    if ($filtro_categoria) $sql .= " AND f.categoria = '$filtro_categoria'";
    $sql .= " ORDER BY f.data_transacao DESC, f.id DESC LIMIT $por_pagina OFFSET $offset";
    
    $result = $conexao->query($sql);
    $transacoes = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    
    // Totais dos filtros
    $sql_totais = "SELECT COALESCE(SUM(CASE WHEN tipo = 'entrada' THEN valor ELSE 0 END), 0) as total_entradas,
                          COALESCE(SUM(CASE WHEN tipo = 'saida' THEN valor ELSE 0 END), 0) as total_saidas
                   FROM financeiro f WHERE f.data_transacao BETWEEN '$filtro_data_inicio' AND '$filtro_data_fim'";
    if ($filtro_tipo) $sql_totais .= " AND f.tipo = '$filtro_tipo'";
    $result = $conexao->query($sql_totais);
    $totais_filtro = $result ? $result->fetch_assoc() : ['total_entradas' => 0, 'total_saidas' => 0];
    
    if (isset($_GET['mensagem'])) {
        $mapa = ['criado' => "✓ Transação registrada!", 'atualizado' => "✓ Transação atualizada!", 'deletado' => "✓ Transação deletada!"];
        $mensagem = $mapa[$_GET['mensagem']] ?? '';
    }
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financeiro - Império AR</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root { --primary: #1e3c72; --secondary: #2a5298; --success: #28a745; --danger: #dc3545; --warning: #ffc107; --info: #17a2b8; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #f5f6fa 0%, #e9ecef 100%); min-height: 100vh; }
        .admin-container { display: flex; min-height: 100vh; }
        .sidebar { width: 300px; background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%); color: white; padding: 20px; position: fixed; height: 100vh; z-index: 1000; overflow-y: auto; }
        .main-content { flex: 1; margin-left: 300px; padding: 30px; overflow-y: auto; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; padding: 20px; background: white; border-radius: 12px; }
        .page-header h1 { color: var(--primary); font-size: 28px; display: flex; align-items: center; gap: 10px; }
        .btn { padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; font-size: 14px; transition: all 0.3s; }
        .btn-primary { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; }
        .btn-success { background: linear-gradient(135deg, var(--success), #34ce57); color: white; }
        .btn-danger { background: linear-gradient(135deg, var(--danger), #e04b5a); color: white; }
        .btn-info { background: linear-gradient(135deg, var(--info), #138496); color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-sm { padding: 6px 12px; font-size: 13px; }
        .btn-group { display: flex; gap: 5px; flex-wrap: wrap; }
        .card { background: white; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); margin-bottom: 30px; overflow: hidden; }
        .card-header { padding: 20px; background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; }
        .card-header h3 { margin: 0; font-size: 18px; display: flex; align-items: center; gap: 10px; }
        .card-body { padding: 20px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #333; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 8px; }
        .form-group input:focus, .form-group select:focus { border-color: var(--primary); outline: none; }
        .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; }
        .table-responsive { overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; background: white; }
        .table thead { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; }
        .table th, .table td { padding: 15px; border-bottom: 1px solid #e0e0e0; text-align: left; }
        .badge { display: inline-block; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-info { background: #cce5ff; color: #004085; }
        .alert { padding: 15px 20px; border-radius: 8px; margin-bottom: 25px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); display: flex; align-items: center; gap: 15px; transition: transform 0.3s; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-icon { width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 28px; }
        .stat-info h3 { font-size: 28px; font-weight: bold; margin: 0; }
        .stat-info p { margin: 5px 0 0; color: #666; }
        .filters { background: white; padding: 20px; border-radius: 12px; margin-bottom: 30px; }
        .filter-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end; }
        .valor-positivo { color: var(--success); font-weight: bold; }
        .valor-negativo { color: var(--danger); font-weight: bold; }
        .charts-section { display: grid; grid-template-columns: repeat(auto-fit, minmax(450px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .chart-container { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .chart-container h3 { margin-bottom: 20px; color: var(--primary); }
        .ia-card { background: linear-gradient(135deg, #6f42c1, #8b5cf6); color: white; }
        .ia-card .card-header { background: rgba(0,0,0,0.1); }
        .ia-card .form-control { background: rgba(255,255,255,0.95); }
        .pagination { display: flex; justify-content: center; gap: 5px; margin-top: 20px; }
        .pagination a { padding: 8px 12px; background: #e9ecef; color: var(--primary); text-decoration: none; border-radius: 4px; }
        .pagination a:hover, .pagination a.active { background: var(--primary); color: white; }
        .empty-message { text-align: center; padding: 50px; background: white; border-radius: 12px; }
        .mic-button { background: #6f42c1; color: white; border: none; border-radius: 50%; width: 50px; height: 50px; font-size: 24px; cursor: pointer; transition: all 0.3s; }
        .mic-button:hover { transform: scale(1.1); }
        .mic-button.recording { background: #dc3545; animation: pulse 1.5s infinite; }
        @keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.1); } 100% { transform: scale(1); } }
        @media (max-width: 768px) { .sidebar { width: 100%; height: auto; position: relative; } .main-content { margin-left: 0; } .form-row, .stats-grid, .charts-section { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>
        <main class="main-content">
            
            <?php if ($mensagem): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($mensagem); ?></div>
            <?php endif; ?>
            <?php if ($erro): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($erro); ?></div>
            <?php endif; ?>

            <?php if ($acao === 'dashboard'): ?>
                
                <div class="page-header">
                    <h1><i class="fas fa-chart-line"></i> Dashboard Financeiro</h1>
                    <div><a href="?acao=listar" class="btn btn-info"><i class="fas fa-list"></i> Extrato</a><a href="?acao=novo" class="btn btn-success"><i class="fas fa-plus-circle"></i> Nova Transação</a><a href="importar_extrato.php" class="btn btn-primary"><i class="fas fa-cloud-upload-alt"></i> Importar Extrato</a></div>
                </div>

                <div class="filters">
                    <form method="GET" class="filter-row"><input type="hidden" name="acao" value="dashboard"><div class="form-group"><label>Data Início</label><input type="date" name="data_inicio" value="<?php echo $data_inicio; ?>"></div><div class="form-group"><label>Data Fim</label><input type="date" name="data_fim" value="<?php echo $data_fim; ?>"></div><div class="form-group"><button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filtrar</button></div></form>
                </div>

                <div class="stats-grid">
                    <div class="stat-card"><div class="stat-icon" style="background: #d4edda; color: #155724;"><i class="fas fa-arrow-up"></i></div><div class="stat-info"><h3 class="valor-positivo"><?php echo formatarMoeda($total_entradas); ?></h3><p>Total de Entradas</p></div></div>
                    <div class="stat-card"><div class="stat-icon" style="background: #f8d7da; color: #721c24;"><i class="fas fa-arrow-down"></i></div><div class="stat-info"><h3 class="valor-negativo"><?php echo formatarMoeda($total_saidas); ?></h3><p>Total de Saídas</p></div></div>
                    <div class="stat-card"><div class="stat-icon" style="background: <?php echo $saldo >= 0 ? '#d4edda' : '#f8d7da'; ?>; color: <?php echo $saldo >= 0 ? '#155724' : '#721c24'; ?>;"><i class="fas fa-wallet"></i></div><div class="stat-info"><h3 class="<?php echo $saldo >= 0 ? 'valor-positivo' : 'valor-negativo'; ?>"><?php echo formatarMoeda($saldo); ?></h3><p>Saldo do Período</p></div></div>
                </div>

                <div class="card ia-card"><div class="card-header"><h3><i class="fas fa-microphone-alt"></i> Lançamento Rápido com IA</h3></div><div class="card-body"><form method="POST" id="form-ia"><input type="hidden" name="acao" value="ia_lancamento"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><div class="form-row"><div class="form-group" style="flex:1;"><label><i class="fas fa-comment-dots"></i> Descreva o lançamento</label><input type="text" name="texto_ia" id="texto_ia" class="form-control" placeholder="Ex: Gastei R$ 150,00 com materiais"></div><div class="form-group" style="width:80px;"><label style="opacity:0;">...</label><button type="button" id="btnMicrofone" class="mic-button"><i class="fas fa-microphone"></i></button></div><div class="form-group" style="width:auto;"><label style="opacity:0;">...</label><button type="submit" class="btn btn-warning"><i class="fas fa-robot"></i> Lançar com IA</button></div></div><small class="text-muted">Ex: "Recebi R$ 500,00 de cliente", "Paguei R$ 200,00 de energia"</small></form></div></div>

                <div class="charts-section"><div class="chart-container"><h3><i class="fas fa-chart-bar"></i> Evolução Mensal</h3><canvas id="chartMensal" style="width:100%; height:300px;"></canvas></div><div class="chart-container"><h3><i class="fas fa-chart-pie"></i> Entradas por Categoria</h3><canvas id="chartEntradas" style="width:100%; height:300px;"></canvas></div><div class="chart-container"><h3><i class="fas fa-chart-pie"></i> Saídas por Categoria</h3><canvas id="chartSaidas" style="width:100%; height:300px;"></canvas></div></div>

                <div class="card"><div class="card-header"><h3><i class="fas fa-history"></i> Últimas Transações</h3></div><div class="card-body"><div class="table-responsive"><table class="table"><thead><tr><th>Data</th><th>Tipo</th><th>Categoria</th><th>Descrição</th><th>Valor</th><th>Forma</th></tr></thead><tbody><?php foreach ($ultimas_transacoes as $item): ?><tr><td><?php echo formatarData($item['data_transacao']); ?></td><td><?php echo getTipoBadge($item['tipo']); ?></td><td><?php $cats = array_merge($categorias_entrada, $categorias_saida); echo $cats[$item['categoria']] ?? ucfirst(str_replace('_', ' ', $item['categoria'] ?? '-')); ?></td><td><?php echo htmlspecialchars($item['descricao']); ?></td><td class="<?php echo $item['tipo'] == 'entrada' ? 'valor-positivo' : 'valor-negativo'; ?>"><?php echo formatarMoeda($item['valor']); ?></td><td><?php echo $formas_pagamento[$item['forma_pagamento']] ?? '-'; ?></td></tr><?php endforeach; ?><?php if (empty($ultimas_transacoes)): ?><tr><td colspan="6" style="text-align:center;">Nenhuma transação encontrada</td></tr><?php endif; ?></tbody></table></div></div></div>

                <script>
                    const graficoMensal = <?php echo json_encode($grafico_mensal); ?>;
                    const entradasCategorias = <?php echo json_encode($entradas_por_categoria); ?>;
                    const saidasCategorias = <?php echo json_encode($saidas_por_categoria); ?>;
                    const totalEntradas = <?php echo $total_entradas ?: 1; ?>;
                    const totalSaidas = <?php echo $total_saidas ?: 1; ?>;
                    
                    new Chart(document.getElementById('chartMensal'), { type: 'line', data: { labels: graficoMensal.map(i => i.mes), datasets: [{ label: 'Entradas', data: graficoMensal.map(i => i.entradas), borderColor: '#28a745', backgroundColor: 'rgba(40,167,69,0.1)', tension: 0.4, fill: true }, { label: 'Saídas', data: graficoMensal.map(i => i.saidas), borderColor: '#dc3545', backgroundColor: 'rgba(220,53,69,0.1)', tension: 0.4, fill: true }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { tooltip: { callbacks: { label: function(ctx) { return 'R$ ' + ctx.raw.toFixed(2).replace('.', ','); } } } } } });
                    if (entradasCategorias.length) new Chart(document.getElementById('chartEntradas'), { type: 'doughnut', data: { labels: entradasCategorias.map(i => i.categoria ? (<?php echo json_encode($categorias_entrada); ?>[i.categoria] || i.categoria.replace('_', ' ')) : 'Outros'), datasets: [{ data: entradasCategorias.map(i => i.total), backgroundColor: ['#28a745', '#20c997', '#17a2b8', '#ffc107', '#6c757d'] }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { tooltip: { callbacks: { label: function(ctx) { return ctx.label + ': R$ ' + ctx.raw.toFixed(2).replace('.', ',') + ' (' + Math.round(ctx.raw / totalEntradas * 100) + '%)'; } } } } } });
                    if (saidasCategorias.length) new Chart(document.getElementById('chartSaidas'), { type: 'doughnut', data: { labels: saidasCategorias.map(i => i.categoria ? (<?php echo json_encode($categorias_saida); ?>[i.categoria] || i.categoria.replace('_', ' ')) : 'Outros'), datasets: [{ data: saidasCategorias.map(i => i.total), backgroundColor: ['#dc3545', '#fd7e14', '#ffc107', '#6c757d', '#17a2b8'] }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { tooltip: { callbacks: { label: function(ctx) { return ctx.label + ': R$ ' + ctx.raw.toFixed(2).replace('.', ',') + ' (' + Math.round(ctx.raw / totalSaidas * 100) + '%)'; } } } } } });
                    
                    const btnMicrofone = document.getElementById('btnMicrofone'), textoIA = document.getElementById('texto_ia'); let recognition = null;
                    if ('webkitSpeechRecognition' in window || 'SpeechRecognition' in window) { const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition; recognition = new SpeechRecognition(); recognition.lang = 'pt-BR'; recognition.continuous = false; recognition.interimResults = false; recognition.onstart = function() { btnMicrofone.classList.add('recording'); btnMicrofone.innerHTML = '<i class="fas fa-microphone-slash"></i>'; }; recognition.onend = function() { btnMicrofone.classList.remove('recording'); btnMicrofone.innerHTML = '<i class="fas fa-microphone"></i>'; }; recognition.onresult = function(event) { textoIA.value = event.results[0][0].transcript; }; recognition.onerror = function() { btnMicrofone.classList.remove('recording'); btnMicrofone.innerHTML = '<i class="fas fa-microphone"></i>'; alert('Erro no microfone'); }; btnMicrofone.addEventListener('click', function() { if (btnMicrofone.classList.contains('recording')) recognition.stop(); else recognition.start(); }); } else { btnMicrofone.style.display = 'none'; }
                </script>

            <?php elseif ($acao === 'listar'): ?>
                
                <div class="page-header">
                    <h1><i class="fas fa-list"></i> Extrato Financeiro</h1>
                    <div><a href="?acao=dashboard" class="btn btn-info"><i class="fas fa-chart-line"></i> Dashboard</a><a href="?acao=novo" class="btn btn-success"><i class="fas fa-plus-circle"></i> Nova Transação</a><a href="importar_extrato.php" class="btn btn-primary"><i class="fas fa-cloud-upload-alt"></i> Importar Extrato</a></div>
                </div>

                <div class="stats-grid"><div class="stat-card"><div class="stat-info"><h3 class="valor-positivo"><?php echo formatarMoeda($totais_filtro['total_entradas'] ?? 0); ?></h3><p>Entradas no período</p></div></div><div class="stat-card"><div class="stat-info"><h3 class="valor-negativo"><?php echo formatarMoeda($totais_filtro['total_saidas'] ?? 0); ?></h3><p>Saídas no período</p></div></div><div class="stat-card"><div class="stat-info"><h3 class="<?php echo (($totais_filtro['total_entradas'] ?? 0) - ($totais_filtro['total_saidas'] ?? 0)) >= 0 ? 'valor-positivo' : 'valor-negativo'; ?>"><?php echo formatarMoeda(($totais_filtro['total_entradas'] ?? 0) - ($totais_filtro['total_saidas'] ?? 0)); ?></h3><p>Saldo no período</p></div></div></div>

                <div class="filters"><form method="GET" class="filter-row"><input type="hidden" name="acao" value="listar"><div class="form-group"><label>Tipo</label><select name="tipo"><option value="">Todos</option><option value="entrada">Entradas</option><option value="saida">Saídas</option></select></div><div class="form-group"><label>Categoria</label><select name="categoria"><option value="">Todas</option><optgroup label="Entradas"><?php foreach ($categorias_entrada as $key => $cat): ?><option value="<?php echo $key; ?>"><?php echo $cat; ?></option><?php endforeach; ?></optgroup><optgroup label="Saídas"><?php foreach ($categorias_saida as $key => $cat): ?><option value="<?php echo $key; ?>"><?php echo $cat; ?></option><?php endforeach; ?></optgroup></select></div><div class="form-group"><label>Data Início</label><input type="date" name="data_inicio" value="<?php echo $filtro_data_inicio ?? date('Y-m-01'); ?>"></div><div class="form-group"><label>Data Fim</label><input type="date" name="data_fim" value="<?php echo $filtro_data_fim ?? date('Y-m-d'); ?>"></div><div class="form-group"><button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filtrar</button></div></form></div>

                <?php if (!empty($transacoes)): ?>
                <div class="card"><div class="table-responsive"><table class="table"><thead><tr><th>Data</th><th>Tipo</th><th>Categoria</th><th>Descrição</th><th>Valor</th><th>Forma</th><th>Cliente</th><th>Ações</th></tr></thead><tbody><?php foreach ($transacoes as $item): ?><tr><td><?php echo formatarData($item['data_transacao']); ?></td><td><?php echo getTipoBadge($item['tipo']); ?></td><td><?php $cats = array_merge($categorias_entrada, $categorias_saida); echo $cats[$item['categoria']] ?? ucfirst(str_replace('_', ' ', $item['categoria'] ?? '-')); ?></td><td><?php echo htmlspecialchars($item['descricao']); ?></td><td class="<?php echo $item['tipo'] == 'entrada' ? 'valor-positivo' : 'valor-negativo'; ?>"><?php echo formatarMoeda($item['valor']); ?></td><td><?php echo $formas_pagamento[$item['forma_pagamento']] ?? '-'; ?></td><td><?php echo htmlspecialchars($item['cliente_nome'] ?? '-'); ?></td><td><div class="btn-group"><a href="?acao=editar&id=<?php echo $item['id']; ?>" class="btn btn-sm btn-primary"><i class="fas fa-edit"></i></a><form method="POST" style="display:inline" onsubmit="return confirm('Excluir esta transação?')"><input type="hidden" name="acao" value="deletar"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="id" value="<?php echo $item['id']; ?>"><button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button></form></div></td></tr><?php endforeach; ?></tbody></table></div></div>
                <?php else: ?>
                <div class="empty-message"><i class="fas fa-chart-line fa-4x" style="color:#ccc;"></i><h3>Nenhuma transação encontrada</h3><a href="?acao=novo" class="btn btn-success">Nova Transação</a></div>
                <?php endif; ?>

            <?php elseif ($acao === 'novo' || $acao === 'editar'): ?>

                <div class="page-header"><h1><i class="fas fa-<?php echo $acao === 'novo' ? 'plus-circle' : 'edit'; ?>"></i> <?php echo $acao === 'novo' ? 'Nova Transação' : 'Editar Transação'; ?></h1><a href="?acao=listar" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Voltar</a></div>

                <div class="card"><div class="card-header"><h3><i class="fas fa-coins"></i> Informações da Transação</h3></div><div class="card-body"><form method="POST"><input type="hidden" name="acao" value="salvar"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><?php if ($acao === 'editar'): ?><input type="hidden" name="id" value="<?php echo $transacao['id']; ?>"><?php endif; ?>
                    <div class="form-row"><div class="form-group"><label>Tipo *</label><select name="tipo" id="tipo" required onchange="mudarCategorias()"><option value="entrada" <?php echo ($transacao['tipo'] ?? '') == 'entrada' ? 'selected' : ''; ?>>Entrada</option><option value="saida" <?php echo ($transacao['tipo'] ?? '') == 'saida' ? 'selected' : ''; ?>>Saída</option></select></div><div class="form-group"><label>Data *</label><input type="date" name="data_transacao" value="<?php echo $transacao['data_transacao'] ?? date('Y-m-d'); ?>" required></div><div class="form-group"><label>Valor *</label><input type="text" name="valor" class="money" value="<?php echo isset($transacao['valor']) ? number_format($transacao['valor'], 2, ',', '.') : '0,00'; ?>" required></div></div>
                    <div class="form-row"><div class="form-group"><label>Categoria *</label><select name="categoria" id="categoria" required><option value="">Selecione</option></select></div><div class="form-group"><label>Forma de Pagamento</label><select name="forma_pagamento"><option value="">Selecione</option><?php foreach ($formas_pagamento as $key => $val): ?><option value="<?php echo $key; ?>" <?php echo ($transacao['forma_pagamento'] ?? '') == $key ? 'selected' : ''; ?>><?php echo $val; ?></option><?php endforeach; ?></select></div></div>
                    <div class="form-group"><label>Cliente (opcional)</label><select name="cliente_id"><option value="">Nenhum</option><?php foreach ($clientes as $cli): ?><option value="<?php echo $cli['id']; ?>" <?php echo ($transacao['cliente_id'] ?? '') == $cli['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cli['nome']); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Descrição *</label><input type="text" name="descricao" value="<?php echo htmlspecialchars($transacao['descricao'] ?? ''); ?>" placeholder="Descrição" required></div>
                    <div class="form-group"><label>Observações</label><textarea name="observacao" rows="3"><?php echo htmlspecialchars($transacao['observacao'] ?? ''); ?></textarea></div>
                    <div style="margin-top:30px; text-align:right;"><button type="submit" class="btn btn-success">Salvar</button> <a href="?acao=listar" class="btn btn-secondary">Cancelar</a></div>
                </form></div></div>

                <script>
                    const categoriasEntrada = <?php echo json_encode($categorias_entrada); ?>;
                    const categoriasSaida = <?php echo json_encode($categorias_saida); ?>;
                    const categoriaAtual = '<?php echo $transacao['categoria'] ?? ''; ?>';
                    function mudarCategorias() { const tipo = document.getElementById('tipo').value; const categoriaSelect = document.getElementById('categoria'); const categorias = tipo === 'entrada' ? categoriasEntrada : categoriasSaida; categoriaSelect.innerHTML = '<option value="">Selecione</option>'; for (const [key, value] of Object.entries(categorias)) { const selected = (key === categoriaAtual) ? 'selected' : ''; categoriaSelect.innerHTML += `<option value="${key}" ${selected}>${value}</option>`; } }
                    document.querySelectorAll('.money').forEach(input => { input.addEventListener('input', function(e) { let value = e.target.value.replace(/\D/g, ''); if (value === '') { e.target.value = '0,00'; return; } if (value.length > 2) { const reais = value.slice(0, -2); const centavos = value.slice(-2); value = reais + ',' + centavos; } else if (value.length === 2) value = '0,' + value; else if (value.length === 1) value = '0,0' + value; value = value.replace(/\B(?=(\d{3})+(?!\d))/g, '.'); e.target.value = value; }); });
                    mudarCategorias();
                </script>

            <?php endif; ?>
        </main>
    </div>
</body>
</html>