<?php
/**
 * =====================================================================
 * GERENCIAMENTO DE SERVIÇOS - VERSÃO CORRIGIDA E OTIMIZADA
 * =====================================================================
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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

// Verifica conexão
if (!$conexao) {
    die("Erro de conexão com banco de dados: " . mysqli_connect_error());
}

$acao = $_GET['acao'] ?? 'listar';
$id = isset($_GET['id']) ? intval($_GET['id']) : null;
$mensagem = '';
$erro = '';
$servicos = [];
$categorias = [];
$produtos = [];
$total_paginas = 0;
$pagina = 1;
$servico = [];
$categoria_edit = [];

// ===== VERIFICAR ESTRUTURA DO BANCO =====
$colunas_servicos = [];
$sql_colunas = "SHOW COLUMNS FROM servicos";
$result_colunas = $conexao->query($sql_colunas);
if ($result_colunas) {
    while ($row = $result_colunas->fetch_assoc()) {
        $colunas_servicos[$row['Field']] = true;
    }
}

// ===== CARREGAR CATEGORIAS DE SERVIÇOS =====
$sql_categorias = "SELECT * FROM categorias_servicos WHERE ativo = 1 ORDER BY nome ASC";
$resultado_categorias = $conexao->query($sql_categorias);
if ($resultado_categorias) {
    while ($linha = $resultado_categorias->fetch_assoc()) {
        $categorias[] = $linha;
    }
}

// ===== CARREGAR PRODUTOS (MATERIAIS) =====
$sql_produtos = "SELECT id, nome, valor_compra FROM produtos WHERE ativo = 1 ORDER BY nome ASC";
$resultado_produtos = $conexao->query($sql_produtos);
if ($resultado_produtos) {
    while ($linha = $resultado_produtos->fetch_assoc()) {
        $produtos[] = $linha;
    }
}

// ===== PROCESSAR AÇÕES POST =====

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao_post = isset($_POST['acao']) ? $_POST['acao'] : '';
    
    if ($acao_post === 'salvar') {
        // Salva novo serviço ou atualiza
        $nome = isset($_POST['nome']) ? $conexao->real_escape_string(trim($_POST['nome'])) : '';
        $descricao = isset($_POST['descricao']) ? $conexao->real_escape_string(trim($_POST['descricao'])) : '';
        $categoria_id = isset($_POST['categoria_id']) ? intval($_POST['categoria_id']) : 0;
        
        // Valor de venda
        $valor_venda_str = isset($_POST['valor_venda']) ? $_POST['valor_venda'] : '0';
        $valor_venda_str = str_replace('.', '', $valor_venda_str);
        $valor_venda_str = str_replace(',', '.', $valor_venda_str);
        $valor_venda = floatval($valor_venda_str);
        
        // Tempo de execução
        $tempo_execucao = isset($_POST['tempo_execucao']) ? intval($_POST['tempo_execucao']) : 0;
        $exibir_cliente = isset($_POST['exibir_cliente']) ? 1 : 0;
        
        // Processar materiais
        $materiais = [];
        if (isset($_POST['materiais']) && isset($_POST['quantidades'])) {
            foreach ($_POST['materiais'] as $index => $mat_id) {
                if (!empty($mat_id) && isset($_POST['quantidades'][$index]) && $_POST['quantidades'][$index] > 0) {
                    $materiais[intval($mat_id)] = intval($_POST['quantidades'][$index]);
                }
            }
        }
        
        $id_editar = isset($_POST['id']) && !empty($_POST['id']) ? intval($_POST['id']) : null;
        
        // Calcula valor de custo
        $valor_custo = 0;
        if (!empty($materiais)) {
            $ids_materiais = implode(',', array_keys($materiais));
            $sql_mat = "SELECT id, valor_compra FROM produtos WHERE id IN ($ids_materiais)";
            $result_mat = $conexao->query($sql_mat);
            if ($result_mat) {
                while ($row = $result_mat->fetch_assoc()) {
                    $valor_custo += floatval($row['valor_compra']) * $materiais[$row['id']];
                }
            }
        }
        
        // Calcula lucro
        $lucro = $valor_venda - $valor_custo;
        
        // Validação
        if (empty($nome)) {
            $erro = "Nome do serviço é obrigatório";
        } elseif ($valor_venda <= 0) {
            $erro = "Valor de venda é obrigatório e deve ser maior que zero";
        } else {
            // Verifica se a categoria existe (se foi selecionada)
            if ($categoria_id > 0) {
                $sql_check_cat = "SELECT id FROM categorias_servicos WHERE id = ?";
                $stmt_check = $conexao->prepare($sql_check_cat);
                $stmt_check->bind_param("i", $categoria_id);
                $stmt_check->execute();
                $result_check = $stmt_check->get_result();
                $categoria_existe = $result_check->num_rows > 0;
                $stmt_check->close();
                
                if (!$categoria_existe) {
                    $categoria_id = 0; // Reseta para 0 se a categoria não existir
                }
            }
            
            if ($id_editar) {
                // ===== ATUALIZA SERVIÇO EXISTENTE =====
                $campos_update = [];
                $tipos = "";
                $params = [];
                
                $campos_update[] = "nome = ?";
                $tipos .= "s";
                $params[] = $nome;
                
                $campos_update[] = "descricao = ?";
                $tipos .= "s";
                $params[] = $descricao;
                
                // Só adiciona categoria se for > 0, senão coloca NULL
                if ($categoria_id > 0) {
                    $campos_update[] = "categoria_id = ?";
                    $tipos .= "i";
                    $params[] = $categoria_id;
                } else {
                    $campos_update[] = "categoria_id = NULL";
                }
                
                $campos_update[] = "valor_unitario = ?";
                $tipos .= "d";
                $params[] = $valor_venda;
                
                if (isset($colunas_servicos['valor_custo'])) {
                    $campos_update[] = "valor_custo = ?";
                    $tipos .= "d";
                    $params[] = $valor_custo;
                }
                
                if (isset($colunas_servicos['lucro'])) {
                    $campos_update[] = "lucro = ?";
                    $tipos .= "d";
                    $params[] = $lucro;
                }
                
                $campos_update[] = "tempo_execucao = ?";
                $tipos .= "i";
                $params[] = $tempo_execucao;
                
                $campos_update[] = "exibir_cliente = ?";
                $tipos .= "i";
                $params[] = $exibir_cliente;
                
                $campos_update[] = "updated_at = NOW()";
                
                // Adiciona o ID no final
                $tipos .= "i";
                $params[] = $id_editar;
                
                $sql = "UPDATE servicos SET " . implode(", ", $campos_update) . " WHERE id = ?";
                
                $stmt = $conexao->prepare($sql);
                
                if ($stmt) {
                    if (!empty($params)) {
                        $stmt->bind_param($tipos, ...$params);
                    }
                    
                    if ($stmt->execute()) {
                        $stmt->close();
                        
                        // Processa materiais se a tabela existir
                        $sql_check_table = "SHOW TABLES LIKE 'servico_materiais'";
                        $result_check_table = $conexao->query($sql_check_table);
                        
                        if ($result_check_table && $result_check_table->num_rows > 0) {
                            // Deleta materiais antigos
                            $sql_del = "DELETE FROM servico_materiais WHERE servico_id = ?";
                            $stmt_del = $conexao->prepare($sql_del);
                            $stmt_del->bind_param("i", $id_editar);
                            $stmt_del->execute();
                            $stmt_del->close();
                            
                            // Insere novos materiais
                            if (!empty($materiais)) {
                                $sql_ins = "INSERT INTO servico_materiais (servico_id, produto_id, quantidade) VALUES (?, ?, ?)";
                                $stmt_ins = $conexao->prepare($sql_ins);
                                
                                foreach ($materiais as $mat_id => $quantidade) {
                                    $stmt_ins->bind_param("iii", $id_editar, $mat_id, $quantidade);
                                    $stmt_ins->execute();
                                }
                                $stmt_ins->close();
                            }
                        }
                        
                        header('Location: ' . BASE_URL . '/app/admin/servicos.php?mensagem=atualizado');
                        exit;
                    } else {
                        $erro = "Erro ao atualizar: " . $stmt->error;
                        $stmt->close();
                    }
                } else {
                    $erro = "Erro na preparação da query: " . $conexao->error;
                }
            } else {
                // ===== INSERE NOVO SERVIÇO =====
                $campos_insert = ["nome", "descricao", "valor_unitario", "tempo_execucao", "exibir_cliente", "ativo", "created_at", "updated_at"];
                $valores_insert = ["?", "?", "?", "?", "?", "1", "NOW()", "NOW()"];
                $tipos = "ssdii";
                $params = [$nome, $descricao, $valor_venda, $tempo_execucao, $exibir_cliente];
                
                // Só adiciona categoria se for > 0
                if ($categoria_id > 0) {
                    $campos_insert[] = "categoria_id";
                    $valores_insert[] = "?";
                    $tipos .= "i";
                    $params[] = $categoria_id;
                }
                
                if (isset($colunas_servicos['valor_custo'])) {
                    $campos_insert[] = "valor_custo";
                    $valores_insert[] = "?";
                    $tipos .= "d";
                    $params[] = $valor_custo;
                }
                
                if (isset($colunas_servicos['lucro'])) {
                    $campos_insert[] = "lucro";
                    $valores_insert[] = "?";
                    $tipos .= "d";
                    $params[] = $lucro;
                }
                
                $sql = "INSERT INTO servicos (" . implode(", ", $campos_insert) . ") 
                        VALUES (" . implode(", ", $valores_insert) . ")";
                
                $stmt = $conexao->prepare($sql);
                
                if ($stmt) {
                    if (!empty($params)) {
                        $stmt->bind_param($tipos, ...$params);
                    }
                    
                    if ($stmt->execute()) {
                        $novo_id = $conexao->insert_id;
                        $stmt->close();
                        
                        // Processa materiais se a tabela existir
                        $sql_check_table = "SHOW TABLES LIKE 'servico_materiais'";
                        $result_check_table = $conexao->query($sql_check_table);
                        
                        if ($result_check_table && $result_check_table->num_rows > 0) {
                            // Insere materiais
                            if (!empty($materiais)) {
                                $sql_ins = "INSERT INTO servico_materiais (servico_id, produto_id, quantidade) VALUES (?, ?, ?)";
                                $stmt_ins = $conexao->prepare($sql_ins);
                                
                                foreach ($materiais as $mat_id => $quantidade) {
                                    $stmt_ins->bind_param("iii", $novo_id, $mat_id, $quantidade);
                                    $stmt_ins->execute();
                                }
                                $stmt_ins->close();
                            }
                        }
                        
                        header('Location: ' . BASE_URL . '/app/admin/servicos.php?mensagem=criado');
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
            $servico = [
                'id' => $id_editar,
                'nome' => $nome,
                'descricao' => $descricao,
                'categoria_id' => $categoria_id,
                'valor_unitario' => $valor_venda,
                'valor_venda' => $valor_venda,
                'valor_custo' => $valor_custo,
                'lucro' => $lucro,
                'tempo_execucao' => $tempo_execucao,
                'exibir_cliente' => $exibir_cliente,
                'ativo' => 1
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
                $sql = "UPDATE categorias_servicos SET nome = ?, descricao = ? WHERE id = ?";
                $stmt = $conexao->prepare($sql);
                
                if ($stmt) {
                    $stmt->bind_param("ssi", $nome_cat, $descricao_cat, $id_cat_editar);
                    
                    if ($stmt->execute()) {
                        $stmt->close();
                        header('Location: ' . BASE_URL . '/app/admin/servicos.php?acao=novo_categoria&mensagem=categoria_atualizada');
                        exit;
                    } else {
                        $erro = "Erro ao atualizar categoria: " . $stmt->error;
                        $stmt->close();
                    }
                }
            } else {
                // Insere nova categoria
                $sql = "INSERT INTO categorias_servicos (nome, descricao, ativo, created_at) VALUES (?, ?, 1, NOW())";
                $stmt = $conexao->prepare($sql);
                
                if ($stmt) {
                    $stmt->bind_param("ss", $nome_cat, $descricao_cat);
                    
                    if ($stmt->execute()) {
                        $stmt->close();
                        header('Location: ' . BASE_URL . '/app/admin/servicos.php?acao=novo_categoria&mensagem=categoria_criada');
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
    // Primeiro, atualiza serviços que usam esta categoria para NULL
    $sql_update = "UPDATE servicos SET categoria_id = NULL WHERE categoria_id = ?";
    $stmt_update = $conexao->prepare($sql_update);
    $stmt_update->bind_param("i", $id);
    $stmt_update->execute();
    $stmt_update->close();
    
    // Depois deleta a categoria
    $sql = "DELETE FROM categorias_servicos WHERE id = ?";
    $stmt = $conexao->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            $stmt->close();
            header('Location: ' . BASE_URL . '/app/admin/servicos.php?acao=novo_categoria&mensagem=categoria_deletada');
            exit;
        }
        $stmt->close();
    }
}

// ===== PROCESSAR EDITAR CATEGORIA =====

if ($acao === 'editar_categoria' && $id) {
    $sql = "SELECT * FROM categorias_servicos WHERE id = ?";
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

// ===== PROCESSAR DELETAR SERVIÇO =====

if ($acao === 'deletar' && $id) {
    // Verifica se a tabela servico_materiais existe
    $sql_check_table = "SHOW TABLES LIKE 'servico_materiais'";
    $result_check_table = $conexao->query($sql_check_table);
    
    if ($result_check_table && $result_check_table->num_rows > 0) {
        // Delete materiais associados
        $sql_del_mat = "DELETE FROM servico_materiais WHERE servico_id = ?";
        $stmt_del_mat = $conexao->prepare($sql_del_mat);
        if ($stmt_del_mat) {
            $stmt_del_mat->bind_param("i", $id);
            $stmt_del_mat->execute();
            $stmt_del_mat->close();
        }
    }
    
    // Delete serviço
    $sql = "DELETE FROM servicos WHERE id = ?";
    $stmt = $conexao->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            $stmt->close();
            header('Location: ' . BASE_URL . '/app/admin/servicos.php?mensagem=deletado');
            exit;
        }
        $stmt->close();
    }
}

// ===== LISTAR SERVIÇOS =====
if ($acao === 'listar' || $acao === 'deletar') {
    $pagina = isset($_GET['pagina']) ? intval($_GET['pagina']) : 1;
    $limite = 50;
    $offset = ($pagina - 1) * $limite;
    
    $sql = "SELECT s.*, c.nome as categoria_nome 
            FROM servicos s 
            LEFT JOIN categorias_servicos c ON s.categoria_id = c.id 
            WHERE s.ativo = 1
            ORDER BY s.nome ASC 
            LIMIT {$limite} OFFSET {$offset}";
    
    $resultado = $conexao->query($sql);
    
    if ($resultado) {
        while ($linha = $resultado->fetch_assoc()) {
            // Mapear valor_unitario para valor_venda para compatibilidade
            if (isset($linha['valor_unitario']) && !isset($linha['valor_venda'])) {
                $linha['valor_venda'] = $linha['valor_unitario'];
            }
            $servicos[] = $linha;
        }
    } else {
        $erro = "Erro na consulta: " . $conexao->error;
    }
    
    $sql_total = "SELECT COUNT(*) as total FROM servicos WHERE ativo = 1";
    $resultado_total = $conexao->query($sql_total);
    
    if ($resultado_total) {
        $total = $resultado_total->fetch_assoc()['total'];
        $total_paginas = ceil($total / $limite);
    }
    
    // Verifica se teve mensagem de sucesso
    if (isset($_GET['mensagem'])) {
        if ($_GET['mensagem'] === 'criado') {
            $mensagem = "✓ Serviço criado com sucesso!";
        } elseif ($_GET['mensagem'] === 'atualizado') {
            $mensagem = "✓ Serviço atualizado com sucesso!";
        } elseif ($_GET['mensagem'] === 'deletado') {
            $mensagem = "✓ Serviço deletado com sucesso!";
        }
    }
    
} elseif ($acao === 'novo') {
    // Mostra formulário de novo serviço
    $servico = [
        'id' => '', 
        'nome' => '', 
        'descricao' => '', 
        'categoria_id' => '', 
        'valor_unitario' => 0,
        'valor_venda' => 0, 
        'valor_custo' => 0,
        'lucro' => 0,
        'tempo_execucao' => 0,
        'exibir_cliente' => 1,
        'ativo' => 1
    ];
    
} elseif ($acao === 'novo_categoria') {
    // Recarrega categorias para exibição
    $categorias = [];
    $sql_categorias = "SELECT * FROM categorias_servicos WHERE ativo = 1 ORDER BY nome ASC";
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
    // Carrega serviço para editar
    $sql = "SELECT * FROM servicos WHERE id = ? AND ativo = 1";
    $stmt = $conexao->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $servico = $resultado->fetch_assoc();
        $stmt->close();
        
        if ($servico) {
            // Mapear valor_unitario para valor_venda
            if (isset($servico['valor_unitario'])) {
                $servico['valor_venda'] = $servico['valor_unitario'];
            }
        } else {
            header('Location: ' . BASE_URL . '/app/admin/servicos.php');
            exit;
        }
    }
} elseif ($acao === 'editar_categoria' && $id) {
    // Carrega categoria para editar
    $sql = "SELECT * FROM categorias_servicos WHERE id = ?";
    $stmt = $conexao->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $categoria_edit = $resultado->fetch_assoc();
        $stmt->close();
        
        if (!$categoria_edit) {
            header('Location: ' . BASE_URL . '/app/admin/servicos.php?acao=novo_categoria');
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
    <title>Serviços - Império AR</title>
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
            box-sizing: border-box;
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
        
        .form-group-checkbox {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 20px 0;
            padding: 15px;
            background: #f9f9f9;
            border-radius: 4px;
        }
        
        .form-group-checkbox input {
            width: auto;
            margin: 0;
        }
        
        .form-group-checkbox label {
            margin: 0;
            font-weight: normal;
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
        
        .materiais-section {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 4px;
            margin: 20px 0;
        }
        
        .material-item {
            display: grid;
            grid-template-columns: 2fr 1fr auto;
            gap: 10px;
            align-items: center;
            padding: 10px;
            background: white;
            border-radius: 3px;
            margin-bottom: 10px;
        }
        
        .material-item select,
        .material-item input {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 3px;
            box-sizing: border-box;
        }
        
        .material-item button {
            background: #dc3545;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 3px;
            cursor: pointer;
        }
        
        .material-item button:hover {
            background: #c82333;
        }
        
        .btn-add-material {
            background: #007bff;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-top: 10px;
        }
        
        .btn-add-material:hover {
            background: #0056b3;
        }
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
                    <h1>🔧 Gerenciamento de Serviços</h1>
                    <div class="btn-group">
                        <a href="<?php echo BASE_URL; ?>/app/admin/servicos.php?acao=novo_categoria" class="btn-categoria">
                            ➕ Gerenciar Categorias
                        </a>
                        <a href="<?php echo BASE_URL; ?>/app/admin/servicos.php?acao=novo" class="btn-novo">
                            ➕ Novo Serviço
                        </a>
                    </div>
                </div>

                <!-- LISTAGEM DE SERVIÇOS -->
                <?php if (count($servicos) > 0): ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Categoria</th>
                            <th>Valor Venda</th>
                            <th>Valor Custo</th>
                            <th>Lucro</th>
                            <th>Tempo (min)</th>
                            <th>Visível</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($servicos as $srv): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($srv['nome']); ?></td>
                            <td><?php echo htmlspecialchars($srv['categoria_nome'] ?? 'Sem categoria'); ?></td>
                            <td>R$ <?php echo number_format($srv['valor_venda'] ?? $srv['valor_unitario'] ?? 0, 2, ',', '.'); ?></td>
                            <td>R$ <?php echo number_format($srv['valor_custo'] ?? 0, 2, ',', '.'); ?></td>
                            <td><strong style="color: #28a745;">R$ <?php echo number_format($srv['lucro'] ?? 0, 2, ',', '.'); ?></strong></td>
                            <td><?php echo $srv['tempo_execucao']; ?></td>
                            <td><?php echo $srv['exibir_cliente'] ? '✓ Sim' : '✗ Não'; ?></td>
                            <td>
                                <a href="<?php echo BASE_URL; ?>/app/admin/servicos.php?acao=editar&id=<?php echo $srv['id']; ?>" class="btn-editar">✏️ Editar</a>
                                <a href="<?php echo BASE_URL; ?>/app/admin/servicos.php?acao=deletar&id=<?php echo $srv['id']; ?>" class="btn-deletar" onclick="return confirm('Tem certeza que deseja deletar este serviço?')">🗑️ Deletar</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php if ($total_paginas > 1): ?>
                <div class="pagination" style="margin-top: 20px; text-align: center;">
                    <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
                    <a href="?pagina=<?php echo $i; ?>" style="display: inline-block; padding: 5px 10px; margin: 0 2px; background: <?php echo $i == $pagina ? '#007bff' : '#f0f0f0'; ?>; color: <?php echo $i == $pagina ? 'white' : '#333'; ?>; text-decoration: none; border-radius: 3px;"><?php echo $i; ?></a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
                
                <?php else: ?>
                <div class="empty-message">
                    <p>Nenhum serviço cadastrado.</p>
                    <a href="<?php echo BASE_URL; ?>/app/admin/servicos.php?acao=novo" class="btn-novo" style="margin-top: 15px;">
                        ➕ Criar Novo Serviço
                    </a>
                </div>
                <?php endif; ?>

            <?php elseif ($acao === 'novo_categoria'): ?>

                <div class="page-header">
                    <h1>🏷️ Gerenciar Categorias</h1>
                    <a href="<?php echo BASE_URL; ?>/app/admin/servicos.php" class="btn-voltar">
                        ← Voltar
                    </a>
                </div>

                <div class="form-card">
                    <h2>➕ Nova Categoria</h2>
                    <form method="POST">
                        <input type="hidden" name="acao" value="salvar_categoria">
                        <div class="form-group">
                            <label>Nome da Categoria *</label>
                            <input type="text" name="nome_categoria" required placeholder="Ex: Instalação, Manutenção, Reparo...">
                        </div>
                        <div class="form-group">
                            <label>Descrição</label>
                            <textarea name="descricao_categoria" rows="3" placeholder="Descrição da categoria..."></textarea>
                        </div>
                        <button type="submit" class="btn-salvar">✓ Criar Categoria</button>
                    </form>
                </div>

                <div class="form-card">
                    <h2>📋 Categorias Existentes</h2>
                    <?php if (count($categorias) > 0): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nome</th>
                                <th>Descrição</th>
                                <th>Data Criação</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categorias as $cat): ?>
                            <tr>
                                <td><?php echo $cat['id']; ?></td>
                                <td><?php echo htmlspecialchars($cat['nome']); ?></td>
                                <td><?php echo htmlspecialchars($cat['descricao'] ?? ''); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($cat['created_at'] ?? 'now')); ?></td>
                                <td>
                                    <a href="<?php echo BASE_URL; ?>/app/admin/servicos.php?acao=editar_categoria&id=<?php echo $cat['id']; ?>" class="btn-editar">✏️ Editar</a>
                                    <a href="<?php echo BASE_URL; ?>/app/admin/servicos.php?acao=deletar_categoria&id=<?php echo $cat['id']; ?>" class="btn-deletar" onclick="return confirm('Deletar esta categoria? Os serviços vinculados ficarão sem categoria.')">🗑️ Deletar</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="empty-message">
                        <p>Nenhuma categoria cadastrada.</p>
                        <p>Crie sua primeira categoria usando o formulário acima.</p>
                    </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($acao === 'editar_categoria'): ?>

                <div class="page-header">
                    <h1>✏️ Editar Categoria</h1>
                    <a href="<?php echo BASE_URL; ?>/app/admin/servicos.php?acao=novo_categoria" class="btn-voltar">← Voltar</a>
                </div>

                <div class="form-card">
                    <form method="POST">
                        <input type="hidden" name="acao" value="salvar_categoria">
                        <input type="hidden" name="id_categoria" value="<?php echo $categoria_edit['id'] ?? ''; ?>">
                        <div class="form-group">
                            <label>Nome da Categoria *</label>
                            <input type="text" name="nome_categoria" value="<?php echo htmlspecialchars($categoria_edit['nome'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Descrição</label>
                            <textarea name="descricao_categoria" rows="3"><?php echo htmlspecialchars($categoria_edit['descricao'] ?? ''); ?></textarea>
                        </div>
                        <button type="submit" class="btn-salvar">✓ Atualizar Categoria</button>
                        <a href="<?php echo BASE_URL; ?>/app/admin/servicos.php?acao=novo_categoria" class="btn-cancelar">✕ Cancelar</a>
                    </form>
                </div>

            <?php else: ?>

                <div class="page-header">
                    <h1><?php echo $acao === 'novo' ? '➕ Novo Serviço' : '✏️ Editar Serviço'; ?></h1>
                    <a href="<?php echo BASE_URL; ?>/app/admin/servicos.php" class="btn-voltar">← Voltar</a>
                </div>

                <div class="form-card">
                    <form method="POST" action="<?php echo BASE_URL; ?>/app/admin/servicos.php">
                        <input type="hidden" name="acao" value="salvar">
                        <?php if ($acao === 'editar'): ?>
                        <input type="hidden" name="id" value="<?php echo $servico['id'] ?? ''; ?>">
                        <?php endif; ?>
                        
                        <h3>📝 Dados Básicos</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Nome do Serviço *</label>
                                <input type="text" name="nome" value="<?php echo htmlspecialchars($servico['nome'] ?? ''); ?>" required placeholder="Ex: Instalação de Ar Condicionado">
                            </div>
                            <div class="form-group">
                                <label>Categoria</label>
                                <select name="categoria_id">
                                    <option value="">-- Sem categoria --</option>
                                    <?php foreach ($categorias as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>" <?php echo ($servico['categoria_id'] ?? '') == $cat['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat['nome']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Descrição</label>
                            <textarea name="descricao" rows="3" placeholder="Descrição detalhada do serviço..."><?php echo htmlspecialchars($servico['descricao'] ?? ''); ?></textarea>
                        </div>

                        <h3>💰 Valores</h3>
                        <div class="form-row-3">
                            <div class="form-group">
                                <label>Valor de Venda (R$) *</label>
                                <input type="text" id="valor_venda" name="valor_venda" value="<?php echo isset($servico['valor_venda']) && $servico['valor_venda'] > 0 ? number_format($servico['valor_venda'], 2, ',', '.') : '0,00'; ?>" required placeholder="0,00">
                                <small style="color: #666;">Valor cobrado do cliente</small>
                            </div>
                            <div class="form-group">
                                <label>Valor de Custo (R$)</label>
                                <input type="text" id="valor_custo" value="<?php echo isset($servico['valor_custo']) ? number_format($servico['valor_custo'], 2, ',', '.') : '0,00'; ?>" readonly style="background: #f5f5f5;">
                                <small style="color: #666;">Calculado automaticamente</small>
                            </div>
                            <div class="form-group">
                                <label>Lucro (R$)</label>
                                <input type="text" id="lucro" value="<?php echo isset($servico['lucro']) ? number_format($servico['lucro'], 2, ',', '.') : '0,00'; ?>" readonly style="background: #f5f5f5; color: #28a745; font-weight: bold;">
                                <small style="color: #666;">Venda - Custo</small>
                            </div>
                        </div>

                        <h3>🔩 Materiais Necessários</h3>
                        <div class="materiais-section">
                            <p style="margin-top: 0; color: #666;">Selecione os materiais utilizados neste serviço</p>
                            <div id="materiais-container">
                                <?php if ($acao === 'editar' && isset($servico['id'])): ?>
                                    <?php
                                    // Verificar se a tabela servico_materiais existe
                                    $sql_check_table = "SHOW TABLES LIKE 'servico_materiais'";
                                    $result_check_table = $conexao->query($sql_check_table);
                                    
                                    if ($result_check_table && $result_check_table->num_rows > 0) {
                                        $sql_mat = "SELECT sm.*, p.nome, p.valor_compra FROM servico_materiais sm 
                                                    JOIN produtos p ON sm.produto_id = p.id 
                                                    WHERE sm.servico_id = ?";
                                        $stmt_mat = $conexao->prepare($sql_mat);
                                        if ($stmt_mat) {
                                            $stmt_mat->bind_param("i", $servico['id']);
                                            $stmt_mat->execute();
                                            $res_mat = $stmt_mat->get_result();
                                            
                                            $index = 0;
                                            while ($mat = $res_mat->fetch_assoc()) {
                                                echo '<div class="material-item">';
                                                echo '<select name="materiais[' . $index . ']" onchange="calcularCusto()">';
                                                echo '<option value="">-- Selecione um material --</option>';
                                                foreach ($produtos as $prod) {
                                                    $sel = $prod['id'] == $mat['produto_id'] ? 'selected' : '';
                                                    echo '<option value="' . $prod['id'] . '" ' . $sel . '>' . htmlspecialchars($prod['nome']) . ' (R$ ' . number_format($prod['valor_compra'], 2, ',', '.') . ')</option>';
                                                }
                                                echo '</select>';
                                                echo '<input type="number" name="quantidades[' . $index . ']" value="' . $mat['quantidade'] . '" min="1" onchange="calcularCusto()">';
                                                echo '<button type="button" onclick="removerMaterial(this)">Remover</button>';
                                                echo '</div>';
                                                $index++;
                                            }
                                            $stmt_mat->close();
                                        }
                                    }
                                    ?>
                                <?php endif; ?>
                            </div>
                            <button type="button" onclick="adicionarMaterial()" class="btn-add-material">+ Adicionar Material</button>
                        </div>

                        <h3>⏱️ Configurações de Execução</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Tempo Estimado (minutos)</label>
                                <input type="number" name="tempo_execucao" value="<?php echo $servico['tempo_execucao'] ?? 0; ?>" min="0" step="15" placeholder="Ex: 60">
                                <small style="color: #666;">Tempo médio para realizar o serviço</small>
                            </div>
                            <div class="form-group-checkbox">
                                <input type="checkbox" id="exibir_cliente" name="exibir_cliente" value="1" <?php echo ($servico['exibir_cliente'] ?? 1) ? 'checked' : ''; ?>>
                                <label for="exibir_cliente">Exibir este serviço para clientes</label>
                                <small style="color: #666; display: block; margin-left: 24px;">Se marcado, aparece na página inicial</small>
                            </div>
                        </div>

                        <div style="margin-top: 30px; border-top: 1px solid #eee; padding-top: 20px;">
                            <button type="submit" class="btn-salvar">✓ Salvar Serviço</button>
                            <a href="<?php echo BASE_URL; ?>/app/admin/servicos.php" class="btn-cancelar">✕ Cancelar</a>
                        </div>
                    </form>
                </div>

            <?php endif; ?>

        </main>
    </div>

    <script>
        const produtos = <?php echo json_encode($produtos); ?>;

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

        function calcularCusto() {
            let custo = 0;
            const container = document.getElementById('materiais-container');
            const items = container.querySelectorAll('.material-item');
            
            items.forEach(item => {
                const select = item.querySelector('select');
                const qtd = item.querySelector('input[type="number"]');
                
                if (select && select.value && !isNaN(select.value) && qtd && qtd.value > 0) {
                    const prod = produtos.find(p => p.id == select.value);
                    if (prod) {
                        custo += parseFloat(prod.valor_compra) * parseInt(qtd.value);
                    }
                }
            });
            
            const vendaInput = document.getElementById('valor_venda');
            const venda = parseFloat(vendaInput.value.replace(/[^\d,]/g, '').replace(',', '.')) || 0;
            const lucro = venda - custo;
            
            document.getElementById('valor_custo').value = custo.toLocaleString('pt-BR', { minimumFractionDigits: 2 });
            document.getElementById('lucro').value = lucro.toLocaleString('pt-BR', { minimumFractionDigits: 2 });
        }

        function adicionarMaterial() {
            const container = document.getElementById('materiais-container');
            const index = container.children.length;
            const div = document.createElement('div');
            div.className = 'material-item';
            
            let html = '<select name="materiais[' + index + ']" onchange="calcularCusto()"><option value="">-- Selecione um material --</option>';
            produtos.forEach(p => {
                html += '<option value="' + p.id + '">' + p.nome + ' (R$ ' + parseFloat(p.valor_compra).toLocaleString('pt-BR', { minimumFractionDigits: 2 }) + ')</option>';
            });
            html += '</select>';
            html += '<input type="number" name="quantidades[' + index + ']" value="1" min="1" onchange="calcularCusto()">';
            html += '<button type="button" onclick="removerMaterial(this)">Remover</button>';
            
            div.innerHTML = html;
            container.appendChild(div);
        }

        function removerMaterial(btn) {
            btn.parentElement.remove();
            // Reindexar os materiais após remover
            const container = document.getElementById('materiais-container');
            const items = container.querySelectorAll('.material-item');
            items.forEach((item, newIndex) => {
                const select = item.querySelector('select');
                const qtd = item.querySelector('input[type="number"]');
                if (select) select.name = 'materiais[' + newIndex + ']';
                if (qtd) qtd.name = 'quantidades[' + newIndex + ']';
            });
            calcularCusto();
        }

        document.addEventListener('DOMContentLoaded', function() {
            const venda = document.getElementById('valor_venda');
            if (venda) {
                venda.addEventListener('input', function() {
                    formatarMoeda(this);
                    calcularCusto();
                });
                calcularCusto();
            }
        });
    </script>
    <script src="<?php echo BASE_URL; ?>/public/js/main.js"></script>
</body>
</html>
