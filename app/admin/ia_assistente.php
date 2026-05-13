<?php
/**
 * =====================================================================
 * IA ASSISTENTE - CONTROLE TOTAL DO SISTEMA IMPÉRIO AR
 * =====================================================================
 * 
 * Funcionalidades:
 * - Executa ações reais no sistema (CRUD completo)
 * - Controla todas as áreas: clientes, orçamentos, vendas, etc.
 * - Interface por voz e texto
 * - Ações em tempo real
 */

session_start();
require_once __DIR__ . '/../../config/environment.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';

if (!Auth::isLogado() || !Auth::isAdmin()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$usuario = Auth::obter_usuario();
global $conexao;

// ===== FUNÇÕES DE EXECUÇÃO DE AÇÕES =====

/**
 * Executa ações no sistema baseado no comando do usuário
 */
function executarAcao($comando, $conexao) {
    $comando_lower = strtolower($comando);
    $resposta = "";
    $acao_executada = false;
    $dados_acao = [];
    
    // ===== 1. CRIAR CLIENTE =====
    if (preg_match('/criar (um )?cliente (chamado|com nome|)["\']?([a-zA-ZÀ-Ú\s]+)["\']?/i', $comando, $matches)) {
        $nome_cliente = trim($matches[3] ?? '');
        if (empty($nome_cliente)) {
            $resposta = "❌ Para criar um cliente, diga: 'criar cliente João Silva'";
        } else {
            // Verificar se cliente já existe
            $check = $conexao->query("SELECT id FROM clientes WHERE nome LIKE '%" . addslashes($nome_cliente) . "%'");
            if ($check && $check->num_rows > 0) {
                $resposta = "⚠️ Já existe um cliente com nome similar. Deseja criar mesmo assim?";
            } else {
                $sql = "INSERT INTO clientes (nome, ativo, data_cadastro) VALUES ('" . addslashes($nome_cliente) . "', 1, NOW())";
                if ($conexao->query($sql)) {
                    $id = $conexao->insert_id;
                    $resposta = "✅ Cliente **{$nome_cliente}** criado com sucesso! ID: {$id}\n\nDeseja adicionar telefone ou email agora?";
                    $acao_executada = true;
                    $dados_acao = ['tipo' => 'cliente_criado', 'id' => $id, 'nome' => $nome_cliente];
                } else {
                    $resposta = "❌ Erro ao criar cliente: " . $conexao->error;
                }
            }
        }
    }
    
    // ===== 2. ADICIONAR TELEFONE/WHATSAPP AO CLIENTE =====
    elseif (preg_match('/adicionar (telefone|whatsapp|celular) (\d{10,11}) (ao|para o) cliente ["\']?([a-zA-ZÀ-Ú\s]+)["\']?/i', $comando, $matches)) {
        $tipo = $matches[1];
        $numero = $matches[2];
        $nome_cliente = trim($matches[4] ?? '');
        
        // Buscar cliente
        $sql = "SELECT id FROM clientes WHERE nome LIKE '%" . addslashes($nome_cliente) . "%' LIMIT 1";
        $result = $conexao->query($sql);
        
        if ($result && $result->num_rows > 0) {
            $cliente = $result->fetch_assoc();
            $campo = $tipo == 'whatsapp' ? 'whatsapp' : 'telefone';
            $sql_update = "UPDATE clientes SET $campo = '$numero' WHERE id = {$cliente['id']}";
            if ($conexao->query($sql_update)) {
                $resposta = "✅ {$tipo} adicionado ao cliente {$nome_cliente}!";
                $acao_executada = true;
            } else {
                $resposta = "❌ Erro ao adicionar telefone.";
            }
        } else {
            $resposta = "❌ Cliente '{$nome_cliente}' não encontrado.";
        }
    }
    
    // ===== 3. CRIAR ORÇAMENTO =====
    elseif (preg_match('/criar (um )?orçamento para (o cliente )?["\']?([a-zA-ZÀ-Ú\s]+)["\']? (de|no) valor (de )?R?\$?[\s]*(\d+(?:[.,]\d{2})?)/i', $comando, $matches)) {
        $nome_cliente = trim($matches[3] ?? '');
        $valor_str = str_replace(',', '.', $matches[6]);
        $valor = floatval($valor_str);
        
        // Buscar cliente
        $sql = "SELECT id FROM clientes WHERE nome LIKE '%" . addslashes($nome_cliente) . "%' LIMIT 1";
        $result = $conexao->query($sql);
        
        if ($result && $result->num_rows > 0) {
            $cliente = $result->fetch_assoc();
            $numero = 'ORC-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $data_validade = date('Y-m-d', strtotime('+30 days'));
            
            $sql_insert = "INSERT INTO orcamentos (numero, cliente_id, data_emissao, data_validade, situacao, valor_total) 
                           VALUES ('$numero', {$cliente['id']}, CURDATE(), '$data_validade', 'pendente', $valor)";
            
            if ($conexao->query($sql_insert)) {
                $id = $conexao->insert_id;
                $resposta = "✅ Orçamento criado com sucesso!\n\n📄 Número: {$numero}\n👤 Cliente: {$nome_cliente}\n💰 Valor: R$ " . number_format($valor, 2, ',', '.') . "\n📅 Validade: " . date('d/m/Y', strtotime($data_validade)) . "\n\nDeseja adicionar produtos ou serviços a este orçamento?";
                $acao_executada = true;
                $dados_acao = ['tipo' => 'orcamento_criado', 'id' => $id, 'numero' => $numero];
            } else {
                $resposta = "❌ Erro ao criar orçamento: " . $conexao->error;
            }
        } else {
            $resposta = "❌ Cliente '{$nome_cliente}' não encontrado. Deseja criá-lo primeiro?";
        }
    }
    
    // ===== 4. ADICIONAR PRODUTO AO ORÇAMENTO =====
    elseif (preg_match('/adicionar (produto|serviço) ["\']?([a-zA-ZÀ-Ú\s]+)["\']? ao orçamento (número|#)?(\d+)/i', $comando, $matches)) {
        $tipo_item = $matches[1];
        $nome_item = trim($matches[2] ?? '');
        $num_orcamento = $matches[4];
        
        // Buscar orçamento
        $sql_orc = "SELECT id FROM orcamentos WHERE numero LIKE '%$num_orcamento%' OR id = $num_orcamento LIMIT 1";
        $result_orc = $conexao->query($sql_orc);
        
        if ($result_orc && $result_orc->num_rows > 0) {
            $orcamento = $result_orc->fetch_assoc();
            
            // Buscar produto/serviço
            if ($tipo_item == 'produto') {
                $sql_item = "SELECT id, valor_venda FROM produtos WHERE nome LIKE '%" . addslashes($nome_item) . "%' LIMIT 1";
            } else {
                $sql_item = "SELECT id, valor_unitario FROM servicos WHERE nome LIKE '%" . addslashes($nome_item) . "%' LIMIT 1";
            }
            $result_item = $conexao->query($sql_item);
            
            if ($result_item && $result_item->num_rows > 0) {
                $item = $result_item->fetch_assoc();
                $valor = $tipo_item == 'produto' ? $item['valor_venda'] : $item['valor_unitario'];
                
                if ($tipo_item == 'produto') {
                    $sql_insert = "INSERT INTO orcamento_produtos (orcamento_id, produto_id, quantidade, valor_unitario, subtotal) 
                                   VALUES ({$orcamento['id']}, {$item['id']}, 1, $valor, $valor)";
                } else {
                    $sql_insert = "INSERT INTO orcamento_servicos (orcamento_id, servico_id, quantidade, valor_unitario, subtotal) 
                                   VALUES ({$orcamento['id']}, {$item['id']}, 1, $valor, $valor)";
                }
                
                if ($conexao->query($sql_insert)) {
                    // Atualizar valor total do orçamento
                    $conexao->query("UPDATE orcamentos SET valor_total = valor_total + $valor WHERE id = {$orcamento['id']}");
                    $resposta = "✅ {$tipo_item} '{$nome_item}' adicionado ao orçamento #{$num_orcamento}!\n💰 Valor adicionado: R$ " . number_format($valor, 2, ',', '.');
                    $acao_executada = true;
                } else {
                    $resposta = "❌ Erro ao adicionar item: " . $conexao->error;
                }
            } else {
                $resposta = "❌ {$tipo_item} '{$nome_item}' não encontrado. Deseja cadastrá-lo primeiro?";
            }
        } else {
            $resposta = "❌ Orçamento #{$num_orcamento} não encontrado.";
        }
    }
    
    // ===== 5. APROVAR ORÇAMENTO =====
    elseif (preg_match('/(aprovar|aceitar) (o )?orçamento (número|#)?(\d+)/i', $comando, $matches)) {
        $num_orcamento = $matches[4];
        
        $sql = "UPDATE orcamentos SET situacao = 'aprovado' WHERE numero LIKE '%$num_orcamento%' OR id = $num_orcamento";
        if ($conexao->query($sql) && $conexao->affected_rows > 0) {
            $resposta = "✅ Orçamento #{$num_orcamento} aprovado com sucesso!\n\nDeseja gerar o pedido agora?";
            $acao_executada = true;
        } else {
            $resposta = "❌ Orçamento #{$num_orcamento} não encontrado ou já está aprovado.";
        }
    }
    
    // ===== 6. CONCLUIR ORÇAMENTO (gerar venda e cobrança) =====
    elseif (preg_match('/(concluir|finalizar) (o )?orçamento (número|#)?(\d+)/i', $comando, $matches)) {
        $num_orcamento = $matches[4];
        
        // Buscar orçamento
        $sql_orc = "SELECT id, cliente_id, valor_total FROM orcamentos WHERE numero LIKE '%$num_orcamento%' OR id = $num_orcamento LIMIT 1";
        $result = $conexao->query($sql_orc);
        
        if ($result && $result->num_rows > 0) {
            $orcamento = $result->fetch_assoc();
            
            // Atualizar status
            $conexao->query("UPDATE orcamentos SET situacao = 'concluido' WHERE id = {$orcamento['id']}");
            
            // Criar venda
            $num_venda = 'VND-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $sql_venda = "INSERT INTO vendas (numero, cliente_id, orcamento_origem_id, data_venda, situacao, valor_total) 
                          VALUES ('$num_venda', {$orcamento['cliente_id']}, {$orcamento['id']}, CURDATE(), 'finalizado', {$orcamento['valor_total']})";
            $conexao->query($sql_venda);
            $venda_id = $conexao->insert_id;
            
            // Criar cobrança
            $num_cobranca = 'COB-' . date('Ymd') . '-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
            $data_vencimento = date('Y-m-d', strtotime('+30 days'));
            $sql_cobranca = "INSERT INTO cobrancas (numero, cliente_id, orcamento_id, venda_id, valor, data_vencimento, status) 
                             VALUES ('$num_cobranca', {$orcamento['cliente_id']}, {$orcamento['id']}, $venda_id, {$orcamento['valor_total']}, '$data_vencimento', 'pendente')";
            $conexao->query($sql_cobranca);
            
            $resposta = "✅ Orçamento #{$num_orcamento} concluído com sucesso!\n\n";
            $resposta .= "📄 Venda criada: {$num_venda}\n";
            $resposta .= "💰 Cobrança gerada: {$num_cobranca}\n";
            $resposta .= "📅 Vencimento: " . date('d/m/Y', strtotime($data_vencimento)) . "\n\n";
            $resposta .= "Deseja enviar a cobrança por WhatsApp?";
            $acao_executada = true;
        } else {
            $resposta = "❌ Orçamento #{$num_orcamento} não encontrado.";
        }
    }
    
    // ===== 7. REGISTRAR PAGAMENTO =====
    elseif (preg_match('/(registrar|receber) pagamento (de|do) (cliente|orçamento) ["\']?([a-zA-ZÀ-Ú\s]+)["\']? (de|no) valor R?\$?[\s]*(\d+(?:[.,]\d{2})?)/i', $comando, $matches)) {
        $nome_cliente = trim($matches[4] ?? '');
        $valor_str = str_replace(',', '.', $matches[6]);
        $valor = floatval($valor_str);
        
        // Buscar cliente
        $sql = "SELECT id FROM clientes WHERE nome LIKE '%" . addslashes($nome_cliente) . "%' LIMIT 1";
        $result = $conexao->query($sql);
        
        if ($result && $result->num_rows > 0) {
            $cliente = $result->fetch_assoc();
            
            // Buscar cobrança pendente
            $sql_cob = "SELECT id, numero, valor FROM cobrancas WHERE cliente_id = {$cliente['id']} AND status = 'pendente' ORDER BY data_vencimento ASC LIMIT 1";
            $result_cob = $conexao->query($sql_cob);
            
            if ($result_cob && $result_cob->num_rows > 0) {
                $cobranca = $result_cob->fetch_assoc();
                $sql_update = "UPDATE cobrancas SET status = 'recebida', data_recebimento = CURDATE() WHERE id = {$cobranca['id']}";
                $conexao->query($sql_update);
                
                // Registrar no financeiro
                $sql_fin = "INSERT INTO financeiro (tipo, valor, descricao, categoria, cliente_id, data_transacao, forma_pagamento) 
                            VALUES ('entrada', $valor, 'Pagamento de cobrança #{$cobranca['numero']}', 'cobrancas', {$cliente['id']}, CURDATE(), 'pix')";
                $conexao->query($sql_fin);
                
                $resposta = "✅ Pagamento de R$ " . number_format($valor, 2, ',', '.') . " registrado para o cliente {$nome_cliente}!\n";
                $resposta .= "💰 Cobrança #{$cobranca['numero']} marcada como recebida.";
                $acao_executada = true;
            } else {
                $resposta = "⚠️ Cliente {$nome_cliente} não possui cobranças pendentes.";
            }
        } else {
            $resposta = "❌ Cliente '{$nome_cliente}' não encontrado.";
        }
    }
    
    // ===== 8. CRIAR PRODUTO =====
    elseif (preg_match('/criar (um )?produto chamado ["\']?([a-zA-ZÀ-Ú\s]+)["\']? (com valor de|por) R?\$?[\s]*(\d+(?:[.,]\d{2})?)/i', $comando, $matches)) {
        $nome_produto = trim($matches[2] ?? '');
        $valor_str = str_replace(',', '.', $matches[4]);
        $valor = floatval($valor_str);
        
        if (empty($nome_produto) || $valor <= 0) {
            $resposta = "❌ Para criar um produto, diga: 'criar produto Ar Condicionado com valor de R$ 1500'";
        } else {
            $sql = "INSERT INTO produtos (nome, valor_venda, ativo) VALUES ('" . addslashes($nome_produto) . "', $valor, 1)";
            if ($conexao->query($sql)) {
                $id = $conexao->insert_id;
                $resposta = "✅ Produto **{$nome_produto}** criado com sucesso!\n💰 Valor: R$ " . number_format($valor, 2, ',', '.') . "\n📦 Estoque inicial: 0\n\nDeseja adicionar estoque?";
                $acao_executada = true;
            } else {
                $resposta = "❌ Erro ao criar produto: " . $conexao->error;
            }
        }
    }
    
    // ===== 9. ATUALIZAR ESTOQUE =====
    elseif (preg_match('/adicionar (\d+) (unidades? de )?estoque (ao|para o) produto ["\']?([a-zA-ZÀ-Ú\s]+)["\']?/i', $comando, $matches)) {
        $quantidade = intval($matches[1]);
        $nome_produto = trim($matches[4] ?? '');
        
        $sql = "UPDATE produtos SET estoque_atual = estoque_atual + $quantidade WHERE nome LIKE '%" . addslashes($nome_produto) . "%'";
        if ($conexao->query($sql) && $conexao->affected_rows > 0) {
            $resposta = "✅ {$quantidade} unidades adicionadas ao estoque do produto {$nome_produto}!";
            $acao_executada = true;
        } else {
            $resposta = "❌ Produto '{$nome_produto}' não encontrado.";
        }
    }
    
    // ===== 10. AGENDAR SERVIÇO =====
    elseif (preg_match('/agendar (um )?serviço para (o cliente )?["\']?([a-zA-ZÀ-Ú\s]+)["\']? no dia (\d{1,2}\/\d{1,2}\/\d{4})/i', $comando, $matches)) {
        $nome_cliente = trim($matches[3] ?? '');
        $data_agendamento = date('Y-m-d', strtotime(str_replace('/', '-', $matches[4])));
        
        // Buscar cliente
        $sql_cli = "SELECT id FROM clientes WHERE nome LIKE '%" . addslashes($nome_cliente) . "%' LIMIT 1";
        $result_cli = $conexao->query($sql_cli);
        
        if ($result_cli && $result_cli->num_rows > 0) {
            $cliente = $result_cli->fetch_assoc();
            $sql_agend = "INSERT INTO agendamentos (cliente_id, data_agendamento, horario_inicio, status) 
                          VALUES ({$cliente['id']}, '$data_agendamento', '08:00:00', 'agendado')";
            if ($conexao->query($sql_agend)) {
                $id = $conexao->insert_id;
                $resposta = "✅ Serviço agendado para {$nome_cliente} no dia " . date('d/m/Y', strtotime($data_agendamento)) . "!\n🆔 Agendamento ID: {$id}\n\nDeseja definir um horário específico?";
                $acao_executada = true;
            } else {
                $resposta = "❌ Erro ao agendar: " . $conexao->error;
            }
        } else {
            $resposta = "❌ Cliente '{$nome_cliente}' não encontrado.";
        }
    }
    
    // ===== 11. ALTERAR STATUS DE AGENDAMENTO =====
    elseif (preg_match('/(marcar|alterar) agendamento (como|para) (confirmado|finalizado|cancelado)/i', $comando, $matches)) {
        $novo_status = $matches[3];
        // Buscar último agendamento do dia
        $sql = "SELECT id FROM agendamentos WHERE data_agendamento = CURDATE() ORDER BY id DESC LIMIT 1";
        $result = $conexao->query($sql);
        
        if ($result && $result->num_rows > 0) {
            $agend = $result->fetch_assoc();
            $sql_update = "UPDATE agendamentos SET status = '$novo_status' WHERE id = {$agend['id']}";
            if ($conexao->query($sql_update)) {
                $resposta = "✅ Agendamento marcado como **{$novo_status}**!";
                $acao_executada = true;
            } else {
                $resposta = "❌ Erro ao atualizar status.";
            }
        } else {
            $resposta = "❌ Nenhum agendamento encontrado para hoje.";
        }
    }
    
    // ===== 12. RELATÓRIOS E CONSULTAS =====
    elseif (preg_match('/(listar|mostrar) (clientes|produtos|serviços|orçamentos|vendas|cobranças)/i', $comando, $matches)) {
        $tabela = $matches[2];
        $tabela_map = [
            'clientes' => 'clientes', 'produtos' => 'produtos', 'serviços' => 'servicos',
            'orçamentos' => 'orcamentos', 'vendas' => 'vendas', 'cobranças' => 'cobrancas'
        ];
        $tabela_sql = $tabela_map[$tabela] ?? $tabela;
        
        $sql = "SELECT * FROM $tabela_sql WHERE ativo = 1 ORDER BY id DESC LIMIT 10";
        $result = $conexao->query($sql);
        
        if ($result && $result->num_rows > 0) {
            $resposta = "📋 **Últimos 10 {$tabela}:**\n\n";
            while ($row = $result->fetch_assoc()) {
                if ($tabela == 'clientes') {
                    $resposta .= "• {$row['nome']} - {$row['telefone']}\n";
                } elseif ($tabela == 'produtos') {
                    $resposta .= "• {$row['nome']} - R$ " . number_format($row['valor_venda'], 2, ',', '.') . " (Estoque: {$row['estoque_atual']})\n";
                } elseif ($tabela == 'orçamentos') {
                    $resposta .= "• #{$row['numero']} - R$ " . number_format($row['valor_total'], 2, ',', '.') . " - {$row['situacao']}\n";
                } else {
                    $resposta .= "• ID {$row['id']}\n";
                }
            }
        } else {
            $resposta = "📋 Nenhum {$tabela} encontrado.";
        }
        $acao_executada = true;
    }
    
    // ===== 13. DASHBOARD RÁPIDO =====
    elseif (strpos($comando_lower, 'dashboard') !== false || strpos($comando_lower, 'resumo') !== false) {
        $stats = [];
        
        $sql_clientes = "SELECT COUNT(*) as total FROM clientes WHERE ativo = 1";
        $stats['clientes'] = $conexao->query($sql_clientes)->fetch_assoc()['total'];
        
        $sql_orc = "SELECT COUNT(*) as total FROM orcamentos WHERE situacao = 'pendente'";
        $stats['orcamentos'] = $conexao->query($sql_orc)->fetch_assoc()['total'];
        
        $sql_cob = "SELECT COUNT(*) as total, COALESCE(SUM(valor), 0) as valor FROM cobrancas WHERE status = 'pendente'";
        $cob = $conexao->query($sql_cob)->fetch_assoc();
        $stats['cobrancas'] = $cob['total'];
        $stats['valor_pendente'] = $cob['valor'];
        
        $sql_agend = "SELECT COUNT(*) as total FROM agendamentos WHERE data_agendamento = CURDATE()";
        $stats['agendamentos'] = $conexao->query($sql_agend)->fetch_assoc()['total'];
        
        $sql_fin = "SELECT COALESCE(SUM(CASE WHEN tipo = 'entrada' THEN valor ELSE 0 END), 0) as entradas,
                           COALESCE(SUM(CASE WHEN tipo = 'saida' THEN valor ELSE 0 END), 0) as saidas
                    FROM financeiro WHERE MONTH(data_transacao) = MONTH(CURDATE())";
        $fin = $conexao->query($sql_fin)->fetch_assoc();
        $stats['saldo'] = $fin['entradas'] - $fin['saidas'];
        
        $resposta = "📊 **DASHBOARD IMPÉRIO AR** 📊\n\n";
        $resposta .= "👥 **Clientes:** {$stats['clientes']} ativos\n";
        $resposta .= "📄 **Orçamentos pendentes:** {$stats['orcamentos']}\n";
        $resposta .= "💰 **Cobranças pendentes:** {$stats['cobrancas']} (R$ " . number_format($stats['valor_pendente'], 2, ',', '.') . ")\n";
        $resposta .= "📅 **Agendamentos hoje:** {$stats['agendamentos']}\n";
        $resposta .= "💵 **Saldo do mês:** R$ " . number_format($stats['saldo'], 2, ',', '.') . "\n\n";
        $resposta .= "💡 *Digite 'ajuda' para ver o que posso fazer*";
        $acao_executada = true;
    }
    
    // ===== 14. AJUDA =====
    elseif (strpos($comando_lower, 'ajuda') !== false || strpos($comando_lower, 'comandos') !== false) {
        $resposta = "🤖 **COMANDOS QUE POSSO EXECUTAR**\n\n";
        $resposta .= "👥 **Clientes:**\n";
        $resposta .= "• 'criar cliente João Silva'\n";
        $resposta .= "• 'adicionar telefone 11999999999 ao cliente João'\n";
        $resposta .= "• 'listar clientes'\n\n";
        $resposta .= "📄 **Orçamentos:**\n";
        $resposta .= "• 'criar orçamento para João no valor de R$ 1500'\n";
        $resposta .= "• 'adicionar produto Ar Condicionado ao orçamento 123'\n";
        $resposta .= "• 'aprovar orçamento 123'\n";
        $resposta .= "• 'concluir orçamento 123' (cria venda e cobrança)\n";
        $resposta .= "• 'listar orçamentos'\n\n";
        $resposta .= "💰 **Financeiro:**\n";
        $resposta .= "• 'registrar pagamento do cliente João no valor de R$ 500'\n";
        $resposta .= "• 'listar cobranças'\n\n";
        $resposta .= "📦 **Produtos:**\n";
        $resposta .= "• 'criar produto Ventilador com valor de R$ 200'\n";
        $resposta .= "• 'adicionar 10 unidades de estoque ao produto Ventilador'\n";
        $resposta .= "• 'listar produtos'\n\n";
        $resposta .= "📅 **Agendamentos:**\n";
        $resposta .= "• 'agendar serviço para João no dia 25/12/2024'\n";
        $resposta .= "• 'marcar agendamento como confirmado'\n\n";
        $resposta .= "📊 **Relatórios:**\n";
        $resposta .= "• 'dashboard' ou 'resumo'\n";
        $resposta .= "• 'listar vendas'\n\n";
        $resposta .= "💬 *Fale ou digite o que precisa fazer!*";
        $acao_executada = true;
    }
    
    // Se nenhuma ação foi identificada
    if (!$acao_executada) {
        $resposta = "🤔 Não entendi o que você deseja fazer.\n\n";
        $resposta .= "💡 **Exemplos de comandos:**\n";
        $resposta .= "• 'criar cliente João Silva'\n";
        $resposta .= "• 'criar orçamento para João no valor de R$ 1500'\n";
        $resposta .= "• 'aprovar orçamento 123'\n";
        $resposta .= "• 'dashboard'\n";
        $resposta .= "• 'ajuda' para ver todos os comandos";
    }
    
    return [
        'resposta' => $resposta,
        'acao_executada' => $acao_executada,
        'dados' => $dados_acao ?? []
    ];
}

// Processar comando via POST (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $comando = $_POST['comando'] ?? '';
    $resultado = executarAcao($comando, $conexao);
    
    echo json_encode($resultado);
    exit;
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IA Assistente - Controle Total</title>
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
        .sidebar { width: 300px; background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%); color: white; padding: 20px; position: fixed; height: 100vh; z-index: 1000; overflow-y: auto; }
        .main-content { flex: 1; margin-left: 300px; padding: 30px; overflow-y: auto; }
        
        .chat-container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            overflow: hidden;
            height: calc(100vh - 100px);
            display: flex;
            flex-direction: column;
        }
        
        .chat-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 20px;
            text-align: center;
        }
        
        .chat-header h1 {
            font-size: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .chat-header p {
            opacity: 0.8;
            font-size: 14px;
            margin-top: 5px;
        }
        
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            background: #f8f9fa;
        }
        
        .message {
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        
        .message.user {
            flex-direction: row-reverse;
        }
        
        .message.user .message-content {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border-radius: 20px 20px 5px 20px;
        }
        
        .message.bot .message-content {
            background: white;
            color: #333;
            border-radius: 20px 20px 20px 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            max-width: 85%;
        }
        
        .message-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }
        
        .message.user .message-avatar {
            background: var(--primary);
            color: white;
        }
        
        .message.bot .message-avatar {
            background: #6f42c1;
            color: white;
        }
        
        .message-content {
            padding: 12px 18px;
            white-space: pre-wrap;
            word-wrap: break-word;
            line-height: 1.5;
        }
        
        .chat-input {
            padding: 20px;
            background: white;
            border-top: 1px solid #e0e0e0;
            display: flex;
            gap: 10px;
        }
        
        .chat-input input {
            flex: 1;
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 30px;
            font-size: 16px;
            outline: none;
            transition: all 0.3s;
        }
        
        .chat-input input:focus {
            border-color: var(--primary);
        }
        
        .chat-input button {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            border: none;
            cursor: pointer;
            font-size: 20px;
            transition: all 0.3s;
        }
        
        .btn-send {
            background: var(--primary);
            color: white;
        }
        
        .btn-send:hover {
            background: var(--secondary);
            transform: scale(1.05);
        }
        
        .btn-mic {
            background: #6f42c1;
            color: white;
        }
        
        .btn-mic:hover {
            background: #5a32a3;
            transform: scale(1.05);
        }
        
        .btn-mic.recording {
            background: var(--danger);
            animation: pulse 1.5s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        
        .suggestion-chips {
            padding: 10px 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            background: #f8f9fa;
            border-top: 1px solid #e0e0e0;
        }
        
        .chip {
            background: #e9ecef;
            padding: 8px 16px;
            border-radius: 25px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .chip:hover {
            background: var(--primary);
            color: white;
        }
        
        .action-success {
            color: var(--success);
            font-weight: bold;
        }
        
        @media (max-width: 768px) {
            .sidebar { width: 100%; height: auto; position: relative; }
            .main-content { margin-left: 0; }
            .message-content { max-width: 85%; }
            .chat-container { height: calc(100vh - 200px); }
            .chip { font-size: 10px; padding: 5px 10px; }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="chat-container">
                <div class="chat-header">
                    <h1>
                        <i class="fas fa-robot"></i>
                        IA Assistente - Controle Total
                    </h1>
                    <p><i class="fas fa-microphone"></i> Fale ou digite | <i class="fas fa-magic"></i> Eu executo ações no sistema</p>
                </div>
                
                <div class="chat-messages" id="chat-messages">
                    <div class="message bot">
                        <div class="message-avatar">
                            <i class="fas fa-robot"></i>
                        </div>
                        <div class="message-content">
                            Olá! 👋 Sou seu assistente com poderes de execução.<br><br>
                            <strong>Posso FAZER coisas por você:</strong><br><br>
                            ✅ Criar clientes, produtos e orçamentos<br>
                            ✅ Aprovar e concluir orçamentos (gerar vendas/cobranças)<br>
                            ✅ Registrar pagamentos e atualizar estoque<br>
                            ✅ Agendar serviços e alterar status<br>
                            ✅ Gerar relatórios e dashboards<br><br>
                            <strong>Digite "ajuda" para ver todos os comandos disponíveis!</strong>
                        </div>
                    </div>
                </div>
                
                <div class="suggestion-chips">
                    <span class="chip" onclick="enviarComando('ajuda')"><i class="fas fa-question-circle"></i> Ajuda</span>
                    <span class="chip" onclick="enviarComando('dashboard')"><i class="fas fa-chart-pie"></i> Dashboard</span>
                    <span class="chip" onclick="enviarComando('criar cliente João Silva')"><i class="fas fa-user-plus"></i> Criar Cliente</span>
                    <span class="chip" onclick="enviarComando('criar orçamento para João no valor de R$ 1500')"><i class="fas fa-file-invoice"></i> Criar Orçamento</span>
                    <span class="chip" onclick="enviarComando('listar clientes')"><i class="fas fa-list"></i> Listar</span>
                </div>
                
                <div class="chat-input">
                    <button id="btnMic" class="btn-mic" title="Falar com IA">
                        <i class="fas fa-microphone"></i>
                    </button>
                    <input type="text" id="comandoInput" placeholder="Ex: criar cliente João Silva..." onkeypress="if(event.key==='Enter') enviarComando()">
                    <button id="btnSend" class="btn-send" onclick="enviarComando()">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </div>
        </main>
    </div>

    <script>
        const chatMessages = document.getElementById('chat-messages');
        const comandoInput = document.getElementById('comandoInput');
        
        function adicionarMensagem(texto, isUser = true) {
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${isUser ? 'user' : 'bot'}`;
            
            const avatar = document.createElement('div');
            avatar.className = 'message-avatar';
            avatar.innerHTML = isUser ? '<i class="fas fa-user"></i>' : '<i class="fas fa-robot"></i>';
            
            const content = document.createElement('div');
            content.className = 'message-content';
            content.innerHTML = texto.replace(/\n/g, '<br>').replace(/\✅/g, '<span style="color:#28a745;">✅</span>').replace(/\❌/g, '<span style="color:#dc3545;">❌</span>');
            
            messageDiv.appendChild(avatar);
            messageDiv.appendChild(content);
            
            chatMessages.appendChild(messageDiv);
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }
        
        function mostrarTyping() {
            const typingDiv = document.createElement('div');
            typingDiv.className = 'message bot';
            typingDiv.id = 'typing-indicator';
            
            const avatar = document.createElement('div');
            avatar.className = 'message-avatar';
            avatar.innerHTML = '<i class="fas fa-robot"></i>';
            
            const content = document.createElement('div');
            content.className = 'message-content';
            content.innerHTML = '<div style="display:flex; gap:5px; padding:5px;"><span style="width:8px; height:8px; background:#999; border-radius:50%; animation: pulse 1.4s infinite;"></span><span style="width:8px; height:8px; background:#999; border-radius:50%; animation: pulse 1.4s infinite 0.2s;"></span><span style="width:8px; height:8px; background:#999; border-radius:50%; animation: pulse 1.4s infinite 0.4s;"></span></div>';
            
            typingDiv.appendChild(avatar);
            typingDiv.appendChild(content);
            
            chatMessages.appendChild(typingDiv);
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }
        
        function removerTyping() {
            const typing = document.getElementById('typing-indicator');
            if (typing) typing.remove();
        }
        
        async function enviarComando(comando = null) {
            const texto = comando || comandoInput.value.trim();
            if (!texto) return;
            
            comandoInput.value = '';
            adicionarMensagem(texto, true);
            mostrarTyping();
            
            try {
                const formData = new FormData();
                formData.append('comando', texto);
                
                const response = await fetch('ia_assistente.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                removerTyping();
                adicionarMensagem(data.resposta, false);
                
                // Se a ação foi executada com sucesso, fazer refresh visual
                if (data.acao_executada) {
                    // Pequeno delay para o usuário ver a resposta
                    setTimeout(() => {
                        // Opcional: atualizar algum contador na sidebar
                    }, 500);
                }
            } catch (error) {
                removerTyping();
                adicionarMensagem("❌ Desculpe, tive um problema ao processar seu comando. Tente novamente.", false);
                console.error('Erro:', error);
            }
        }
        
        // Reconhecimento de voz
        const btnMic = document.getElementById('btnMic');
        let recognition = null;
        
        if ('webkitSpeechRecognition' in window || 'SpeechRecognition' in window) {
            const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            recognition = new SpeechRecognition();
            recognition.lang = 'pt-BR';
            recognition.continuous = false;
            recognition.interimResults = false;
            
            recognition.onstart = function() {
                btnMic.classList.add('recording');
                btnMic.innerHTML = '<i class="fas fa-microphone-slash"></i>';
            };
            
            recognition.onend = function() {
                btnMic.classList.remove('recording');
                btnMic.innerHTML = '<i class="fas fa-microphone"></i>';
            };
            
            recognition.onresult = function(event) {
                const texto = event.results[0][0].transcript;
                comandoInput.value = texto;
                enviarComando(texto);
            };
            
            recognition.onerror = function(event) {
                console.error('Erro:', event.error);
                btnMic.classList.remove('recording');
                btnMic.innerHTML = '<i class="fas fa-microphone"></i>';
                if (event.error !== 'no-speech') {
                    adicionarMensagem("🎤 Não consegui entender. Tente falar novamente.", false);
                }
            };
            
            btnMic.addEventListener('click', function() {
                if (btnMic.classList.contains('recording')) {
                    recognition.stop();
                } else {
                    recognition.start();
                }
            });
        } else {
            btnMic.style.display = 'none';
        }
    </script>
</body>
</html>