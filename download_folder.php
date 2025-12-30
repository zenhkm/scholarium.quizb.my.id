<?php
// Download Google Drive folder as ZIP using service account
// Requires: composer require google/apiclient (library di ../familyhood.my.id/vendor/google/)

// Path to Google API Client autoload
require_once __DIR__ . '/../familyhood.my.id/vendor/autoload.php';

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
                $files = array_merge($files, listFilesRecursive($service, $file->id, $filePath . '/'));
            } else {
                $files[] = ['id' => $file->id, 'name' => $file->name, 'path' => $filePath];
            }
        }
        $pageToken = $response->getNextPageToken();
    } while ($pageToken);
    return $files;
}

$files = listFilesRecursive($service, $folderId);
if (empty($files)) {
    http_response_code(404);
    echo 'No files found in folder.';
    exit;
}

$zip = new ZipArchive();
$tmpZip = tempnam(sys_get_temp_dir(), 'gdrivezip');
$zip->open($tmpZip, ZipArchive::CREATE);
foreach ($files as $file) {
    $content = $service->files->get($file['id'], ['alt' => 'media']);
    $zip->addFromString($file['path'], $content->getBody()->getContents());
}
$zip->close();

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="drive-folder-' . $folderId . '.zip"');
header('Content-Length: ' . filesize($tmpZip));
readfile($tmpZip);
unlink($tmpZip);
exit;
