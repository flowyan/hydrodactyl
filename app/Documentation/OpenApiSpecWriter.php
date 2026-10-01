<?php

namespace Pterodactyl\Documentation;

use Knuckles\Scribe\Writing\OpenAPISpecWriter as ScribeOpenApiSpecWriter;

/** Laravel's {model:column} route syntax is not an OpenAPI path parameter. */
class OpenApiSpecWriter extends ScribeOpenApiSpecWriter
{
    public function generateSpecContent(array $groupedEndpoints): array
    {
        $spec = parent::generateSpecContent($groupedEndpoints);
        $paths = [];
        $canonical = [];
        foreach ($spec['paths'] as $path => $item) {
            $path = preg_replace('/\{([^}:]+):[^}]+\}/', '{$1}', $path);
            $shape = preg_replace('/\{[^}]+\}/', '{}', $path);
            $canonical[$shape] ??= $path;
            $path = $canonical[$shape];
            if (!isset($paths[$path])) {
                $paths[$path] = $item;
                continue;
            }
            foreach ($item as $key => $operation) {
                if ($key !== 'parameters') {
                    $paths[$path][$key] = $operation;
                }
            }
        }
        $spec['paths'] = $paths;

        return $spec;
    }
}
