<?php
/**
 * =====================================================================
 * CLASSE UPLOAD - Gerenciar Upload de Arquivos
 * =====================================================================
 * 
 * Responsabilidade: Gerenciar upload seguro de arquivos
 * Uso: $upload = new Upload(); $upload->enviar($_FILES['arquivo']);
 * Recebe: Array $_FILES do formulário
 * Retorna: String com caminho do arquivo ou false
 * 
 * Operações:
 * - Validar tipo de arquivo
 * - Validar tamanho de arquivo
 * - Renomear arquivo com segurança
 * - Comprimir imagens
 * - Gerar estrutura de pastas
 * - Registrar uploads no log
 */

class Upload {
    
    private $diretorio = '';
    private $tipos_permitidos = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
    private $tamanho_maximo = 52428800; // 50MB em bytes
    private $prefixo_arquivo = '';
    
    /**
     * Construtor
     */
    public function __construct() {
        // Usa diretório padrão se não informado depois
        $this->diretorio = DIR_UPLOADS;
    }
    
    /**
     * Método: definir_diretorio()
     * Responsabilidade: Definir diretório onde salvar arquivo
     * Parâmetros: $caminho (string com caminho)
     * Retorna: bool (sempre true, cria pasta se não existir)
     * 
     * Uso:
     *   $upload->definir_diretorio(DIR_UPLOADS . '/clientes');
     */
    public function definir_diretorio($caminho) {
        if (empty($caminho)) {
            return false;
        }
        
        $this->diretorio = rtrim($caminho, '/');
        
        // Cria diretório se não existir
        if (!is_dir($this->diretorio)) {
            mkdir($this->diretorio, DIR_PERMISSIONS, true);
        }
        
        return true;
    }
    
    /**
     * Método: definir_tipos_permitidos()
     * Responsabilidade: Definir quais tipos de arquivo são permitidos
     * Parâmetros: $tipos (array com extensões: ['jpg', 'png', 'pdf'])
     * Retorna: void
     * 
     * Uso:
     *   $upload->definir_tipos_permitidos(['jpg', 'png', 'gif']);
     */
    public function definir_tipos_permitidos($tipos) {
        if (is_array($tipos)) {
            $this->tipos_permitidos = $tipos;
        }
    }
    
    /**
     * Método: definir_tamanho_maximo()
     * Responsabilidade: Definir tamanho máximo de arquivo
     * Parâmetros: $bytes (int com tamanho em bytes)
     * Retorna: void
     * 
     * Uso:
     *   $upload->definir_tamanho_maximo(10485760); // 10MB
     */
    public function definir_tamanho_maximo($bytes) {
        if ($bytes > 0) {
            $this->tamanho_maximo = $bytes;
        }
    }
    
    /**
     * Método: definir_prefixo()
     * Responsabilidade: Definir prefixo para renomear arquivo
     * Parâmetros: $prefixo (string)
     * Retorna: void
     * 
     * Uso:
     *   $upload->definir_prefixo('cliente_123_');
     */
    public function definir_prefixo($prefixo) {
        if (!empty($prefixo)) {
            $this->prefixo_arquivo = $prefixo;
        }
    }
    
    /**
     * Método: enviar()
     * Responsabilidade: Processar upload de arquivo
     * Parâmetros:
     *   $arquivo - array $_FILES['campo'] do formulário
     *   $renomear - bool para renomear com timestamp (padrão: true)
     * Retorna: string com caminho relativo do arquivo ou false
     * 
     * Validações realizadas:
     *   - Arquivo foi realmente feito upload
     *   - Tipo de arquivo é permitido
     *   - Tamanho está dentro do limite
     *   - Arquivo não é malicioso
     * 
     * Exemplo:
     *   $caminho = $upload->enviar($_FILES['foto']);
     *   // Retorna: '/uploads/clientes/cliente_123_20260214_abc123.jpg'
     */
    public function enviar($arquivo, $renomear = true) {
        // Validações básicas
        if (empty($arquivo) || !is_array($arquivo)) {
            registrar_log_erro("Upload: arquivo inválido");
            return false;
        }
        
        // Verifica se houve erro no upload
        if ($arquivo['error'] != UPLOAD_ERR_OK) {
            registrar_log_erro("Upload: erro PHP - {$arquivo['error']}");
            return false;
        }
        
        // Verifica se arquivo existe
        if (!is_uploaded_file($arquivo['tmp_name'])) {
            registrar_log_erro("Upload: arquivo não é upload válido");
            return false;
        }
        
        // Obtém informações do arquivo
        $nome_original = basename($arquivo['name']);
        $tamanho = filesize($arquivo['tmp_name']);
        $tipo_mime = mime_content_type($arquivo['tmp_name']);
        
        // Extrai extensão
        $partes = explode('.', $nome_original);
        $extensao = strtolower(end($partes));
        
        // Validação de tamanho
        if ($tamanho > $this->tamanho_maximo) {
            registrar_log_erro("Upload: arquivo excede tamanho máximo ({$tamanho} > {$this->tamanho_maximo})");
            return false;
        }
        
        // Validação de tipo
        if (!in_array($extensao, $this->tipos_permitidos)) {
            registrar_log_erro("Upload: tipo de arquivo não permitido ({$extensao})");
            return false;
        }
        
        // Validação de MIME type para segurança extra
        if (!$this->validar_tipo_mime($extensao, $tipo_mime)) {
            registrar_log_erro("Upload: MIME type não corresponde à extensão");
            return false;
        }
        
        // Define novo nome do arquivo
        if ($renomear) {
            $nome_novo = $this->gerar_nome_arquivo($extensao);
        } else {
            $nome_novo = $this->sanitizar_nome_arquivo($nome_original);
        }
        
        // Caminho completo onde salvar
        $caminho_completo = $this->diretorio . '/' . $nome_novo;
        
        // Move arquivo
        if (!move_uploaded_file($arquivo['tmp_name'], $caminho_completo)) {
            registrar_log_erro("Upload: erro ao mover arquivo para {$caminho_completo}");
            return false;
        }
        
        // Define permissões
        chmod($caminho_completo, FILE_PERMISSIONS);
        
        // Se for imagem, tenta comprimir
        if (in_array($extensao, ['jpg', 'jpeg', 'png', 'gif'])) {
            $this->comprimir_imagem($caminho_completo, $extensao);
        }
        
        // Retorna caminho relativo
        $caminho_relativo = str_replace(BASE_PATH, '', $caminho_completo);
        
        registrar_log_erro("Upload realizado: {$nome_novo}");
        
        return $caminho_relativo;
    }
    
    /**
     * Método: deletar()
     * Responsabilidade: Deletar arquivo do servidor
     * Parâmetros: $caminho (string com caminho relativo)
     * Retorna: bool (sucesso ou falha)
     * 
     * Uso:
     *   $upload->deletar('/uploads/clientes/foto.jpg');
     */
    public function deletar($caminho) {
        if (empty($caminho)) {
            return false;
        }
        
        // Monta caminho completo
        $caminho_completo = BASE_PATH . $caminho;
        
        // Valida se arquivo existe
        if (!file_exists($caminho_completo)) {
            registrar_log_erro("Deletar: arquivo não encontrado ({$caminho_completo})");
            return false;
        }
        
        // Valida se é arquivo (não diretório)
        if (!is_file($caminho_completo)) {
            registrar_log_erro("Deletar: caminho não é arquivo válido ({$caminho_completo})");
            return false;
        }
        
        // Deleta arquivo
        if (!unlink($caminho_completo)) {
            registrar_log_erro("Deletar: erro ao deletar arquivo ({$caminho_completo})");
            return false;
        }
        
        registrar_log_erro("Arquivo deletado: {$caminho}");
        
        return true;
    }
    
    /**
     * Método PRIVADO: gerar_nome_arquivo()
     * Responsabilidade: Gerar nome único e seguro para arquivo
     * Parâmetros: $extensao (string)
     * Retorna: string com nome do arquivo
     * 
     * Formato: prefixo_data_aleatorio.extensao
     * Exemplo: cliente_123_20260214_a7f3e9b2.jpg
     */
    private function gerar_nome_arquivo($extensao) {
        // Data e hora para evitar duplicação
        $timestamp = date('YmdHis');
        
        // String aleatória para mais segurança
        $aleatorio = bin2hex(random_bytes(4));
        
        // Monta nome
        $nome = $this->prefixo_arquivo . $timestamp . '_' . $aleatorio . '.' . strtolower($extensao);
        
        return $nome;
    }
    
    /**
     * Método PRIVADO: sanitizar_nome_arquivo()
     * Responsabilidade: Sanitizar nome original do arquivo
     * Parâmetros: $nome (string)
     * Retorna: string com nome sanitizado
     * 
     * Remove caracteres perigosos, acentos, espaços
     */
    private function sanitizar_nome_arquivo($nome) {
        // Remove extensão
        $partes = explode('.', $nome);
        $extensao = strtolower(array_pop($partes));
        
        // Junta nome sem extensão
        $nome_base = implode('.', $partes);
        
        // Remove caracteres especiais
        $nome_base = preg_replace('/[^a-zA-Z0-9._-]/', '_', $nome_base);
        
        // Remove múltiplos underscores
        $nome_base = preg_replace('/_+/', '_', $nome_base);
        
        // Limite de caracteres
        $nome_base = substr($nome_base, 0, 200);
        
        return $nome_base . '.' . $extensao;
    }
    
    /**
     * Método PRIVADO: validar_tipo_mime()
     * Responsabilidade: Validar MIME type para segurança
     * Parâmetros: $extensao, $mime_type
     * Retorna: bool
     * 
     * Mapeia tipos MIME esperados por extensão
     */
    private function validar_tipo_mime($extensao, $mime_type) {
        // Mapa de extensões para MIME types esperados
        $mapa_mime = [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'pdf' => ['application/pdf'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'xls' => ['application/vnd.ms-excel'],
            'csv' => ['text/csv', 'application/csv']
        ];
        
        // Se extensão não tem MIME definido, aceita
        if (!array_key_exists($extensao, $mapa_mime)) {
            return true;
        }
        
        // Verifica se MIME type está na lista permitida
        return in_array($mime_type, $mapa_mime[$extensao]);
    }
    
    /**
     * Método PRIVADO: comprimir_imagem()
     * Responsabilidade: Comprimir imagem para economizar espaço
     * Parâmetros: $caminho (string), $extensao (string)
     * Retorna: bool
     * 
     * Nota: Requer extensão GD do PHP
     */
    private function comprimir_imagem($caminho, $extensao) {
        // Verifica se GD está disponível
        if (!extension_loaded('gd')) {
            return false;
        }
        
        try {
            // Carrega imagem baseado na extensão
            switch ($extensao) {
                case 'jpg':
                case 'jpeg':
                    $imagem = imagecreatefromjpeg($caminho);
                    break;
                case 'png':
                    $imagem = imagecreatefrompng($caminho);
                    break;
                case 'gif':
                    $imagem = imagecreatefromgif($caminho);
                    break;
                default:
                    return false;
            }
            
            if (!$imagem) {
                return false;
            }
            
            // Salva imagem comprimida
            if ($extensao == 'png') {
                imagepng($imagem, $caminho, 8);
            } else {
                imagejpeg($imagem, $caminho, IMAGE_COMPRESSION_QUALITY);
            }
            
            // Libera memória
            imagedestroy($imagem);
            
            return true;
        } catch (Exception $e) {
            registrar_log_erro("Erro ao comprimir imagem: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Método: obter_url()
     * Responsabilidade: Converter caminho relativo em URL
     * Parâmetros: $caminho (string com caminho relativo)
     * Retorna: string com URL completa
     * 
     * Uso:
     *   $url = $upload->obter_url('/uploads/clientes/foto.jpg');
     *   // Retorna: https://seu-dominio.com/public/uploads/clientes/foto.jpg
     */
    public function obter_url($caminho) {
        if (empty($caminho)) {
            return '';
        }
        
        // Remove barra inicial se houver
        $caminho = ltrim($caminho, '/');
        
        return BASE_URL . '/' . $caminho;
    }
    
    /**
     * Método: verificar_arquivo()
     * Responsabilidade: Verificar se arquivo existe
     * Parâmetros: $caminho (string com caminho relativo)
     * Retorna: bool
     * 
     * Uso:
     *   if ($upload->verificar_arquivo('/uploads/clientes/foto.jpg')) {
     *       // Arquivo existe
     *   }
     */
    public function verificar_arquivo($caminho) {
        if (empty($caminho)) {
            return false;
        }
        
        $caminho_completo = BASE_PATH . $caminho;
        
        return file_exists($caminho_completo) && is_file($caminho_completo);
    }
    
    /**
     * Método: obter_tamanho_arquivo()
     * Responsabilidade: Obter tamanho de arquivo em bytes
     * Parâmetros: $caminho (string com caminho relativo)
     * Retorna: int com tamanho em bytes ou false
     * 
     * Uso:
     *   $tamanho = $upload->obter_tamanho_arquivo('/uploads/clientes/foto.jpg');
     */
    public function obter_tamanho_arquivo($caminho) {
        if (!$this->verificar_arquivo($caminho)) {
            return false;
        }
        
        $caminho_completo = BASE_PATH . $caminho;
        
        return filesize($caminho_completo);
    }
    
    /**
     * Método: formatar_tamanho()
     * Responsabilidade: Formatar tamanho em bytes para legível (KB, MB, GB)
     * Parâmetros: $bytes (int)
     * Retorna: string formatada
     * 
     * Uso:
     *   echo $upload->formatar_tamanho(5242880); // Retorna "5 MB"
     */
    public static function formatar_tamanho($bytes) {
        if ($bytes == 0) {
            return '0 B';
        }
        
        $unidades = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = abs($bytes);
        $i = 0;
        
        while ($bytes >= 1024 && $i < count($unidades) - 1) {
            $bytes /= 1024;
            $i++;
        }
        
        return round($bytes, 2) . ' ' . $unidades[$i];
    }
}

?>
