<?php
require_once __DIR__ . '/includes/auth.php';

$builderMode = 'create';
$checklistId = 0;

$pageTitle = 'New Checklist · FieldPlx';
$pageDescription = 'Create a reusable checklist';
$settingsActivePage = 'checklists';

require __DIR__ . '/checklist-builder-ui.php';
