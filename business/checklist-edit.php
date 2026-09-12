<?php
require_once __DIR__ . '/includes/auth.php';

$builderMode = 'edit';
$checklistId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($checklistId <= 0) {
    header('Location: checklists.php');
    exit;
}

$pageTitle = 'Edit Checklist · FieldPlx';
$pageDescription = 'Edit an existing reusable checklist';
$settingsActivePage = 'checklists';

require __DIR__ . '/checklist-builder-ui.php';
