<?php
require_once __DIR__ . '/bootstrap.php';
api_require_method('POST');

$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($authorization === '' && function_exists('getallheaders')) {
    $headers = getallheaders();
    $authorization = $headers['Authorization'] ?? $headers['authorization'] ?? '';
}
if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/i', trim($authorization), $matches)) {
    api_json(401, ['success' => false, 'error' => 'Please sign in to view this route.']);
}
$tokenHash = hash('sha256', strtolower($matches[1]));
$session = db()->prepare(
    'SELECT 1 FROM customer_sessions WHERE token_hash = ? AND expires_at > NOW() '
    . 'UNION ALL '
    . 'SELECT 1 FROM delivery_driver_sessions WHERE token_hash = ? AND expires_at > NOW() '
    . 'LIMIT 1'
);
$session->execute([$tokenHash, $tokenHash]);
if (!$session->fetchColumn()) {
    api_json(401, ['success' => false, 'error' => 'Your session expired. Please sign in again.']);
}

$data = api_input();
$readPoint = static function ($value): ?array {
    if (!is_array($value)) return null;
    $latitude = filter_var($value['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $longitude = filter_var($value['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($latitude === false || $longitude === false || !is_finite((float)$latitude) || !is_finite((float)$longitude)
        || $latitude < 15.05 || $latitude > 16.25 || $longitude < 119.80 || $longitude > 121.10) {
        return null;
    }
    return ['latitude' => (float)$latitude, 'longitude' => (float)$longitude];
};
$origin = $readPoint($data['origin'] ?? null);
$destination = $readPoint($data['destination'] ?? null);
if (!$origin || !$destination) {
    api_json(422, ['success' => false, 'error' => 'The route points must be inside the Tarlac service area.']);
}

$osrmBase = rtrim(trim((string)getenv('OSRM_BASE_URL')), '/');
if ($osrmBase === '') {
    api_json(503, ['success' => false, 'error' => 'Tarlac routing is not configured yet.']);
}
$query = http_build_query(['overview' => 'full', 'geometries' => 'geojson', 'steps' => 'false']);
$routeUrl = $osrmBase . '/route/v1/driving/'
    . $origin['longitude'] . ',' . $origin['latitude'] . ';'
    . $destination['longitude'] . ',' . $destination['latitude'] . '?' . $query;
$context = stream_context_create(['http' => [
    'method' => 'GET',
    'header' => "Accept: application/json\r\n",
    'timeout' => 8,
    'ignore_errors' => true,
]]);
$response = @file_get_contents($routeUrl, false, $context);
$status = 0;
foreach (($http_response_header ?? []) as $header) {
    if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) $status = (int)$matches[1];
}
$result = is_string($response) ? json_decode($response, true) : null;
if ($status < 200 || $status >= 300 || !is_array($result)) {
    error_log('Private OSRM route request failed with HTTP ' . $status . '.');
    api_json(502, ['success' => false, 'error' => 'The road route is temporarily unavailable.']);
}
$route = $result['routes'][0] ?? null;
if (($result['code'] ?? '') !== 'Ok' || !is_array($route) || !is_array($route['geometry']['coordinates'] ?? null)) {
    api_json(422, ['success' => false, 'error' => 'No drivable route was found between these locations.']);
}
api_json(200, [
    'success' => true,
    'route' => [
        'geometry' => $route['geometry'],
        'distance_meters' => (float)($route['distance'] ?? 0),
        'duration_seconds' => (float)($route['duration'] ?? 0),
    ],
]);
