<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('POST');

$data = api_input();
$action = api_text($data, 'action');
$apiKey = trim((string)getenv('GOOGLE_MAPS_API_KEY'));
if ($apiKey === '') api_json(503, ['success' => false, 'error' => 'Address search is not configured yet. Add GOOGLE_MAPS_API_KEY in Railway.']);

function google_places_request(string $url, string $method, array $headers, ?array $payload = null): array
{
    $headerLines = implode("\r\n", $headers);
    $options = ['method' => $method, 'header' => $headerLines, 'timeout' => 8, 'ignore_errors' => true];
    if ($payload !== null) $options['content'] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $result = @file_get_contents($url, false, stream_context_create(['http' => $options]));
    $status = 0;
    foreach (($http_response_header ?? []) as $line) if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $match)) $status = (int)$match[1];
    $decoded = is_string($result) ? json_decode($result, true) : null;
    if ($status < 200 || $status >= 300 || !is_array($decoded)) {
        error_log('Google Places request failed with HTTP ' . $status);
        api_json(503, ['success' => false, 'error' => 'Address search is temporarily unavailable. Please try again.']);
    }
    return $decoded;
}

$headers = ['Content-Type: application/json', 'X-Goog-Api-Key: ' . $apiKey];
if ($action === 'autocomplete') {
    $input = api_text($data, 'input');
    if (mb_strlen($input) < 3 || mb_strlen($input) > 160) api_json(422, ['success' => false, 'error' => 'Enter at least 3 characters to search for an address.']);
    $response = google_places_request(
        'https://places.googleapis.com/v1/places:autocomplete', 'POST',
        array_merge($headers, ['X-Goog-FieldMask: suggestions.placePrediction.placeId,suggestions.placePrediction.text,suggestions.placePrediction.structuredFormat']),
        ['input' => $input, 'includedRegionCodes' => ['ph'], 'locationBias' => ['rectangle' => ['low' => ['latitude' => 15.4400, 'longitude' => 120.6000], 'high' => ['latitude' => 15.5350, 'longitude' => 120.7300]]]]
    );
    $suggestions = [];
    foreach (($response['suggestions'] ?? []) as $suggestion) {
        $place = $suggestion['placePrediction'] ?? null;
        if (!is_array($place) || empty($place['placeId']) || empty($place['text']['text'])) continue;
        $suggestions[] = ['place_id' => $place['placeId'], 'text' => $place['text']['text'], 'primary_text' => $place['structuredFormat']['mainText']['text'] ?? $place['text']['text'], 'secondary_text' => $place['structuredFormat']['secondaryText']['text'] ?? ''];
    }
    api_json(200, ['success' => true, 'suggestions' => $suggestions]);
}

if ($action === 'details') {
    $placeId = api_text($data, 'place_id');
    if (!preg_match('/^[A-Za-z0-9_-]{8,200}$/', $placeId)) api_json(422, ['success' => false, 'error' => 'Choose an address from the search results.']);
    $response = google_places_request(
        'https://places.googleapis.com/v1/places/' . rawurlencode($placeId), 'GET',
        array_merge($headers, ['X-Goog-FieldMask: formattedAddress,location']), null
    );
    $latitude = $response['location']['latitude'] ?? null;
    $longitude = $response['location']['longitude'] ?? null;
    if (!is_numeric($latitude) || !is_numeric($longitude) || empty($response['formattedAddress'])) api_json(422, ['success' => false, 'error' => 'This result has no usable address. Choose another result.']);
    api_json(200, ['success' => true, 'place' => ['address' => $response['formattedAddress'], 'latitude' => (float)$latitude, 'longitude' => (float)$longitude, 'delivery_available' => api_is_delivery_area((float)$latitude, (float)$longitude)]]);
}

api_json(422, ['success' => false, 'error' => 'Unknown address search action.']);