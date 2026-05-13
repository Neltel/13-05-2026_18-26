<?php
/**
 * =====================================================================
 * CLASSE WHATSAPP - Integração com whatsapp-web.js
 * =====================================================================
 * Comunica com o serviço Node.js via API REST
 */

class WhatsApp {
    
    private $apiUrl;
    private $sessionId;
    private $conexao;
    
    /**
     * Construtor
     * @param mysqli|null $conexao Conexão com banco de dados
     * @param string $sessionId Identificador da sessão
     */
    public function __construct($conexao = null, $sessionId = 'default') {
        // URL do serviço Node.js (ajuste conforme seu ambiente)
        $this->apiUrl = getenv('WHATSAPP_API_URL') ?: 'http://localhost:3001';
        $this->sessionId = $sessionId;
        $this->conexao = $conexao;
    }
    
    /**
     * Envia mensagem via WhatsApp
     * @param string $numero Número de destino (com DDD)
     * @param string $mensagem Texto da mensagem
     * @return array Resultado da operação
     */
    public function enviarMensagem($numero, $mensagem) {
        // Validação
        if (empty($numero) || empty($mensagem)) {
            return $this->erro('Número e mensagem são obrigatórios');
        }
        
        // Formatar número
        $numero = $this->formatarNumero($numero);
        
        // Dados para envio
        $dados = [
            'to' => $numero,
            'message' => $mensagem,
            'sessionId' => $this->sessionId
        ];
        
        // Chamar API
        $resposta = $this->chamarAPI('/api/send', $dados);
        
        if ($resposta && isset($resposta['success']) && $resposta['success']) {
            return [
                'sucesso' => true,
                'messageId' => $resposta['messageId'] ?? null,
                'timestamp' => $resposta['timestamp'] ?? null,
                'numero' => $numero,
                'mensagem' => $mensagem
            ];
        }
        
        return $this->erro($resposta['message'] ?? 'Erro ao enviar mensagem');
    }
    
    /**
     * Envia orçamento via WhatsApp
     */
    public function enviarOrcamento($numero, $cliente, $valor, $numeroOrcamento = null) {
        $valorFormatado = number_format($valor, 2, ',', '.');
        
        $mensagem = "💰 *Orçamento - Império AR*\n\n";
        $mensagem .= "Olá *{$cliente}*! 👋\n\n";
        $mensagem .= "Seu orçamento foi gerado com sucesso!\n\n";
        $mensagem .= "📋 *Número:* " . ($numeroOrcamento ? $numeroOrcamento : 'Consulte seu email') . "\n";
        $mensagem .= "💵 *Valor:* R$ {$valorFormatado}\n";
        $mensagem .= "⏱️ *Válido por:* 7 dias\n\n";
        $mensagem .= "Para mais detalhes, acesse nosso portal ou entre em contato conosco!\n\n";
        $mensagem .= "Qualquer dúvida, estamos à disposição! 😊";
        
        return $this->enviarMensagem($numero, $mensagem);
    }
    
    /**
     * Envia confirmação de pedido
     */
    public function enviarConfirmacaoPedido($numero, $cliente, $valor, $numeroPedido) {
        $valorFormatado = number_format($valor, 2, ',', '.');
        
        $mensagem = "✅ *Pedido Confirmado - Império AR*\n\n";
        $mensagem .= "Olá *{$cliente}*! 🎉\n\n";
        $mensagem .= "Seu pedido foi confirmado com sucesso!\n\n";
        $mensagem .= "📋 *Número do Pedido:* {$numeroPedido}\n";
        $mensagem .= "💵 *Valor Total:* R$ {$valorFormatado}\n";
        $mensagem .= "📦 *Status:* Aguardando confirmação de pagamento\n\n";
        $mensagem .= "Obrigado pela confiança! 🙏";
        
        return $this->enviarMensagem($numero, $mensagem);
    }
    
    /**
     * Envia lembrete de agendamento
     */
    public function enviarLembreteAgendamento($numero, $cliente, $data, $hora) {
        $dataFormatada = date('d/m/Y', strtotime($data));
        
        $mensagem = "🔔 *Lembrete de Agendamento - Império AR*\n\n";
        $mensagem .= "Olá *{$cliente}*! 👋\n\n";
        $mensagem .= "Seu agendamento está confirmado para:\n\n";
        $mensagem .= "📅 *Data:* {$dataFormatada}\n";
        $mensagem .= "🕐 *Horário:* {$hora}\n\n";
        $mensagem .= "Em caso de imprevistos, avise-nos com antecedência.\n\n";
        $mensagem .= "Agradecemos a preferência! 🙏";
        
        return $this->enviarMensagem($numero, $mensagem);
    }
    
    /**
     * Envia recibo de cobrança
     */
    public function enviarRecibo($numero, $cliente, $valor, $data) {
        $valorFormatado = number_format($valor, 2, ',', '.');
        $dataFormatada = date('d/m/Y', strtotime($data));
        
        $mensagem = "🧾 *Recibo de Cobrança - Império AR*\n\n";
        $mensagem .= "Olá *{$cliente}*!\n\n";
        $mensagem .= "Comprovante de sua cobrança:\n\n";
        $mensagem .= "💵 *Valor:* R$ {$valorFormatado}\n";
        $mensagem .= "📅 *Data:* {$dataFormatada}\n";
        $mensagem .= "📍 *Status:* Processado\n\n";
        $mensagem .= "Obrigado! 🎉";
        
        return $this->enviarMensagem($numero, $mensagem);
    }
    
    /**
     * Verifica se número é válido no WhatsApp
     */
    public function verificarNumero($numero) {
        $numero = $this->formatarNumero($numero);
        
        $dados = [
            'number' => $numero,
            'sessionId' => $this->sessionId
        ];
        
        $resposta = $this->chamarAPI('/api/check-number', $dados);
        
        if ($resposta && isset($resposta['success'])) {
            return [
                'valido' => $resposta['exists'] ?? false,
                'numero' => $numero,
                'detalhes' => $resposta['details'] ?? null
            ];
        }
        
        return ['valido' => false, 'erro' => 'Erro ao verificar número'];
    }
    
    /**
     * Verifica status da conexão
     */
    public function verificarConexao() {
        $resposta = $this->chamarAPI('/api/status', [], 'GET');
        
        if ($resposta && isset($resposta['service'])) {
            return [
                'conectado' => true,
                'servico' => $resposta['service'],
                'clientes' => $resposta['clients'] ?? [],
                'uptime' => $resposta['uptime'] ?? 0
            ];
        }
        
        return [
            'conectado' => false,
            'erro' => 'Serviço WhatsApp indisponível'
        ];
    }
    
    /**
     * Formata número para padrão internacional
     */
    private function formatarNumero($numero) {
        // Remove caracteres especiais
        $numero = preg_replace('/[^0-9]/', '', $numero);
        
        // Remove zeros à esquerda (mantém apenas os dígitos significativos)
        $numero = ltrim($numero, '0');
        
        // Garante código do país Brasil (55)
        if (!str_starts_with($numero, '55')) {
            $numero = '55' . $numero;
        }
        
        return $numero;
    }
    
    /**
     * Chama a API do serviço Node.js
     */
    private function chamarAPI($endpoint, $dados = [], $metodo = 'POST') {
        $url = $this->apiUrl . $endpoint;
        
        $ch = curl_init($url);
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        
        if ($metodo === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dados));
        }
        
        $resposta = curl_exec($ch);
        $erro = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($erro) {
            $this->logAPI('ERROR', $endpoint, $dados, ['error' => $erro]);
            return null;
        }
        
        $resultado = json_decode($resposta, true);
        $this->logAPI('INFO', $endpoint, $dados, $resultado);
        
        return $resultado;
    }
    
    /**
     * Registra logs de API
     */
    private function logAPI($level, $endpoint, $request, $response) {
        if (!$this->conexao) {
            return;
        }
        
        try {
            $sql = "INSERT INTO logs_whatsapp (nivel, endpoint, requisicao, resposta, data_hora) 
                    VALUES (?, ?, ?, ?, NOW())";
            
            $stmt = $this->conexao->prepare($sql);
            if ($stmt) {
                $req_json = json_encode($request);
                $res_json = json_encode($response);
                
                $stmt->bind_param("ssss", $level, $endpoint, $req_json, $res_json);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Exception $e) {
            error_log("Erro ao registrar log de API: " . $e->getMessage());
        }
    }
    
    /**
     * Retorna resposta de erro padronizada
     */
    private function erro($mensagem) {
        return [
            'sucesso' => false,
            'erro' => $mensagem,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
}

?>
