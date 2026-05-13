<?php
/**
 * Garantias - Império AR
 * Gerenciamento de garantias emitidas para clientes
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

// Gerar número da garantia
function gerarNumeroGarantia($conexao) {
    $ano = date('Y');
    $mes = date('m');
    
    $sql = "SELECT COUNT(*) as total FROM garantias WHERE YEAR(created_at) = $ano AND MONTH(created_at) = $mes";
    $result = $conexao->query($sql);
    $total = $result->fetch_assoc()['total'];
    $sequencial = str_pad($total + 1, 4, '0', STR_PAD_LEFT);
    
    return "GAR-$ano$mes-$sequencial";
}

// Função para formatar número de telefone para WhatsApp (com +55)
function formatarTelefoneWhatsApp($telefone) {
    // Remove tudo que não é número
    $numero = preg_replace('/[^0-9]/', '', $telefone);
    
    // Remove o 9 extra se tiver 11 dígitos (padrão Brasil)
    if (strlen($numero) === 11) {
        // Mantém como está (DDD + 9 + número)
    } elseif (strlen($numero) === 10) {
        // Adiciona o 9 após o DDD
        $numero = substr($numero, 0, 2) . '9' . substr($numero, 2);
    }
    
    // Adiciona o código do Brasil +55
    if (substr($numero, 0, 2) !== '55') {
        $numero = '55' . $numero;
    }
    
    return $numero;
}

// Função para gerar mensagem da garantia para WhatsApp
function gerarMensagemWhatsApp($garantia, $cliente, $orcamento = null) {
    $tipos_garantia = [
        'instalacao' => 'Instalação',
        'manutencao' => 'Manutenção',
        'reparo' => 'Reparo',
        'pecas' => 'Peças',
        'servicos_gerais' => 'Serviços Gerais',
        'personalizado' => 'Personalizado'
    ];
    
    $tipo_texto = $tipos_garantia[$garantia['tipo']] ?? $garantia['tipo'];
    if ($garantia['tipo'] === 'personalizado' && !empty($garantia['tipo_personalizado'])) {
        $tipo_texto = $garantia['tipo_personalizado'];
    }
    
    // Listar coberturas
    $coberturas = [];
    if ($garantia['cobertura_vazamento_flanges']) $coberturas[] = "✓ Vazamento em flanges";
    if ($garantia['cobertura_vazamento_conexoes']) $coberturas[] = "✓ Vazamento em conexões";
    if ($garantia['cobertura_ruido_anormal']) $coberturas[] = "✓ Ruído anormal no equipamento";
    if ($garantia['cobertura_oxidacao_serpentina']) $coberturas[] = "✓ Oxidação da serpentina";
    if ($garantia['cobertura_defeito_placa']) $coberturas[] = "✓ Defeito na placa eletrônica";
    if ($garantia['cobertura_queima_compressor']) $coberturas[] = "✓ Queima do compressor";
    if ($garantia['cobertura_tubo_rompido']) $coberturas[] = "✓ Tubo rompido";
    if (!empty($garantia['cobertura_outros'])) $coberturas[] = "✓ " . $garantia['cobertura_outros'];
    
    $coberturas_texto = !empty($coberturas) ? implode("\n", $coberturas) : "Nenhuma cobertura específica";
    
    $mensagem = "🏷️ *CERTIFICADO DE GARANTIA - IMPÉRIO AR*\n\n";
    $mensagem .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    $mensagem .= "*Número da Garantia:* " . $garantia['numero'] . "\n";
    $mensagem .= "*Data de Emissão:* " . formatarData($garantia['data_emissao']) . "\n\n";
    
    $mensagem .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $mensagem .= "*DADOS DO CLIENTE*\n";
    $mensagem .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $mensagem .= "*Nome:* " . ($cliente['nome'] ?? 'Não informado') . "\n";
    if (!empty($cliente['cpf_cnpj'])) {
        $mensagem .= "*CPF/CNPJ:* " . $cliente['cpf_cnpj'] . "\n";
    }
    if (!empty($cliente['telefone'])) {
        $mensagem .= "*Telefone:* " . $cliente['telefone'] . "\n";
    }
    $mensagem .= "\n";
    
    $mensagem .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $mensagem .= "*DADOS DA GARANTIA*\n";
    $mensagem .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $mensagem .= "*Data do Serviço:* " . formatarData($garantia['data_servico']) . "\n";
    $mensagem .= "*Início da Garantia:* " . formatarData($garantia['data_inicio']) . "\n";
    $mensagem .= "*Validade:* " . formatarData($garantia['data_validade']) . "\n";
    $mensagem .= "*Duração:* " . $garantia['tempo_garantia_dias'] . " dias\n";
    $mensagem .= "*Tipo:* " . $tipo_texto . "\n";
    if (!empty($orcamento)) {
        $mensagem .= "*Orçamento:* " . $orcamento['numero'] . "\n";
    }
    if (!empty($garantia['responsavel_tecnico'])) {
        $mensagem .= "*Responsável Técnico:* " . $garantia['responsavel_tecnico'] . "\n";
    }
    $mensagem .= "\n";
    
    $mensagem .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $mensagem .= "*COBERTURAS DA GARANTIA*\n";
    $mensagem .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $mensagem .= $coberturas_texto . "\n\n";
    
    $mensagem .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $mensagem .= "*CONDIÇÕES DA GARANTIA*\n";
    $mensagem .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $mensagem .= $garantia['condicoes'] . "\n\n";
    
    if (!empty($garantia['observacoes'])) {
        $mensagem .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $mensagem .= "*OBSERVAÇÕES*\n";
        $mensagem .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $mensagem .= $garantia['observacoes'] . "\n\n";
    }
    
    $mensagem .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $mensagem .= "*IMPORTANTE:*\n";
    $mensagem .= "• Guarde este certificado em local seguro\n";
    $mensagem .= "• Apresente este documento para acionar a garantia\n";
    $mensagem .= "• Para serviços fora da garantia, consulte nossos preços\n\n";
    
    $mensagem .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $mensagem .= "*IMPÉRIO AR - REFRIGERAÇÃO*\n";
    $mensagem .= "📞 (17) 99624-0725\n";
    $mensagem .= "📧 contato@imperioar.com.br\n";
    $mensagem .= "🌐 https://imperiodoar.com.br\n";
    $mensagem .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $mensagem .= "\n*Documento emitido eletronicamente - Válido em todo território nacional*";
    
    return $mensagem;
}

// ===== PROCESSAR ENVIO VIA WHATSAPP =====
if (isset($_GET['enviar_whatsapp']) && isset($_GET['id'])) {
    $id_garantia = intval($_GET['id']);
    
    // Buscar dados da garantia
    $sql = "SELECT g.*, c.nome as cliente_nome, c.cpf_cnpj, c.telefone, c.whatsapp 
            FROM garantias g
            LEFT JOIN clientes c ON g.cliente_id = c.id
            WHERE g.id = $id_garantia";
    $result = $conexao->query($sql);
    $garantia_whatsapp = $result->fetch_assoc();
    
    if ($garantia_whatsapp) {
        // Buscar orçamento se existir
        $orcamento = null;
        if (!empty($garantia_whatsapp['orcamento_id'])) {
            $sql_orc = "SELECT numero FROM orcamentos WHERE id = " . $garantia_whatsapp['orcamento_id'];
            $result_orc = $conexao->query($sql_orc);
            if ($result_orc) {
                $orcamento = $result_orc->fetch_assoc();
            }
        }
        
        // Preparar dados do cliente
        $cliente = [
            'nome' => $garantia_whatsapp['cliente_nome'],
            'cpf_cnpj' => $garantia_whatsapp['cpf_cnpj'],
            'telefone' => $garantia_whatsapp['telefone']
        ];
        
        $mensagem = gerarMensagemWhatsApp($garantia_whatsapp, $cliente, $orcamento);
        
        // Número do WhatsApp do cliente (prioriza whatsapp, depois telefone)
        $numero_cliente = $garantia_whatsapp['whatsapp'] ?? $garantia_whatsapp['telefone'] ?? '';
        
        // Formatar número com +55
        $numero_cliente = formatarTelefoneWhatsApp($numero_cliente);
        
        if (!empty($numero_cliente) && strlen($numero_cliente) >= 12) {
            // URL do WhatsApp
            $whatsapp_url = "https://api.whatsapp.com/send?phone=" . $numero_cliente . "&text=" . urlencode($mensagem);
            
            // Redirecionar para o WhatsApp
            header('Location: ' . $whatsapp_url);
            exit;
        } else {
            $mensagem_erro = "Cliente não possui número de telefone válido para envio de WhatsApp.";
            $tipo_mensagem = "danger";
        }
    }
}

// ===== PROCESSAR AÇÕES =====
$acao = $_GET['acao'] ?? 'listar';
$id = isset($_GET['id']) ? intval($_GET['id']) : null;
$mensagem = '';
$erro = '';
$tipo_mensagem = '';

// Buscar dados para os selects
$clientes = $conexao->query("SELECT id, nome, cpf_cnpj, telefone, whatsapp FROM clientes WHERE ativo = 1 ORDER BY nome")->fetch_all(MYSQLI_ASSOC);

// Buscar orçamentos por cliente (via AJAX)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'orcamentos') {
    $cliente_id = intval($_GET['cliente_id']);
    $orcamentos_filtrados = [];
    
    if ($cliente_id > 0) {
        $sql_orc = "SELECT o.id, o.numero, o.valor_total, o.data_emissao, o.situacao, c.nome as cliente_nome 
                    FROM orcamentos o 
                    LEFT JOIN clientes c ON o.cliente_id = c.id 
                    WHERE o.cliente_id = $cliente_id
                    ORDER BY o.id DESC 
                    LIMIT 50";
        $result_orc = $conexao->query($sql_orc);
        if ($result_orc) {
            while ($row = $result_orc->fetch_assoc()) {
                $orcamentos_filtrados[] = $row;
            }
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($orcamentos_filtrados);
    exit;
}

// Buscar todos os orçamentos para quando já tem cliente selecionado
$orcamentos = [];
$cliente_selecionado = isset($_GET['cliente_id']) ? intval($_GET['cliente_id']) : 0;

// Buscar agendamentos
$agendamentos = [];
$sql_age = "SELECT a.id, a.data_agendamento, a.horario_inicio, c.nome as cliente_nome 
            FROM agendamentos a 
            LEFT JOIN clientes c ON a.cliente_id = c.id 
            WHERE a.status NOT IN ('cancelado') 
            ORDER BY a.data_agendamento DESC 
            LIMIT 100";
$result_age = $conexao->query($sql_age);
if ($result_age) {
    while ($row = $result_age->fetch_assoc()) {
        $agendamentos[] = $row;
    }
}

// Tipos de garantia
$tipos_garantia = [
    'instalacao' => 'Instalação',
    'manutencao' => 'Manutenção',
    'reparo' => 'Reparo',
    'pecas' => 'Peças',
    'servicos_gerais' => 'Serviços Gerais',
    'personalizado' => 'Personalizado'
];

// Opções de cobertura
$coberturas = [
    'vazamento_flanges' => 'Vazamento em flanges',
    'vazamento_conexoes' => 'Vazamento em conexões',
    'ruido_anormal' => 'Ruído anormal no equipamento',
    'oxidacao_serpentina' => 'Oxidação da serpentina',
    'defeito_placa' => 'Defeito na placa eletrônica',
    'queima_compressor' => 'Queima do compressor',
    'tubo_rompido' => 'Tubo rompido'
];

// ===== PROCESSAR POST =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao_post = $_POST['acao'] ?? '';
    
    if ($acao_post === 'salvar') {
        $cliente_id = intval($_POST['cliente_id']);
        $orcamento_id = !empty($_POST['orcamento_id']) ? intval($_POST['orcamento_id']) : null;
        $agendamento_id = !empty($_POST['agendamento_id']) ? intval($_POST['agendamento_id']) : null;
        $data_servico = $_POST['data_servico'] ?? date('Y-m-d');
        $tempo_garantia_dias = intval($_POST['tempo_garantia_dias'] ?? 90);
        $data_inicio = $_POST['data_inicio'] ?? date('Y-m-d');
        $data_validade = date('Y-m-d', strtotime($data_inicio . " + $tempo_garantia_dias days"));
        $tipo = $_POST['tipo'] ?? 'servicos_gerais';
        $tipo_personalizado = ($tipo === 'personalizado' && isset($_POST['tipo_personalizado'])) ? trim($_POST['tipo_personalizado']) : null;
        $condicoes = trim($_POST['condicoes'] ?? '');
        $observacoes = trim($_POST['observacoes'] ?? '');
        $responsavel_tecnico = trim($_POST['responsavel_tecnico'] ?? '');
        
        // Coberturas
        $cobertura_vazamento_flanges = isset($_POST['cobertura_vazamento_flanges']) ? 1 : 0;
        $cobertura_vazamento_conexoes = isset($_POST['cobertura_vazamento_conexoes']) ? 1 : 0;
        $cobertura_ruido_anormal = isset($_POST['cobertura_ruido_anormal']) ? 1 : 0;
        $cobertura_oxidacao_serpentina = isset($_POST['cobertura_oxidacao_serpentina']) ? 1 : 0;
        $cobertura_defeito_placa = isset($_POST['cobertura_defeito_placa']) ? 1 : 0;
        $cobertura_queima_compressor = isset($_POST['cobertura_queima_compressor']) ? 1 : 0;
        $cobertura_tubo_rompido = isset($_POST['cobertura_tubo_rompido']) ? 1 : 0;
        $cobertura_outros = trim($_POST['cobertura_outros'] ?? '');
        
        $id_editar = isset($_POST['id']) ? intval($_POST['id']) : 0;
        
        if ($cliente_id <= 0) {
            $erro = "Selecione um cliente";
        } elseif (empty($condicoes)) {
            $erro = "Informe as condições da garantia";
        } else {
            $data_emissao = date('Y-m-d');
            $numero = $id_editar ? null : gerarNumeroGarantia($conexao);
            
            // Verificar se as colunas existem na tabela
            $colunas = [];
            $sql_colunas = "SHOW COLUMNS FROM garantias";
            $result_colunas = $conexao->query($sql_colunas);
            if ($result_colunas) {
                while ($row = $result_colunas->fetch_assoc()) {
                    $colunas[$row['Field']] = true;
                }
            }
            
            if ($id_editar) {
                // Atualizar garantia existente
                $campos = [];
                $params = [];
                $tipos = "";
                
                $campos[] = "cliente_id = ?";
                $params[] = $cliente_id;
                $tipos .= "i";
                
                if (isset($colunas['orcamento_id'])) {
                    $campos[] = "orcamento_id = ?";
                    $params[] = $orcamento_id;
                    $tipos .= "i";
                }
                
                if (isset($colunas['agendamento_id'])) {
                    $campos[] = "agendamento_id = ?";
                    $params[] = $agendamento_id;
                    $tipos .= "i";
                }
                
                if (isset($colunas['data_servico'])) {
                    $campos[] = "data_servico = ?";
                    $params[] = $data_servico;
                    $tipos .= "s";
                }
                
                if (isset($colunas['tempo_garantia_dias'])) {
                    $campos[] = "tempo_garantia_dias = ?";
                    $params[] = $tempo_garantia_dias;
                    $tipos .= "i";
                }
                
                if (isset($colunas['data_inicio'])) {
                    $campos[] = "data_inicio = ?";
                    $params[] = $data_inicio;
                    $tipos .= "s";
                }
                
                if (isset($colunas['data_validade'])) {
                    $campos[] = "data_validade = ?";
                    $params[] = $data_validade;
                    $tipos .= "s";
                }
                
                if (isset($colunas['tipo'])) {
                    $campos[] = "tipo = ?";
                    $params[] = $tipo;
                    $tipos .= "s";
                }
                
                if (isset($colunas['tipo_personalizado'])) {
                    $campos[] = "tipo_personalizado = ?";
                    $params[] = $tipo_personalizado;
                    $tipos .= "s";
                }
                
                if (isset($colunas['condicoes'])) {
                    $campos[] = "condicoes = ?";
                    $params[] = $condicoes;
                    $tipos .= "s";
                }
                
                if (isset($colunas['observacoes'])) {
                    $campos[] = "observacoes = ?";
                    $params[] = $observacoes;
                    $tipos .= "s";
                }
                
                if (isset($colunas['responsavel_tecnico'])) {
                    $campos[] = "responsavel_tecnico = ?";
                    $params[] = $responsavel_tecnico;
                    $tipos .= "s";
                }
                
                if (isset($colunas['cobertura_vazamento_flanges'])) {
                    $campos[] = "cobertura_vazamento_flanges = ?";
                    $params[] = $cobertura_vazamento_flanges;
                    $tipos .= "i";
                }
                
                if (isset($colunas['cobertura_vazamento_conexoes'])) {
                    $campos[] = "cobertura_vazamento_conexoes = ?";
                    $params[] = $cobertura_vazamento_conexoes;
                    $tipos .= "i";
                }
                
                if (isset($colunas['cobertura_ruido_anormal'])) {
                    $campos[] = "cobertura_ruido_anormal = ?";
                    $params[] = $cobertura_ruido_anormal;
                    $tipos .= "i";
                }
                
                if (isset($colunas['cobertura_oxidacao_serpentina'])) {
                    $campos[] = "cobertura_oxidacao_serpentina = ?";
                    $params[] = $cobertura_oxidacao_serpentina;
                    $tipos .= "i";
                }
                
                if (isset($colunas['cobertura_defeito_placa'])) {
                    $campos[] = "cobertura_defeito_placa = ?";
                    $params[] = $cobertura_defeito_placa;
                    $tipos .= "i";
                }
                
                if (isset($colunas['cobertura_queima_compressor'])) {
                    $campos[] = "cobertura_queima_compressor = ?";
                    $params[] = $cobertura_queima_compressor;
                    $tipos .= "i";
                }
                
                if (isset($colunas['cobertura_tubo_rompido'])) {
                    $campos[] = "cobertura_tubo_rompido = ?";
                    $params[] = $cobertura_tubo_rompido;
                    $tipos .= "i";
                }
                
                if (isset($colunas['cobertura_outros'])) {
                    $campos[] = "cobertura_outros = ?";
                    $params[] = $cobertura_outros;
                    $tipos .= "s";
                }
                
                $campos[] = "updated_at = NOW()";
                $params[] = $id_editar;
                $tipos .= "i";
                
                $sql = "UPDATE garantias SET " . implode(", ", $campos) . " WHERE id = ?";
                $stmt = $conexao->prepare($sql);
                
                if ($stmt) {
                    $stmt->bind_param($tipos, ...$params);
                    if ($stmt->execute()) {
                        $mensagem = "Garantia atualizada com sucesso!";
                        $tipo_mensagem = "success";
                    } else {
                        $erro = "Erro ao atualizar: " . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $erro = "Erro na preparação: " . $conexao->error;
                }
            } else {
                // Inserir nova garantia
                $campos_insert = [];
                $placeholders = [];
                $params = [];
                $tipos = "";
                
                $campos_insert[] = "numero";
                $placeholders[] = "?";
                $params[] = $numero;
                $tipos .= "s";
                
                $campos_insert[] = "cliente_id";
                $placeholders[] = "?";
                $params[] = $cliente_id;
                $tipos .= "i";
                
                if (isset($colunas['orcamento_id'])) {
                    $campos_insert[] = "orcamento_id";
                    $placeholders[] = "?";
                    $params[] = $orcamento_id;
                    $tipos .= "i";
                }
                
                if (isset($colunas['agendamento_id'])) {
                    $campos_insert[] = "agendamento_id";
                    $placeholders[] = "?";
                    $params[] = $agendamento_id;
                    $tipos .= "i";
                }
                
                if (isset($colunas['data_servico'])) {
                    $campos_insert[] = "data_servico";
                    $placeholders[] = "?";
                    $params[] = $data_servico;
                    $tipos .= "s";
                }
                
                if (isset($colunas['tempo_garantia_dias'])) {
                    $campos_insert[] = "tempo_garantia_dias";
                    $placeholders[] = "?";
                    $params[] = $tempo_garantia_dias;
                    $tipos .= "i";
                }
                
                $campos_insert[] = "data_emissao";
                $placeholders[] = "?";
                $params[] = $data_emissao;
                $tipos .= "s";
                
                if (isset($colunas['data_inicio'])) {
                    $campos_insert[] = "data_inicio";
                    $placeholders[] = "?";
                    $params[] = $data_inicio;
                    $tipos .= "s";
                }
                
                if (isset($colunas['data_validade'])) {
                    $campos_insert[] = "data_validade";
                    $placeholders[] = "?";
                    $params[] = $data_validade;
                    $tipos .= "s";
                }
                
                if (isset($colunas['tipo'])) {
                    $campos_insert[] = "tipo";
                    $placeholders[] = "?";
                    $params[] = $tipo;
                    $tipos .= "s";
                }
                
                if (isset($colunas['tipo_personalizado'])) {
                    $campos_insert[] = "tipo_personalizado";
                    $placeholders[] = "?";
                    $params[] = $tipo_personalizado;
                    $tipos .= "s";
                }
                
                if (isset($colunas['condicoes'])) {
                    $campos_insert[] = "condicoes";
                    $placeholders[] = "?";
                    $params[] = $condicoes;
                    $tipos .= "s";
                }
                
                if (isset($colunas['observacoes'])) {
                    $campos_insert[] = "observacoes";
                    $placeholders[] = "?";
                    $params[] = $observacoes;
                    $tipos .= "s";
                }
                
                if (isset($colunas['responsavel_tecnico'])) {
                    $campos_insert[] = "responsavel_tecnico";
                    $placeholders[] = "?";
                    $params[] = $responsavel_tecnico;
                    $tipos .= "s";
                }
                
                if (isset($colunas['cobertura_vazamento_flanges'])) {
                    $campos_insert[] = "cobertura_vazamento_flanges";
                    $placeholders[] = "?";
                    $params[] = $cobertura_vazamento_flanges;
                    $tipos .= "i";
                }
                
                if (isset($colunas['cobertura_vazamento_conexoes'])) {
                    $campos_insert[] = "cobertura_vazamento_conexoes";
                    $placeholders[] = "?";
                    $params[] = $cobertura_vazamento_conexoes;
                    $tipos .= "i";
                }
                
                if (isset($colunas['cobertura_ruido_anormal'])) {
                    $campos_insert[] = "cobertura_ruido_anormal";
                    $placeholders[] = "?";
                    $params[] = $cobertura_ruido_anormal;
                    $tipos .= "i";
                }
                
                if (isset($colunas['cobertura_oxidacao_serpentina'])) {
                    $campos_insert[] = "cobertura_oxidacao_serpentina";
                    $placeholders[] = "?";
                    $params[] = $cobertura_oxidacao_serpentina;
                    $tipos .= "i";
                }
                
                if (isset($colunas['cobertura_defeito_placa'])) {
                    $campos_insert[] = "cobertura_defeito_placa";
                    $placeholders[] = "?";
                    $params[] = $cobertura_defeito_placa;
                    $tipos .= "i";
                }
                
                if (isset($colunas['cobertura_queima_compressor'])) {
                    $campos_insert[] = "cobertura_queima_compressor";
                    $placeholders[] = "?";
                    $params[] = $cobertura_queima_compressor;
                    $tipos .= "i";
                }
                
                if (isset($colunas['cobertura_tubo_rompido'])) {
                    $campos_insert[] = "cobertura_tubo_rompido";
                    $placeholders[] = "?";
                    $params[] = $cobertura_tubo_rompido;
                    $tipos .= "i";
                }
                
                if (isset($colunas['cobertura_outros'])) {
                    $campos_insert[] = "cobertura_outros";
                    $placeholders[] = "?";
                    $params[] = $cobertura_outros;
                    $tipos .= "s";
                }
                
                $campos_insert[] = "created_at";
                $placeholders[] = "NOW()";
                
                $sql = "INSERT INTO garantias (" . implode(", ", $campos_insert) . ") VALUES (" . implode(", ", $placeholders) . ")";
                $stmt = $conexao->prepare($sql);
                
                if ($stmt) {
                    if (!empty($params)) {
                        $stmt->bind_param($tipos, ...$params);
                    }
                    if ($stmt->execute()) {
                        $mensagem = "Garantia criada com sucesso! Número: $numero";
                        $tipo_mensagem = "success";
                    } else {
                        $erro = "Erro ao criar: " . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $erro = "Erro na preparação: " . $conexao->error;
                }
            }
        }
        
        if (!empty($erro)) {
            $acao = $id_editar ? 'editar' : 'novo';
        } elseif ($acao_post === 'salvar' && empty($erro)) {
            header('Location: ' . BASE_URL . '/app/admin/garantias.php?mensagem=' . urlencode($mensagem));
            exit;
        }
    } elseif ($acao_post === 'deletar' && $id) {
        $sql = "DELETE FROM garantias WHERE id = ?";
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $mensagem = "Garantia removida com sucesso!";
            $tipo_mensagem = "success";
        } else {
            $erro = "Erro ao remover: " . $stmt->error;
        }
        $stmt->close();
    }
}

// ===== PROCESSAR DELETE VIA GET =====
if ($acao === 'deletar' && $id && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $sql = "DELETE FROM garantias WHERE id = ?";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        header('Location: ' . BASE_URL . '/app/admin/garantias.php?mensagem=Garantia removida com sucesso');
        exit;
    }
    $stmt->close();
}

// ===== BUSCAR DADOS =====
if ($acao === 'listar' || $acao === 'deletar') {
    $garantias = $conexao->query("
        SELECT g.*, c.nome as cliente_nome, c.cpf_cnpj, c.telefone, c.whatsapp
        FROM garantias g
        LEFT JOIN clientes c ON g.cliente_id = c.id
        ORDER BY g.created_at DESC
        LIMIT 200
    ")->fetch_all(MYSQLI_ASSOC);
    
    if (isset($_GET['mensagem'])) {
        $mensagem = $_GET['mensagem'];
        $tipo_mensagem = "success";
    }
} elseif ($acao === 'novo') {
    $garantia = [
        'id' => '',
        'numero' => gerarNumeroGarantia($conexao),
        'cliente_id' => '',
        'orcamento_id' => '',
        'agendamento_id' => '',
        'data_servico' => date('Y-m-d'),
        'tempo_garantia_dias' => 90,
        'data_emissao' => date('Y-m-d'),
        'data_inicio' => date('Y-m-d'),
        'data_validade' => date('Y-m-d', strtotime('+90 days')),
        'tipo' => 'servicos_gerais',
        'tipo_personalizado' => '',
        'condicoes' => '',
        'observacoes' => '',
        'responsavel_tecnico' => '',
        'cobertura_vazamento_flanges' => 0,
        'cobertura_vazamento_conexoes' => 0,
        'cobertura_ruido_anormal' => 0,
        'cobertura_oxidacao_serpentina' => 0,
        'cobertura_defeito_placa' => 0,
        'cobertura_queima_compressor' => 0,
        'cobertura_tubo_rompido' => 0,
        'cobertura_outros' => ''
    ];
} elseif ($acao === 'editar' && $id) {
    $garantia = $conexao->query("SELECT * FROM garantias WHERE id = $id")->fetch_assoc();
    if (!$garantia) {
        header('Location: ' . BASE_URL . '/app/admin/garantias.php?mensagem=Garantia não encontrada');
        exit;
    }
    
    // Buscar orçamentos do cliente da garantia
    if ($garantia['cliente_id'] > 0) {
        $sql_orc = "SELECT o.id, o.numero, o.valor_total, o.data_emissao, o.situacao, c.nome as cliente_nome 
                    FROM orcamentos o 
                    LEFT JOIN clientes c ON o.cliente_id = c.id 
                    WHERE o.cliente_id = {$garantia['cliente_id']}
                    ORDER BY o.id DESC 
                    LIMIT 50";
        $result_orc = $conexao->query($sql_orc);
        if ($result_orc) {
            while ($row = $result_orc->fetch_assoc()) {
                $orcamentos[] = $row;
            }
        }
    }
} elseif ($acao === 'ver' && $id) {
    $garantia = $conexao->query("
        SELECT g.*, c.nome as cliente_nome, c.cpf_cnpj, c.telefone, c.email, c.endereco_rua, c.endereco_numero, c.endereco_bairro, c.endereco_cidade, c.endereco_estado, c.whatsapp,
               o.numero as orcamento_numero
        FROM garantias g
        LEFT JOIN clientes c ON g.cliente_id = c.id
        LEFT JOIN orcamentos o ON g.orcamento_id = o.id
        WHERE g.id = $id
    ")->fetch_assoc();
    if (!$garantia) {
        header('Location: ' . BASE_URL . '/app/admin/garantias.php?mensagem=Garantia não encontrada');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Garantias - Império AR</title>
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
            grid-template-columns: repeat(4, 1fr);
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
        .badge-ativa { background: #d1e7dd; color: #0f5132; }
        .badge-expirada { background: #f8d7da; color: #721c24; }
        .badge-proximo { background: #fff3cd; color: #856404; }

        /* Buttons */
        .btn-view, .btn-edit, .btn-delete, .btn-whatsapp {
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
        .btn-whatsapp { background: #25D366; color: white; }
        .btn-whatsapp:hover { background: #128C7E; color: white; }

        /* Form Card */
        .form-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
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

        .checkbox-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            background: var(--gray-100);
            padding: 15px;
            border-radius: 8px;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .checkbox-item input {
            width: 18px;
            height: 18px;
            margin: 0;
        }

        .checkbox-item label {
            margin: 0;
            font-weight: normal;
            cursor: pointer;
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

        /* View Card */
        .view-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .info-row {
            display: flex;
            padding: 12px 0;
            border-bottom: 1px solid var(--gray-200);
        }

        .info-label {
            width: 200px;
            font-weight: 600;
            color: var(--gray-800);
        }

        .info-value {
            flex: 1;
            color: var(--gray-600);
        }

        .cobertura-list {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .cobertura-item {
            background: var(--gray-100);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
        }

        .cobertura-item.covered {
            background: #d1e7dd;
            color: #0f5132;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-success { background: #d1e7dd; color: #0f5132; border: 1px solid #badbcc; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        /* Loading spinner */
        .loading-spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid var(--gray-300);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* DataTables */
        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter {
            margin-bottom: 15px;
        }
        .dataTables_wrapper .dataTables_paginate {
            margin-top: 20px;
            text-align: center;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            padding: 6px 12px;
            margin: 0 3px;
            border-radius: 6px;
            border: 1px solid var(--gray-300);
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: var(--primary);
            color: white;
        }

        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 15px; }
            .form-row, .form-row-3, .checkbox-grid { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .info-row { flex-direction: column; }
            .info-label { width: 100%; margin-bottom: 5px; }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1>
                    <i class="fas fa-shield-alt"></i>
                    Garantias
                </h1>
                <div style="display: flex; gap: 15px;">
                    <a href="garantias.php?acao=novo" class="btn-novo">
                        <i class="fas fa-plus"></i> Nova Garantia
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
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-shield-alt"></i></div>
                        <div class="stat-number"><?php echo count($garantias); ?></div>
                        <div class="stat-label">Total Garantias</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                        <div class="stat-number">
                            <?php 
                            $ativas = 0;
                            foreach ($garantias as $g) {
                                if ($g['data_validade'] >= date('Y-m-d')) $ativas++;
                            }
                            echo $ativas;
                            ?>
                        </div>
                        <div class="stat-label">Garantias Ativas</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-clock"></i></div>
                        <div class="stat-number">
                            <?php 
                            $proximas = 0;
                            foreach ($garantias as $g) {
                                $dias = (strtotime($g['data_validade']) - strtotime(date('Y-m-d'))) / 86400;
                                if ($dias > 0 && $dias <= 30) $proximas++;
                            }
                            echo $proximas;
                            ?>
                        </div>
                        <div class="stat-label">Vencem em 30 dias</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                        <div class="stat-number">
                            <?php 
                            $expiradas = 0;
                            foreach ($garantias as $g) {
                                if ($g['data_validade'] < date('Y-m-d')) $expiradas++;
                            }
                            echo $expiradas;
                            ?>
                        </div>
                        <div class="stat-label">Garantias Expiradas</div>
                    </div>
                </div>

                <div class="table-container">
                    <div class="table-responsive">
                        <table class="table table-hover" id="table-garantias">
                            <thead>
                                <tr>
                                    <th>Número</th>
                                    <th>Cliente</th>
                                    <th>Data Serviço</th>
                                    <th>Validade</th>
                                    <th>Dias</th>
                                    <th>Tipo</th>
                                    <th>Status</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($garantias as $item): 
                                    $hoje = date('Y-m-d');
                                    $validade = $item['data_validade'];
                                    $dias_restantes = $validade ? (strtotime($validade) - strtotime($hoje)) / 86400 : 0;
                                    
                                    if ($dias_restantes < 0) {
                                        $status_class = 'badge-expirada';
                                        $status_text = 'Expirada';
                                    } elseif ($dias_restantes <= 30) {
                                        $status_class = 'badge-proximo';
                                        $status_text = floor($dias_restantes) . ' dias restantes';
                                    } else {
                                        $status_class = 'badge-ativa';
                                        $status_text = 'Ativa';
                                    }
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($item['numero']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($item['cliente_nome'] ?? '-'); ?></td>
                                    <td><?php echo formatarData($item['data_servico']); ?></td>
                                    <td><?php echo formatarData($item['data_validade']); ?></td>
                                    <td><?php echo $item['tempo_garantia_dias'] ?? '-'; ?> dias</span></td>
                                    <td><?php echo $tipos_garantia[$item['tipo']] ?? $item['tipo']; ?></span></td>
                                    <td><span class="badge-status <?php echo $status_class; ?>"><?php echo $status_text; ?></span></td>
                                    <td>
                                        <a href="garantias.php?acao=ver&id=<?php echo $item['id']; ?>" class="btn-view" title="Visualizar">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="garantias.php?acao=editar&id=<?php echo $item['id']; ?>" class="btn-edit" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php if (!empty($item['whatsapp']) || !empty($item['telefone'])): ?>
                                        <a href="garantias.php?enviar_whatsapp=1&id=<?php echo $item['id']; ?>" class="btn-whatsapp" title="Enviar via WhatsApp" target="_blank">
                                            <i class="fab fa-whatsapp"></i> Enviar
                                        </a>
                                        <?php endif; ?>
                                        <a href="garantias.php?acao=deletar&id=<?php echo $item['id']; ?>" class="btn-delete" title="Excluir" onclick="return confirm('Tem certeza que deseja excluir esta garantia?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </span>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php elseif ($acao === 'ver' && isset($garantia)): ?>
                <div class="view-card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h2 style="color: var(--primary); margin: 0;">
                            <i class="fas fa-shield-alt"></i> Certificado de Garantia
                        </h2>
                        <div>
                            <?php if (!empty($garantia['whatsapp']) || !empty($garantia['telefone'])): ?>
                            <a href="garantias.php?enviar_whatsapp=1&id=<?php echo $garantia['id']; ?>" class="btn-whatsapp" style="margin-right: 10px;" target="_blank">
                                <i class="fab fa-whatsapp"></i> Enviar via WhatsApp
                            </a>
                            <?php endif; ?>
                            <a href="garantias.php" class="btn-view">
                                <i class="fas fa-arrow-left"></i> Voltar
                            </a>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Número da Garantia:</div>
                        <div class="info-value"><strong><?php echo htmlspecialchars($garantia['numero']); ?></strong></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Cliente:</div>
                        <div class="info-value"><?php echo htmlspecialchars($garantia['cliente_nome']); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">CPF/CNPJ:</div>
                        <div class="info-value"><?php echo htmlspecialchars($garantia['cpf_cnpj'] ?? '-'); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Telefone:</div>
                        <div class="info-value"><?php echo htmlspecialchars($garantia['telefone'] ?? '-'); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Orçamento:</div>
                        <div class="info-value"><?php echo htmlspecialchars($garantia['orcamento_numero'] ?? '-'); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Data do Serviço:</div>
                        <div class="info-value"><?php echo formatarData($garantia['data_servico']); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Data de Emissão:</div>
                        <div class="info-value"><?php echo formatarData($garantia['data_emissao']); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Data de Início:</div>
                        <div class="info-value"><?php echo formatarData($garantia['data_inicio']); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Data de Validade:</div>
                        <div class="info-value"><?php echo formatarData($garantia['data_validade']); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Tempo de Garantia:</div>
                        <div class="info-value"><?php echo $garantia['tempo_garantia_dias']; ?> dias</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Tipo de Garantia:</div>
                        <div class="info-value">
                            <?php 
                            if ($garantia['tipo'] === 'personalizado' && $garantia['tipo_personalizado']) {
                                echo htmlspecialchars($garantia['tipo_personalizado']);
                            } else {
                                echo $tipos_garantia[$garantia['tipo']] ?? $garantia['tipo'];
                            }
                            ?>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Coberturas:</div>
                        <div class="info-value">
                            <div class="cobertura-list">
                                <?php if ($garantia['cobertura_vazamento_flanges']): ?>
                                <span class="cobertura-item covered"><i class="fas fa-check-circle"></i> Vazamento em flanges</span>
                                <?php endif; ?>
                                <?php if ($garantia['cobertura_vazamento_conexoes']): ?>
                                <span class="cobertura-item covered"><i class="fas fa-check-circle"></i> Vazamento em conexões</span>
                                <?php endif; ?>
                                <?php if ($garantia['cobertura_ruido_anormal']): ?>
                                <span class="cobertura-item covered"><i class="fas fa-check-circle"></i> Ruído anormal</span>
                                <?php endif; ?>
                                <?php if ($garantia['cobertura_oxidacao_serpentina']): ?>
                                <span class="cobertura-item covered"><i class="fas fa-check-circle"></i> Oxidação da serpentina</span>
                                <?php endif; ?>
                                <?php if ($garantia['cobertura_defeito_placa']): ?>
                                <span class="cobertura-item covered"><i class="fas fa-check-circle"></i> Defeito na placa</span>
                                <?php endif; ?>
                                <?php if ($garantia['cobertura_queima_compressor']): ?>
                                <span class="cobertura-item covered"><i class="fas fa-check-circle"></i> Queima do compressor</span>
                                <?php endif; ?>
                                <?php if ($garantia['cobertura_tubo_rompido']): ?>
                                <span class="cobertura-item covered"><i class="fas fa-check-circle"></i> Tubo rompido</span>
                                <?php endif; ?>
                                <?php if ($garantia['cobertura_outros']): ?>
                                <span class="cobertura-item covered"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($garantia['cobertura_outros']); ?></span>
                                <?php endif; ?>
                                <?php if (!$garantia['cobertura_vazamento_flanges'] && !$garantia['cobertura_vazamento_conexoes'] && !$garantia['cobertura_ruido_anormal'] && !$garantia['cobertura_oxidacao_serpentina'] && !$garantia['cobertura_defeito_placa'] && !$garantia['cobertura_queima_compressor'] && !$garantia['cobertura_tubo_rompido'] && !$garantia['cobertura_outros']): ?>
                                <span class="cobertura-item">Nenhuma cobertura específica</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Condições da Garantia:</div>
                        <div class="info-value">
                            <div style="background: var(--gray-100); padding: 15px; border-radius: 8px;">
                                <?php echo nl2br(htmlspecialchars($garantia['condicoes'])); ?>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($garantia['observacoes']): ?>
                    <div class="info-row">
                        <div class="info-label">Observações:</div>
                        <div class="info-value"><?php echo nl2br(htmlspecialchars($garantia['observacoes'])); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($garantia['responsavel_tecnico']): ?>
                    <div class="info-row">
                        <div class="info-label">Responsável Técnico:</div>
                        <div class="info-value"><?php echo htmlspecialchars($garantia['responsavel_tecnico']); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <div style="margin-top: 30px; padding: 20px; background: var(--gray-100); border-radius: 8px; text-align: center;">
                        <i class="fas fa-certificate" style="font-size: 24px; color: var(--primary);"></i>
                        <p style="margin-top: 10px; font-size: 12px; color: var(--gray-600);">
                            Documento emitido eletronicamente pela Império AR - Refrigeração<br>
                            CNPJ: 66.282.593/0001-34
                        </p>
                    </div>
                </div>

            <?php elseif ($acao === 'novo' || $acao === 'editar'): ?>
                <div class="form-card">
                    <h2 style="color: var(--primary); margin-bottom: 25px;">
                        <i class="fas fa-<?php echo $acao === 'novo' ? 'plus' : 'edit'; ?>"></i>
                        <?php echo $acao === 'novo' ? 'Nova Garantia' : 'Editar Garantia'; ?>
                    </h2>
                    
                    <form method="POST" id="formGarantia">
                        <input type="hidden" name="acao" value="salvar">
                        <?php if ($acao === 'editar'): ?>
                        <input type="hidden" name="id" value="<?php echo $garantia['id']; ?>">
                        <?php endif; ?>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Cliente *</label>
                                <select name="cliente_id" id="cliente_id" required>
                                    <option value="">Selecione um cliente</option>
                                    <?php foreach ($clientes as $cli): ?>
                                    <option value="<?php echo $cli['id']; ?>" <?php echo ($garantia['cliente_id'] ?? '') == $cli['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cli['nome']); ?> <?php echo $cli['cpf_cnpj'] ? ' - ' . htmlspecialchars($cli['cpf_cnpj']) : ''; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Orçamento</label>
                                <select name="orcamento_id" id="orcamento_id">
                                    <option value="">-- Selecione um orçamento --</option>
                                    <?php if ($acao === 'editar' && isset($orcamentos)): ?>
                                        <?php foreach ($orcamentos as $orc): ?>
                                        <option value="<?php echo $orc['id']; ?>" <?php echo ($garantia['orcamento_id'] ?? '') == $orc['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($orc['numero']); ?> - <?php echo htmlspecialchars($orc['cliente_nome'] ?? 'Cliente não informado'); ?> 
                                            (R$ <?php echo number_format($orc['valor_total'] ?? 0, 2, ',', '.'); ?>) - <?php echo $orc['situacao']; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                                <div id="loading-orcamentos" style="display: none; font-size: 12px; color: var(--info); margin-top: 5px;">
                                    <span class="loading-spinner"></span> Carregando orçamentos...
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-row-3">
                            <div class="form-group">
                                <label>Data do Serviço *</label>
                                <input type="date" name="data_servico" value="<?php echo $garantia['data_servico'] ?? date('Y-m-d'); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Data de Início da Garantia</label>
                                <input type="date" name="data_inicio" id="data_inicio" value="<?php echo $garantia['data_inicio'] ?? date('Y-m-d'); ?>">
                            </div>
                            <div class="form-group">
                                <label>Tempo de Garantia (dias) *</label>
                                <input type="number" name="tempo_garantia_dias" id="tempo_garantia_dias" value="<?php echo $garantia['tempo_garantia_dias'] ?? 90; ?>" min="1" max="3650" required>
                                <small id="validade_preview" style="color: var(--success); font-size: 12px;"></small>
                            </div>
                        </div>
                        
                        <div class="form-row-3">
                            <div class="form-group">
                                <label>Tipo de Garantia</label>
                                <select name="tipo" id="tipo_garantia">
                                    <?php foreach ($tipos_garantia as $key => $label): ?>
                                    <option value="<?php echo $key; ?>" <?php echo ($garantia['tipo'] ?? 'servicos_gerais') == $key ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group" id="campo_tipo_personalizado" style="display: none;">
                                <label>Especificar Tipo Personalizado</label>
                                <input type="text" name="tipo_personalizado" placeholder="Ex: Garantia Estendida de Compressor" value="<?php echo htmlspecialchars($garantia['tipo_personalizado'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Responsável Técnico</label>
                                <input type="text" name="responsavel_tecnico" placeholder="Nome do técnico responsável" value="<?php echo htmlspecialchars($garantia['responsavel_tecnico'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Coberturas da Garantia</label>
                            <div class="checkbox-grid">
                                <?php foreach ($coberturas as $key => $label): ?>
                                <div class="checkbox-item">
                                    <input type="checkbox" name="cobertura_<?php echo $key; ?>" id="cobertura_<?php echo $key; ?>" 
                                           value="1" <?php echo ($garantia['cobertura_' . $key] ?? 0) ? 'checked' : ''; ?>>
                                    <label for="cobertura_<?php echo $key; ?>"><?php echo $label; ?></label>
                                </div>
                                <?php endforeach; ?>
                                <div class="checkbox-item" style="grid-column: span 2;">
                                    <input type="checkbox" name="cobertura_outros_check" id="cobertura_outros_check" 
                                           value="1" <?php echo !empty($garantia['cobertura_outros']) ? 'checked' : ''; ?>>
                                    <label for="cobertura_outros_check">Outros:</label>
                                    <input type="text" name="cobertura_outros" style="width: 70%; margin-left: 10px;" 
                                           placeholder="Especifique outras coberturas" value="<?php echo htmlspecialchars($garantia['cobertura_outros'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Condições da Garantia *</label>
                            <textarea name="condicoes" rows="5" placeholder="Descreva as condições da garantia..." required><?php echo htmlspecialchars($garantia['condicoes'] ?? ''); ?></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label>Observações Adicionais</label>
                            <textarea name="observacoes" rows="3" placeholder="Observações extras..."><?php echo htmlspecialchars($garantia['observacoes'] ?? ''); ?></textarea>
                        </div>
                        
                        <div style="display: flex; gap: 15px; margin-top: 20px;">
                            <button type="submit" class="btn-salvar">
                                <i class="fas fa-save"></i> Salvar Garantia
                            </button>
                            <a href="garantias.php" class="btn-cancelar">
                                <i class="fas fa-times"></i> Cancelar
                            </a>
                        </div>
                    </form>
                </div>
                
                <script>
                    // Função para carregar orçamentos por cliente
                    function carregarOrcamentos(clienteId, orcamentoSelecionadoId = null) {
                        if (!clienteId || clienteId == '') {
                            var selectOrcamento = document.getElementById('orcamento_id');
                            selectOrcamento.innerHTML = '<option value="">-- Selecione um orçamento --</option>';
                            return;
                        }
                        
                        var loadingDiv = document.getElementById('loading-orcamentos');
                        var selectOrcamento = document.getElementById('orcamento_id');
                        
                        loadingDiv.style.display = 'block';
                        selectOrcamento.disabled = true;
                        
                        fetch('garantias.php?ajax=orcamentos&cliente_id=' + clienteId)
                            .then(response => response.json())
                            .then(data => {
                                selectOrcamento.innerHTML = '<option value="">-- Selecione um orçamento --</option>';
                                
                                if (data.length === 0) {
                                    selectOrcamento.innerHTML += '<option value="" disabled>Nenhum orçamento encontrado para este cliente</option>';
                                } else {
                                    data.forEach(orc => {
                                        var option = document.createElement('option');
                                        option.value = orc.id;
                                        var valor = parseFloat(orc.valor_total || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, style: 'currency', currency: 'BRL' });
                                        option.textContent = orc.numero + ' - ' + (orc.cliente_nome || 'Cliente') + ' (' + valor + ') - ' + orc.situacao;
                                        if (orcamentoSelecionadoId && orc.id == orcamentoSelecionadoId) {
                                            option.selected = true;
                                        }
                                        selectOrcamento.appendChild(option);
                                    });
                                }
                                
                                loadingDiv.style.display = 'none';
                                selectOrcamento.disabled = false;
                            })
                            .catch(error => {
                                console.error('Erro ao carregar orçamentos:', error);
                                loadingDiv.style.display = 'none';
                                selectOrcamento.disabled = false;
                                selectOrcamento.innerHTML = '<option value="">Erro ao carregar orçamentos</option>';
                            });
                    }
                    
                    // Evento de mudança do cliente
                    var clienteSelect = document.getElementById('cliente_id');
                    var orcamentoSelecionado = <?php echo json_encode($garantia['orcamento_id'] ?? null); ?>;
                    
                    if (clienteSelect) {
                        clienteSelect.addEventListener('change', function() {
                            carregarOrcamentos(this.value, null);
                        });
                        
                        if (clienteSelect.value && clienteSelect.value != '') {
                            carregarOrcamentos(clienteSelect.value, orcamentoSelecionado);
                        }
                    }
                    
                    // Mostrar/esconder campo de tipo personalizado
                    var tipoSelect = document.getElementById('tipo_garantia');
                    var campoPersonalizado = document.getElementById('campo_tipo_personalizado');
                    
                    if (tipoSelect) {
                        tipoSelect.addEventListener('change', function() {
                            campoPersonalizado.style.display = this.value === 'personalizado' ? 'block' : 'none';
                        });
                        if (tipoSelect.value === 'personalizado') {
                            campoPersonalizado.style.display = 'block';
                        }
                    }
                    
                    // Mostrar/esconder campo de outros
                    var outrosCheck = document.getElementById('cobertura_outros_check');
                    var inputOutros = document.querySelector('input[name="cobertura_outros"]');
                    
                    if (outrosCheck) {
                        outrosCheck.addEventListener('change', function() {
                            if (inputOutros) {
                                inputOutros.style.display = this.checked ? 'inline-block' : 'none';
                                if (!this.checked) inputOutros.value = '';
                            }
                        });
                        if (outrosCheck.checked && inputOutros) {
                            inputOutros.style.display = 'inline-block';
                        }
                    }
                    
                    // Calcular data de validade
                    function calcularValidade() {
                        var dataInicio = document.getElementById('data_inicio').value;
                        var dias = parseInt(document.getElementById('tempo_garantia_dias').value);
                        var previewSpan = document.getElementById('validade_preview');
                        
                        if (dataInicio && dias && !isNaN(dias)) {
                            var data = new Date(dataInicio);
                            data.setDate(data.getDate() + dias);
                            var dia = String(data.getDate()).padStart(2, '0');
                            var mes = String(data.getMonth() + 1).padStart(2, '0');
                            var ano = data.getFullYear();
                            previewSpan.innerHTML = ' → Vence em: ' + dia + '/' + mes + '/' + ano;
                        } else {
                            previewSpan.innerHTML = '';
                        }
                    }
                    
                    var dataInicioInput = document.getElementById('data_inicio');
                    var tempoDiasInput = document.getElementById('tempo_garantia_dias');
                    
                    if (dataInicioInput) dataInicioInput.addEventListener('change', calcularValidade);
                    if (tempoDiasInput) tempoDiasInput.addEventListener('change', calcularValidade);
                    calcularValidade();
                </script>
            <?php endif; ?>
        </main>
    </div>

    <script>
        $(document).ready(function() {
            if ($('#table-garantias').length) {
                $('#table-garantias').DataTable({
                    language: {
                        url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-BR.json'
                    },
                    pageLength: 25,
                    order: [[2, 'desc']],
                    columnDefs: [{ orderable: false, targets: [7] }]
                });
            }
        });
    </script>
</body>
</html>