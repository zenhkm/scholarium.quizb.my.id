<?php
// Download Google Drive folder as ZIP using service account
// Requires: composer require google/apiclient (library di ../familyhood.my.id/vendor/google/)

// Locate Composer autoload (try a few known locations)
$autoloadCandidates = [
    __DIR__ . '/../familyhood.my.id/vendor/autoload.php',
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
];
$found = false;
foreach ($autoloadCandidates as $c) {
    if (file_exists($c)) { require_once $c; $found = true; break; }
}
if (!$found) {
    http_response_code(500);
    echo 'Composer autoload not found. Please ensure google/apiclient is installed.';
    exit;
}

// Path to service account JSON
$serviceAccountPath = __DIR__ . '/config/service-account.json';

if (!file_exists($serviceAccountPath)) {
    http_response_code(500);
    echo 'Service account JSON not found.';
    exit;
}

$folderId = isset($_GET['id']) ? $_GET['id'] : '';
if ($folderId === '') {
    http_response_code(400);
    echo 'Missing folder ID.';
    exit;
}

if (!class_exists('Google_Client')) {
    http_response_code(500);
    echo 'Google Client library not loaded.';
    exit;
}

$client = new Google_Client();
$client->setAuthConfig($serviceAccountPath);
$client->addScope(Google_Service_Drive::DRIVE_READONLY);
$service = new Google_Service_Drive($client);

function listFilesRecursive($service, $folderId, $path = '') {
    $files = [];
    $pageToken = null;
    do {
        $response = $service->files->listFiles([
            'q' => "'$folderId' in parents and trashed=false",
            'fields' => 'nextPageToken, files(id, name, mimeType)',
            'pageToken' => $pageToken
        ]);
        foreach ($response->files as $file) {
            $filePath = $path . $file->name;
            if ($file->mimeType === 'application/vnd.google-apps.folder') {
                // include folder marker (empty dir)
                $files[] = ['id' => $file->id, 'name' => $file->name, 'path' => $filePath . '/', 'is_folder' => true];
                $files = array_merge($files, listFilesRecursive($service, $file->id, $filePath . '/'));
            } else {
                $files[] = ['id' => $file->id, 'name' => $file->name, 'path' => $filePath, 'mimeType' => $file->mimeType];
            }
        }
        $pageToken = $response->getNextPageToken();
    } while ($pageToken);
    return $files;
}

// Ensure ZipArchive available and set no time limit for large downloads
if (!class_exists('ZipArchive')) {
    http_response_code(500);
    echo 'PHP ZipArchive extension is required.';
    exit;
}
set_time_limit(0);

// Test we can access folder metadata (permission check)
try {
    $folderMeta = $service->files->get($folderId, ['fields' => 'id,name,mimeType']);
    if ($folderMeta->getMimeType() !== 'application/vnd.google-apps.folder') {
        // It's acceptable if user passes a file ID — still handle
        $folderName = $folderMeta->getName();
    } else {
        $folderName = $folderMeta->getName();
    }
} catch (Exception $e) {
    http_response_code(403);
    echo 'Cannot access folder: ' . htmlspecialchars($e->getMessage());
    echo '\nEnsure the folder is shared with the service account email: ' . htmlspecialchars(getenv('SERVICE_ACCOUNT_EMAIL') ?: 'scholarium@scholarium-482812.iam.gserviceaccount.com');
    exit;
}

$files = listFilesRecursive($service, $folderId);
if (empty($files)) {
    http_response_code(404);
    echo 'No files found in folder.';
    exit;
}

$zip = new ZipArchive();
$tmpZip = tempnam(sys_get_temp_dir(), 'gdrivezip');
if ($zip->open($tmpZip, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    echo 'Failed to create temporary ZIP.';
    exit;
}

// Helper map for Google Docs export types and extensions
function getExportMimeAndExt($gMime) {
    $map = [
        'application/vnd.google-apps.document' => ['mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'ext' => 'docx'],
        'application/vnd.google-apps.spreadsheet' => ['mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'ext' => 'xlsx'],
        'application/vnd.google-apps.presentation' => ['mime' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'ext' => 'pptx'],
        'application/vnd.google-apps.drawing' => ['mime' => 'image/png', 'ext' => 'png'],
    ];
    return $map[$gMime] ?? ['mime' => 'application/pdf', 'ext' => 'pdf'];
}

foreach ($files as $file) {
    if (!empty($file['is_folder'])) {
        // Add directory entry
        $zip->addEmptyDir($file['path']);
        continue;
    }
    try {
        $remoteMime = $file['mimeType'] ?? '';
        if (strpos($remoteMime, 'application/vnd.google-apps') === 0) {
            // Export Google Docs types
            $export = getExportMimeAndExt($remoteMime);
            $resp = $service->files->export($file['id'], $export['mime'], ['alt' => 'media']);
            $data = $resp->getBody()->getContents();
            $entryName = $file['path'] . '.' . $export['ext'];
            $zip->addFromString($entryName, $data);
        } else {
            $resp = $service->files->get($file['id'], ['alt' => 'media']);
            $data = $resp->getBody()->getContents();
            $zip->addFromString($file['path'], $data);
        }
    } catch (Exception $e) {
        // Skip problematic file but record an entry
        $zip->addFromString($file['path'] . '.ERROR.txt', 'Failed to download: ' . $e->getMessage());
    }
}

$zip->close();

// Output ZIP for download with a nicer filename
$safeFolder = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $folderName ?: $folderId);
$zipName = 'drive-folder-' . $safeFolder . '.zip';

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Content-Length: ' . filesize($tmpZip));
readfile($tmpZip);
unlink($tmpZip);
exit;
