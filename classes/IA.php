<?php
/**
 * Classe IA - Versão de desenvolvimento
 * Simula funcionalidades de IA para geração de relatórios e diagnósticos
 */
class IA {
    
    private $api_key = '';
    private $modelo = 'gpt-3.5-turbo';
    private $db = null;
    
    /**
     * Construtor
     */
    public function __construct($conexao = null) {
        $this->db = $conexao;
        // Carrega configurações se existirem
        $this->carregar_configuracoes();
    }
    
    /**
     * Carrega configurações do banco
     */
    private function carregar_configuracoes() {
        // Versão simplificada para desenvolvimento
        $this->api_key = getenv('OPENAI_API_KEY') ?: '';
    }
    
    /**
     * Gera relatório técnico baseado em dados do serviço
     * 
     * @param array $dados_servico Dados do serviço realizado
     * @param array $dados_cliente Dados do cliente
     * @param array $dados_equipamento Dados do equipamento
     * @return string Relatório gerado
     */
    public function gerar_relatorio_tecnico($dados_servico, $dados_cliente, $dados_equipamento = []) {
        // Simula geração de relatório
        $relatorio = $this->simular_relatorio($dados_servico, $dados_cliente, $dados_equipamento);
        
        // Log para debug
        $this->salvar_log('relatorio', $relatorio);
        
        return $relatorio;
    }
    
    /**
     * Gera diagnóstico baseado em sintomas descritos
     * 
     * @param string $sintomas Descrição dos sintomas
     * @param string $tipo_equipamento Tipo do equipamento
     * @return array Diagnóstico com possíveis causas e soluções
     */
    public function gerar_diagnostico($sintomas, $tipo_equipamento = 'ar_condicionado') {
        $diagnostico = $this->simular_diagnostico($sintomas, $tipo_equipamento);
        
        $this->salvar_log('diagnostico', json_encode($diagnostico));
        
        return $diagnostico;
    }
    
    /**
     * Sugere peças com base no diagnóstico
     * 
     * @param string $diagnostico Diagnóstico gerado
     * @return array Lista de peças sugeridas
     */
    public function sugerir_pecas($diagnostico) {
        return [
            [
                'nome' => 'Capacitor',
                'quantidade' => 1,
                'prioridade' => 'alta',
                'justificativa' => 'Componente comum em falhas de partida'
            ],
            [
                'nome' => 'Ventilador',
                'quantidade' => 1,
                'prioridade' => 'media',
                'justificativa' => 'Possível problema de rotação'
            ]
        ];
    }
    
    /**
     * Traduz texto para português (se necessário)
     * 
     * @param string $texto Texto para traduzir
     * @return string Texto traduzido
     */
    public function traduzir($texto) {
        // Simula tradução (apenas retorna o texto)
        return $texto;
    }
    
    /**
     * Resume um texto longo
     * 
     * @param string $texto Texto para resumir
     * @param int $max_tamanho Tamanho máximo do resumo
     * @return string Texto resumido
     */
    public function resumir($texto, $max_tamanho = 200) {
        if (strlen($texto) <= $max_tamanho) {
            return $texto;
        }
        
        return substr($texto, 0, $max_tamanho) . '...';
    }
    
    /**
     * SIMULAÇÃO: Gera relatório técnico
     */
    private function simular_relatorio($servico, $cliente, $equipamento) {
        $data = date('d/m/Y', strtotime($servico['data_servico'] ?? 'now'));
        $cliente_nome = $cliente['nome'] ?? 'Cliente';
        $servico_tipo = $servico['tipo'] ?? 'manutenção';
        
        $relatorio = "RELATÓRIO TÉCNICO - SISTEMA DE DIAGNÓSTICO\n";
        $relatorio .= str_repeat('=', 50) . "\n\n";
        
        $relatorio .= "DATA: {$data}\n";
        $relatorio .= "CLIENTE: {$cliente_nome}\n";
        $relatorio .= "TIPO DE SERVIÇO: " . strtoupper($servico_tipo) . "\n\n";
        
        $relatorio .= "DESCRIÇÃO DO SERVIÇO REALIZADO:\n";
        $relatorio .= "----------------------------------------\n";
        $relatorio .= "Foi realizada " . $servico_tipo . " no equipamento do cliente. ";
        $relatorio .= "Durante o serviço, foram verificados os seguintes pontos:\n\n";
        
        $relatorio .= "1. Verificação do sistema elétrico - OK\n";
        $relatorio .= "2. Verificação do gás refrigerante - Nível adequado\n";
        $relatorio .= "3. Limpeza dos filtros - Realizada\n";
        $relatorio .= "4. Verificação de vazamentos - Nenhum vazamento detectado\n";
        $relatorio .= "5. Teste de funcionamento - Equipamento operando normalmente\n\n";
        
        $relatorio .= "RECOMENDAÇÕES:\n";
        $relatorio .= "----------------------------------------\n";
        $relatorio .= "- Realizar limpeza dos filtros a cada 3 meses\n";
        $relatorio .= "- Verificar drenagem periodicamente\n";
        $relatorio .= "- Próxima manutenção preventiva em 6 meses\n\n";
        
        $relatorio .= "TÉCNICO RESPONSÁVEL: Sistema IA (Diagnóstico Automatizado)\n";
        $relatorio .= "GERADO EM: " . date('d/m/Y H:i:s');
        
        return $relatorio;
    }
    
    /**
     * SIMULAÇÃO: Gera diagnóstico baseado em sintomas
     */
    private function simular_diagnostico($sintomas, $tipo) {
        $sintomas = strtolower($sintomas);
        
        $diagnosticos = [
            'nao liga' => [
                'causas' => ['Falta de energia', 'Problema no capacitor', 'Placa eletrônica queimada'],
                'solucoes' => ['Verificar disjuntor', 'Testar capacitor', 'Verificar placa'],
                'pecas' => ['Capacitor', 'Fusível', 'Fonte']
            ],
            'gela pouco' => [
                'causas' => ['Falta de gás', 'Filtro sujo', 'Problema no compressor'],
                'solucoes' => ['Recarga de gás', 'Limpeza de filtros', 'Verificar compressor'],
                'pecas' => ['Gás refrigerante', 'Filtro', 'Óleo']
            ],
            'barulho' => [
                'causas' => ['Ventilador desbalanceado', 'Parafuso solto', 'Rolamento gasto'],
                'solucoes' => ['Balancear ventilador', 'Apertar fixações', 'Trocar rolamento'],
                'pecas' => ['Rolamento', 'Ventilador', 'Parafusos']
            ]
        ];
        
        // Tenta encontrar diagnóstico baseado nos sintomas
        foreach ($diagnosticos as $chave => $diag) {
            if (strpos($sintomas, $chave) !== false) {
                return [
                    'sintomas_detectados' => $sintomas,
                    'tipo_equipamento' => $tipo,
                    'possiveis_causas' => $diag['causas'],
                    'solucoes_recomendadas' => $diag['solucoes'],
                    'pecas_necessarias' => $diag['pecas'],
                    'confianca' => '85%',
                    'observacoes' => 'Diagnóstico gerado automaticamente. Recomenda-se avaliação presencial.'
                ];
            }
        }
        
        // Diagnóstico genérico se não encontrar correspondência
        return [
            'sintomas_detectados' => $sintomas,
            'tipo_equipamento' => $tipo,
            'possiveis_causas' => ['Falha genérica', 'Necessário avaliação técnica'],
            'solucoes_recomendadas' => ['Realizar inspeção completa', 'Verificar componentes principais'],
            'pecas_necessarias' => ['A definir após inspeção'],
            'confianca' => '50%',
            'observacoes' => 'Sintomas não específicos. Necessário avaliação presencial detalhada.'
        ];
    }
    
    /**
     * Salva log para debug
     */
    private function salvar_log($tipo, $conteudo) {
        $log_dir = __DIR__ . '/../logs';
        $log_file = $log_dir . '/ia.log';
        
        // Cria pasta de logs se não existir
        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0755, true);
        }
        
        $log = sprintf(
            "[%s] TIPO: %s\n%s\n%s\n",
            date('Y-m-d H:i:s'),
            $tipo,
            str_repeat('-', 50),
            str_replace(["\n", "\r"], ' ', $conteudo) . "\n"
        );
        
        file_put_contents($log_file, $log, FILE_APPEND);
    }
}
