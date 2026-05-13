<?php
echo "<pre>";
echo "Conteúdo do sidebar.php:\n\n";
$content = file_get_contents('includes/sidebar.php');
echo htmlspecialchars($content);
echo "</pre>";
?>