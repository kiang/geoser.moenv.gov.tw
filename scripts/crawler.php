<?php

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;

// ========== STEP 1: CRAWL DATA ==========

$cities = [
    '南投縣',
    '嘉義縣',
    '基隆市',
    '屏東縣',
    '彰化縣',
    '新竹市',
    '新竹縣',
    '桃園市',
    '臺中市',
    '臺南市',
    '花蓮縣',
    '苗栗縣',
    '雲林縣',
    '高雄市'
];

$client = new Client([
    'timeout' => 30,
    'verify' => false
]);

$baseUrl = 'https://services7.arcgis.com/tVmMUEViFfyHBZvj/arcgis/rest/services/%E5%BB%A2%E6%A3%84%E7%89%A9%E6%A3%84%E7%BD%AE%E5%A0%B4%E5%9D%80_20210929/FeatureServer/0/query?f=json&where=%E7%B8%A3%E5%B8%82%20%3D%20N%27{city}%27&returnGeometry=true&spatialRel=esriSpatialRelIntersects&outFields=OBJECTID%2C%E6%A1%88%E4%BB%B6%E7%B7%A8%E8%99%9F%2C%E7%B8%A3%E5%B8%82%2C%E8%A1%8C%E6%94%BF%E5%8D%80%2C%E5%9C%B0%E5%9D%80%2C%E5%9C%B0%E8%99%9F%2C%E5%A0%B4%E5%9D%80%E5%90%8D%E7%A8%B1%2CLon%2CLat%2C%E5%BB%A2%E6%A3%84%E7%89%A9%E7%A8%AE%E9%A1%9E%2C%E6%9C%80%E5%BE%8C%E6%9B%B4%E6%96%B0%E6%97%A5%2C%E5%9C%9F%E6%B0%B4%E5%88%97%E7%AE%A1%E5%A0%B4%2C%E7%8F%BE%E5%A0%B4%E7%8B%80%E6%85%8B&outSR=102100&resultOffset=0&resultRecordCount=2000';

$rawDir = __DIR__ . '/../raw';

echo "========== CRAWLING DATA ==========\n\n";

foreach ($cities as $city) {
    echo "Fetching data for: {$city}\n";

    $cityEncoded = urlencode($city);
    $url = str_replace('{city}', $cityEncoded, $baseUrl);

    try {
        $response = $client->request('GET', $url);
        $body = $response->getBody()->getContents();

        $filename = "{$rawDir}/{$city}.json";
        file_put_contents($filename, $body);

        echo "Saved to: {$filename}\n";
    } catch (\Exception $e) {
        echo "Error fetching {$city}: " . $e->getMessage() . "\n";
    }

    // Be polite to the server
    sleep(1);
}

echo "\n========== EXTRACTING TO GEOJSON AND CSV ==========\n\n";

// ========== STEP 2: EXTRACT DATA ==========

$jsonOutputFile = __DIR__ . '/../docs/json/points.json';
$csvOutputFile = __DIR__ . '/../docs/csv/points.csv';

// Initialize GeoJSON structure
$geojson = [
    'type' => 'FeatureCollection',
    'features' => []
];

// Array to store all CSV rows
$csvRows = [];
$csvHeaders = null;

// Get all JSON files from raw directory
$jsonFiles = glob($rawDir . '/*.json');

echo "Processing " . count($jsonFiles) . " files...\n";

foreach ($jsonFiles as $file) {
    $city = basename($file, '.json');
    echo "Processing: {$city}\n";

    $content = file_get_contents($file);
    $data = json_decode($content, true);

    if (!isset($data['features'])) {
        echo "  Warning: No features found in {$city}\n";
        continue;
    }

    foreach ($data['features'] as $feature) {
        $attributes = $feature['attributes'];

        // Extract for GeoJSON (OBJECTID, Lon, Lat)
        if (isset($attributes['Lon']) && isset($attributes['Lat']) && isset($attributes['OBJECTID'])) {
            $geojsonFeature = [
                'type' => 'Feature',
                'properties' => [
                    'OBJECTID' => $attributes['OBJECTID']
                ],
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [
                        (float)$attributes['Lon'],
                        (float)$attributes['Lat']
                    ]
                ]
            ];
            $geojson['features'][] = $geojsonFeature;
        }

        // Extract for CSV (all attributes except Lon, Lat)
        $csvRow = [];
        foreach ($attributes as $key => $value) {
            if ($key !== 'Lon' && $key !== 'Lat') {
                $csvRow[$key] = $value;
            }
        }

        // Set CSV headers from first row
        if ($csvHeaders === null) {
            $csvHeaders = array_keys($csvRow);
        }

        $csvRows[] = $csvRow;
    }
}

// Write GeoJSON file
echo "\nWriting GeoJSON to: {$jsonOutputFile}\n";
file_put_contents($jsonOutputFile, json_encode($geojson, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "Total features in GeoJSON: " . count($geojson['features']) . "\n";

// Write CSV file
echo "\nWriting CSV to: {$csvOutputFile}\n";
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
echo "Total rows in CSV: " . count($csvRows) . "\n";

echo "\nDone!\n";
