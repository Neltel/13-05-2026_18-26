<?php
echo "<pre>";
echo "Conteúdo do database.php:\n\n";
$content = file_get_contents('../../config/database.php');
echo htmlspecialchars($content);
echo "</pre>";
?>