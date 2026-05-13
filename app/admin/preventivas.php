<?php
/**
 * Preventivas - Império AR
 * Gerenciamento de contratos de manutenção preventiva
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

// ===== FUNÇÕES AUXILIARES =====
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

// Gerar número da preventiva
function gerarNumeroPreventiva($conexao) {
    $ano = date('Y');
    $mes = date('m');
    
    $sql = "SELECT COUNT(*) as total FROM preventivas WHERE YEAR(created_at) = $ano AND MONTH(created_at) = $mes";
    $result = $conexao->query($sql);
    $total = $result->fetch_assoc()['total'];
    $sequencial = str_pad($total + 1, 4, '0', STR_PAD_LEFT);
    
    return "PMP-$ano$mes-$sequencial";
}

// Calcular próxima data baseado na frequência
function calcularProximaData($ultima_data, $frequencia) {
    if (empty($ultima_data)) {
        return date('Y-m-d');
    }
    
    $data = new DateTime($ultima_data);
    
    switch ($frequencia) {
        case 'semanal':
            $data->modify('+7 days');
            break;
        case 'quinzenal':
            $data->modify('+15 days');
            break;
        case 'mensal':
            $data->modify('+1 month');
            break;
        case 'trimestral':
            $data->modify('+3 months');
            break;
        case 'semestral':
            $data->modify('+6 months');
            break;
        case 'anual':
            $data->modify('+1 year');
            break;
        default:
            $data->modify('+1 month');
    }
    
    return $data->format('Y-m-d');
}

// Frequências disponíveis
$frequencias = [
    'semanal' => 'Semanal (7 dias)',
    'quinzenal' => 'Quinzenal (15 dias)',
    'mensal' => 'Mensal (30 dias)',
    'trimestral' => 'Trimestral (3 meses)',
    'semestral' => 'Semestral (6 meses)',
    'anual' => 'Anual (12 meses)'
];

// Status disponíveis
$status_preventiva = [
    'ativo' => 'Ativo',
    'inativo' => 'Inativo',
    'pausado' => 'Pausado'
];

// ===== PROCESSAR AÇÕES =====
$acao = $_GET['acao'] ?? 'listar';
$id = isset($_GET['id']) ? intval($_GET['id']) : null;
$mensagem = '';
$erro = '';
$tipo_mensagem = '';

// Buscar clientes
$clientes = $conexao->query("SELECT id, nome, cpf_cnpj, telefone, whatsapp FROM clientes WHERE ativo = 1 ORDER BY nome")->fetch_all(MYSQLI_ASSOC);

// ===== PROCESSAR POST =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao_post = $_POST['acao'] ?? '';
    
    if ($acao_post === 'salvar_preventiva') {
        $cliente_id = intval($_POST['cliente_id']);
        $tipo = $_POST['tipo'] ?? 'preventiva';
        $frequencia = $_POST['frequencia'] ?? 'mensal';
        $notificar_whatsapp = isset($_POST['notificar_whatsapp']) ? 1 : 0;
        $notificacao_1_dia = isset($_POST['notificacao_1_dia']) ? 1 : 0;
        $notificacao_1_hora = isset($_POST['notificacao_1_hora']) ? 1 : 0;
        $status = $_POST['status'] ?? 'ativo';
        $proxima_data = $_POST['proxima_data'] ?? date('Y-m-d');
        $ultima_manutencao = !empty($_POST['ultima_manutencao']) ? $_POST['ultima_manutencao'] : null;
        
        $id_editar = isset($_POST['id']) ? intval($_POST['id']) : 0;
        
        if ($cliente_id <= 0) {
            $erro = "Selecione um cliente";
        } else {
            $numero = $id_editar ? null : gerarNumeroPreventiva($conexao);
            
            if ($id_editar) {
                // Atualizar preventiva existente
                $sql = "UPDATE preventivas SET 
                            cliente_id = ?,
                            tipo = ?,
                            frequencia = ?,
                            notificar_whatsapp = ?,
                            notificacao_1_dia = ?,
                            notificacao_1_hora = ?,
                            status = ?,
                            proxima_data = ?,
                            ultima_manutencao = ?,
                            updated_at = NOW()
                        WHERE id = ?";
                $stmt = $conexao->prepare($sql);
                $stmt->bind_param("issiiiss si", 
                    $cliente_id, $tipo, $frequencia, $notificar_whatsapp,
                    $notificacao_1_dia, $notificacao_1_hora, $status,
                    $proxima_data, $ultima_manutencao, $id_editar);
                
                if ($stmt->execute()) {
                    $mensagem = "Contrato de preventiva atualizado com sucesso!";
                    $tipo_mensagem = "success";
                } else {
                    $erro = "Erro ao atualizar: " . $stmt->error;
                }
                $stmt->close();
            } else {
                // Inserir nova preventiva
                $sql = "INSERT INTO preventivas (
                            numero, cliente_id, tipo, frequencia, notificar_whatsapp,
                            notificacao_1_dia, notificacao_1_hora, status, proxima_data,
                            ultima_manutencao, created_at, updated_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                $stmt = $conexao->prepare($sql);
                $stmt->bind_param("sissiiisss", 
                    $numero, $cliente_id, $tipo, $frequencia, $notificar_whatsapp,
                    $notificacao_1_dia, $notificacao_1_hora, $status, $proxima_data, $ultima_manutencao);
                
                if ($stmt->execute()) {
                    $novo_id = $conexao->insert_id;
                    $mensagem = "Contrato de preventiva criado com sucesso! Número: $numero";
                    $tipo_mensagem = "success";
                    
                    // Redirecionar para editar para adicionar equipamentos
                    header('Location: ' . BASE_URL . '/app/admin/preventivas.php?acao=editar&id=' . $novo_id . '&mensagem=' . urlencode($mensagem));
                    exit;
                } else {
                    $erro = "Erro ao criar: " . $stmt->error;
                }
                $stmt->close();
            }
        }
        
        if (!empty($erro)) {
            $acao = $id_editar ? 'editar' : 'novo';
        }
    } elseif ($acao_post === 'salvar_equipamento') {
        $preventiva_id = intval($_POST['preventiva_id']);
        $marca = trim($_POST['marca'] ?? '');
        $modelo = trim($_POST['modelo'] ?? '');
        $potencia = trim($_POST['potencia'] ?? '');
        $gas_refrigerante = trim($_POST['gas_refrigerante'] ?? '');
        $carga_gas = floatval(str_replace(',', '.', $_POST['carga_gas'] ?? 0));
        $ambiente = $_POST['ambiente'] ?? 'limpo';
        
        $id_equipamento = isset($_POST['equipamento_id']) ? intval($_POST['equipamento_id']) : 0;
        
        if ($id_equipamento) {
            // Atualizar equipamento
            $sql = "UPDATE pmp_equipamentos SET 
                        marca = ?, modelo = ?, potencia = ?, gas_refrigerante = ?,
                        carga_gas = ?, ambiente = ?
                    WHERE id = ? AND preventiva_id = ?";
            $stmt = $conexao->prepare($sql);
            $stmt->bind_param("ssssdsii", $marca, $modelo, $potencia, $gas_refrigerante, $carga_gas, $ambiente, $id_equipamento, $preventiva_id);
            
            if ($stmt->execute()) {
                $mensagem = "Equipamento atualizado com sucesso!";
                $tipo_mensagem = "success";
            } else {
                $erro = "Erro ao atualizar equipamento: " . $stmt->error;
            }
            $stmt->close();
        } else {
            // Inserir novo equipamento
            $sql = "INSERT INTO pmp_equipamentos (preventiva_id, marca, modelo, potencia, gas_refrigerante, carga_gas, ambiente) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conexao->prepare($sql);
            $stmt->bind_param("issssds", $preventiva_id, $marca, $modelo, $potencia, $gas_refrigerante, $carga_gas, $ambiente);
            
            if ($stmt->execute()) {
                $mensagem = "Equipamento adicionado com sucesso!";
                $tipo_mensagem = "success";
            } else {
                $erro = "Erro ao adicionar equipamento: " . $stmt->error;
            }
            $stmt->close();
        }
        
        $acao = 'editar';
        $id = $preventiva_id;
    } elseif ($acao_post === 'deletar_equipamento' && isset($_POST['equipamento_id'])) {
        $equipamento_id = intval($_POST['equipamento_id']);
        $preventiva_id = intval($_POST['preventiva_id']);
        
        $sql = "DELETE FROM pmp_equipamentos WHERE id = ? AND preventiva_id = ?";
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param("ii", $equipamento_id, $preventiva_id);
        
        if ($stmt->execute()) {
            $mensagem = "Equipamento removido com sucesso!";
            $tipo_mensagem = "success";
        } else {
            $erro = "Erro ao remover equipamento: " . $stmt->error;
        }
        $stmt->close();
        
        $acao = 'editar';
        $id = $preventiva_id;
    } elseif ($acao_post === 'salvar_checklist') {
        $equipamento_id = intval($_POST['equipamento_id']);
        $preventiva_id = intval($_POST['preventiva_id']);
        $data_manutencao = $_POST['data_manutencao'] ?? date('Y-m-d');
        $itens = $_POST['itens'] ?? [];
        $observacoes = $_POST['observacoes'] ?? '';
        
        // Salvar cada item do checklist
        foreach ($itens as $item => $resultado) {
            $sql = "INSERT INTO pmp_checklist (pmp_equipamento_id, data_manutencao, item, resultado, observacao) 
                    VALUES (?, ?, ?, ?, ?)";
            $stmt = $conexao->prepare($sql);
            $observacao_item = $observacoes[$item] ?? '';
            $stmt->bind_param("issss", $equipamento_id, $data_manutencao, $item, $resultado, $observacao_item);
            $stmt->execute();
            $stmt->close();
        }
        
        // Atualizar última manutenção da preventiva
        $sql = "UPDATE preventivas SET ultima_manutencao = ?, proxima_data = ? WHERE id = ?";
        $stmt = $conexao->prepare($sql);
        $proxima_data = calcularProximaData($data_manutencao, 'mensal'); // Pega a frequência do contrato
        $stmt->bind_param("ssi", $data_manutencao, $proxima_data, $preventiva_id);
        $stmt->execute();
        $stmt->close();
        
        $mensagem = "Checklist registrado com sucesso!";
        $tipo_mensagem = "success";
        $acao = 'editar';
        $id = $preventiva_id;
    }
}

// ===== PROCESSAR DELETE VIA GET =====
if ($acao === 'deletar' && $id) {
    // Verificar se tem equipamentos vinculados
    $check = $conexao->query("SELECT COUNT(*) as total FROM pmp_equipamentos WHERE preventiva_id = $id")->fetch_assoc();
    if ($check['total'] > 0) {
        // Deletar equipamentos primeiro
        $conexao->query("DELETE FROM pmp_equipamentos WHERE preventiva_id = $id");
    }
    
    $sql = "DELETE FROM preventivas WHERE id = ?";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        header('Location: ' . BASE_URL . '/app/admin/preventivas.php?mensagem=Contrato removido com sucesso');
        exit;
    }
    $stmt->close();
}

// ===== PROCESSAR ALTERAÇÃO DE STATUS =====
if ($acao === 'alterar_status' && $id) {
    $novo_status = $_GET['status'] ?? '';
    if (in_array($novo_status, ['ativo', 'inativo', 'pausado'])) {
        $sql = "UPDATE preventivas SET status = ? WHERE id = ?";
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param("si", $novo_status, $id);
        if ($stmt->execute()) {
            header('Location: ' . BASE_URL . '/app/admin/preventivas.php?mensagem=Status alterado com sucesso');
            exit;
        }
        $stmt->close();
    }
}

// ===== BUSCAR DADOS =====
if ($acao === 'listar') {
    $preventivas = $conexao->query("
        SELECT p.*, c.nome as cliente_nome, c.cpf_cnpj, c.telefone, c.whatsapp,
               (SELECT COUNT(*) FROM pmp_equipamentos WHERE preventiva_id = p.id) as total_equipamentos
        FROM preventivas p
        LEFT JOIN clientes c ON p.cliente_id = c.id
        ORDER BY p.proxima_data ASC
        LIMIT 200
    ")->fetch_all(MYSQLI_ASSOC);
    
    if (isset($_GET['mensagem'])) {
        $mensagem = $_GET['mensagem'];
        $tipo_mensagem = "success";
    }
} elseif ($acao === 'novo') {
    $preventiva = [
        'id' => '',
        'numero' => gerarNumeroPreventiva($conexao),
        'cliente_id' => '',
        'tipo' => 'preventiva',
        'frequencia' => 'mensal',
        'notificar_whatsapp' => 1,
        'notificacao_1_dia' => 1,
        'notificacao_1_hora' => 1,
        'status' => 'ativo',
        'proxima_data' => date('Y-m-d'),
        'ultima_manutencao' => null
    ];
    $equipamentos = [];
} elseif ($acao === 'editar' && $id) {
    $preventiva = $conexao->query("SELECT * FROM preventivas WHERE id = $id")->fetch_assoc();
    if (!$preventiva) {
        header('Location: ' . BASE_URL . '/app/admin/preventivas.php?mensagem=Contrato não encontrado');
        exit;
    }
    
    // Buscar equipamentos vinculados
    $equipamentos = $conexao->query("
        SELECT * FROM pmp_equipamentos WHERE preventiva_id = $id ORDER BY id
    ")->fetch_all(MYSQLI_ASSOC);
    
    // Buscar checklists
    $checklists = [];
    foreach ($equipamentos as $eq) {
        $checklists[$eq['id']] = $conexao->query("
            SELECT * FROM pmp_checklist WHERE pmp_equipamento_id = {$eq['id']} ORDER BY data_manutencao DESC LIMIT 10
        ")->fetch_all(MYSQLI_ASSOC);
    }
} elseif ($acao === 'checklist' && $id) {
    $equipamento_id = $id;
    $equipamento = $conexao->query("SELECT e.*, p.cliente_id, p.numero as preventiva_numero, c.nome as cliente_nome 
                                    FROM pmp_equipamentos e
                                    JOIN preventivas p ON e.preventiva_id = p.id
                                    JOIN clientes c ON p.cliente_id = c.id
                                    WHERE e.id = $equipamento_id")->fetch_assoc();
    if (!$equipamento) {
        header('Location: ' . BASE_URL . '/app/admin/preventivas.php?mensagem=Equipamento não encontrado');
        exit;
    }
    
    // Itens do checklist padrão
    $checklist_itens = [
        'Limpeza dos filtros',
        'Limpeza da serpentina evaporadora',
        'Limpeza da serpentina condensadora',
        'Verificação do gás refrigerante',
        'Verificação da pressão de trabalho',
        'Verificação da drenagem',
        'Verificação da instalação elétrica',
        'Verificação do funcionamento do compressor',
        'Verificação da ventoinha',
        'Verificação do termostato',
        'Verificação do controle remoto',
        'Verificação de ruídos anormais'
    ];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preventivas - Império AR</title>
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

        .btn-novo {
            background: var(--success);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .btn-novo:hover {
            background: #1e7e34;
            color: white;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
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
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
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
        .badge-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        .badge-ativo { background: #d1e7dd; color: #0f5132; }
        .badge-inativo { background: #f8d7da; color: #721c24; }
        .badge-pausado { background: #fff3cd; color: #856404; }
        .badge-urgente { background: #f8d7da; color: #721c24; }
        .badge-hoje { background: #cfe2ff; color: #084298; }

        /* Buttons */
        .btn-view, .btn-edit, .btn-delete, .btn-checklist, .btn-status {
            padding: 5px 12px;
            border-radius: 6px;
            font-size: 12px;
            text-decoration: none;
            display: inline-block;
            margin: 2px;
            transition: all 0.2s;
        }
        .btn-view { background: var(--info); color: white; }
        .btn-view:hover { background: #138496; color: white; }
        .btn-edit { background: var(--primary); color: white; }
        .btn-edit:hover { background: var(--primary-light); color: white; }
        .btn-delete { background: var(--danger); color: white; }
        .btn-delete:hover { background: #c82333; color: white; }
        .btn-checklist { background: var(--warning); color: #333; }
        .btn-checklist:hover { background: #e0a800; color: #333; }
        .btn-status { background: var(--gray-600); color: white; }

        /* Form Card */
        .form-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 25px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--gray-800);
            font-size: 13px;
        }

        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            font-size: 14px;
        }

        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-row-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20px;
        }

        .form-row-4 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr;
            gap: 20px;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .checkbox-item input {
            width: 18px;
            height: 18px;
            margin: 0;
        }

        .checkbox-item label {
            margin: 0;
            font-weight: normal;
        }

        .btn-salvar {
            background: var(--success);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-salvar:hover {
            background: #1e7e34;
        }

        .btn-cancelar {
            background: var(--gray-600);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }

        .btn-cancelar:hover {
            background: #5a6268;
            color: white;
        }

        .btn-add {
            background: var(--info);
            color: white;
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
        }

        .btn-add:hover {
            background: #138496;
            color: white;
        }

        /* Equipamentos List */
        .equipamentos-list {
            margin-top: 15px;
        }

        .equipamento-card {
            background: var(--gray-100);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }

        .equipamento-info {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
        }

        .equipamento-info span {
            font-size: 13px;
        }

        .equipamento-info strong {
            color: var(--primary);
        }

        .equipamento-actions {
            display: flex;
            gap: 5px;
        }

        /* Modal */
        .modal-content {
            border-radius: 12px;
        }

        .modal-header {
            background: var(--primary);
            color: white;
            border-radius: 12px 12px 0 0;
        }

        .modal-header .btn-close {
            filter: invert(1);
        }

        /* Checklist */
        .checklist-table {
            width: 100%;
        }

        .checklist-table th, .checklist-table td {
            padding: 10px;
            border-bottom: 1px solid var(--gray-200);
        }

        .resultado-ok { color: var(--success); }
        .resultado-pendente { color: var(--warning); }
        .resultado-problema { color: var(--danger); }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-success { background: #d1e7dd; color: #0f5132; border: 1px solid #badbcc; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 15px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .form-row, .form-row-3, .form-row-4 { grid-template-columns: 1fr; }
            .checkbox-group { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1>
                    <i class="fas fa-calendar-check"></i>
                    Manutenção Preventiva
                </h1>
                <div style="display: flex; gap: 15px;">
                    <a href="preventivas.php?acao=novo" class="btn-novo">
                        <i class="fas fa-plus"></i> Novo Contrato
                    </a>
                    <div class="user-badge">
                        <i class="fas fa-user-circle me-2"></i>
                        <?php echo htmlspecialchars($usuario['nome'] ?? 'Admin'); ?>
                    </div>
                </div>
            </div>

            <?php if ($mensagem): ?>
            <div class="alert alert-<?php echo $tipo_mensagem ?? 'success'; ?>">
                <i class="fas fa-<?php echo ($tipo_mensagem ?? 'success') === 'success' ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
                <?php echo htmlspecialchars($mensagem); ?>
            </div>
            <?php endif; ?>

            <?php if ($acao === 'listar'): ?>
                <!-- Stats Cards -->
                <?php 
                $total_ativos = 0;
                $total_pausados = 0;
                $total_inativos = 0;
                $proximos_7_dias = 0;
                $hoje = date('Y-m-d');
                
                foreach ($preventivas as $p) {
                    if ($p['status'] == 'ativo') $total_ativos++;
                    if ($p['status'] == 'pausado') $total_pausados++;
                    if ($p['status'] == 'inativo') $total_inativos++;
                    
                    $dias = (strtotime($p['proxima_data']) - strtotime($hoje)) / 86400;
                    if ($dias <= 7 && $dias >= 0 && $p['status'] == 'ativo') $proximos_7_dias++;
                }
                ?>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                        <div class="stat-number"><?php echo $total_ativos; ?></div>
                        <div class="stat-label">Contratos Ativos</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-pause-circle"></i></div>
                        <div class="stat-number"><?php echo $total_pausados; ?></div>
                        <div class="stat-label">Pausados</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-stop-circle"></i></div>
                        <div class="stat-number"><?php echo $total_inativos; ?></div>
                        <div class="stat-label">Inativos</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-calendar-week"></i></div>
                        <div class="stat-number"><?php echo $proximos_7_dias; ?></div>
                        <div class="stat-label">Vencem em 7 dias</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-tools"></i></div>
                        <div class="stat-number"><?php echo count($preventivas); ?></div>
                        <div class="stat-label">Total Contratos</div>
                    </div>
                </div>

                <div class="table-container">
                    <div class="table-responsive">
                        <table class="table table-hover" id="table-preventivas">
                            <thead>
                                <tr>
                                    <th>Contrato</th>
                                    <th>Cliente</th>
                                    <th>Frequência</th>
                                    <th>Próxima Data</th>
                                    <th>Status</th>
                                    <th>Equip.</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($preventivas as $item): 
                                    $dias_restantes = (strtotime($item['proxima_data']) - strtotime($hoje)) / 86400;
                                    $status_class = '';
                                    if ($item['status'] == 'ativo') {
                                        if ($dias_restantes <= 0) {
                                            $status_class = 'badge-urgente';
                                            $status_text = 'Atrasado!';
                                        } elseif ($dias_restantes <= 7) {
                                            $status_class = 'badge-hoje';
                                            $status_text = 'Vence em ' . floor($dias_restantes) . ' dias';
                                        } else {
                                            $status_class = 'badge-ativo';
                                            $status_text = 'Ativo';
                                        }
                                    } elseif ($item['status'] == 'pausado') {
                                        $status_class = 'badge-pausado';
                                        $status_text = 'Pausado';
                                    } else {
                                        $status_class = 'badge-inativo';
                                        $status_text = 'Inativo';
                                    }
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($item['numero']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($item['cliente_nome'] ?? '-'); ?></td>
                                    <td><?php echo $frequencias[$item['frequencia']] ?? $item['frequencia']; ?></td>
                                    <td>
                                        <?php echo formatarData($item['proxima_data']); ?>
                                        <?php if ($item['status'] == 'ativo' && $dias_restantes <= 7 && $dias_restantes > 0): ?>
                                        <br><small class="text-warning">⚠️ <?php echo floor($dias_restantes); ?> dias</small>
                                        <?php elseif ($item['status'] == 'ativo' && $dias_restantes <= 0): ?>
                                        <br><small class="text-danger">🔴 Atrasado!</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge-status <?php echo $status_class; ?>"><?php echo $status_text; ?></span></td>
                                    <td><?php echo $item['total_equipamentos']; ?> equip.</span></td>
                                    <td>
                                        <a href="preventivas.php?acao=editar&id=<?php echo $item['id']; ?>" class="btn-edit" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php if ($item['status'] == 'ativo'): ?>
                                        <a href="preventivas.php?acao=alterar_status&id=<?php echo $item['id']; ?>&status=pausado" class="btn-status" title="Pausar" onclick="return confirm('Pausar este contrato?')">
                                            <i class="fas fa-pause"></i>
                                        </a>
                                        <?php elseif ($item['status'] == 'pausado'): ?>
                                        <a href="preventivas.php?acao=alterar_status&id=<?php echo $item['id']; ?>&status=ativo" class="btn-status" title="Ativar" onclick="return confirm('Ativar este contrato?')">
                                            <i class="fas fa-play"></i>
                                        </a>
                                        <?php endif; ?>
                                        <a href="preventivas.php?acao=deletar&id=<?php echo $item['id']; ?>" class="btn-delete" title="Excluir" onclick="return confirm('Tem certeza que deseja excluir este contrato? Todos os equipamentos e históricos serão removidos.')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </span>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php elseif ($acao === 'novo' || ($acao === 'editar' && isset($preventiva))): ?>
                <!-- Formulário do Contrato -->
                <div class="form-card">
                    <h2 style="color: var(--primary); margin-bottom: 25px;">
                        <i class="fas fa-<?php echo $acao === 'novo' ? 'plus' : 'edit'; ?>"></i>
                        <?php echo $acao === 'novo' ? 'Novo Contrato de Preventiva' : 'Editar Contrato'; ?>
                    </h2>
                    
                    <form method="POST">
                        <input type="hidden" name="acao" value="salvar_preventiva">
                        <?php if ($acao === 'editar'): ?>
                        <input type="hidden" name="id" value="<?php echo $preventiva['id']; ?>">
                        <?php endif; ?>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Cliente *</label>
                                <select name="cliente_id" required>
                                    <option value="">Selecione um cliente</option>
                                    <?php foreach ($clientes as $cli): ?>
                                    <option value="<?php echo $cli['id']; ?>" <?php echo ($preventiva['cliente_id'] ?? '') == $cli['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cli['nome']); ?> <?php echo $cli['cpf_cnpj'] ? ' - ' . htmlspecialchars($cli['cpf_cnpj']) : ''; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Frequência *</label>
                                <select name="frequencia" required>
                                    <?php foreach ($frequencias as $key => $label): ?>
                                    <option value="<?php echo $key; ?>" <?php echo ($preventiva['frequencia'] ?? 'mensal') == $key ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row-3">
                            <div class="form-group">
                                <label>Próxima Manutenção</label>
                                <input type="date" name="proxima_data" value="<?php echo $preventiva['proxima_data'] ?? date('Y-m-d'); ?>">
                            </div>
                            <div class="form-group">
                                <label>Última Manutenção</label>
                                <input type="date" name="ultima_manutencao" value="<?php echo $preventiva['ultima_manutencao'] ?? ''; ?>">
                            </div>
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status">
                                    <?php foreach ($status_preventiva as $key => $label): ?>
                                    <option value="<?php echo $key; ?>" <?php echo ($preventiva['status'] ?? 'ativo') == $key ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Notificações</label>
                            <div class="checkbox-group">
                                <div class="checkbox-item">
                                    <input type="checkbox" name="notificar_whatsapp" id="notificar_whatsapp" value="1" <?php echo ($preventiva['notificar_whatsapp'] ?? 1) ? 'checked' : ''; ?>>
                                    <label for="notificar_whatsapp">Enviar notificações via WhatsApp</label>
                                </div>
                                <div class="checkbox-item">
                                    <input type="checkbox" name="notificacao_1_dia" id="notificacao_1_dia" value="1" <?php echo ($preventiva['notificacao_1_dia'] ?? 1) ? 'checked' : ''; ?>>
                                    <label for="notificacao_1_dia">1 dia antes</label>
                                </div>
                                <div class="checkbox-item">
                                    <input type="checkbox" name="notificacao_1_hora" id="notificacao_1_hora" value="1" <?php echo ($preventiva['notificacao_1_hora'] ?? 1) ? 'checked' : ''; ?>>
                                    <label for="notificacao_1_hora">1 hora antes</label>
                                </div>
                            </div>
                        </div>
                        
                        <div style="display: flex; gap: 15px; margin-top: 20px;">
                            <button type="submit" class="btn-salvar">
                                <i class="fas fa-save"></i> Salvar Contrato
                            </button>
                            <a href="preventivas.php" class="btn-cancelar">
                                <i class="fas fa-times"></i> Cancelar
                            </a>
                        </div>
                    </form>
                </div>

                <?php if ($acao === 'editar'): ?>
                <!-- Gerenciar Equipamentos -->
                <div class="form-card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h3 style="color: var(--primary); margin: 0;">
                            <i class="fas fa-microchip"></i> Equipamentos
                        </h3>
                        <button type="button" class="btn-add" data-bs-toggle="modal" data-bs-target="#modalEquipamento">
                            <i class="fas fa-plus"></i> Adicionar Equipamento
                        </button>
                    </div>
                    
                    <div class="equipamentos-list">
                        <?php if (empty($equipamentos)): ?>
                        <div style="text-align: center; padding: 30px; color: var(--gray-600);">
                            <i class="fas fa-microchip fa-2x mb-2"></i>
                            <p>Nenhum equipamento cadastrado neste contrato.</p>
                            <p>Clique em "Adicionar Equipamento" para começar.</p>
                        </div>
                        <?php else: ?>
                            <?php foreach ($equipamentos as $eq): ?>
                            <div class="equipamento-card">
                                <div class="equipamento-info">
                                    <span><strong>Marca:</strong> <?php echo htmlspecialchars($eq['marca'] ?: '-'); ?></span>
                                    <span><strong>Modelo:</strong> <?php echo htmlspecialchars($eq['modelo'] ?: '-'); ?></span>
                                    <span><strong>Potência:</strong> <?php echo htmlspecialchars($eq['potencia'] ?: '-'); ?></span>
                                    <span><strong>Gás:</strong> <?php echo htmlspecialchars($eq['gas_refrigerante'] ?: '-'); ?></span>
                                </div>
                                <div class="equipamento-actions">
                                    <button type="button" class="btn-edit" onclick="editarEquipamento(<?php echo $eq['id']; ?>, '<?php echo addslashes($eq['marca']); ?>', '<?php echo addslashes($eq['modelo']); ?>', '<?php echo addslashes($eq['potencia']); ?>', '<?php echo addslashes($eq['gas_refrigerante']); ?>', <?php echo $eq['carga_gas']; ?>, '<?php echo $eq['ambiente']; ?>')" style="padding: 5px 10px; font-size: 11px;">
                                        <i class="fas fa-edit"></i> Editar
                                    </button>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="acao" value="deletar_equipamento">
                                        <input type="hidden" name="equipamento_id" value="<?php echo $eq['id']; ?>">
                                        <input type="hidden" name="preventiva_id" value="<?php echo $preventiva['id']; ?>">
                                        <button type="submit" class="btn-delete" onclick="return confirm('Remover este equipamento?')" style="padding: 5px 10px; font-size: 11px;">
                                            <i class="fas fa-trash"></i> Remover
                                        </button>
                                    </form>
                                    <a href="preventivas.php?acao=checklist&id=<?php echo $eq['id']; ?>" class="btn-checklist" style="padding: 5px 10px; font-size: 11px;">
                                        <i class="fas fa-clipboard-list"></i> Checklist
                                    </a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

            <?php elseif ($acao === 'checklist' && isset($equipamento)): ?>
                <!-- Página de Checklist -->
                <div class="form-card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h2 style="color: var(--primary); margin: 0;">
                            <i class="fas fa-clipboard-list"></i> Checklist de Manutenção
                        </h2>
                        <a href="preventivas.php?acao=editar&id=<?php echo $equipamento['preventiva_id']; ?>" class="btn-cancelar">
                            <i class="fas fa-arrow-left"></i> Voltar
                        </a>
                    </div>
                    
                    <div class="info-row" style="background: var(--gray-100); padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                        <div><strong>Cliente:</strong> <?php echo htmlspecialchars($equipamento['cliente_nome']); ?></div>
                        <div><strong>Contrato:</strong> <?php echo htmlspecialchars($equipamento['preventiva_numero']); ?></div>
                        <div><strong>Equipamento:</strong> <?php echo htmlspecialchars($equipamento['marca'] ?: 'Não informado'); ?> - <?php echo htmlspecialchars($equipamento['modelo'] ?: 'Não informado'); ?></div>
                    </div>
                    
                    <form method="POST">
                        <input type="hidden" name="acao" value="salvar_checklist">
                        <input type="hidden" name="equipamento_id" value="<?php echo $equipamento['id']; ?>">
                        <input type="hidden" name="preventiva_id" value="<?php echo $equipamento['preventiva_id']; ?>">
                        
                        <div class="form-group">
                            <label>Data da Manutenção</label>
                            <input type="date" name="data_manutencao" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="checklist-table">
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th style="width: 150px;">Resultado</th>
                                        <th>Observação</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($checklist_itens as $index => $item): ?>
                                    <tr>
                                        <td><?php echo $item; ?></td>
                                        <td>
                                            <select name="itens[<?php echo $index; ?>]" required style="width: 100%; padding: 5px; border-radius: 4px; border: 1px solid #ddd;">
                                                <option value="ok">✓ OK</option>
                                                <option value="pendente">⚠ Pendente</option>
                                                <option value="problema">✗ Problema</option>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="text" name="observacoes[<?php echo $index; ?>]" placeholder="Observação..." style="width: 100%; padding: 5px; border-radius: 4px; border: 1px solid #ddd;">
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div style="display: flex; gap: 15px; margin-top: 20px;">
                            <button type="submit" class="btn-salvar">
                                <i class="fas fa-save"></i> Salvar Checklist
                            </button>
                            <a href="preventivas.php?acao=editar&id=<?php echo $equipamento['preventiva_id']; ?>" class="btn-cancelar">
                                <i class="fas fa-times"></i> Cancelar
                            </a>
                        </div>
                    </form>
                </div>

            <?php endif; ?>
        </main>
    </div>

    <!-- Modal Adicionar/Editar Equipamento -->
    <div class="modal fade" id="modalEquipamento" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-microchip"></i> Adicionar Equipamento</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="acao" value="salvar_equipamento">
                        <input type="hidden" name="preventiva_id" value="<?php echo $preventiva['id']; ?>">
                        <input type="hidden" name="equipamento_id" id="equipamento_id" value="">
                        
                        <div class="form-group">
                            <label>Marca</label>
                            <input type="text" name="marca" id="marca" class="form-control" placeholder="Ex: LG, Samsung, Midea...">
                        </div>
                        <div class="form-group">
                            <label>Modelo</label>
                            <input type="text" name="modelo" id="modelo" class="form-control" placeholder="Ex: S3-W12, M3F...">
                        </div>
                        <div class="form-group">
                            <label>Potência (BTUs)</label>
                            <input type="text" name="potencia" id="potencia" class="form-control" placeholder="Ex: 12000, 18000, 24000...">
                        </div>
                        <div class="form-group">
                            <label>Gás Refrigerante</label>
                            <input type="text" name="gas_refrigerante" id="gas_refrigerante" class="form-control" placeholder="Ex: R-410A, R-32...">
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Carga de Gás (kg)</label>
                                <input type="text" name="carga_gas" id="carga_gas" class="form-control" placeholder="0,00">
                            </div>
                            <div class="form-group">
                                <label>Estado do Ambiente</label>
                                <select name="ambiente" id="ambiente" class="form-control">
                                    <option value="limpo">Limpo</option>
                                    <option value="pouco_sujo">Pouco Sujo</option>
                                    <option value="medio_sujo">Médio Sujo</option>
                                    <option value="muito_sujo">Muito Sujo</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Salvar Equipamento</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function editarEquipamento(id, marca, modelo, potencia, gas, cargaGas, ambiente) {
            document.getElementById('equipamento_id').value = id;
            document.getElementById('marca').value = marca;
            document.getElementById('modelo').value = modelo;
            document.getElementById('potencia').value = potencia;
            document.getElementById('gas_refrigerante').value = gas;
            document.getElementById('carga_gas').value = cargaGas.toString().replace('.', ',');
            document.getElementById('ambiente').value = ambiente;
            
            var modal = new bootstrap.Modal(document.getElementById('modalEquipamento'));
            modal.show();
        }
        
        // Reset modal quando fechar
        document.getElementById('modalEquipamento').addEventListener('hidden.bs.modal', function () {
            document.getElementById('equipamento_id').value = '';
            document.getElementById('marca').value = '';
            document.getElementById('modelo').value = '';
            document.getElementById('potencia').value = '';
            document.getElementById('gas_refrigerante').value = '';
            document.getElementById('carga_gas').value = '';
            document.getElementById('ambiente').value = 'limpo';
        });
        
        $(document).ready(function() {
            if ($('#table-preventivas').length) {
                $('#table-preventivas').DataTable({
                    language: {
                        url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-BR.json'
                    },
                    pageLength: 25,
                    order: [[3, 'asc']],
                    columnDefs: [{ orderable: false, targets: [6] }]
                });
            }
        });
    </script>
</body>
</html>