-- Tabela para logs do WhatsApp
CREATE TABLE IF NOT EXISTS logs_whatsapp (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nivel VARCHAR(20) NOT NULL,
    endpoint VARCHAR(255) NOT NULL,
    requisicao LONGTEXT,
    resposta LONGTEXT,
    data_hora DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_data (data_hora),
    INDEX idx_nivel (nivel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela para mensagens enviadas
CREATE TABLE IF NOT EXISTS whatsapp_mensagens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero VARCHAR(20) NOT NULL,
    mensagem LONGTEXT NOT NULL,
    tipo VARCHAR(50), -- 'orcamento', 'pedido', 'cobranca', etc
    status VARCHAR(20) DEFAULT 'enviada',
    message_id VARCHAR(255),
    data_envio DATETIME DEFAULT CURRENT_TIMESTAMP,
    data_leitura DATETIME,
    INDEX idx_numero (numero),
    INDEX idx_data (data_envio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;