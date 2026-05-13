<?php
/**
 * =====================================================================
 * VENDAS - SISTEMA DE GESTÃO IMPÉRIO AR
 * =====================================================================
 * 
 * Responsabilidade: Gerenciar vendas realizadas
 * Baseado na estrutura da tabela `vendas` do banco de dados
 * 
 * Funcionalidades:
 * - Listar vendas com filtros
 * - Registrar nova venda
 * - Editar venda existente
 * - Visualizar detalhes
 * - Atualizar status
 * - Gerar recibo/comprovante
 * 
 * Tabela: vendas
 * Campos: id, numero, cliente_id, data_venda, situacao, observacao,
 *         desconto_percentual, valor_adicional, valor_total, 
 *         valor_custo, valor_lucro, created_at, updated_at
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
$vendas = [];
$clientes = [];
$venda = [];
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

function calcularMargemLucro($valor_total, $valor_custo) {
    if ($valor_total <= 0) return 0;
    return (($valor_total - $valor_custo) / $valor_total) * 100;
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

// Carregar dados
$clientes = carregarClientes($conexao);

// ===== VERIFICAR SE EXISTEM TABELAS DE ITENS DE VENDA =====
// (Opcional - se você quiser adicionar produtos/serviços às vendas no futuro)
function verificarTabelasItens($conexao) {
    $tabelas_existentes = [];
    
    // Verifica se existe tabela de produtos de venda
    $result = $conexao->query("SHOW TABLES LIKE 'venda_produtos'");
    if ($result->num_rows > 0) {
        $tabelas_existentes[] = 'venda_produtos';
    }
    
    // Verifica se existe tabela de serviços de venda
    $result = $conexao->query("SHOW TABLES LIKE 'venda_servicos'");
    if ($result->num_rows > 0) {
        $tabelas_existentes[] = 'venda_servicos';
    }
    
    return $tabelas_existentes;
}

$tabelas_itens = verificarTabelasItens($conexao);

// ===== FUNÇÕES PARA ITENS DA VENDA (se as tabelas existirem) =====
function buscarItensProdutos($conexao, $venda_id) {
    $itens = [];
    // Verifica se tabela existe antes de consultar
    $result = $conexao->query("SHOW TABLES LIKE 'venda_produtos'");
    if ($result->num_rows > 0) {
        $sql = "SELECT vp.*, p.nome, p.valor_venda 
                FROM venda_produtos vp 
                JOIN produtos p ON vp.produto_id = p.id 
                WHERE vp.venda_id = ?";
        $stmt = $conexao->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $venda_id);
            $stmt->execute();
            $resultado = $stmt->get_result();
            while ($row = $resultado->fetch_assoc()) {
                $itens[] = $row;
            }
            $stmt->close();
        }
    }
    return $itens;
}

function buscarItensServicos($conexao, $venda_id) {
    $itens = [];
    // Verifica se tabela existe antes de consultar
    $result = $conexao->query("SHOW TABLES LIKE 'venda_servicos'");
    if ($result->num_rows > 0) {
        $sql = "SELECT vs.*, s.nome, s.valor_unitario 
                FROM venda_servicos vs 
                JOIN servicos s ON vs.servico_id = s.id 
                WHERE vs.venda_id = ?";
        $stmt = $conexao->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $venda_id);
            $stmt->execute();
            $resultado = $stmt->get_result();
            while ($row = $resultado->fetch_assoc()) {
                $itens[] = $row;
            }
            $stmt->close();
        }
    }
    return $itens;
}

// ===== PROCESSAR AÇÕES POST =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verificarCSRF($_POST['csrf_token'])) {
        $erro = "Token de segurança inválido.";
    } else {
        $acao_post = $_POST['acao'] ?? '';
        
        // ===== SALVAR VENDA =====
        if ($acao_post === 'salvar') {
            $cliente_id = isset($_POST['cliente_id']) ? intval($_POST['cliente_id']) : 0;
            $data_venda = $_POST['data_venda'] ?? date('Y-m-d');
            $observacao = $conexao->real_escape_string($_POST['observacao'] ?? '');
            $situacao = trim($_POST['situacao'] ?? 'finalizado');
            $desconto_percentual = floatval($_POST['desconto_percentual'] ?? 0);
            $valor_adicional = moedaParaFloat($_POST['valor_adicional'] ?? '0,00');
            $valor_total = moedaParaFloat($_POST['valor_total'] ?? '0,00');
            $valor_custo = moedaParaFloat($_POST['valor_custo'] ?? '0,00');
            
            $id_editar = isset($_POST['id']) ? intval($_POST['id']) : null;
            
            // Calcula lucro
            $valor_lucro = $valor_total - $valor_custo;
            
            if ($cliente_id <= 0) {
                $erro = "Cliente é obrigatório";
            } elseif ($valor_total <= 0) {
                $erro = "Valor total deve ser maior que zero";
            } else {
                if ($id_editar) {
                    // UPDATE
                    $sql = "UPDATE vendas SET 
                            cliente_id = ?,
                            data_venda = ?,
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
                        $data_venda,
                        $observacao,
                        $situacao,
                        $desconto_percentual,
                        $valor_adicional,
                        $valor_total,
                        $valor_custo,
                        $valor_lucro,
                        $id_editar
                    );
                    
                    if ($stmt->execute()) {
                        $mensagem = "Venda atualizada com sucesso!";
                    } else {
                        $erro = "Erro ao atualizar venda: " . $stmt->error;
                    }
                    $stmt->close();
                    
                } else {
                    // INSERT
                    $numero = 'VND-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                    
                    $sql = "INSERT INTO vendas (numero, cliente_id, data_venda, situacao, observacao, desconto_percentual, valor_adicional, valor_total, valor_custo, valor_lucro) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    
                    $stmt = $conexao->prepare($sql);
                    $stmt->bind_param(
                        "sissdddddd",
                        $numero,
                        $cliente_id,
                        $data_venda,
                        $situacao,
                        $observacao,
                        $desconto_percentual,
                        $valor_adicional,
                        $valor_total,
                        $valor_custo,
                        $valor_lucro
                    );
                    
                    if ($stmt->execute()) {
                        $novo_id = $conexao->insert_id;
                        
                        // Registra no financeiro se for uma venda finalizada
                        if ($situacao == 'finalizado') {
                            $financeiro->registrarEntrada([
                                'valor' => $valor_total,
                                'descricao' => "Venda #$novo_id",
                                'cliente_id' => $cliente_id,
                                'data' => $data_venda
                            ]);
                        }
                        
                        header('Location: ' . BASE_URL . '/app/admin/vendas.php?mensagem=criado');
                        exit;
                    } else {
                        $erro = "Erro ao criar venda: " . $stmt->error;
                    }
                    $stmt->close();
                }
            }
        }
        
        // ===== ATUALIZAR STATUS =====
        if ($acao_post === 'atualizar_status' && isset($_POST['id'])) {
            $venda_id = intval($_POST['id']);
            $novo_status = $_POST['status'] ?? '';
            
            $status_validos = ['pendente', 'em_andamento', 'finalizado', 'cancelado'];
            if (in_array($novo_status, $status_validos)) {
                $sql = "UPDATE vendas SET situacao = ? WHERE id = ?";
                $stmt = $conexao->prepare($sql);
                $stmt->bind_param("si", $novo_status, $venda_id);
                
                if ($stmt->execute()) {
                    $mensagem = "Status atualizado com sucesso!";
                    
                    // Se for finalizado, registra no financeiro
                    if ($novo_status == 'finalizado') {
                        $venda = $conexao->query("SELECT * FROM vendas WHERE id = $venda_id")->fetch_assoc();
                        $financeiro->registrarEntrada([
                            'valor' => $venda['valor_total'],
                            'descricao' => "Venda #$venda_id",
                            'cliente_id' => $venda['cliente_id'],
                            'data' => $venda['data_venda']
                        ]);
                    }
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
    // Busca TODAS as cobranças vinculadas a esta venda
    $check_cobrancas = $conexao->query("SELECT id, numero, valor, status FROM cobrancas WHERE venda_id = $id");
    
    if ($check_cobrancas->num_rows > 0) {
        $cobrancas_lista = [];
        while ($cob = $check_cobrancas->fetch_assoc()) {
            $status_texto = $cob['status'] ?? 'desconhecido';
            $numero_texto = $cob['numero'] ?? '#' . $cob['id'];
            $cobrancas_lista[] = "{$numero_texto} (R$ " . number_format($cob['valor'], 2, ',', '.') . ") - Status: {$status_texto}";
        }
        
        $lista_html = "<ul style='margin-top:10px; margin-left:20px;'>";
        foreach ($cobrancas_lista as $item) {
            $lista_html .= "<li>{$item}</li>";
        }
        $lista_html .= "</ul>";
        
        $erro = "Não é possível excluir esta venda pois existem as seguintes cobranças vinculadas: {$lista_html}";
    } else {
        $sql = "DELETE FROM vendas WHERE id = ?";
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            header('Location: ' . BASE_URL . '/app/admin/vendas.php?mensagem=deletado');
            exit;
        } else {
            $erro = "Erro ao deletar venda";
        }
        $stmt->close();
    }
}

if ($acao === 'finalizar' && $id) {
    $conexao->begin_transaction();
    
    try {
        $sql = "UPDATE vendas SET situacao = 'finalizado' WHERE id = ?";
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        
        // Busca dados da venda para registrar no financeiro
        $venda = $conexao->query("SELECT * FROM vendas WHERE id = $id")->fetch_assoc();
        $financeiro->registrarEntrada([
            'valor' => $venda['valor_total'],
            'descricao' => "Venda #$id",
            'cliente_id' => $venda['cliente_id'],
            'data' => $venda['data_venda']
        ]);
        
        $conexao->commit();
        header('Location: ' . BASE_URL . '/app/admin/vendas.php?mensagem=finalizado');
        exit;
        
    } catch (Exception $e) {
        $conexao->rollback();
        $erro = "Erro ao finalizar venda: " . $e->getMessage();
    }
}

if ($acao === 'cancelar' && $id) {
    $sql = "UPDATE vendas SET situacao = 'cancelado' WHERE id = ?";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        header('Location: ' . BASE_URL . '/app/admin/vendas.php?mensagem=cancelado');
        exit;
    }
    $stmt->close();
}

if ($acao === 'recibo' && $id) {
    // Redireciona para a página de recibo com os dados da venda
    header('Location: ' . BASE_URL . '/app/admin/orcamentos.php?acao=recibo&id=' . $id . '&tipo=venda');
    exit;
}

// ===== LISTAR VENDAS =====
if ($acao === 'listar') {
    $filtro_status = $_GET['status'] ?? '';
    $filtro_cliente = $_GET['cliente'] ?? '';
    $filtro_mes = $_GET['mes'] ?? date('m');
    $filtro_ano = $_GET['ano'] ?? date('Y');
    $filtro_data_inicio = $_GET['data_inicio'] ?? '';
    $filtro_data_fim = $_GET['data_fim'] ?? '';
    
    $offset = ($pagina_atual - 1) * $por_pagina;
    
    $sql = "SELECT v.*, c.nome as cliente_nome, c.telefone as cliente_telefone 
            FROM vendas v 
            LEFT JOIN clientes c ON v.cliente_id = c.id 
            WHERE 1=1";
    $params = [];
    $types = "";
    
    if ($filtro_status) {
        $sql .= " AND v.situacao = ?";
        $params[] = $filtro_status;
        $types .= "s";
    }
    
    if ($filtro_cliente) {
        $sql .= " AND (c.nome LIKE ? OR c.telefone LIKE ?)";
        $params[] = "%$filtro_cliente%";
        $params[] = "%$filtro_cliente%";
        $types .= "ss";
    }
    
    // Filtro por período
    if (!empty($filtro_data_inicio) && !empty($filtro_data_fim)) {
        $sql .= " AND DATE(v.data_venda) BETWEEN ? AND ?";
        $params[] = $filtro_data_inicio;
        $params[] = $filtro_data_fim;
        $types .= "ss";
    } else {
        $sql .= " AND MONTH(v.data_venda) = ? AND YEAR(v.data_venda) = ?";
        $params[] = $filtro_mes;
        $params[] = $filtro_ano;
        $types .= "ii";
    }
    
    $sql .= " ORDER BY v.data_venda DESC LIMIT ? OFFSET ?";
    $params[] = $por_pagina;
    $params[] = $offset;
    $types .= "ii";
    
    $stmt = $conexao->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    $vendas = [];
    while ($linha = $resultado->fetch_assoc()) {
        // Se existirem tabelas de itens, busca os itens
        if (!empty($tabelas_itens)) {
            $linha['itens_produtos'] = buscarItensProdutos($conexao, $linha['id']);
            $linha['itens_servicos'] = buscarItensServicos($conexao, $linha['id']);
        }
        $vendas[] = $linha;
    }
    $stmt->close();
    
    // Total para paginação
    $sql_total = "SELECT COUNT(*) as total FROM vendas v WHERE 1=1";
    if ($filtro_status) $sql_total .= " AND v.situacao = '$filtro_status'";
    $resultado_total = $conexao->query($sql_total);
    $total = $resultado_total->fetch_assoc()['total'];
    $total_paginas = ceil($total / $por_pagina);
    
    // Estatísticas
    $stats = [
        'pendente' => 0,
        'em_andamento' => 0,
        'finalizado' => 0,
        'cancelado' => 0,
        'total_vendas' => 0,
        'total_lucro' => 0
    ];
    
    $sql_stats = "SELECT situacao, COUNT(*) as total, SUM(valor_total) as soma_total, SUM(valor_lucro) as soma_lucro
                  FROM vendas 
                  WHERE MONTH(data_venda) = ? AND YEAR(data_venda) = ? 
                  GROUP BY situacao";
    $stmt_stats = $conexao->prepare($sql_stats);
    $stmt_stats->bind_param("ii", $filtro_mes, $filtro_ano);
    $stmt_stats->execute();
    $result_stats = $stmt_stats->get_result();
    while ($row = $result_stats->fetch_assoc()) {
        if (isset($stats[$row['situacao']])) {
            $stats[$row['situacao']] = $row['total'];
        }
        $stats['total_vendas'] += $row['soma_total'];
        $stats['total_lucro'] += $row['soma_lucro'];
    }
    $stmt_stats->close();
    
    // Mensagens
    if (isset($_GET['mensagem'])) {
        $mapa = [
            'criado' => "✓ Venda criada com sucesso!",
            'atualizado' => "✓ Venda atualizada com sucesso!",
            'deletado' => "✓ Venda deletada com sucesso!",
            'finalizado' => "✓ Venda finalizada com sucesso!",
            'cancelado' => "✓ Venda cancelada com sucesso!"
        ];
        $mensagem = $mapa[$_GET['mensagem']] ?? '';
    }
}

// ===== CARREGAR VENDA PARA EDIÇÃO =====
if ($acao === 'editar' && $id) {
    $sql = "SELECT v.*, c.nome as cliente_nome 
            FROM vendas v 
            LEFT JOIN clientes c ON v.cliente_id = c.id 
            WHERE v.id = ?";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $venda = $resultado->fetch_assoc();
    $stmt->close();
    
    if (!$venda) {
        header('Location: ' . BASE_URL . '/app/admin/vendas.php');
        exit;
    }
    
    // Se existirem tabelas de itens, busca os itens
    if (!empty($tabelas_itens)) {
        $venda['itens_produtos'] = buscarItensProdutos($conexao, $id);
        $venda['itens_servicos'] = buscarItensServicos($conexao, $id);
    }
}

// ===== NOVA VENDA =====
if ($acao === 'novo') {
    $venda = [
        'id' => '',
        'cliente_id' => '',
        'data_venda' => date('Y-m-d'),
        'situacao' => 'finalizado',
        'observacao' => '',
        'desconto_percentual' => 0,
        'valor_adicional' => 0,
        'valor_total' => 0,
        'valor_custo' => 0,
        'valor_lucro' => 0
    ];
}

// ===== VISUALIZAR VENDA =====
if ($acao === 'visualizar' && $id) {
    $sql = "SELECT v.*, c.nome as cliente_nome, c.cpf_cnpj, c.telefone, c.email, c.whatsapp,
                   c.endereco_rua, c.endereco_numero, c.endereco_bairro, c.endereco_cidade, c.endereco_estado
            FROM vendas v 
            LEFT JOIN clientes c ON v.cliente_id = c.id 
            WHERE v.id = ?";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $venda = $resultado->fetch_assoc();
    $stmt->close();
    
    if (!$venda) {
        header('Location: ' . BASE_URL . '/app/admin/vendas.php');
        exit;
    }
    
    // Busca cobranças relacionadas
    $sql_cobrancas = "SELECT * FROM cobrancas WHERE venda_id = ? OR orcamento_id IN (SELECT id FROM orcamentos WHERE id = ?)";
    $stmt_cobrancas = $conexao->prepare($sql_cobrancas);
    $stmt_cobrancas->bind_param("ii", $id, $id);
    $stmt_cobrancas->execute();
    $venda['cobrancas'] = $stmt_cobrancas->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_cobrancas->close();
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vendas - Império AR</title>
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
        
        .valor-destaque {
            font-size: 20px;
            font-weight: bold;
            color: var(--success);
        }
        
        .lucro-positivo {
            color: var(--success);
            font-weight: bold;
        }
        
        .lucro-negativo {
            color: var(--danger);
            font-weight: bold;
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
                        <i class="fas fa-dollar-sign"></i>
                        Gerenciamento de Vendas
                    </h1>
                    <div class="header-actions">
                        <a href="?acao=novo" class="btn btn-success">
                            <i class="fas fa-plus-circle"></i> Nova Venda
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
                    
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #d4edda; color: #155724;">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo formatarMoeda($stats['total_vendas'] ?? 0); ?></h3>
                            <p>Total em Vendas</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #d4edda; color: #155724;">
                            <i class="fas fa-chart-pie"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo formatarMoeda($stats['total_lucro'] ?? 0); ?></h3>
                            <p>Lucro Total</p>
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
                            <label>Período Início</label>
                            <input type="date" name="data_inicio" class="form-control" value="<?php echo $filtro_data_inicio ?? ''; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label>Período Fim</label>
                            <input type="date" name="data_fim" class="form-control" value="<?php echo $filtro_data_fim ?? ''; ?>">
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

                <!-- Lista de Vendas -->
                <?php if (!empty($vendas)): ?>
                <div class="card">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th style="color:#203f78">Número</th>
                                    <th style="color:#203f78">Cliente</th>
                                    <th style="color:#203f78">Data</th>
                                    <th style="color:#203f78">Status</th>
                                    <th style="color:#203f78">Valor Total</th>
                                    <th style="color:#203f78">Custo</th>
                                    <th style="color:#203f78">Lucro</th>
                                    <th style="color:#203f78">Margem</th>
                                    <th style="color:#203f78">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($vendas as $item): 
                                    $margem = calcularMargemLucro($item['valor_total'], $item['valor_custo']);
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($item['numero'] ?? '#' . $item['id']); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($item['cliente_nome']); ?></td>
                                    <td><?php echo formatarData($item['data_venda']); ?></td>
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
                                    <td><strong class="text-success"><?php echo formatarMoeda($item['valor_total']); ?></strong></td>
                                    <td><?php echo formatarMoeda($item['valor_custo']); ?></td>
                                    <td class="<?php echo $item['valor_lucro'] >= 0 ? 'lucro-positivo' : 'lucro-negativo'; ?>">
                                        <?php echo formatarMoeda($item['valor_lucro']); ?>
                                    </td>
                                    <td>
                                        <span class="badge" style="background: <?php echo $margem >= 30 ? '#d4edda' : ($margem >= 15 ? '#fff3cd' : '#f8d7da'); ?>; color: <?php echo $margem >= 30 ? '#155724' : ($margem >= 15 ? '#856404' : '#721c24'); ?>">
                                            <?php echo number_format($margem, 1, ',', '.'); ?>%
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <a href="?acao=visualizar&id=<?php echo $item['id']; ?>" class="btn btn-sm btn-info" title="Visualizar">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            
                                            <a href="?acao=editar&id=<?php echo $item['id']; ?>" class="btn btn-sm btn-primary" title="Editar">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            
                                            <?php if ($item['situacao'] != 'finalizado' && $item['situacao'] != 'cancelado'): ?>
                                                <a href="?acao=finalizar&id=<?php echo $item['id']; ?>" class="btn btn-sm btn-success" title="Finalizar" onclick="return confirm('Confirmar finalização da venda?')">
                                                    <i class="fas fa-check"></i>
                                                </a>
                                                <a href="?acao=cancelar&id=<?php echo $item['id']; ?>" class="btn btn-sm btn-danger" title="Cancelar" onclick="return confirm('Cancelar esta venda?')">
                                                    <i class="fas fa-times"></i>
                                                </a>
                                            <?php endif; ?>
                                            
                                            <a href="?acao=recibo&id=<?php echo $item['id']; ?>" class="btn btn-sm btn-warning" title="Recibo" target="_blank">
                                                <i class="fas fa-receipt"></i>
                                            </a>
                                            
                                            <?php if (empty($item['itens_produtos']) && empty($item['itens_servicos'])): ?>
                                            <a href="?acao=deletar&id=<?php echo $item['id']; ?>" class="btn btn-sm btn-danger" title="Excluir" onclick="return confirm('Tem certeza? Esta ação não pode ser desfeita.')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                            <?php endif; ?>
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
                    <a href="?pagina=<?php echo $i; ?>&status=<?php echo urlencode($filtro_status ?? ''); ?>&cliente=<?php echo urlencode($filtro_cliente ?? ''); ?>&data_inicio=<?php echo urlencode($filtro_data_inicio ?? ''); ?>&data_fim=<?php echo urlencode($filtro_data_fim ?? ''); ?>&mes=<?php echo $filtro_mes ?? date('m'); ?>&ano=<?php echo $filtro_ano ?? date('Y'); ?>" 
                       class="<?php echo $i == $pagina_atual ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>

                <?php else: ?>
                <div class="empty-message" style="text-align: center; padding: 50px; background: white; border-radius: 12px;">
                    <i class="fas fa-dollar-sign fa-4x" style="color: #ccc; margin-bottom: 20px;"></i>
                    <h3 style="color: #666; margin-bottom: 10px;">Nenhuma venda encontrada</h3>
                    <p style="color: #999; margin-bottom: 20px;">Comece registrando uma nova venda.</p>
                    <a href="?acao=novo" class="btn btn-success">
                        <i class="fas fa-plus-circle"></i> Nova Venda
                    </a>
                </div>
                <?php endif; ?>

            <?php elseif ($acao === 'novo' || $acao === 'editar'): ?>

                <div class="page-header">
                    <h1>
                        <i class="fas fa-<?php echo $acao === 'novo' ? 'plus-circle' : 'edit'; ?>"></i>
                        <?php echo $acao === 'novo' ? 'Registrar Nova Venda' : 'Editar Venda #' . ($venda['numero'] ?? $id); ?>
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
                            <i class="fas fa-dollar-sign"></i>
                            Informações da Venda
                        </h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="form-venda" onsubmit="return validarFormulario()">
                            <input type="hidden" name="acao" value="salvar">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <?php if ($acao === 'editar'): ?>
                                <input type="hidden" name="id" value="<?php echo $venda['id']; ?>">
                            <?php endif; ?>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Cliente *</label>
                                    <select name="cliente_id" id="cliente_id" required class="form-control">
                                        <option value="">-- Selecione um cliente --</option>
                                        <?php foreach ($clientes as $cli): ?>
                                        <option value="<?php echo $cli['id']; ?>" 
                                                <?php echo ($venda['cliente_id'] ?? '') == $cli['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cli['nome'] . ' - ' . $cli['telefone']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label>Data da Venda</label>
                                    <input type="date" name="data_venda" value="<?php echo $venda['data_venda'] ?? date('Y-m-d'); ?>" class="form-control">
                                </div>

                                <div class="form-group">
                                    <label>Situação</label>
                                    <select name="situacao" class="form-control">
                                        <option value="pendente" <?php echo ($venda['situacao'] ?? '') == 'pendente' ? 'selected' : ''; ?>>Pendente</option>
                                        <option value="em_andamento" <?php echo ($venda['situacao'] ?? '') == 'em_andamento' ? 'selected' : ''; ?>>Em Andamento</option>
                                        <option value="finalizado" <?php echo ($venda['situacao'] ?? '') == 'finalizado' ? 'selected' : ''; ?>>Finalizado</option>
                                        <option value="cancelado" <?php echo ($venda['situacao'] ?? '') == 'cancelado' ? 'selected' : ''; ?>>Cancelado</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Observações</label>
                                <textarea name="observacao" rows="3" class="form-control" placeholder="Observações da venda..."><?php echo htmlspecialchars($venda['observacao'] ?? ''); ?></textarea>
                            </div>

                            <h3 style="margin: 30px 0 20px; color: var(--primary);">
                                <i class="fas fa-calculator"></i> Valores
                            </h3>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Desconto (%)</label>
                                    <input type="number" name="desconto_percentual" id="desconto_percentual" 
                                           value="<?php echo $venda['desconto_percentual'] ?? 0; ?>" 
                                           min="0" max="100" step="0.01" class="form-control" onchange="calcularLucro()">
                                </div>
                                
                                <div class="form-group">
                                    <label>Valor Adicional (R$)</label>
                                    <input type="text" name="valor_adicional" id="valor_adicional" 
                                           value="<?php echo isset($venda['valor_adicional']) ? number_format($venda['valor_adicional'], 2, ',', '.') : '0,00'; ?>" 
                                           class="form-control money" onchange="calcularLucro()">
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Valor Total da Venda *</label>
                                    <input type="text" name="valor_total" id="valor_total" 
                                           value="<?php echo isset($venda['valor_total']) ? number_format($venda['valor_total'], 2, ',', '.') : '0,00'; ?>" 
                                           class="form-control money" required onchange="calcularLucro()">
                                </div>
                                
                                <div class="form-group">
                                    <label>Custo Total</label>
                                    <input type="text" name="valor_custo" id="valor_custo" 
                                           value="<?php echo isset($venda['valor_custo']) ? number_format($venda['valor_custo'], 2, ',', '.') : '0,00'; ?>" 
                                           class="form-control money" onchange="calcularLucro()">
                                </div>
                                
                                <div class="form-group">
                                    <label>Lucro (calculado)</label>
                                    <input type="text" name="valor_lucro" id="valor_lucro" 
                                           value="<?php echo isset($venda['valor_lucro']) ? number_format($venda['valor_lucro'], 2, ',', '.') : '0,00'; ?>" 
                                           class="form-control" readonly style="background: #f0f0f0;">
                                </div>
                            </div>

                            <div class="resumo" style="margin-top: 20px;">
                                <div class="resumo-row">
                                    <span>Margem de Lucro:</span>
                                    <span id="margem-lucro">0,00%</span>
                                </div>
                            </div>

                            <div style="margin-top: 30px; display: flex; gap: 15px; justify-content: flex-end;">
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="fas fa-save"></i> Salvar Venda
                                </button>
                                <a href="?acao=listar" class="btn btn-secondary btn-lg">
                                    <i class="fas fa-times"></i> Cancelar
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <script>
                    function moedaParaFloat(valor) {
                        if (!valor) return 0;
                        valor = valor.toString().replace(/[^\d,]/g, '').replace(',', '.');
                        return parseFloat(valor) || 0;
                    }

                    function floatParaMoeda(valor) {
                        return valor.toFixed(2).replace('.', ',');
                    }

                    function calcularLucro() {
                        let valorTotal = moedaParaFloat(document.getElementById('valor_total').value);
                        let valorCusto = moedaParaFloat(document.getElementById('valor_custo').value);
                        let desconto = parseFloat(document.getElementById('desconto_percentual').value) || 0;
                        let adicional = moedaParaFloat(document.getElementById('valor_adicional').value);
                        
                        // Aplica desconto no valor total
                        let valorComDesconto = valorTotal * (1 - desconto / 100);
                        let valorFinal = valorComDesconto + adicional;
                        
                        let lucro = valorFinal - valorCusto;
                        let margem = valorFinal > 0 ? (lucro / valorFinal) * 100 : 0;
                        
                        document.getElementById('valor_lucro').value = floatParaMoeda(lucro);
                        document.getElementById('margem-lucro').innerHTML = margem.toFixed(2).replace('.', ',') + '%';
                        
                        // Atualiza cor da margem
                        let margemElement = document.getElementById('margem-lucro');
                        if (margem >= 30) {
                            margemElement.style.color = '#28a745';
                        } else if (margem >= 15) {
                            margemElement.style.color = '#ffc107';
                        } else {
                            margemElement.style.color = '#dc3545';
                        }
                    }

                    function validarFormulario() {
                        let cliente = document.getElementById('cliente_id').value;
                        let valorTotal = moedaParaFloat(document.getElementById('valor_total').value);
                        
                        if (!cliente) {
                            alert('Selecione um cliente!');
                            return false;
                        }
                        
                        if (valorTotal <= 0) {
                            alert('Valor total deve ser maior que zero!');
                            return false;
                        }
                        
                        return true;
                    }

                    // Máscara monetária
                    document.querySelectorAll('.money').forEach(input => {
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
                            
                            calcularLucro();
                        });
                    });

                    document.addEventListener('DOMContentLoaded', function() {
                        calcularLucro();
                    });
                </script>

            <?php elseif ($acao === 'visualizar' && !empty($venda)): ?>

                <div class="page-header">
                    <h1>
                        <i class="fas fa-eye"></i>
                        Detalhes da Venda #<?php echo $venda['numero'] ?? $venda['id']; ?>
                    </h1>
                    <div class="header-actions">
                        <a href="?acao=listar" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Voltar
                        </a>
                        <a href="?acao=editar&id=<?php echo $venda['id']; ?>" class="btn btn-primary">
                            <i class="fas fa-edit"></i> Editar
                        </a>
                        <a href="?acao=recibo&id=<?php echo $venda['id']; ?>" class="btn btn-warning" target="_blank">
                            <i class="fas fa-receipt"></i> Recibo
                        </a>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3>Informações da Venda</h3>
                    </div>
                    <div class="card-body">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Número</label>
                                <input type="text" class="form-control" value="<?php echo $venda['numero'] ?? 'N/D'; ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label>Data</label>
                                <input type="text" class="form-control" value="<?php echo formatarData($venda['data_venda']); ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label>Status</label>
                                <input type="text" class="form-control" value="<?php echo ucfirst($venda['situacao']); ?>" readonly>
                            </div>
                        </div>

                        <h3 style="margin: 20px 0 10px;">Cliente</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Nome</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($venda['cliente_nome']); ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label>CPF/CNPJ</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($venda['cpf_cnpj'] ?? 'N/D'); ?>" readonly>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Telefone</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($venda['telefone'] ?? $venda['whatsapp'] ?? 'N/D'); ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label>Email</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($venda['email'] ?? 'N/D'); ?>" readonly>
                            </div>
                        </div>

                        <h3 style="margin: 20px 0 10px;">Valores</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Valor Total</label>
                                <input type="text" class="form-control valor-destaque" value="<?php echo formatarMoeda($venda['valor_total']); ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label>Custo</label>
                                <input type="text" class="form-control" value="<?php echo formatarMoeda($venda['valor_custo']); ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label>Lucro</label>
                                <input type="text" class="form-control <?php echo $venda['valor_lucro'] >= 0 ? 'lucro-positivo' : 'lucro-negativo'; ?>" 
                                       value="<?php echo formatarMoeda($venda['valor_lucro']); ?>" readonly>
                            </div>
                        </div>
                        
                        <?php if (!empty($venda['observacao'])): ?>
                        <div class="form-group">
                            <label>Observações</label>
                            <textarea class="form-control" rows="3" readonly><?php echo htmlspecialchars($venda['observacao']); ?></textarea>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($venda['cobrancas'])): ?>
                        <h3 style="margin: 20px 0 10px;">Cobranças Relacionadas</h3>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Número</th>
                                    <th>Valor</th>
                                    <th>Vencimento</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($venda['cobrancas'] as $cob): ?>
                                <tr>
                                    <td><?php echo $cob['numero'] ?? '#' . $cob['id']; ?></td>
                                    <td><?php echo formatarMoeda($cob['valor']); ?></td>
                                    <td><?php echo formatarData($cob['data_vencimento']); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $cob['status']; ?>">
                                            <?php echo ucfirst($cob['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>

            <?php endif; ?>

        </main>
    </div>
</body>
</html>
