<?php
/**
 * =====================================================================
 * WORKFLOW - SISTEMA DE GESTÃO IMPÉRIO AR
 * =====================================================================
 * 
 * Responsabilidade: Gerenciar o fluxo de trabalho entre módulos
 * - Criar Pedido a partir de Orçamento aprovado
 * - Criar Venda e Cobrança a partir de Orçamento concluído
 * - Garantir integridade transacional
 * - VERSÃO FINAL - SEM DUPLICAÇÕES
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Financeiro.php';

class Workflow {
    
    private $conexao;
    private $financeiro;

    public function __construct($conexao) {
        $this->conexao = $conexao;
        $this->financeiro = new Financeiro($conexao);
    }

    /**
     * Cria um Pedido a partir de um Orçamento aprovado
     * @param int $orcamento_id ID do orçamento de origem
     * @return int|false ID do pedido criado ou false em caso de erro
     */
    public function criarPedidoDeOrcamento($orcamento_id) {
        // Busca dados completos do orçamento
        $orcamento = $this->buscarOrcamentoCompleto($orcamento_id);
        if (!$orcamento) {
            error_log("Workflow: Orçamento $orcamento_id não encontrado para criar pedido.");
            return false;
        }

        // Verifica se já existe pedido para este orçamento
        $check = $this->conexao->query("SELECT id FROM pedidos WHERE orcamento_origem_id = $orcamento_id LIMIT 1");
        if ($check->num_rows > 0) {
            error_log("Workflow: Pedido já existe para o orçamento $orcamento_id.");
            return false;
        }

        $this->conexao->begin_transaction();

        try {
            // 1. Criar o pedido principal
            $numero_pedido = 'PED-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $sql_pedido = "INSERT INTO pedidos 
                (numero, cliente_id, orcamento_origem_id, data_pedido, situacao, observacao, 
                 desconto_percentual, valor_adicional, valor_total, valor_custo, valor_lucro) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->conexao->prepare($sql_pedido);
            $situacao_pedido = 'pendente';
            $stmt->bind_param(
                "siisssddddd",
                $numero_pedido,
                $orcamento['cliente_id'],
                $orcamento_id,
                date('Y-m-d'),
                $situacao_pedido,
                $orcamento['observacao'],
                $orcamento['desconto_percentual'],
                $orcamento['valor_adicional'],
                $orcamento['valor_total'],
                $orcamento['valor_custo'],
                $orcamento['valor_lucro']
            );
            $stmt->execute();
            $novo_pedido_id = $this->conexao->insert_id;
            $stmt->close();

            // 2. Copiar produtos
            if (!empty($orcamento['itens_produtos'])) {
                $sql_item = "INSERT INTO pedido_produtos (pedido_id, produto_id, quantidade, valor_unitario, subtotal) 
                             VALUES (?, ?, ?, ?, ?)";
                $stmt_item = $this->conexao->prepare($sql_item);
                foreach ($orcamento['itens_produtos'] as $item) {
                    $stmt_item->bind_param("iiddd", $novo_pedido_id, $item['produto_id'], $item['quantidade'], $item['valor_unitario'], $item['subtotal']);
                    $stmt_item->execute();
                }
                $stmt_item->close();
            }

            // 3. Copiar serviços
            if (!empty($orcamento['itens_servicos'])) {
                $sql_item = "INSERT INTO pedido_servicos (pedido_id, servico_id, quantidade, valor_unitario, subtotal) 
                             VALUES (?, ?, ?, ?, ?)";
                $stmt_item = $this->conexao->prepare($sql_item);
                foreach ($orcamento['itens_servicos'] as $item) {
                    $stmt_item->bind_param("iiddd", $novo_pedido_id, $item['servico_id'], $item['quantidade'], $item['valor_unitario'], $item['subtotal']);
                    $stmt_item->execute();
                }
                $stmt_item->close();
            }

            $this->conexao->commit();
            error_log("Workflow: Pedido $novo_pedido_id criado com sucesso a partir do orçamento $orcamento_id.");
            return $novo_pedido_id;

        } catch (Exception $e) {
            $this->conexao->rollback();
            error_log("Workflow: Erro ao criar pedido do orçamento $orcamento_id: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Cria uma Venda e uma Cobrança a partir de um Orçamento concluído
     * VERSÃO FINAL - NUNCA CRIA DUPLICATAS
     *
     * @param int $orcamento_id ID do orçamento de origem
     * @return int|false ID da venda criada ou false em caso de erro
     */
    public function criarVendaDeOrcamento($orcamento_id) {
        $orcamento = $this->buscarOrcamentoCompleto($orcamento_id);
        if (!$orcamento) {
            error_log("Workflow: Orçamento $orcamento_id não encontrado.");
            return false;
        }

        // ===== VERIFICAÇÕES RIGOROSAS ANTES DE QUALQUER AÇÃO =====
        
        // 1. Já existe venda?
        $venda_existente_id = null;
        $check_venda = $this->conexao->query("SELECT id FROM vendas WHERE orcamento_origem_id = $orcamento_id");
        if ($check_venda && $check_venda->num_rows > 0) {
            $venda_existente = $check_venda->fetch_assoc();
            $venda_existente_id = $venda_existente['id'];
            error_log("Workflow: Venda $venda_existente_id já existe para orçamento $orcamento_id.");
        }
        
        // 2. Já existe cobrança?
        $cobranca_existente_id = null;
        $check_cob = $this->conexao->query("SELECT id FROM cobrancas WHERE orcamento_id = $orcamento_id");
        if ($check_cob && $check_cob->num_rows > 0) {
            $cobranca_existente = $check_cob->fetch_assoc();
            $cobranca_existente_id = $cobranca_existente['id'];
            error_log("Workflow: Cobrança $cobranca_existente_id já existe para orçamento $orcamento_id.");
        }

        // SE JÁ TEM TUDO, NÃO FAZ NADA
        if ($venda_existente_id && $cobranca_existente_id) {
            error_log("Workflow: Venda e cobrança já existem. Nada a fazer.");
            return $venda_existente_id;
        }

        $this->conexao->begin_transaction();

        try {
            // 3. Criar venda SOMENTE SE NÃO EXISTIR
            $nova_venda_id = $venda_existente_id;
            
            if (!$nova_venda_id) {
                $numero_venda = 'VND-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                $sql_venda = "INSERT INTO vendas 
                    (numero, cliente_id, orcamento_origem_id, data_venda, situacao, observacao, 
                     desconto_percentual, valor_adicional, valor_total, valor_custo, valor_lucro) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $this->conexao->prepare($sql_venda);
                $situacao_venda = 'finalizado';
                $observacao_venda = $orcamento['observacao'] . " (Gerado a partir do Orçamento #" . ($orcamento['numero'] ?? $orcamento_id) . ")";
                $stmt->bind_param(
                    "siisssddddd",
                    $numero_venda,
                    $orcamento['cliente_id'],
                    $orcamento_id,
                    date('Y-m-d'),
                    $situacao_venda,
                    $observacao_venda,
                    $orcamento['desconto_percentual'],
                    $orcamento['valor_adicional'],
                    $orcamento['valor_total'],
                    $orcamento['valor_custo'],
                    $orcamento['valor_lucro']
                );
                $stmt->execute();
                $nova_venda_id = $this->conexao->insert_id;
                $stmt->close();
                error_log("Workflow: Nova venda $nova_venda_id criada.");
            }

            // 4. Criar cobrança SOMENTE SE NÃO EXISTIR
            if (!$cobranca_existente_id) {
                $numero_cobranca = 'COB-' . date('Ymd') . '-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
                $data_vencimento = date('Y-m-d', strtotime('+7 days'));
                
                $sql_cobranca = "INSERT INTO cobrancas 
                    (numero, cliente_id, orcamento_id, venda_id, valor, data_vencimento, status, tipo_pagamento, observacao) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt_cob = $this->conexao->prepare($sql_cobranca);
                $status_cob = 'pendente';
                $tipo_pagamento = 'pix';
                $observacao_cob = "Cobrança gerada automaticamente a partir do Orçamento #" . ($orcamento['numero'] ?? $orcamento_id);
                
                $stmt_cob->bind_param(
                    "siiidssss",
                    $numero_cobranca,
                    $orcamento['cliente_id'],
                    $orcamento_id,
                    $nova_venda_id,
                    $orcamento['valor_total'],
                    $data_vencimento,
                    $status_cob,
                    $tipo_pagamento,
                    $observacao_cob
                );
                $stmt_cob->execute();
                $stmt_cob->close();
                error_log("Workflow: Nova cobrança criada para venda $nova_venda_id.");
            }

            // 5. Registrar no financeiro (só se criou venda nova)
            if (!$venda_existente_id) {
                $this->financeiro->registrarEntrada([
                    'valor' => $orcamento['valor_total'],
                    'descricao' => "Venda #$nova_venda_id - Orçamento #" . ($orcamento['numero'] ?? $orcamento_id),
                    'cliente_id' => $orcamento['cliente_id'],
                    'data' => date('Y-m-d')
                ]);
            }

            $this->conexao->commit();
            error_log("Workflow: Processo concluído para orçamento $orcamento_id. Venda ID: $nova_venda_id");
            return $nova_venda_id;

        } catch (Exception $e) {
            $this->conexao->rollback();
            error_log("Workflow: Erro: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Busca orçamento completo com todos os itens
     * @param int $orcamento_id
     * @return array|null
     */
    private function buscarOrcamentoCompleto($orcamento_id) {
        // Dados principais
        $sql = "SELECT o.*, c.nome as cliente_nome, c.whatsapp 
                FROM orcamentos o 
                LEFT JOIN clientes c ON o.cliente_id = c.id 
                WHERE o.id = ?";
        $stmt = $this->conexao->prepare($sql);
        $stmt->bind_param("i", $orcamento_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $orcamento = $result->fetch_assoc();
        $stmt->close();

        if (!$orcamento) {
            return null;
        }

        // Itens produtos
        $sql_prod = "SELECT op.*, p.nome, p.valor_venda 
                     FROM orcamento_produtos op 
                     JOIN produtos p ON op.produto_id = p.id 
                     WHERE op.orcamento_id = ?";
        $stmt_prod = $this->conexao->prepare($sql_prod);
        $stmt_prod->bind_param("i", $orcamento_id);
        $stmt_prod->execute();
        $orcamento['itens_produtos'] = $stmt_prod->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_prod->close();

        // Itens serviços
        $sql_serv = "SELECT os.*, s.nome, s.valor_unitario, s.tempo_execucao 
                     FROM orcamento_servicos os 
                     JOIN servicos s ON os.servico_id = s.id 
                     WHERE os.orcamento_id = ?";
        $stmt_serv = $this->conexao->prepare($sql_serv);
        $stmt_serv->bind_param("i", $orcamento_id);
        $stmt_serv->execute();
        $orcamento['itens_servicos'] = $stmt_serv->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_serv->close();

        return $orcamento;
    }

    /**
     * Verifica se um orçamento já tem pedido vinculado
     * @param int $orcamento_id
     * @return bool
     */
    public function temPedido($orcamento_id) {
        $result = $this->conexao->query("SELECT id FROM pedidos WHERE orcamento_origem_id = $orcamento_id LIMIT 1");
        return $result && $result->num_rows > 0;
    }

    /**
     * Verifica se um orçamento já tem venda vinculada
     * @param int $orcamento_id
     * @return bool
     */
    public function temVenda($orcamento_id) {
        $result = $this->conexao->query("SELECT id FROM vendas WHERE orcamento_origem_id = $orcamento_id LIMIT 1");
        return $result && $result->num_rows > 0;
    }

    /**
     * Verifica se um orçamento já tem cobrança vinculada
     * @param int $orcamento_id
     * @return bool
     */
    public function temCobranca($orcamento_id) {
        $result = $this->conexao->query("SELECT id FROM cobrancas WHERE orcamento_id = $orcamento_id LIMIT 1");
        return $result && $result->num_rows > 0;
    }
}
?>
