<?php
/**
 * =====================================================================
 * ORÇAMENTOS - SISTEMA UNIFICADO IMPÉRIO AR (COM WORKFLOW INTEGRADO)
 * =====================================================================
 * 
 * Funcionalidades:
 * - App-1 (9 botões) + App-2 (11 botões) + Site do Cliente
 * - Gestão completa de orçamentos, pedidos, vendas
 * - Workflow automático: Aprovado -> Pedido, Concluído -> Venda + Cobrança
 * - IA integrada para diagnósticos
 * - WhatsApp automatizado com redirecionamento
 * - Calculadora de carga térmica
 * - Especificações técnicas
 * - Tempo de execução dos serviços em horas:minutos
 * - Categorias nos selects de produtos e serviços
 * - Recibo, Contrato, PDF, Garantia
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/environment.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/PDF.php';
require_once __DIR__ . '/../../classes/IA.php';
require_once __DIR__ . '/../../classes/Financeiro.php';
require_once __DIR__ . '/../../classes/Workflow.php';

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
    die("Erro de conexão com banco de dados: " . mysqli_connect_error());
}

// ===== FUNÇÃO PARA DETECTAR DISPOSITIVO MÓVEL =====
function isMobileDevice() {
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $mobile_keywords = ['android', 'iphone', 'ipad', 'ipod', 'mobile', 'blackberry', 'windows phone', 'opera mini', 'iemobile'];
    
    foreach ($mobile_keywords as $keyword) {
        if (stripos($user_agent, $keyword) !== false) {
            return true;
        }
    }
    return false;
}

// ===== FUNÇÕES AUXILIARES =====
if (!function_exists('formatarMoeda')) {
    function formatarMoeda($valor) {
        if (empty($valor) || !is_numeric($valor)) $valor = 0;
        return 'R$ ' . number_format(floatval($valor), 2, ',', '.');
    }
}

if (!function_exists('moedaParaFloat')) {
    function moedaParaFloat($valor) {
        if (empty($valor)) return 0;
        $valor = str_replace(['R$', ' ', '.'], '', $valor);
        $valor = str_replace(',', '.', $valor);
        return floatval($valor);
    }
}

// ===== FUNÇÃO GERAR LINK WHATSAPP BUSINESS (ATUALIZADA) =====
if (!function_exists('gerarLinkWhatsApp')) {
    function gerarLinkWhatsApp($whatsapp, $mensagem) {
        $numero = preg_replace('/[^0-9]/', '', $whatsapp);
        if (substr($numero, 0, 2) !== '55') {
            $numero = '55' . $numero;
        }
        $mensagem_encoded = rawurlencode($mensagem);
        $is_mobile = isMobileDevice();
        
        if ($is_mobile) {
            return "whatsapp://send?phone={$numero}&text={$mensagem_encoded}";
        } else {
            return "https://web.whatsapp.com/send?phone={$numero}&text={$mensagem_encoded}";
        }
    }
}

if (!function_exists('gerarLinkWhatsAppBusiness')) {
    function gerarLinkWhatsAppBusiness($whatsapp, $mensagem) {
        $numero = preg_replace('/[^0-9]/', '', $whatsapp);
        if (substr($numero, 0, 2) !== '55') {
            $numero = '55' . $numero;
        }
        $mensagem_encoded = rawurlencode($mensagem);
        $is_mobile = isMobileDevice();
        
        if ($is_mobile) {
            return "whatsapp://business?phone={$numero}&text={$mensagem_encoded}";
        } else {
            return "https://web.whatsapp.com/send?phone={$numero}&text={$mensagem_encoded}";
        }
    }
}

if (!function_exists('formatarTempo')) {
    function formatarTempo($minutos) {
        if (empty($minutos) || !is_numeric($minutos)) {
            return '';
        }
        $horas = floor($minutos / 60);
        $min_restantes = $minutos % 60;
        if ($horas > 0) {
            return sprintf("%dh %02dmin", $horas, $min_restantes);
        } else {
            return sprintf("%dmin", $min_restantes);
        }
    }
}

// ===== FUNÇÃO PARA BUSCAR PRODUTOS COM UNIDADE E KG =====
function buscarItensProdutosComUnidade($conexao, $orcamento_id) {
    $itens = [];
    $sql = "SELECT op.*, 
                   p.nome, 
                   p.valor_venda,
                   p.unidade_medida,
                   p.kg_por_metro,
                   p.categoria_id
            FROM orcamento_produtos op 
            JOIN produtos p ON op.produto_id = p.id 
            WHERE op.orcamento_id = ?";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $orcamento_id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    while ($row = $resultado->fetch_assoc()) {
        $itens[] = $row;
    }
    $stmt->close();
    return $itens;
}

// ===== FUNÇÃO UNIFICADA DE ENVIO WHATSAPP =====
function enviarOrcamentoWhatsApp($conexao, $id) {
    try {
        $sql = "SELECT o.*, c.nome as cliente_nome, c.whatsapp as cliente_whatsapp
                FROM orcamentos o
                LEFT JOIN clientes c ON o.cliente_id = c.id
                WHERE o.id = ?";
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $orcamento_wa = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$orcamento_wa) {
            throw new Exception("Orçamento não encontrado");
        }

        $whatsapp = $orcamento_wa['cliente_whatsapp'] ?? '';
        if (empty($whatsapp)) {
            throw new Exception("Cliente não possui whatsapp cadastrado");
        }

        $itens_produtos = buscarItensProdutosComUnidade($conexao, $id);
        $itens_servicos = buscarItensServicos($conexao, $id);
        
        $chave_pix = '(17) 9 9624-0725';
        $whatsapp_empresa = '5517996240725';
        $valor_total = floatval($orcamento_wa['valor_total']);
        
        $mensagem = "IMPERIO AR - ORCAMENTO\n";
        $mensagem .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $mensagem .= "Cliente: " . ($orcamento_wa['cliente_nome'] ?? 'N/I') . "\n";
        $mensagem .= "Data: " . date('d/m/Y') . "\n";
        $mensagem .= "Orcamento Nº: " . ($orcamento_wa['numero'] ?? '#' . $id) . "\n\n";
        
        $mensagem .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $mensagem .= "MATERIAIS\n";
        $mensagem .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        
        if (!empty($itens_produtos)) {
            foreach ($itens_produtos as $item) {
                $quantidade = floatval($item['quantidade']);
                $unidade = $item['unidade_medida'] ?? 'UN';
                $kg_por_metro = floatval($item['kg_por_metro'] ?? 0);
                $categoria_id = intval($item['categoria_id'] ?? 0);
                
                $quantidade_txt = '';
                
                switch ($unidade) {
                    case 'MT':
                        if ($categoria_id == 15 && $kg_por_metro > 0) {
                            $kg_total = $quantidade * $kg_por_metro;
                            $quantidade_txt = number_format($kg_total, 2, ',', '.') . ' kg';
                        } else {
                            $quantidade_txt = $quantidade . ' metros';
                        }
                        break;
                    case 'KG':
                        $quantidade_txt = $quantidade . ' kg';
                        break;
                    case 'LT':
                        $quantidade_txt = $quantidade . ' litros';
                        break;
                    default:
                        $quantidade_txt = $quantidade . ($quantidade > 1 ? ' unidades' : ' unidade');
                        break;
                }
                
                $mensagem .= "- " . $item['nome'] . " (" . $quantidade_txt . ")\n";
            }
        } else {
            $mensagem .= "Nenhum material informado.\n";
        }
        
        $mensagem .= "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $mensagem .= "MAO DE OBRA\n";
        $mensagem .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        
        if (!empty($itens_servicos)) {
            foreach ($itens_servicos as $item) {
                $quantidade = intval($item['quantidade']);
                $quantidade_txt = $quantidade > 1 ? " ({$quantidade}x)" : "";
                $mensagem .= "- " . $item['nome'] . $quantidade_txt . "\n";
                
                if (!empty($item['tempo_execucao'])) {
                    $tempo_formatado = formatarTempo($item['tempo_execucao']);
                    $mensagem .= "  Prazo: " . $tempo_formatado . "\n";
                }
            }
        } else {
            $mensagem .= "Nenhum servico informado.\n";
        }
        
        $mensagem .= "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $mensagem .= "VALOR TOTAL: " . formatarMoeda($valor_total) . "\n";
        $mensagem .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        $mensagem .= "PAGAMENTO\n";
        $mensagem .= "A vista (Dinheiro ou PIX): " . formatarMoeda($valor_total) . "\n";
        $mensagem .= "PIX: {$chave_pix}\n";
        $mensagem .= "Banco: Nu Pagamentos\n\n";
        
        $mensagem .= "OUTRAS FORMAS DE PAGAMENTO\n";
        $mensagem .= "Consulte-nos para outras formas de pagamento ou emissao de Nota Fiscal.\n";
        $mensagem .= "Os valores podem ser ajustados conforme a necessidade.\n\n";
        
        $mensagem .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $mensagem .= "EMPRESA\n";
        $mensagem .= "Imperio AR - Especialistas em Conforto Termico\n";
        $mensagem .= "Whatsapp: " . $whatsapp_empresa . "\n";
        $mensagem .= "www.imperioar.com.br\n";
        $mensagem .= "Este orcamento e valido por 5 dias.\n\n";
        
        $mensagem .= "ASSINATURA DIGITAL DO CONTRATO\n";
        $mensagem .= "Acesse o link abaixo para visualizar e assinar:\n";
        $mensagem .= "https://imperiodoar.com.br/assinar_contrato.php\n\n";
        $mensagem .= "Como acessar:\n";
        $mensagem .= "Digite seu CPF ou CNPJ (apenas numeros)\n\n";
        $mensagem .= "Apos a assinatura digital, daremos seguimento ao servico.\n";

        $check_column = $conexao->query("SHOW COLUMNS FROM orcamentos LIKE 'status'");
        if ($check_column && $check_column->num_rows > 0) {
            $sql_update = "UPDATE orcamentos SET status = 'enviado' WHERE id = ?";
            $stmt_update = $conexao->prepare($sql_update);
            $stmt_update->bind_param("i", $id);
            $stmt_update->execute();
            $stmt_update->close();
        }

        $link = gerarLinkWhatsApp($whatsapp, $mensagem);
        
        return [
            'success' => true,
            'link' => $link,
            'whatsapp' => $whatsapp,
            'is_mobile' => isMobileDevice()
        ];
        
    } catch (Exception $e) {
        error_log("Erro ao enviar WhatsApp: " . $e->getMessage());
        return [
            'success' => false,
            'erro' => $e->getMessage()
        ];
    }
}

// ===== FUNÇÕES PARA ITENS =====
function buscarItensProdutos($conexao, $orcamento_id) {
    $itens = [];
    $sql = "SELECT op.*, p.nome, p.valor_venda, c.nome as categoria_nome 
            FROM orcamento_produtos op 
            JOIN produtos p ON op.produto_id = p.id 
            LEFT JOIN categorias_produtos c ON p.categoria_id = c.id 
            WHERE op.orcamento_id = ?";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $orcamento_id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    while ($row = $resultado->fetch_assoc()) {
        $itens[] = $row;
    }
    $stmt->close();
    return $itens;
}

function buscarItensServicos($conexao, $orcamento_id) {
    $itens = [];
    $sql = "SELECT os.*, s.nome, s.valor_unitario, s.tempo_execucao, cat.nome as categoria_nome 
            FROM orcamento_servicos os 
            JOIN servicos s ON os.servico_id = s.id 
            LEFT JOIN categorias_servicos cat ON s.categoria_servico_id = cat.id 
            WHERE os.orcamento_id = ?";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $orcamento_id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    while ($row = $resultado->fetch_assoc()) {
        $itens[] = $row;
    }
    $stmt->close();
    return $itens;
}

// ===== CARREGAR DADOS =====
function carregarClientes($conexao) {
    $clientes = [];
    $sql = "SELECT id, nome, whatsapp, email, cpf_cnpj, 
                   endereco_rua, endereco_numero, endereco_bairro, 
                   endereco_cidade, endereco_estado, endereco_cep 
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
            WHERE p.ativo = 1 ORDER BY c.nome, p.nome ASC";
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
            LEFT JOIN categorias_servicos c ON s.categoria_servico_id = c.id 
            WHERE s.ativo = 1 ORDER BY c.nome, s.nome ASC";
    $resultado = $conexao->query($sql);
    if ($resultado) {
        while ($linha = $resultado->fetch_assoc()) {
            $servicos[] = $linha;
        }
    }
    return $servicos;
}

function carregarCategorias($conexao, $tipo = 'todos') {
    $categorias = [];
    $sql = "SELECT * FROM categorias_produtos WHERE ativo = 1";
    if ($tipo != 'todos') {
        $sql .= " AND tipo = ?";
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param("s", $tipo);
        $stmt->execute();
        $resultado = $stmt->get_result();
    } else {
        $sql .= " ORDER BY nome ASC";
        $resultado = $conexao->query($sql);
    }
    
    if ($resultado) {
        while ($linha = $resultado->fetch_assoc()) {
            $categorias[] = $linha;
        }
    }
    return $categorias;
}

// ===== VARIÁVEIS GLOBAIS =====
$acao = $_GET['acao'] ?? 'listar';
$id = isset($_GET['id']) ? intval($_GET['id']) : null;
$subacao = $_GET['subacao'] ?? '';
$mensagem = '';
$erro = '';
$orcamentos = [];
$clientes = carregarClientes($conexao);
$produtos = carregarProdutos($conexao);
$servicos = carregarServicos($conexao);
$materiais = [];
$categorias = carregarCategorias($conexao);
$orcamento = [];
$orcamento_recibo = [];
$pagina_atual = isset($_GET['pagina']) ? intval($_GET['pagina']) : 1;
$por_pagina = 20;

// ===== INICIALIZAR CLASSES =====
$pdf = new PDF($conexao);
$ia = new IA($conexao);
$financeiro = new Financeiro($conexao);
$workflow = new Workflow($conexao);

// ===== VALIDAÇÃO CSRF =====
function verificarCSRF($token) {
    return isset($_SESSION['csrf_token']) && $token === $_SESSION['csrf_token'];
}

// ===== PROCESSAR AÇÕES GET =====
if ($acao === 'enviar_whatsapp' && $id) {
    $resultado = enviarOrcamentoWhatsApp($conexao, $id);
    
    if ($resultado['success']) {
        echo "<!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Redirecionando para WhatsApp...</title>
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    min-height: 100vh;
                    margin: 0;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                }
                .container {
                    text-align: center;
                    background: white;
                    padding: 40px;
                    border-radius: 20px;
                    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                    max-width: 500px;
                    margin: 20px;
                }
                .spinner {
                    width: 50px;
                    height: 50px;
                    border: 5px solid #f3f3f3;
                    border-top: 5px solid #25D366;
                    border-radius: 50%;
                    animation: spin 1s linear infinite;
                    margin: 20px auto;
                }
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
                .btn {
                    display: inline-block;
                    background: #25D366;
                    color: white;
                    padding: 12px 24px;
                    text-decoration: none;
                    border-radius: 10px;
                    margin-top: 20px;
                    font-weight: 600;
                }
                .btn:hover {
                    background: #128C7E;
                }
                .info {
                    background: #e8f4f8;
                    padding: 15px;
                    border-radius: 10px;
                    margin-top: 20px;
                    font-size: 14px;
                    color: #1e3c72;
                }
            </style>
        </head>
        <body>
            <div class='container'>
                <h2>Redirecionando para WhatsApp</h2>
                <div class='spinner'></div>
                <p>Aguarde, abrindo o WhatsApp...</p>
                <p><strong>Destinatario:</strong> " . htmlspecialchars($resultado['whatsapp']) . "</p>
                <div class='info'>
                    <strong>Modo detectado:</strong> " . ($resultado['is_mobile'] ? "App Mobile" : "Web WhatsApp") . "
                </div>
                <a href='" . $resultado['link'] . "' class='btn' target='_blank'>
                    Clique aqui se nao abrir
                </a>
                <p style='margin-top: 20px;'>
                    <a href='?acao=listar' style='color: #666;'>← Voltar para lista</a>
                </p>
            </div>
            <script>
                window.location.href = '" . $resultado['link'] . "';
                setTimeout(function() {
                    if (!document.hidden) {
                        var info = document.querySelector('.info');
                        if (info) {
                            info.innerHTML += '<br><br>Se o WhatsApp nao abriu, verifique se o aplicativo esta instalado.';
                        }
                    }
                }, 3000);
            </script>
        </body>
        </html>";
        exit;
    } else {
        header('Location: ?acao=listar&erro=' . urlencode($resultado['erro']));
        exit;
    }
}

// DELETAR
if ($acao === 'deletar' && $id) {
    $conexao->begin_transaction();
    try {
        $conexao->query("DELETE FROM orcamento_produtos WHERE orcamento_id = $id");
        $conexao->query("DELETE FROM orcamento_servicos WHERE orcamento_id = $id");
        $conexao->query("DELETE FROM cobrancas WHERE orcamento_id = $id");
        $conexao->query("DELETE FROM orcamentos WHERE id = $id");
        $conexao->commit();
        header('Location: ' . BASE_URL . '/app/admin/orcamentos.php?mensagem=deletado');
        exit;
    } catch (Exception $e) {
        $conexao->rollback();
        $erro = "Erro ao deletar: " . $e->getMessage();
    }
}

// CONCLUIR
if ($acao === 'concluir' && $id) {
    $conexao->begin_transaction();
    try {
        $check_venda = $conexao->query("SELECT id, situacao FROM vendas WHERE orcamento_origem_id = $id");
        
        $sql = "UPDATE orcamentos SET situacao = 'concluido' WHERE id = ?";
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        
        if ($check_venda->num_rows == 0) {
            $workflow->criarVendaDeOrcamento($id);
            $mensagem_adicional = " Venda e cobranca geradas.";
        } else {
            $mensagem_adicional = " (Venda ja existente)";
        }
        
        $conexao->commit();
        header('Location: ' . BASE_URL . '/app/admin/orcamentos.php?mensagem=concluido' . urlencode($mensagem_adicional));
        exit;
    } catch (Exception $e) {
        $conexao->rollback();
        $erro = "Erro ao concluir: " . $e->getMessage();
    }
}

// REABRIR
if ($acao === 'reabrir' && $id) {
    $sql = "UPDATE orcamentos SET situacao = 'pendente' WHERE id = ?";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    
    header('Location: ' . BASE_URL . '/app/admin/orcamentos.php?mensagem=reaberto');
    exit;
}

// GERAR GARANTIA
if ($acao === 'gerar_garantia' && $id) {
    header('Location: ' . BASE_URL . '/app/admin/garantias.php?acao=nova&orcamento_id=' . $id);
    exit;
}

// RECIBO
if ($acao === 'recibo' && $id) {
    $orcamento_recibo = [];
    
    $tipo = $_GET['tipo'] ?? 'orcamento';
    
    if ($tipo === 'venda') {
        $sql = "SELECT v.*, c.nome as cliente_nome, c.whatsapp, c.email, c.cpf_cnpj,
                       c.endereco_rua, c.endereco_numero, c.endereco_bairro,
                       c.endereco_cidade, c.endereco_estado
                FROM vendas v 
                LEFT JOIN clientes c ON v.cliente_id = c.id 
                WHERE v.id = ?";
    } else {
        $sql = "SELECT o.*, c.nome as cliente_nome, c.whatsapp, c.email, c.cpf_cnpj,
                       c.endereco_rua, c.endereco_numero, c.endereco_bairro,
                       c.endereco_cidade, c.endereco_estado
                FROM orcamentos o 
                LEFT JOIN clientes c ON o.cliente_id = c.id 
                WHERE o.id = ?";
    }
    
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $orcamento_recibo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!empty($orcamento_recibo)) {
        if ($tipo !== 'venda') {
            $orcamento_recibo['itens_produtos'] = buscarItensProdutos($conexao, $id);
            $orcamento_recibo['itens_servicos'] = buscarItensServicos($conexao, $id);
        } else {
            $orcamento_recibo['itens_produtos'] = [];
            $orcamento_recibo['itens_servicos'] = [];
        }
    }
    
    $sql = "SELECT * FROM cobrancas WHERE venda_id = ? OR orcamento_id = ? ORDER BY id DESC LIMIT 1";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("ii", $id, $id);
    $stmt->execute();
    $cobranca = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $numero_recibo = 'REC-' . date('Ymd') . '-' . str_pad($id, 4, '0', STR_PAD_LEFT);
    
    include 'views/recibo.php';
    exit;
}

// ===== PROCESSAR AÇÕES POST =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verificarCSRF($_POST['csrf_token'])) {
        $erro = "Token de seguranca invalido.";
    } else {
        $acao_post = $_POST['acao'] ?? '';
        
        // ===== SALVAR ORÇAMENTO =====
        if ($acao_post === 'salvar') {
            $cliente_id = isset($_POST['cliente_id']) ? intval($_POST['cliente_id']) : 0;
            $data_emissao = $_POST['data_emissao'] ?? date('Y-m-d');
            $data_validade = $_POST['data_validade'] ?? date('Y-m-d', strtotime('+30 days'));
            $observacao = $conexao->real_escape_string($_POST['observacao'] ?? '');
            $tipo_registro = $_POST['tipo_registro'] ?? 'orcamento';
            $situacao = trim($_POST['situacao'] ?? 'pendente');
            
            $desconto_percentual = floatval($_POST['desconto_percentual'] ?? 0);
            $valor_adicional = moedaParaFloat($_POST['valor_adicional'] ?? '0,00');
            
            $id_editar = isset($_POST['id']) && !empty($_POST['id']) ? intval($_POST['id']) : null;
            
            $itens_produtos = [];
            if (isset($_POST['produtos']) && is_array($_POST['produtos'])) {
                foreach ($_POST['produtos'] as $index => $prod_id) {
                    if (!empty($prod_id) && isset($_POST['qtd_produtos'][$index]) && $_POST['qtd_produtos'][$index] > 0) {
                        $qtd = floatval($_POST['qtd_produtos'][$index]);
                        $valor_str = $_POST['valor_produtos'][$index] ?? '0,00';
                        $valor = moedaParaFloat($valor_str);
                        
                        $itens_produtos[] = [
                            'id' => intval($prod_id),
                            'quantidade' => $qtd,
                            'valor_unitario' => $valor,
                            'subtotal' => $qtd * $valor
                        ];
                    }
                }
            }
            
            $itens_servicos = [];
            if (isset($_POST['servicos']) && is_array($_POST['servicos'])) {
                foreach ($_POST['servicos'] as $index => $serv_id) {
                    if (!empty($serv_id) && isset($_POST['qtd_servicos'][$index]) && $_POST['qtd_servicos'][$index] > 0) {
                        $qtd = intval($_POST['qtd_servicos'][$index]);
                        $valor_str = $_POST['valor_servicos'][$index] ?? '0,00';
                        $valor = moedaParaFloat($valor_str);
                        
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
            
            $valor_custo = 0;
            foreach ($itens_produtos as $item) {
                $sql = "SELECT valor_compra FROM produtos WHERE id = ?";
                $stmt = $conexao->prepare($sql);
                $stmt->bind_param("i", $item['id']);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    $valor_custo += floatval($row['valor_compra']) * $item['quantidade'];
                }
                $stmt->close();
            }
            $valor_lucro = $valor_total - $valor_custo;
            
            if ($cliente_id <= 0) {
                $erro = "Cliente e obrigatorio";
            } elseif (empty($itens_produtos) && empty($itens_servicos)) {
                $erro = "Adicione pelo menos um produto ou servico";
            } else {
                $situacao_anterior = '';
                if ($id_editar) {
                    $sql_old = "SELECT situacao FROM orcamentos WHERE id = ?";
                    $stmt_old = $conexao->prepare($sql_old);
                    $stmt_old->bind_param("i", $id_editar);
                    $stmt_old->execute();
                    $old_data = $stmt_old->get_result()->fetch_assoc();
                    $situacao_anterior = $old_data['situacao'] ?? '';
                    $stmt_old->close();
                }
                
                if ($id_editar) {
                    $conexao->begin_transaction();
                    
                    try {
                        $sql = "UPDATE orcamentos SET 
                                cliente_id = ?,
                                data_emissao = ?,
                                data_validade = ?,
                                observacao = ?,
                                tipo_registro = ?,
                                situacao = ?,
                                desconto_percentual = ?,
                                valor_adicional = ?,
                                valor_total = ?,
                                valor_custo = ?,
                                valor_lucro = ?
                                WHERE id = ?";
                        
                        $stmt = $conexao->prepare($sql);
                        $stmt->bind_param(
                            "isssssdddddi",
                            $cliente_id,
                            $data_emissao,
                            $data_validade,
                            $observacao,
                            $tipo_registro,
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
                        
                        $conexao->query("DELETE FROM orcamento_produtos WHERE orcamento_id = $id_editar");
                        $conexao->query("DELETE FROM orcamento_servicos WHERE orcamento_id = $id_editar");
                        
                        if (!empty($itens_produtos)) {
                            $sql = "INSERT INTO orcamento_produtos (orcamento_id, produto_id, quantidade, valor_unitario, subtotal) 
                                    VALUES (?, ?, ?, ?, ?)";
                            $stmt = $conexao->prepare($sql);
                            foreach ($itens_produtos as $item) {
                                $stmt->bind_param("iiddd", $id_editar, $item['id'], $item['quantidade'], $item['valor_unitario'], $item['subtotal']);
                                $stmt->execute();
                            }
                            $stmt->close();
                        }
                        
                        if (!empty($itens_servicos)) {
                            $sql = "INSERT INTO orcamento_servicos (orcamento_id, servico_id, quantidade, valor_unitario, subtotal) 
                                    VALUES (?, ?, ?, ?, ?)";
                            $stmt = $conexao->prepare($sql);
                            foreach ($itens_servicos as $item) {
                                $stmt->bind_param("iiddd", $id_editar, $item['id'], $item['quantidade'], $item['valor_unitario'], $item['subtotal']);
                                $stmt->execute();
                            }
                            $stmt->close();
                        }
                        
                        $conexao->commit();
                        
                        if ($situacao_anterior != 'aprovado' && $situacao == 'aprovado') {
                            if (!$workflow->temPedido($id_editar)) {
                                $workflow->criarPedidoDeOrcamento($id_editar);
                            }
                        }
                        
                        if ($situacao_anterior != 'concluido' && $situacao == 'concluido') {
                            if (!$workflow->temVenda($id_editar)) {
                                $workflow->criarVendaDeOrcamento($id_editar);
                            }
                        }
                        
                        if ($tipo_registro == 'venda' && $situacao == 'aprovado') {
                            $financeiro->registrarEntrada([
                                'valor' => $valor_total,
                                'descricao' => "Venda #$id_editar",
                                'cliente_id' => $cliente_id,
                                'data' => $data_emissao
                            ]);
                        }
                        
                        header('Location: ' . BASE_URL . '/app/admin/orcamentos.php?mensagem=atualizado');
                        exit;
                        
                    } catch (Exception $e) {
                        $conexao->rollback();
                        $erro = "Erro ao atualizar: " . $e->getMessage();
                    }
                    
                } else {
                    $conexao->begin_transaction();
                    
                    try {
                        $numero = 'ORC-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                        
                        $sql = "INSERT INTO orcamentos (numero, cliente_id, data_emissao, data_validade, tipo_registro, situacao, observacao, desconto_percentual, valor_adicional, valor_total, valor_custo, valor_lucro) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        
                        $stmt = $conexao->prepare($sql);
                        $stmt->bind_param(
                            "sisssssddddd",
                            $numero,
                            $cliente_id,
                            $data_emissao,
                            $data_validade,
                            $tipo_registro,
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
                        
                        if (!empty($itens_produtos)) {
                            $sql = "INSERT INTO orcamento_produtos (orcamento_id, produto_id, quantidade, valor_unitario, subtotal) 
                                    VALUES (?, ?, ?, ?, ?)";
                            $stmt = $conexao->prepare($sql);
                            foreach ($itens_produtos as $item) {
                                $stmt->bind_param("iiddd", $novo_id, $item['id'], $item['quantidade'], $item['valor_unitario'], $item['subtotal']);
                                $stmt->execute();
                            }
                            $stmt->close();
                        }
                        
                        if (!empty($itens_servicos)) {
                            $sql = "INSERT INTO orcamento_servicos (orcamento_id, servico_id, quantidade, valor_unitario, subtotal) 
                                    VALUES (?, ?, ?, ?, ?)";
                            $stmt = $conexao->prepare($sql);
                            foreach ($itens_servicos as $item) {
                                $stmt->bind_param("iiddd", $novo_id, $item['id'], $item['quantidade'], $item['valor_unitario'], $item['subtotal']);
                                $stmt->execute();
                            }
                            $stmt->close();
                        }
                        
                        $conexao->commit();
                        
                        if ($situacao == 'aprovado') {
                            $workflow->criarPedidoDeOrcamento($novo_id);
                        }
                        
                        if ($situacao == 'concluido') {
                            $workflow->criarVendaDeOrcamento($novo_id);
                        }
                        
                        header('Location: ' . BASE_URL . '/app/admin/orcamentos.php?mensagem=criado');
                        exit;
                        
                    } catch (Exception $e) {
                        $conexao->rollback();
                        $erro = "Erro ao criar: " . $e->getMessage();
                    }
                }
            }
        }
        
        // ===== GERAR COBRANÇA =====
        if ($acao_post === 'gerar_cobranca' && isset($_POST['orcamento_id'])) {
            $orcamento_id = intval($_POST['orcamento_id']);
            $tipo_pagamento = $_POST['tipo_pagamento'] ?? 'a_vista';
            $valor = isset($_POST['valor']) ? floatval($_POST['valor']) : 0;
            $status = $_POST['situacao'] ?? 'pendente';
            $data_recebimento = ($status == 'recebida') ? ($_POST['data_pagamento'] ?? date('Y-m-d')) : null;
            
            $sql_busca = "SELECT cliente_id FROM orcamentos WHERE id = ?";
            $stmt_busca = $conexao->prepare($sql_busca);
            $stmt_busca->bind_param("i", $orcamento_id);
            $stmt_busca->execute();
            $orc_data = $stmt_busca->get_result()->fetch_assoc();
            $stmt_busca->close();
            
            if (!$orc_data) {
                $erro = "Orcamento nao encontrado";
            } elseif ($valor <= 0) {
                $erro = "Valor invalido";
            } else {
                do {
                    $numero = 'COB-' . date('Ymd') . '-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
                    $check_numero = $conexao->query("SELECT id FROM cobrancas WHERE numero = '$numero'");
                } while ($check_numero && $check_numero->num_rows > 0);
                
                $sql = "INSERT INTO cobrancas (numero, cliente_id, orcamento_id, valor, data_vencimento, status, tipo_pagamento, data_recebimento, observacao) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $data_vencimento = date('Y-m-d', strtotime('+30 days'));
                $observacao = "Cobranca gerada a partir do Orcamento #" . ($orcamento_id);
                
                $stmt = $conexao->prepare($sql);
                $stmt->bind_param(
                    "siidsssss",
                    $numero,
                    $orc_data['cliente_id'],
                    $orcamento_id,
                    $valor,
                    $data_vencimento,
                    $status,
                    $tipo_pagamento,
                    $data_recebimento,
                    $observacao
                );
                
                if ($stmt->execute()) {
                    $mensagem = "Cobranca gerada com sucesso!";
                    
                    if ($status == 'recebida') {
                        $check_table = $conexao->query("SHOW TABLES LIKE 'financeiro'");
                        if ($check_table && $check_table->num_rows > 0 && class_exists('Financeiro')) {
                            $financeiro = new Financeiro($conexao);
                            $financeiro->registrarEntrada([
                                'valor' => $valor,
                                'descricao' => "Cobranca #$numero",
                                'cliente_id' => $orc_data['cliente_id'],
                                'data' => $data_recebimento
                            ]);
                        }
                    }
                } else {
                    $erro = "Erro ao gerar cobranca: " . $stmt->error;
                }
                $stmt->close();
            }
        }
    }
}

// ===== LISTAR ORÇAMENTOS =====
if ($acao === 'listar') {
    $filtro_status = $_GET['status'] ?? '';
    $filtro_tipo = $_GET['tipo'] ?? '';
    $filtro_cliente = $_GET['cliente'] ?? '';
    $filtro_data_inicio = $_GET['data_inicio'] ?? '';
    $filtro_data_fim = $_GET['data_fim'] ?? '';
    $filtro_mes = $_GET['mes'] ?? date('m');
    $filtro_ano = $_GET['ano'] ?? date('Y');
    
    $offset = ($pagina_atual - 1) * $por_pagina;
    
    $sql = "SELECT o.*, c.nome as cliente_nome, c.whatsapp as cliente_whatsapp
            FROM orcamentos o 
            LEFT JOIN clientes c ON o.cliente_id = c.id 
            WHERE 1=1";
    $params = [];
    $types = "";
    
    if ($filtro_status) {
        $sql .= " AND o.situacao = ?";
        $params[] = $filtro_status;
        $types .= "s";
    }
    
    if ($filtro_tipo) {
        $sql .= " AND o.tipo_registro = ?";
        $params[] = $filtro_tipo;
        $types .= "s";
    }
    
    if ($filtro_cliente) {
        $sql .= " AND (c.nome LIKE ? OR c.whatsapp LIKE ?)";
        $params[] = "%$filtro_cliente%";
        $params[] = "%$filtro_cliente%";
        $types .= "ss";
    }
    
    if (!empty($filtro_data_inicio) && !empty($filtro_data_fim)) {
        $sql .= " AND DATE(o.data_emissao) BETWEEN ? AND ?";
        $params[] = $filtro_data_inicio;
        $params[] = $filtro_data_fim;
        $types .= "ss";
    } else {
        $sql .= " AND MONTH(o.data_emissao) = ? AND YEAR(o.data_emissao) = ?";
        $params[] = $filtro_mes;
        $params[] = $filtro_ano;
        $types .= "ii";
    }
    
    $sql .= " ORDER BY o.data_emissao DESC LIMIT ? OFFSET ?";
    $params[] = $por_pagina;
    $params[] = $offset;
    $types .= "ii";
    
    $stmt = $conexao->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $orcamentos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $sql_total = "SELECT COUNT(*) as total FROM orcamentos o WHERE 1=1";
    if ($filtro_status) $sql_total .= " AND o.situacao = '$filtro_status'";
    if ($filtro_tipo) $sql_total .= " AND o.tipo_registro = '$filtro_tipo'";
    
    $resultado_total = $conexao->query($sql_total);
    $total = $resultado_total->fetch_assoc()['total'];
    $total_paginas = ceil($total / $por_pagina);
    
    $stats = [
        'pendente' => 0,
        'aprovado' => 0,
        'concluido' => 0,
        'cancelado' => 0,
        'orcamento' => 0,
        'pedido' => 0,
        'venda' => 0
    ];
    
    $sql_stats = "SELECT situacao, COUNT(*) as total 
                  FROM orcamentos 
                  WHERE MONTH(data_emissao) = ? AND YEAR(data_emissao) = ? 
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
    
    $sql_tipos = "SELECT tipo_registro, COUNT(*) as total 
                  FROM orcamentos 
                  WHERE MONTH(data_emissao) = ? AND YEAR(data_emissao) = ? 
                  GROUP BY tipo_registro";
    $stmt_tipos = $conexao->prepare($sql_tipos);
    $stmt_tipos->bind_param("ii", $filtro_mes, $filtro_ano);
    $stmt_tipos->execute();
    $result_tipos = $stmt_tipos->get_result();
    while ($row = $result_tipos->fetch_assoc()) {
        if (isset($stats[$row['tipo_registro']])) {
            $stats[$row['tipo_registro']] = $row['total'];
        }
    }
    $stmt_tipos->close();
    
    if (isset($_GET['mensagem'])) {
        $mapa_mensagens = [
            'criado' => "Orcamento criado com sucesso!",
            'atualizado' => "Orcamento atualizado com sucesso!",
            'deletado' => "Orcamento deletado com sucesso!",
            'concluido' => "Orcamento concluido! Venda e cobranca geradas.",
            'reaberto' => "Orcamento reaberto com sucesso!",
            'whatsapp_enviado' => "Orcamento enviado com sucesso via WhatsApp!"
        ];
        $mensagem = $mapa_mensagens[$_GET['mensagem']] ?? '';
    }
    
    if (isset($_GET['erro'])) {
        $mapa_erros = [
            'whatsapp_falhou' => "Erro ao enviar mensagem via WhatsApp",
            'cliente_sem_whatsapp' => "Cliente nao possui whatsapp cadastrado"
        ];
        $erro = $mapa_erros[$_GET['erro']] ?? '';
    }
}

// ===== CARREGAR ORÇAMENTO PARA EDIÇÃO =====
if ($acao === 'editar' && $id) {
    $sql = "SELECT o.*, 
                   c.nome as cliente_nome, 
                   c.whatsapp, 
                   c.email, 
                   c.cpf_cnpj, 
                   c.endereco_rua, 
                   c.endereco_numero, 
                   c.endereco_bairro, 
                   c.endereco_cidade, 
                   c.endereco_estado, 
                   c.endereco_cep
            FROM orcamentos o 
            LEFT JOIN clientes c ON o.cliente_id = c.id 
            WHERE o.id = ?";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $orcamento = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($orcamento) {
        $orcamento['itens_produtos'] = buscarItensProdutos($conexao, $id);
        $orcamento['itens_servicos'] = buscarItensServicos($conexao, $id);
        $orcamento['itens_materiais'] = [];
        
        $check_pedido = $conexao->query("SELECT id, numero FROM pedidos WHERE orcamento_origem_id = $id LIMIT 1");
        $orcamento['pedido_vinculado'] = $check_pedido->fetch_assoc();
        
        $check_venda = $conexao->query("SELECT id, numero FROM vendas WHERE orcamento_origem_id = $id LIMIT 1");
        $orcamento['venda_vinculada'] = $check_venda->fetch_assoc();
        
        $sql_cobrancas = "SELECT * FROM cobrancas WHERE orcamento_id = ? ORDER BY id DESC";
        $stmt_cobrancas = $conexao->prepare($sql_cobrancas);
        $stmt_cobrancas->bind_param("i", $id);
        $stmt_cobrancas->execute();
        $orcamento['cobrancas'] = $stmt_cobrancas->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_cobrancas->close();
    } else {
        header('Location: ' . BASE_URL . '/app/admin/orcamentos.php');
        exit;
    }
}

// ===== NOVO ORÇAMENTO =====
if ($acao === 'novo') {
    $orcamento = [
        'id' => '',
        'cliente_id' => '',
        'data_emissao' => date('Y-m-d'),
        'data_validade' => date('Y-m-d', strtotime('+30 days')),
        'tipo_registro' => 'orcamento',
        'situacao' => 'pendente',
        'observacao' => '',
        'desconto_percentual' => 0,
        'valor_adicional' => 0,
        'valor_total' => 0,
        'valor_custo' => 0,
        'valor_lucro' => 0,
        'itens_produtos' => [],
        'itens_servicos' => [],
        'itens_materiais' => []
    ];
}

?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Orcamentos - Imperio AR (Sistema Unificado)</title>
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
        
        .btn-primary { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; }
        .btn-success { background: linear-gradient(135deg, var(--success), #34ce57); color: white; }
        .btn-danger { background: linear-gradient(135deg, var(--danger), #e04b5a); color: white; }
        .btn-warning { background: linear-gradient(135deg, var(--warning), #e0a800); color: #333; }
        .btn-info { background: linear-gradient(135deg, var(--info), #138496); color: white; }
        .btn-secondary { background: linear-gradient(135deg, #6c757d, #5a6268); color: white; }
        .btn-whatsapp { background: #25D366; color: white; }
        .btn-pdf { background: #dc3545; color: white; }
        
        .btn-sm { padding: 6px 12px; font-size: 13px; }
        .btn-lg { padding: 12px 24px; font-size: 16px; }
        
        .btn-group {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
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
            color: #000000;
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
        .badge-aprovado { background: #d4edda; color: #155724; }
        .badge-concluido { background: #d1ecf1; color: #0c5460; }
        .badge-cancelado { background: #f8d7da; color: #721c24; }
        .badge-orcamento { background: #e2d5f1; color: #563d7c; }
        .badge-pedido { background: #fff3cd; color: #856404; }
        .badge-venda { background: #d4edda; color: #155724; }
        .badge-enviado { background: #cce5ff; color: #004085; }
        
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
        
        .alert-info {
            background: #cce5ff;
            color: #004085;
            border: 1px solid #b8daff;
        }
        
        .valor-destaque {
            font-weight: bold;
            color: #28a745;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
        }
        
        .pagination a {
            padding: 8px 15px;
            background: white;
            border-radius: 6px;
            text-decoration: none;
            color: var(--primary);
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .pagination a:hover,
        .pagination a.active {
            background: var(--primary);
            color: white;
        }
        
        .empty-message {
            text-align: center;
            padding: 50px;
            background: white;
            border-radius: 12px;
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
            .item-row {
                grid-template-columns: 1fr;
            }
            .header-actions {
                flex-direction: column;
            }
            .form-row {
                grid-template-columns: 1fr;
            }
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
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
                        <i class="fas fa-file-invoice-dollar"></i>
                        Gerenciamento de Orcamentos
                    </h1>
                    <div class="header-actions">
                        <a href="?acao=novo" class="btn btn-primary">
                            <i class="fas fa-plus-circle"></i> Novo Orcamento
                        </a>
                    </div>
                </div>

                <div class="stats-grid">
                    <div class="stat-card" style="border-left: 5px solid #ffc107;">
                        <div class="stat-info">
                            <h3><?php echo $stats['pendente'] ?? 0; ?></h3>
                            <p>Pendentes</p>
                        </div>
                    </div>
                    <div class="stat-card" style="border-left: 5px solid #28a745;">
                        <div class="stat-info">
                            <h3><?php echo $stats['aprovado'] ?? 0; ?></h3>
                            <p>Aprovados</p>
                        </div>
                    </div>
                    <div class="stat-card" style="border-left: 5px solid #17a2b8;">
                        <div class="stat-info">
                            <h3><?php echo $stats['concluido'] ?? 0; ?></h3>
                            <p>Concluidos</p>
                        </div>
                    </div>
                    <div class="stat-card" style="border-left: 5px solid #dc3545;">
                        <div class="stat-info">
                            <h3><?php echo $stats['cancelado'] ?? 0; ?></h3>
                            <p>Cancelados</p>
                        </div>
                    </div>
                </div>

                <div class="filters">
                    <form method="GET" class="form-row">
                        <input type="hidden" name="acao" value="listar">
                        
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status">
                                <option value="">Todos</option>
                                <option value="pendente" <?php echo ($filtro_status ?? '') == 'pendente' ? 'selected' : ''; ?>>Pendente</option>
                                <option value="aprovado" <?php echo ($filtro_status ?? '') == 'aprovado' ? 'selected' : ''; ?>>Aprovado</option>
                                <option value="concluido" <?php echo ($filtro_status ?? '') == 'concluido' ? 'selected' : ''; ?>>Concluido</option>
                                <option value="cancelado" <?php echo ($filtro_status ?? '') == 'cancelado' ? 'selected' : ''; ?>>Cancelado</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Tipo</label>
                            <select name="tipo">
                                <option value="">Todos</option>
                                <option value="orcamento" <?php echo ($filtro_tipo ?? '') == 'orcamento' ? 'selected' : ''; ?>>Orcamento</option>
                                <option value="pedido" <?php echo ($filtro_tipo ?? '') == 'pedido' ? 'selected' : ''; ?>>Pedido</option>
                                <option value="venda" <?php echo ($filtro_tipo ?? '') == 'venda' ? 'selected' : ''; ?>>Venda</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Cliente</label>
                            <input type="text" name="cliente" placeholder="Nome ou WhatsApp" value="<?php echo htmlspecialchars($filtro_cliente ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label>Mes</label>
                            <select name="mes">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?php echo $m; ?>" <?php echo ($filtro_mes ?? date('m')) == $m ? 'selected' : ''; ?>>
                                        <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Ano</label>
                            <select name="ano">
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

                <?php if (!empty($orcamentos)): ?>
                <div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Número</th>
                    <th>Cliente</th>
                    <th>Data</th>
                    <th>Tipo</th>
                    <th>Status</th>
                    <th>Valor</th>
                    <th style="min-width: 200px;">Observações</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orcamentos as $item): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($item['numero'] ?? '#' . $item['id']); ?></strong></td>
                    <td><?php echo htmlspecialchars($item['cliente_nome']); ?></td>
                    <td><?php echo date('d/m/Y', strtotime($item['data_emissao'])); ?></td>
                    <td><span class="badge badge-<?php echo $item['tipo_registro'] ?? 'orcamento'; ?>"><?php echo ucfirst($item['tipo_registro'] ?? 'Orçamento'); ?></span></td>
                    <td><span class="badge badge-<?php echo $item['situacao']; ?>"><?php echo ucfirst($item['situacao']); ?></span></td>
                    <td><strong><?php echo formatarMoeda($item['valor_total']); ?></strong></td>
                    <td style="max-width: 250px; word-wrap: break-word; white-space: normal;">
                        <?php 
                        $observacao = trim($item['observacao'] ?? '');
                        if (!empty($observacao)):
                            // Limita o texto a 80 caracteres com tooltip completo
                            if (strlen($observacao) > 80):
                                echo '<span title="' . htmlspecialchars($observacao) . '" style="cursor: help;">';
                                echo htmlspecialchars(substr($observacao, 0, 80)) . '...';
                                echo '</span>';
                            else:
                                echo '<span title="' . htmlspecialchars($observacao) . '" style="cursor: help;">';
                                echo htmlspecialchars($observacao);
                                echo '</span>';
                            endif;
                        else:
                            echo '<span style="color: #999; font-style: italic;">—</span>';
                        endif;
                        ?>
                    </td>
                    <td>
                        <div class="btn-group">
                            <a href="?acao=editar&id=<?php echo $item['id']; ?>" class="btn btn-sm btn-primary" title="Editar">
                                <i class="fas fa-edit"></i>
                            </a>
                            
                            <?php if ($item['situacao'] != 'concluido'): ?>
                                <a href="?acao=concluir&id=<?php echo $item['id']; ?>" class="btn btn-sm btn-success" title="Concluir" onclick="return confirm('Confirmar conclusão? Venda e cobrança serão geradas.')">
                                    <i class="fas fa-check"></i>
                                </a>
                            <?php else: ?>
                                <a href="?acao=reabrir&id=<?php echo $item['id']; ?>" class="btn btn-sm btn-warning" title="Reabrir" onclick="return confirm('Reabrir este registro?')">
                                    <i class="fas fa-redo-alt"></i>
                                </a>
                            <?php endif; ?>
                            
                            <a href="?acao=recibo&id=<?php echo $item['id']; ?>" class="btn btn-sm btn-info" title="Recibo" target="_blank">
                                <i class="fas fa-receipt"></i>
                            </a>
                            
                            <a href="gerar_contrato_pdf.php?id=<?php echo $item['id']; ?>" class="btn btn-sm btn-info" title="Gerar Contrato" target="_blank" style="background: #6f42c1; border-color: #6f42c1;">
                                <i class="fas fa-file-contract"></i>
                            </a>
                            
                            <?php if (!empty($item['cliente_whatsapp'])): ?>
                            <a href="?acao=enviar_whatsapp&id=<?php echo $item['id']; ?>" class="btn btn-sm btn-success" title="Enviar WhatsApp" target="_blank">
                                <i class="fab fa-whatsapp"></i>
                            </a>
                            <?php else: ?>
                            <button class="btn btn-sm btn-secondary" title="Cliente sem WhatsApp" disabled>
                                <i class="fab fa-whatsapp"></i>
                            </button>
                            <?php endif; ?>
                            
                            <a href="gerar_pdf_orcamento.php?id=<?php echo $item['id']; ?>" class="btn btn-sm btn-danger" title="PDF" target="_blank">
                                <i class="fas fa-file-pdf"></i>
                            </a>
                            
                            <?php if ($item['situacao'] == 'concluido'): ?>
                                <a href="?acao=gerar_garantia&id=<?php echo $item['id']; ?>" class="btn btn-sm btn-warning" title="Garantia">
                                    <i class="fas fa-shield-alt"></i>
                                </a>
                            <?php endif; ?>
                            
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

                <?php if ($total_paginas > 1): ?>
                <div class="pagination">
                    <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
                    <a href="?pagina=<?php echo $i; ?>&status=<?php echo urlencode($filtro_status ?? ''); ?>&tipo=<?php echo urlencode($filtro_tipo ?? ''); ?>&cliente=<?php echo urlencode($filtro_cliente ?? ''); ?>&mes=<?php echo $filtro_mes ?? date('m'); ?>&ano=<?php echo $filtro_ano ?? date('Y'); ?>" 
                       class="<?php echo $i == $pagina_atual ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>

                <?php else: ?>
                <div class="empty-message">
                    <i class="fas fa-file-invoice fa-4x" style="color: #ccc;"></i>
                    <h3 style="color: #666; margin-top: 20px;">Nenhum orcamento encontrado</h3>
                    <a href="?acao=novo" class="btn btn-primary" style="margin-top: 20px;">
                        <i class="fas fa-plus-circle"></i> Criar Primeiro Orcamento
                    </a>
                </div>
                <?php endif; ?>

            <?php elseif ($acao === 'novo' || $acao === 'editar'): ?>

                <div class="page-header">
                    <h1>
                        <i class="fas fa-<?php echo $acao === 'novo' ? 'plus-circle' : 'edit'; ?>"></i>
                        <?php echo $acao === 'novo' ? 'Novo Orcamento' : 'Editar Orcamento #' . ($orcamento['numero'] ?? $id); ?>
                    </h1>
                    <div class="header-actions">
                        <a href="?acao=listar" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Voltar
                        </a>
                    </div>
                </div>

                <?php if ($acao === 'editar' && (!empty($orcamento['pedido_vinculado']) || !empty($orcamento['venda_vinculada']))): ?>
                <div class="alert alert-info">
                    <i class="fas fa-link"></i>
                    <strong>Vinculos ativos:</strong>
                    <?php if (!empty($orcamento['pedido_vinculado'])): ?>
                        <a href="pedidos.php?acao=editar&id=<?php echo $orcamento['pedido_vinculado']['id']; ?>" style="margin-left: 15px; color: #004085;">
                            <i class="fas fa-shopping-cart"></i> Pedido #<?php echo $orcamento['pedido_vinculado']['numero']; ?>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($orcamento['venda_vinculada'])): ?>
                        <a href="vendas.php?acao=editar&id=<?php echo $orcamento['venda_vinculada']['id']; ?>" style="margin-left: 15px; color: #004085;">
                            <i class="fas fa-dollar-sign"></i> Venda #<?php echo $orcamento['venda_vinculada']['numero']; ?>
                        </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-file-invoice"></i>
                            Informacoes do Registro
                        </h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="form-orcamento">
                            <input type="hidden" name="acao" value="salvar">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <?php if ($acao === 'editar'): ?>
                                <input type="hidden" name="id" value="<?php echo $orcamento['id']; ?>">
                            <?php endif; ?>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Cliente *</label>
                                    <select name="cliente_id" id="cliente_id" required class="form-control">
                                        <option value="">-- Selecione um cliente --</option>
                                        <?php foreach ($clientes as $cli): ?>
                                        <option value="<?php echo $cli['id']; ?>" 
                                                data-whatsapp="<?php echo $cli['whatsapp']; ?>"
                                                data-email="<?php echo $cli['email']; ?>"
                                                <?php echo ($orcamento['cliente_id'] ?? '') == $cli['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cli['nome'] . ' - ' . $cli['whatsapp']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Data de Emissao</label>
                                    <input type="date" name="data_emissao" value="<?php echo $orcamento['data_emissao'] ?? date('Y-m-d'); ?>" class="form-control">
                                </div>

                                <div class="form-group">
                                    <label>Data de Validade</label>
                                    <input type="date" name="data_validade" value="<?php echo $orcamento['data_validade'] ?? date('Y-m-d', strtotime('+30 days')); ?>" class="form-control">
                                </div>

                                <div class="form-group">
                                    <label>Tipo de Registro</label>
                                    <select name="tipo_registro" class="form-control">
                                        <option value="orcamento" <?php echo ($orcamento['tipo_registro'] ?? '') == 'orcamento' ? 'selected' : ''; ?>>Orcamento</option>
                                        <option value="pedido" <?php echo ($orcamento['tipo_registro'] ?? '') == 'pedido' ? 'selected' : ''; ?>>Pedido</option>
                                        <option value="venda" <?php echo ($orcamento['tipo_registro'] ?? '') == 'venda' ? 'selected' : ''; ?>>Venda</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label>Situacao</label>
                                    <select name="situacao" class="form-control">
                                        <option value="pendente" <?php echo ($orcamento['situacao'] ?? '') == 'pendente' ? 'selected' : ''; ?>>Pendente</option>
                                        <option value="aprovado" <?php echo ($orcamento['situacao'] ?? '') == 'aprovado' ? 'selected' : ''; ?>>Aprovado</option>
                                        <option value="concluido" <?php echo ($orcamento['situacao'] ?? '') == 'concluido' ? 'selected' : ''; ?>>Concluido</option>
                                        <option value="cancelado" <?php echo ($orcamento['situacao'] ?? '') == 'cancelado' ? 'selected' : ''; ?>>Cancelado</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Observacoes</label>
                                <textarea name="observacao" rows="3" class="form-control" placeholder="Observacoes gerais..."><?php echo htmlspecialchars($orcamento['observacao'] ?? ''); ?></textarea>
                            </div>

                            <h3 style="margin: 30px 0 20px; color: var(--primary);">
                                <i class="fas fa-box"></i> Produtos
                            </h3>
                            <div class="items-section">
                                <div id="produtos-container">
                                    <?php if (!empty($orcamento['itens_produtos'])): ?>
                                        <?php foreach ($orcamento['itens_produtos'] as $index => $item): ?>
                                        <div class="item-row" id="produto-row-<?php echo $index; ?>">
                                            <select name="produtos[]" class="produto-select" onchange="atualizarValorProduto(this)">
                                                <option value="">-- Selecione um produto --</option>
                                                <?php foreach ($produtos as $p): ?>
                                                <option value="<?php echo $p['id']; ?>" 
                                                        data-valor="<?php echo $p['valor_venda']; ?>"
                                                        data-custo="<?php echo $p['valor_compra']; ?>"
                                                        data-categoria="<?php echo htmlspecialchars($p['categoria_nome'] ?? 'Sem categoria'); ?>"
                                                        <?php echo ($item['produto_id'] ?? $item['id']) == $p['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($p['nome']); ?> 
                                                    (R$ <?php echo number_format($p['valor_venda'], 2, ',', '.'); ?>)
                                                    <?php if (!empty($p['categoria_nome'])): ?>
                                                        [<?php echo htmlspecialchars($p['categoria_nome']); ?>]
                                                    <?php endif; ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="number" name="qtd_produtos[]" value="<?php echo $item['quantidade']; ?>" min="1" step="1" class="form-control" onchange="calcularTotal()">
                                            <input type="text" name="valor_produtos[]" value="<?php echo number_format($item['valor_unitario'], 2, ',', '.'); ?>" class="form-control money" onchange="calcularTotal()">
                                            <input type="text" name="subtotal_produtos[]" value="<?php echo number_format($item['subtotal'], 2, ',', '.'); ?>" class="form-control" readonly>
                                            <button type="button" onclick="removerItem(this)" class="btn btn-sm btn-danger">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <button type="button" onclick="adicionarProduto()" class="btn btn-success btn-add-item">
                                    <i class="fas fa-plus-circle"></i> Adicionar Produto
                                </button>
                            </div>

                            <h3 style="margin: 30px 0 20px; color: var(--primary);">
                                <i class="fas fa-tools"></i> Servicos
                            </h3>
                            <div class="items-section">
                                <div id="servicos-container">
                                    <?php if (!empty($orcamento['itens_servicos'])): ?>
                                        <?php foreach ($orcamento['itens_servicos'] as $index => $item): ?>
                                        <div class="item-row" id="servico-row-<?php echo $index; ?>">
                                            <select name="servicos[]" class="servico-select" onchange="atualizarValorServico(this)">
                                                <option value="">-- Selecione um servico --</option>
                                                <?php foreach ($servicos as $s): ?>
                                                <option value="<?php echo $s['id']; ?>" 
                                                        data-valor="<?php echo $s['valor_unitario']; ?>"
                                                        data-categoria="<?php echo htmlspecialchars($s['categoria_nome'] ?? 'Sem categoria'); ?>"
                                                        <?php echo ($item['servico_id'] ?? $item['id']) == $s['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($s['nome']); ?> 
                                                    (R$ <?php echo number_format($s['valor_unitario'], 2, ',', '.'); ?>)
                                                    <?php if (!empty($s['categoria_nome'])): ?>
                                                        [<?php echo htmlspecialchars($s['categoria_nome']); ?>]
                                                    <?php endif; ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="number" name="qtd_servicos[]" value="<?php echo $item['quantidade']; ?>" min="1" step="1" class="form-control" onchange="calcularTotal()">
                                            <input type="text" name="valor_servicos[]" value="<?php echo number_format($item['valor_unitario'], 2, ',', '.'); ?>" class="form-control money" onchange="calcularTotal()">
                                            <input type="text" name="subtotal_servicos[]" value="<?php echo number_format($item['subtotal'], 2, ',', '.'); ?>" class="form-control" readonly>
                                            <button type="button" onclick="removerItem(this)" class="btn btn-sm btn-danger">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <button type="button" onclick="adicionarServico()" class="btn btn-success btn-add-item">
                                    <i class="fas fa-plus-circle"></i> Adicionar Servico
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
                                               value="<?php echo $orcamento['desconto_percentual'] ?? 0; ?>" 
                                               min="0" max="100" step="0.01" class="form-control" onchange="calcularTotal()">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Valor Adicional (R$)</label>
                                        <input type="text" name="valor_adicional" id="valor_adicional" 
                                               value="<?php echo isset($orcamento['valor_adicional']) ? number_format($orcamento['valor_adicional'], 2, ',', '.') : '0,00'; ?>" 
                                               class="form-control money" onchange="calcularTotal()">
                                    </div>
                                </div>

                                <div class="resumo-row">
                                    <span>Subtotal Produtos:</span>
                                    <span id="subtotal-produtos">R$ 0,00</span>
                                </div>
                                <div class="resumo-row">
                                    <span>Subtotal Servicos:</span>
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
                                    <i class="fas fa-save"></i> Salvar
                                </button>
                                
                                <?php if ($acao === 'editar' && !empty($orcamento['whatsapp'])): ?>
                                <a href="?acao=enviar_whatsapp&id=<?php echo $id; ?>" class="btn btn-whatsapp btn-lg" target="_blank">
                                    <i class="fab fa-whatsapp"></i> WhatsApp
                                </a>
                                <?php endif; ?>
                                
                                <a href="gerar_pdf_orcamento.php?id=<?php echo $id; ?>" class="btn btn-pdf btn-lg" target="_blank">
                                    <i class="fas fa-file-pdf"></i> PDF
                                </a>
                                
                                <?php if ($acao === 'editar' && ($orcamento['situacao'] ?? '') == 'concluido'): ?>
                                <a href="?acao=gerar_garantia&id=<?php echo $id; ?>" class="btn btn-warning btn-lg">
                                    <i class="fas fa-shield-alt"></i> Garantia
                                </a>
                                <?php endif; ?>
                                
                                <a href="?acao=listar" class="btn btn-secondary btn-lg">
                                    <i class="fas fa-times"></i> Cancelar
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if ($acao === 'editar' && !empty($orcamento['cobrancas'])): ?>
                <div class="card" style="margin-top: 30px;">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-credit-card"></i>
                            Cobrancas Vinculadas
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Numero</th>
                                        <th>Valor</th>
                                        <th>Vencimento</th>
                                        <th>Status</th>
                                        <th>Tipo</th>
                                        <th>Data Receb.</th>
                                        <th>Acoes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($orcamento['cobrancas'] as $cobranca): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($cobranca['numero'] ?? '#' . $cobranca['id']); ?></strong></td>
                                        <td class="valor-destaque"><?php echo formatarMoeda($cobranca['valor'] ?? 0); ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($cobranca['data_vencimento'] ?? date('Y-m-d'))); ?></td>
                                        <td>
                                            <span class="badge badge-<?php echo $cobranca['status'] ?? 'pendente'; ?>">
                                                <?php echo ucfirst($cobranca['status'] ?? 'Pendente'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php 
                                            $tipo = '';
                                            if (!empty($cobranca['tipo_pagamento'])) {
                                                $tipo = ucfirst($cobranca['tipo_pagamento']);
                                            }
                                            echo $tipo ?: '-';
                                            ?>
                                        </td>
                                        <td><?php echo !empty($cobranca['data_recebimento']) ? date('d/m/Y', strtotime($cobranca['data_recebimento'])) : '-'; ?></td>
                                        <td>
                                            <div class="btn-group">
                                                <?php if (($cobranca['status'] ?? '') == 'pendente'): ?>
                                                <a href="cobrancas.php?acao=baixar&id=<?php echo $cobranca['id']; ?>" class="btn btn-sm btn-success" title="Receber" target="_blank">
                                                    <i class="fas fa-check-circle"></i>
                                                </a>
                                                <?php endif; ?>
                                                
                                                <a href="cobrancas.php?acao=editar&id=<?php echo $cobranca['id']; ?>" class="btn btn-sm btn-primary" title="Editar" target="_blank">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                
                                                <a href="cobrancas.php?acao=enviar_whatsapp&id=<?php echo $cobranca['id']; ?>" class="btn btn-sm btn-whatsapp" title="WhatsApp" target="_blank">
                                                    <i class="fab fa-whatsapp"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <script>
                    const produtos = <?php echo json_encode($produtos); ?>;
                    const servicos = <?php echo json_encode($servicos); ?>;

                    function adicionarProduto() {
                        const container = document.getElementById('produtos-container');
                        const index = container.children.length;
                        const div = document.createElement('div');
                        div.className = 'item-row';
                        div.id = 'produto-row-' + Date.now();
                        
                        let html = '<select name="produtos[]" class="produto-select" onchange="atualizarValorProduto(this)">';
                        html += '<option value="">-- Selecione um produto --</option>';
                        
                        let produtosPorCategoria = {};
                        produtos.forEach(p => {
                            let categoria = p.categoria_nome || 'Sem categoria';
                            if (!produtosPorCategoria[categoria]) {
                                produtosPorCategoria[categoria] = [];
                            }
                            produtosPorCategoria[categoria].push(p);
                        });
                        
                        Object.keys(produtosPorCategoria).sort().forEach(categoria => {
                            html += `<optgroup label="${categoria}">`;
                            produtosPorCategoria[categoria].forEach(p => {
                                html += '<option value="' + p.id + '" data-valor="' + p.valor_venda + '" data-custo="' + (p.valor_compra || 0) + '">';
                                html += p.nome + ' (R$ ' + parseFloat(p.valor_venda).toFixed(2).replace('.', ',') + ')';
                                html += '</option>';
                            });
                            html += '</optgroup>';
                        });
                        
                        html += '</select>';
                        html += '<input type="number" name="qtd_produtos[]" value="1" min="1" step="1" class="form-control" onchange="calcularTotal()">';
                        html += '<input type="text" name="valor_produtos[]" value="0,00" class="form-control money" onchange="calcularTotal()">';
                        html += '<input type="text" name="subtotal_produtos[]" value="0,00" class="form-control" readonly>';
                        html += '<button type="button" onclick="removerItem(this)" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>';
                        
                        div.innerHTML = html;
                        container.appendChild(div);
                        
                        aplicarMascaraMonetaria(div.querySelector('.money'));
                        calcularTotal();
                    }

                    function adicionarServico() {
                        const container = document.getElementById('servicos-container');
                        const index = container.children.length;
                        const div = document.createElement('div');
                        div.className = 'item-row';
                        div.id = 'servico-row-' + Date.now();
                        
                        let html = '<select name="servicos[]" class="servico-select" onchange="atualizarValorServico(this)">';
                        html += '<option value="">-- Selecione um servico --</option>';
                        
                        let servicosPorCategoria = {};
                        servicos.forEach(s => {
                            let categoria = s.categoria_nome || 'Sem categoria';
                            if (!servicosPorCategoria[categoria]) {
                                servicosPorCategoria[categoria] = [];
                            }
                            servicosPorCategoria[categoria].push(s);
                        });
                        
                        Object.keys(servicosPorCategoria).sort().forEach(categoria => {
                            html += `<optgroup label="${categoria}">`;
                            servicosPorCategoria[categoria].forEach(s => {
                                html += '<option value="' + s.id + '" data-valor="' + s.valor_unitario + '">';
                                html += s.nome + ' (R$ ' + parseFloat(s.valor_unitario).toFixed(2).replace('.', ',') + ')';
                                html += '</option>';
                            });
                            html += '</optgroup>';
                        });
                        
                        html += '</select>';
                        html += '<input type="number" name="qtd_servicos[]" value="1" min="1" step="1" class="form-control" onchange="calcularTotal()">';
                        html += '<input type="text" name="valor_servicos[]" value="0,00" class="form-control money" onchange="calcularTotal()">';
                        html += '<input type="text" name="subtotal_servicos[]" value="0,00" class="form-control" readonly>';
                        html += '<button type="button" onclick="removerItem(this)" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>';
                        
                        div.innerHTML = html;
                        container.appendChild(div);
                        
                        aplicarMascaraMonetaria(div.querySelector('.money'));
                        calcularTotal();
                    }

                    function removerItem(botao) {
                        if (confirm('Remover este item?')) {
                            const row = botao.closest('.item-row');
                            if (row) {
                                row.remove();
                                calcularTotal();
                            }
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
                            const select = row.querySelector('select[name="produtos[]"]');
                            if (select && select.value) {
                                const qtd = parseFloat(row.querySelector('input[name="qtd_produtos[]"]').value) || 0;
                                const valorStr = row.querySelector('input[name="valor_produtos[]"]').value;
                                const valor = parseFloat(valorStr.replace(/\./g, '').replace(',', '.')) || 0;
                                const subtotal = qtd * valor;
                                
                                if (!isNaN(subtotal)) {
                                    totalProdutos += subtotal;
                                    const subtotalInput = row.querySelector('input[name="subtotal_produtos[]"]');
                                    if (subtotalInput) {
                                        subtotalInput.value = subtotal.toFixed(2).replace('.', ',');
                                    }
                                }
                            }
                        });
                        
                        document.querySelectorAll('#servicos-container .item-row').forEach(row => {
                            const select = row.querySelector('select[name="servicos[]"]');
                            if (select && select.value) {
                                const qtd = parseFloat(row.querySelector('input[name="qtd_servicos[]"]').value) || 0;
                                const valorStr = row.querySelector('input[name="valor_servicos[]"]').value;
                                const valor = parseFloat(valorStr.replace(/\./g, '').replace(',', '.')) || 0;
                                const subtotal = qtd * valor;
                                
                                if (!isNaN(subtotal)) {
                                    totalServicos += subtotal;
                                    const subtotalInput = row.querySelector('input[name="subtotal_servicos[]"]');
                                    if (subtotalInput) {
                                        subtotalInput.value = subtotal.toFixed(2).replace('.', ',');
                                    }
                                }
                            }
                        });
                        
                        document.getElementById('subtotal-produtos').textContent = 'R$ ' + totalProdutos.toFixed(2).replace('.', ',');
                        document.getElementById('subtotal-servicos').textContent = 'R$ ' + totalServicos.toFixed(2).replace('.', ',');
                        
                        const subtotalGeral = totalProdutos + totalServicos;
                        
                        const descontoPercentual = parseFloat(document.getElementById('desconto_percentual')?.value) || 0;
                        const descontoValor = subtotalGeral * (descontoPercentual / 100);
                        
                        const adicionalStr = document.getElementById('valor_adicional')?.value || '0,00';
                        const adicional = parseFloat(adicionalStr.replace(/\./g, '').replace(',', '.')) || 0;
                        
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
                        
                        input.addEventListener('blur', function() {
                            calcularTotal();
                        });
                    }

                    document.addEventListener('DOMContentLoaded', function() {
                        document.querySelectorAll('.money').forEach(input => {
                            aplicarMascaraMonetaria(input);
                        });
                        
                        calcularTotal();
                        
                        document.addEventListener('change', function(e) {
                            if (e.target.matches('input[type="number"], select')) {
                                calcularTotal();
                            }
                        });
                    });
                </script>

            <?php endif; ?>

        </main>
    </div>
</body>
</html>