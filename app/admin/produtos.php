<?php
/**
 * =====================================================================
 * GERENCIAMENTO DE PRODUTOS - VERSÃO COM UNIDADES DE MEDIDA
 * =====================================================================
 * 
 * Novas funcionalidades:
 * - Unidades de medida: UN, KG, MT, LT, CX, PC
 * - Controle por metro/kg para tubos de cobre
 * - Cálculo automático de preço por metro
 * - Conversão automática para tubos
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
$produtos = [];
$categorias = [];
$total_paginas = 0;
$pagina = 1;

// ===== CARREGAR CATEGORIAS =====
$sql_categorias = "SELECT * FROM categorias_produtos WHERE ativo = 1 ORDER BY nome ASC";
$resultado_categorias = $conexao->query($sql_categorias);
if ($resultado_categorias) {
    while ($linha = $resultado_categorias->fetch_assoc()) {
        $categorias[] = $linha;
    }
}

// ===== PROCESSAR AÇÕES POST =====

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao_post = isset($_POST['acao']) ? $_POST['acao'] : '';
    
    if ($acao_post === 'salvar') {
        // Salva novo produto ou atualiza
        $nome = isset($_POST['nome']) ? $conexao->real_escape_string(trim($_POST['nome'])) : '';
        $descricao = isset($_POST['descricao']) ? $conexao->real_escape_string(trim($_POST['descricao'])) : '';
        $categoria_id = isset($_POST['categoria_id']) ? intval($_POST['categoria_id']) : 0;
        
        // NOVOS CAMPOS DE UNIDADE DE MEDIDA
        $unidade_medida = isset($_POST['unidade_medida']) ? $_POST['unidade_medida'] : 'UN';
        $kg_por_metro = isset($_POST['kg_por_metro']) ? floatval(str_replace(',', '.', $_POST['kg_por_metro'])) : 0;
        $preco_por_kg = isset($_POST['preco_por_kg']) ? floatval(str_replace(',', '.', $_POST['preco_por_kg'])) : 0;
        
        // Remove formatação dos valores
        $valor_compra_str = isset($_POST['valor_compra']) ? $_POST['valor_compra'] : '0';
        $valor_compra_str = str_replace('.', '', $valor_compra_str);
        $valor_compra_str = str_replace(',', '.', $valor_compra_str);
        $valor_compra = floatval($valor_compra_str);
        
        $valor_venda_str = isset($_POST['valor_venda']) ? $_POST['valor_venda'] : '0';
        $valor_venda_str = str_replace('.', '', $valor_venda_str);
        $valor_venda_str = str_replace(',', '.', $valor_venda_str);
        $valor_venda = floatval($valor_venda_str);
        
        // Se for categoria de tubos e preço por kg foi informado, recalcular valor_venda
        $categoria_nome = '';
        foreach ($categorias as $cat) {
            if ($cat['id'] == $categoria_id) {
                $categoria_nome = strtolower($cat['nome']);
                break;
            }
        }
        
        // Auto-cálculo para tubos de cobre
        if (strpos($categoria_nome, 'tubo') !== false && $kg_por_metro > 0 && $preco_por_kg > 0) {
            // Preço por metro = kg_por_metro * preco_por_kg
            $preco_por_metro = $kg_por_metro * $preco_por_kg;
            $valor_venda = $preco_por_metro; // Preço por metro
            $descricao .= " | KG por metro: {$kg_por_metro}kg | Preço KG: R$ " . number_format($preco_por_kg, 2, ',', '.');
        }
        
        $estoque_atual = isset($_POST['estoque_atual']) ? intval($_POST['estoque_atual']) : 0;
        $estoque_minimo = isset($_POST['estoque_minimo']) ? intval($_POST['estoque_minimo']) : 0;
        $exibir_cliente = isset($_POST['exibir_cliente']) ? 1 : 0;
        
        $id_editar = isset($_POST['id']) && !empty($_POST['id']) ? intval($_POST['id']) : null;
        
        // Calcula margem de lucro
        if ($valor_compra > 0) {
            $margem_lucro = (($valor_venda - $valor_compra) / $valor_compra) * 100;
        } else {
            $margem_lucro = 0;
        }
        
        // Validação
        if (empty($nome)) {
            $erro = "Nome é obrigatório";
        } elseif ($valor_venda <= 0) {
            $erro = "Valor de venda é obrigatório e deve ser maior que zero";
        } else {
            if ($id_editar) {
                // ATUALIZA PRODUTO EXISTENTE
                $sql = "UPDATE produtos SET 
                        nome = ?,
                        descricao = ?,
                        categoria_id = ?,
                        valor_compra = ?,
                        valor_venda = ?,
                        margem_lucro = ?,
                        estoque_atual = ?,
                        estoque_minimo = ?,
                        exibir_cliente = ?,
                        unidade_medida = ?,
                        kg_por_metro = ?,
                        preco_por_kg = ?
                        WHERE id = ?";
                
                $stmt = $conexao->prepare($sql);
                
                if ($stmt) {
                    // Tipos: s=string, i=int, d=double
                    $stmt->bind_param(
                        "ssidddiiisddi",
                        $nome,
                        $descricao,
                        $categoria_id,
                        $valor_compra,
                        $valor_venda,
                        $margem_lucro,
                        $estoque_atual,
                        $estoque_minimo,
                        $exibir_cliente,
                        $unidade_medida,
                        $kg_por_metro,
                        $preco_por_kg,
                        $id_editar
                    );
                    
                    if ($stmt->execute()) {
                        $stmt->close();
                        header('Location: ' . BASE_URL . '/app/admin/produtos.php?mensagem=atualizado');
                        exit;
                    } else {
                        $erro = "Erro ao atualizar: " . $stmt->error;
                        $stmt->close();
                    }
                } else {
                    $erro = "Erro na preparação da query: " . $conexao->error;
                }
            } else {
                // INSERE NOVO PRODUTO
                $sql = "INSERT INTO produtos (
                        nome, descricao, categoria_id, valor_compra, valor_venda, margem_lucro,
                        estoque_atual, estoque_minimo, exibir_cliente, ativo,
                        unidade_medida, kg_por_metro, preco_por_kg
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)";
                
                $stmt = $conexao->prepare($sql);
                
                if ($stmt) {
                    $stmt->bind_param(
                        "ssidddiiisdd",
                        $nome,
                        $descricao,
                        $categoria_id,
                        $valor_compra,
                        $valor_venda,
                        $margem_lucro,
                        $estoque_atual,
                        $estoque_minimo,
                        $exibir_cliente,
                        $unidade_medida,
                        $kg_por_metro,
                        $preco_por_kg
                    );
                    
                    if ($stmt->execute()) {
                        $stmt->close();
                        header('Location: ' . BASE_URL . '/app/admin/produtos.php?mensagem=criado');
                        exit;
                    } else {
                        $erro = "Erro ao criar: " . $stmt->error;
                        $stmt->close();
                    }
                } else {
                    $erro = "Erro na preparação da query: " . $conexao->error;
                }
            }
        }
        
        // Se houve erro, mantém na tela de formulário
        if (!empty($erro)) {
            $acao = $id_editar ? 'editar' : 'novo';
            $produto = [
                'id' => $id_editar,
                'nome' => $nome,
                'descricao' => $descricao,
                'categoria_id' => $categoria_id,
                'valor_compra' => $valor_compra,
                'valor_venda' => $valor_venda,
                'estoque_atual' => $estoque_atual,
                'estoque_minimo' => $estoque_minimo,
                'exibir_cliente' => $exibir_cliente,
                'ativo' => 1,
                'unidade_medida' => $unidade_medida,
                'kg_por_metro' => $kg_por_metro,
                'preco_por_kg' => $preco_por_kg
            ];
        }
    } elseif ($acao_post === 'salvar_categoria') {
        // ===== PROCESSAR SALVAR CATEGORIA =====
        $nome_cat = isset($_POST['nome_categoria']) ? $conexao->real_escape_string(trim($_POST['nome_categoria'])) : '';
        $descricao_cat = isset($_POST['descricao_categoria']) ? $conexao->real_escape_string(trim($_POST['descricao_categoria'])) : '';
        $id_cat_editar = isset($_POST['id_categoria']) && !empty($_POST['id_categoria']) ? intval($_POST['id_categoria']) : null;
        
        if (empty($nome_cat)) {
            $erro = "Nome da categoria é obrigatório";
        } else {
            if ($id_cat_editar) {
                // Atualiza categoria
                $sql = "UPDATE categorias_produtos SET nome = ?, descricao = ? WHERE id = ?";
                $stmt = $conexao->prepare($sql);
                
                if ($stmt) {
                    $stmt->bind_param("ssi", $nome_cat, $descricao_cat, $id_cat_editar);
                    
                    if ($stmt->execute()) {
                        $stmt->close();
                        header('Location: ' . BASE_URL . '/app/admin/produtos.php?acao=novo_categoria&mensagem=categoria_atualizada');
                        exit;
                    } else {
                        $erro = "Erro ao atualizar categoria: " . $stmt->error;
                        $stmt->close();
                    }
                }
            } else {
                // Insere nova categoria
                $sql = "INSERT INTO categorias_produtos (nome, descricao, ativo) VALUES (?, ?, 1)";
                $stmt = $conexao->prepare($sql);
                
                if ($stmt) {
                    $stmt->bind_param("ss", $nome_cat, $descricao_cat);
                    
                    if ($stmt->execute()) {
                        $stmt->close();
                        header('Location: ' . BASE_URL . '/app/admin/produtos.php?acao=novo_categoria&mensagem=categoria_criada');
                        exit;
                    } else {
                        $erro = "Erro ao criar categoria: " . $stmt->error;
                        $stmt->close();
                    }
                }
            }
        }
    }
}

// ===== PROCESSAR DELETAR CATEGORIA =====

if ($acao === 'deletar_categoria' && $id) {
    // Verificar se existem produtos usando esta categoria
    $check_sql = "SELECT COUNT(*) as total FROM produtos WHERE categoria_id = ?";
    $check_stmt = $conexao->prepare($check_sql);
    $check_stmt->bind_param("i", $id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $total_produtos = $check_result->fetch_assoc()['total'];
    $check_stmt->close();
    
    if ($total_produtos > 0) {
        $erro = "Não é possível deletar esta categoria pois existem $total_produtos produto(s) vinculados a ela.";
    } else {
        $sql = "DELETE FROM categorias_produtos WHERE id = ?";
        $stmt = $conexao->prepare($sql);
        
        if ($stmt) {
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                $stmt->close();
                header('Location: ' . BASE_URL . '/app/admin/produtos.php?acao=novo_categoria&mensagem=categoria_deletada');
                exit;
            }
            $stmt->close();
        }
    }
}

// ===== PROCESSAR EDITAR CATEGORIA =====

if ($acao === 'editar_categoria' && $id) {
    $sql = "SELECT * FROM categorias_produtos WHERE id = ?";
    $stmt = $conexao->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $categoria_edit = $resultado->fetch_assoc();
        $stmt->close();
        
        if (!$categoria_edit) {
            $erro = "Categoria não encontrada";
            $acao = 'novo_categoria';
        }
    }
}

// ===== PROCESSAR DELETAR PRODUTO =====

if ($acao === 'deletar' && $id) {
    $sql = "DELETE FROM produtos WHERE id = ?";
    $stmt = $conexao->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            $stmt->close();
            header('Location: ' . BASE_URL . '/app/admin/produtos.php?mensagem=deletado');
            exit;
        }
        $stmt->close();
    }
}

// ===== LISTAR PRODUTOS =====
if ($acao === 'listar' || $acao === 'deletar') {
    $pagina = isset($_GET['pagina']) ? intval($_GET['pagina']) : 1;
    $limite = 50;
    $offset = ($pagina - 1) * $limite;
    
    $sql = "SELECT p.*, c.nome as categoria_nome FROM produtos p 
            LEFT JOIN categorias_produtos c ON p.categoria_id = c.id 
            ORDER BY p.nome ASC LIMIT {$limite} OFFSET {$offset}";
    $resultado = $conexao->query($sql);
    
    if ($resultado) {
        while ($linha = $resultado->fetch_assoc()) {
            $produtos[] = $linha;
        }
    }
    
    $sql_total = "SELECT COUNT(*) as total FROM produtos";
    $resultado_total = $conexao->query($sql_total);
    
    if ($resultado_total) {
        $total = $resultado_total->fetch_assoc()['total'];
        $total_paginas = ceil($total / $limite);
    }
    
    // Verifica se teve mensagem de sucesso
    if (isset($_GET['mensagem'])) {
        if ($_GET['mensagem'] === 'criado') {
            $mensagem = "✓ Produto criado com sucesso!";
        } elseif ($_GET['mensagem'] === 'atualizado') {
            $mensagem = "✓ Produto atualizado com sucesso!";
        } elseif ($_GET['mensagem'] === 'deletado') {
            $mensagem = "✓ Produto deletado com sucesso!";
        }
    }
    
} elseif ($acao === 'novo') {
    // Mostra formulário de novo produto
    $produto = [
        'id' => '', 
        'nome' => '', 
        'descricao' => '', 
        'categoria_id' => '', 
        'valor_compra' => 0, 
        'valor_venda' => 0, 
        'estoque_atual' => 0, 
        'estoque_minimo' => 0, 
        'exibir_cliente' => 1,
        'ativo' => 1,
        'unidade_medida' => 'UN',
        'kg_por_metro' => 0,
        'preco_por_kg' => 0
    ];
    
} elseif ($acao === 'novo_categoria') {
    // Recarrega categorias para exibição
    $categorias = [];
    $sql_categorias = "SELECT * FROM categorias_produtos WHERE ativo = 1 ORDER BY nome ASC";
    $resultado_categorias = $conexao->query($sql_categorias);
    if ($resultado_categorias) {
        while ($linha = $resultado_categorias->fetch_assoc()) {
            $categorias[] = $linha;
        }
    }
    
    // Verifica se teve mensagem de sucesso
    if (isset($_GET['mensagem'])) {
        if ($_GET['mensagem'] === 'categoria_criada') {
            $mensagem = "✓ Categoria criada com sucesso!";
        } elseif ($_GET['mensagem'] === 'categoria_atualizada') {
            $mensagem = "✓ Categoria atualizada com sucesso!";
        } elseif ($_GET['mensagem'] === 'categoria_deletada') {
            $mensagem = "✓ Categoria deletada com sucesso!";
        }
    }
    
} elseif ($acao === 'editar' && $id) {
    // Carrega produto para editar
    $sql = "SELECT * FROM produtos WHERE id = ?";
    $stmt = $conexao->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $produto = $resultado->fetch_assoc();
        $stmt->close();
        
        if (!$produto) {
            header('Location: ' . BASE_URL . '/app/admin/produtos.php');
            exit;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Produtos - Império AR</title>
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
        
        .btn-group {
            display: flex;
            gap: 10px;
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
        
        .btn-categoria {
            background: #28a745;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-categoria:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.4);
        }
        
        .btn-voltar {
            background: #6c757d;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-voltar:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(108, 117, 125, 0.4);
        }
        
        .form-group {
            margin-bottom: 15px;
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
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 5px rgba(102, 126, 234, 0.3);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .form-row-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
        }
        
        .form-row-4 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr;
            gap: 15px;
        }
        
        .form-group-checkbox {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .form-group-checkbox input {
            width: auto;
            margin: 0;
        }
        
        .btn-salvar {
            background: #28a745;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
        }
        
        .btn-salvar:hover {
            background: #218838;
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
        
        .estoque-critico {
            background: #fff3cd;
            color: #856404;
        }
        
        .info-box {
            background: #e7f3ff;
            border-left: 4px solid #2196F3;
            padding: 10px 15px;
            margin-bottom: 15px;
            font-size: 13px;
            border-radius: 0 4px 4px 0;
        }
        
        .info-box strong {
            color: #0b5e9e;
        }
        
        .campo-tubo {
            background: #f0f8ff;
            padding: 15px;
            border-radius: 4px;
            margin: 15px 0;
            border: 1px solid #b8daff;
        }
        
        .campo-tubo label {
            color: #004085;
        }
        
        .badge-unidade {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
            background: #e2e3e5;
            color: #383d41;
        }
        
        .badge-unidade.UN { background: #cce5ff; color: #004085; }
        .badge-unidade.KG { background: #d4edda; color: #155724; }
        .badge-unidade.MT { background: #fff3cd; color: #856404; }
        .badge-unidade.LT { background: #d1ecf1; color: #0c5460; }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- INCLUIR SIDEBAR -->
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <main class="main-content">
            
            <?php if ($mensagem): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($mensagem); ?></div>
            <?php endif; ?>

            <?php if ($erro): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($erro); ?></div>
            <?php endif; ?>

            <?php if ($acao === 'listar'): ?>
                
                <div class="page-header">
                    <h1>📦 Gerenciamento de Produtos</h1>
                    <div class="btn-group">
                        <a href="<?php echo BASE_URL; ?>/app/admin/produtos.php?acao=novo_categoria" class="btn-categoria">
                            ➕ Gerenciar Categorias
                        </a>
                        <a href="<?php echo BASE_URL; ?>/app/admin/produtos.php?acao=novo" class="btn-novo">
                            ➕ Novo Produto
                        </a>
                    </div>
                </div>

                <!-- LISTAGEM DE PRODUTOS -->
                <?php if (count($produtos) > 0): ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Categoria</th>
                            <th>Unidade</th>
                            <th>Valor Compra</th>
                            <th>Valor Venda</th>
                            <th>Margem</th>
                            <th>Estoque</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($produtos as $prod): 
                            $unidade_class = '';
                            switch($prod['unidade_medida'] ?? 'UN') {
                                case 'UN': $unidade_class = 'UN'; break;
                                case 'KG': $unidade_class = 'KG'; break;
                                case 'MT': $unidade_class = 'MT'; break;
                                case 'LT': $unidade_class = 'LT'; break;
                                default: $unidade_class = '';
                            }
                        ?>
                        <tr class="<?php echo ($prod['estoque_atual'] < $prod['estoque_minimo']) ? 'estoque-critico' : ''; ?>">
                            <td><?php echo htmlspecialchars($prod['nome']); ?></td>
                            <td><?php echo htmlspecialchars($prod['categoria_nome'] ?? 'Sem categoria'); ?></td>
                            <td>
                                <span class="badge-unidade <?php echo $unidade_class; ?>">
                                    <?php 
                                    $unidades = ['UN' => 'Unidade', 'KG' => 'Quilo', 'MT' => 'Metro', 'LT' => 'Litro', 'CX' => 'Caixa', 'PC' => 'Peça'];
                                    echo $unidades[$prod['unidade_medida'] ?? 'UN'] ?? $prod['unidade_medida'] ?? 'UN';
                                    ?>
                                </span>
                                <?php if (!empty($prod['kg_por_metro']) && $prod['kg_por_metro'] > 0): ?>
                                    <br><small><?php echo $prod['kg_por_metro']; ?> kg/m</small>
                                <?php endif; ?>
                            </td>
                            <td>R$ <?php echo number_format($prod['valor_compra'], 2, ',', '.'); ?></td>
                            <td>R$ <?php echo number_format($prod['valor_venda'], 2, ',', '.'); ?></td>
                            <td><?php echo number_format($prod['margem_lucro'], 2, ',', '.'); ?>%</td>
                            <td>
                                <?php if ($prod['estoque_atual'] < $prod['estoque_minimo']): ?>
                                    <strong style="color: #dc3545;">⚠️ <?php echo $prod['estoque_atual']; ?></strong>
                                <?php else: ?>
                                    <?php echo $prod['estoque_atual']; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?php echo BASE_URL; ?>/app/admin/produtos.php?acao=editar&id=<?php echo $prod['id']; ?>" class="btn-editar">✏️ Editar</a>
                                <a href="<?php echo BASE_URL; ?>/app/admin/produtos.php?acao=deletar&id=<?php echo $prod['id']; ?>" class="btn-deletar" onclick="return confirm('Tem certeza que deseja deletar este produto?')">🗑️ Deletar</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- PAGINAÇÃO -->
                <?php if ($total_paginas > 1): ?>
                <div style="margin-top: 20px; text-align: center;">
                    <?php for ($p = 1; $p <= $total_paginas; $p++): ?>
                    <a href="<?php echo BASE_URL; ?>/app/admin/produtos.php?pagina=<?php echo $p; ?>" 
                       style="padding: 8px 12px; margin: 0 5px; background: <?php echo $p == $pagina ? '#667eea' : '#ddd'; ?>; color: <?php echo $p == $pagina ? 'white' : '#333'; ?>; text-decoration: none; border-radius: 3px; display: inline-block;">
                        <?php echo $p; ?>
                    </a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
                
                <?php else: ?>
                <div class="empty-message">
                    <p>Nenhum produto cadastrado.</p>
                    <a href="<?php echo BASE_URL; ?>/app/admin/produtos.php?acao=novo" class="btn-novo" style="margin-top: 15px;">
                        ➕ Criar Novo Produto
                    </a>
                </div>
                <?php endif; ?>

            <?php elseif ($acao === 'novo_categoria'): ?>

                <!-- GERENCIAR CATEGORIAS -->
                <div class="page-header">
                    <h1>🏷️ Gerenciar Categorias</h1>
                    <a href="<?php echo BASE_URL; ?>/app/admin/produtos.php" class="btn-voltar">
                        ← Voltar para Produtos
                    </a>
                </div>

                <!-- FORMULÁRIO NOVA CATEGORIA -->
                <div class="form-card">
                    <h2>➕ Nova Categoria</h2>
                    
                    <form method="POST">
                        <input type="hidden" name="acao" value="salvar_categoria">
                        
                        <div class="form-group">
                            <label>Nome da Categoria *</label>
                            <input type="text" name="nome_categoria" placeholder="Ex: Tubos de cobre, Elétrica, Compressores..." required>
                        </div>

                        <div class="form-group">
                            <label>Descrição</label>
                            <textarea name="descricao_categoria" placeholder="Descrição da categoria (opcional)" rows="3"></textarea>
                        </div>

                        <div style="margin-top: 20px;">
                            <button type="submit" class="btn-salvar">✓ Criar Categoria</button>
                        </div>
                    </form>
                </div>

                <!-- LISTA DE CATEGORIAS -->
                <div class="form-card">
                    <h2>📋 Categorias Existentes</h2>
                    
                    <?php if (count($categorias) > 0): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Descrição</th>
                                <th>Produtos</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categorias as $cat): 
                                // Contar produtos nesta categoria
                                $count_sql = "SELECT COUNT(*) as total FROM produtos WHERE categoria_id = " . $cat['id'];
                                $count_result = $conexao->query($count_sql);
                                $total_produtos_cat = $count_result->fetch_assoc()['total'];
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($cat['nome']); ?></strong></td>
                                <td><?php echo htmlspecialchars($cat['descricao'] ?? ''); ?></td>
                                <td><?php echo $total_produtos_cat; ?> produto(s)</td>
                                <td>
                                    <a href="<?php echo BASE_URL; ?>/app/admin/produtos.php?acao=editar_categoria&id=<?php echo $cat['id']; ?>" class="btn-editar">✏️ Editar</a>
                                    <?php if ($total_produtos_cat == 0): ?>
                                    <a href="<?php echo BASE_URL; ?>/app/admin/produtos.php?acao=deletar_categoria&id=<?php echo $cat['id']; ?>" class="btn-deletar" onclick="return confirm('Tem certeza que deseja deletar esta categoria?')">🗑️ Deletar</a>
                                    <?php else: ?>
                                    <span class="btn-deletar" style="opacity: 0.5; cursor: not-allowed;" title="Não é possível deletar categoria com produtos vinculados">🗑️ Deletar</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <p style="text-align: center; color: #666; padding: 30px;">Nenhuma categoria cadastrada ainda.</p>
                    <?php endif; ?>
                </div>

            <?php elseif ($acao === 'editar_categoria' && isset($categoria_edit)): ?>

                <!-- EDITAR CATEGORIA -->
                <div class="page-header">
                    <h1>✏️ Editar Categoria</h1>
                    <a href="<?php echo BASE_URL; ?>/app/admin/produtos.php?acao=novo_categoria" class="btn-voltar">
                        ← Voltar para Categorias
                    </a>
                </div>

                <div class="form-card">
                    <form method="POST">
                        <input type="hidden" name="acao" value="salvar_categoria">
                        <input type="hidden" name="id_categoria" value="<?php echo isset($categoria_edit['id']) ? $categoria_edit['id'] : ''; ?>">
                        
                        <div class="form-group">
                            <label>Nome da Categoria *</label>
                            <input type="text" name="nome_categoria" value="<?php echo isset($categoria_edit['nome']) ? htmlspecialchars($categoria_edit['nome']) : ''; ?>" placeholder="Ex: Tubos de cobre, Elétrica, Compressores..." required>
                        </div>

                        <div class="form-group">
                            <label>Descrição</label>
                            <textarea name="descricao_categoria" placeholder="Descrição da categoria (opcional)" rows="3"><?php echo isset($categoria_edit['descricao']) ? htmlspecialchars($categoria_edit['descricao']) : ''; ?></textarea>
                        </div>

                        <div style="margin-top: 20px;">
                            <button type="submit" class="btn-salvar">✓ Atualizar Categoria</button>
                            <a href="<?php echo BASE_URL; ?>/app/admin/produtos.php?acao=novo_categoria" class="btn-cancelar">✕ Cancelar</a>
                        </div>
                    </form>
                </div>

            <?php else: ?>

                <!-- FORMULÁRIO NOVO/EDITAR PRODUTO COM UNIDADES DE MEDIDA -->
                <div class="page-header">
                    <h1><?php echo $acao === 'novo' ? '➕ Novo Produto' : '✏️ Editar Produto'; ?></h1>
                    <a href="<?php echo BASE_URL; ?>/app/admin/produtos.php" class="btn-voltar">
                        ← Voltar para Produtos
                    </a>
                </div>

                <div class="form-card">
                    <div class="info-box">
                        <strong>ℹ️ Dica:</strong> Para produtos da categoria <strong>Tubos de cobre</strong>, preencha os campos "KG por metro" e "Preço por KG" que o valor de venda será calculado automaticamente.
                    </div>
                    
                    <form method="POST" action="<?php echo BASE_URL; ?>/app/admin/produtos.php">
                        <input type="hidden" name="acao" value="salvar">
                        
                        <?php if ($acao === 'editar' && isset($produto['id'])): ?>
                        <input type="hidden" name="id" value="<?php echo $produto['id']; ?>">
                        <?php endif; ?>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Nome do Produto *</label>
                                <input type="text" name="nome" value="<?php echo isset($produto['nome']) ? htmlspecialchars($produto['nome']) : ''; ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Categoria *</label>
                                <select name="categoria_id" id="categoria_id" required onchange="verificarCategoriaTubo()">
                                    <option value="">-- Selecione uma categoria --</option>
                                    <?php foreach ($categorias as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>" 
                                            data-nome="<?php echo strtolower($cat['nome']); ?>"
                                            <?php echo isset($produto['categoria_id']) && $produto['categoria_id'] == $cat['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat['nome']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Descrição</label>
                            <textarea name="descricao" rows="3" placeholder="Descrição detalhada do produto"><?php echo isset($produto['descricao']) ? htmlspecialchars($produto['descricao']) : ''; ?></textarea>
                        </div>

                        <div class="form-row-4">
                            <div class="form-group">
                                <label>Unidade de Medida *</label>
                                <select name="unidade_medida" id="unidade_medida" required>
                                    <option value="UN" <?php echo (isset($produto['unidade_medida']) ? $produto['unidade_medida'] : 'UN') == 'UN' ? 'selected' : ''; ?>>Unidade (UN)</option>
                                    <option value="KG" <?php echo (isset($produto['unidade_medida']) ? $produto['unidade_medida'] : '') == 'KG' ? 'selected' : ''; ?>>Quilograma (KG)</option>
                                    <option value="MT" <?php echo (isset($produto['unidade_medida']) ? $produto['unidade_medida'] : '') == 'MT' ? 'selected' : ''; ?>>Metro (MT)</option>
                                    <option value="LT" <?php echo (isset($produto['unidade_medida']) ? $produto['unidade_medida'] : '') == 'LT' ? 'selected' : ''; ?>>Litro (LT)</option>
                                    <option value="CX" <?php echo (isset($produto['unidade_medida']) ? $produto['unidade_medida'] : '') == 'CX' ? 'selected' : ''; ?>>Caixa (CX)</option>
                                    <option value="PC" <?php echo (isset($produto['unidade_medida']) ? $produto['unidade_medida'] : '') == 'PC' ? 'selected' : ''; ?>>Peça (PC)</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Valor de Compra (R$)</label>
                                <input type="text" id="valor_compra" name="valor_compra" value="<?php echo isset($produto['valor_compra']) && $produto['valor_compra'] > 0 ? number_format($produto['valor_compra'], 2, ',', '.') : '0,00'; ?>" placeholder="0,00">
                            </div>
                            
                            <div class="form-group">
                                <label>Valor de Venda (R$) *</label>
                                <input type="text" id="valor_venda" name="valor_venda" value="<?php echo isset($produto['valor_venda']) && $produto['valor_venda'] > 0 ? number_format($produto['valor_venda'], 2, ',', '.') : '0,00'; ?>" placeholder="0,00" required>
                            </div>

                            <div class="form-group">
                                <label>Margem de Lucro (%)</label>
                                <input type="text" id="margem" value="<?php echo isset($produto['margem_lucro']) ? number_format($produto['margem_lucro'], 2, ',', '.') : '0,00'; ?>" readonly style="background: #f5f5f5;">
                            </div>
                        </div>

                        <!-- CAMPOS ESPECÍFICOS PARA TUBOS (mostrar apenas quando categoria for Tubos) -->
                        <div id="campos_tubo" class="campo-tubo" style="display: none;">
                            <h4 style="margin-top: 0; color: #004085;">📏 Configurações para Tubos</h4>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>KG por Metro</label>
                                    <input type="text" id="kg_por_metro" name="kg_por_metro" value="<?php echo isset($produto['kg_por_metro']) && $produto['kg_por_metro'] > 0 ? number_format($produto['kg_por_metro'], 3, ',', '.') : '0,000'; ?>" placeholder="0,000" onchange="calcularPrecoTubo()">
                                    <small style="color: #666;">Ex: 0.150 kg por metro</small>
                                </div>
                                <div class="form-group">
                                    <label>Preço por KG (R$)</label>
                                    <input type="text" id="preco_por_kg" name="preco_por_kg" value="<?php echo isset($produto['preco_por_kg']) && $produto['preco_por_kg'] > 0 ? number_format($produto['preco_por_kg'], 2, ',', '.') : '0,00'; ?>" placeholder="0,00" onchange="calcularPrecoTubo()">
                                    <small style="color: #666;">Preço do quilo do cobre</small>
                                </div>
                                <div class="form-group">
                                    <label>Preço por Metro (calculado)</label>
                                    <input type="text" id="preco_por_metro" readonly style="background: #e9ecef;" value="R$ 0,00">
                                </div>
                            </div>
                            <p style="margin: 5px 0 0; font-size: 12px; color: #28a745;">
                                <i class="fas fa-calculator"></i> O valor de venda será atualizado automaticamente com base no preço por metro
                            </p>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Estoque Atual</label>
                                <input type="number" name="estoque_atual" value="<?php echo isset($produto['estoque_atual']) ? $produto['estoque_atual'] : 0; ?>" min="0">
                            </div>
                            
                            <div class="form-group">
                                <label>Estoque Mínimo</label>
                                <input type="number" name="estoque_minimo" value="<?php echo isset($produto['estoque_minimo']) ? $produto['estoque_minimo'] : 0; ?>" min="0">
                            </div>
                        </div>

                        <div class="form-group-checkbox">
                            <input type="checkbox" id="exibir_cliente" name="exibir_cliente" value="1" <?php echo isset($produto['exibir_cliente']) && $produto['exibir_cliente'] ? 'checked' : ''; ?>>
                            <label for="exibir_cliente" style="margin: 0;">Exibir para clientes no portal</label>
                        </div>

                        <div style="margin-top: 20px;">
                            <button type="submit" class="btn-salvar">✓ Salvar Produto</button>
                            <a href="<?php echo BASE_URL; ?>/app/admin/produtos.php" class="btn-cancelar">✕ Cancelar</a>
                        </div>
                    </form>
                </div>

            <?php endif; ?>

        </main>
    </div>

    <script>
        function formatarMoeda(input) {
            let valor = input.value.replace(/\D/g, '');
            
            if (valor.length === 0) {
                input.value = '';
                return;
            }
            
            valor = (parseInt(valor) / 100).toLocaleString('pt-BR', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
            
            input.value = valor;
        }

        function formatarNumero(input, casas = 3) {
            let valor = input.value.replace(/\D/g, '');
            
            if (valor.length === 0) {
                input.value = '0,' + '0'.repeat(casas);
                return;
            }
            
            // Garantir que tenha o número correto de casas decimais
            while (valor.length <= casas) {
                valor = '0' + valor;
            }
            
            let inteiro = valor.slice(0, -casas);
            let decimal = valor.slice(-casas);
            
            // Remover zeros à esquerda do inteiro, mas manter pelo menos um zero
            inteiro = inteiro.replace(/^0+/, '');
            if (inteiro === '') inteiro = '0';
            
            input.value = inteiro + ',' + decimal;
        }

        function calcularMargem() {
            const compra = document.getElementById('valor_compra').value;
            const venda = document.getElementById('valor_venda').value;
            const margem = document.getElementById('margem');
            
            let valorCompra = parseFloat(compra.replace(/[^\d,]/g, '').replace(',', '.')) || 0;
            let valorVenda = parseFloat(venda.replace(/[^\d,]/g, '').replace(',', '.')) || 0;
            
            let percentual = 0;
            if (valorCompra > 0 && valorVenda > 0) {
                percentual = ((valorVenda - valorCompra) / valorCompra) * 100;
            }
            
            margem.value = percentual.toFixed(2).replace('.', ',');
        }

        function calcularPrecoTubo() {
            const kgPorMetroStr = document.getElementById('kg_por_metro').value;
            const precoPorKgStr = document.getElementById('preco_por_kg').value;
            
            let kgPorMetro = parseFloat(kgPorMetroStr.replace(/[^\d,]/g, '').replace(',', '.')) || 0;
            let precoPorKg = parseFloat(precoPorKgStr.replace(/[^\d,]/g, '').replace(',', '.')) || 0;
            
            if (kgPorMetro > 0 && precoPorKg > 0) {
                let precoPorMetro = kgPorMetro * precoPorKg;
                
                // Atualizar campo de preço por metro
                document.getElementById('preco_por_metro').value = 'R$ ' + precoPorMetro.toFixed(2).replace('.', ',');
                
                // Atualizar valor de venda
                document.getElementById('valor_venda').value = precoPorMetro.toFixed(2).replace('.', ',');
                
                // Recalcular margem
                calcularMargem();
            }
        }

        function verificarCategoriaTubo() {
            const select = document.getElementById('categoria_id');
            const selectedOption = select.options[select.selectedIndex];
            const nomeCategoria = selectedOption.getAttribute('data-nome') || '';
            const camposTubo = document.getElementById('campos_tubo');
            
            // Verificar se a categoria contém "tubo" no nome
            if (nomeCategoria.includes('tubo')) {
                camposTubo.style.display = 'block';
            } else {
                camposTubo.style.display = 'none';
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const inputCompra = document.getElementById('valor_compra');
            const inputVenda = document.getElementById('valor_venda');
            const inputKgMetro = document.getElementById('kg_por_metro');
            const inputPrecoKg = document.getElementById('preco_por_kg');
            
            if (inputCompra) {
                inputCompra.addEventListener('input', function() {
                    formatarMoeda(this);
                    calcularMargem();
                });
            }
            
            if (inputVenda) {
                inputVenda.addEventListener('input', function() {
                    formatarMoeda(this);
                    calcularMargem();
                });
            }
            
            if (inputKgMetro) {
                inputKgMetro.addEventListener('input', function() {
                    formatarNumero(this, 3);
                    calcularPrecoTubo();
                });
            }
            
            if (inputPrecoKg) {
                inputPrecoKg.addEventListener('input', function() {
                    formatarMoeda(this);
                    calcularPrecoTubo();
                });
            }
            
            // Verificar categoria ao carregar a página
            verificarCategoriaTubo();
            
            // Se já existirem valores para tubo, calcular preço
            if (inputKgMetro && inputPrecoKg && inputKgMetro.value !== '0,000' && inputPrecoKg.value !== '0,00') {
                calcularPrecoTubo();
            }
            
            calcularMargem();
        });
    </script>

    <script src="<?php echo BASE_URL; ?>/public/js/main.js"></script>
</body>
</html>
