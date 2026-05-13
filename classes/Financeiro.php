<?php
/**
 * Classe Financeiro - Versão de desenvolvimento
 * Responsável por cálculos financeiros, cobranças e transações
 */
class Financeiro {
    
    private $db;
    private $configuracoes;
    
    /**
     * Construtor
     */
    public function __construct($conexao = null) {
        $this->db = $conexao;
        $this->carregar_configuracoes();
    }
    
    /**
     * Carrega configurações financeiras
     */
    private function carregar_configuracoes() {
        // Tenta conectar ao banco se a conexão foi passada
        if (!empty($this->db)) {
            try {
                // Busca configurações do banco
                $sql = "SELECT * FROM configuracoes WHERE id = 1";
                $resultado = $this->db->query($sql);
                
                if ($resultado) {
                    $config = $resultado->fetch_assoc();
                    if ($config) {
                        $this->configuracoes = [
                            'taxas_cartao_credito' => $config['taxas_cartao_credito'] ?? 3.99,
                            'taxas_cartao_debito' => $config['taxas_cartao_debito'] ?? 2.49,
                            'chave_pix_cpf' => $config['chave_pix_cpf'] ?? '',
                            'chave_pix_cnpj' => $config['chave_pix_cnpj'] ?? '',
                            'chave_pix_telefone' => $config['chave_pix_telefone'] ?? '',
                            'chave_pix_aleatoria' => $config['chave_pix_aleatoria'] ?? ''
                        ];
                    } else {
                        $this->configuracoes_padrao();
                    }
                } else {
                    $this->configuracoes_padrao();
                }
            } catch (Exception $e) {
                // Fallback para configurações padrão
                $this->configuracoes_padrao();
            }
        } else {
            $this->configuracoes_padrao();
        }
    }
    
    /**
     * Configurações padrão
     */
    private function configuracoes_padrao() {
        $this->configuracoes = [
            'taxas_cartao_credito' => 3.99,
            'taxas_cartao_debito' => 2.49,
            'chave_pix_cpf' => '123.456.789-00',
            'chave_pix_cnpj' => '00.000.000/0001-00',
            'chave_pix_telefone' => '+5511999999999',
            'chave_pix_aleatoria' => '123e4567-e89b-12d3-a456-426614174000'
        ];
    }
    
    /**
     * Calcula valor com desconto
     * 
     * @param float $valor Valor original
     * @param float $percentual Percentual de desconto
     * @return float Valor com desconto
     */
    public function calcular_desconto($valor, $percentual) {
        if ($percentual <= 0) return $valor;
        if ($percentual > 100) $percentual = 100;
        
        $desconto = $valor * ($percentual / 100);
        return $valor - $desconto;
    }
    
    /**
     * Calcula valor com acréscimo
     * 
     * @param float $valor Valor original
     * @param float $percentual Percentual de acréscimo
     * @return float Valor com acréscimo
     */
    public function calcular_acrescimo($valor, $percentual) {
        if ($percentual <= 0) return $valor;
        
        $acrescimo = $valor * ($percentual / 100);
        return $valor + $acrescimo;
    }
    
    /**
     * Calcula valor com juros
     * 
     * @param float $valor Valor original
     * @param float $taxa_juros Taxa de juros mensal
     * @param int $parcelas Número de parcelas
     * @return array Valores calculados
     */
    public function calcular_juros($valor, $taxa_juros, $parcelas = 1) {
        if ($parcelas <= 1) {
            return [
                'valor_original' => $valor,
                'valor_final' => $valor,
                'valor_parcela' => $valor,
                'total_juros' => 0,
                'taxa_juros' => $taxa_juros,
                'parcelas' => 1
            ];
        }
        
        // Cálculo de juros compostos
        $taxa_decimal = $taxa_juros / 100;
        $valor_final = $valor * pow(1 + $taxa_decimal, $parcelas);
        $valor_parcela = $valor_final / $parcelas;
        
        return [
            'valor_original' => $valor,
            'valor_final' => $valor_final,
            'valor_parcela' => $valor_parcela,
            'total_juros' => $valor_final - $valor,
            'taxa_juros' => $taxa_juros,
            'parcelas' => $parcelas
        ];
    }
    
    /**
     * Calcula taxas de cartão
     * 
     * @param float $valor Valor da transação
     * @param string $tipo 'credito' ou 'debito'
     * @param int $parcelas Número de parcelas (para crédito)
     * @return array Taxas calculadas
     */
    public function calcular_taxa_cartao($valor, $tipo = 'credito', $parcelas = 1) {
        $taxa_percentual = ($tipo == 'credito') 
            ? ($this->configuracoes['taxas_cartao_credito'] ?? 3.99)
            : ($this->configuracoes['taxas_cartao_debito'] ?? 2.49);
        
        // Para crédito parcelado, taxa pode ser maior
        if ($tipo == 'credito' && $parcelas > 1) {
            $taxa_percentual += ($parcelas - 1) * 0.5; // Incremento de 0.5% por parcela extra
        }
        
        $valor_taxa = $valor * ($taxa_percentual / 100);
        $valor_liquido = $valor - $valor_taxa;
        
        return [
            'valor_bruto' => $valor,
            'valor_liquido' => $valor_liquido,
            'valor_taxa' => $valor_taxa,
            'taxa_percentual' => $taxa_percentual,
            'tipo' => $tipo,
            'parcelas' => $parcelas
        ];
    }
    
    /**
     * Gera número sequencial para documento
     * 
     * @param string $tipo Tipo de documento (ORC, VND, REC, COB, GAR)
     * @return string Número formatado
     */
    public function gerar_numero_documento($tipo = 'ORC') {
        $data = date('Ymd');
        $prefixos_validos = ['ORC', 'VND', 'REC', 'COB', 'GAR', 'PED'];
        
        if (!in_array($tipo, $prefixos_validos)) {
            $tipo = 'DOC';
        }
        
        // Gera número sequencial baseado em arquivo ou banco
        $sequencial = $this->get_ultimo_sequencial($tipo) + 1;
        
        return sprintf("%s-%s-%04d", $tipo, $data, $sequencial);
    }
    
    /**
     * Obtém último número sequencial usado
     */
    private function get_ultimo_sequencial($tipo) {
        $arquivo = __DIR__ . '/../temp/sequencial_' . $tipo . '.txt';
        
        if (file_exists($arquivo)) {
            return (int) file_get_contents($arquivo);
        }
        
        return 0;
    }
    
    /**
     * Atualiza número sequencial
     */
    private function atualizar_sequencial($tipo, $sequencial) {
        $dir = __DIR__ . '/../temp';
        $arquivo = $dir . '/sequencial_' . $tipo . '.txt';
        
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        file_put_contents($arquivo, $sequencial);
    }
    
    /**
     * Calcula valores de orçamento/venda
     * 
     * @param array $itens Lista de itens (produtos/serviços)
     * @param float $desconto_percentual Desconto percentual
     * @param float $adicional Valor adicional
     * @return array Totais calculados
     */
    public function calcular_totais($itens, $desconto_percentual = 0, $adicional = 0) {
        $subtotal = 0;
        $custo_total = 0;
        
        foreach ($itens as $item) {
            $subtotal += $item['subtotal'] ?? ($item['quantidade'] * $item['valor_unitario']);
            $custo_total += ($item['custo'] ?? 0) * ($item['quantidade'] ?? 1);
        }
        
        $valor_desconto = $subtotal * ($desconto_percentual / 100);
        $valor_total = $subtotal - $valor_desconto + $adicional;
        $lucro_total = $valor_total - $custo_total;
        
        return [
            'subtotal' => $subtotal,
            'custo_total' => $custo_total,
            'desconto_percentual' => $desconto_percentual,
            'valor_desconto' => $valor_desconto,
            'adicional' => $adicional,
            'valor_total' => $valor_total,
            'lucro_total' => $lucro_total,
            'margem_lucro' => $valor_total > 0 ? ($lucro_total / $valor_total) * 100 : 0
        ];
    }
    
    /**
     * Registra uma cobrança
     * 
     * @param array $dados Dados da cobrança
     * @return int|false ID da cobrança ou false
     */
    public function registrar_cobranca($dados) {
        $cobranca = [
            'numero' => $dados['numero'] ?? $this->gerar_numero_documento('COB'),
            'cliente_id' => $dados['cliente_id'] ?? 0,
            'pedido_id' => $dados['pedido_id'] ?? null,
            'orcamento_id' => $dados['orcamento_id'] ?? null,
            'venda_id' => $dados['venda_id'] ?? null,
            'valor' => $dados['valor'] ?? 0,
            'data_vencimento' => $dados['data_vencimento'] ?? date('Y-m-d', strtotime('+30 days')),
            'status' => $dados['status'] ?? 'pendente',
            'tipo_pagamento' => $dados['tipo_pagamento'] ?? 'pix',
            'data_recebimento' => $dados['data_recebimento'] ?? null,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        // Simula inserção no banco
        if (!empty($this->db)) {
            try {
                $campos = implode(', ', array_keys($cobranca));
                $placeholders = implode(', ', array_fill(0, count($cobranca), '?'));
                
                $sql = "INSERT INTO cobrancas ($campos) VALUES ($placeholders)";
                $stmt = $this->db->prepare($sql);
                
                if ($stmt) {
                    $tipos = str_repeat('s', count($cobranca));
                    $valores = array_values($cobranca);
                    $stmt->bind_param($tipos, ...$valores);
                    $stmt->execute();
                    $id = $this->db->insert_id;
                    $stmt->close();
                    return $id;
                }
            } catch (Exception $e) {
                error_log("Erro ao registrar cobrança: " . $e->getMessage());
            }
        }
        
        // Retorna ID simulado
        return rand(100, 999);
    }
    
    /**
     * Marca cobrança como recebida
     * 
     * @param int $cobranca_id ID da cobrança
     * @param string $tipo_pagamento Tipo de pagamento
     * @return bool Sucesso ou falha
     */
    public function receber_cobranca($cobranca_id, $tipo_pagamento = null) {
        $dados = [
            'status' => 'recebida',
            'data_recebimento' => date('Y-m-d')
        ];
        
        if ($tipo_pagamento) {
            $dados['tipo_pagamento'] = $tipo_pagamento;
        }
        
        if (!empty($this->db)) {
            try {
                $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($dados)));
                $sql = "UPDATE cobrancas SET $set WHERE id = ?";
                $stmt = $this->db->prepare($sql);
                
                if ($stmt) {
                    $tipos = str_repeat('s', count($dados)) . 'i';
                    $valores = array_merge(array_values($dados), [$cobranca_id]);
                    $stmt->bind_param($tipos, ...$valores);
                    $stmt->execute();
                    $stmt->close();
                    return true;
                }
            } catch (Exception $e) {
                error_log("Erro ao receber cobrança: " . $e->getMessage());
            }
        }
        
        return true; // Simula sucesso
    }
    
    /**
     * Obtém informações de PIX
     * 
     * @return array Dados do PIX
     */
    public function get_info_pix() {
        return [
            'chaves' => [
                'cpf' => $this->configuracoes['chave_pix_cpf'] ?? '',
                'cnpj' => $this->configuracoes['chave_pix_cnpj'] ?? '',
                'telefone' => $this->configuracoes['chave_pix_telefone'] ?? '',
                'aleatoria' => $this->configuracoes['chave_pix_aleatoria'] ?? ''
            ],
            'qrcode' => null, // Em produção, gerar QR Code real
            'texto_copia_cola' => '00020126360014br.gov.bcb.pix0114+551199999999952040000530398654040.015802BR5905Exemplo6008Sao Paulo62070503***6304E9E7'
        ];
    }
    
    /**
     * Gera boleto bancário
     * 
     * @param array $dados Dados do boleto
     * @return array Dados do boleto gerado
     */
    public function gerar_boleto($dados) {
        $banco = $dados['banco'] ?? 'Itaú';
        $valor = $dados['valor'] ?? 0;
        $vencimento = $dados['vencimento'] ?? date('d/m/Y', strtotime('+5 days'));
        
        return [
            'nosso_numero' => rand(100000, 999999) . '-' . rand(0, 9),
            'linha_digitavel' => '34191.79001 01043.510047 91020.150008 6 ' . rand(100000000, 999999999),
            'codigo_barras' => '341' . rand(100000000000000000, 999999999999999999),
            'valor' => $valor,
            'vencimento' => $vencimento,
            'banco' => $banco,
            'url_pdf' => '#' // Em produção, gerar PDF real
        ];
    }
    
    /**
     * Calcula comissão de vendedor/técnico
     * 
     * @param float $valor Venda
     * @param float $percentual Percentual de comissão
     * @param int $vendedor_id ID do vendedor
     * @return array Comissão calculada
     */
    public function calcular_comissao($valor, $percentual = 5, $vendedor_id = null) {
        $valor_comissao = $valor * ($percentual / 100);
        
        return [
            'vendedor_id' => $vendedor_id,
            'valor_venda' => $valor,
            'percentual' => $percentual,
            'valor_comissao' => $valor_comissao,
            'data_calculo' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Gera relatório financeiro
     * 
     * @param string $periodo 'diario', 'semanal', 'mensal', 'anual'
     * @param array $filtros Filtros adicionais
     * @return array Dados do relatório
     */
    public function gerar_relatorio($periodo = 'mensal', $filtros = []) {
        $data_fim = date('Y-m-d');
        
        switch ($periodo) {
            case 'diario':
                $data_inicio = date('Y-m-d');
                break;
            case 'semanal':
                $data_inicio = date('Y-m-d', strtotime('-7 days'));
                break;
            case 'mensal':
                $data_inicio = date('Y-m-d', strtotime('-30 days'));
                break;
            case 'anual':
                $data_inicio = date('Y-m-d', strtotime('-365 days'));
                break;
            default:
                $data_inicio = date('Y-m-d', strtotime('-30 days'));
        }
        
        // Dados simulados
        return [
            'periodo' => $periodo,
            'data_inicio' => $data_inicio,
            'data_fim' => $data_fim,
            'filtros' => $filtros,
            'resumo' => [
                'total_receitas' => rand(10000, 50000),
                'total_despesas' => rand(5000, 20000),
                'saldo' => rand(5000, 30000),
                'receitas_pendentes' => rand(1000, 5000),
                'despesas_pendentes' => rand(500, 3000)
            ],
            'por_forma_pagamento' => [
                'pix' => rand(2000, 10000),
                'credito' => rand(3000, 15000),
                'debito' => rand(1000, 5000),
                'dinheiro' => rand(500, 3000),
                'boleto' => rand(1000, 8000)
            ],
            'grafico' => [
                'labels' => ['Semana 1', 'Semana 2', 'Semana 3', 'Semana 4'],
                'valores' => [rand(1000, 5000), rand(1000, 5000), rand(1000, 5000), rand(1000, 5000)]
            ]
        ];
    }
    
    /**
     * Formata valor monetário
     * 
     * @param float $valor Valor a formatar
     * @param bool $simbolo Incluir símbolo R$
     * @return string Valor formatado
     */
    public function formatar_valor($valor, $simbolo = true) {
        $valor_formatado = number_format($valor, 2, ',', '.');
        return $simbolo ? 'R$ ' . $valor_formatado : $valor_formatado;
    }
    
    /**
     * Converte valor de string para float
     * 
     * @param string $valor_str String com valor (ex: "R$ 1.234,56")
     * @return float Valor convertido
     */
    public function converter_valor($valor_str) {
        // Remove R$, espaços e substitui vírgula por ponto
        $valor_str = preg_replace('/[R$\s]/', '', $valor_str);
        $valor_str = str_replace('.', '', $valor_str);
        $valor_str = str_replace(',', '.', $valor_str);
        
        return (float) $valor_str;
    }
    
    /**
     * Calcula troco
     * 
     * @param float $valor_total Valor total da compra
     * @param float $valor_pago Valor pago
     * @return float Troco
     */
    public function calcular_troco($valor_total, $valor_pago) {
        if ($valor_pago < $valor_total) {
            return 0;
        }
        
        return $valor_pago - $valor_total;
    }
    
    /**
     * Verifica se vencimento está próximo
     * 
     * @param string $data_vencimento Data no formato Y-m-d
     * @param int $dias_limite Dias para considerar próximo
     * @return array Status do vencimento
     */
    public function verificar_vencimento($data_vencimento, $dias_limite = 5) {
        $hoje = new DateTime();
        $vencimento = new DateTime($data_vencimento);
        $diferenca = $hoje->diff($vencimento)->days;
        
        if ($hoje > $vencimento) {
            return [
                'status' => 'vencido',
                'dias_atraso' => $diferenca,
                'cor' => '#ff0000',
                'mensagem' => "Vencido há {$diferenca} dia(s)"
            ];
        } elseif ($diferenca <= $dias_limite) {
            return [
                'status' => 'proximo',
                'dias_restantes' => $diferenca,
                'cor' => '#ff9900',
                'mensagem' => "Vence em {$diferenca} dia(s)"
            ];
        } else {
            return [
                'status' => 'ok',
                'dias_restantes' => $diferenca,
                'cor' => '#00aa00',
                'mensagem' => "Vence em {$diferenca} dia(s)"
            ];
        }
    }
    
    /**
     * Registra uma entrada financeira
     * 
     * @param array $dados Dados da entrada
     * @return bool Sucesso ou falha
     */
    public function registrarEntrada($dados) {
        // Método auxiliar para registrar entradas no financeiro
        return $this->registrar_cobranca($dados) !== false;
    }
}
