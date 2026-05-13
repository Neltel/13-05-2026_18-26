<?php
// gerar_recibo_pdf.php
function gerarReciboPDF($pagamento_id, $conn) {
    // Buscar dados do pagamento
    $sql = "SELECT p.*, a.nome, a.documento, a.tipo, a.chave_pix, a.endereco 
            FROM pagamentos_ajudantes p
            JOIN ajudantes a ON p.ajudante_id = a.id
            WHERE p.id = $pagamento_id";
    $result = $conn->query($sql);
    
    if (!$result || $result->num_rows == 0) {
        return false;
    }
    
    $data = $result->fetch_assoc();
    
    // HTML do Recibo
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Recibo de Pagamento</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 40px; }
            .recibo-container { max-width: 700px; margin: 0 auto; border: 1px solid #ddd; padding: 30px; }
            .header { text-align: center; border-bottom: 2px solid #333; padding-bottom: 20px; margin-bottom: 30px; }
            .empresa-nome { font-size: 24px; font-weight: bold; color: #005C97; }
            .recibo-titulo { font-size: 18px; margin-top: 10px; color: #666; }
            .info-pagamento { margin: 30px 0; }
            .info-row { margin: 10px 0; }
            .valor { font-size: 22px; font-weight: bold; color: #28a745; }
            .declaracao { margin-top: 40px; padding: 20px; background: #f8f9fa; font-style: italic; border-left: 4px solid #005C97; }
            .assinaturas { margin-top: 50px; display: flex; justify-content: space-between; }
            .assinatura { text-align: center; margin-top: 40px; }
            .linha-assinatura { width: 200px; border-top: 1px solid #000; margin: 10px auto; }
            .footer { margin-top: 50px; text-align: center; font-size: 12px; color: #999; }
            .dados-empresa { font-size: 12px; color: #666; margin-top: 10px; }
        </style>
    </head>
    <body>
        <div class="recibo-container">
            <div class="header">
                <div class="empresa-nome">IMPÉRIO AR - REFRIGERAÇÃO</div>
                <div class="recibo-titulo">RECIBO DE PAGAMENTO A PRESTADOR DE SERVIÇO</div>
                <div class="dados-empresa">
                    CNPJ: 66.282.593/0001-34<br>
                    Rua Itanhaém, 138 - Vila Anchieta - São José do Rio Preto/SP
                </div>
            </div>
            
            <div class="info-pagamento">
                <div class="info-row"><strong>Nº Recibo:</strong> ' . str_pad($pagamento_id, 6, "0", STR_PAD_LEFT) . '</div>
                <div class="info-row"><strong>Data de Emissão:</strong> ' . date('d/m/Y') . '</div>
                <div class="info-row"><strong>Data do Pagamento:</strong> ' . date('d/m/Y', strtotime($data['data_pagamento'])) . '</div>
                <div class="info-row"><strong>Período do Serviço:</strong> ' . date('d/m/Y', strtotime($data['data_referencia_servico'])) . '</div>
                <div class="info-row"><hr></div>
                
                <div class="info-row"><strong>Recebi(emos) de:</strong> IMPÉRIO AR - REFRIGERAÇÃO</div>
                <div class="info-row"><strong>A quantia de:</strong> <span class="valor">R$ ' . number_format($data['valor'], 2, ',', '.') . '</span></div>
                <div class="info-row"><strong>Referente a:</strong> ' . htmlspecialchars($data['descricao_servico']) . '</div>
                <div class="info-row"><hr></div>
                
                <div class="info-row"><strong>Prestador:</strong> ' . htmlspecialchars($data['nome']) . '</div>
                <div class="info-row"><strong>' . ($data['tipo'] == 'PF' ? 'CPF' : 'CNPJ') . ':</strong> ' . $data['documento'] . '</div>';
    
    if ($data['chave_pix']) {
        $html .= '<div class="info-row"><strong>Chave PIX:</strong> ' . $data['chave_pix'] . '</div>';
    }
    
    $html .= '    <div class="info-row"><strong>Forma de Pagamento:</strong> PIX / Transferência Bancária</div>
            </div>
            
            <div class="declaracao">
                <strong>DECLARAÇÃO DO PRESTADOR:</strong><br>
                Declaro que recebi o valor acima descrito como pagamento pelos serviços prestados, 
                assumindo toda a responsabilidade pela regularidade fiscal do meu trabalho.
                <br><br>
                <strong>Cláusula de Vínculo:</strong> "O presente pagamento refere-se a serviço eventual, 
                sem subordinação ou vínculo empregatício, nos termos da lei."
            </div>
            
            <div class="assinaturas">
                <div class="assinatura">
                    <div class="linha-assinatura"></div>
                    <div>Império AR - Refrigeração</div>
                    <div style="font-size: 12px;">Pagador</div>
                </div>
                <div class="assinatura">
                    <div class="linha-assinatura"></div>
                    <div>' . htmlspecialchars($data['nome']) . '</div>
                    <div style="font-size: 12px;">Prestador de Serviço</div>
                </div>
            </div>
            
            <div class="footer">
                Recibo gerado eletronicamente - Documento com validade jurídica conforme MP 2.200-2/2001
            </div>
        </div>
    </body>
    </html>';
    
    // Tentar usar DomPDF se disponível, senão salvar como HTML
    $pdf_dir = 'uploads/recibos/';
    if (!is_dir($pdf_dir)) {
        mkdir($pdf_dir, 0755, true);
    }
    
    $pdf_filename = "recibo_" . str_pad($pagamento_id, 6, "0", STR_PAD_LEFT) . "_" . date('Ymd') . ".html";
    $pdf_path = $pdf_dir . $pdf_filename;
    
    // Salvar como HTML (funciona sem DomPDF)
    file_put_contents($pdf_path, $html);
    
    // Se tiver DomPDF, converte para PDF (opcional)
    if (file_exists('../../vendor/dompdf/autoload.inc.php')) {
        require_once '../../vendor/dompdf/autoload.inc.php';
        $dompdf = new Dompdf\Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        
        $pdf_path = $pdf_dir . "recibo_" . str_pad($pagamento_id, 6, "0", STR_PAD_LEFT) . "_" . date('Ymd') . ".pdf";
        file_put_contents($pdf_path, $dompdf->output());
    }
    
    return $pdf_path;
}
?>