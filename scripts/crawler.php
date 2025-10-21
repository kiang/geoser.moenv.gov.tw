<?php

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;

$client = new Client([
    'timeout' => 60,
    'verify' => false
]);

$rootDir = __DIR__ . '/..';
$rawDir = "{$rootDir}/raw";
$tmpDir = "{$rootDir}/tmp";
$jsonOutputFile = "{$rootDir}/docs/json/points.json";
$csvOutputFile = "{$rootDir}/docs/csv/points.csv";

// Create tmp directory if it doesn't exist
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0755, true);
}

echo "========== FETCHING DATASET LIST ==========\n\n";

// Fetch the dataset list from MOENV API
$response = $client->request('POST', 'https://data.moenv.gov.tw/api/frontstage/datastore.search', [
    'headers' => [
        'Accept' => 'application/json, text/plain, */*',
        'Content-Type' => 'application/json'
    ],
    'body' => json_encode([
        'resource_id' => '4bc290c9-93f6-4b47-afde-6fce87c91a4b',
        'limit' => 100,
        'offset' => 0
    ])
]);

$data = json_decode($response->getBody()->getContents(), true);

if (!isset($data['payload']['records']) || empty($data['payload']['records'])) {
    die("Error: No records found in API response\n");
}

// Find the record with the largest _id
$latestRecord = null;
$maxId = 0;

foreach ($data['payload']['records'] as $record) {
    if ($record['_id'] > $maxId) {
        $maxId = $record['_id'];
        $latestRecord = $record;
    }
}

echo "Latest dataset:\n";
echo "  ID: {$latestRecord['_id']}\n";
echo "  Filename: {$latestRecord['filename']}\n";
echo "  URL: {$latestRecord['url']}\n\n";

echo "========== DOWNLOADING ZIP FILE ==========\n\n";

// Download the zip file
$zipFile = "{$tmpDir}/data.zip";
$response = $client->request('GET', $latestRecord['url'], [
    'sink' => $zipFile
]);

echo "Downloaded to: {$zipFile}\n\n";

echo "========== EXTRACTING ZIP FILE ==========\n\n";

// Extract the zip file
$zip = new ZipArchive;
if ($zip->open($zipFile) === true) {
    $zip->extractTo($tmpDir);
    $zip->close();
    echo "Extracted to: {$tmpDir}\n\n";
} else {
    die("Error: Failed to extract zip file\n");
}

// Find the .shp file (search recursively)
exec("find {$tmpDir} -name '*.shp' -type f", $shpFiles);
if (empty($shpFiles)) {
    die("Error: No .shp file found in extracted files\n");
}

$shpFile = $shpFiles[0];
echo "Found shapefile: {$shpFile}\n\n";

echo "========== CONVERTING SHAPEFILE TO GEOJSON ==========\n\n";

// Convert shapefile to GeoJSON using ogr2ogr
$geojsonTmpFile = "{$tmpDir}/output.geojson";
exec("ogr2ogr -f GeoJSON -t_srs EPSG:4326 {$geojsonTmpFile} {$shpFile}", $output, $returnCode);

if ($returnCode !== 0) {
    die("Error: Failed to convert shapefile to GeoJSON\n");
}

echo "Converted to GeoJSON\n\n";

echo "========== PROCESSING DATA ==========\n\n";

// Read the converted GeoJSON
$geojsonData = json_decode(file_get_contents($geojsonTmpFile), true);

// Initialize output structures
$geojson = [
    'type' => 'FeatureCollection',
    'features' => []
];

$csvRows = [];
$csvHeaders = null;

// Process each feature
foreach ($geojsonData['features'] as $feature) {
    $properties = $feature['properties'];
    $geometry = $feature['geometry'];

    // Map field names - use Chinese field names
    $id = $properties['案件編號'] ?? null;
    $lon = $properties['Lon'] ?? null;
    $lat = $properties['Lat'] ?? null;

    // Extract for GeoJSON (id from 案件編號, Lon, Lat)
    if ($lon !== null && $lat !== null && $id !== null) {
        $geojsonFeature = [
            'type' => 'Feature',
            'properties' => [
                'id' => $id
            ],
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [
                    (float)$lon,
                    (float)$lat
                ]
            ]
        ];
        $geojson['features'][] = $geojsonFeature;
    }

    // Extract for CSV (all attributes except Lon, Lat)
    // Map 案件編號 to id for consistency
    $csvRow = [];
    foreach ($properties as $key => $value) {
        if ($key !== 'Lon' && $key !== 'Lat') {
            if ($key === '案件編號') {
                $csvRow['id'] = $value;
            } else {
                $csvRow[$key] = $value;
            }
        }
    }

    // Set CSV headers from first row
    if ($csvHeaders === null) {
        $csvHeaders = array_keys($csvRow);
    }

    $csvRows[] = $csvRow;
}

// Write GeoJSON file
echo "Writing GeoJSON to: {$jsonOutputFile}\n";
file_put_contents($jsonOutputFile, json_encode($geojson, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "Total features in GeoJSON: " . count($geojson['features']) . "\n\n";

// Write CSV file
echo "Writing CSV to: {$csvOutputFile}\n";
$csvHandle = fopen($csvOutputFile, 'w');

// Write headers
if ($csvHeaders) {
    fputcsv($csvHandle, $csvHeaders);
}

// Write rows
foreach ($csvRows as $row) {
    $orderedRow = [];
    foreach ($csvHeaders as $header) {
        $orderedRow[] = $row[$header] ?? '';
    }
    fputcsv($csvHandle, $orderedRow);
}

fclose($csvHandle);
echo "Total rows in CSV: " . count($csvRows) . "\n\n";

echo "========== CLEANING UP ==========\n\n";

// Clean up tmp directory recursively
function removeDirectory($path) {
    if (!is_dir($path)) {
        return unlink($path);
    }
    $files = array_diff(scandir($path), ['.', '..']);
    foreach ($files as $file) {
        removeDirectory($path . '/' . $file);
    }
    return rmdir($path);
}

$files = glob("{$tmpDir}/*");
foreach ($files as $file) {
    removeDirectory($file);
}
echo "Cleaned up temporary files\n\n";

echo "Done!\n";
