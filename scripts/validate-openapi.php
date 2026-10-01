<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

$spec = Yaml::parseFile($argv[1] ?? dirname(__DIR__) . '/public/docs/openapi.yaml');
$errors = [];
$check = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) {
        $errors[] = $message;
    }
};

$check(($spec['openapi'] ?? null) === '3.0.3', 'Expected OpenAPI 3.0.3.');
$check(($spec['servers'][0]['url'] ?? null) === '/', 'Server must be portable across deployments.');
$check(!isset($spec['paths']['/api/docs']), 'Scalar UI must not be an API operation.');
$schemas = $spec['components']['schemas'] ?? [];
$check(count($schemas) > 10, 'Reusable response schemas are missing.');

foreach (['/api/application/users', '/api/application/servers', '/api/client/account', '/api/client/servers/wings/{server_uuid}'] as $path) {
    $check(isset($spec['paths'][$path]), "Missing representative path: $path");
}

$walk = static function (mixed $value, string $location) use (&$walk, $spec, $check): void {
    if (!is_array($value)) {
        return;
    }
    if (isset($value['$ref'])) {
        $ref = $value['$ref'];
        $check(str_starts_with($ref, '#/components/schemas/'), "Unexpected reference at $location: $ref");
        $name = substr($ref, strlen('#/components/schemas/'));
        $check(isset($spec['components']['schemas'][$name]), "Unresolved reference at $location: $ref");
    }
    foreach ($value as $key => $child) {
        $walk($child, "$location/$key");
    }
};
$walk($spec, '#');

$operations = 0;
$successes = 0;
$pathShapes = [];
foreach ($spec['paths'] ?? [] as $path => $item) {
    $shape = preg_replace('/\{[^}]+\}/', '{}', $path);
    $check(!isset($pathShapes[$shape]), "Duplicate templated path: $path");
    $pathShapes[$shape] = true;
    preg_match_all('/\{([^}]+)\}/', $path, $matches);
    $parameters = $item['parameters'] ?? [];
    foreach ($matches[1] as $name) {
        $check((bool) array_filter($parameters, fn ($parameter) => ($parameter['in'] ?? null) === 'path' && ($parameter['name'] ?? null) === $name), "Missing path parameter $name on $path");
    }
    foreach ($item as $method => $operation) {
        if (!in_array($method, ['get', 'post', 'put', 'patch', 'delete'])) {
            continue;
        }
        ++$operations;
        $check(isset($operation['responses']) && is_array($operation['responses']), "Missing responses on $method $path");
        foreach ($operation['responses'] ?? [] as $status => $response) {
            if ((int) $status >= 200 && (int) $status < 300) {
                ++$successes;
                break;
            }
        }
    }
}

foreach (['/api/application/users', '/api/application/servers', '/api/client/account'] as $path) {
    $response = $spec['paths'][$path]['get']['responses'][200] ?? [];
    $check(isset($response['content']['application/json']['schema']['$ref']), "Missing typed 200 response on GET $path");
}
$check($successes >= (int) ceil($operations * 0.8), 'Successful responses are missing from more than 20% of operations.');

if ($errors) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}

echo "Validated $operations operations, $successes successful responses, and " . count($schemas) . " schemas.\n";
