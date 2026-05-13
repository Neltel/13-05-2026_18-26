<?php
/**
 * =====================================================================
 * GERENCIAMENTO DE CLIENTES - COM EXCLUSÃO EM CASCATA CONTROLADA
 * =====================================================================
 */

require_once __DIR__ . '/../../config/environment.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';

session_start();

if (!Auth::isLogado() || !Auth::isAdmin()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$usuario = Auth::obter_usuario();
global $conexao;

$acao = $_GET['acao'] ?? 'listar';
$id = isset($_GET['id']) ? intval($_GET['id']) : null;
$mensagem = '';
$erro = '';
$clientes = [];
$total_paginas = 0;
$pagina = 1;

// ===== FUNÇÕES AUXILIARES =====
function formatar_cpf_cnpj($valor) {
    $valor = preg_replace('/\D/', '', $valor);
    
    if (strlen($valor) == 11) {
        return substr($valor, 0, 3) . '.' . substr($valor, 3, 3) . '.' . substr($valor, 6, 3) . '-' . substr($valor, 9);
    } elseif (strlen($valor) == 14) {
        return substr($valor, 0, 2) . '.' . substr($valor, 2, 3) . '.' . substr($valor, 5, 3) . '/' . substr($valor, 8, 4) . '-' . substr($valor, 12);
    }
    
    return $valor;
}

function formatar_telefone($valor) {
    $valor = preg_replace('/\D/', '', $valor);
    
    if (strlen($valor) == 10) {
        return '(' . substr($valor, 0, 2) . ') ' . substr($valor, 2, 4) . '-' . substr($valor, 6);
    } elseif (strlen($valor) == 11) {
        return '(' . substr($valor, 0, 2) . ') ' . substr($valor, 2, 5) . '-' . substr($valor, 7);
    }
    
    return $valor;
}

/**
 * Buscar todos os registros relacionados a um cliente
 */
function buscarRegistrosRelacionados($conexao, $cliente_id) {
    $registros = [];
    
    // Orçamentos
    $sql = "SELECT id, numero, data_emissao, valor_total, situacao FROM orcamentos WHERE cliente_id = ? ORDER BY data_emissao DESC";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $cliente_id);
    $stmt->execute();
    $registros['orcamentos'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Vendas
    $sql = "SELECT id, numero, data_venda, valor_total, situacao FROM vendas WHERE cliente_id = ? ORDER BY data_venda DESC";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $cliente_id);
    $stmt->execute();
    $registros['vendas'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Pedidos
    $sql = "SELECT id, numero, data_pedido, valor_total, situacao FROM pedidos WHERE cliente_id = ? ORDER BY data_pedido DESC";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $cliente_id);
    $stmt->execute();
    $registros['pedidos'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Cobranças
    $sql = "SELECT id, numero, valor, data_vencimento, status FROM cobrancas WHERE cliente_id = ? ORDER BY data_vencimento DESC";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $cliente_id);
    $stmt->execute();
    $registros['cobrancas'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Agendamentos
    $sql = "SELECT id, data_agendamento, horario_inicio, status FROM agendamentos WHERE cliente_id = ? ORDER BY data_agendamento DESC";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $cliente_id);
    $stmt->execute();
    $registros['agendamentos'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Preventivas
    $sql = "SELECT id, numero, tipo, frequencia, status FROM preventivas WHERE cliente_id = ? ORDER BY id DESC";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $cliente_id);
    $stmt->execute();
    $registros['preventivas'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Garantias
    $sql = "SELECT id, numero, data_emissao, data_validade, tipo FROM garantias WHERE cliente_id = ? ORDER BY data_emissao DESC";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $cliente_id);
    $stmt->execute();
    $registros['garantias'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Contratos
    $sql = "SELECT id, numero, data_emissao, valor_total, status FROM contratos WHERE cliente_id = ? ORDER BY data_emissao DESC";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $cliente_id);
    $stmt->execute();
    $registros['contratos'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $registros;
}

/**
 * Deletar todos os registros relacionados a um cliente (exclusão em cascata)
 */
function deletarRegistrosRelacionados($conexao, $cliente_id, $opcoes) {
    $conexao->begin_transaction();
    
    try {
        // Orçamentos
        if (isset($opcoes['orcamentos']) && !empty($opcoes['orcamentos'])) {
            $ids = implode(',', array_map('intval', $opcoes['orcamentos']));
            $conexao->query("DELETE FROM orcamento_produtos WHERE orcamento_id IN ($ids)");
            $conexao->query("DELETE FROM orcamento_servicos WHERE orcamento_id IN ($ids)");
            $conexao->query("DELETE FROM checklist_equipamentos WHERE orcamento_id IN ($ids)");
            $conexao->query("DELETE FROM checklist_obra WHERE orcamento_id IN ($ids)");
            $conexao->query("DELETE FROM checklist_resumo WHERE orcamento_id IN ($ids)");
            $conexao->query("DELETE FROM logs_contratos WHERE orcamento_id IN ($ids)");
            $conexao->query("DELETE FROM orcamentos WHERE id IN ($ids)");
        }
        
        // Vendas
        if (isset($opcoes['vendas']) && !empty($opcoes['vendas'])) {
            $ids = implode(',', array_map('intval', $opcoes['vendas']));
            $conexao->query("DELETE FROM vendas WHERE id IN ($ids)");
        }
        
        // Pedidos
        if (isset($opcoes['pedidos']) && !empty($opcoes['pedidos'])) {
            $ids = implode(',', array_map('intval', $opcoes['pedidos']));
            $conexao->query("DELETE FROM pedido_produtos WHERE pedido_id IN ($ids)");
            $conexao->query("DELETE FROM pedido_servicos WHERE pedido_id IN ($ids)");
            $conexao->query("DELETE FROM pedidos WHERE id IN ($ids)");
        }
        
        // Cobranças
        if (isset($opcoes['cobrancas']) && !empty($opcoes['cobrancas'])) {
            $ids = implode(',', array_map('intval', $opcoes['cobrancas']));
            $conexao->query("DELETE FROM cobrancas WHERE id IN ($ids)");
        }
        
        // Agendamentos
        if (isset($opcoes['agendamentos']) && !empty($opcoes['agendamentos'])) {
            $ids = implode(',', array_map('intval', $opcoes['agendamentos']));
            $conexao->query("DELETE FROM agendamentos WHERE id IN ($ids)");
        }
        
        // Preventivas
        if (isset($opcoes['preventivas']) && !empty($opcoes['preventivas'])) {
            $ids = implode(',', array_map('intval', $opcoes['preventivas']));
            $conexao->query("DELETE FROM pmp_equipamentos WHERE preventiva_id IN ($ids)");
            $conexao->query("DELETE FROM preventivas WHERE id IN ($ids)");
        }
        
        // Garantias
        if (isset($opcoes['garantias']) && !empty($opcoes['garantias'])) {
            $ids = implode(',', array_map('intval', $opcoes['garantias']));
            $conexao->query("DELETE FROM garantias WHERE id IN ($ids)");
        }
        
        // Contratos
        if (isset($opcoes['contratos']) && !empty($opcoes['contratos'])) {
            $ids = implode(',', array_map('intval', $opcoes['contratos']));
            $conexao->query("DELETE FROM contratos WHERE id IN ($ids)");
        }
        
        // Finalmente, deletar o cliente
        $conexao->query("DELETE FROM clientes WHERE id = $cliente_id");
        
        $conexao->commit();
        return ['success' => true, 'message' => 'Cliente e todos os registros relacionados foram deletados com sucesso!'];
        
    } catch (Exception $e) {
        $conexao->rollback();
        return ['success' => false, 'message' => 'Erro ao deletar: ' . $e->getMessage()];
    }
}

// ===== PROCESSAR AÇÕES POST =====

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Processar exclusão em cascata
    if (isset($_POST['acao']) && $_POST['acao'] === 'deletar_cascata') {
        $cliente_id = intval($_POST['cliente_id']);
        
        $opcoes = [];
        $tipos = ['orcamentos', 'vendas', 'pedidos', 'cobrancas', 'agendamentos', 'preventivas', 'garantias', 'contratos'];
        
        foreach ($tipos as $tipo) {
            if (isset($_POST[$tipo]) && is_array($_POST[$tipo])) {
                $opcoes[$tipo] = array_map('intval', $_POST[$tipo]);
            }
        }
        
        $resultado = deletarRegistrosRelacionados($conexao, $cliente_id, $opcoes);
        
        if ($resultado['success']) {
            header('Location: ' . BASE_URL . '/app/admin/clientes.php?mensagem=deletado_cascata');
            exit;
        } else {
            $erro = $resultado['message'];
        }
    }
    
    // Salvar cliente (manter o código existente)
    if (isset($_POST['acao']) && $_POST['acao'] === 'salvar') {
        // ... (manter o código de salvar cliente existente)
        $nome = isset($_POST['nome']) ? $conexao->real_escape_string(trim($_POST['nome'])) : '';
        $pessoa_tipo = isset($_POST['pessoa_tipo']) ? $_POST['pessoa_tipo'] : 'fisica';
        $cpf_cnpj = isset($_POST['cpf_cnpj']) ? preg_replace('/\D/', '', $_POST['cpf_cnpj']) : '';
        $telefone = isset($_POST['telefone']) ? preg_replace('/\D/', '', $_POST['telefone']) : '';
        $whatsapp = isset($_POST['whatsapp']) ? preg_replace('/\D/', '', $_POST['whatsapp']) : '';
        $email = isset($_POST['email']) ? $conexao->real_escape_string($_POST['email']) : '';
        $endereco_rua = isset($_POST['endereco_rua']) ? $conexao->real_escape_string(trim($_POST['endereco_rua'])) : '';
        $endereco_numero = isset($_POST['endereco_numero']) ? $conexao->real_escape_string($_POST['endereco_numero']) : '';
        $endereco_bairro = isset($_POST['endereco_bairro']) ? $conexao->real_escape_string($_POST['endereco_bairro']) : '';
        $endereco_cidade = isset($_POST['endereco_cidade']) ? $conexao->real_escape_string(trim($_POST['endereco_cidade'])) : '';
        $endereco_estado = isset($_POST['endereco_estado']) ? $_POST['endereco_estado'] : 'SP';
        
        $id_editar = isset($_POST['id']) && !empty($_POST['id']) ? intval($_POST['id']) : null;
        
        if (empty($nome)) {
            $erro = "Nome é obrigatório";
        } elseif (empty($cpf_cnpj)) {
            $erro = "CPF/CNPJ é obrigatório";
        } else {
            if ($id_editar) {
                $sql_check = "SELECT id FROM clientes WHERE cpf_cnpj = ? AND id != ?";
                $stmt_check = $conexao->prepare($sql_check);
                $stmt_check->bind_param("si", $cpf_cnpj, $id_editar);
                $stmt_check->execute();
                $result_check = $stmt_check->get_result();
                
                if ($result_check->num_rows > 0) {
                    $erro = "❌ Este CPF/CNPJ já está cadastrado para outro cliente!";
                } else {
                    $sql = "UPDATE clientes SET nome=?, pessoa_tipo=?, cpf_cnpj=?, telefone=?, whatsapp=?, email=?, endereco_rua=?, endereco_numero=?, endereco_bairro=?, endereco_cidade=?, endereco_estado=? WHERE id=?";
                    $stmt = $conexao->prepare($sql);
                    $stmt->bind_param("sssssssssssi", $nome, $pessoa_tipo, $cpf_cnpj, $telefone, $whatsapp, $email, $endereco_rua, $endereco_numero, $endereco_bairro, $endereco_cidade, $endereco_estado, $id_editar);
                    
                    if ($stmt->execute()) {
                        header('Location: ' . BASE_URL . '/app/admin/clientes.php?mensagem=atualizado');
                        exit;
                    } else {
                        $erro = "Erro ao atualizar: " . $stmt->error;
                    }
                }
            } else {
                $sql_check = "SELECT id FROM clientes WHERE cpf_cnpj = ?";
                $stmt_check = $conexao->prepare($sql_check);
                $stmt_check->bind_param("s", $cpf_cnpj);
                $stmt_check->execute();
                $result_check = $stmt_check->get_result();
                
                if ($result_check->num_rows > 0) {
                    $erro = "❌ Este CPF/CNPJ já está cadastrado no sistema!";
                } else {
                    $sql = "INSERT INTO clientes (nome, pessoa_tipo, cpf_cnpj, telefone, whatsapp, email, endereco_rua, endereco_numero, endereco_bairro, endereco_cidade, endereco_estado, ativo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";
                    $stmt = $conexao->prepare($sql);
                    $stmt->bind_param("sssssssssss", $nome, $pessoa_tipo, $cpf_cnpj, $telefone, $whatsapp, $email, $endereco_rua, $endereco_numero, $endereco_bairro, $endereco_cidade, $endereco_estado);
                    
                    if ($stmt->execute()) {
                        header('Location: ' . BASE_URL . '/app/admin/clientes.php?mensagem=criado');
                        exit;
                    } else {
                        $erro = "Erro ao criar: " . $stmt->error;
                    }
                }
            }
        }
    }
}

// ===== PROCESSAR AÇÕES GET =====

// Mostrar tela de confirmação de exclusão com todos os registros
if ($acao === 'confirmar_deletar' && $id) {
    $sql = "SELECT * FROM clientes WHERE id = ?";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $cliente = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$cliente) {
        header('Location: ' . BASE_URL . '/app/admin/clientes.php');
        exit;
    }
    
    $registros = buscarRegistrosRelacionados($conexao, $id);
    $total_registros = 0;
    foreach ($registros as $tipo => $items) {
        $total_registros += count($items);
    }
}

// Deletar simples (apenas se não tiver dependências)
if ($acao === 'deletar' && $id) {
    $registros = buscarRegistrosRelacionados($conexao, $id);
    $total_registros = 0;
    foreach ($registros as $tipo => $items) {
        $total_registros += count($items);
    }
    
    if ($total_registros > 0) {
        // Redirecionar para tela de confirmação
        header('Location: ' . BASE_URL . '/app/admin/clientes.php?acao=confirmar_deletar&id=' . $id);
        exit;
    } else {
        // Sem dependências, deletar direto
        $sql = "DELETE FROM clientes WHERE id = ?";
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            header('Location: ' . BASE_URL . '/app/admin/clientes.php?mensagem=deletado');
            exit;
        } else {
            $erro = "Erro ao deletar cliente: " . $stmt->error;
        }
        $stmt->close();
    }
}

// ===== LISTAR CLIENTES =====
if ($acao === 'listar') {
    $pagina = isset($_GET['pagina']) ? intval($_GET['pagina']) : 1;
    $limite = 50;
    $offset = ($pagina - 1) * $limite;
    
    $sql = "SELECT * FROM clientes ORDER BY nome ASC LIMIT {$limite} OFFSET {$offset}";
    $resultado = $conexao->query($sql);
    
    if ($resultado) {
        while ($linha = $resultado->fetch_assoc()) {
            $clientes[] = $linha;
        }
    }
    
    $sql_total = "SELECT COUNT(*) as total FROM clientes";
    $resultado_total = $conexao->query($sql_total);
    
    if ($resultado_total) {
        $total = $resultado_total->fetch_assoc()['total'];
        $total_paginas = ceil($total / $limite);
    }
    
    if (isset($_GET['mensagem'])) {
        if ($_GET['mensagem'] === 'criado') {
            $mensagem = "✓ Cliente criado com sucesso!";
        } elseif ($_GET['mensagem'] === 'atualizado') {
            $mensagem = "✓ Cliente atualizado com sucesso!";
        } elseif ($_GET['mensagem'] === 'deletado') {
            $mensagem = "✓ Cliente deletado com sucesso!";
        } elseif ($_GET['mensagem'] === 'deletado_cascata') {
            $mensagem = "✓ Cliente e todos os registros relacionados foram deletados com sucesso!";
        }
    }
    
} elseif ($acao === 'novo') {
    $cliente = [
        'id' => '', 'nome' => '', 'pessoa_tipo' => 'fisica', 'cpf_cnpj' => '', 
        'telefone' => '', 'whatsapp' => '', 'email' => '', 'endereco_rua' => '', 
        'endereco_numero' => '', 'endereco_bairro' => '', 'endereco_cidade' => '', 
        'endereco_estado' => 'SP', 'ativo' => 1
    ];
    
} elseif ($acao === 'editar' && $id) {
    $sql = "SELECT * FROM clientes WHERE id = ?";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $cliente = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$cliente) {
        $erro = "Cliente não encontrado";
        $acao = 'listar';
    }
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clientes - Império AR</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/css/admin.css">
    <style>
        .admin-container {
            display: flex;
            min-height: 100vh;
            background: #f5f6fa;
        }
        
        .sidebar {
            width: 280px;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: 20px;
            overflow-y: auto;
            position: fixed;
            height: 100vh;
            z-index: 50;
            box-shadow: 0 8px 24px rgba(0,0,0,0.2);
        }
        
        .sidebar-header {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.2);
        }
        
        .sidebar-header h2 {
            color: white;
            margin-bottom: 8px;
            font-size: 20px;
        }
        
        .sidebar-header p {
            color: rgba(255,255,255,0.8);
            font-size: 12px;
            margin: 0;
        }
        
        .sidebar-nav {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .nav-item {
            padding: 12px 16px;
            border-radius: 6px;
            color: rgba(255,255,255,0.8);
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }
        
        .nav-item:hover,
        .nav-item.active {
            background: rgba(255,255,255,0.2);
            color: white;
            padding-left: 24px;
        }
        
        .sidebar-footer {
            position: absolute;
            bottom: 20px;
            width: calc(100% - 40px);
        }
        
        .btn-logout {
            width: 100%;
            background: rgba(255,255,255,0.2);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
            padding: 12px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: block;
            text-align: center;
        }
        
        .btn-logout:hover {
            background: #dc3545;
            border-color: #dc3545;
        }
        
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 20px;
            overflow-y: auto;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 2px solid #eee;
        }
        
        .page-header h1 {
            margin: 0;
        }
        
        .btn-novo {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-novo:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .form-group {
            margin-bottom: 15px;
            position: relative;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #333;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-family: Arial, sans-serif;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 5px rgba(102, 126, 234, 0.3);
        }
        
        .validation-message {
            font-size: 12px;
            margin-top: 5px;
            display: block;
        }
        
        .validation-error {
            color: #dc3545;
        }
        
        .validation-success {
            color: #28a745;
        }
        
        input.validation-error-field {
            border-color: #dc3545 !important;
            background-color: #fff8f8 !important;
        }
        
        input.validation-success-field {
            border-color: #28a745 !important;
            background-color: #f0fff0 !important;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .btn-salvar {
            background: #28a745;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        
        .btn-salvar:hover:not(:disabled) {
            background: #218838;
            transform: translateY(-2px);
        }
        
        .btn-salvar:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .btn-cancelar {
            background: #6c757d;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            margin-left: 10px;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-cancelar:hover {
            background: #5a6268;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            margin-top: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-radius: 4px;
            overflow: hidden;
        }
        
        .table th {
            background: #f5f5f5;
            padding: 12px;
            text-align: left;
            border-bottom: 2px solid #ddd;
            font-weight: bold;
        }
        
        .table td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
        }
        
        .table tr:hover {
            background: #f9f9f9;
        }
        
        .btn-editar {
            background: #007bff;
            color: white;
            padding: 6px 12px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
            text-decoration: none;
            display: inline-block;
            margin-right: 5px;
        }
        
        .btn-editar:hover {
            background: #0056b3;
        }
        
        .btn-deletar {
            background: #dc3545;
            color: white;
            padding: 6px 12px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-deletar:hover {
            background: #c82333;
        }
        
        .alert {
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            white-space: pre-line;
        }
        
        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
        }
        
        .form-card {
            background: white;
            padding: 20px;
            border-radius: 4px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .empty-message {
            text-align: center;
            padding: 40px;
            color: #666;
            background: #f9f9f9;
            border-radius: 4px;
        }
        
        .loading {
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #3498db;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-left: 5px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Estilos para tela de confirmação de exclusão */
        .confirm-delete-container {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .client-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #dc3545;
        }
        
        .registros-section {
            margin-bottom: 25px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .registros-header {
            background: #f8f9fa;
            padding: 12px 15px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: bold;
            border-bottom: 1px solid #dee2e6;
        }
        
        .registros-header:hover {
            background: #e9ecef;
        }
        
        .registros-content {
            padding: 15px;
            display: none;
        }
        
        .registros-content.show {
            display: block;
        }
        
        .registros-table {
            width: 100%;
            font-size: 13px;
        }
        
        .registros-table th,
        .registros-table td {
            padding: 8px;
            border-bottom: 1px solid #dee2e6;
        }
        
        .select-all {
            margin-bottom: 10px;
        }
        
        .btn-deletar-cascata {
            background: #dc3545;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            margin-top: 20px;
        }
        
        .btn-deletar-cascata:hover {
            background: #c82333;
        }
        
        .btn-selecionar-todos {
            background: #6c757d;
            color: white;
            padding: 5px 10px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
        }
        
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
        }
        
        .badge-pendente { background: #ffc107; color: #333; }
        .badge-aprovado { background: #28a745; color: white; }
        .badge-concluido { background: #17a2b8; color: white; }
        .badge-cancelado { background: #dc3545; color: white; }
        .badge-recebida { background: #28a745; color: white; }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <main class="main-content">
            
            <?php if ($mensagem): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($mensagem); ?></div>
            <?php endif; ?>

            <?php if ($erro): ?>
            <div class="alert alert-error"><?php echo $erro; ?></div>
            <?php endif; ?>

            <?php if ($acao === 'listar'): ?>
                
                <div class="page-header">
                    <h1>👥 Gerenciamento de Clientes</h1>
                    <a href="<?php echo BASE_URL; ?>/app/admin/clientes.php?acao=novo" class="btn-novo">
                        ➕ Novo Cliente
                    </a>
                </div>

                <?php if (count($clientes) > 0): ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>CPF/CNPJ</th>
                            <th>Telefone</th>
                            <th>Email</th>
                            <th>Cidade/Estado</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clientes as $cli): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($cli['nome']); ?></td>
                            <td><?php echo formatar_cpf_cnpj($cli['cpf_cnpj']); ?></td>
                            <td><?php echo formatar_telefone($cli['telefone']); ?></td>
                            <td><?php echo htmlspecialchars($cli['email']); ?></td>
                            <td><?php echo htmlspecialchars($cli['endereco_cidade'] . '/' . $cli['endereco_estado']); ?></td>
                            <td>
                                <a href="<?php echo BASE_URL; ?>/app/admin/clientes.php?acao=editar&id=<?php echo $cli['id']; ?>" class="btn-editar">✏️ Editar</a>
                                <a href="<?php echo BASE_URL; ?>/app/admin/clientes.php?acao=deletar&id=<?php echo $cli['id']; ?>" class="btn-deletar" onclick="return confirm('⚠️ Este cliente pode ter registros vinculados. Deseja prosseguir?')">🗑️ Deletar</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($total_paginas > 1): ?>
                <div style="margin-top: 20px; text-align: center;">
                    <?php for ($p = 1; $p <= $total_paginas; $p++): ?>
                    <a href="?pagina=<?php echo $p; ?>" style="padding: 8px 12px; margin: 0 5px; background: <?php echo $p == $pagina ? '#667eea' : '#ddd'; ?>; color: <?php echo $p == $pagina ? 'white' : '#333'; ?>; text-decoration: none; border-radius: 3px; display: inline-block;"><?php echo $p; ?></a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
                
                <?php else: ?>
                <div class="empty-message">
                    <p>Nenhum cliente cadastrado.</p>
                    <a href="?acao=novo" class="btn-novo" style="margin-top: 15px;">➕ Criar Novo Cliente</a>
                </div>
                <?php endif; ?>

            <?php elseif ($acao === 'confirmar_deletar' && isset($cliente)): ?>
                
                <div class="page-header">
                    <h1>⚠️ Confirmar Exclusão de Cliente</h1>
                    <a href="<?php echo BASE_URL; ?>/app/admin/clientes.php" class="btn-cancelar">✕ Cancelar</a>
                </div>

                <div class="confirm-delete-container">
                    <div class="client-info">
                        <h3>📋 Informações do Cliente</h3>
                        <p><strong>Nome:</strong> <?php echo htmlspecialchars($cliente['nome']); ?></p>
                        <p><strong>CPF/CNPJ:</strong> <?php echo formatar_cpf_cnpj($cliente['cpf_cnpj']); ?></p>
                        <p><strong>Telefone:</strong> <?php echo formatar_telefone($cliente['telefone']); ?></p>
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($cliente['email']); ?></p>
                        <p><strong>Endereço:</strong> <?php echo htmlspecialchars($cliente['endereco_rua'] . ', ' . $cliente['endereco_numero'] . ' - ' . $cliente['endereco_cidade'] . '/' . $cliente['endereco_estado']); ?></p>
                    </div>

                    <div class="alert alert-warning">
                        <strong>⚠️ ATENÇÃO!</strong> Este cliente possui <strong><?php echo $total_registros; ?></strong> registro(s) vinculado(s).<br>
                        Selecione abaixo quais registros deseja excluir junto com o cliente.
                    </div>

                    <form method="POST" id="formDeletarCascata">
                        <input type="hidden" name="acao" value="deletar_cascata">
                        <input type="hidden" name="cliente_id" value="<?php echo $cliente['id']; ?>">

                        <?php 
                        $tipos = [
                            'orcamentos' => ['nome' => '📄 Orçamentos', 'cor' => '#17a2b8', 'campos' => ['numero', 'data_emissao', 'valor_total', 'situacao']],
                            'vendas' => ['nome' => '💰 Vendas', 'cor' => '#28a745', 'campos' => ['numero', 'data_venda', 'valor_total', 'situacao']],
                            'pedidos' => ['nome' => '📦 Pedidos', 'cor' => '#ffc107', 'campos' => ['numero', 'data_pedido', 'valor_total', 'situacao']],
                            'cobrancas' => ['nome' => '💳 Cobranças', 'cor' => '#dc3545', 'campos' => ['numero', 'data_vencimento', 'valor', 'status']],
                            'agendamentos' => ['nome' => '📅 Agendamentos', 'cor' => '#6c757d', 'campos' => ['data_agendamento', 'horario_inicio', 'status']],
                            'preventivas' => ['nome' => '🔧 Manutenções Preventivas', 'cor' => '#20c997', 'campos' => ['numero', 'tipo', 'frequencia', 'status']],
                            'garantias' => ['nome' => '🛡️ Garantias', 'cor' => '#fd7e14', 'campos' => ['numero', 'data_emissao', 'data_validade', 'tipo']],
                            'contratos' => ['nome' => '📑 Contratos', 'cor' => '#6610f2', 'campos' => ['numero', 'data_emissao', 'valor_total', 'status']]
                        ];
                        ?>

                        <?php foreach ($tipos as $tipo => $info): ?>
                            <?php if (!empty($registros[$tipo])): ?>
                            <div class="registros-section">
                                <div class="registros-header" onclick="toggleSection(this)">
                                    <span style="color: <?php echo $info['cor']; ?>;"><?php echo $info['nome']; ?></span>
                                    <span><?php echo count($registros[$tipo]); ?> registro(s) 
                                    <span style="font-size: 12px;">▼</span></span>
                                </div>
                                <div class="registros-content">
                                    <div class="select-all">
                                        <label>
                                            <input type="checkbox" class="select-all-<?php echo $tipo; ?>" onchange="selecionarTodos('<?php echo $tipo; ?>', this.checked)">
                                            <strong>Selecionar todos os <?php echo $info['nome']; ?></strong>
                                        </label>
                                    </div>
                                    <table class="registros-table">
                                        <thead>
                                            <tr>
                                                <th><input type="checkbox" id="select_all_<?php echo $tipo; ?>" onchange="selecionarTodos('<?php echo $tipo; ?>', this.checked)"></th>
                                                <?php foreach ($info['campos'] as $campo): ?>
                                                    <th><?php echo ucfirst(str_replace('_', ' ', $campo)); ?></th>
                                                <?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($registros[$tipo] as $item): ?>
                                            <tr>
                                                <td><input type="checkbox" name="<?php echo $tipo; ?>[]" value="<?php echo $item['id']; ?>" class="checkbox-<?php echo $tipo; ?>"></td>
                                                <?php foreach ($info['campos'] as $campo): ?>
                                                    <td>
                                                        <?php 
                                                        if (strpos($campo, 'valor') !== false) {
                                                            echo 'R$ ' . number_format($item[$campo] ?? 0, 2, ',', '.');
                                                        } elseif (strpos($campo, 'data') !== false && !empty($item[$campo])) {
                                                            echo date('d/m/Y', strtotime($item[$campo]));
                                                        } elseif ($campo == 'situacao' || $campo == 'status') {
                                                            $status = $item[$campo] ?? 'pendente';
                                                            $badge_class = '';
                                                            if ($status == 'pendente') $badge_class = 'badge-pendente';
                                                            elseif ($status == 'aprovado') $badge_class = 'badge-aprovado';
                                                            elseif ($status == 'concluido') $badge_class = 'badge-concluido';
                                                            elseif ($status == 'recebida') $badge_class = 'badge-recebida';
                                                            elseif ($status == 'cancelado' || $status == 'cancelada') $badge_class = 'badge-cancelado';
                                                            echo '<span class="badge ' . $badge_class . '">' . ucfirst($status) . '</span>';
                                                        } else {
                                                            echo htmlspecialchars($item[$campo] ?? '-');
                                                        }
                                                        ?>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php endforeach; ?>

                        <div style="margin-top: 30px; text-align: center;">
                            <button type="button" class="btn-selecionar-todos" onclick="selecionarTodasSecoes(true)">✅ Selecionar Todos os Registros</button>
                            <button type="button" class="btn-selecionar-todos" onclick="selecionarTodasSecoes(false)" style="margin-left: 10px;">❌ Desmarcar Todos</button>
                        </div>

                        <div style="margin-top: 30px; display: flex; gap: 15px; justify-content: center;">
                            <button type="submit" class="btn-deletar-cascata" onclick="return confirm('⚠️ ATENÇÃO! Tem certeza que deseja excluir o cliente e TODOS os registros selecionados?\n\nEsta ação NÃO pode ser desfeita!')">
                                🗑️ Excluir Cliente e Registros Selecionados
                            </button>
                            <a href="<?php echo BASE_URL; ?>/app/admin/clientes.php" class="btn-cancelar">✕ Cancelar</a>
                        </div>
                    </form>
                </div>

                <script>
                    function toggleSection(header) {
                        const content = header.nextElementSibling;
                        content.classList.toggle('show');
                        const arrow = header.querySelector('span:last-child span');
                        if (arrow) {
                            arrow.textContent = content.classList.contains('show') ? '▲' : '▼';
                        }
                    }
                    
                    function selecionarTodos(tipo, checked) {
                        const checkboxes = document.querySelectorAll(`.checkbox-${tipo}`);
                        checkboxes.forEach(cb => cb.checked = checked);
                    }
                    
                    function selecionarTodasSecoes(checked) {
                        const tipos = ['orcamentos', 'vendas', 'pedidos', 'cobrancas', 'agendamentos', 'preventivas', 'garantias', 'contratos'];
                        tipos.forEach(tipo => {
                            const checkboxes = document.querySelectorAll(`.checkbox-${tipo}`);
                            checkboxes.forEach(cb => cb.checked = checked);
                        });
                    }
                    
                    // Abrir todas as seções por padrão
                    document.querySelectorAll('.registros-header').forEach(header => {
                        header.nextElementSibling.classList.add('show');
                    });
                </script>

            <?php elseif ($acao === 'novo' || $acao === 'editar'): ?>

                <div class="form-card">
                    <h2><?php echo $acao === 'novo' ? '➕ Novo Cliente' : '✏️ Editar Cliente'; ?></h2>
                    
                    <form method="POST">
                        <input type="hidden" name="acao" value="salvar">
                        <?php if ($acao === 'editar'): ?>
                        <input type="hidden" name="id" value="<?php echo $cliente['id']; ?>">
                        <?php endif; ?>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Nome *</label>
                                <input type="text" name="nome" value="<?php echo htmlspecialchars($cliente['nome']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Tipo de Pessoa *</label>
                                <select name="pessoa_tipo" required>
                                    <option value="fisica" <?php echo $cliente['pessoa_tipo'] == 'fisica' ? 'selected' : ''; ?>>Pessoa Física</option>
                                    <option value="juridica" <?php echo $cliente['pessoa_tipo'] == 'juridica' ? 'selected' : ''; ?>>Pessoa Jurídica</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>CPF/CNPJ *</label>
                                <input type="text" name="cpf_cnpj" value="<?php echo htmlspecialchars($cliente['cpf_cnpj']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Email</label>
                                <input type="email" name="email" value="<?php echo htmlspecialchars($cliente['email']); ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Telefone</label>
                                <input type="text" name="telefone" value="<?php echo htmlspecialchars($cliente['telefone']); ?>">
                            </div>
                            <div class="form-group">
                                <label>WhatsApp</label>
                                <input type="text" name="whatsapp" value="<?php echo htmlspecialchars($cliente['whatsapp']); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Rua *</label>
                            <input type="text" name="endereco_rua" value="<?php echo htmlspecialchars($cliente['endereco_rua']); ?>" required>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Número *</label>
                                <input type="text" name="endereco_numero" value="<?php echo htmlspecialchars($cliente['endereco_numero']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Bairro</label>
                                <input type="text" name="endereco_bairro" value="<?php echo htmlspecialchars($cliente['endereco_bairro']); ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Cidade *</label>
                                <input type="text" name="endereco_cidade" value="<?php echo htmlspecialchars($cliente['endereco_cidade']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Estado *</label>
                                <select name="endereco_estado" required>
                                    <option value="SP" <?php echo $cliente['endereco_estado'] == 'SP' ? 'selected' : ''; ?>>São Paulo (SP)</option>
                                    <option value="RJ" <?php echo $cliente['endereco_estado'] == 'RJ' ? 'selected' : ''; ?>>Rio de Janeiro (RJ)</option>
                                    <option value="MG" <?php echo $cliente['endereco_estado'] == 'MG' ? 'selected' : ''; ?>>Minas Gerais (MG)</option>
                                    <option value="RS" <?php echo $cliente['endereco_estado'] == 'RS' ? 'selected' : ''; ?>>Rio Grande do Sul (RS)</option>
                                    <option value="BA" <?php echo $cliente['endereco_estado'] == 'BA' ? 'selected' : ''; ?>>Bahia (BA)</option>
                                    <option value="SC" <?php echo $cliente['endereco_estado'] == 'SC' ? 'selected' : ''; ?>>Santa Catarina (SC)</option>
                                    <option value="PR" <?php echo $cliente['endereco_estado'] == 'PR' ? 'selected' : ''; ?>>Paraná (PR)</option>
                                    <option value="PE" <?php echo $cliente['endereco_estado'] == 'PE' ? 'selected' : ''; ?>>Pernambuco (PE)</option>
                                    <option value="CE" <?php echo $cliente['endereco_estado'] == 'CE' ? 'selected' : ''; ?>>Ceará (CE)</option>
                                    <option value="PA" <?php echo $cliente['endereco_estado'] == 'PA' ? 'selected' : ''; ?>>Pará (PA)</option>
                                    <option value="GO" <?php echo $cliente['endereco_estado'] == 'GO' ? 'selected' : ''; ?>>Goiás (GO)</option>
                                    <option value="PB" <?php echo $cliente['endereco_estado'] == 'PB' ? 'selected' : ''; ?>>Paraíba (PB)</option>
                                    <option value="MA" <?php echo $cliente['endereco_estado'] == 'MA' ? 'selected' : ''; ?>>Maranhão (MA)</option>
                                    <option value="ES" <?php echo $cliente['endereco_estado'] == 'ES' ? 'selected' : ''; ?>>Espírito Santo (ES)</option>
                                    <option value="PI" <?php echo $cliente['endereco_estado'] == 'PI' ? 'selected' : ''; ?>>Piauí (PI)</option>
                                    <option value="RN" <?php echo $cliente['endereco_estado'] == 'RN' ? 'selected' : ''; ?>>Rio Grande do Norte (RN)</option>
                                    <option value="AL" <?php echo $cliente['endereco_estado'] == 'AL' ? 'selected' : ''; ?>>Alagoas (AL)</option>
                                    <option value="MT" <?php echo $cliente['endereco_estado'] == 'MT' ? 'selected' : ''; ?>>Mato Grosso (MT)</option>
                                    <option value="MS" <?php echo $cliente['endereco_estado'] == 'MS' ? 'selected' : ''; ?>>Mato Grosso do Sul (MS)</option>
                                    <option value="DF" <?php echo $cliente['endereco_estado'] == 'DF' ? 'selected' : ''; ?>>Distrito Federal (DF)</option>
                                    <option value="TO" <?php echo $cliente['endereco_estado'] == 'TO' ? 'selected' : ''; ?>>Tocantins (TO)</option>
                                    <option value="AC" <?php echo $cliente['endereco_estado'] == 'AC' ? 'selected' : ''; ?>>Acre (AC)</option>
                                    <option value="AM" <?php echo $cliente['endereco_estado'] == 'AM' ? 'selected' : ''; ?>>Amazonas (AM)</option>
                                    <option value="AP" <?php echo $cliente['endereco_estado'] == 'AP' ? 'selected' : ''; ?>>Amapá (AP)</option>
                                    <option value="RR" <?php echo $cliente['endereco_estado'] == 'RR' ? 'selected' : ''; ?>>Roraima (RR)</option>
                                </select>
                            </div>
                        </div>

                        <div style="margin-top: 20px;">
                            <button type="submit" class="btn-salvar">✓ Salvar</button>
                            <a href="<?php echo BASE_URL; ?>/app/admin/clientes.php" class="btn-cancelar">✕ Cancelar</a>
                        </div>
                    </form>
                </div>

            <?php endif; ?>

        </main>
    </div>
</body>
</html>