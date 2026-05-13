<?php
echo "<h2>Conteúdo do importar_extrato.php</h2>";
echo "<pre>";
$content = file_get_contents('importar_extrato.php');
echo htmlspecialchars(substr($content, 0, 3000));
echo "\n\n... (mostrando primeiros 3000 caracteres) ...";
echo "</pre>";
?>