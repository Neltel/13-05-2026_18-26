<?php
/**
 * =====================================================================
 * CLASSE PDF - Geração de PDFs
 * =====================================================================
 * 
 * Responsabilidade: Gerar PDFs de orçamentos, recibos, garantias, relatórios
 * Uso: $pdf = new PDF(); $pdf->gerar_orcamento($orcamento_id);
 * Recebe: ID de documento e dados
 * Retorna: String com caminho do PDF ou stream direto
 * 
 * Operações:
 * - Gerar PDF de orçamento
 * - Gerar PDF de recibo
 * - Gerar PDF de garantia
 * - Gerar PDF de relatório técnico
 * - Salvar ou exibir no navegador
 * - Enviar por email
 */

class PDF {
    
    private $titulo = '';
    private $empresa_nome = '';
    private $empresa_logo = '';
    private $empresa_cnpj = '';
    private $empresa_endereco = '';
    private $empresa_telefone = '';
    private $empresa_whatsapp = '';
    
    /**
     * Construtor
     */
    public function __construct() {
        $this->carregar_configuracoes_empresa();
    }
    
    /**
     * Método PRIVADO: carregar_configuracoes_empresa()
     * Responsabilidade: Carregar dados da empresa do banco
     * Parâmetros: none
     * Retorna: void
     */
    private function carregar_configuracoes_empresa() {
        // Verifica se a classe Database existe
        if (!class_exists('Database')) {
            $this->configuracoes_padrao();
            return;
        }
        
        try {
            $db = Database::getInstance();
            
            // Busca primeira configuração (empresa)
            $config = $db->selectOne('configuracoes', ['id' => 1]);
            
            if ($config) {
                $this->empresa_nome = $config['nome_empresa'] ?? 'Império AR - Refrigeração';
                $this->empresa_logo = $config['logo_url'] ?? '';
                $this->empresa_cnpj = $config['cnpj'] ?? '00.000.000/0001-00';
                
                $endereco = '';
                if (!empty($config['endereco_rua'])) {
                    $endereco = $config['endereco_rua'];
                    if (!empty($config['endereco_numero'])) {
                        $endereco .= ', ' . $config['endereco_numero'];
                    }
                    if (!empty($config['endereco_bairro'])) {
                        $endereco .= ' - ' . $config['endereco_bairro'];
                    }
                    if (!empty($config['endereco_cidade']) && !empty($config['endereco_estado'])) {
                        $endereco .= ', ' . $config['endereco_cidade'] . '/' . $config['endereco_estado'];
                    }
                }
                $this->empresa_endereco = $endereco ?: 'Endereço não informado';
                $this->empresa_telefone = $config['telefone'] ?? '(11) 3333-4444';
                $this->empresa_whatsapp = $config['whatsapp'] ?? '(11) 99999-8888';
            }
        } catch (Exception $e) {
            $this->configuracoes_padrao();
        }
    }
    
    /**
     * Configurações padrão
     */
    private function configuracoes_padrao() {
        $this->empresa_nome = 'Império AR - Refrigeração';
        $this->empresa_logo = '';
        $this->empresa_cnpj = '00.000.000/0001-00';
        $this->empresa_endereco = 'Endereço não informado';
        $this->empresa_telefone = '(11) 3333-4444';
        $this->empresa_whatsapp = '(11) 99999-8888';
    }
    
    /**
     * Método: gerar_orcamento()
     * Responsabilidade: Gerar PDF de orçamento
     * Parâmetros:
     *   $orcamento_id - int com ID do orçamento
     *   $salvar - bool para salvar em arquivo ou exibir (padrão: false = exibir)
     * Retorna: string com caminho do arquivo ou false
     * 
     * Uso:
     *   $pdf->gerar_orcamento(123, false); // Exibe no navegador
     *   $arquivo = $pdf->gerar_orcamento(123, true); // Salva e retorna caminho
     */
    public function gerar_orcamento($orcamento_id, $salvar = false) {
        if (empty($orcamento_id)) {
            return false;
        }
        
        return $this->gerar_orcamento_exemplo($salvar);
    }
    
    /**
     * Método PRIVADO: gerar_orcamento_exemplo()
     * Responsabilidade: Gerar orçamento de exemplo para teste
     * Parâmetros: $salvar - bool
     * Retorna: string ou bool
     */
    private function gerar_orcamento_exemplo($salvar = false) {
        $orcamento = [
            'numero' => 'ORC-20260215-0001',
            'data_emissao' => date('Y-m-d'),
            'data_validade' => date('Y-m-d', strtotime('+30 days')),
            'cliente_id' => 1,
            'valor_total' => 2500.00,
            'valor_custo' => 1500.00,
            'valor_lucro' => 1000.00,
            'desconto_percentual' => 0,
            'valor_adicional' => 0,
            'observacao' => 'Orçamento gerado para teste do sistema.',
            'produtos' => [
                [
                    'nome' => 'Ar Condicionado 12000 BTUs',
                    'quantidade' => 1,
                    'valor_unitario' => 1800.00,
                    'subtotal' => 1800.00
                ],
                [
                    'nome' => 'Kit Instalação',
                    'quantidade' => 1,
                    'valor_unitario' => 200.00,
                    'subtotal' => 200.00
                ]
            ],
            'servicos' => [
                [
                    'nome' => 'Instalação de Ar Condicionado',
                    'quantidade' => 1,
                    'valor_unitario' => 500.00,
                    'subtotal' => 500.00
                ]
            ]
        ];
        
        $cliente = [
            'nome' => 'Cliente Exemplo',
            'cpf_cnpj' => '123.456.789-00',
            'endereco_rua' => 'Rua Exemplo',
            'endereco_numero' => '123',
            'endereco_bairro' => 'Centro',
            'endereco_cidade' => 'São Paulo',
            'endereco_estado' => 'SP',
            'whatsapp' => '(11) 99999-9999'
        ];
        
        $html = $this->montar_html_orcamento($orcamento, $cliente);
        
        if ($salvar) {
            return $this->salvar_pdf($html, 'orcamento_exemplo');
        } else {
            $this->exibir_pdf($html, 'orcamento_exemplo');
            return true;
        }
    }
    
    /**
     * Método PRIVADO: montar_html_orcamento()
     * Responsabilidade: Montar HTML estruturado para PDF de orçamento
     * Parâmetros: $orcamento, $cliente
     * Retorna: string com HTML
     */
    private function montar_html_orcamento($orcamento, $cliente) {
        $html = $this->obter_cabecalho();
        
        $produtos = isset($orcamento['produtos']) ? $orcamento['produtos'] : [];
        $servicos = isset($orcamento['servicos']) ? $orcamento['servicos'] : [];
        
        $html .= "
        <h1>ORÇAMENTO</h1>
        
        <table class='info-document' style='width: 100%; margin-bottom: 20px;'>
            <tr>
                <td style='width: 50%;'><strong>Número:</strong> " . ($orcamento['numero'] ?? 'N/D') . "</td>
                <td style='width: 50%;'><strong>Data:</strong> " . (isset($orcamento['data_emissao']) ? date('d/m/Y', strtotime($orcamento['data_emissao'])) : date('d/m/Y')) . "</td>
            </tr>
            <tr>
                <td colspan='2'><strong>Válido até:</strong> " . (isset($orcamento['data_validade']) ? date('d/m/Y', strtotime($orcamento['data_validade'])) : date('d/m/Y', strtotime('+30 days'))) . "</td>
            </tr>
        </table>
        
        <h2>Dados do Cliente</h2>
        <table class='info-cliente' style='width: 100%; margin-bottom: 20px;'>
            <tr>
                <td><strong>Nome/Empresa:</strong> " . ($cliente['nome'] ?? 'Cliente não informado') . "</td>
            </tr>
            <tr>
                <td><strong>CPF/CNPJ:</strong> " . ($cliente['cpf_cnpj'] ?? '') . "</td>
            </tr>
            <tr>
                <td><strong>Endereço:</strong> " . (($cliente['endereco_rua'] ?? '') . ', ' . ($cliente['endereco_numero'] ?? '') . ' - ' . ($cliente['endereco_bairro'] ?? '')) . "</td>
            </tr>
            <tr>
                <td><strong>Cidade/Estado:</strong> " . ($cliente['endereco_cidade'] ?? '') . "/" . ($cliente['endereco_estado'] ?? '') . "</td>
            </tr>
            <tr>
                <td><strong>Telefone/WhatsApp:</strong> " . ($cliente['whatsapp'] ?? '') . "</td>
            </tr>
        </table>
        
        <h2>Itens do Orçamento</h2>
        <table class='items-table' style='width: 100%; border-collapse: collapse; margin-bottom: 20px;'>
            <thead>
                <tr style='background-color: #333; color: white;'>
                    <th style='padding: 8px; border: 1px solid #ddd; text-align: left;'>Descrição</th>
                    <th style='padding: 8px; border: 1px solid #ddd; text-align: center;'>Quantidade</th>
                    <th style='padding: 8px; border: 1px solid #ddd; text-align: right;'>Valor Unit.</th>
                    <th style='padding: 8px; border: 1px solid #ddd; text-align: right;'>Total</th>
                </tr>
            </thead>
            <tbody>";
        
        foreach ($produtos as $produto) {
            $html .= "
                <tr>
                    <td style='padding: 8px; border: 1px solid #ddd;'>" . ($produto['nome'] ?? 'Produto') . "</td>
                    <td style='padding: 8px; border: 1px solid #ddd; text-align: center;'>" . ($produto['quantidade'] ?? 1) . "</td>
                    <td style='padding: 8px; border: 1px solid #ddd; text-align: right;'>R$ " . number_format($produto['valor_unitario'] ?? 0, 2, ',', '.') . "</td>
                    <td style='padding: 8px; border: 1px solid #ddd; text-align: right;'>R$ " . number_format($produto['subtotal'] ?? 0, 2, ',', '.') . "</td>
                </tr>";
        }
        
        foreach ($servicos as $servico) {
            $html .= "
                <tr>
                    <td style='padding: 8px; border: 1px solid #ddd;'>" . ($servico['nome'] ?? 'Serviço') . "</td>
                    <td style='padding: 8px; border: 1px solid #ddd; text-align: center;'>" . ($servico['quantidade'] ?? 1) . "</td>
                    <td style='padding: 8px; border: 1px solid #ddd; text-align: right;'>R$ " . number_format($servico['valor_unitario'] ?? 0, 2, ',', '.') . "</td>
                    <td style='padding: 8px; border: 1px solid #ddd; text-align: right;'>R$ " . number_format($servico['subtotal'] ?? 0, 2, ',', '.') . "</td>
                </tr>";
        }
        
        if (empty($produtos) && empty($servicos)) {
            $html .= "
                <tr>
                    <td colspan='4' style='padding: 20px; border: 1px solid #ddd; text-align: center;'>Nenhum item encontrado</td>
                </tr>";
        }
        
        $subtotal = ($orcamento['valor_total'] ?? 0) + (($orcamento['valor_total'] ?? 0) * ($orcamento['desconto_percentual'] ?? 0) / 100);
        $desconto_valor = ($orcamento['valor_total'] ?? 0) * ($orcamento['desconto_percentual'] ?? 0) / 100;
        
        $html .= "
            </tbody>
        </table>
        
        <h2>Resumo Financeiro</h2>
        <table class='resumo-financeiro' style='width: 100%; margin-left: auto; width: 50%; float: right;'>
            <tr>
                <td style='padding: 5px;'><strong>Subtotal:</strong></td>
                <td style='padding: 5px; text-align: right;'>R$ " . number_format($subtotal, 2, ',', '.') . "</td>
            </tr>
            <tr>
                <td style='padding: 5px;'><strong>Desconto (" . ($orcamento['desconto_percentual'] ?? 0) . "%):</strong></td>
                <td style='padding: 5px; text-align: right; color: #ff0000;'>-R$ " . number_format($desconto_valor, 2, ',', '.') . "</td>
            </tr>
            <tr>
                <td style='padding: 5px;'><strong>Valor Adicional:</strong></td>
                <td style='padding: 5px; text-align: right;'>R$ " . number_format($orcamento['valor_adicional'] ?? 0, 2, ',', '.') . "</td>
            </tr>
            <tr style='background-color: #f0f0f0; font-weight: bold;'>
                <td style='padding: 8px;'><strong>VALOR TOTAL:</strong></td>
                <td style='padding: 8px; text-align: right; font-size: 16px;'>R$ " . number_format($orcamento['valor_total'] ?? 0, 2, ',', '.') . "</td>
            </tr>
            <tr>
                <td style='padding: 5px;'><strong>Custo de Materiais:</strong></td>
                <td style='padding: 5px; text-align: right;'>R$ " . number_format($orcamento['valor_custo'] ?? 0, 2, ',', '.') . "</td>
            </tr>
            <tr>
                <td style='padding: 5px;'><strong>Lucro Estimado:</strong></td>
                <td style='padding: 5px; text-align: right;'>R$ " . number_format($orcamento['valor_lucro'] ?? 0, 2, ',', '.') . "</td>
            </tr>
        </table>
        <div style='clear: both;'></div>";
        
        if (!empty($orcamento['observacao'])) {
            $html .= "
            <h2 style='margin-top: 30px;'>Observações</h2>
            <p style='padding: 10px; background-color: #f9f9f9; border: 1px solid #ddd;'>" . nl2br($orcamento['observacao']) . "</p>";
        }
        
        $html .= $this->obter_rodape();
        
        return $html;
    }
    
    /**
     * Método: gerar_recibo()
     */
    public function gerar_recibo($venda_id, $salvar = false) {
        if (empty($venda_id)) {
            return false;
        }
        
        return $this->gerar_recibo_exemplo($salvar);
    }
    
    /**
     * Método PRIVADO: gerar_recibo_exemplo()
     */
    private function gerar_recibo_exemplo($salvar = false) {
        $venda = [
            'numero' => 'VND-20260215-0001',
            'data_venda' => date('Y-m-d'),
            'cliente_id' => 1,
            'valor_total' => 2500.00
        ];
        
        $cliente = [
            'nome' => 'Cliente Exemplo',
            'cpf_cnpj' => '123.456.789-00',
            'whatsapp' => '(11) 99999-9999'
        ];
        
        $html = $this->montar_html_recibo($venda, $cliente);
        
        if ($salvar) {
            return $this->salvar_pdf($html, 'recibo_exemplo');
        } else {
            $this->exibir_pdf($html, 'recibo_exemplo');
            return true;
        }
    }
    
    /**
     * Método PRIVADO: montar_html_recibo()
     */
    private function montar_html_recibo($venda, $cliente) {
        $html = $this->obter_cabecalho();
        
        $html .= "
        <h1>RECIBO DE PAGAMENTO</h1>
        
        <table class='info-document' style='width: 100%; margin-bottom: 20px;'>
            <tr>
                <td style='width: 50%;'><strong>Número do Recibo:</strong> REC-" . ($venda['numero'] ?? 'N/D') . "</td>
                <td style='width: 50%;'><strong>Data:</strong> " . date('d/m/Y') . "</td>
            </tr>
        </table>
        
        <h2>Dados do Cliente</h2>
        <p style='margin-bottom: 20px;'>
            <strong>" . ($cliente['nome'] ?? 'Cliente não informado') . "</strong><br>
            CPF/CNPJ: " . ($cliente['cpf_cnpj'] ?? '') . "<br>
            Telefone: " . ($cliente['whatsapp'] ?? 'Não informado') . "
        </p>
        
        <h2>Descrição do Pagamento</h2>
        <p style='margin-bottom: 20px;'>
            Referente à venda número: <strong>" . ($venda['numero'] ?? 'N/D') . "</strong><br>
            Data da venda: " . (isset($venda['data_venda']) ? date('d/m/Y', strtotime($venda['data_venda'])) : date('d/m/Y')) . "<br>
            Data do pagamento: " . date('d/m/Y') . "
        </p>
        
        <table class='resumo-financeiro' style='width: 100%; margin: 20px 0;'>
            <tr>
                <td style='padding: 8px;'><strong>Valor Bruto:</strong></td>
                <td style='padding: 8px; text-align: right;'>R$ " . number_format($venda['valor_total'] ?? 0, 2, ',', '.') . "</td>
            </tr>
            <tr style='background-color: #d4edda; font-weight: bold;'>
                <td style='padding: 8px;'><strong>VALOR RECEBIDO:</strong></td>
                <td style='padding: 8px; text-align: right;'>R$ " . number_format($venda['valor_total'] ?? 0, 2, ',', '.') . "</td>
            </tr>
        </table>
        
        <p style='margin-top: 30px; text-align: center; font-weight: bold;'>
            Declaro ter recebido a quantia de <strong>R$ " . number_format($venda['valor_total'] ?? 0, 2, ',', '.') . "</strong><br>
            do cliente <strong>" . ($cliente['nome'] ?? 'Cliente não informado') . "</strong><br>
            como pagamento integral referente à venda acima mencionada.
        </p>
        
        <p style='margin-top: 40px;'><strong>Forma de Pagamento:</strong> PIX / Dinheiro / Cartão</p>
        
        <hr style='margin: 40px 0; border: none; border-top: 1px solid #ccc;'>
        
        <p style='text-align: center; margin-top: 40px;'>
            Assinado digitalmente em " . date('d/m/Y \à\s H:i') . " via sistema " . $this->empresa_nome . "
        </p>";
        
        $html .= $this->obter_rodape();
        
        return $html;
    }
    
    /**
     * Método: gerar_garantia()
     */
    public function gerar_garantia($garantia_id, $salvar = false) {
        if (empty($garantia_id)) {
            return false;
        }
        
        return $this->gerar_garantia_exemplo($salvar);
    }
    
    /**
     * Método PRIVADO: gerar_garantia_exemplo()
     */
    private function gerar_garantia_exemplo($salvar = false) {
        $garantia = [
            'numero' => 'GAR-20260215-0001',
            'data_emissao' => date('Y-m-d'),
            'data_validade' => date('Y-m-d', strtotime('+90 days')),
            'tipo' => 'instalacao',
            'cliente_id' => 1,
            'condicoes' => "Esta garantia cobre defeitos de fabricação e instalação por um período de 90 dias.\n\nNão estão cobertos:\n- Danos causados por mau uso\n- Instalação elétrica inadequada\n- Sobretensão na rede elétrica\n- Limpeza inadequada dos filtros"
        ];
        
        $cliente = [
            'nome' => 'Cliente Exemplo',
            'cpf_cnpj' => '123.456.789-00',
            'endereco_rua' => 'Rua Exemplo',
            'endereco_numero' => '123',
            'endereco_bairro' => 'Centro',
            'endereco_cidade' => 'São Paulo',
            'endereco_estado' => 'SP'
        ];
        
        $html = $this->montar_html_garantia($garantia, $cliente);
        
        if ($salvar) {
            return $this->salvar_pdf($html, 'garantia_exemplo');
        } else {
            $this->exibir_pdf($html, 'garantia_exemplo');
            return true;
        }
    }
    
    /**
     * Método PRIVADO: montar_html_garantia()
     */
    private function montar_html_garantia($garantia, $cliente) {
        $html = $this->obter_cabecalho();
        
        $tipos_garantia = [
            'instalacao' => 'Instalação',
            'manutencao' => 'Manutenção',
            'reparo' => 'Reparo',
            'pecas' => 'Peças',
            'servicos_gerais' => 'Serviços Gerais',
            'personalizado' => $garantia['tipo_personalizado'] ?? 'Personalizado'
        ];
        
        $tipo_exibicao = $tipos_garantia[$garantia['tipo']] ?? ucfirst(str_replace('_', ' ', $garantia['tipo']));
        
        $html .= "
        <h1>TERMO DE GARANTIA</h1>
        
        <table class='info-document' style='width: 100%; margin-bottom: 20px;'>
            <tr>
                <td style='width: 50%;'><strong>Número:</strong> " . ($garantia['numero'] ?? 'N/D') . "</td>
                <td style='width: 50%;'><strong>Tipo:</strong> " . $tipo_exibicao . "</td>
            </tr>
            <tr>
                <td><strong>Emissão:</strong> " . (isset($garantia['data_emissao']) ? date('d/m/Y', strtotime($garantia['data_emissao'])) : date('d/m/Y')) . "</td>
                <td><strong>Válida até:</strong> " . (isset($garantia['data_validade']) ? date('d/m/Y', strtotime($garantia['data_validade'])) : date('d/m/Y', strtotime('+90 days'))) . "</td>
            </tr>
        </table>
        
        <h2>Beneficiário</h2>
        <p style='margin-bottom: 20px;'>
            <strong>" . ($cliente['nome'] ?? 'Cliente não informado') . "</strong><br>
            CPF/CNPJ: " . ($cliente['cpf_cnpj'] ?? '') . "<br>
            Endereço: " . ($cliente['endereco_rua'] ?? '') . ", " . ($cliente['endereco_numero'] ?? '') . " - " . ($cliente['endereco_bairro'] ?? '') . ", " . ($cliente['endereco_cidade'] ?? '') . "/" . ($cliente['endereco_estado'] ?? '') . "
        </p>
        
        <h2>Condições da Garantia</h2>
        <div style='border: 1px solid #ccc; padding: 15px; background-color: #f9f9f9; margin-bottom: 20px;'>
            " . nl2br($garantia['condicoes'] ?? 'Garantia padrão de 90 dias para serviços e peças.') . "
        </div>";
        
        $html .= $this->obter_rodape();
        
        return $html;
    }
    
    /**
     * Método PRIVADO: obter_cabecalho()
     */
    private function obter_cabecalho() {
        $logo_html = '';
        if (!empty($this->empresa_logo)) {
            $logo_html = "<img src='" . $this->empresa_logo . "' style='max-height: 60px; max-width: 200px;'>";
        }
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Documento</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { font-family: Arial, sans-serif; font-size: 12px; line-height: 1.6; color: #333; }
                .header { border-bottom: 2px solid #333; padding-bottom: 10px; margin-bottom: 20px; overflow: hidden; }
                .header-info { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
                .company-name { font-size: 18px; font-weight: bold; color: #333; }
                .company-info { font-size: 10px; text-align: right; color: #666; }
                h1 { color: #333; margin: 20px 0 15px; text-align: center; font-size: 18px; text-transform: uppercase; }
                h2 { color: #666; margin: 15px 0 10px; font-size: 14px; border-bottom: 1px solid #ccc; padding-bottom: 5px; }
                table { width: 100%; border-collapse: collapse; }
                th { background-color: #f5f5f5; font-weight: bold; }
                .info-document td { padding: 3px; }
                .info-cliente td { padding: 2px; }
                .items-table { margin: 10px 0; }
                .items-table th { background-color: #333; color: white; padding: 8px; }
                .footer { margin-top: 30px; padding-top: 10px; border-top: 1px solid #ccc; text-align: center; font-size: 9px; color: #666; }
                p { margin-bottom: 10px; }
                hr { margin: 20px 0; border: none; border-top: 1px solid #ccc; }
                .clear { clear: both; }
            </style>
        </head>
        <body>
            <div class='header'>
                <div class='header-info'>
                    <div class='company-name'>" . $logo_html . " " . $this->empresa_nome . "</div>
                    <div class='company-info'>
                        CNPJ: " . $this->empresa_cnpj . "<br>
                        " . $this->empresa_endereco . "<br>
                        Tel: " . $this->empresa_telefone . " | WhatsApp: " . $this->empresa_whatsapp . "
                    </div>
                </div>
            </div>";
    }
    
    /**
     * Método PRIVADO: obter_rodape()
     */
    private function obter_rodape() {
        return "
            <div class='footer'>
                <p>Este documento foi gerado eletronicamente e é válido sem assinatura.</p>
                <p>Documento gerado em " . date('d/m/Y \à\s H:i') . " | Página 1/1</p>
            </div>
        </body>
        </html>";
    }
    
    /**
     * Método PRIVADO: salvar_pdf()
     */
    private function salvar_pdf($html, $nome) {
        try {
            if (!defined('DIR_UPLOADS')) {
                define('DIR_UPLOADS', __DIR__ . '/../uploads');
            }
            if (!defined('DIR_PERMISSIONS')) {
                define('DIR_PERMISSIONS', 0755);
            }
            if (!defined('BASE_PATH')) {
                define('BASE_PATH', dirname(__DIR__));
            }
            
            $nome_arquivo = preg_replace('/[^a-z0-9_-]/i', '', $nome) . '_' . date('Ymd_His') . '.html';
            $caminho_completo = DIR_UPLOADS . '/pdfs/' . $nome_arquivo;
            
            if (!is_dir(dirname($caminho_completo))) {
                mkdir(dirname($caminho_completo), DIR_PERMISSIONS, true);
            }
            
            file_put_contents($caminho_completo, $html);
            
            return str_replace(BASE_PATH, '', $caminho_completo);
        } catch (Exception $e) {
            error_log("Erro ao salvar PDF: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Método PRIVADO: exibir_pdf()
     */
    private function exibir_pdf($html, $nome) {
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: inline; filename="' . $nome . '.html"');
        
        echo $html;
        exit;
    }
    
    /**
     * Método PRIVADO: formatar_cpf_cnpj()
     */
    private function formatar_cpf_cnpj($valor) {
        $valor = preg_replace('/\D/', '', $valor);
        
        if (empty($valor)) {
            return 'Não informado';
        }
        
        if (strlen($valor) == 11) {
            return substr($valor, 0, 3) . '.' . 
                   substr($valor, 3, 3) . '.' . 
                   substr($valor, 6, 3) . '-' . 
                   substr($valor, 9, 2);
        } elseif (strlen($valor) == 14) {
            return substr($valor, 0, 2) . '.' . 
                   substr($valor, 2, 3) . '.' . 
                   substr($valor, 5, 3) . '/' . 
                   substr($valor, 8, 4) . '-' . 
                   substr($valor, 12, 2);
        }
        
        return $valor;
    }
}
?>
