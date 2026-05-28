<?php
// Якщо змінна $pageTitle не задана, використовуємо стандартну назву
$pageTitle = $pageTitle ?? 'VibeMatch';
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= htmlspecialchars($pageTitle) ?></title>
<link rel="stylesheet" href="../public/assets/styles.css">
<link rel="icon" type="image/png" href="assets/favicon2.png?v=2">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">