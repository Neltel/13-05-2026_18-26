<?php
/**
 * Classe WhatsApp para envio de mensagens
 * VERSÃO CORRIGIDA - 22/02/2026
 */

class WhatsApp {
    private $conexao;
    
    public function __construct($conexao = null) {
        $this->conexao = $conexao;
    }
    
    /**
     * Gera link do WhatsApp para envio
     */
    public function gerarLink($telefone, $mensagem) {
        // Log para debug
        error_log("WhatsApp::gerarLink - Telefone original: " . $telefone);
        
        // Limpar telefone (deixar apenas números)
        $telefone = preg_replace('/[^0-9]/', '', $telefone);
        error_log("WhatsApp::gerarLink - Telefone limpo: " . $telefone);
        
        // Se estiver vazio, retorna erro
        if (empty($telefone)) {
            error_log("WhatsApp::gerarLink - ERRO: Telefone vazio");
            return "#";
        }
        
        // Garantir código do país (55 para Brasil)
        if (substr($telefone, 0, 2) !== '55') {
            $telefone = '55' . $telefone;
            error_log("WhatsApp::gerarLink - Adicionado código país: " . $telefone);
        }
        
        // Garantir que tem 9 no celular (para números com 8 dígitos)
        // Formato esperado: 55 + DDD (2) + 9 + 8 dígitos = 13 dígitos
        if (strlen($telefone) == 12) { // 55 + DDD (2) + 8 dígitos = 12
            $telefone = substr($telefone, 0, 4) . '9' . substr($telefone, 4);
            error_log("WhatsApp::gerarLink - Adicionado dígito 9: " . $telefone);
        }
        
        // Garantir que tem 13 dígitos (formato internacional)
        if (strlen($telefone) < 13) {
            error_log("WhatsApp::gerarLink - AVISO: Telefone com " . strlen($telefone) . " dígitos (esperado 13)");
        }
        
        // Codificar mensagem
        $mensagem_codificada = rawurlencode($mensagem);
        
        // Montar link
        $link = "https://wa.me/{$telefone}?text={$mensagem_codificada}";
        error_log("WhatsApp::gerarLink - Link final: " . $link);
        
        return $link;
    }
    
    /**
     * Redireciona para WhatsApp
     */
    public function redirecionar($telefone, $mensagem) {
        $link = $this->gerarLink($telefone, $mensagem);
        
        if ($link == "#") {
            error_log("WhatsApp::redirecionar - ERRO: Link inválido");
            return false;
        }
        
        error_log("WhatsApp::redirecionar - Redirecionando para: " . $link);
        header("Location: " . $link);
        exit;
    }
    
    /**
     * Envia mensagem via API (fallback)
     */
    public function enviarMensagem($telefone, $mensagem) {
        $link = $this->gerarLink($telefone, $mensagem);
        
        return [
            'success' => ($link != "#"),
            'method' => 'redirect',
            'link' => $link,
            'telefone' => $telefone
        ];
    }
}
