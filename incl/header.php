<?php
/*
 * Shared page top for all authenticated app pages.
 *
 * Before including, optionally set:
 *   $page_title  — text shown in the browser tab (defaults to "M26")
 *   $extra_head  — raw HTML/CSS injected into <head> for page-specific styles
 *
 * The login page does NOT use this (it has its own centered layout).
 */
$page_title = $page_title ?? 'M26';
$extra_head = $extra_head ?? '';
?>
<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel='icon' href='images/m26.png' type='image/png'>
    <link rel='stylesheet' href='css/styles.css?v=16'/>
    <?php echo $extra_head; ?>
</head>
<body>
<div class='page-wrapper'>
    <?php include_once __DIR__ . '/sidebar.php'; ?>
    <div class='main-content'>
