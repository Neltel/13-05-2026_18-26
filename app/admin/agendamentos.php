<?php
/**
 * =====================================================================
 * AGENDAMENTOS - SISTEMA DE GESTÃO IMPÉRIO AR (VERSÃO UNIFICADA)
 * =====================================================================
 * 
 * Responsabilidade: Gerenciar agendamentos de serviços
 * Inclui: Listagem, calendário, criação, edição, notificações WhatsApp
 * 
 * NOVIDADES:
 * - Evento na conversa do WhatsApp (salvo para ambos)
 * - Envio para grupo com link do Google Calendar
 * - Lembretes automáticos
 * - Endereço do cliente nas mensagens
 * - Envio para grupo com todas as informações + calendário
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/environment.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/WhatsApp.php';

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
$visualizacao = $_GET['view'] ?? 'lista';
$mensagem = '';
$erro = '';
$agendamentos = [];
$clientes = [];
$servicos = [];
$agendamento = [];
$pagina_atual = isset($_GET['pagina']) ? intval($_GET['pagina']) : 1;
$por_pagina = 20;
$total_paginas = 0;

// Parâmetros de filtro
$filtro_status = $_GET['status'] ?? '';
$filtro_cliente = $_GET['cliente'] ?? '';
$filtro_servico = $_GET['servico'] ?? '';
$filtro_data_inicio = $_GET['data_inicio'] ?? date('Y-m-d');
$filtro_data_fim = $_GET['data_fim'] ?? date('Y-m-d', strtotime('+30 days'));

// ===== INICIALIZAR CLASSES =====
$whatsapp = new WhatsApp($conexao);

// ===== FUNÇÕES AUXILIARES =====
function formatarData($data) {
    if (empty($data)) return '';
    return date('d/m/Y', strtotime($data));
}

function formatarHora($hora) {
    if (empty($hora)) return '';
    return date('H:i', strtotime($hora));
}

function verificarCSRF($token) {
    return isset($_SESSION['csrf_token']) && $token === $_SESSION['csrf_token'];
}

function getStatusBadge($status) {
    $classes = [
        'agendado' => 'badge-info',
        'confirmado' => 'badge-primary',
        'em_execucao' => 'badge-warning',
        'finalizado' => 'badge-success',
        'cancelado' => 'badge-danger'
    ];
    
    $labels = [
        'agendado' => 'Agendado',
        'confirmado' => 'Confirmado',
        'em_execucao' => 'Em Execução',
        'finalizado' => 'Finalizado',
        'cancelado' => 'Cancelado'
    ];
    
    $classe = $classes[$status] ?? 'badge-secondary';
    $label = $labels[$status] ?? ucfirst($status);
    
    return '<span class="badge ' . $classe . '">' . $label . '</span>';
}

// ===== FUNÇÃO PARA FORMATAR DATA/HORA =====
if (!function_exists('formatarDataHora')) {
    function formatarDataHora($data, $hora) {
        if (empty($data) || empty($hora)) return '';
        $dias = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
        $dia_semana = $dias[date('w', strtotime($data))];
        $data_formatada = date('d/m/Y', strtotime($data));
        return "{$dia_semana}, {$data_formatada} as {$hora}";
    }
}

// ===== FUNÇÃO PARA GERAR LINK DO WHATSAPP =====
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

// ===== FUNÇÃO PARA CRIAR LINK DO GOOGLE CALENDAR =====
if (!function_exists('gerarLinkGoogleCalendar')) {
    function gerarLinkGoogleCalendar($dados) {
        $titulo_evento = "IMPERIO AR - {$dados['servico_nome']} - {$dados['cliente_nome']}";
        
        // Formatar datas para o Google Calendar (YYYYMMDDTHHMMSS)
        $data_inicio = date('Ymd', strtotime($dados['data_agendamento'])) . 'T' . str_replace(':', '', $dados['horario_inicio']) . '00';
        $data_fim = date('Ymd', strtotime($dados['data_agendamento'])) . 'T' . str_replace(':', '', $dados['horario_fim']) . '00';
        
        // Montar endereço completo
        $endereco_completo = "Av. Presidente Kennedy, 1234 - Centro";
        if (!empty($dados['endereco_rua'])) {
            $endereco_completo = $dados['endereco_rua'];
            if (!empty($dados['endereco_numero'])) $endereco_completo .= ", {$dados['endereco_numero']}";
            if (!empty($dados['endereco_bairro'])) $endereco_completo .= " - {$dados['endereco_bairro']}";
            if (!empty($dados['endereco_cidade'])) $endereco_completo .= ", {$dados['endereco_cidade']}";
        }
        
        // Descrição do evento
        $descricao_evento = "Cliente: {$dados['cliente_nome']}\n";
        $descricao_evento .= "Servico: {$dados['servico_nome']}\n";
        if (!empty($dados['observacao'])) $descricao_evento .= "Obs: {$dados['observacao']}\n";
        $descricao_evento .= "Telefone: (17) 99624-0725";
        
        $link_calendar = "https://www.google.com/calendar/render?action=TEMPLATE";
        $link_calendar .= "&text=" . urlencode($titulo_evento);
        $link_calendar .= "&dates=" . $data_inicio . "/" . $data_fim;
        $link_calendar .= "&details=" . urlencode($descricao_evento);
        $link_calendar .= "&location=" . urlencode($endereco_completo);
        
        return $link_calendar;
    }
}

// ===== CARREGAR DADOS BÁSICOS =====
function carregarClientes($conexao) {
    $clientes = [];
    $sql = "SELECT id, nome, telefone, whatsapp, email,
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

function carregarServicos($conexao) {
    $servicos = [];
    $sql = "SELECT id, nome, valor_unitario, tempo_execucao 
            FROM servicos WHERE ativo = 1 ORDER BY nome ASC";
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
$servicos = carregarServicos($conexao);

// ===== PROCESSAR AÇÕES =====
// ENVIAR WHATSAPP (COM OPÇÃO DE GRUPO E CALENDÁRIO)
if ($acao === 'enviar_whatsapp' && $id) {
    $tipo = $_GET['tipo'] ?? 'cliente';
    $mensagem_personalizada = $_GET['msg'] ?? '';
    
    // Buscar dados do agendamento e cliente
    $sql = "SELECT a.*, 
                   c.nome as cliente_nome, 
                   c.telefone, 
                   c.whatsapp,
                   c.endereco_rua,
                   c.endereco_numero,
                   c.endereco_bairro,
                   c.endereco_cidade,
                   s.nome as servico_nome,
                   s.tempo_execucao
            FROM agendamentos a
            LEFT JOIN clientes c ON a.cliente_id = c.id
            LEFT JOIN servicos s ON a.servico_id = s.id
            WHERE a.id = ?";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $dados = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$dados) {
        header('Location: ' . BASE_URL . '/app/admin/agendamentos.php?view=' . $visualizacao . '&erro=nao_encontrado');
        exit;
    }
    
    // Definir telefone de destino
    $telefone_destino = '';
    $nome_destino = '';
    
    if ($tipo === 'cliente') {
        $telefone_destino = $dados['whatsapp'] ?? $dados['telefone'] ?? '';
        $nome_destino = $dados['cliente_nome'] ?? 'Cliente';
    } elseif ($tipo === 'eu') {
        $telefone_destino = '5517996240725'; // SEU NÚMERO AQUI
        $nome_destino = 'Voce';
    } elseif ($tipo === 'grupo') {
        // NÚMERO DO GRUPO (você precisa descobrir e colocar aqui)
        $telefone_destino = 'x1iyjqo2 x6ikm8r x10wlt62 x1n2onr6 xlyipyv xuxw1ft x1rg5ohu x1jchvi3 xjb2p0i xo1l8bm x17mssa0 x1ic7a3i _ao3e'; // SUBSTITUA PELO NÚMERO DO GRUPO
        $nome_destino = 'Agenda';
    }
    
    // Formatar dados
    $data_formatada = formatarData($dados['data_agendamento']);
    $hora_inicio = formatarHora($dados['horario_inicio']);
    $hora_fim = formatarHora($dados['horario_fim']);
    
    // Calcular tempo de execução
    $tempo_execucao = '';
    if (!empty($dados['tempo_execucao'])) {
        $minutos = $dados['tempo_execucao'];
        $horas = floor($minutos / 60);
        $min_rest = $minutos % 60;
        $tempo_execucao = $horas > 0 ? "{$horas}h {$min_rest}min" : "{$min_rest}min";
    }
    
    // Gerar link do Google Calendar
    $link_calendar = gerarLinkGoogleCalendar($dados);
    
    if ($tipo === 'grupo') {
        // Verificar se cliente tem telefone para o grupo
        if (empty($telefone_destino)) {
            header('Location: ' . BASE_URL . '/app/admin/agendamentos.php?view=' . $visualizacao . '&erro=sem_telefone_grupo');
            exit;
        }
        
        // Mensagem para o grupo com todas as informações
        $mensagem = "📋 NOVO AGENDAMENTO - IMPERIO AR\n";
        $mensagem .= "━━━━━━━━━━━━━━━━━━━━━\n\n";
        $mensagem .= "CLIENTE: {$dados['cliente_nome']}\n";
        $mensagem .= "DATA: {$data_formatada}\n";
        $mensagem .= "HORARIO: {$hora_inicio} as {$hora_fim}\n";
        $mensagem .= "SERVICO: {$dados['servico_nome']}\n";
        if (!empty($tempo_execucao)) $mensagem .= "DURACAO: {$tempo_execucao}\n";
        $mensagem .= "TELEFONE CLIENTE: {$dados['telefone']}\n\n";
        
        if (!empty($dados['endereco_rua'])) {
            $endereco_completo = $dados['endereco_rua'];
            if (!empty($dados['endereco_numero'])) $endereco_completo .= ", {$dados['endereco_numero']}";
            if (!empty($dados['endereco_bairro'])) $endereco_completo .= " - {$dados['endereco_bairro']}";
            if (!empty($dados['endereco_cidade'])) $endereco_completo .= ", {$dados['endereco_cidade']}";
            
            $mensagem .= "ENDERECO DO SERVICO:\n";
            $mensagem .= "{$endereco_completo}\n\n";
        }
        
        if (!empty($dados['observacao'])) {
            $mensagem .= "OBSERVACOES:\n";
            $mensagem .= "{$dados['observacao']}\n\n";
        }
        
        $mensagem .= "━━━━━━━━━━━━━━━━━━━━━\n";
        $mensagem .= "📅 ADICIONAR AO GOOGLE CALENDAR:\n";
        $mensagem .= "Clique no link abaixo para adicionar este evento ao seu Google Calendar:\n";
        $mensagem .= $link_calendar . "\n\n";
        
        $mensagem .= "IMPERIO AR - Especialistas em Conforto Termico\n";
        $mensagem .= "(17) 99624-0725";
        
        // Enviar diretamente para o número do grupo
        $link = gerarLinkWhatsApp($telefone_destino, $mensagem);
        
        echo "<script>
            window.open('{$link}', '_blank');
            setTimeout(function() { 
                window.location.href = '?view={$visualizacao}&mensagem=grupo_notificado'; 
            }, 1000);
        </script>";
        exit;
        
    } elseif ($tipo === 'cliente') {
        // Verificar se cliente tem telefone
        if (empty($telefone_destino)) {
            header('Location: ' . BASE_URL . '/app/admin/agendamentos.php?view=' . $visualizacao . '&erro=sem_telefone');
            exit;
        }
        
        // Mensagem para o cliente
$mensagem = "IMPERIO AR - CONFIRMACAO DE AGENDAMENTO\n\n";
$mensagem .= "Ola {$dados['cliente_nome']}, seu agendamento foi confirmado!\n\n";
$mensagem .= "ualquer duvida pode nos procurar, estamos a disposição.\n\n";
$mensagem .= "DATA: {$data_formatada}\n";
$mensagem .= "HORARIO: {$hora_inicio} as {$hora_fim}\n";
$mensagem .= "SERVICO: {$dados['servico_nome']}\n\n";

// Montar endereço completo do cliente
$endereco_completo = "Endereco nao cadastrado";
if (!empty($dados['endereco_rua'])) {
    $endereco_completo = $dados['endereco_rua'];
    if (!empty($dados['endereco_numero'])) $endereco_completo .= ", {$dados['endereco_numero']}";
    if (!empty($dados['endereco_bairro'])) $endereco_completo .= " - {$dados['endereco_bairro']}";
    if (!empty($dados['endereco_cidade'])) $endereco_completo .= ", {$dados['endereco_cidade']}";
    if (!empty($dados['endereco_estado'])) $endereco_completo .= "/{$dados['endereco_estado']}";
    if (!empty($dados['endereco_cep'])) $endereco_completo .= " - CEP: {$dados['endereco_cep']}";
}
$mensagem .= "LOCAL: {$endereco_completo}\n\n";

if (!empty($mensagem_personalizada)) {
    $mensagem .= "MENSAGEM: {$mensagem_personalizada}\n\n";
}
$mensagem .= "IMPERIO AR - (17) 99624-0725";

$link = gerarLinkWhatsApp($telefone_destino, $mensagem);
echo "<script>window.open('{$link}', '_blank');</script>";
        
    } elseif ($tipo === 'eu') {
        // Mensagem para você mesmo com todas as informações
        $mensagem = "📋 LEMBRETE DE AGENDAMENTO - IMPERIO AR\n";
        $mensagem .= "━━━━━━━━━━━━━━━━━━━━━\n\n";
        $mensagem .= "CLIENTE: {$dados['cliente_nome']}\n";
        $mensagem .= "DATA: {$data_formatada}\n";
        $mensagem .= "HORARIO: {$hora_inicio} as {$hora_fim}\n";
        $mensagem .= "SERVICO: {$dados['servico_nome']}\n";
        if (!empty($tempo_execucao)) $mensagem .= "DURACAO: {$tempo_execucao}\n";
        $mensagem .= "TELEFONE CLIENTE: {$dados['telefone']}\n\n";
        
        if (!empty($dados['endereco_rua'])) {
            $endereco_completo = $dados['endereco_rua'];
            if (!empty($dados['endereco_numero'])) $endereco_completo .= ", {$dados['endereco_numero']}";
            if (!empty($dados['endereco_bairro'])) $endereco_completo .= " - {$dados['endereco_bairro']}";
            if (!empty($dados['endereco_cidade'])) $endereco_completo .= ", {$dados['endereco_cidade']}";
            
            $mensagem .= "ENDERECO DO SERVICO:\n";
            $mensagem .= "{$endereco_completo}\n\n";
        }
        
        if (!empty($dados['observacao'])) {
            $mensagem .= "OBSERVACOES:\n";
            $mensagem .= "{$dados['observacao']}\n\n";
        }
        
        $mensagem .= "━━━━━━━━━━━━━━━━━━━━━\n";
        $mensagem .= "📅 ADICIONAR AO GOOGLE CALENDAR:\n";
        $mensagem .= $link_calendar . "\n\n";
        
        $mensagem .= "IMPERIO AR - Especialistas em Conforto Termico\n";
        $mensagem .= "(17) 99624-0725";
        
        $link = gerarLinkWhatsApp($telefone_destino, $mensagem);
        echo "<script>window.open('{$link}', '_blank');</script>";
    }
    
    echo "<script>
        setTimeout(function() { 
            window.location.href = '?view={$visualizacao}&mensagem=whatsapp_enviado'; 
        }, 1000);
    </script>";
    exit;
}

// ENVIAR EVENTO WHATSAPP (cria evento na conversa)
if ($acao === 'enviar_evento' && $id) {
    // Buscar dados do agendamento e cliente
    $sql = "SELECT a.*, 
                   c.nome as cliente_nome, 
                   c.telefone, 
                   c.whatsapp,
                   c.endereco_rua,
                   c.endereco_numero,
                   c.endereco_bairro,
                   c.endereco_cidade,
                   s.nome as servico_nome
            FROM agendamentos a
            LEFT JOIN clientes c ON a.cliente_id = c.id
            LEFT JOIN servicos s ON a.servico_id = s.id
            WHERE a.id = ?";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $dados = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$dados) {
        header('Location: ' . BASE_URL . '/app/admin/agendamentos.php?view=' . $visualizacao . '&erro=nao_encontrado');
        exit;
    }
    
    $telefone_cliente = $dados['whatsapp'] ?? $dados['telefone'] ?? '';
    
    if (empty($telefone_cliente)) {
        header('Location: ' . BASE_URL . '/app/admin/agendamentos.php?view=' . $visualizacao . '&erro=sem_telefone');
        exit;
    }
    
    // Formatar dados
    $data_formatada = formatarData($dados['data_agendamento']);
    $hora_inicio = formatarHora($dados['horario_inicio']);
    $hora_fim = formatarHora($dados['horario_fim']);
    
    // Montar endereço completo
    $endereco_completo = "Endereco nao cadastrado";
    if (!empty($dados['endereco_rua'])) {
        $endereco_completo = $dados['endereco_rua'];
        if (!empty($dados['endereco_numero'])) $endereco_completo .= ", " . $dados['endereco_numero'];
        if (!empty($dados['endereco_bairro'])) $endereco_completo .= " - " . $dados['endereco_bairro'];
        if (!empty($dados['endereco_cidade'])) $endereco_completo .= ", " . $dados['endereco_cidade'];
    }
    
    // Mensagem para o cliente (evento na conversa)
    $mensagem_evento = "CONFIRMACAO DE AGENDAMENTO - IMPERIO AR\n\n";
    $mensagem_evento .= "Ola {$dados['cliente_nome']}, seu agendamento foi confirmado!\n\n";
    $mensagem_evento .= "DATA: {$data_formatada}\n";
    $mensagem_evento .= "HORARIO: {$hora_inicio} as {$hora_fim}\n";
    $mensagem_evento .= "SERVICO: {$dados['servico_nome']}\n\n";
    $mensagem_evento .= "ENDERECO DO SERVICO:\n";
    $mensagem_evento .= "{$endereco_completo}\n\n";
    
    if (!empty($dados['observacao'])) {
        $mensagem_evento .= "OBSERVACOES:\n";
        $mensagem_evento .= "{$dados['observacao']}\n\n";
    }
    
    $mensagem_evento .= "IMPERIO AR - Especialistas em Conforto Termico\n";
    $mensagem_evento .= "Telefone: (17) 99624-0725\n\n";
    $mensagem_evento .= "Este evento sera salvo na nossa conversa para que ambos possamos lembrar. Confirme sua presenca respondendo esta mensagem.";
    
    // Enviar para o cliente
    $link_cliente = gerarLinkWhatsApp($telefone_cliente, $mensagem_evento);
    
    // Também enviar para você
    $telefone_eu = '5517996240725'; // SEU NÚMERO AQUI
    $mensagem_para_mim = "EVENTO AGENDADO\n\n";
    $mensagem_para_mim .= "Cliente: {$dados['cliente_nome']}\n";
    $mensagem_para_mim .= "Data: {$data_formatada}\n";
    $mensagem_para_mim .= "Horario: {$hora_inicio} as {$hora_fim}\n";
    $mensagem_para_mim .= "Servico: {$dados['servico_nome']}\n";
    
    $link_eu = gerarLinkWhatsApp($telefone_eu, $mensagem_para_mim);
    
    // Abrir ambas as conversas
    echo "<script>
        window.open('{$link_cliente}', '_blank');
        setTimeout(function() { 
            window.open('{$link_eu}', '_blank');
            setTimeout(function() {
                window.location.href = '?view={$visualizacao}&mensagem=evento_enviado'; 
            }, 1000);
        }, 1000);
    </script>";
    exit;
}

// ===== PROCESSAR AÇÕES POST =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verificarCSRF($_POST['csrf_token'])) {
        $erro = "Token de segurança inválido.";
    } else {
        $acao_post = $_POST['acao'] ?? '';
        
        // ===== SALVAR AGENDAMENTO =====
        if ($acao_post === 'salvar') {
            $cliente_id = isset($_POST['cliente_id']) ? intval($_POST['cliente_id']) : 0;
            $data_agendamento = $_POST['data_agendamento'] ?? '';
            $horario_inicio = $_POST['horario_inicio'] ?? '';
            $horario_fim = $_POST['horario_fim'] ?? '';
            $servico_id = !empty($_POST['servico_id']) ? intval($_POST['servico_id']) : null;
            $status = $_POST['status'] ?? 'agendado';
            $observacao = $conexao->real_escape_string($_POST['observacao'] ?? '');
            $notificacao_24h = isset($_POST['notificacao_24h']) ? 1 : 0;
            $notificacao_1h = isset($_POST['notificacao_1h']) ? 1 : 0;
            
            $id_editar = isset($_POST['id']) ? intval($_POST['id']) : null;
            
            // Validações básicas
            if ($cliente_id <= 0) {
                $erro = "Selecione um cliente";
            } elseif (empty($data_agendamento)) {
                $erro = "Data do agendamento é obrigatória";
            } elseif (empty($horario_inicio)) {
                $erro = "Horário de início é obrigatório";
            } elseif (empty($horario_fim)) {
                $erro = "Horário de fim é obrigatório";
            } elseif (strtotime($horario_inicio) >= strtotime($horario_fim)) {
                $erro = "Horário de início deve ser anterior ao horário de fim";
            } else {
                // VERIFICAÇÃO DE DISPONIBILIDADE
                $sql_check = "SELECT id FROM agendamentos 
                              WHERE data_agendamento = ? 
                              AND status NOT IN ('cancelado', 'finalizado')";
                
                $params = [$data_agendamento];
                $types = "s";
                
                $sql_check .= " AND (
                                  (horario_inicio <= ? AND horario_fim > ?) OR
                                  (horario_inicio < ? AND horario_fim >= ?)
                              )";
                
                $params[] = $horario_inicio;
                $params[] = $horario_inicio;
                $params[] = $horario_fim;
                $params[] = $horario_fim;
                $types .= "ssss";
                
                if ($id_editar) {
                    $sql_check .= " AND id != ?";
                    $params[] = $id_editar;
                    $types .= "i";
                }
                
                $stmt_check = $conexao->prepare($sql_check);
                $stmt_check->bind_param($types, ...$params);
                $stmt_check->execute();
                $result_check = $stmt_check->get_result();
                
                if ($result_check->num_rows > 0) {
                    $erro = "Já existe um agendamento neste horário";
                } else {
                    if ($id_editar) {
                        // UPDATE
                        $sql = "UPDATE agendamentos SET 
                                cliente_id = ?,
                                data_agendamento = ?,
                                horario_inicio = ?,
                                horario_fim = ?,
                                servico_id = ?,
                                status = ?,
                                observacao = ?,
                                notificacao_24h = ?,
                                notificacao_1h = ?
                                WHERE id = ?";
                        
                        $stmt = $conexao->prepare($sql);
                        $stmt->bind_param(
                            "isssisiiii",
                            $cliente_id,
                            $data_agendamento,
                            $horario_inicio,
                            $horario_fim,
                            $servico_id,
                            $status,
                            $observacao,
                            $notificacao_24h,
                            $notificacao_1h,
                            $id_editar
                        );
                        
                        if ($stmt->execute()) {
                            $mensagem = "Agendamento atualizado com sucesso!";
                            header('Location: ' . BASE_URL . '/app/admin/agendamentos.php?view=' . $visualizacao . '&mensagem=atualizado');
                            exit;
                        } else {
                            $erro = "Erro ao atualizar agendamento: " . $stmt->error;
                        }
                        $stmt->close();
                        
                    } else {
                        // INSERT
                        $sql = "INSERT INTO agendamentos (
                                cliente_id, data_agendamento, horario_inicio, horario_fim,
                                servico_id, status, observacao, notificacao_24h, notificacao_1h
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        
                        $stmt = $conexao->prepare($sql);
                        $stmt->bind_param(
                            "isssisiii",
                            $cliente_id,
                            $data_agendamento,
                            $horario_inicio,
                            $horario_fim,
                            $servico_id,
                            $status,
                            $observacao,
                            $notificacao_24h,
                            $notificacao_1h
                        );
                        
                        if ($stmt->execute()) {
                            header('Location: ' . BASE_URL . '/app/admin/agendamentos.php?view=' . $visualizacao . '&mensagem=criado');
                            exit;
                        } else {
                            $erro = "Erro ao criar agendamento: " . $stmt->error;
                        }
                        $stmt->close();
                    }
                }
                $stmt_check->close();
            }
        }
        
        // ===== ATUALIZAR STATUS =====
        if ($acao_post === 'atualizar_status' && isset($_POST['id'])) {
            $agendamento_id = intval($_POST['id']);
            $novo_status = $_POST['status'] ?? '';
            
            $status_validos = ['agendado', 'confirmado', 'em_execucao', 'finalizado', 'cancelado'];
            if (in_array($novo_status, $status_validos)) {
                $sql = "UPDATE agendamentos SET status = ? WHERE id = ?";
                $stmt = $conexao->prepare($sql);
                $stmt->bind_param("si", $novo_status, $agendamento_id);
                
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
    $check = $conexao->query("SELECT status FROM agendamentos WHERE id = $id");
    $status_atual = $check->fetch_assoc()['status'];
    
    if ($status_atual !== 'cancelado' && $status_atual !== 'finalizado') {
        $erro = "Só é possível excluir agendamentos cancelados ou finalizados";
    } else {
        $sql = "DELETE FROM agendamentos WHERE id = ?";
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            header('Location: ' . BASE_URL . '/app/admin/agendamentos.php?view=' . $visualizacao . '&mensagem=deletado');
            exit;
        } else {
            $erro = "Erro ao deletar agendamento";
        }
        $stmt->close();
    }
}

if ($acao === 'confirmar' && $id) {
    $sql = "UPDATE agendamentos SET status = 'confirmado' WHERE id = ?";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        header('Location: ' . BASE_URL . '/app/admin/agendamentos.php?view=' . $visualizacao . '&mensagem=confirmado');
        exit;
    }
    $stmt->close();
}

if ($acao === 'iniciar' && $id) {
    $sql = "UPDATE agendamentos SET status = 'em_execucao' WHERE id = ?";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        header('Location: ' . BASE_URL . '/app/admin/agendamentos.php?view=' . $visualizacao . '&mensagem=iniciado');
        exit;
    }
    $stmt->close();
}

if ($acao === 'finalizar' && $id) {
    $sql = "UPDATE agendamentos SET status = 'finalizado' WHERE id = ?";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        header('Location: ' . BASE_URL . '/app/admin/agendamentos.php?view=' . $visualizacao . '&mensagem=finalizado');
        exit;
    }
    $stmt->close();
}

if ($acao === 'cancelar' && $id) {
    $sql = "UPDATE agendamentos SET status = 'cancelado' WHERE id = ?";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        header('Location: ' . BASE_URL . '/app/admin/agendamentos.php?view=' . $visualizacao . '&mensagem=cancelado');
        exit;
    }
    $stmt->close();
}

// ===== CARREGAR AGENDAMENTO PARA EDIÇÃO =====
if ($acao === 'editar' && $id) {
    $sql = "SELECT a.*, c.nome as cliente_nome 
            FROM agendamentos a 
            LEFT JOIN clientes c ON a.cliente_id = c.id 
            WHERE a.id = ?";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $agendamento = $resultado->fetch_assoc();
    $stmt->close();
    
    if (!$agendamento) {
        header('Location: ' . BASE_URL . '/app/admin/agendamentos.php?view=' . $visualizacao);
        exit;
    }
}

// ===== NOVO AGENDAMENTO =====
if ($acao === 'novo') {
    $agendamento = [
        'id' => '',
        'cliente_id' => '',
        'data_agendamento' => date('Y-m-d'),
        'horario_inicio' => '08:00',
        'horario_fim' => '09:00',
        'servico_id' => '',
        'status' => 'agendado',
        'observacao' => '',
        'notificacao_24h' => 1,
        'notificacao_1h' => 1
    ];
}

// ===== LISTAR AGENDAMENTOS =====
if ($acao === 'listar' && $visualizacao === 'lista') {
    $offset = ($pagina_atual - 1) * $por_pagina;
    
    $sql = "SELECT a.*, c.nome as cliente_nome, c.telefone, c.whatsapp,
                   s.nome as servico_nome, s.valor_unitario
            FROM agendamentos a
            LEFT JOIN clientes c ON a.cliente_id = c.id
            LEFT JOIN servicos s ON a.servico_id = s.id
            WHERE a.data_agendamento BETWEEN ? AND ?";
    $params = [$filtro_data_inicio, $filtro_data_fim];
    $types = "ss";
    
    if ($filtro_status) {
        $sql .= " AND a.status = ?";
        $params[] = $filtro_status;
        $types .= "s";
    }
    
    if ($filtro_cliente) {
        $sql .= " AND c.nome LIKE ?";
        $params[] = "%$filtro_cliente%";
        $types .= "s";
    }
    
    if ($filtro_servico) {
        $sql .= " AND a.servico_id = ?";
        $params[] = $filtro_servico;
        $types .= "i";
    }
    
    $sql .= " ORDER BY a.data_agendamento ASC, a.horario_inicio ASC LIMIT ? OFFSET ?";
    $params[] = $por_pagina;
    $params[] = $offset;
    $types .= "ii";
    
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    $agendamentos = [];
    while ($linha = $resultado->fetch_assoc()) {
        $agendamentos[] = $linha;
    }
    $stmt->close();
    
    // Total para paginação
    $sql_total = "SELECT COUNT(*) as total FROM agendamentos 
                  WHERE data_agendamento BETWEEN ? AND ?";
    $stmt_total = $conexao->prepare($sql_total);
    $stmt_total->bind_param("ss", $filtro_data_inicio, $filtro_data_fim);
    $stmt_total->execute();
    $total = $stmt_total->get_result()->fetch_assoc()['total'];
    $total_paginas = ceil($total / $por_pagina);
    $stmt_total->close();
    
    // Estatísticas
    $stats = [
        'agendado' => 0,
        'confirmado' => 0,
        'em_execucao' => 0,
        'finalizado' => 0,
        'cancelado' => 0
    ];
    
    $sql_stats = "SELECT status, COUNT(*) as total 
                  FROM agendamentos 
                  WHERE data_agendamento BETWEEN ? AND ?
                  GROUP BY status";
    $stmt_stats = $conexao->prepare($sql_stats);
    $stmt_stats->bind_param("ss", $filtro_data_inicio, $filtro_data_fim);
    $stmt_stats->execute();
    $result_stats = $stmt_stats->get_result();
    while ($row = $result_stats->fetch_assoc()) {
        if (isset($stats[$row['status']])) {
            $stats[$row['status']] = $row['total'];
        }
    }
    $stmt_stats->close();
    
    // Mensagens
    if (isset($_GET['mensagem'])) {
        $mapa = [
            'criado' => "Agendamento criado com sucesso!",
            'atualizado' => "Agendamento atualizado com sucesso!",
            'deletado' => "Agendamento deletado com sucesso!",
            'confirmado' => "Agendamento confirmado com sucesso!",
            'iniciado' => "Agendamento iniciado com sucesso!",
            'finalizado' => "Agendamento finalizado com sucesso!",
            'cancelado' => "Agendamento cancelado com sucesso!",
            'whatsapp_enviado' => "Mensagem enviada com sucesso!",
            'evento_enviado' => "Evento enviado! Agora esta salvo para voce e para o cliente.",
            'grupo_notificado' => "Grupo notificado com sucesso! Link do calendario enviado."
        ];
        $mensagem = $mapa[$_GET['mensagem']] ?? '';
    }
    
    if (isset($_GET['erro'])) {
        $mapa_erro = [
            'nao_encontrado' => "Agendamento nao encontrado",
            'sem_telefone' => "Cliente nao possui telefone cadastrado",
            'sem_telefone_grupo' => "Numero do grupo nao configurado"
        ];
        $erro = $mapa_erro[$_GET['erro']] ?? $_GET['erro'];
    }
}

// ===== VISUALIZAR CALENDÁRIO =====
if ($visualizacao === 'calendario') {
    $mes = $_GET['mes'] ?? date('m');
    $ano = $_GET['ano'] ?? date('Y');
    
    $primeiro_dia = "$ano-$mes-01";
    $ultimo_dia = date('Y-m-t', strtotime($primeiro_dia));
    
    $sql = "SELECT a.*, c.nome as cliente_nome, c.whatsapp,
                   s.nome as servico_nome
            FROM agendamentos a
            LEFT JOIN clientes c ON a.cliente_id = c.id
            LEFT JOIN servicos s ON a.servico_id = s.id
            WHERE a.data_agendamento BETWEEN ? AND ?
            ORDER BY a.data_agendamento ASC, a.horario_inicio ASC";
    
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("ss", $primeiro_dia, $ultimo_dia);
    $stmt->execute();
    $agendamentos_mes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $agendamentos_por_dia = [];
    foreach ($agendamentos_mes as $ag) {
        $dia = date('j', strtotime($ag['data_agendamento']));
        if (!isset($agendamentos_por_dia[$dia])) {
            $agendamentos_por_dia[$dia] = [];
        }
        $agendamentos_por_dia[$dia][] = $ag;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agendamentos - Império AR</title>
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
        
        .btn-whatsapp {
            background: #25D366;
            color: white;
        }
        
        .btn-whatsapp:hover {
            background: #128C7E;
        }
        
        .btn-calendar {
            background: #4285F4;
            color: white;
        }
        
        .btn-calendar:hover {
            background: #3367D6;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 13px;
        }
        
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
        
        .badge-info { background: #cce5ff; color: #004085; }
        .badge-primary { background: #cce5ff; color: #004085; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        
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
        
        .view-toggle {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .view-toggle .btn {
            flex: 1;
        }
        
        .calendar {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .calendar-weekdays {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            text-align: center;
            font-weight: bold;
            margin-bottom: 10px;
            color: var(--primary);
        }
        
        .calendar-days {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 5px;
        }
        
        .calendar-day {
            min-height: 100px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 10px;
            background: white;
        }
        
        .calendar-day.empty {
            background: #f8f9fa;
        }
        
        .calendar-day .day-number {
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .calendar-event {
            font-size: 11px;
            padding: 2px 4px;
            margin-bottom: 2px;
            border-radius: 3px;
            cursor: pointer;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .calendar-event.agendado { background: #cce5ff; }
        .calendar-event.confirmado { background: #cce5ff; border-left: 3px solid #004085; }
        .calendar-event.em_execucao { background: #fff3cd; }
        .calendar-event.finalizado { background: #d4edda; }
        .calendar-event.cancelado { background: #f8d7da; text-decoration: line-through; }
        
        .dropdown {
            position: relative;
            display: inline-block;
        }
        
        .dropdown-content {
            display: none;
            position: absolute;
            background-color: #fff;
            min-width: 250px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.2);
            z-index: 1;
            border-radius: 4px;
            padding: 10px 0;
            right: 0;
        }
        
        .dropdown:hover .dropdown-content {
            display: block;
        }
        
        .dropdown-item {
            color: #333;
            padding: 8px 15px;
            text-decoration: none;
            display: block;
            transition: background 0.3s;
        }
        
        .dropdown-item:hover {
            background-color: #f1f1f1;
        }
        
        .dropdown-divider {
            height: 1px;
            background-color: #e0e0e0;
            margin: 8px 0;
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
            <div class="view-toggle">
                <a href="?view=lista" class="btn btn-<?php echo $visualizacao === 'lista' ? 'primary' : 'secondary'; ?>">
                    <i class="fas fa-list"></i> Lista
                </a>
                <a href="?view=calendario" class="btn btn-<?php echo $visualizacao === 'calendario' ? 'primary' : 'secondary'; ?>">
                    <i class="fas fa-calendar-alt"></i> Calendário
                </a>
            </div>
            <?php endif; ?>

            <?php if ($acao === 'listar' && $visualizacao === 'lista'): ?>
                <div class="page-header">
                    <h1>
                        <i class="fas fa-calendar-check"></i>
                        Gerenciamento de Agendamentos
                    </h1>
                    <div class="header-actions">
                        <a href="?acao=novo&view=<?php echo $visualizacao; ?>" class="btn btn-primary">
                            <i class="fas fa-plus-circle"></i> Novo Agendamento
                        </a>
                    </div>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #cce5ff; color: #004085;">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['agendado'] ?? 0; ?></h3>
                            <p>Agendados</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #cce5ff; color: #004085;">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['confirmado'] ?? 0; ?></h3>
                            <p>Confirmados</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #fff3cd; color: #856404;">
                            <i class="fas fa-spinner"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['em_execucao'] ?? 0; ?></h3>
                            <p>Em Execução</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #d4edda; color: #155724;">
                            <i class="fas fa-flag-checkered"></i>
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

                <div class="filters">
                    <form method="GET" class="filter-row">
                        <input type="hidden" name="view" value="lista">
                        
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" class="form-control">
                                <option value="">Todos</option>
                                <option value="agendado" <?php echo ($filtro_status ?? '') == 'agendado' ? 'selected' : ''; ?>>Agendado</option>
                                <option value="confirmado" <?php echo ($filtro_status ?? '') == 'confirmado' ? 'selected' : ''; ?>>Confirmado</option>
                                <option value="em_execucao" <?php echo ($filtro_status ?? '') == 'em_execucao' ? 'selected' : ''; ?>>Em Execução</option>
                                <option value="finalizado" <?php echo ($filtro_status ?? '') == 'finalizado' ? 'selected' : ''; ?>>Finalizado</option>
                                <option value="cancelado" <?php echo ($filtro_status ?? '') == 'cancelado' ? 'selected' : ''; ?>>Cancelado</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Cliente</label>
                            <input type="text" name="cliente" class="form-control" placeholder="Nome do cliente" value="<?php echo htmlspecialchars($filtro_cliente ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label>Serviço</label>
                            <select name="servico" class="form-control">
                                <option value="">Todos</option>
                                <?php foreach ($servicos as $s): ?>
                                <option value="<?php echo $s['id']; ?>" <?php echo ($filtro_servico ?? '') == $s['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($s['nome']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Data Início</label>
                            <input type="date" name="data_inicio" class="form-control" value="<?php echo $filtro_data_inicio ?? date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label>Data Fim</label>
                            <input type="date" name="data_fim" class="form-control" value="<?php echo $filtro_data_fim ?? date('Y-m-d', strtotime('+30 days')); ?>">
                        </div>
                        
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary" style="width: 100%;">
                                <i class="fas fa-filter"></i> Filtrar
                            </button>
                        </div>
                    </form>
                </div>

                <?php if (!empty($agendamentos)): ?>
                <div class="card">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Data/Hora</th>
                                    <th>Cliente</th>
                                    <th>Serviço</th>
                                    <th>Status</th>
                                    <th>Contato</th>
                                    <th>Notificações</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($agendamentos as $item): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo formatarData($item['data_agendamento']); ?></strong><br>
                                        <small><?php echo formatarHora($item['horario_inicio']) . ' - ' . formatarHora($item['horario_fim']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($item['cliente_nome']); ?></td>
                                    <td><?php echo htmlspecialchars($item['servico_nome'] ?? '-'); ?></td>
                                    <td><?php echo getStatusBadge($item['status']); ?></td>
                                    <td>
                                        <?php if (!empty($item['whatsapp'])): ?>
                                            <a href="https://wa.me/55<?php echo preg_replace('/[^0-9]/', '', $item['whatsapp']); ?>" target="_blank" class="text-success">
                                                <i class="fab fa-whatsapp"></i>
                                            </a>
                                        <?php endif; ?>
                                        <?php if (!empty($item['telefone'])): ?>
                                            <br><small><?php echo $item['telefone']; ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($item['notificacao_24h']): ?>
                                            <span class="badge badge-info" title="Notificação 24h">24h</span>
                                        <?php endif; ?>
                                        <?php if ($item['notificacao_1h']): ?>
                                            <span class="badge badge-info" title="Notificação 1h">1h</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <a href="?acao=editar&id=<?php echo $item['id']; ?>&view=<?php echo $visualizacao; ?>" class="btn btn-sm btn-primary" title="Editar">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            
                                            <?php if ($item['status'] == 'agendado'): ?>
                                                <a href="?acao=confirmar&id=<?php echo $item['id']; ?>&view=<?php echo $visualizacao; ?>" class="btn btn-sm btn-success" title="Confirmar" onclick="return confirm('Confirmar este agendamento?')">
                                                    <i class="fas fa-check"></i>
                                                </a>
                                            <?php endif; ?>
                                            
                                            <?php if ($item['status'] == 'confirmado'): ?>
                                                <a href="?acao=iniciar&id=<?php echo $item['id']; ?>&view=<?php echo $visualizacao; ?>" class="btn btn-sm btn-warning" title="Iniciar" onclick="return confirm('Iniciar execução?')">
                                                    <i class="fas fa-play"></i>
                                                </a>
                                            <?php endif; ?>
                                            
                                            <?php if ($item['status'] == 'em_execucao'): ?>
                                                <a href="?acao=finalizar&id=<?php echo $item['id']; ?>&view=<?php echo $visualizacao; ?>" class="btn btn-sm btn-success" title="Finalizar" onclick="return confirm('Finalizar este agendamento?')">
                                                    <i class="fas fa-flag-checkered"></i>
                                                </a>
                                            <?php endif; ?>
                                            
                                            <?php if (!in_array($item['status'], ['finalizado', 'cancelado'])): ?>
                                                <a href="?acao=cancelar&id=<?php echo $item['id']; ?>&view=<?php echo $visualizacao; ?>" class="btn btn-sm btn-danger" title="Cancelar" onclick="return confirm('Cancelar este agendamento?')">
                                                    <i class="fas fa-times"></i>
                                                </a>
                                            <?php endif; ?>
                                            
                                            <!-- BOTÃO EVENTO WHATSAPP -->
                                            <?php if (!empty($item['whatsapp']) || !empty($item['telefone'])): ?>
                                            <a href="?acao=enviar_evento&id=<?php echo $item['id']; ?>&view=<?php echo $visualizacao; ?>" 
                                               class="btn btn-sm btn-calendar" 
                                               title="Criar evento na conversa (salvo para ambos)"
                                               target="_blank">
                                                <i class="fas fa-calendar-plus"></i> Evento
                                            </a>
                                            <?php endif; ?>
                                            
                                            <!-- DROPDOWN WHATSAPP -->
                                            <div class="dropdown" style="display: inline-block;">
                                                <button class="btn btn-sm btn-whatsapp" style="border-radius: 4px;">
                                                    <i class="fab fa-whatsapp"></i> ▼
                                                </button>
                                                <div class="dropdown-content">
                                                    <a href="?acao=enviar_whatsapp&id=<?php echo $item['id']; ?>&tipo=cliente&view=<?php echo $visualizacao; ?>" 
                                                       class="dropdown-item" target="_blank">
                                                        Enviar para Cliente
                                                    </a>
                                                    <a href="?acao=enviar_whatsapp&id=<?php echo $item['id']; ?>&tipo=eu&view=<?php echo $visualizacao; ?>" 
                                                       class="dropdown-item" target="_blank">
                                                        Enviar para mim (com calendário)
                                                    </a>
                                                    <a href="?acao=enviar_whatsapp&id=<?php echo $item['id']; ?>&tipo=grupo&view=<?php echo $visualizacao; ?>" 
                                                       class="dropdown-item" target="_blank">
                                                        Enviar para Grupo (com calendário)
                                                    </a>
                                                    <div class="dropdown-divider"></div>
                                                    <div style="padding: 8px 15px;">
                                                        <small>Mensagem personalizada:</small>
                                                        <input type="text" id="msg_<?php echo $item['id']; ?>" class="form-control form-control-sm" style="margin-top: 5px;" placeholder="Digite...">
                                                        <button class="btn btn-sm btn-success" style="margin-top: 5px; width: 100%;" onclick="enviarComMensagem(<?php echo $item['id']; ?>)">
                                                            Enviar
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <?php if (in_array($item['status'], ['cancelado', 'finalizado'])): ?>
                                            <a href="?acao=deletar&id=<?php echo $item['id']; ?>&view=<?php echo $visualizacao; ?>" class="btn btn-sm btn-danger" title="Excluir" onclick="return confirm('Tem certeza? Esta ação não pode ser desfeita.')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php if (!empty($item['observacao'])): ?>
                                <tr style="background: #f8f9fa;">
                                    <td colspan="7">
                                        <small><strong>Obs:</strong> <?php echo htmlspecialchars($item['observacao']); ?></small>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if ($total_paginas > 1): ?>
                <div class="pagination">
                    <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
                    <a href="?pagina=<?php echo $i; ?>&status=<?php echo urlencode($filtro_status ?? ''); ?>&cliente=<?php echo urlencode($filtro_cliente ?? ''); ?>&servico=<?php echo urlencode($filtro_servico ?? ''); ?>&data_inicio=<?php echo urlencode($filtro_data_inicio ?? ''); ?>&data_fim=<?php echo urlencode($filtro_data_fim ?? ''); ?>&view=lista" 
                       class="<?php echo $i == $pagina_atual ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>

                <?php else: ?>
                <div class="empty-message" style="text-align: center; padding: 50px; background: white; border-radius: 12px;">
                    <i class="fas fa-calendar-times fa-4x" style="color: #ccc; margin-bottom: 20px;"></i>
                    <h3 style="color: #666; margin-bottom: 10px;">Nenhum agendamento encontrado</h3>
                    <p style="color: #999; margin-bottom: 20px;">Comece criando um novo agendamento.</p>
                    <a href="?acao=novo&view=<?php echo $visualizacao; ?>" class="btn btn-primary">
                        <i class="fas fa-plus-circle"></i> Novo Agendamento
                    </a>
                </div>
                <?php endif; ?>

            <?php elseif ($visualizacao === 'calendario' && $acao === 'listar'): ?>
                <div class="page-header">
                    <h1>
                        <i class="fas fa-calendar-alt"></i>
                        Calendário de Agendamentos
                    </h1>
                    <div class="header-actions">
                        <a href="?acao=novo&view=calendario&mes=<?php echo $mes; ?>&ano=<?php echo $ano; ?>" class="btn btn-primary">
                            <i class="fas fa-plus-circle"></i> Novo Agendamento
                        </a>
                    </div>
                </div>

                <div class="calendar">
                    <div class="calendar-header">
                        <a href="?view=calendario&mes=<?php echo $mes-1; ?>&ano=<?php echo $ano; ?>" class="btn btn-sm btn-secondary">
                            <i class="fas fa-chevron-left"></i> Mês Anterior
                        </a>
                        <h2><?php echo strftime('%B de %Y', strtotime("$ano-$mes-01")); ?></h2>
                        <a href="?view=calendario&mes=<?php echo $mes+1; ?>&ano=<?php echo $ano; ?>" class="btn btn-sm btn-secondary">
                            Próximo Mês <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>

                    <div class="calendar-weekdays">
                        <div>Dom</div>
                        <div>Seg</div>
                        <div>Ter</div>
                        <div>Qua</div>
                        <div>Qui</div>
                        <div>Sex</div>
                        <div>Sáb</div>
                    </div>

                    <div class="calendar-days">
                        <?php
                        $primeiro_dia_semana = date('w', strtotime($primeiro_dia));
                        $dias_no_mes = date('t', strtotime($primeiro_dia));
                        
                        for ($i = 0; $i < $primeiro_dia_semana; $i++):
                        ?>
                        <div class="calendar-day empty"></div>
                        <?php endfor; ?>
                        
                        <?php for ($dia = 1; $dia <= $dias_no_mes; $dia++): 
                            $eventos = $agendamentos_por_dia[$dia] ?? [];
                        ?>
                        <div class="calendar-day">
                            <div class="day-number"><?php echo $dia; ?></div>
                            <?php foreach ($eventos as $evento): ?>
                            <div class="calendar-event <?php echo $evento['status']; ?>" 
                                 onclick="window.location.href='?acao=editar&id=<?php echo $evento['id']; ?>&view=calendario&mes=<?php echo $mes; ?>&ano=<?php echo $ano; ?>'"
                                 title="<?php echo $evento['cliente_nome'] . ' - ' . formatarHora($evento['horario_inicio']); ?>">
                                <?php echo formatarHora($evento['horario_inicio']) . ' - ' . htmlspecialchars($evento['cliente_nome']); ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

            <?php elseif ($acao === 'novo' || $acao === 'editar'): ?>
                <div class="page-header">
                    <h1>
                        <i class="fas fa-<?php echo $acao === 'novo' ? 'plus-circle' : 'edit'; ?>"></i>
                        <?php echo $acao === 'novo' ? 'Novo Agendamento' : 'Editar Agendamento'; ?>
                    </h1>
                    <div class="header-actions">
                        <a href="?view=<?php echo $visualizacao; ?><?php echo ($visualizacao === 'calendario' ? '&mes=' . ($_GET['mes'] ?? date('m')) . '&ano=' . ($_GET['ano'] ?? date('Y')) : ''); ?>" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Voltar
                        </a>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-calendar-plus"></i>
                            Informações do Agendamento
                        </h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="form-agendamento">
                            <input type="hidden" name="acao" value="salvar">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <?php if ($acao === 'editar'): ?>
                                <input type="hidden" name="id" value="<?php echo $agendamento['id']; ?>">
                            <?php endif; ?>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Cliente *</label>
                                    <select name="cliente_id" required class="form-control">
                                        <option value="">-- Selecione um cliente --</option>
                                        <?php foreach ($clientes as $cli): ?>
                                        <option value="<?php echo $cli['id']; ?>" 
                                                <?php echo ($agendamento['cliente_id'] ?? '') == $cli['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cli['nome'] . ' - ' . ($cli['telefone'] ?? $cli['whatsapp'] ?? '')); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label>Data do Agendamento *</label>
                                    <input type="date" name="data_agendamento" value="<?php echo $agendamento['data_agendamento'] ?? date('Y-m-d'); ?>" required class="form-control">
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Horário Início *</label>
                                    <input type="time" name="horario_inicio" value="<?php echo $agendamento['horario_inicio'] ?? '08:00'; ?>" required class="form-control">
                                </div>

                                <div class="form-group">
                                    <label>Horário Fim *</label>
                                    <input type="time" name="horario_fim" value="<?php echo $agendamento['horario_fim'] ?? '09:00'; ?>" required class="form-control">
                                </div>

                                <div class="form-group">
                                    <label>Serviço</label>
                                    <select name="servico_id" class="form-control" id="servico_select">
                                        <option value="">-- Selecione um serviço --</option>
                                        <?php foreach ($servicos as $s): ?>
                                        <option value="<?php echo $s['id']; ?>" 
                                                data-tempo="<?php echo $s['tempo_execucao']; ?>"
                                                <?php echo ($agendamento['servico_id'] ?? '') == $s['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($s['nome']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Status</label>
                                    <select name="status" class="form-control">
                                        <option value="agendado" <?php echo ($agendamento['status'] ?? '') == 'agendado' ? 'selected' : ''; ?>>Agendado</option>
                                        <option value="confirmado" <?php echo ($agendamento['status'] ?? '') == 'confirmado' ? 'selected' : ''; ?>>Confirmado</option>
                                        <option value="em_execucao" <?php echo ($agendamento['status'] ?? '') == 'em_execucao' ? 'selected' : ''; ?>>Em Execução</option>
                                        <option value="finalizado" <?php echo ($agendamento['status'] ?? '') == 'finalizado' ? 'selected' : ''; ?>>Finalizado</option>
                                        <option value="cancelado" <?php echo ($agendamento['status'] ?? '') == 'cancelado' ? 'selected' : ''; ?>>Cancelado</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label>Notificações</label>
                                    <div style="display: flex; gap: 20px; padding: 10px 0;">
                                        <label style="display: flex; align-items: center; gap: 5px;">
                                            <input type="checkbox" name="notificacao_24h" value="1" <?php echo ($agendamento['notificacao_24h'] ?? 1) ? 'checked' : ''; ?>>
                                            24h antes
                                        </label>
                                        <label style="display: flex; align-items: center; gap: 5px;">
                                            <input type="checkbox" name="notificacao_1h" value="1" <?php echo ($agendamento['notificacao_1h'] ?? 1) ? 'checked' : ''; ?>>
                                            1h antes
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Observações</label>
                                <textarea name="observacao" rows="3" class="form-control" placeholder="Observações sobre o agendamento..."><?php echo htmlspecialchars($agendamento['observacao'] ?? ''); ?></textarea>
                            </div>

                            <div style="margin-top: 30px; display: flex; gap: 15px; justify-content: flex-end;">
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="fas fa-save"></i> Salvar Agendamento
                                </button>
                                <a href="?view=<?php echo $visualizacao; ?><?php echo ($visualizacao === 'calendario' ? '&mes=' . ($_GET['mes'] ?? date('m')) . '&ano=' . ($_GET['ano'] ?? date('Y')) : ''); ?>" class="btn btn-secondary btn-lg">
                                    <i class="fas fa-times"></i> Cancelar
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        document.getElementById('servico_select')?.addEventListener('change', function() {
            const option = this.options[this.selectedIndex];
            const tempo = option.getAttribute('data-tempo');
            
            if (tempo && tempo > 0) {
                const inicio = document.querySelector('input[name="horario_inicio"]').value;
                if (inicio) {
                    const [h, m] = inicio.split(':');
                    const data = new Date();
                    data.setHours(parseInt(h), parseInt(m) + parseInt(tempo));
                    const horaFim = data.getHours().toString().padStart(2, '0') + ':' + 
                                   data.getMinutes().toString().padStart(2, '0');
                    document.querySelector('input[name="horario_fim"]').value = horaFim;
                }
            }
        });

        function enviarComMensagem(id) {
            const msg = document.getElementById('msg_' + id).value;
            if (msg.trim() === '') {
                alert('Digite uma mensagem!');
                return;
            }
            window.open('?acao=enviar_whatsapp&id=' + id + '&tipo=cliente&msg=' + encodeURIComponent(msg), '_blank');
        }
    </script>
</body>
</html>
