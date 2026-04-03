<?php
// Settings and Configurations
$storageFilePath = 'data/storage.json';

// Load JSON Data
function loadData($filePath) {
    if (!file_exists($filePath)) {
        return [];
    }
    $jsonData = file_get_contents($filePath);
    return json_decode($jsonData, true);
}

// Save JSON Data
function saveData($filePath, $data) {
    file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT));
}

// User and Product Management functions...

?>