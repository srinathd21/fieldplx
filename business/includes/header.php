<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'database.php';
require_once 'theme-loader.php';

$pageTitle = $pageTitle ?? 'FieldPlx';
$pageDescription = $pageDescription ?? 'FieldPlx application';
$activeMenu = $activeMenu ?? '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="<?= htmlspecialchars($pageDescription) ?>">
  <title><?= htmlspecialchars($pageTitle) ?></title>

  <script>
  (function () {
    try {
      if (localStorage.getItem('fieldplx_dark_mode') === '1') {
        document.documentElement.classList.add('app-dark-mode');
      }
    } catch (e) {}
  })();
  </script>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Lato:wght@300;400;700;900&family=Montserrat:wght@300;400;500;600;700;800;900&family=Nunito:wght@300;400;500;600;700;800;900&family=Open+Sans:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800;900&family=Raleway:wght@300;400;500;600;700;800;900&family=Roboto:wght@300;400;500;700;900&display=swap" rel="stylesheet">
  <script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>
  <link rel="stylesheet" href="assets/css/theme.css.php?v=<?= time() ?>">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div id="appShell" class="app-shell">
  <header class="brand-bar">
    <svg class="brand-mark" viewBox="0 0 64 78" aria-hidden="true">
      <polygon points="7,17 29,5 29,34 7,46" fill="#078ebd"/>
      <polygon points="33,5 57,17 33,31" fill="#005e8f"/>
      <polygon points="33,35 57,22 57,49 33,62" fill="#1bc8cb"/>
      <polygon points="7,51 29,39 29,73" fill="#0872a5"/>
    </svg>
    <span class="brand-name">FieldPlx</span>
  </header>

  <?php require __DIR__ . '/nav.php'; ?>
  <?php require __DIR__ . '/sidebar.php'; ?>

  <main class="main-wrap">
    <section class="workspace">
