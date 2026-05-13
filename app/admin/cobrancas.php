<?php
/**
 * =====================================================================
 * COBRANÇAS - SISTEMA DE GESTÃO IMPÉRIO AR (VERSÃO CORRIGIDA)
 * =====================================================================
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/environment.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/WhatsApp.php';
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
$cobrancas = [];
$clientes = [];
$orcamentos = [];
$pedidos = [];
$vendas = [];
$cobranca = [];
$pagina_atual = isset($_GET['pagina']) ? intval($_GET['pagina']) : 1;
$por_pagina = 20;
$total_paginas = 0;

// ===== INICIALIZAR CLASSES =====
$whatsapp = new WhatsApp($conexao);
$financeiro = new Financeiro($conexao);

// ===== FUNÇÃO DE VALIDAÇÃO DE DATA =====
function validarData($data) {
    if (empty($data) || $data === null || trim($data) === '') {
        return date('Y-m-d');
    }
    
    $data = trim($data);
    
    // Se for apenas o ano (ex: '2026' ou 2026)
    if (is_numeric($data) && strlen((string)$data) === 4) {
        $ano = intval($data);
        if ($ano >= 1900 && $ano <= 2100) {
            return $ano . '-01-01';
        }
    }
    
    // Se já estiver no formato YYYY-MM-DD
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
        $timestamp = strtotime($data);
        if ($timestamp !== false && $timestamp > 0) {
            return $data;
        }
    }
    
    // Formato brasileiro dd/mm/yyyy
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $data, $matches)) {
        $dia = $matches[1];
        $mes = $matches[2];
        $ano = $matches[3];
        if (checkdate($mes, $dia, $ano)) {
            return "{$ano}-{$mes}-{$dia}";
        }
    }
    
    // Tentar qualquer formato com strtotime
    $timestamp = strtotime($data);
    if ($timestamp !== false && $timestamp > 0) {
        return date('Y-m-d', $timestamp);
    }
    
    return date('Y-m-d');
}

// ===== FUNÇÕES AUXILIARES =====
if (!function_exists('formatarMoeda')) {
    function formatarMoeda($valor) {
        if(empty($valor) || !is_numeric($valor)) $valor = 0;
        return 'R$ ' . number_format(floatval($valor), 2, ',', '.');
    }
}

function formatarData($data) {
    if (empty($data)) return '';
    return date('d/m/Y', strtotime($data));
}

if (!function_exists('moedaParaFloat')) {
    function moedaParaFloat($valor) {
        if(empty($valor) || trim($valor) === 'R$ 0,00') return 0;
        $valor = str_replace(['R$', ' ', '.'], '', $valor);
        $valor = str_replace(',', '.', $valor);
        return floatval($valor);
    }
}

if (!function_exists('gerarLinkWhatsApp')) {
    function gerarLinkWhatsApp($telefone, $mensagem) {
        $numero = preg_replace('/[^0-9]/', '', $telefone);
        if (substr($numero, 0, 2) !== '55') {
            $numero = '55' . $numero;
        }
        $mensagem_encoded = rawurlencode($mensagem);
        return "https://wa.me/{$numero}?text={$mensagem_encoded}";
    }
}

function verificarCSRF($token) {
    return isset($_SESSION['csrf_token']) && $token === $_SESSION['csrf_token'];
}

function getStatusBadge($status) {
    $classes = [
        'pendente' => 'badge-warning',
        'vencida' => 'badge-danger',
        'a_receber' => 'badge-info',
        'recebida' => 'badge-success',
        'cancelada' => 'badge-secondary'
    ];
    
    $labels = [
        'pendente' => 'Pendente',
        'vencida' => 'Vencida',
        'a_receber' => 'A Receber',
        'recebida' => 'Recebida',
        'cancelada' => 'Cancelada'
    ];
    
    $classe = $classes[$status] ?? 'badge-secondary';
    $label = $labels[$status] ?? ucfirst($status);
    
    return '<span class="badge ' . $classe . '">' . $label . '</span>';
}

function getStatusVencimento($data_vencimento) {
    if (empty($data_vencimento) || $data_vencimento == '0000-00-00') {
        return ['status' => 'sem_data', 'dias' => 0, 'classe' => ''];
    }
    
    $hoje = new DateTime();
    $vencimento = new DateTime($data_vencimento);
    $diferenca = $hoje->diff($vencimento)->days;
    
    if ($hoje > $vencimento) {
        return [
            'status' => 'vencido',
            'dias' => $diferenca,
            'classe' => 'vencido',
            'texto' => "Vencido há {$diferenca} dia(s)"
        ];
    } elseif ($diferenca <= 5) {
        return [
            'status' => 'proximo',
            'dias' => $diferenca,
            'classe' => 'proximo',
            'texto' => "Vence em {$diferenca} dia(s)"
        ];
    } else {
        return [
            'status' => 'ok',
            'dias' => $diferenca,
            'classe' => 'ok',
            'texto' => "Vence em {$diferenca} dia(s)"
        ];
    }
}

/**
 * Função: enviarCobrancaWhatsApp()
 */
function enviarCobrancaWhatsApp($conexao, $cobranca_id) {
    try {
        $sql = "SELECT c.*, 
                       cl.nome as cliente_nome, 
                       cl.whatsapp, 
                       cl.telefone,
                       o.numero as orcamento_numero,
                       p.numero as pedido_numero,
                       v.numero as venda_numero
                FROM cobrancas c
                LEFT JOIN clientes cl ON c.cliente_id = cl.id
                LEFT JOIN orcamentos o ON c.orcamento_id = o.id
                LEFT JOIN pedidos p ON c.pedido_id = p.id
                LEFT JOIN vendas v ON c.venda_id = v.id
                WHERE c.id = ?";
        
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param("i", $cobranca_id);
        $stmt->execute();
        $dados = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$dados) {
            throw new Exception("Cobrança não encontrada!");
        }
        
        if (empty($dados['whatsapp']) && empty($dados['telefone'])) {
            throw new Exception("Cliente não possui telefone cadastrado!");
        }
        
        $telefone = $dados['whatsapp'] ?? $dados['telefone'];
        $data_vencimento = date('d/m/Y', strtotime($dados['data_vencimento']));
        $valor_formatado = formatarMoeda($dados['valor']);
        
        $origem = '';
        $numero_origem = '';
        if (!empty($dados['orcamento_numero'])) {
            $origem = "Orçamento";
            $numero_origem = $dados['orcamento_numero'];
        } elseif (!empty($dados['pedido_numero'])) {
            $origem = "Pedido";
            $numero_origem = $dados['pedido_numero'];
        } elseif (!empty($dados['venda_numero'])) {
            $origem = "Venda";
            $numero_origem = $dados['venda_numero'];
        }
        
        $mensagem = "*IMPÉRIO AR - COBRANÇA*\n";
        $mensagem .= "━━━━━━━━━━━━━━━━━━━━━\n\n";
        $mensagem .= "*Cliente:* {$dados['cliente_nome']}\n";
        $mensagem .= "*Valor:* {$valor_formatado}\n";
        $mensagem .= "*Vencimento:* {$data_vencimento}\n";
        
        if (!empty($origem)) {
            $mensagem .= "*Referente:* {$origem} {$numero_origem}\n";
        }
        
        $mensagem .= "\n*FORMAS DE PAGAMENTO:*\n";
        $mensagem .= "▸ PIX: (17) 9 9624-0725\n";
        $mensagem .= "▸ Dinheiro\n";
        $mensagem .= "▸ Cartão de Débito/Crédito\n\n";
        $mensagem .= "Após o pagamento, envie o comprovante para este WhatsApp.\n\n";
        $mensagem .= "━━━━━━━━━━━━━━━━━━━━━\n";
        $mensagem .= "*Império AR* - Especialistas em conforto térmico!\n";
        $mensagem .= "📞 (17) 9 9624-0725";
        
        $link_whatsapp = gerarLinkWhatsApp($telefone, $mensagem);
        
        return [
            'success' => true,
            'link' => $link_whatsapp,
            'telefone' => $telefone
        ];
        
    } catch (Exception $e) {
        error_log("Erro ao enviar cobrança WhatsApp: " . $e->getMessage());
        return [
            'success' => false,
            'erro' => $e->getMessage()
        ];
    }
}

// ===== CARREGAR DADOS BÁSICOS =====
function carregarClientes($conexao) {
    $clientes = [];
    $sql = "SELECT id, nome, telefone, whatsapp, email FROM clientes WHERE ativo = 1 ORDER BY nome ASC";
    $resultado = $conexao->query($sql);
    if ($resultado) {
        while ($linha = $resultado->fetch_assoc()) {
            $clientes[] = $linha;
        }
    }
    return $clientes;
}

function carregarOrcamentos($conexao) {
    $orcamentos = [];
    $sql = "SELECT o.id, o.numero, o.valor_total, c.nome as cliente_nome
            FROM orcamentos o
            LEFT JOIN clientes c ON o.cliente_id = c.id
            WHERE o.situacao = 'concluido'
            ORDER BY o.id DESC LIMIT 100";
    $resultado = $conexao->query($sql);
    if ($resultado) {
        while ($linha = $resultado->fetch_assoc()) {
            $orcamentos[] = $linha;
        }
    }
    return $orcamentos;
}

function carregarPedidos($conexao) {
    $pedidos = [];
    $sql = "SELECT p.id, p.numero, p.valor_total, c.nome as cliente_nome
            FROM pedidos p
            LEFT JOIN clientes c ON p.cliente_id = c.id
            WHERE p.situacao = 'finalizado'
            ORDER BY p.id DESC LIMIT 100";
    $resultado = $conexao->query($sql);
    if ($resultado) {
        while ($linha = $resultado->fetch_assoc()) {
            $pedidos[] = $linha;
        }
    }
    return $pedidos;
}

function carregarVendas($conexao) {
    $vendas = [];
    $sql = "SELECT v.id, v.numero, v.valor_total, c.nome as cliente_nome
            FROM vendas v
            LEFT JOIN clientes c ON v.cliente_id = c.id
            WHERE v.situacao = 'finalizado'
            ORDER BY v.id DESC LIMIT 100";
    $resultado = $conexao->query($sql);
    if ($resultado) {
        while ($linha = $resultado->fetch_assoc()) {
            $vendas[] = $linha;
        }
    }
    return $vendas;
}

// Carregar dados
$clientes = carregarClientes($conexao);
$orcamentos = carregarOrcamentos($conexao);
$pedidos = carregarPedidos($conexao);
$vendas = carregarVendas($conexao);

// ===== PROCESSAR AÇÕES POST =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verificarCSRF($_POST['csrf_token'])) {
        $erro = "Token de segurança inválido.";
    } else {
        $acao_post = $_POST['acao'] ?? '';
        
        // ===== SALVAR COBRANÇA =====
        if ($acao_post === 'salvar') {
            $cliente_id = isset($_POST['cliente_id']) ? intval($_POST['cliente_id']) : 0;
            $valor = moedaParaFloat($_POST['valor'] ?? '0,00');
            
            $data_vencimento_raw = $_POST['data_vencimento'] ?? '';
            $data_vencimento = validarData($data_vencimento_raw);
            
            $status = $_POST['status'] ?? 'pendente';
            $tipo_pagamento = !empty($_POST['tipo_pagamento']) ? $_POST['tipo_pagamento'] : null;
            
            $data_recebimento_raw = $_POST['data_recebimento'] ?? '';
            $data_recebimento = !empty($data_recebimento_raw) ? validarData($data_recebimento_raw) : null;
            
            $observacao = $conexao->real_escape_string($_POST['observacao'] ?? '');
            
            $orcamento_id = !empty($_POST['orcamento_id']) ? intval($_POST['orcamento_id']) : null;
            $pedido_id = !empty($_POST['pedido_id']) ? intval($_POST['pedido_id']) : null;
            $venda_id = !empty($_POST['venda_id']) ? intval($_POST['venda_id']) : null;
            
            $id_editar = isset($_POST['id']) ? intval($_POST['id']) : null;
            
            if ($cliente_id <= 0) {
                $erro = "Selecione um cliente";
            } elseif ($valor <= 0) {
                $erro = "Valor deve ser maior que zero";
            } else {
                if (empty($erro)) {
                    $numero = $_POST['numero'] ?? null;
                    if (!$numero && !$id_editar) {
                        do {
                            $numero = 'COB-' . date('Ymd') . '-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
                            $check_numero = $conexao->query("SELECT id FROM cobrancas WHERE numero = '$numero'");
                        } while ($check_numero && $check_numero->num_rows > 0);
                    }
                    
                    if ($id_editar) {
                        $sql = "UPDATE cobrancas SET 
                                numero = ?,
                                cliente_id = ?,
                                orcamento_id = ?,
                                pedido_id = ?,
                                venda_id = ?,
                                valor = ?,
                                data_vencimento = ?,
                                status = ?,
                                tipo_pagamento = ?,
                                data_recebimento = ?,
                                observacao = ?
                                WHERE id = ?";
                        
                        $stmt = $conexao->prepare($sql);
                        $stmt->bind_param(
                            "siiiiddssssi",
                            $numero, $cliente_id, $orcamento_id, $pedido_id, $venda_id,
                            $valor, $data_vencimento, $status, $tipo_pagamento,
                            $data_recebimento, $observacao, $id_editar
                        );
                        
                        if ($stmt->execute()) {
                            header('Location: ' . BASE_URL . '/app/admin/cobrancas.php?mensagem=atualizado');
                            exit;
                        } else {
                            $erro = "Erro ao atualizar cobrança: " . $stmt->error;
                        }
                        $stmt->close();
                        
                    } else {
                        $sql = "INSERT INTO cobrancas (
                                numero, cliente_id, orcamento_id, pedido_id, venda_id,
                                valor, data_vencimento, status, tipo_pagamento, data_recebimento, observacao
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        
                        $stmt = $conexao->prepare($sql);
                        $stmt->bind_param(
                            "siiiidsssss",
                            $numero, $cliente_id, $orcamento_id, $pedido_id, $venda_id,
                            $valor, $data_vencimento, $status, $tipo_pagamento,
                            $data_recebimento, $observacao
                        );
                        
                        if ($stmt->execute()) {
                            if ($status == 'recebida') {
                                $check_table = $conexao->query("SHOW TABLES LIKE 'financeiro'");
                                if ($check_table && $check_table->num_rows > 0) {
                                    $financeiro->registrarEntrada([
                                        'valor' => $valor,
                                        'descricao' => "Cobrança #$numero",
                                        'cliente_id' => $cliente_id,
                                        'data' => $data_recebimento ?? date('Y-m-d')
                                    ]);
                                }
                            }
                            
                            header('Location: ' . BASE_URL . '/app/admin/cobrancas.php?mensagem=criado');
                            exit;
                        } else {
                            $erro = "Erro ao criar cobrança: " . $stmt->error;
                        }
                        $stmt->close();
                    }
                }
            }
        }
        
        // ===== BAIXAR COBRANÇA =====
        if ($acao_post === 'baixar' && isset($_POST['id'])) {
            $cobranca_id = intval($_POST['id']);
            $data_recebimento = validarData($_POST['data_recebimento'] ?? date('Y-m-d'));
            $tipo_pagamento = !empty($_POST['tipo_pagamento']) ? $_POST['tipo_pagamento'] : null;
            
            $conexao->begin_transaction();
            
            try {
                $sql_busca = "SELECT * FROM cobrancas WHERE id = ?";
                $stmt_busca = $conexao->prepare($sql_busca);
                $stmt_busca->bind_param("i", $cobranca_id);
                $stmt_busca->execute();
                $cob_data = $stmt_busca->get_result()->fetch_assoc();
                $stmt_busca->close();
                
                $sql = "UPDATE cobrancas SET status = 'recebida', data_recebimento = ?, tipo_pagamento = ? WHERE id = ?";
                $stmt = $conexao->prepare($sql);
                $stmt->bind_param("ssi", $data_recebimento, $tipo_pagamento, $cobranca_id);
                $stmt->execute();
                $stmt->close();
                
                $check_table = $conexao->query("SHOW TABLES LIKE 'financeiro'");
                if ($check_table && $check_table->num_rows > 0) {
                    $financeiro->registrarEntrada([
                        'valor' => $cob_data['valor'],
                        'descricao' => "Recebimento de cobrança #" . ($cob_data['numero'] ?? $cobranca_id),
                        'cliente_id' => $cob_data['cliente_id'],
                        'data' => $data_recebimento
                    ]);
                }
                
                $conexao->commit();
                header('Location: ' . BASE_URL . '/app/admin/cobrancas.php?mensagem=recebido');
                exit;
                
            } catch (Exception $e) {
                $conexao->rollback();
                $erro = "Erro ao baixar cobrança: " . $e->getMessage();
            }
        }
        
        // ===== DELETAR COBRANÇA VIA POST =====
        if ($acao_post === 'deletar' && isset($_POST['id'])) {
            $cobranca_id = intval($_POST['id']);
            
            $check = $conexao->query("SELECT status FROM cobrancas WHERE id = $cobranca_id");
            if ($check && $check->num_rows > 0) {
                $status_atual = $check->fetch_assoc()['status'];
                
                if ($status_atual !== 'pendente' && $status_atual !== 'cancelada') {
                    $erro = "Só é possível excluir cobranças pendentes ou canceladas.";
                } else {
                    $sql = "DELETE FROM cobrancas WHERE id = ?";
                    $stmt = $conexao->prepare($sql);
                    $stmt->bind_param("i", $cobranca_id);
                    
                    if ($stmt->execute()) {
                        header('Location: ' . BASE_URL . '/app/admin/cobrancas.php?mensagem=deletado');
                        exit;
                    } else {
                        $erro = "Erro ao deletar cobrança: " . $stmt->error;
                    }
                    $stmt->close();
                }
            }
        }
    }
}

// ===== PROCESSAR AÇÕES GET =====
if ($acao === 'deletar' && $id) {
    $check = $conexao->query("SELECT status FROM cobrancas WHERE id = $id");
    $status_atual = $check->fetch_assoc()['status'];
    
    if ($status_atual !== 'pendente' && $status_atual !== 'cancelada') {
        $erro = "Só é possível excluir cobranças pendentes ou canceladas";
        $acao = 'listar';
    } else {
        $sql = "DELETE FROM cobrancas WHERE id = ?";
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            header('Location: ' . BASE_URL . '/app/admin/cobrancas.php?mensagem=deletado');
            exit;
        } else {
            $erro = "Erro ao deletar cobrança";
        }
        $stmt->close();
    }
}

if ($acao === 'cancelar_get' && $id) {
    $sql = "UPDATE cobrancas SET status = 'cancelada' WHERE id = ?";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        header('Location: ' . BASE_URL . '/app/admin/cobrancas.php?mensagem=cancelado');
        exit;
    }
    $stmt->close();
}

if ($acao === 'enviar_whatsapp' && $id) {
    $resultado = enviarCobrancaWhatsApp($conexao, $id);
    
    if ($resultado['success']) {
        echo "<script>window.open('{$resultado['link']}', '_blank'); setTimeout(function() { window.location.href = '" . BASE_URL . "/app/admin/cobrancas.php?mensagem=whatsapp_enviado'; }, 1000);</script>";
        exit;
    } else {
        $erro = $resultado['erro'] ?? 'Erro ao enviar WhatsApp';
    }
}

if ($acao === 'baixar' && $id) {
    $sql = "SELECT c.*, cl.nome as cliente_nome, cl.whatsapp, cl.telefone,
                   o.numero as orcamento_numero, p.numero as pedido_numero, v.numero as venda_numero
            FROM cobrancas c
            LEFT JOIN clientes cl ON c.cliente_id = cl.id
            LEFT JOIN orcamentos o ON c.orcamento_id = o.id
            LEFT JOIN pedidos p ON c.pedido_id = p.id
            LEFT JOIN vendas v ON c.venda_id = v.id
            WHERE c.id = ?";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $cobranca = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$cobranca) {
        header('Location: ' . BASE_URL . '/app/admin/cobrancas.php');
        exit;
    }
}

if ($acao === 'editar' && $id) {
    $sql = "SELECT c.*, cl.nome as cliente_nome,
                   o.numero as orcamento_numero, p.numero as pedido_numero, v.numero as venda_numero
            FROM cobrancas c
            LEFT JOIN clientes cl ON c.cliente_id = cl.id
            LEFT JOIN orcamentos o ON c.orcamento_id = o.id
            LEFT JOIN pedidos p ON c.pedido_id = p.id
            LEFT JOIN vendas v ON c.venda_id = v.id
            WHERE c.id = ?";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $cobranca = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$cobranca) {
        header('Location: ' . BASE_URL . '/app/admin/cobrancas.php');
        exit;
    }
}

if ($acao === 'novo') {
    $cobranca = [
        'id' => '',
        'cliente_id' => $_GET['cliente_id'] ?? '',
        'orcamento_id' => $_GET['orcamento_id'] ?? '',
        'pedido_id' => $_GET['pedido_id'] ?? '',
        'venda_id' => $_GET['venda_id'] ?? '',
        'numero' => '',
        'valor' => $_GET['valor'] ?? '0,00',
        'data_vencimento' => date('Y-m-d', strtotime('+30 days')),
        'status' => 'pendente',
        'tipo_pagamento' => '',
        'data_recebimento' => '',
        'observacao' => ''
    ];
}

// ===== LISTAR COBRANÇAS =====
if ($acao === 'listar') {
    $filtro_status = $_GET['status'] ?? '';
    $filtro_cliente = $_GET['cliente'] ?? '';
    $filtro_tipo = $_GET['tipo'] ?? '';
    $filtro_data_inicio = $_GET['data_inicio'] ?? date('Y-m-d', strtotime('-2 years'));
    $filtro_data_fim = $_GET['data_fim'] ?? date('Y-m-d', strtotime('+1 year'));
    
    $offset = ($pagina_atual - 1) * $por_pagina;
    
    $sql = "SELECT c.*, cl.nome as cliente_nome, cl.telefone, cl.whatsapp,
                   o.numero as orcamento_numero, p.numero as pedido_numero, v.numero as venda_numero
            FROM cobrancas c
            LEFT JOIN clientes cl ON c.cliente_id = cl.id
            LEFT JOIN orcamentos o ON c.orcamento_id = o.id
            LEFT JOIN pedidos p ON c.pedido_id = p.id
            LEFT JOIN vendas v ON c.venda_id = v.id
            WHERE 1=1";
    $params = [];
    $types = "";
    
    if ($filtro_status) {
        $sql .= " AND c.status = ?";
        $params[] = $filtro_status;
        $types .= "s";
    }
    
    if ($filtro_cliente) {
        $sql .= " AND cl.nome LIKE ?";
        $params[] = "%$filtro_cliente%";
        $types .= "s";
    }
    
    if ($filtro_tipo) {
        if ($filtro_tipo === 'orcamento') {
            $sql .= " AND c.orcamento_id IS NOT NULL";
        } elseif ($filtro_tipo === 'pedido') {
            $sql .= " AND c.pedido_id IS NOT NULL";
        } elseif ($filtro_tipo === 'venda') {
            $sql .= " AND c.venda_id IS NOT NULL";
        }
    }
    
    $sql .= " AND c.data_vencimento BETWEEN ? AND ?";
    $params[] = $filtro_data_inicio;
    $params[] = $filtro_data_fim;
    $types .= "ss";
    
    $sql .= " ORDER BY 
              CASE 
                WHEN c.status = 'vencida' THEN 1
                WHEN c.status = 'pendente' THEN 2
                WHEN c.status = 'recebida' THEN 3
                ELSE 4
              END,
              c.data_vencimento ASC, c.id DESC";
    
    $stmt = $conexao->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $cobrancas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Estatísticas
    $stats = ['pendente' => 0, 'vencida' => 0, 'recebida' => 0, 'cancelada' => 0, 'total_pendente' => 0, 'total_recebido' => 0];
    
    $sql_stats = "SELECT status, COUNT(*) as total, SUM(valor) as soma FROM cobrancas GROUP BY status";
    $stmt_stats = $conexao->prepare($sql_stats);
    $stmt_stats->execute();
    $result_stats = $stmt_stats->get_result();
    while ($row = $result_stats->fetch_assoc()) {
        if (isset($stats[$row['status']])) {
            $stats[$row['status']] = $row['total'];
        }
        if ($row['status'] == 'recebida') {
            $stats['total_recebido'] += $row['soma'];
        } elseif ($row['status'] != 'cancelada') {
            $stats['total_pendente'] += $row['soma'];
        }
    }
    $stmt_stats->close();
    
    if (isset($_GET['mensagem'])) {
        $mapa = [
            'criado' => "✓ Cobrança criada com sucesso!",
            'atualizado' => "✓ Cobrança atualizada com sucesso!",
            'deletado' => "✓ Cobrança deletada com sucesso!",
            'cancelado' => "✓ Cobrança cancelada com sucesso!",
            'recebido' => "✓ Cobrança recebida com sucesso!",
            'whatsapp_enviado' => "✓ Cobrança enviada via WhatsApp!"
        ];
        $mensagem = $mapa[$_GET['mensagem']] ?? '';
    }
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cobranças - Império AR</title>
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
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #f5f6fa 0%, #e9ecef 100%); min-height: 100vh; }
        .admin-container { display: flex; min-height: 100vh; }
        .sidebar { width: 300px; background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%); color: white; padding: 20px; position: fixed; height: 100vh; z-index: 1000; }
        .main-content { flex: 1; margin-left: 300px; padding: 30px; overflow-y: auto; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; padding: 20px; background: white; border-radius: 12px; }
        .page-header h1 { color: var(--primary); font-size: 28px; display: flex; align-items: center; gap: 10px; }
        .btn { padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; font-size: 14px; }
        .btn-primary { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; }
        .btn-success { background: linear-gradient(135deg, var(--success), #34ce57); color: white; }
        .btn-danger { background: linear-gradient(135deg, var(--danger), #e04b5a); color: white; }
        .btn-warning { background: linear-gradient(135deg, var(--warning), #e0a800); color: #333; }
        .btn-info { background: linear-gradient(135deg, var(--info), #138496); color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-whatsapp { background: #25D366; color: white; }
        .btn-sm { padding: 6px 12px; font-size: 13px; }
        .btn-group { display: flex; gap: 5px; flex-wrap: wrap; }
        .card { background: white; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); margin-bottom: 30px; overflow: hidden; }
        .card-header { padding: 20px; background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; }
        .card-header h3 { margin: 0; font-size: 18px; display: flex; align-items: center; gap: 10px; }
        .card-body { padding: 20px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #333; font-size: 14px; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 14px; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: var(--primary); outline: none; }
        .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; }
        .table-responsive { overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; background: white; }
        .table thead { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; }
        .table th { padding: 15px; text-align: left; font-weight: 600; font-size: 14px; }
        .table td { padding: 15px; border-bottom: 1px solid #e0e0e0; color: #333; }
        .badge { display: inline-block; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; text-transform: uppercase; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-info { background: #cce5ff; color: #004085; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-secondary { background: #e2e3e5; color: #383d41; }
        .alert { padding: 15px 20px; border-radius: 8px; margin-bottom: 25px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); display: flex; align-items: center; gap: 15px; }
        .stat-info h3 { margin: 0; font-size: 24px; font-weight: bold; }
        .stat-info p { margin: 5px 0 0; color: #666; }
        .filters { background: white; padding: 20px; border-radius: 12px; margin-bottom: 30px; }
        .filter-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end; }
        .valor-destaque { font-size: 16px; font-weight: bold; color: var(--success); }
        .link-badge { display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 11px; background: #6c757d; color: white; margin-left: 5px; text-decoration: none; }
        .danger-zone { border-left: 5px solid #dc3545; margin-top: 30px; }
        .pagination { display: flex; justify-content: center; gap: 5px; margin-top: 20px; }
        .pagination a { padding: 8px 12px; background: #e9ecef; color: var(--primary); text-decoration: none; border-radius: 4px; }
        .pagination a.active { background: var(--primary); color: white; }
        .empty-message { text-align: center; padding: 50px; background: white; border-radius: 12px; }
        @media (max-width: 768px) {
            .sidebar { width: 100%; height: auto; position: relative; }
            .main-content { margin-left: 0; }
            .form-row { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: 1fr; }
        }
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

            <?php if ($acao === 'listar'): ?>
                
                <div class="page-header">
                    <h1><i class="fas fa-credit-card"></i> Gerenciamento de Cobranças</h1>
                    <a href="?acao=novo" class="btn btn-primary"><i class="fas fa-plus-circle"></i> Nova Cobrança</a>
                </div>

                <div class="stats-grid">
                    <div class="stat-card" style="border-left: 5px solid #ffc107;"><div class="stat-info"><h3><?php echo $stats['pendente'] ?? 0; ?></h3><p>Pendentes</p></div></div>
                    <div class="stat-card" style="border-left: 5px solid #dc3545;"><div class="stat-info"><h3><?php echo $stats['vencida'] ?? 0; ?></h3><p>Vencidas</p></div></div>
                    <div class="stat-card" style="border-left: 5px solid #28a745;"><div class="stat-info"><h3><?php echo $stats['recebida'] ?? 0; ?></h3><p>Recebidas</p></div></div>
                    <div class="stat-card" style="border-left: 5px solid #17a2b8;"><div class="stat-info"><h3><?php echo formatarMoeda($stats['total_pendente'] ?? 0); ?></h3><p>Valor Pendente</p></div></div>
                    <div class="stat-card" style="border-left: 5px solid #28a745;"><div class="stat-info"><h3><?php echo formatarMoeda($stats['total_recebido'] ?? 0); ?></h3><p>Valor Recebido</p></div></div>
                </div>

                <div class="filters">
                    <form method="GET" class="filter-row">
                        <input type="hidden" name="acao" value="listar">
                        <div class="form-group"><label>Status</label><select name="status"><option value="">Todos</option><option value="pendente">Pendente</option><option value="vencida">Vencida</option><option value="recebida">Recebida</option><option value="cancelada">Cancelada</option></select></div>
                        <div class="form-group"><label>Cliente</label><input type="text" name="cliente" placeholder="Nome" value="<?php echo htmlspecialchars($filtro_cliente ?? ''); ?>"></div>
                        <div class="form-group"><label>Tipo</label><select name="tipo"><option value="">Todos</option><option value="orcamento">Orçamento</option><option value="pedido">Pedido</option><option value="venda">Venda</option></select></div>
                        <div class="form-group"><label>Data Início</label><input type="date" name="data_inicio" value="<?php echo $filtro_data_inicio ?? date('Y-m-01'); ?>"></div>
                        <div class="form-group"><label>Data Fim</label><input type="date" name="data_fim" value="<?php echo $filtro_data_fim ?? date('Y-m-d'); ?>"></div>
                        <div class="form-group"><button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filtrar</button></div>
                    </form>
                </div>

                <?php if (!empty($cobrancas)): ?>
                <div class="card"><div class="table-responsive"><table class="table"><thead><tr><th>Número</th><th>Cliente</th><th>Origem</th><th>Valor</th><th>Vencimento</th><th>Status</th><th>Pagamento</th><th>Ações</th></tr></thead><tbody>
                    <?php foreach ($cobrancas as $item): 
                        $vencimento_info = getStatusVencimento($item['data_vencimento']);
                        $podeExcluir = ($item['status'] == 'pendente' || $item['status'] == 'cancelada');
                        $origem = '';
                        $link_origem = '';
                        if (!empty($item['orcamento_numero'])) { $origem = 'Orçamento'; $link_origem = "orcamentos.php?acao=editar&id={$item['orcamento_id']}"; }
                        elseif (!empty($item['pedido_numero'])) { $origem = 'Pedido'; $link_origem = "pedidos.php?acao=editar&id={$item['pedido_id']}"; }
                        elseif (!empty($item['venda_numero'])) { $origem = 'Venda'; $link_origem = "vendas.php?acao=editar&id={$item['venda_id']}"; }
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($item['numero'] ?? '#' . $item['id']); ?></strong></td>
                        <td><?php echo htmlspecialchars($item['cliente_nome']); ?></td>
                        <td><?php if ($link_origem) { echo "<a href='$link_origem' class='link-badge'>$origem</a>"; } else { echo "<small>Avulsa</small>"; } ?></td>
                        <td class="valor-destaque"><?php echo formatarMoeda($item['valor']); ?></td>
                        <td><?php echo formatarData($item['data_vencimento']); ?><br><small class="vencimento-<?php echo $vencimento_info['classe']; ?>"><?php echo $vencimento_info['texto']; ?></small></td>
                        <td><?php echo getStatusBadge($item['status']); ?></td>
                        <td><?php if ($item['tipo_pagamento']) { echo "<small>" . ucfirst($item['tipo_pagamento']) . "</small>"; if ($item['data_recebimento']) { echo "<br><small>" . formatarData($item['data_recebimento']) . "</small>"; } } ?></td>
                        <td><div class="btn-group">
                            <?php if ($item['status'] != 'recebida' && $item['status'] != 'cancelada'): ?>
                                <a href="?acao=baixar&id=<?php echo $item['id']; ?>" class="btn btn-sm btn-success" title="Receber"><i class="fas fa-check-circle"></i></a>
                            <?php endif; ?>
                            <a href="?acao=editar&id=<?php echo $item['id']; ?>" class="btn btn-sm btn-primary" title="Editar"><i class="fas fa-edit"></i></a>
                            <?php if ($item['status'] != 'recebida' && $item['status'] != 'cancelada'): ?>
                                <a href="?acao=cancelar_get&id=<?php echo $item['id']; ?>" class="btn btn-sm btn-warning" title="Cancelar" onclick="return confirm('Cancelar esta cobrança?')"><i class="fas fa-times"></i></a>
                            <?php endif; ?>
                            <a href="?acao=enviar_whatsapp&id=<?php echo $item['id']; ?>" class="btn btn-sm btn-whatsapp" title="WhatsApp" target="_blank"><i class="fab fa-whatsapp"></i></a>
                            <?php if ($podeExcluir): ?>
                                <form method="POST" style="display:inline" onsubmit="return confirm('Excluir esta cobrança?')"><input type="hidden" name="acao" value="deletar"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="id" value="<?php echo $item['id']; ?>"><button type="submit" class="btn btn-sm btn-danger" title="Excluir"><i class="fas fa-trash"></i></button></form>
                            <?php else: ?>
                                <button class="btn btn-sm btn-secondary" disabled><i class="fas fa-trash"></i></button>
                            <?php endif; ?>
                        </div></td>
                    </tr>
                    <?php if (!empty($item['observacao'])): ?>
                    <tr style="background:#f8f9fa;"><td colspan="8"><small><strong>Obs:</strong> <?php echo htmlspecialchars($item['observacao']); ?></small></td></tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </tbody></table></div></div>
                <?php else: ?>
                <div class="empty-message"><i class="fas fa-credit-card fa-4x" style="color:#ccc; margin-bottom:20px;"></i><h3 style="color:#666;">Nenhuma cobrança encontrada</h3><a href="?acao=novo" class="btn btn-primary" style="margin-top:20px;"><i class="fas fa-plus-circle"></i> Nova Cobrança</a></div>
                <?php endif; ?>

            <?php elseif ($acao === 'novo' || $acao === 'editar'): ?>

                <div class="page-header">
                    <h1><i class="fas fa-<?php echo $acao === 'novo' ? 'plus-circle' : 'edit'; ?>"></i> <?php echo $acao === 'novo' ? 'Nova Cobrança' : 'Editar Cobrança'; ?></h1>
                    <a href="?acao=listar" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Voltar</a>
                </div>

                <div class="card"><div class="card-header"><h3><i class="fas fa-credit-card"></i> Informações da Cobrança</h3></div><div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="acao" value="salvar"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <?php if ($acao === 'editar'): ?><input type="hidden" name="id" value="<?php echo $cobranca['id']; ?>"><?php endif; ?>
                        <div class="form-row">
                            <div class="form-group"><label>Cliente *</label><select name="cliente_id" required><option value="">Selecione</option><?php foreach ($clientes as $cli): ?><option value="<?php echo $cli['id']; ?>" <?php echo ($cobranca['cliente_id'] ?? '') == $cli['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cli['nome']); ?></option><?php endforeach; ?></select></div>
                            <div class="form-group"><label>Número</label><input type="text" name="numero" value="<?php echo htmlspecialchars($cobranca['numero'] ?? ''); ?>" placeholder="Deixe em branco para gerar automático"></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label>Valor *</label><input type="text" name="valor" value="<?php echo isset($cobranca['valor']) ? formatarMoeda($cobranca['valor']) : 'R$ 0,00'; ?>" required></div>
                            <div class="form-group"><label>Data Vencimento *</label><input type="date" name="data_vencimento" value="<?php echo $cobranca['data_vencimento'] ?? date('Y-m-d', strtotime('+30 days')); ?>" required></div>
                            <div class="form-group"><label>Status</label><select name="status"><option value="pendente">Pendente</option><option value="vencida">Vencida</option><option value="recebida">Recebida</option><option value="cancelada">Cancelada</option></select></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label>Data Recebimento</label><input type="date" name="data_recebimento" value="<?php echo $cobranca['data_recebimento'] ?? ''; ?>"></div>
                            <div class="form-group"><label>Tipo Pagamento</label><select name="tipo_pagamento"><option value="">Selecione</option><option value="dinheiro">Dinheiro</option><option value="debito">Débito</option><option value="credito">Crédito</option><option value="pix">PIX</option><option value="boleto">Boleto</option></select></div>
                        </div>
                        <div class="form-group"><label>Observações</label><textarea name="observacao" rows="3"><?php echo htmlspecialchars($cobranca['observacao'] ?? ''); ?></textarea></div>
                        <div style="margin-top:30px; text-align:right;"><button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Salvar</button> <a href="?acao=listar" class="btn btn-secondary">Cancelar</a></div>
                    </form>
                </div></div>

            <?php elseif ($acao === 'baixar' && !empty($cobranca)): ?>

                <div class="page-header"><h1><i class="fas fa-check-circle"></i> Receber Cobrança</h1><a href="?acao=listar" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Voltar</a></div>
                <div class="card"><div class="card-header"><h3>Cobrança: <?php echo htmlspecialchars($cobranca['numero'] ?? '#' . $cobranca['id']); ?></h3></div><div class="card-body">
                    <div style="background:#f8f9fa; padding:20px; border-radius:8px; margin-bottom:20px;"><div class="form-row"><div class="form-group"><label>Cliente</label><input type="text" value="<?php echo htmlspecialchars($cobranca['cliente_nome']); ?>" class="form-control" readonly></div><div class="form-group"><label>Valor</label><input type="text" value="<?php echo formatarMoeda($cobranca['valor']); ?>" class="form-control valor-destaque" readonly></div><div class="form-group"><label>Vencimento</label><input type="text" value="<?php echo formatarData($cobranca['data_vencimento']); ?>" class="form-control" readonly></div></div></div>
                    <form method="POST"><input type="hidden" name="acao" value="baixar"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="id" value="<?php echo $cobranca['id']; ?>">
                        <div class="form-row"><div class="form-group"><label>Data Recebimento *</label><input type="date" name="data_recebimento" value="<?php echo date('Y-m-d'); ?>" required></div><div class="form-group"><label>Tipo Pagamento *</label><select name="tipo_pagamento" required><option value="">Selecione</option><option value="dinheiro">Dinheiro</option><option value="debito">Débito</option><option value="credito">Crédito</option><option value="pix">PIX</option><option value="boleto">Boleto</option></select></div></div>
                        <div style="margin-top:30px; text-align:right;"><button type="submit" class="btn btn-success"><i class="fas fa-check-circle"></i> Confirmar Recebimento</button> <a href="?acao=listar" class="btn btn-secondary">Cancelar</a></div>
                    </form>
                </div></div>

            <?php endif; ?>
        </main>
    </div>
</body>
</html>