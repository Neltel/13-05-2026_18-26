<?php
/**
 * =====================================================================
 * GESTÃO FISCAL - SISTEMA INTEGRADO IMPÉRIO AR
 * =====================================================================
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

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

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$usuario = Auth::obter_usuario();
global $conexao;

if (!$conexao) {
    die("Erro de conexão com banco de dados");
}

// ===== VERIFICAR E AJUSTAR TABELAS =====
$tabelas_necessarias = ['ajudantes', 'financeiro_arquivos', 'pagamentos_ajudantes'];
foreach ($tabelas_necessarias as $tabela) {
    $check = $conexao->query("SHOW TABLES LIKE '$tabela'");
    if (!$check || $check->num_rows == 0) {
        $erro_tabela = "Tabela '$tabela' não encontrada. Execute o script SQL completo no phpMyAdmin.";
    }
}

// Verificar se coluna tipo_conta existe na tabela financeiro
$check_coluna = $conexao->query("SHOW COLUMNS FROM financeiro LIKE 'tipo_conta'");
if (!$check_coluna || $check_coluna->num_rows == 0) {
    $conexao->query("ALTER TABLE financeiro ADD COLUMN tipo_conta ENUM('PJ', 'PF') DEFAULT 'PJ'");
    $conexao->query("ALTER TABLE financeiro ADD COLUMN comprovante_url VARCHAR(500) DEFAULT NULL");
    $conexao->query("ALTER TABLE financeiro ADD COLUMN origem ENUM('manual', 'importacao', 'fiscal') DEFAULT 'manual'");
}

$aba_ativa = $_GET['aba'] ?? 'dashboard';
$message = '';
$message_type = '';

// ===== FUNÇÕES AUXILIARES =====
function formatarMoedaFiscal($valor) {
    if (empty($valor) || !is_numeric($valor)) $valor = 0;
    return 'R$ ' . number_format(floatval($valor), 2, ',', '.');
}

function getBadgeCategoria($categoria, $tipo) {
    $cores = [
        'vendas' => 'success', 'cobrancas' => 'info', 'cliente' => 'primary',
        'materiais' => 'danger', 'fornecedores' => 'warning', 'funcionarios' => 'secondary',
        'alimentacao' => 'warning', 'combustivel' => 'info', 'transporte' => 'primary',
        'outras_entradas' => 'secondary', 'outras_saidas' => 'secondary',
        'transferencia_pj' => 'primary', 'despesas_pessoais' => 'danger', 'contas' => 'warning'
    ];
    $cor = $cores[$categoria] ?? ($tipo == 'entrada' ? 'success' : 'danger');
    return "<span class='badge badge-{$cor}'>{$categoria}</span>";
}

// ===== BUSCAR TRANSAÇÕES DO FINANCEIRO (INTEGRAÇÃO) =====
function buscarTransacoesFinanceiro($conexao, $tipo_conta = null, $limite = 30, $mes = null) {
    $sql = "SELECT f.*, 
                   CASE WHEN f.tipo = 'entrada' THEN '+' ELSE '-' END as sinal,
                   DATE_FORMAT(f.data_transacao, '%d/%m/%Y') as data_formatada
            FROM financeiro f 
            WHERE 1=1";
    if ($tipo_conta) {
        $sql .= " AND f.tipo_conta = '$tipo_conta'";
    }
    if ($mes) {
        $sql .= " AND DATE_FORMAT(f.data_transacao, '%Y-%m') = '$mes'";
    }
    $sql .= " ORDER BY f.data_transacao DESC LIMIT $limite";
    
    $result = $conexao->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

// ===== BUSCAR RESUMO DO FINANCEIRO =====
function buscarResumoFinanceiro($conexao, $tipo_conta, $mes = null) {
    $where = "tipo_conta = '$tipo_conta'";
    if ($mes) {
        $where .= " AND DATE_FORMAT(data_transacao, '%Y-%m') = '$mes'";
    } else {
        $where .= " AND MONTH(data_transacao) = MONTH(CURDATE()) AND YEAR(data_transacao) = YEAR(CURDATE())";
    }
    
    $entradas = $conexao->query("SELECT COALESCE(SUM(valor), 0) as total FROM financeiro WHERE tipo = 'entrada' AND $where")->fetch_assoc()['total'];
    $saidas = $conexao->query("SELECT COALESCE(SUM(valor), 0) as total FROM financeiro WHERE tipo = 'saida' AND $where")->fetch_assoc()['total'];
    
    return ['entradas' => $entradas, 'saidas' => $saidas, 'saldo' => $entradas - $saidas];
}

// ===== PROCESSAR FORMULÁRIOS =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = "Erro de segurança. Recarregue a página.";
        $message_type = "danger";
    } else {
        $action = $_POST['action'];
        
        // ===== UPLOAD DE ARQUIVO (MÓDULO FISCAL) =====
        if ($action === 'upload_arquivo') {
            $tipo_conta = $_POST['tipo_conta'];
            $categoria = $_POST['categoria'];
            $valor = floatval(str_replace(',', '.', str_replace('.', '', $_POST['valor'])));
            $data_referencia = $_POST['data_referencia'];
            $descricao = addslashes($_POST['descricao'] ?? '');
            $observacao = addslashes($_POST['observacao'] ?? '');
            $ajudante_id = !empty($_POST['ajudante_id']) ? intval($_POST['ajudante_id']) : 'NULL';
            
            if (isset($_FILES['arquivo']) && $_FILES['arquivo']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = "uploads/comprovantes/" . strtolower($tipo_conta) . "/";
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                
                $ext = pathinfo($_FILES['arquivo']['name'], PATHINFO_EXTENSION);
                $nome_arquivo = date('Ymd_His') . "_" . uniqid() . "." . $ext;
                $caminho = $upload_dir . $nome_arquivo;
                
                if (move_uploaded_file($_FILES['arquivo']['tmp_name'], $caminho)) {
                    // Salvar na tabela financeiro_arquivos
                    $sql = "INSERT INTO financeiro_arquivos (tipo_conta, categoria, valor, data_referencia, caminho_arquivo, nome_original, descricao, observacao, ajudante_id) 
                            VALUES ('$tipo_conta', '$categoria', $valor, '$data_referencia', '$caminho', '{$_FILES['arquivo']['name']}', '$descricao', '$observacao', $ajudante_id)";
                    
                    if ($conexao->query($sql)) {
                        // Também salvar na tabela financeiro (integração)
                        $tipo_movimento = (strpos($categoria, 'Vendas') !== false || strpos($categoria, 'Receitas') !== false || strpos($categoria, 'Transferência') !== false) ? 'entrada' : 'saida';
                        
                        // Mapear categoria para o financeiro
                        $categoria_financeiro = '';
                        if ($tipo_conta == 'PJ') {
                            if ($tipo_movimento == 'entrada') {
                                $categoria_financeiro = 'vendas';
                            } else {
                                if (strpos($categoria, 'Peças') !== false) $categoria_financeiro = 'materiais';
                                elseif (strpos($categoria, 'Ajudantes') !== false) $categoria_financeiro = 'funcionarios';
                                elseif (strpos($categoria, 'Alimentação') !== false) $categoria_financeiro = 'alimentacao';
                                elseif (strpos($categoria, 'Combustível') !== false) $categoria_financeiro = 'combustivel';
                                else $categoria_financeiro = 'outras_saidas';
                            }
                        } else {
                            if ($tipo_movimento == 'entrada') {
                                $categoria_financeiro = 'transferencia_pj';
                            } else {
                                $categoria_financeiro = 'despesas_pessoais';
                            }
                        }
                        
                        $sql2 = "INSERT INTO financeiro (tipo, valor, descricao, categoria, data_transacao, forma_pagamento, observacao, tipo_conta, origem, comprovante_url) 
                                 VALUES ('$tipo_movimento', $valor, '$descricao', '$categoria_financeiro', '$data_referencia', 'upload', '$observacao', '$tipo_conta', 'fiscal', '$caminho')";
                        $conexao->query($sql2);
                        
                        $message = "✅ Arquivo enviado e integrado com sucesso!";
                        $message_type = "success";
                    } else {
                        $message = "Erro ao salvar: " . $conexao->error;
                        $message_type = "danger";
                    }
                }
            }
        }
        
        // ===== CADASTRAR AJUDANTE =====
        elseif ($action === 'cadastrar_ajudante') {
            $nome = addslashes($_POST['nome']);
            $documento = preg_replace('/[^0-9]/', '', $_POST['documento']);
            $tipo = $_POST['tipo'];
            $chave_pix = addslashes($_POST['chave_pix'] ?? '');
            $telefone = addslashes($_POST['telefone'] ?? '');
            $email = addslashes($_POST['email'] ?? '');
            $endereco = addslashes($_POST['endereco'] ?? '');
            
            $sql = "INSERT INTO ajudantes (nome, documento, tipo, chave_pix, telefone, email, endereco) 
                    VALUES ('$nome', '$documento', '$tipo', '$chave_pix', '$telefone', '$email', '$endereco')";
            
            if ($conexao->query($sql)) {
                $message = "✅ Ajudante cadastrado com sucesso!";
                $message_type = "success";
            } else {
                $message = "Erro: " . $conexao->error;
                $message_type = "danger";
            }
        }
        
        // ===== GERAR PAGAMENTO =====
        elseif ($action === 'gerar_pagamento') {
            $ajudante_id = intval($_POST['ajudante_id']);
            $valor = floatval(str_replace(',', '.', str_replace('.', '', $_POST['valor'])));
            $data_pagamento = $_POST['data_pagamento'];
            $data_referencia = $_POST['data_referencia_servico'];
            $descricao = addslashes($_POST['descricao_servico']);
            $nota_fiscal = addslashes($_POST['nota_fiscal_numero'] ?? '');
            
            $result = $conexao->query("SELECT tipo, nome FROM ajudantes WHERE id = $ajudante_id");
            $ajudante = $result->fetch_assoc();
            
            if ($ajudante['tipo'] === 'PJ' && empty($nota_fiscal)) {
                $message = "Para Pessoa Jurídica é obrigatório informar o número da Nota Fiscal!";
                $message_type = "danger";
            } else {
                $conexao->begin_transaction();
                try {
                    // Registrar pagamento
                    $sql = "INSERT INTO pagamentos_ajudantes (ajudante_id, valor, data_pagamento, data_referencia_servico, descricao_servico, nota_fiscal_numero, status) 
                            VALUES ($ajudante_id, $valor, '$data_pagamento', '$data_referencia', '$descricao', '$nota_fiscal', 'pendente')";
                    $conexao->query($sql);
                    $pagamento_id = $conexao->insert_id;
                    
                    // Registrar saída no financeiro
                    $observacao = "Pagamento para ajudante: " . $ajudante['nome'] . " - " . $descricao;
                    $sql2 = "INSERT INTO financeiro (tipo, valor, descricao, categoria, data_transacao, forma_pagamento, observacao, tipo_conta, origem) 
                             VALUES ('saida', $valor, '$descricao', 'funcionarios', '$data_pagamento', 'pix', '$observacao', 'PJ', 'fiscal')";
                    $conexao->query($sql2);
                    
                    $conexao->commit();
                    $message = "✅ Pagamento registrado e integrado ao financeiro!";
                    $message_type = "success";
                } catch (Exception $e) {
                    $conexao->rollback();
                    $message = "Erro: " . $e->getMessage();
                    $message_type = "danger";
                }
            }
        }
        
        // ===== LANÇAMENTO MANUAL DIRETO =====
        elseif ($action === 'lancamento_manual') {
            $tipo_conta = $_POST['tipo_conta'];
            $tipo_movimento = $_POST['tipo_movimento'];
            $categoria = $_POST['categoria'];
            $valor = floatval(str_replace(',', '.', str_replace('.', '', $_POST['valor'])));
            $data = $_POST['data'];
            $descricao = addslashes($_POST['descricao']);
            $observacao = addslashes($_POST['observacao'] ?? '');
            
            $sql = "INSERT INTO financeiro (tipo, valor, descricao, categoria, data_transacao, forma_pagamento, observacao, tipo_conta, origem) 
                    VALUES ('$tipo_movimento', $valor, '$descricao', '$categoria', '$data', 'manual', '$observacao', '$tipo_conta', 'manual')";
            
            if ($conexao->query($sql)) {
                $message = "✅ Lançamento registrado com sucesso!";
                $message_type = "success";
            } else {
                $message = "Erro: " . $conexao->error;
                $message_type = "danger";
            }
        }
    }
}

// ===== DADOS PARA O DASHBOARD =====
$mes_atual = date('Y-m');
$total_ajudantes = $conexao->query("SELECT COUNT(*) as total FROM ajudantes")->fetch_assoc()['total'] ?? 0;

// Resumo PJ e PF do financeiro
$resumo_pj = buscarResumoFinanceiro($conexao, 'PJ');
$resumo_pf = buscarResumoFinanceiro($conexao, 'PF');

// Totais gerais PJ e PF
$total_entradas_pj = $conexao->query("SELECT COALESCE(SUM(valor), 0) as total FROM financeiro WHERE tipo_conta='PJ' AND tipo='entrada'")->fetch_assoc()['total'] ?? 0;
$total_saidas_pj = $conexao->query("SELECT COALESCE(SUM(valor), 0) as total FROM financeiro WHERE tipo_conta='PJ' AND tipo='saida'")->fetch_assoc()['total'] ?? 0;
$total_entradas_pf = $conexao->query("SELECT COALESCE(SUM(valor), 0) as total FROM financeiro WHERE tipo_conta='PF' AND tipo='entrada'")->fetch_assoc()['total'] ?? 0;
$total_saidas_pf = $conexao->query("SELECT COALESCE(SUM(valor), 0) as total FROM financeiro WHERE tipo_conta='PF' AND tipo='saida'")->fetch_assoc()['total'] ?? 0;

// Totais do financeiro_arquivos (uploads manuais)
$total_uploads_pj = $conexao->query("SELECT COUNT(*) as total FROM financeiro_arquivos WHERE tipo_conta='PJ'")->fetch_assoc()['total'] ?? 0;
$total_uploads_pf = $conexao->query("SELECT COUNT(*) as total FROM financeiro_arquivos WHERE tipo_conta='PF'")->fetch_assoc()['total'] ?? 0;

// Total de transações integradas no financeiro
$total_transacoes_pj = $conexao->query("SELECT COUNT(*) as total FROM financeiro WHERE tipo_conta='PJ'")->fetch_assoc()['total'] ?? 0;
$total_transacoes_pf = $conexao->query("SELECT COUNT(*) as total FROM financeiro WHERE tipo_conta='PF'")->fetch_assoc()['total'] ?? 0;

$pagamentos_pendentes = $conexao->query("SELECT COUNT(*) as total FROM pagamentos_ajudantes WHERE status='pendente'")->fetch_assoc()['total'] ?? 0;

// Últimas transações integradas
$ultimas_transacoes_pj = buscarTransacoesFinanceiro($conexao, 'PJ', 10);
$ultimas_transacoes_pf = buscarTransacoesFinanceiro($conexao, 'PF', 10);

// Lucro MEI
$entradas_mei = $conexao->query("SELECT COALESCE(SUM(valor), 0) as total FROM financeiro WHERE tipo='entrada' AND tipo_conta='PJ' AND DATE_FORMAT(data_transacao, '%Y-%m') = '$mes_atual'")->fetch_assoc()['total'];
$saidas_mei = $conexao->query("SELECT COALESCE(SUM(valor), 0) as total FROM financeiro WHERE tipo='saida' AND tipo_conta='PJ' AND DATE_FORMAT(data_transacao, '%Y-%m') = '$mes_atual'")->fetch_assoc()['total'];
$lucro_mei = $entradas_mei - $saidas_mei;
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestão Fiscal - Império AR</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        :root { --primary: #1e3c72; --secondary: #2a5298; --success: #28a745; --danger: #dc3545; --warning: #ffc107; --info: #17a2b8; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #f5f6fa 0%, #e9ecef 100%); min-height: 100vh; }
        .admin-container { display: flex; min-height: 100vh; }
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
        .btn-lg { padding: 12px 24px; font-size: 16px; }
        .card { background: white; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); margin-bottom: 30px; overflow: hidden; }
        .card-header { padding: 20px; background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; }
        .card-header h3 { margin: 0; font-size: 18px; display: flex; align-items: center; gap: 10px; }
        .card-body { padding: 20px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #333; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 8px; font-family: inherit; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 3px rgba(30,60,114,0.1); }
        .table-responsive { overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; background: white; }
        .table thead { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; }
        .table th, .table td { padding: 15px; border-bottom: 1px solid #e0e0e0; text-align: left; }
        .badge { display: inline-block; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-info { background: #cce5ff; color: #004085; }
        .badge-primary { background: #cfe2ff; color: #004085; }
        .badge-secondary { background: #e9ecef; color: #6c757d; }
        .badge-pj { background: #1e3c72; color: white; }
        .badge-pf { background: #28a745; color: white; }
        .alert { padding: 15px 20px; border-radius: 8px; margin-bottom: 25px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        .alert-info { background: #cce5ff; color: #004085; border: 1px solid #b8daff; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); display: flex; align-items: center; gap: 15px; transition: transform 0.3s; cursor: pointer; }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
        .stat-icon { width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 28px; }
        .stat-info h3 { font-size: 28px; font-weight: bold; margin: 0; }
        .stat-info p { margin: 5px 0 0; color: #666; font-size: 13px; }
        .upload-area { border: 2px dashed #dee2e6; border-radius: 12px; padding: 30px; text-align: center; cursor: pointer; transition: all 0.3s; background: #f8f9fa; }
        .upload-area:hover { border-color: var(--primary); background: rgba(30,60,114,0.05); }
        .valor-positivo { color: var(--success); font-weight: bold; }
        .valor-negativo { color: var(--danger); font-weight: bold; }
        .nav-tabs { display: flex; gap: 5px; margin-bottom: 25px; background: white; padding: 10px 20px; border-radius: 12px; flex-wrap: wrap; }
        .nav-tab { padding: 10px 20px; border-radius: 8px; text-decoration: none; color: #666; transition: all 0.3s; }
        .nav-tab:hover { background: #e9ecef; color: var(--primary); }
        .nav-tab.active { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; }
        .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; }
        .integracao-info { background: #e8f4fd; border-left: 4px solid var(--info); padding: 15px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; }
        @media (max-width: 768px) { .sidebar { width: 100%; height: auto; position: relative; } .main-content { margin-left: 0; } }
        @media print {
            .sidebar, .page-header, .nav-tabs, .btn, .alert-secondary, .integracao-info, .header-actions, .btn-group {
                display: none !important;
            }
            .main-content {
                margin-left: 0 !important;
                padding: 0 !important;
            }
            .card {
                break-inside: avoid;
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>
        <main class="main-content">
            
            <!-- Header -->
            <div class="page-header">
                <h1><i class="fas fa-chart-line"></i> Gestão Fiscal</h1>
                <div>
                    <a href="financeiro.php" class="btn btn-info"><i class="fas fa-chart-pie"></i> Financeiro</a>
                    <a href="importar_extrato.php" class="btn btn-primary"><i class="fas fa-cloud-upload-alt"></i> Importar Extrato</a>
                </div>
            </div>
            
            <!-- Mensagem de integração -->
            <div class="integracao-info">
                <i class="fas fa-link"></i> <strong>Sistema Integrado</strong> - Os dados são compartilhados entre:
                <strong>Financeiro</strong> | <strong>Importar Extrato</strong> | <strong>Gestão Fiscal</strong>
                <span class="badge badge-info" style="margin-left: 10px;">PJ e PF integrados</span>
            </div>
            
            <!-- Alertas -->
            <?php if (isset($erro_tabela)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo $erro_tabela; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <i class="fas fa-<?php echo $message_type == 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i> 
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <!-- Tabs -->
            <div class="nav-tabs">
                <a href="?aba=dashboard" class="nav-tab <?php echo $aba_ativa == 'dashboard' ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a href="?aba=ajudantes" class="nav-tab <?php echo $aba_ativa == 'ajudantes' ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i> Ajudantes
                </a>
                <a href="?aba=pj" class="nav-tab <?php echo $aba_ativa == 'pj' ? 'active' : ''; ?>">
                    <i class="fas fa-building"></i> Conta PJ
                </a>
                <a href="?aba=pf" class="nav-tab <?php echo $aba_ativa == 'pf' ? 'active' : ''; ?>">
                    <i class="fas fa-user"></i> Conta PF
                </a>
                <a href="?aba=pagamentos" class="nav-tab <?php echo $aba_ativa == 'pagamentos' ? 'active' : ''; ?>">
                    <i class="fas fa-money-bill-wave"></i> Pagamentos
                </a>
                <a href="?aba=relatorio" class="nav-tab <?php echo $aba_ativa == 'relatorio' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-bar"></i> Relatório MEI
                </a>
            </div>
            
            <!-- ==================== DASHBOARD ==================== -->
            <?php if ($aba_ativa == 'dashboard'): ?>
            
            <div class="stats-grid">
                <div class="stat-card" onclick="window.location.href='?aba=ajudantes'">
                    <div class="stat-icon" style="background: #cce5ff; color: #004085;"><i class="fas fa-users"></i></div>
                    <div class="stat-info"><h3><?php echo $total_ajudantes; ?></h3><p>Ajudantes Cadastrados</p></div>
                </div>
                <div class="stat-card" onclick="window.location.href='?aba=pj'">
                    <div class="stat-icon" style="background: #1e3c7220; color: #1e3c72;"><i class="fas fa-building"></i></div>
                    <div class="stat-info">
                        <h3 class="valor-positivo"><?php echo formatarMoedaFiscal($total_entradas_pj); ?></h3>
                        <p>Total Entradas PJ</p>
                    </div>
                </div>
                <div class="stat-card" onclick="window.location.href='?aba=pj'">
                    <div class="stat-icon" style="background: #dc354520; color: #dc3545;"><i class="fas fa-arrow-down"></i></div>
                    <div class="stat-info">
                        <h3 class="valor-negativo"><?php echo formatarMoedaFiscal($total_saidas_pj); ?></h3>
                        <p>Total Saídas PJ</p>
                    </div>
                </div>
                <div class="stat-card" onclick="window.location.href='?aba=relatorio'">
                    <div class="stat-icon" style="background: #fff3cd; color: #856404;"><i class="fas fa-chart-line"></i></div>
                    <div class="stat-info">
                        <h3><?php echo formatarMoedaFiscal($lucro_mei); ?></h3>
                        <p>Lucro Disponível MEI</p>
                    </div>
                </div>
            </div>
            
            <div class="stats-grid">
                <div class="stat-card" onclick="window.location.href='?aba=pf'">
                    <div class="stat-icon" style="background: #28a74520; color: #28a745;"><i class="fas fa-user"></i></div>
                    <div class="stat-info">
                        <h3 class="valor-positivo"><?php echo formatarMoedaFiscal($total_entradas_pf); ?></h3>
                        <p>Total Entradas PF</p>
                    </div>
                </div>
                <div class="stat-card" onclick="window.location.href='?aba=pf'">
                    <div class="stat-icon" style="background: #dc354520; color: #dc3545;"><i class="fas fa-arrow-down"></i></div>
                    <div class="stat-info">
                        <h3 class="valor-negativo"><?php echo formatarMoedaFiscal($total_saidas_pf); ?></h3>
                        <p>Total Saídas PF</p>
                    </div>
                </div>
                <div class="stat-card" onclick="window.location.href='?aba=pagamentos'">
                    <div class="stat-icon" style="background: #fff3cd; color: #856404;"><i class="fas fa-clock"></i></div>
                    <div class="stat-info"><h3><?php echo $pagamentos_pendentes; ?></h3><p>Pagamentos Pendentes</p></div>
                </div>
                <div class="stat-card" onclick="window.location.href='financeiro.php'">
                    <div class="stat-icon" style="background: #cce5ff; color: #004085;"><i class="fas fa-database"></i></div>
                    <div class="stat-info">
                        <h3><?php echo $total_transacoes_pj + $total_transacoes_pf; ?></h3>
                        <p>Total Transações</p>
                    </div>
                </div>
            </div>
            
            <!-- Últimas transações integradas PJ -->
            <div class="row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 30px;">
                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-building"></i> Últimas Transações PJ</h3></div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table">
                                <thead><tr><th>Data</th><th>Descrição</th><th>Valor</th><th>Origem</th></tr></thead>
                                <tbody>
                                    <?php if (!empty($ultimas_transacoes_pj)): ?>
                                        <?php foreach ($ultimas_transacoes_pj as $trans): ?>
                                        <tr>
                                            <td><?php echo $trans['data_formatada']; ?></td>
                                            <td><?php echo htmlspecialchars(substr($trans['descricao'], 0, 40)); ?>...</td>
                                            <td class="<?php echo $trans['tipo'] == 'entrada' ? 'valor-positivo' : 'valor-negativo'; ?>">
                                                <?php echo $trans['tipo'] == 'entrada' ? '+' : '-'; ?> R$ <?php echo number_format($trans['valor'], 2, ',', '.'); ?>
                                            </td>
                                            <td><span class="badge badge-info"><?php echo $trans['origem'] ?? 'manual'; ?></span></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <td><td colspan="4" class="text-center">Nenhuma transação encontrada</td></td>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Últimas transações integradas PF -->
                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-user"></i> Últimas Transações PF</h3></div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table">
                                <thead><tr><th>Data</th><th>Descrição</th><th>Valor</th><th>Origem</th></tr></thead>
                                <tbody>
                                    <?php if (!empty($ultimas_transacoes_pf)): ?>
                                        <?php foreach ($ultimas_transacoes_pf as $trans): ?>
                                        <tr>
                                            <td><?php echo $trans['data_formatada']; ?></td>
                                            <td><?php echo htmlspecialchars(substr($trans['descricao'], 0, 40)); ?>...</td>
                                            <td class="<?php echo $trans['tipo'] == 'entrada' ? 'valor-positivo' : 'valor-negativo'; ?>">
                                                <?php echo $trans['tipo'] == 'entrada' ? '+' : '-'; ?> R$ <?php echo number_format($trans['valor'], 2, ',', '.'); ?>
                                            </td>
                                            <td><span class="badge badge-info"><?php echo $trans['origem'] ?? 'manual'; ?></span></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <td><td colspan="4" class="text-center">Nenhuma transação encontrada</td></td>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Status das Tabelas -->
            <div class="card">
                <div class="card-header"><h3><i class="fas fa-database"></i> Status do Sistema</h3></div>
                <div class="card-body">
                    <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
                        <div class="stat-card" style="cursor: default;">
                            <div class="stat-icon" style="background: #d4edda; color: #155724;"><i class="fas fa-check"></i></div>
                            <div class="stat-info"><h3><?php echo $total_ajudantes; ?></h3><p>Ajudantes</p></div>
                        </div>
                        <div class="stat-card" style="cursor: default;">
                            <div class="stat-icon" style="background: #d4edda; color: #155724;"><i class="fas fa-check"></i></div>
                            <div class="stat-info"><h3><?php echo $total_transacoes_pj; ?></h3><p>Transações PJ</p></div>
                        </div>
                        <div class="stat-card" style="cursor: default;">
                            <div class="stat-icon" style="background: #d4edda; color: #155724;"><i class="fas fa-check"></i></div>
                            <div class="stat-info"><h3><?php echo $total_transacoes_pf; ?></h3><p>Transações PF</p></div>
                        </div>
                        <div class="stat-card" style="cursor: default;">
                            <div class="stat-icon" style="background: #d4edda; color: #155724;"><i class="fas fa-check"></i></div>
                            <div class="stat-info"><h3><?php echo $total_uploads_pj + $total_uploads_pf; ?></h3><p>Comprovantes</p></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- ==================== AJUDANTES ==================== -->
            <?php elseif ($aba_ativa == 'ajudantes'): ?>
            
            <div class="row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 30px;">
                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-user-plus"></i> Novo Ajudante</h3></div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="cadastrar_ajudante">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            
                            <div class="form-row">
                                <div class="form-group"><label>Nome Completo *</label><input type="text" name="nome" class="form-control" required></div>
                                <div class="form-group"><label>Tipo *</label><select name="tipo" class="form-control" required><option value="PF">Pessoa Física (PF)</option><option value="PJ">Pessoa Jurídica (PJ)</option></select></div>
                            </div>
                            <div class="form-row">
                                <div class="form-group"><label>CPF / CNPJ *</label><input type="text" name="documento" class="form-control" placeholder="000.000.000-00" required></div>
                                <div class="form-group"><label>Chave PIX</label><input type="text" name="chave_pix" class="form-control" placeholder="CPF, email ou telefone"></div>
                            </div>
                            <div class="form-row">
                                <div class="form-group"><label>Telefone</label><input type="text" name="telefone" class="form-control" placeholder="(00) 00000-0000"></div>
                                <div class="form-group"><label>E-mail</label><input type="email" name="email" class="form-control"></div>
                            </div>
                            <div class="form-group"><label>Endereço</label><textarea name="endereco" class="form-control" rows="2"></textarea></div>
                            
                            <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Cadastrar</button>
                        </form>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-list"></i> Lista de Ajudantes</h3></div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table">
                                <thead><tr><th>Nome</th><th>Documento</th><th>Tipo</th><th>PIX</th><th>Ações</th></tr></thead>
                                <tbody>
                                    <?php
                                    $result = $conexao->query("SELECT * FROM ajudantes ORDER BY nome");
                                    if ($result && $result->num_rows > 0):
                                        while($row = $result->fetch_assoc()):
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($row['nome']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($row['documento']); ?></td>
                                        <td><span class="badge badge-<?php echo $row['tipo'] == 'PF' ? 'info' : 'warning'; ?>"><?php echo $row['tipo']; ?></span></td>
                                        <td><?php echo htmlspecialchars($row['chave_pix'] ?? '-'); ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-success" onclick="gerarPagamento(<?php echo $row['id']; ?>, '<?php echo addslashes($row['nome']); ?>', '<?php echo $row['tipo']; ?>')">
                                                <i class="fas fa-money-bill-wave"></i> Pagar
                                            </button>
                                        </td>
                                    </tr>
                                    <?php 
                                        endwhile;
                                    else:
                                    ?>
                                    <td><td colspan="5" class="text-center">Nenhum ajudante cadastrado</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Modal Gerar Pagamento -->
            <div id="modalPagamento" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
                <div style="background:white; border-radius:12px; max-width:500px; width:90%; margin:auto;">
                    <div style="padding:20px; background:linear-gradient(135deg, var(--primary), var(--secondary)); color:white; border-radius:12px 12px 0 0;">
                        <h3><i class="fas fa-money-bill-wave"></i> Gerar Pagamento</h3>
                    </div>
                    <form method="POST" style="padding:20px;">
                        <input type="hidden" name="action" value="gerar_pagamento">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="ajudante_id" id="modal_ajudante_id">
                        
                        <div class="form-group">
                            <label>Ajudante</label>
                            <input type="text" id="modal_ajudante_nome" class="form-control" readonly>
                        </div>
                        
                        <div class="form-group">
                            <label>Valor (R$) *</label>
                            <input type="text" name="valor" class="form-control money" placeholder="0,00" required>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Data Pagamento *</label>
                                <input type="date" name="data_pagamento" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Data Referência *</label>
                                <input type="date" name="data_referencia_servico" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Descrição do Serviço *</label>
                            <textarea name="descricao_servico" class="form-control" rows="3" required></textarea>
                        </div>
                        
                        <div class="form-group" id="div_nota_fiscal" style="display:none;">
                            <label>Número da Nota Fiscal *</label>
                            <input type="text" name="nota_fiscal_numero" class="form-control" placeholder="Ex: 12345">
                        </div>
                        
                        <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:20px;">
                            <button type="button" class="btn btn-secondary" onclick="fecharModal()">Cancelar</button>
                            <button type="submit" class="btn btn-success">Gerar Pagamento</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- ==================== CONTA PJ ==================== -->
            <?php elseif ($aba_ativa == 'pj'): ?>
            
            <div class="row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(450px, 1fr)); gap: 30px;">
                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-upload"></i> Upload Comprovante PJ</h3></div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="upload_arquivo">
                            <input type="hidden" name="tipo_conta" value="PJ">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            
                            <div class="form-group">
                                <label>Tipo de Movimento</label>
                                <select name="categoria" class="form-control" required>
                                    <option value="">Selecione...</option>
                                    <optgroup label="💰 ENTRADAS">
                                        <option value="Vendas de Serviços">Vendas de Serviços</option>
                                        <option value="Vendas de Produtos">Vendas de Produtos</option>
                                        <option value="Outras Receitas">Outras Receitas</option>
                                    </optgroup>
                                    <optgroup label="📉 SAÍDAS">
                                        <option value="Compra de Peças">Compra de Peças</option>
                                        <option value="Material de Consumo">Material de Consumo</option>
                                        <option value="Pagamento Ajudantes">Pagamento Ajudantes</option>
                                        <option value="Combustível">Combustível</option>
                                        <option value="Alimentação">Alimentação</option>
                                        <option value="Despesas Administrativas">Despesas Administrativas</option>
                                    </optgroup>
                                </select>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group"><label>Valor (R$)</label><input type="text" name="valor" class="form-control money" placeholder="0,00" required></div>
                                <div class="form-group"><label>Data</label><input type="date" name="data_referencia" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
                            </div>
                            
                            <div class="form-group"><label>Descrição</label><input type="text" name="descricao" class="form-control" placeholder="Ex: Venda para cliente X"></div>
                            <div class="form-group"><label>Observação</label><textarea name="observacao" class="form-control" rows="2"></textarea></div>
                            
                            <div class="form-group">
                                <label>Comprovante</label>
                                <div class="upload-area" onclick="$('#arquivo_pj').click()">
                                    <i class="fas fa-cloud-upload-alt fa-2x"></i>
                                    <p>Clique para selecionar o arquivo</p>
                                    <small>PDF, JPG ou PNG</small>
                                </div>
                                <input type="file" name="arquivo" id="arquivo_pj" style="display:none" accept=".pdf,.jpg,.jpeg,.png">
                            </div>
                            
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar</button>
                        </form>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-history"></i> Transações PJ</h3></div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr><th>Data</th><th>Descrição</th><th>Categoria</th><th>Valor</th><th>Origem</th></tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $transacoes_pj = buscarTransacoesFinanceiro($conexao, 'PJ', 50);
                                    if (!empty($transacoes_pj)):
                                        foreach ($transacoes_pj as $trans):
                                    ?>
                                    <tr>
                                        <td><?php echo date('d/m/Y', strtotime($trans['data_transacao'])); ?></td>
                                        <td><?php echo htmlspecialchars(substr($trans['descricao'], 0, 35)); ?>...</td>
                                        <td><span class="badge badge-<?php echo $trans['tipo'] == 'entrada' ? 'success' : 'danger'; ?>"><?php echo $trans['categoria'] ?? '-'; ?></span></td>
                                        <td class="<?php echo $trans['tipo'] == 'entrada' ? 'valor-positivo' : 'valor-negativo'; ?>">
                                            <?php echo $trans['tipo'] == 'entrada' ? '+' : '-'; ?> R$ <?php echo number_format($trans['valor'], 2, ',', '.'); ?>
                                        </td>
                                        <td><span class="badge badge-info"><?php echo $trans['origem'] ?? 'manual'; ?></span></td>
                                    </tr>
                                    <?php 
                                        endforeach;
                                    else:
                                    ?>
                                    <td><td colspan="5" class="text-center">Nenhuma transação encontrada</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer text-center">
                        <small class="text-muted">Total de transações PJ: <?php echo $total_transacoes_pj; ?> | Entradas: <?php echo formatarMoedaFiscal($total_entradas_pj); ?> | Saídas: <?php echo formatarMoedaFiscal($total_saidas_pj); ?></small>
                    </div>
                </div>
            </div>
            
            <!-- ==================== CONTA PF ==================== -->
            <?php elseif ($aba_ativa == 'pf'): ?>
            
            <div class="row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(450px, 1fr)); gap: 30px;">
                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-upload"></i> Upload Comprovante PF</h3></div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="upload_arquivo">
                            <input type="hidden" name="tipo_conta" value="PF">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            
                            <div class="form-group">
                                <select name="categoria" class="form-control" required>
                                    <option value="">Selecione...</option>
                                    <optgroup label="💰 ENTRADAS">
                                        <option value="Transferência PJ para PF">Transferência PJ → PF</option>
                                        <option value="Pró-labore">Pró-labore</option>
                                        <option value="Retirada de Lucro">Retirada de Lucro</option>
                                    </optgroup>
                                    <optgroup label="📉 SAÍDAS">
                                        <option value="Despesas Pessoais">Despesas Pessoais</option>
                                        <option value="Pagamento de Contas">Pagamento de Contas</option>
                                        <option value="Investimentos">Investimentos</option>
                                        <option value="Lazer">Lazer/Entretenimento</option>
                                    </optgroup>
                                </select>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group"><label>Valor (R$)</label><input type="text" name="valor" class="form-control money" placeholder="0,00" required></div>
                                <div class="form-group"><label>Data</label><input type="date" name="data_referencia" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
                            </div>
                            
                            <div class="form-group"><label>Descrição</label><input type="text" name="descricao" class="form-control"></div>
                            <div class="form-group"><label>Observação</label><textarea name="observacao" class="form-control" rows="2"></textarea></div>
                            
                            <div class="form-group">
                                <label>Comprovante</label>
                                <div class="upload-area" onclick="$('#arquivo_pf').click()">
                                    <i class="fas fa-cloud-upload-alt fa-2x"></i>
                                    <p>Clique para selecionar o arquivo</p>
                                    <small>PDF, JPG ou PNG</small>
                                </div>
                                <input type="file" name="arquivo" id="arquivo_pf" style="display:none" accept=".pdf,.jpg,.jpeg,.png">
                            </div>
                            
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar</button>
                        </form>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-history"></i> Transações PF</h3></div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr><th>Data</th><th>Descrição</th><th>Categoria</th><th>Valor</th><th>Origem</th></tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $transacoes_pf = buscarTransacoesFinanceiro($conexao, 'PF', 50);
                                    if (!empty($transacoes_pf)):
                                        foreach ($transacoes_pf as $trans):
                                    ?>
                                    <tr>
                                        <td><?php echo date('d/m/Y', strtotime($trans['data_transacao'])); ?></td>
                                        <td><?php echo htmlspecialchars(substr($trans['descricao'], 0, 35)); ?>...</td>
                                        <td><span class="badge badge-<?php echo $trans['tipo'] == 'entrada' ? 'success' : 'danger'; ?>"><?php echo $trans['categoria'] ?? '-'; ?></span></td>
                                        <td class="<?php echo $trans['tipo'] == 'entrada' ? 'valor-positivo' : 'valor-negativo'; ?>">
                                            <?php echo $trans['tipo'] == 'entrada' ? '+' : '-'; ?> R$ <?php echo number_format($trans['valor'], 2, ',', '.'); ?>
                                        </td>
                                        <td><span class="badge badge-info"><?php echo $trans['origem'] ?? 'manual'; ?></span></td>
                                    </tr>
                                    <?php 
                                        endforeach;
                                    else:
                                    ?>
                                    <td><td colspan="5" class="text-center">Nenhuma transação encontrada</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer text-center">
                        <small class="text-muted">Total de transações PF: <?php echo $total_transacoes_pf; ?> | Entradas: <?php echo formatarMoedaFiscal($total_entradas_pf); ?> | Saídas: <?php echo formatarMoedaFiscal($total_saidas_pf); ?></small>
                    </div>
                </div>
            </div>
            
            <!-- ==================== PAGAMENTOS ==================== -->
            <?php elseif ($aba_ativa == 'pagamentos'): ?>
            
            <div class="card">
                <div class="card-header"><h3><i class="fas fa-list"></i> Pagamentos Realizados</h3></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr><th>Data</th><th>Ajudante</th><th>Serviço</th><th>Valor</th><th>Status</th><th>NF/Recibo</th><th>Integração</th></tr>
                            </thead>
                            <tbody>
                                <?php
                                $result = $conexao->query("SELECT p.*, a.nome, a.tipo FROM pagamentos_ajudantes p JOIN ajudantes a ON p.ajudante_id = a.id ORDER BY p.data_pagamento DESC");
                                if ($result && $result->num_rows > 0):
                                    while($row = $result->fetch_assoc()):
                                ?>
                                <tr>
                                    <td><?php echo date('d/m/Y', strtotime($row['data_pagamento'])); ?></td>
                                    <td><strong><?php echo htmlspecialchars($row['nome']); ?></strong> <span class="badge badge-info"><?php echo $row['tipo']; ?></span></td>
                                    <td><?php echo htmlspecialchars(substr($row['descricao_servico'], 0, 40)); ?>...</td>
                                    <td>R$ <?php echo number_format($row['valor'], 2, ',', '.'); ?></td>
                                    <td><span class="badge badge-<?php echo $row['status'] == 'pago' ? 'success' : 'warning'; ?>"><?php echo $row['status']; ?></span></td>
                                    <td>
                                        <?php if($row['nota_fiscal_numero']): ?>
                                            <span class="badge badge-info">NF: <?php echo $row['nota_fiscal_numero']; ?></span>
                                        <?php else: ?>
                                            <span>-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge badge-success"><i class="fas fa-check"></i> Financeiro</span></td>
                                </tr>
                                <?php 
                                    endwhile;
                                else:
                                ?>
                                <tr><td colspan="7" class="text-center">Nenhum pagamento registrado</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- ==================== RELATÓRIO MEI COMPLETO ==================== -->
            <?php elseif ($aba_ativa == 'relatorio'): 

// Filtro de mês e ano
$mes_selecionado = $_GET['mes'] ?? date('m');
$ano_selecionado = $_GET['ano'] ?? date('Y');
$data_inicio = "$ano_selecionado-$mes_selecionado-01";
$data_fim = date('Y-m-t', strtotime($data_inicio));

// ===== 1. LIMITE MEI ANUAL =====
$limite_mei_anual = 81000;
$faturamento_acumulado = $conexao->query("SELECT COALESCE(SUM(valor), 0) as total 
    FROM financeiro 
    WHERE tipo = 'entrada' 
    AND tipo_conta = 'PJ' 
    AND YEAR(data_transacao) = $ano_selecionado
    AND categoria IN ('vendas', 'cobrancas', 'cliente', 'outras_entradas')")->fetch_assoc()['total'];

$saldo_mei_restante = $limite_mei_anual - $faturamento_acumulado;
$percentual_mei = ($faturamento_acumulado / $limite_mei_anual) * 100;

// ===== 2. DADOS DO MÊS SELECIONADO =====
$faturamento_mes = $conexao->query("SELECT COALESCE(SUM(valor), 0) as total 
    FROM financeiro 
    WHERE tipo = 'entrada' 
    AND tipo_conta = 'PJ' 
    AND YEAR(data_transacao) = $ano_selecionado 
    AND MONTH(data_transacao) = $mes_selecionado
    AND categoria IN ('vendas', 'cobrancas', 'cliente', 'outras_entradas')")->fetch_assoc()['total'];

$custos_materiais = $conexao->query("SELECT COALESCE(SUM(valor), 0) as total 
    FROM financeiro 
    WHERE tipo = 'saida' 
    AND tipo_conta = 'PJ' 
    AND YEAR(data_transacao) = $ano_selecionado 
    AND MONTH(data_transacao) = $mes_selecionado
    AND categoria IN ('materiais', 'fornecedores')")->fetch_assoc()['total'];

$pagamentos_ajudantes = $conexao->query("SELECT COALESCE(SUM(valor), 0) as total 
    FROM pagamentos_ajudantes 
    WHERE status = 'pago' 
    AND YEAR(data_pagamento) = $ano_selecionado 
    AND MONTH(data_pagamento) = $mes_selecionado")->fetch_assoc()['total'];

$despesas_operacionais = $conexao->query("SELECT COALESCE(SUM(valor), 0) as total 
    FROM financeiro 
    WHERE tipo = 'saida' 
    AND tipo_conta = 'PJ' 
    AND YEAR(data_transacao) = $ano_selecionado 
    AND MONTH(data_transacao) = $mes_selecionado
    AND categoria IN ('alimentacao', 'combustivel', 'transporte', 'impostos', 'outras_saidas')")->fetch_assoc()['total'];

$total_saidas_pj_mes = $custos_materiais + $pagamentos_ajudantes + $despesas_operacionais;
$lucro_mes = $faturamento_mes - $total_saidas_pj_mes;

// ===== 3. DADOS DA CONTA PF =====
$entradas_pf_mes = $conexao->query("SELECT COALESCE(SUM(valor), 0) as total 
    FROM financeiro 
    WHERE tipo = 'entrada' 
    AND tipo_conta = 'PF' 
    AND YEAR(data_transacao) = $ano_selecionado 
    AND MONTH(data_transacao) = $mes_selecionado")->fetch_assoc()['total'];

$saidas_pf_mes = $conexao->query("SELECT COALESCE(SUM(valor), 0) as total 
    FROM financeiro 
    WHERE tipo = 'saida' 
    AND tipo_conta = 'PF' 
    AND YEAR(data_transacao) = $ano_selecionado 
    AND MONTH(data_transacao) = $mes_selecionado")->fetch_assoc()['total'];

$saldo_pf_mes = $entradas_pf_mes - $saidas_pf_mes;

// ===== 4. CÁLCULOS RECOMENDADOS =====
$prolabore_recomendado = max(1412, $faturamento_mes * 0.3);
$prolabore_minimo = 1412;
$despesas_totais = $total_saidas_pj_mes + $saidas_pf_mes;
$meta_faturamento = $despesas_totais + $prolabore_recomendado;
$dias_no_mes = date('t', strtotime($data_inicio));
$gasto_medio_diario = $total_saidas_pj_mes / $dias_no_mes;

// ===== 5. ALERTAS =====
$alertas = [];

if ($faturamento_acumulado > $limite_mei_anual * 0.9) {
    $alertas[] = ['tipo' => 'danger', 'msg' => "⚠️ ATENÇÃO: Você já utilizou " . number_format($percentual_mei, 1) . "% do limite MEI anual! Restam apenas R$ " . number_format($saldo_mei_restante, 2, ',', '.')];
} elseif ($faturamento_acumulado > $limite_mei_anual * 0.7) {
    $alertas[] = ['tipo' => 'warning', 'msg' => "📊 Alerta: Você utilizou " . number_format($percentual_mei, 1) . "% do limite MEI anual."];
}

if ($lucro_mes < 0) {
    $alertas[] = ['tipo' => 'danger', 'msg' => "⚠️ Empresa teve prejuízo de R$ " . number_format(abs($lucro_mes), 2, ',', '.') . " neste mês! Reveja seus custos."];
}

if ($saldo_pf_mes < 0) {
    $alertas[] = ['tipo' => 'warning', 'msg' => "📉 Suas despesas pessoais superaram as entradas em R$ " . number_format(abs($saldo_pf_mes), 2, ',', '.')];
}

if ($faturamento_mes < $meta_faturamento && $faturamento_mes > 0) {
    $faltante = $meta_faturamento - $faturamento_mes;
    $alertas[] = ['tipo' => 'info', 'msg' => "🎯 Para cobrir todas as despesas e o pró-labore, você precisa faturar mais R$ " . number_format($faltante, 2, ',', '.') . " neste mês."];
}
?>

<div class="row">
    <div class="col-12">
        <!-- Seletor de Mês/Ano -->
        <div class="card mb-4">
            <div class="card-header">
                <h3><i class="fas fa-calendar-alt"></i> Seletor de Período</h3>
            </div>
            <div class="card-body">
                <form method="GET" class="form-row">
                    <input type="hidden" name="aba" value="relatorio">
                    <div class="form-group" style="flex: 1;">
                        <label>Mês</label>
                        <select name="mes" class="form-control">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo str_pad($m, 2, '0', STR_PAD_LEFT); ?>" <?php echo $mes_selecionado == str_pad($m, 2, '0', STR_PAD_LEFT) ? 'selected' : ''; ?>>
                                    <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label>Ano</label>
                        <select name="ano" class="form-control">
                            <?php for ($a = date('Y'); $a >= 2024; $a--): ?>
                                <option value="<?php echo $a; ?>" <?php echo $ano_selecionado == $a ? 'selected' : ''; ?>><?php echo $a; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex: 0 0 auto; align-self: flex-end;">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filtrar</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Alertas -->
        <?php foreach ($alertas as $alerta): ?>
        <div class="alert alert-<?php echo $alerta['tipo']; ?>">
            <i class="fas fa-<?php echo $alerta['tipo'] == 'danger' ? 'exclamation-triangle' : ($alerta['tipo'] == 'warning' ? 'bell' : 'info-circle'); ?>"></i>
            <?php echo $alerta['msg']; ?>
        </div>
        <?php endforeach; ?>

        <!-- Cards Principais -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: #1e3c7220; color: #1e3c72;"><i class="fas fa-chart-line"></i></div>
                <div class="stat-info">
                    <h3>R$ <?php echo number_format($faturamento_mes, 2, ',', '.'); ?></h3>
                    <p>Faturamento PJ (<?php echo date('F/Y', strtotime($data_inicio)); ?>)</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #dc354520; color: #dc3545;"><i class="fas fa-arrow-down"></i></div>
                <div class="stat-info">
                    <h3>R$ <?php echo number_format($total_saidas_pj_mes, 2, ',', '.'); ?></h3>
                    <p>Total Saídas PJ</p>
                    <small>Peças: R$ <?php echo number_format($custos_materiais, 2, ',', '.'); ?> | Ajudantes: R$ <?php echo number_format($pagamentos_ajudantes, 2, ',', '.'); ?></small>
                </div>
            </div>
            <div class="stat-card" style="border-left: 4px solid <?php echo $lucro_mes >= 0 ? '#28a745' : '#dc3545'; ?>">
                <div class="stat-icon" style="background: <?php echo $lucro_mes >= 0 ? '#28a74520' : '#dc354520'; ?>; color: <?php echo $lucro_mes >= 0 ? '#28a745' : '#dc3545'; ?>;">
                    <i class="fas fa-wallet"></i>
                </div>
                <div class="stat-info">
                    <h3 class="<?php echo $lucro_mes >= 0 ? 'valor-positivo' : 'valor-negativo'; ?>">
                        R$ <?php echo number_format($lucro_mes, 2, ',', '.'); ?>
                    </h3>
                    <p>Lucro Disponível para PF</p>
                </div>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: #28a74520; color: #28a745;"><i class="fas fa-user"></i></div>
                <div class="stat-info">
                    <h3>R$ <?php echo number_format($entradas_pf_mes, 2, ',', '.'); ?></h3>
                    <p>Entradas PF (Transferências PJ → PF)</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #ffc10720; color: #ffc107;"><i class="fas fa-shopping-cart"></i></div>
                <div class="stat-info">
                    <h3>R$ <?php echo number_format($saidas_pf_mes, 2, ',', '.'); ?></h3>
                    <p>Despesas Pessoais PF</p>
                </div>
            </div>
            <div class="stat-card" style="border-left: 4px solid <?php echo $saldo_pf_mes >= 0 ? '#28a745' : '#dc3545'; ?>">
                <div class="stat-icon" style="background: <?php echo $saldo_pf_mes >= 0 ? '#28a74520' : '#dc354520'; ?>;">
                    <i class="fas fa-piggy-bank"></i>
                </div>
                <div class="stat-info">
                    <h3 class="<?php echo $saldo_pf_mes >= 0 ? 'valor-positivo' : 'valor-negativo'; ?>">
                        R$ <?php echo number_format($saldo_pf_mes, 2, ',', '.'); ?>
                    </h3>
                    <p>Saldo PF no Mês</p>
                </div>
            </div>
        </div>

        <!-- Tabela de Composição do Lucro -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-calculator"></i> Composição do Resultado - <?php echo date('F/Y', strtotime($data_inicio)); ?></h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="table-light">
                            <tr><th colspan="2" class="text-center">DEMONSTRATIVO DE RESULTADOS</th></tr>
                        </thead>
                        <tbody>
                            <tr class="table-success">
                                <td width="60%"><strong>(+) FATURAMENTO PJ</strong></td>
                                <td class="text-end fw-bold">R$ <?php echo number_format($faturamento_mes, 2, ',', '.'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>(-) CUSTOS OPERACIONAIS</strong></td>
                                <td class="text-end text-danger">- R$ <?php echo number_format($custos_materiais, 2, ',', '.'); ?></td>
                            </tr>
                            <tr>
                                <td style="padding-left: 30px;">Compra de Materiais/Peças</td>
                                <td class="text-end text-danger">- R$ <?php echo number_format($custos_materiais, 2, ',', '.'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>(-) DESPESAS OPERACIONAIS</strong></td>
                                <td class="text-end text-danger">- R$ <?php echo number_format($despesas_operacionais, 2, ',', '.'); ?></td>
                            </tr>
                            <tr>
                                <td style="padding-left: 30px;">Alimentação, Combustível, Transporte, Impostos</td>
                                <td class="text-end text-danger">- R$ <?php echo number_format($despesas_operacionais, 2, ',', '.'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>(-) PAGAMENTO DE AJUDANTES</strong></td>
                                <td class="text-end text-danger">- R$ <?php echo number_format($pagamentos_ajudantes, 2, ',', '.'); ?></td>
                            </tr>
                            <tr class="table-info">
                                <td><strong>= LUCRO OPERACIONAL (Disponível para PF)</strong></td>
                                <td class="text-end fw-bold <?php echo $lucro_mes >= 0 ? 'valor-positivo' : 'valor-negativo'; ?>">
                                    R$ <?php echo number_format($lucro_mes, 2, ',', '.'); ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Análise de Pró-labore e Metas -->
        <div class="stats-grid">
            <div class="card">
                <div class="card-header" style="background: linear-gradient(135deg, #6f42c1, #8b5cf6);">
                    <h3><i class="fas fa-dollar-sign"></i> Pró-labore Recomendado</h3>
                </div>
                <div class="card-body">
                    <div class="text-center mb-3">
                        <h2 class="text-primary">R$ <?php echo number_format($prolabore_recomendado, 2, ',', '.'); ?></h2>
                        <p>Valor recomendado para retirada de pró-labore</p>
                        <small class="text-muted">Baseado em 30% do faturamento ou salário mínimo (R$ 1.412,00)</small>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-6 text-center">
                            <small>Pró-labore mínimo</small>
                            <h4>R$ <?php echo number_format($prolabore_minimo, 2, ',', '.'); ?></h4>
                        </div>
                        <div class="col-6 text-center">
                            <small>Máximo que pode retirar</small>
                            <h4 class="text-success">R$ <?php echo number_format(max(0, $lucro_mes), 2, ',', '.'); ?></h4>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header" style="background: linear-gradient(135deg, #fd7e14, #ffc107);">
                    <h3><i class="fas fa-bullseye"></i> Metas Financeiras</h3>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label>Despesas Totais (PJ + PF): <strong>R$ <?php echo number_format($despesas_totais, 2, ',', '.'); ?></strong></label>
                        <div class="progress mb-2" style="height: 20px;">
                            <div class="progress-bar bg-danger" style="width: <?php echo min(100, ($despesas_totais / max(1, $meta_faturamento)) * 100); ?>%">
                                <?php echo round(($despesas_totais / max(1, $meta_faturamento)) * 100); ?>%
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label>Meta de Faturamento: <strong>R$ <?php echo number_format($meta_faturamento, 2, ',', '.'); ?></strong></label>
                        <div class="progress" style="height: 20px;">
                            <div class="progress-bar bg-success" style="width: <?php echo min(100, ($faturamento_mes / max(1, $meta_faturamento)) * 100); ?>%">
                                <?php echo round(($faturamento_mes / max(1, $meta_faturamento)) * 100); ?>%
                            </div>
                        </div>
                    </div>
                    <hr>
                    <div class="text-center">
                        <small>💡 <strong>Meta:</strong> Faturar <strong>R$ <?php echo number_format($meta_faturamento, 2, ',', '.'); ?></strong> para cobrir todas as despesas e o pró-labore.</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Controle do Limite MEI Anual -->
        <div class="card">
            <div class="card-header" style="background: linear-gradient(135deg, #1e3c72, #2a5298);">
                <h3><i class="fas fa-chart-bar"></i> Controle do Limite MEI - Ano <?php echo $ano_selecionado; ?></h3>
            </div>
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <div class="progress mb-2" style="height: 30px;">
                            <div class="progress-bar <?php echo $percentual_mei > 90 ? 'bg-danger' : ($percentual_mei > 70 ? 'bg-warning' : 'bg-success'); ?>" 
                                 style="width: <?php echo min(100, $percentual_mei); ?>%">
                                <?php echo number_format($percentual_mei, 1); ?>%
                            </div>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span>Faturamento atual: <strong>R$ <?php echo number_format($faturamento_acumulado, 2, ',', '.'); ?></strong></span>
                            <span>Limite MEI: <strong>R$ <?php echo number_format($limite_mei_anual, 2, ',', '.'); ?></strong></span>
                            <span>Disponível: <strong class="text-success">R$ <?php echo number_format(max(0, $saldo_mei_restante), 2, ',', '.'); ?></strong></span>
                        </div>
                    </div>
                    <div class="col-md-4 text-center">
                        <?php if ($percentual_mei > 90): ?>
                            <div class="alert alert-danger mb-0">
                                <i class="fas fa-exclamation-triangle"></i> ATENÇÃO!<br>
                                Você está próximo do limite MEI!
                            </div>
                        <?php elseif ($percentual_mei > 70): ?>
                            <div class="alert alert-warning mb-0">
                                <i class="fas fa-bell"></i> Acompanhe o faturamento
                            </div>
                        <?php else: ?>
                            <div class="alert alert-success mb-0">
                                <i class="fas fa-check-circle"></i> Dentro do limite MEI
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Gasto Médio Diário -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-chart-line"></i> Análise de Gastos</h3>
            </div>
            <div class="card-body">
                <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
                    <div class="text-center">
                        <h4>Gasto Médio Diário PJ</h4>
                        <h2 class="text-danger">R$ <?php echo number_format($gasto_medio_diario, 2, ',', '.'); ?></h2>
                        <small>Média de gastos por dia</small>
                    </div>
                    <div class="text-center">
                        <h4>Gasto Médio Diário PF</h4>
                        <h2 class="text-warning">R$ <?php echo number_format($saidas_pf_mes / $dias_no_mes, 2, ',', '.'); ?></h2>
                        <small>Despesas pessoais por dia</small>
                    </div>
                    <div class="text-center">
                        <h4>Ticket Médio PJ</h4>
                        <h2 class="text-primary">R$ <?php 
                            $num_vendas = $conexao->query("SELECT COUNT(*) as total FROM financeiro WHERE tipo='entrada' AND tipo_conta='PJ' AND YEAR(data_transacao) = $ano_selecionado AND MONTH(data_transacao) = $mes_selecionado")->fetch_assoc()['total'];
                            $ticket_medio = $num_vendas > 0 ? $faturamento_mes / $num_vendas : 0;
                            echo number_format($ticket_medio, 2, ',', '.');
                        ?></h2>
                        <small>Valor médio por venda</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Botões de Ação -->
        <div class="d-flex justify-content-end gap-2 mt-3">
            <button class="btn btn-success" onclick="window.location.href='?aba=pf'">
                <i class="fas fa-exchange-alt me-1"></i> Registrar Transferência PJ → PF
            </button>
            <button class="btn btn-info" onclick="window.location.href='financeiro.php?acao=listar&tipo_conta=PJ'">
                <i class="fas fa-chart-line me-1"></i> Ver Detalhes no Financeiro
            </button>
            <button class="btn btn-primary" onclick="window.print()">
                <i class="fas fa-print me-1"></i> Imprimir Relatório
            </button>
        </div>

        <div class="alert alert-secondary mt-4">
            <i class="fas fa-info-circle me-2"></i>
            <strong>📋 Resumo do mês:</strong>
            <ul class="mt-2 mb-0">
                <li>💰 <strong>Faturamento PJ:</strong> R$ <?php echo number_format($faturamento_mes, 2, ',', '.'); ?></li>
                <li>📉 <strong>Custos e Despesas PJ:</strong> R$ <?php echo number_format($total_saidas_pj_mes, 2, ',', '.'); ?> (<?php echo round(($total_saidas_pj_mes / max(1, $faturamento_mes)) * 100, 1); ?>% do faturamento)</li>
                <li>📈 <strong>Lucro Disponível para PF:</strong> R$ <?php echo number_format($lucro_mes, 2, ',', '.'); ?></li>
                <li>🏦 <strong>Transferido para PF:</strong> R$ <?php echo number_format($entradas_pf_mes, 2, ',', '.'); ?></li>
                <li>💳 <strong>Despesas Pessoais PF:</strong> R$ <?php echo number_format($saidas_pf_mes, 2, ',', '.'); ?></li>
                <li>📊 <strong>Saldo final PF:</strong> R$ <?php echo number_format($saldo_pf_mes, 2, ',', '.'); ?></li>
            </ul>
        </div>
    </div>
</div>
<?php endif; ?>
        </main>
    </div>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js"></script>
    <script>
        $('.money').mask('000.000.000.000.000,00', {reverse: true});
        
        $('#arquivo_pj, #arquivo_pf').on('change', function() {
            const fileName = $(this).val().split('\\').pop();
            $(this).closest('.card-body').find('.upload-area p').html('<i class="fas fa-file-pdf"></i> ' + fileName);
        });
        
        let tipoAjudanteAtual = '';
        
        function gerarPagamento(id, nome, tipo) {
            document.getElementById('modal_ajudante_id').value = id;
            document.getElementById('modal_ajudante_nome').value = nome;
            tipoAjudanteAtual = tipo;
            
            if (tipo === 'PJ') {
                document.getElementById('div_nota_fiscal').style.display = 'block';
                document.querySelector('#div_nota_fiscal input').required = true;
            } else {
                document.getElementById('div_nota_fiscal').style.display = 'none';
                document.querySelector('#div_nota_fiscal input').required = false;
            }
            
            document.getElementById('modalPagamento').style.display = 'flex';
        }
        
        function fecharModal() {
            document.getElementById('modalPagamento').style.display = 'none';
        }
        
        document.getElementById('modalPagamento').addEventListener('click', function(e) {
            if (e.target === this) fecharModal();
        });
    </script>
</body>
</html>