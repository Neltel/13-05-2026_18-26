<?php
/**
 * =====================================================================
 * PEDIDOS - SISTEMA DE GESTÃO IMPÉRIO AR (COM SERVIÇOS)
 * =====================================================================
 * 
 * Responsabilidade: Gerenciar pedidos de serviços e produtos
 * Funcionalidades:
 * - Listar pedidos com filtros
 * - Criar novo pedido (produtos + serviços)
 * - Editar pedido existente
 * - Visualizar detalhes
 * - Atualizar status
 * - Enviar WhatsApp
 * - Gerar PDF
 * 
 * VERSÃO COMPLETA COM SUPORTE A SERVIÇOS
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/environment.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/WhatsApp.php';
require_once __DIR__ . '/../../classes/PDF.php';
require_once __DIR__ . '/../../classes/Financeiro.php';

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
$acao = $_GET['acao'] ?? 'listar';
$id = isset($_GET['id']) ? intval($_GET['id']) : null;
$mensagem = '';
$erro = '';
$pedidos = [];
$clientes = [];
$produtos = [];
$servicos = [];
$pedido = [];
$pagina_atual = isset($_GET['pagina']) ? intval($_GET['pagina']) : 1;
$por_pagina = 20;
$total_paginas = 0;

// ===== INICIALIZAR CLASSES =====
$whatsapp = new WhatsApp($conexao);
$pdf = new PDF($conexao);
$financeiro = new Financeiro($conexao);

// ===== FUNÇÕES AUXILIARES =====
function formatarMoeda($valor) {
    if (empty($valor) || !is_numeric($valor)) $valor = 0;
    return 'R$ ' . number_format(floatval($valor), 2, ',', '.');
}

function moedaParaFloat($valor) {
    if (empty($valor)) return 0;
    $valor = str_replace(['R$', ' ', '.'], '', $valor);
    $valor = str_replace(',', '.', $valor);
    return floatval($valor);
}

function formatarData($data) {
    if (empty($data)) return '';
    return date('d/m/Y', strtotime($data));
}

function verificarCSRF($token) {
    return isset($_SESSION['csrf_token']) && $token === $_SESSION['csrf_token'];
}

// ===== CARREGAR DADOS BÁSICOS =====
function carregarClientes($conexao) {
    $clientes = [];
    $sql = "SELECT id, nome, telefone, email, cpf_cnpj, whatsapp 
            FROM clientes WHERE ativo = 1 ORDER BY nome ASC";
    $resultado = $conexao->query($sql);
    if ($resultado) {
        while ($linha = $resultado->fetch_assoc()) {
            $clientes[] = $linha;
        }
    }
    return $clientes;
}

function carregarProdutos($conexao) {
    $produtos = [];
    $sql = "SELECT p.*, c.nome as categoria_nome 
            FROM produtos p 
            LEFT JOIN categorias_produtos c ON p.categoria_id = c.id 
            WHERE p.ativo = 1 ORDER BY p.nome ASC";
    $resultado = $conexao->query($sql);
    if ($resultado) {
        while ($linha = $resultado->fetch_assoc()) {
            $produtos[] = $linha;
        }
    }
    return $produtos;
}

function carregarServicos($conexao) {
    $servicos = [];
    $sql = "SELECT s.*, c.nome as categoria_nome 
            FROM servicos s 
            LEFT JOIN categorias_servicos c ON s.categoria_id = c.id 
            WHERE s.ativo = 1 ORDER BY s.nome ASC";
    $resultado = $conexao->query($sql);
    if ($resultado) {
        while ($linha = $resultado->fetch_assoc()) {
            $servicos[] = $linha;
        }
    }
    return $servicos;
}

// Carregar dados
$clientes = carregarClientes($conexao);
$produtos = carregarProdutos($conexao);
$servicos = carregarServicos($conexao);

// ===== FUNÇÕES PARA ITENS DO PEDIDO =====
function buscarItensProdutos($conexao, $pedido_id) {
    $itens = [];
    $sql = "SELECT pp.*, p.nome, p.valor_venda 
            FROM pedido_produtos pp 
            JOIN produtos p ON pp.produto_id = p.id 
            WHERE pp.pedido_id = ?";
    $stmt = $conexao->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $pedido_id);
        $stmt->execute();
        $resultado = $stmt->get_result();
        while ($row = $resultado->fetch_assoc()) {
            $itens[] = $row;
        }
        $stmt->close();
    }
    return $itens;
}

function buscarItensServicos($conexao, $pedido_id) {
    $itens = [];
    // Criar tabela pedido_servicos se não existir
    $sql = "SELECT ps.*, s.nome, s.valor_unitario 
            FROM pedido_servicos ps 
            JOIN servicos s ON ps.servico_id = s.id 
            WHERE ps.pedido_id = ?";
    $stmt = $conexao->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $pedido_id);
        $stmt->execute();
        $resultado = $stmt->get_result();
        while ($row = $resultado->fetch_assoc()) {
            $itens[] = $row;
        }
        $stmt->close();
    }
    return $itens;
}

// ===== VERIFICAR E CRIAR TABELAS SE NECESSÁRIO =====
function verificarTabelas($conexao) {
    // Verifica se tabela pedido_servicos existe
    $result = $conexao->query("SHOW TABLES LIKE 'pedido_servicos'");
    if ($result->num_rows == 0) {
        // Cria a tabela
        $sql = "CREATE TABLE IF NOT EXISTS `pedido_servicos` (
            `id` int NOT NULL AUTO_INCREMENT,
            `pedido_id` int NOT NULL,
            `servico_id` int NOT NULL,
            `quantidade` int DEFAULT NULL,
            `valor_unitario` decimal(10,2) DEFAULT NULL,
            `subtotal` decimal(12,2) DEFAULT NULL,
            `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `pedido_id` (`pedido_id`),
            KEY `servico_id` (`servico_id`),
            CONSTRAINT `pedido_servicos_ibfk_1` FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`) ON DELETE CASCADE,
            CONSTRAINT `pedido_servicos_ibfk_2` FOREIGN KEY (`servico_id`) REFERENCES `servicos` (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $conexao->query($sql);
    }
}

// Chama a verificação
verificarTabelas($conexao);

// ===== PROCESSAR AÇÕES POST =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verificarCSRF($_POST['csrf_token'])) {
        $erro = "Token de segurança inválido.";
    } else {
        $acao_post = $_POST['acao'] ?? '';
        
        // ===== SALVAR PEDIDO =====
        if ($acao_post === 'salvar') {
            $cliente_id = isset($_POST['cliente_id']) ? intval($_POST['cliente_id']) : 0;
            $data_pedido = $_POST['data_pedido'] ?? date('Y-m-d');
            $observacao = $conexao->real_escape_string($_POST['observacao'] ?? '');
            $situacao = trim($_POST['situacao'] ?? 'pendente');
            $desconto_percentual = floatval($_POST['desconto_percentual'] ?? 0);
            $valor_adicional = moedaParaFloat($_POST['valor_adicional'] ?? '0,00');
            $id_editar = isset($_POST['id']) ? intval($_POST['id']) : null;
            
            // Processa produtos
            $itens_produtos = [];
            if (isset($_POST['produtos']) && is_array($_POST['produtos'])) {
                foreach ($_POST['produtos'] as $index => $prod_id) {
                    if (!empty($prod_id) && isset($_POST['qtd_produtos'][$index]) && $_POST['qtd_produtos'][$index] > 0) {
                        $qtd = floatval($_POST['qtd_produtos'][$index]);
                        $valor = moedaParaFloat($_POST['valor_produtos'][$index] ?? '0,00');
                        
                        $itens_produtos[] = [
                            'id' => intval($prod_id),
                            'quantidade' => $qtd,
                            'valor_unitario' => $valor,
                            'subtotal' => $qtd * $valor
                        ];
                    }
                }
            }
            
            // Processa serviços
            $itens_servicos = [];
            if (isset($_POST['servicos']) && is_array($_POST['servicos'])) {
                foreach ($_POST['servicos'] as $index => $serv_id) {
                    if (!empty($serv_id) && isset($_POST['qtd_servicos'][$index]) && $_POST['qtd_servicos'][$index] > 0) {
                        $qtd = floatval($_POST['qtd_servicos'][$index]);
                        $valor = moedaParaFloat($_POST['valor_servicos'][$index] ?? '0,00');
                        
                        $itens_servicos[] = [
                            'id' => intval($serv_id),
                            'quantidade' => $qtd,
                            'valor_unitario' => $valor,
                            'subtotal' => $qtd * $valor
                        ];
                    }
                }
            }
            
            $subtotal_produtos = array_sum(array_column($itens_produtos, 'subtotal'));
            $subtotal_servicos = array_sum(array_column($itens_servicos, 'subtotal'));
            $subtotal_geral = $subtotal_produtos + $subtotal_servicos;
            
            $desconto_valor = $subtotal_geral * ($desconto_percentual / 100);
            $valor_total = ($subtotal_geral - $desconto_valor) + $valor_adicional;
            
            // Calcula custo (apenas produtos têm custo)
            $valor_custo = 0;
            foreach ($itens_produtos as $item) {
                $sql = "SELECT valor_compra FROM produtos WHERE id = ?";
                $stmt = $conexao->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("i", $item['id']);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($row = $res->fetch_assoc()) {
                        $valor_custo += floatval($row['valor_compra']) * $item['quantidade'];
                    }
                    $stmt->close();
                }
            }
            $valor_lucro = $valor_total - $valor_custo;
            
            if ($cliente_id <= 0) {
                $erro = "Cliente é obrigatório";
            } elseif (empty($itens_produtos) && empty($itens_servicos)) {
                $erro = "Adicione pelo menos um produto ou serviço";
            } else {
                if ($id_editar) {
                    // UPDATE
                    $conexao->begin_transaction();
                    
                    try {
                        $sql = "UPDATE pedidos SET 
                                cliente_id = ?,
                                data_pedido = ?,
                                observacao = ?,
                                situacao = ?,
                                desconto_percentual = ?,
                                valor_adicional = ?,
                                valor_total = ?,
                                valor_custo = ?,
                                valor_lucro = ?
                                WHERE id = ?";
                        
                        $stmt = $conexao->prepare($sql);
                        $stmt->bind_param(
                            "isssdddddi",
                            $cliente_id,
                            $data_pedido,
                            $observacao,
                            $situacao,
                            $desconto_percentual,
                            $valor_adicional,
                            $valor_total,
                            $valor_custo,
                            $valor_lucro,
                            $id_editar
                        );
                        $stmt->execute();
                        $stmt->close();
                        
                        // Remove itens antigos
                        $sql_delete_produtos = "DELETE FROM pedido_produtos WHERE pedido_id = ?";
                        $stmt_delete = $conexao->prepare($sql_delete_produtos);
                        $stmt_delete->bind_param("i", $id_editar);
                        $stmt_delete->execute();
                        $stmt_delete->close();
                        
                        $sql_delete_servicos = "DELETE FROM pedido_servicos WHERE pedido_id = ?";
                        $stmt_delete = $conexao->prepare($sql_delete_servicos);
                        $stmt_delete->bind_param("i", $id_editar);
                        $stmt_delete->execute();
                        $stmt_delete->close();
                        
                        // Insere novos produtos
                        if (!empty($itens_produtos)) {
                            $sql = "INSERT INTO pedido_produtos (pedido_id, produto_id, quantidade, valor_unitario, subtotal) 
                                    VALUES (?, ?, ?, ?, ?)";
                            $stmt = $conexao->prepare($sql);
                            foreach ($itens_produtos as $item) {
                                $stmt->bind_param("iiddd", $id_editar, $item['id'], $item['quantidade'], $item['valor_unitario'], $item['subtotal']);
                                $stmt->execute();
                            }
                            $stmt->close();
                        }
                        
                        // Insere novos serviços
                        if (!empty($itens_servicos)) {
                            $sql = "INSERT INTO pedido_servicos (pedido_id, servico_id, quantidade, valor_unitario, subtotal) 
                                    VALUES (?, ?, ?, ?, ?)";
                            $stmt = $conexao->prepare($sql);
                            foreach ($itens_servicos as $item) {
                                $stmt->bind_param("iiddd", $id_editar, $item['id'], $item['quantidade'], $item['valor_unitario'], $item['subtotal']);
                                $stmt->execute();
                            }
                            $stmt->close();
                        }
                        
                        $conexao->commit();
                        header('Location: ' . BASE_URL . '/app/admin/pedidos.php?mensagem=atualizado');
                        exit;
                        
                    } catch (Exception $e) {
                        $conexao->rollback();
                        $erro = "Erro ao atualizar: " . $e->getMessage();
                    }
                    
                } else {
                    // INSERT
                    $conexao->begin_transaction();
                    
                    try {
                        $numero = 'PED-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                        
                        $sql = "INSERT INTO pedidos (numero, cliente_id, data_pedido, situacao, observacao, desconto_percentual, valor_adicional, valor_total, valor_custo, valor_lucro) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        
                        $stmt = $conexao->prepare($sql);
                        $stmt->bind_param(
                            "sissdddddd",
                            $numero,
                            $cliente_id,
                            $data_pedido,
                            $situacao,
                            $observacao,
                            $desconto_percentual,
                            $valor_adicional,
                            $valor_total,
                            $valor_custo,
                            $valor_lucro
                        );
                        $stmt->execute();
                        $novo_id = $conexao->insert_id;
                        $stmt->close();
                        
                        // Insere produtos
                        if (!empty($itens_produtos)) {
                            $sql = "INSERT INTO pedido_produtos (pedido_id, produto_id, quantidade, valor_unitario, subtotal) 
                                    VALUES (?, ?, ?, ?, ?)";
                            $stmt = $conexao->prepare($sql);
                            foreach ($itens_produtos as $item) {
                                $stmt->bind_param("iiddd", $novo_id, $item['id'], $item['quantidade'], $item['valor_unitario'], $item['subtotal']);
                                $stmt->execute();
                            }
                            $stmt->close();
                        }
                        
                        // Insere serviços
                        if (!empty($itens_servicos)) {
                            $sql = "INSERT INTO pedido_servicos (pedido_id, servico_id, quantidade, valor_unitario, subtotal) 
                                    VALUES (?, ?, ?, ?, ?)";
                            $stmt = $conexao->prepare($sql);
                            foreach ($itens_servicos as $item) {
                                $stmt->bind_param("iiddd", $novo_id, $item['id'], $item['quantidade'], $item['valor_unitario'], $item['subtotal']);
                                $stmt->execute();
                            }
                            $stmt->close();
                        }
                        
                        $conexao->commit();
                        header('Location: ' . BASE_URL . '/app/admin/pedidos.php?mensagem=criado');
                        exit;
                        
                    } catch (Exception $e) {
                        $conexao->rollback();
                        $erro = "Erro ao criar: " . $e->getMessage();
                    }
                }
            }
        }
        
        // ===== ATUALIZAR STATUS =====
        if ($acao_post === 'atualizar_status' && isset($_POST['id'])) {
            $pedido_id = intval($_POST['id']);
            $novo_status = $_POST['status'] ?? '';
            
            $status_validos = ['pendente', 'em_andamento', 'finalizado', 'cancelado'];
            if (in_array($novo_status, $status_validos)) {
                $sql = "UPDATE pedidos SET situacao = ? WHERE id = ?";
                $stmt = $conexao->prepare($sql);
                $stmt->bind_param("si", $novo_status, $pedido_id);
                
                if ($stmt->execute()) {
                    $mensagem = "Status atualizado com sucesso!";
                } else {
                    $erro = "Erro ao atualizar status";
                }
                $stmt->close();
            }
        }
    }
}

// ===== PROCESSAR AÇÕES GET =====
if ($acao === 'deletar' && $id) {
    $conexao->begin_transaction();
    
    try {
        $sql1 = "DELETE FROM pedido_produtos WHERE pedido_id = ?";
        $stmt1 = $conexao->prepare($sql1);
        $stmt1->bind_param("i", $id);
        $stmt1->execute();
        $stmt1->close();
        
        $sql2 = "DELETE FROM pedido_servicos WHERE pedido_id = ?";
        $stmt2 = $conexao->prepare($sql2);
        $stmt2->bind_param("i", $id);
        $stmt2->execute();
        $stmt2->close();
        
        $sql3 = "DELETE FROM pedidos WHERE id = ?";
        $stmt3 = $conexao->prepare($sql3);
        $stmt3->bind_param("i", $id);
        $stmt3->execute();
        $stmt3->close();
        
        $conexao->commit();
        header('Location: ' . BASE_URL . '/app/admin/pedidos.php?mensagem=deletado');
        exit;
        
    } catch (Exception $e) {
        $conexao->rollback();
        $erro = "Erro ao deletar: " . $e->getMessage();
    }
}

if ($acao === 'finalizar' && $id) {
    $sql = "UPDATE pedidos SET situacao = 'finalizado' WHERE id = ?";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        header('Location: ' . BASE_URL . '/app/admin/pedidos.php?mensagem=finalizado');
        exit;
    }
    $stmt->close();
}

if ($acao === 'cancelar' && $id) {
    $sql = "UPDATE pedidos SET situacao = 'cancelado' WHERE id = ?";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        header('Location: ' . BASE_URL . '/app/admin/pedidos.php?mensagem=cancelado');
        exit;
    }
    $stmt->close();
}

// ===== LISTAR PEDIDOS =====
if ($acao === 'listar') {
    $filtro_status = $_GET['status'] ?? '';
    $filtro_cliente = $_GET['cliente'] ?? '';
    $filtro_mes = $_GET['mes'] ?? date('m');
    $filtro_ano = $_GET['ano'] ?? date('Y');
    
    $offset = ($pagina_atual - 1) * $por_pagina;
    
    $sql = "SELECT p.*, c.nome as cliente_nome, c.telefone as cliente_telefone 
            FROM pedidos p 
            LEFT JOIN clientes c ON p.cliente_id = c.id 
            WHERE 1=1";
    $params = [];
    $types = "";
    
    if ($filtro_status) {
        $sql .= " AND p.situacao = ?";
        $params[] = $filtro_status;
        $types .= "s";
    }
    
    if ($filtro_cliente) {
        $sql .= " AND (c.nome LIKE ? OR c.telefone LIKE ?)";
        $params[] = "%$filtro_cliente%";
        $params[] = "%$filtro_cliente%";
        $types .= "ss";
    }
    
    $sql .= " AND MONTH(p.data_pedido) = ? AND YEAR(p.data_pedido) = ?";
    $params[] = $filtro_mes;
    $params[] = $filtro_ano;
    $types .= "ii";
    
    $sql .= " ORDER BY p.data_pedido DESC LIMIT ? OFFSET ?";
    $params[] = $por_pagina;
    $params[] = $offset;
    $types .= "ii";
    
    $stmt = $conexao->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    $pedidos = [];
    while ($linha = $resultado->fetch_assoc()) {
        $linha['itens_produtos'] = buscarItensProdutos($conexao, $linha['id']);
        $linha['itens_servicos'] = buscarItensServicos($conexao, $linha['id']);
        $pedidos[] = $linha;
    }
    $stmt->close();
    
    // Total para paginação
    $sql_total = "SELECT COUNT(*) as total FROM pedidos p WHERE 1=1";
    if ($filtro_status) $sql_total .= " AND p.situacao = '$filtro_status'";
    $resultado_total = $conexao->query($sql_total);
    $total = $resultado_total->fetch_assoc()['total'];
    $total_paginas = ceil($total / $por_pagina);
    
    // Estatísticas
    $stats = [
        'pendente' => 0,
        'em_andamento' => 0,
        'finalizado' => 0,
        'cancelado' => 0
    ];
    
    $sql_stats = "SELECT situacao, COUNT(*) as total 
                  FROM pedidos 
                  WHERE MONTH(data_pedido) = ? AND YEAR(data_pedido) = ? 
                  GROUP BY situacao";
    $stmt_stats = $conexao->prepare($sql_stats);
    $stmt_stats->bind_param("ii", $filtro_mes, $filtro_ano);
    $stmt_stats->execute();
    $result_stats = $stmt_stats->get_result();
    while ($row = $result_stats->fetch_assoc()) {
        if (isset($stats[$row['situacao']])) {
            $stats[$row['situacao']] = $row['total'];
        }
    }
    $stmt_stats->close();
    
    // Mensagens
    if (isset($_GET['mensagem'])) {
        $mapa = [
            'criado' => "✓ Pedido criado com sucesso!",
            'atualizado' => "✓ Pedido atualizado com sucesso!",
            'deletado' => "✓ Pedido deletado com sucesso!",
            'finalizado' => "✓ Pedido finalizado com sucesso!",
            'cancelado' => "✓ Pedido cancelado com sucesso!"
        ];
        $mensagem = $mapa[$_GET['mensagem']] ?? '';
    }
}

// ===== CARREGAR PEDIDO PARA EDIÇÃO =====
if ($acao === 'editar' && $id) {
    $sql = "SELECT p.*, c.nome as cliente_nome 
            FROM pedidos p 
            LEFT JOIN clientes c ON p.cliente_id = c.id 
            WHERE p.id = ?";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $pedido = $resultado->fetch_assoc();
    $stmt->close();
    
    if ($pedido) {
        $pedido['itens_produtos'] = buscarItensProdutos($conexao, $id);
        $pedido['itens_servicos'] = buscarItensServicos($conexao, $id);
    } else {
        header('Location: ' . BASE_URL . '/app/admin/pedidos.php');
        exit;
    }
}

// ===== NOVO PEDIDO =====
if ($acao === 'novo') {
    $pedido = [
        'id' => '',
        'cliente_id' => '',
        'data_pedido' => date('Y-m-d'),
        'situacao' => 'pendente',
        'observacao' => '',
        'desconto_percentual' => 0,
        'valor_adicional' => 0,
        'valor_total' => 0,
        'valor_custo' => 0,
        'valor_lucro' => 0,
        'itens_produtos' => [],
        'itens_servicos' => []
    ];
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pedidos - Império AR (com Serviços)</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #1e3c72;
            --secondary: #2a5298;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
            --dark: #343a40;
            --light: #f8f9fa;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f6fa 0%, #e9ecef 100%);
            min-height: 100vh;
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
            overflow-y: auto;
            position: fixed;
            height: 100vh;
            z-index: 1000;
            box-shadow: 2px 0 20px rgba(0,0,0,0.1);
        }
        
        .sidebar-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.2);
        }
        
        .sidebar-header h2 {
            color: white;
            margin-bottom: 5px;
            font-size: 24px;
        }
        
        .sidebar-header p {
            color: rgba(255,255,255,0.8);
            font-size: 14px;
        }
        
        .sidebar-nav {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .nav-item {
            padding: 12px 16px;
            border-radius: 8px;
            color: rgba(255,255,255,0.9);
            transition: all 0.3s;
            text-decoration: none;
            font-size: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .nav-item i {
            width: 24px;
            font-size: 18px;
        }
        
        .nav-item:hover,
        .nav-item.active {
            background: rgba(255,255,255,0.15);
            color: white;
            transform: translateX(5px);
        }
        
        .sidebar-footer {
            position: absolute;
            bottom: 20px;
            width: calc(100% - 40px);
        }
        
        .btn-logout {
            width: 100%;
            background: rgba(255,255,255,0.1);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
            padding: 12px;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            display: block;
            text-align: center;
            transition: all 0.3s;
        }
        
        .btn-logout:hover {
            background: var(--danger);
            border-color: var(--danger);
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
            margin: 0;
            color: var(--primary);
            font-size: 28px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .header-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(30, 60, 114, 0.3);
        }
        
        .btn-success {
            background: linear-gradient(135deg, var(--success), #34ce57);
            color: white;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #e04b5a);
            color: white;
        }
        
        .btn-warning {
            background: linear-gradient(135deg, var(--warning), #e0a800);
            color: #333;
        }
        
        .btn-info {
            background: linear-gradient(135deg, var(--info), #138496);
            color: white;
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: white;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 13px;
        }
        
        .btn-lg {
            padding: 12px 24px;
            font-size: 16px;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 30px;
            overflow: hidden;
        }
        
        .card-header {
            padding: 20px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
        }
        
        .card-header h3 {
            margin: 0;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .card-body {
            padding: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(30, 60, 114, 0.1);
            outline: none;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }
        
        .table thead {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
        }
        
        .table th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            font-size: 14px;
        }
        
        .table td {
            padding: 15px;
            border-bottom: 1px solid #e0e0e0;
            color: #333;
        }
        
        .table tbody tr:hover {
            background: #f8f9fa;
        }
        
        .items-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
            border: 2px dashed #dee2e6;
        }
        
        .item-row {
            display: grid;
            grid-template-columns: 3fr 1fr 1fr 1fr auto;
            gap: 15px;
            align-items: center;
            padding: 15px;
            background: white;
            border-radius: 8px;
            margin-bottom: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .item-row select,
        .item-row input {
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            width: 100%;
        }
        
        .item-row button {
            background: var(--danger);
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 6px;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .item-row button:hover {
            background: #c82333;
        }
        
        .btn-add-item {
            background: var(--success);
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            margin-top: 15px;
        }
        
        .resumo {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 20px;
            border-radius: 10px;
            margin-top: 20px;
        }
        
        .resumo-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 15px;
        }
        
        .resumo-row.total {
            border-top: 2px solid #adb5bd;
            padding-top: 15px;
            margin-top: 15px;
            font-weight: bold;
            font-size: 18px;
            color: var(--success);
        }
        
        .badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-pendente { background: #fff3cd; color: #856404; }
        .badge-em_andamento { background: #cce5ff; color: #004085; }
        .badge-finalizado { background: #d4edda; color: #155724; }
        .badge-cancelado { background: #f8d7da; color: #721c24; }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
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
        
        .pagination {
            margin-top: 30px;
            text-align: center;
        }
        
        .pagination a {
            display: inline-block;
            padding: 8px 16px;
            margin: 0 4px;
            background: white;
            color: var(--primary);
            text-decoration: none;
            border-radius: 6px;
            border: 1px solid #dee2e6;
            transition: all 0.3s;
        }
        
        .pagination a:hover,
        .pagination a.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            gap: 15px;
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .stat-info h3 {
            margin: 0;
            font-size: 24px;
            font-weight: bold;
        }
        
        .stat-info p {
            margin: 5px 0 0;
            color: #666;
            font-size: 14px;
        }
        
        .filters {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
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
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .item-row {
                grid-template-columns: 1fr;
            }
            
            .header-actions {
                flex-direction: column;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
         <!-- INCLUIR SIDEBAR -->
        <?php include __DIR__ . '/includes/sidebar.php'; ?>


         <main class="main-content">
            
            <?php if ($mensagem): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($mensagem); ?>
            </div>
            <?php endif; ?>

            <?php if ($erro): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($erro); ?>
            </div>
            <?php endif; ?>

            <?php if ($acao === 'listar'): ?>
                
                <div class="page-header">
                    <h1>
                        <i class="fas fa-shopping-cart"></i>
                        Gerenciamento de Pedidos
                    </h1>
                    <div class="header-actions">
                        <a href="?acao=novo" class="btn btn-primary">
                            <i class="fas fa-plus-circle"></i> Novo Pedido
                        </a>
                    </div>
                </div>

                <!-- Stats Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #fff3cd; color: #856404;">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['pendente'] ?? 0; ?></h3>
                            <p>Pendentes</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #cce5ff; color: #004085;">
                            <i class="fas fa-spinner"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['em_andamento'] ?? 0; ?></h3>
                            <p>Em Andamento</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #d4edda; color: #155724;">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['finalizado'] ?? 0; ?></h3>
                            <p>Finalizados</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #f8d7da; color: #721c24;">
                            <i class="fas fa-times-circle"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['cancelado'] ?? 0; ?></h3>
                            <p>Cancelados</p>
                        </div>
                    </div>
                </div>

                <!-- Filtros -->
                <div class="filters">
                    <form method="GET" class="filter-row">
                        <input type="hidden" name="acao" value="listar">
                        
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" class="form-control">
                                <option value="">Todos</option>
                                <option value="pendente" <?php echo ($filtro_status ?? '') == 'pendente' ? 'selected' : ''; ?>>Pendente</option>
                                <option value="em_andamento" <?php echo ($filtro_status ?? '') == 'em_andamento' ? 'selected' : ''; ?>>Em Andamento</option>
                                <option value="finalizado" <?php echo ($filtro_status ?? '') == 'finalizado' ? 'selected' : ''; ?>>Finalizado</option>
                                <option value="cancelado" <?php echo ($filtro_status ?? '') == 'cancelado' ? 'selected' : ''; ?>>Cancelado</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Cliente</label>
                            <input type="text" name="cliente" class="form-control" placeholder="Nome ou telefone" value="<?php echo htmlspecialchars($filtro_cliente ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label>Mês</label>
                            <select name="mes" class="form-control">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?php echo $m; ?>" <?php echo ($filtro_mes ?? date('m')) == $m ? 'selected' : ''; ?>>
                                        <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Ano</label>
                            <select name="ano" class="form-control">
                                <?php for ($a = date('Y'); $a >= 2020; $a--): ?>
                                    <option value="<?php echo $a; ?>" <?php echo ($filtro_ano ?? date('Y')) == $a ? 'selected' : ''; ?>>
                                        <?php echo $a; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary" style="width: 100%;">
                                <i class="fas fa-filter"></i> Filtrar
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Lista de Pedidos -->
                <?php if (!empty($pedidos)): ?>
                <div class="card">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Número</th>
                                    <th>Cliente</th>
                                    <th>Data</th>
                                    <th>Status</th>
                                    <th>Produtos</th>
                                    <th>Serviços</th>
                                    <th>Valor Total</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pedidos as $item): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($item['numero'] ?? '#' . $item['id']); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($item['cliente_nome']); ?></td>
                                    <td><?php echo formatarData($item['data_pedido']); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $item['situacao']; ?>">
                                            <?php 
                                            $status = [
                                                'pendente' => 'Pendente',
                                                'em_andamento' => 'Em Andamento',
                                                'finalizado' => 'Finalizado',
                                                'cancelado' => 'Cancelado'
                                            ];
                                            echo $status[$item['situacao']] ?? ucfirst($item['situacao']); 
                                            ?>
                                        </span>
                                    </td>
                                    <td><?php echo count($item['itens_produtos'] ?? []); ?></td>
                                    <td><?php echo count($item['itens_servicos'] ?? []); ?></td>
                                    <td><strong class="text-success"><?php echo formatarMoeda($item['valor_total']); ?></strong></td>
                                    <td>
                                        <div class="btn-group">
                                            <a href="?acao=editar&id=<?php echo $item['id']; ?>" class="btn btn-sm btn-primary" title="Editar">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            
                                            <?php if ($item['situacao'] != 'finalizado' && $item['situacao'] != 'cancelado'): ?>
                                                <a href="?acao=finalizar&id=<?php echo $item['id']; ?>" class="btn btn-sm btn-success" title="Finalizar" onclick="return confirm('Confirmar finalização?')">
                                                    <i class="fas fa-check"></i>
                                                </a>
                                                <a href="?acao=cancelar&id=<?php echo $item['id']; ?>" class="btn btn-sm btn-danger" title="Cancelar" onclick="return confirm('Cancelar este pedido?')">
                                                    <i class="fas fa-times"></i>
                                                </a>
                                            <?php endif; ?>
                                            
                                            <a href="#" class="btn btn-sm btn-info" title="Detalhes" onclick="alert('Em desenvolvimento')">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            
                                            <a href="?acao=deletar&id=<?php echo $item['id']; ?>" class="btn btn-sm btn-danger" title="Excluir" onclick="return confirm('Tem certeza? Esta ação não pode ser desfeita.')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Paginação -->
                <?php if ($total_paginas > 1): ?>
                <div class="pagination">
                    <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
                    <a href="?pagina=<?php echo $i; ?>&status=<?php echo urlencode($filtro_status ?? ''); ?>&cliente=<?php echo urlencode($filtro_cliente ?? ''); ?>&mes=<?php echo $filtro_mes ?? date('m'); ?>&ano=<?php echo $filtro_ano ?? date('Y'); ?>" 
                       class="<?php echo $i == $pagina_atual ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>

                <?php else: ?>
                <div class="empty-message" style="text-align: center; padding: 50px; background: white; border-radius: 12px;">
                    <i class="fas fa-shopping-cart fa-4x" style="color: #ccc; margin-bottom: 20px;"></i>
                    <h3 style="color: #666; margin-bottom: 10px;">Nenhum pedido encontrado</h3>
                    <p style="color: #999; margin-bottom: 20px;">Comece criando um novo pedido com produtos ou serviços.</p>
                    <a href="?acao=novo" class="btn btn-primary">
                        <i class="fas fa-plus-circle"></i> Novo Pedido
                    </a>
                </div>
                <?php endif; ?>

            <?php elseif ($acao === 'novo' || $acao === 'editar'): ?>

                <div class="page-header">
                    <h1>
                        <i class="fas fa-<?php echo $acao === 'novo' ? 'plus-circle' : 'edit'; ?>"></i>
                        <?php echo $acao === 'novo' ? 'Novo Pedido' : 'Editar Pedido #' . ($pedido['numero'] ?? $id); ?>
                    </h1>
                    <div class="header-actions">
                        <a href="?acao=listar" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Voltar
                        </a>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-shopping-cart"></i>
                            Informações do Pedido
                        </h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="form-pedido">
                            <input type="hidden" name="acao" value="salvar">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <?php if ($acao === 'editar'): ?>
                                <input type="hidden" name="id" value="<?php echo $pedido['id']; ?>">
                            <?php endif; ?>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Cliente *</label>
                                    <select name="cliente_id" required class="form-control">
                                        <option value="">-- Selecione um cliente --</option>
                                        <?php foreach ($clientes as $cli): ?>
                                        <option value="<?php echo $cli['id']; ?>" 
                                                <?php echo ($pedido['cliente_id'] ?? '') == $cli['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cli['nome'] . ' - ' . $cli['telefone']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label>Data do Pedido</label>
                                    <input type="date" name="data_pedido" value="<?php echo $pedido['data_pedido'] ?? date('Y-m-d'); ?>" class="form-control">
                                </div>

                                <div class="form-group">
                                    <label>Situação</label>
                                    <select name="situacao" class="form-control">
                                        <option value="pendente" <?php echo ($pedido['situacao'] ?? '') == 'pendente' ? 'selected' : ''; ?>>Pendente</option>
                                        <option value="em_andamento" <?php echo ($pedido['situacao'] ?? '') == 'em_andamento' ? 'selected' : ''; ?>>Em Andamento</option>
                                        <option value="finalizado" <?php echo ($pedido['situacao'] ?? '') == 'finalizado' ? 'selected' : ''; ?>>Finalizado</option>
                                        <option value="cancelado" <?php echo ($pedido['situacao'] ?? '') == 'cancelado' ? 'selected' : ''; ?>>Cancelado</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Observações</label>
                                <textarea name="observacao" rows="3" class="form-control" placeholder="Observações do pedido..."><?php echo htmlspecialchars($pedido['observacao'] ?? ''); ?></textarea>
                            </div>

                            <h3 style="margin: 30px 0 20px; color: var(--primary);">
                                <i class="fas fa-box"></i> Produtos
                            </h3>
                            <div class="items-section">
                                <div id="produtos-container">
                                    <?php if (!empty($pedido['itens_produtos'])): ?>
                                        <?php foreach ($pedido['itens_produtos'] as $index => $item): ?>
                                        <div class="item-row" id="produto-row-<?php echo $index; ?>">
                                            <select name="produtos[]" class="produto-select" onchange="atualizarValorProduto(this)">
                                                <option value="">-- Selecione um produto --</option>
                                                <?php foreach ($produtos as $p): ?>
                                                <option value="<?php echo $p['id']; ?>" 
                                                        data-valor="<?php echo $p['valor_venda']; ?>"
                                                        data-custo="<?php echo $p['valor_compra']; ?>"
                                                        <?php echo ($item['produto_id'] ?? $item['id']) == $p['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($p['nome'] . ' (R$ ' . number_format($p['valor_venda'], 2, ',', '.') . ')'); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="number" name="qtd_produtos[]" value="<?php echo $item['quantidade']; ?>" min="1" step="1" class="form-control" onchange="calcularTotal()">
                                            <input type="text" name="valor_produtos[]" value="<?php echo number_format($item['valor_unitario'], 2, ',', '.'); ?>" class="form-control money" onchange="calcularTotal()">
                                            <input type="text" name="subtotal_produtos[]" value="<?php echo number_format($item['subtotal'], 2, ',', '.'); ?>" class="form-control" readonly>
                                            <button type="button" onclick="removerItem(this, 'produto')" class="btn btn-sm btn-danger">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <button type="button" onclick="adicionarProduto()" class="btn-add-item">
                                    <i class="fas fa-plus-circle"></i> Adicionar Produto
                                </button>
                            </div>

                            <h3 style="margin: 30px 0 20px; color: var(--primary);">
                                <i class="fas fa-tools"></i> Serviços
                            </h3>
                            <div class="items-section">
                                <div id="servicos-container">
                                    <?php if (!empty($pedido['itens_servicos'])): ?>
                                        <?php foreach ($pedido['itens_servicos'] as $index => $item): ?>
                                        <div class="item-row" id="servico-row-<?php echo $index; ?>">
                                            <select name="servicos[]" class="servico-select" onchange="atualizarValorServico(this)">
                                                <option value="">-- Selecione um serviço --</option>
                                                <?php foreach ($servicos as $s): ?>
                                                <option value="<?php echo $s['id']; ?>" 
                                                        data-valor="<?php echo $s['valor_unitario']; ?>"
                                                        <?php echo ($item['servico_id'] ?? $item['id']) == $s['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($s['nome'] . ' (R$ ' . number_format($s['valor_unitario'], 2, ',', '.') . ')'); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="number" name="qtd_servicos[]" value="<?php echo $item['quantidade']; ?>" min="1" step="1" class="form-control" onchange="calcularTotal()">
                                            <input type="text" name="valor_servicos[]" value="<?php echo number_format($item['valor_unitario'], 2, ',', '.'); ?>" class="form-control money" onchange="calcularTotal()">
                                            <input type="text" name="subtotal_servicos[]" value="<?php echo number_format($item['subtotal'], 2, ',', '.'); ?>" class="form-control" readonly>
                                            <button type="button" onclick="removerItem(this, 'servico')" class="btn btn-sm btn-danger">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <button type="button" onclick="adicionarServico()" class="btn-add-item">
                                    <i class="fas fa-plus-circle"></i> Adicionar Serviço
                                </button>
                            </div>

                            <h3 style="margin: 30px 0 20px; color: var(--primary);">
                                <i class="fas fa-calculator"></i> Totais
                            </h3>
                            <div class="resumo">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Desconto (%)</label>
                                        <input type="number" name="desconto_percentual" id="desconto_percentual" 
                                               value="<?php echo $pedido['desconto_percentual'] ?? 0; ?>" 
                                               min="0" max="100" step="0.01" class="form-control" onchange="calcularTotal()">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Valor Adicional (R$)</label>
                                        <input type="text" name="valor_adicional" id="valor_adicional" 
                                               value="<?php echo isset($pedido['valor_adicional']) ? number_format($pedido['valor_adicional'], 2, ',', '.') : '0,00'; ?>" 
                                               class="form-control money" onchange="calcularTotal()">
                                    </div>
                                </div>

                                <div class="resumo-row">
                                    <span>Subtotal Produtos:</span>
                                    <span id="subtotal-produtos">R$ 0,00</span>
                                </div>
                                <div class="resumo-row">
                                    <span>Subtotal Serviços:</span>
                                    <span id="subtotal-servicos">R$ 0,00</span>
                                </div>
                                <div class="resumo-row">
                                    <span>Desconto:</span>
                                    <span id="desconto-valor">-R$ 0,00</span>
                                </div>
                                <div class="resumo-row">
                                    <span>Adicional:</span>
                                    <span id="adicional-valor">R$ 0,00</span>
                                </div>
                                <div class="resumo-row total">
                                    <span>Total Geral:</span>
                                    <span id="valor-total">R$ 0,00</span>
                                </div>
                            </div>

                            <div style="margin-top: 30px; display: flex; gap: 15px; justify-content: flex-end;">
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="fas fa-save"></i> Salvar Pedido
                                </button>
                                <a href="?acao=listar" class="btn btn-secondary btn-lg">
                                    <i class="fas fa-times"></i> Cancelar
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- JavaScript para cálculos -->
                <script>
                    const produtos = <?php echo json_encode($produtos); ?>;
                    const servicos = <?php echo json_encode($servicos); ?>;

                    function adicionarProduto() {
                        const container = document.getElementById('produtos-container');
                        const index = container.children.length;
                        const div = document.createElement('div');
                        div.className = 'item-row';
                        div.id = 'produto-row-' + index;
                        
                        let html = '<select name="produtos[]" class="produto-select" onchange="atualizarValorProduto(this)">';
                        html += '<option value="">-- Selecione um produto --</option>';
                        produtos.forEach(p => {
                            html += '<option value="' + p.id + '" data-valor="' + p.valor_venda + '" data-custo="' + (p.valor_compra || 0) + '">';
                            html += p.nome + ' (R$ ' + parseFloat(p.valor_venda).toFixed(2).replace('.', ',') + ')';
                            html += '</option>';
                        });
                        html += '</select>';
                        html += '<input type="number" name="qtd_produtos[]" value="1" min="1" step="1" class="form-control" onchange="calcularTotal()">';
                        html += '<input type="text" name="valor_produtos[]" value="0,00" class="form-control money" onchange="calcularTotal()">';
                        html += '<input type="text" name="subtotal_produtos[]" value="0,00" class="form-control" readonly>';
                        html += '<button type="button" onclick="removerItem(this, \'produto\')" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>';
                        
                        div.innerHTML = html;
                        container.appendChild(div);
                        
                        aplicarMascaraMonetaria(div.querySelector('.money'));
                    }

                    function adicionarServico() {
                        const container = document.getElementById('servicos-container');
                        const index = container.children.length;
                        const div = document.createElement('div');
                        div.className = 'item-row';
                        div.id = 'servico-row-' + index;
                        
                        let html = '<select name="servicos[]" class="servico-select" onchange="atualizarValorServico(this)">';
                        html += '<option value="">-- Selecione um serviço --</option>';
                        servicos.forEach(s => {
                            html += '<option value="' + s.id + '" data-valor="' + s.valor_unitario + '">';
                            html += s.nome + ' (R$ ' + parseFloat(s.valor_unitario).toFixed(2).replace('.', ',') + ')';
                            html += '</option>';
                        });
                        html += '</select>';
                        html += '<input type="number" name="qtd_servicos[]" value="1" min="1" step="1" class="form-control" onchange="calcularTotal()">';
                        html += '<input type="text" name="valor_servicos[]" value="0,00" class="form-control money" onchange="calcularTotal()">';
                        html += '<input type="text" name="subtotal_servicos[]" value="0,00" class="form-control" readonly>';
                        html += '<button type="button" onclick="removerItem(this, \'servico\')" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>';
                        
                        div.innerHTML = html;
                        container.appendChild(div);
                        
                        aplicarMascaraMonetaria(div.querySelector('.money'));
                    }

                    function removerItem(botao, tipo) {
                        if (confirm('Remover este item?')) {
                            botao.closest('.item-row').remove();
                            calcularTotal();
                        }
                    }

                    function atualizarValorProduto(select) {
                        const valor = select.options[select.selectedIndex]?.getAttribute('data-valor');
                        const row = select.closest('.item-row');
                        const valorInput = row.querySelector('input[name="valor_produtos[]"]');
                        
                        if (valor) {
                            valorInput.value = parseFloat(valor).toFixed(2).replace('.', ',');
                        }
                        calcularTotal();
                    }

                    function atualizarValorServico(select) {
                        const valor = select.options[select.selectedIndex]?.getAttribute('data-valor');
                        const row = select.closest('.item-row');
                        const valorInput = row.querySelector('input[name="valor_servicos[]"]');
                        
                        if (valor) {
                            valorInput.value = parseFloat(valor).toFixed(2).replace('.', ',');
                        }
                        calcularTotal();
                    }

                    function calcularTotal() {
                        let totalProdutos = 0;
                        let totalServicos = 0;
                        
                        document.querySelectorAll('#produtos-container .item-row').forEach(row => {
                            const select = row.querySelector('select');
                            if (select && select.value) {
                                const qtd = parseFloat(row.querySelector('input[name="qtd_produtos[]"]').value) || 0;
                                const valorStr = row.querySelector('input[name="valor_produtos[]"]').value;
                                const valor = parseFloat(valorStr.replace(/[^\d,]/g, '').replace(',', '.')) || 0;
                                const subtotal = qtd * valor;
                                
                                totalProdutos += subtotal;
                                
                                const subtotalInput = row.querySelector('input[name="subtotal_produtos[]"]');
                                if (subtotalInput) {
                                    subtotalInput.value = subtotal.toFixed(2).replace('.', ',');
                                }
                            }
                        });
                        
                        document.querySelectorAll('#servicos-container .item-row').forEach(row => {
                            const select = row.querySelector('select');
                            if (select && select.value) {
                                const qtd = parseFloat(row.querySelector('input[name="qtd_servicos[]"]').value) || 0;
                                const valorStr = row.querySelector('input[name="valor_servicos[]"]').value;
                                const valor = parseFloat(valorStr.replace(/[^\d,]/g, '').replace(',', '.')) || 0;
                                const subtotal = qtd * valor;
                                
                                totalServicos += subtotal;
                                
                                const subtotalInput = row.querySelector('input[name="subtotal_servicos[]"]');
                                if (subtotalInput) {
                                    subtotalInput.value = subtotal.toFixed(2).replace('.', ',');
                                }
                            }
                        });
                        
                        document.getElementById('subtotal-produtos').textContent = 'R$ ' + totalProdutos.toFixed(2).replace('.', ',');
                        document.getElementById('subtotal-servicos').textContent = 'R$ ' + totalServicos.toFixed(2).replace('.', ',');
                        
                        const subtotalGeral = totalProdutos + totalServicos;
                        
                        const descontoPercentual = parseFloat(document.getElementById('desconto_percentual')?.value) || 0;
                        const descontoValor = subtotalGeral * (descontoPercentual / 100);
                        
                        const adicionalStr = document.getElementById('valor_adicional')?.value || '0,00';
                        const adicional = parseFloat(adicionalStr.replace(/[^\d,]/g, '').replace(',', '.')) || 0;
                        
                        const totalGeral = (subtotalGeral - descontoValor) + adicional;
                        
                        document.getElementById('desconto-valor').textContent = '-R$ ' + descontoValor.toFixed(2).replace('.', ',');
                        document.getElementById('adicional-valor').textContent = 'R$ ' + adicional.toFixed(2).replace('.', ',');
                        document.getElementById('valor-total').textContent = 'R$ ' + totalGeral.toFixed(2).replace('.', ',');
                    }

                    function aplicarMascaraMonetaria(input) {
                        if (!input) return;
                        
                        input.addEventListener('input', function(e) {
                            let value = e.target.value.replace(/\D/g, '');
                            
                            if (value === '') {
                                e.target.value = '0,00';
                                return;
                            }
                            
                            if (value.length > 2) {
                                const reais = value.slice(0, -2);
                                const centavos = value.slice(-2);
                                value = reais + ',' + centavos;
                            } else if (value.length === 2) {
                                value = '0,' + value;
                            } else if (value.length === 1) {
                                value = '0,0' + value;
                            }
                            
                            value = value.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                            e.target.value = value;
                            
                            calcularTotal();
                        });
                    }

                    document.addEventListener('DOMContentLoaded', function() {
                        document.querySelectorAll('.money').forEach(input => {
                            aplicarMascaraMonetaria(input);
                        });
                        calcularTotal();
                    });
                </script>

            <?php endif; ?>

        </main>
    </div>
</body>
</html>
