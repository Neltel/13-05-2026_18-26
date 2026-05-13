<?php
/**
 * =====================================================
 * VARIÁVEIS DE AMBIENTE
 * =====================================================
 * 
 * Responsabilidade: Definir todas as constantes do sistema
 * Uso: Incluir antes de database.php
 * 
 * CREDENCIAIS ATUALIZADAS: 14/02/2026
 * DOMÍNIO ATUALIZADO: imperiodoar.com.br
 */

// ===== INFORMAÇÕES BANCO DE DADOS =====
define('DB_HOST', 'localhost');
define('DB_USER', 'imperiod_imperio');
define('DB_PASS', 'RBevv45BxPb7hQht2KKF');
define('DB_NAME', 'imperiod_imperio');

// ===== MODO DEBUG =====
// Em produção, mudar para false
define('DEBUG_MODE', true);

// ===== URLs E CAMINHOS =====
// URL base do site (SEM BARRA FINAL) - ATUALIZADO PARA DOMÍNIO CORRETO
define('BASE_URL', 'https://imperiodoar.com.br');

// Caminho físico da raiz do projeto
define('BASE_PATH', dirname(__DIR__));

// Diretórios principais
define('DIR_APP', BASE_PATH . '/app');
define('DIR_PUBLIC', BASE_PATH . '/public');
define('DIR_CLASSES', BASE_PATH . '/classes');
define('DIR_TEMPLATES', BASE_PATH . '/templates');
define('DIR_UPLOADS', BASE_PATH . '/public/uploads');
define('DIR_LOGS', BASE_PATH . '/logs');

// URLs de upload (para acessar via web)
define('URL_UPLOADS', BASE_URL . '/public/uploads');
define('URL_CSS', BASE_URL . '/public/css');
define('URL_JS', BASE_URL . '/public/js');

// ===== CONFIGURAÇÕES DE SEGURANÇA =====

// Tempo de sessão (em minutos)
define('SESSION_TIMEOUT', 120);

// Tentativas máximas de login
define('MAX_LOGIN_ATTEMPTS', 5);

// Tempo de bloqueio após muitas tentativas (em minutos)
define('LOGIN_LOCK_TIME', 30);

// Tokens de CSRF (proteção contra ataques)
define('CSRF_TOKEN_LENGTH', 32);

// ===== CONFIGURAÇÕES DE UPLOAD =====

// Tamanho máximo de upload (em bytes)
// 50MB
define('MAX_UPLOAD_SIZE', 52428800);

// Tipos de arquivo permitidos por função
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
define('ALLOWED_PDF_TYPES', ['pdf']);
define('ALLOWED_EXCEL_TYPES', ['xlsx', 'xls', 'csv']);

// Qualidade de compressão de imagens
define('IMAGE_COMPRESSION_QUALITY', 85);

// ===== CONFIGURAÇÕES DE PAGINAÇÃO =====

// Registros por página (padrão)
define('REGISTROS_POR_PAGINA', 50);

// ===== CONFIGURAÇÕES DE EMAIL/NOTIFICAÇÕES =====

define('EMAIL_FROM', 'noreply@imperiodoar.com.br');
define('EMAIL_FROM_NAME', 'Império AR - NM Refrigeração');

// ===== CONFIGURAÇÕES WHATSAPP =====
define('WHATSAPP_API_URL', '');
define('WHATSAPP_API_TOKEN', '');
define('WHATSAPP_PHONE_ID', '');

// ===== CONFIGURAÇÕES IA =====
define('IA_API_URL', '');
define('IA_API_KEY', '');
define('IA_MODEL', '');

// ===== CONFIGURAÇÕES PDF =====
define('PDF_FONT', 'Helvetica');
define('PDF_PAGE_SIZE', 'A4');

// ===== CONFIGURAÇÕES SISTEMA =====

// Timezone padrão
define('TIMEZONE', 'America/Sao_Paulo');

// Formato de data brasileira
define('DATE_FORMAT', 'd/m/Y');
define('DATETIME_FORMAT', 'd/m/Y H:i:s');
define('TIME_FORMAT', 'H:i');

// Formato monetário brasileiro
define('CURRENCY_SYMBOL', 'R$');
define('DECIMAL_SEPARATOR', ',');
define('THOUSANDS_SEPARATOR', '.');

// ===== CONFIGURAÇÕES API =====

// Versão da API
define('API_VERSION', '1.0.0');

// Limite de requisições por minuto (por IP)
define('API_RATE_LIMIT', 100);

// ===== CONFIGURAÇÕES PERMISSÕES =====

// Permissões de arquivo
define('FILE_PERMISSIONS', 0644);
define('DIR_PERMISSIONS', 0755);

// ===== CONFIGURAÇÕES PLUGINS =====

// Diretório de plugins (futuro)
define('DIR_PLUGINS', BASE_PATH . '/plugins');

// ===== DEFINIR TIMEZONE GLOBAL =====

date_default_timezone_set(TIMEZONE);

// ===== VERIFICAÇÕES DE SEGURANÇA =====

// Verifica se está rodando via HTTPS em produção
if (!DEBUG_MODE && !isset($_SERVER['HTTPS'])) {
    // Redireciona para HTTPS
    header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
    exit;
}

// Define headers de segurança
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');

?>
