<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('POST');

$data = api_input();
$action = api_text($data, 'action');
$apiKey = trim((string)getenv('GEOAPIFY_API_KEY'));
if ($apiKey === '') api_json(503, ['success' => false, 'error' => 'Address search is not configured yet. Add GEOAPIFY_API_KEY in Railway.']);
if ($action !== 'autocomplete') api_json(422, ['success' => false, 'error' => 'Unknown address search action.']);

$input = api_text($data, 'input');
if (mb_strlen($input) < 3 || mb_strlen($input) > 160) api_json(422, ['success' => false, 'error' => 'Enter at least 3 characters to search for an address.']);

$query = http_build_query([
    'text' => $input,
    'format' => 'json',
    'limit' => 6,
    'filter' => 'countrycode:ph',
    // Prefer Tarlac City results while still permitting registration outside the delivery area.
    'bias' => 'rect:120.6000,15.4400,120.7300,15.5350',
    'apiKey' => $apiKey,
]);
$url = 'https://api.geoapify.com/v1/geocode/autocomplete?' . $query;
$context = stream_context_create(['http' => [
    'method' => 'GET',
    'header' => "Accept: application/json\r\nUser-Agent: PiasLaundryShop/1.0\r\n",
    'timeout' => 8,
    'ignore_errors' => true,
]]);
$response = @file_get_contents($url, false, $context);
$status = 0;
foreach (($http_response_header ?? []) as $line) if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $match)) $status = (int)$match[1];
$payload = is_string($response) ? json_decode($response, true) : null;
if ($status < 200 || $status >= 300 || !is_array($payload)) {
    error_log('Geoapify address search failed with HTTP ' . $status);
    if ($status === 401 || $status === 403) api_json(503, ['success' => false, 'error' => 'Geoapify rejected the address-search key. Check GEOAPIFY_API_KEY and its restrictions in Railway.']);
    if ($status === 429) api_json(429, ['success' => false, 'error' => 'Address search reached its Geoapify request limit. Please try again later.']);
    if ($status === 0) api_json(503, ['success' => false, 'error' => 'Could not connect to Geoapify address search. Please try again.']);
    api_json(503, ['success' => false, 'error' => 'Address search is temporarily unavailable. Please try again.']);
}

$suggestions = [];
foreach (($payload['results'] ?? []) as $result) {
    $latitude = $result['lat'] ?? null;
    $longitude = $result['lon'] ?? null;
    $address = trim((string)($result['formatted'] ?? ''));
    if (!is_numeric($latitude) || !is_numeric($longitude) || $address === '') continue;
    $primary = trim((string)($result['address_line1'] ?? $result['name'] ?? $address));
    $secondary = trim((string)($result['address_line2'] ?? ''));
    $suggestions[] = [
        'place_id' => (string)($result['place_id'] ?? sha1($address . '|' . $latitude . '|' . $longitude)),
        'text' => $address,
        'primary_text' => $primary ?: $address,
        'secondary_text' => $secondary,
        'address' => $address,
        'latitude' => (float)$latitude,
        'longitude' => (float)$longitude,
        'delivery_available' => api_is_delivery_area((float)$latitude, (float)$longitude),
    ];
}
api_json(200, ['success' => true, 'suggestions' => $suggestions]);