<?php
require_once 'incl/dbconn.php';
require_staff();

if (!isset($_GET['document_id']) || !isset($_GET['site_id'])) {
    header('Location: view_sites.php');
    exit;
}

$document_id = (int)$_GET['document_id'];
$site_id     = (int)$_GET['site_id'];

$stmt = $conn->prepare("SELECT doc_filename FROM site_documents WHERE document_id = ? AND site_id = ?");
$stmt->bind_param('ii', $document_id, $site_id);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($doc) {
    // Delete the file from disk
    $filepath = 'uploads/' . $doc['doc_filename'];
    if (file_exists($filepath)) {
        unlink($filepath);
    }

    $stmt = $conn->prepare("DELETE FROM site_documents WHERE document_id = ?");
    $stmt->bind_param('i', $document_id);
    $stmt->execute();
    $stmt->close();
}

flash('Document deleted.');
header('Location: site_detail.php?site_id=' . $site_id);
exit;
