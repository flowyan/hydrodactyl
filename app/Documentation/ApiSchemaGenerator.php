<?php

namespace Pterodactyl\Documentation;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Illuminate\Support\Facades\Route;
use Knuckles\Camel\Output\OutputEndpointData;
use Pterodactyl\Transformers\Api\Application\BaseTransformer;
use Knuckles\Scribe\Writing\OpenApiSpecGenerators\OpenApiGenerator;

/**
 * Adds response contracts from the code that produces them, without making API calls.
 * Unrecognised responses are left for explicit Scribe response annotations.
 */
class ApiSchemaGenerator extends OpenApiGenerator
{
    private array $schemas = [];

    private array $methods = [];

    public function root(array $root, array $groupedEndpoints): array
    {
        $root['servers'] = [['url' => '/']];
        $root['components']['schemas'] = array_merge($this->commonSchemas(), $this->schemas);

        return $root;
    }

    public function pathItem(array $pathItem, array $groupedEndpoints, OutputEndpointData $endpoint): array
    {
        $method = $this->controllerMethod($endpoint);
        if (!$method) {
            return $pathItem;
        }

        $source = $this->methodSource($method);
        $verb = $endpoint->httpMethods[0];
        $status = $this->successStatus($source, $verb);
        $schema = $this->responseSchema($method, $source);
        $pathItem['responses'] = (array) ($pathItem['responses'] ?? []);
        if (isset($pathItem['requestBody'])) {
            $this->normalizeEmptyProperties($pathItem['requestBody']);
        }

        // Explicit @response metadata takes precedence when static analysis cannot
        // describe a controller's output.
        if ($status === 204) {
            $pathItem['responses']['204'] = ['description' => 'No content'];
        } elseif ($schema) {
            $pathItem['responses'][(string) $status] = [
                'description' => $status === 201 ? 'Created' : ($status === 202 ? 'Accepted' : 'Success'),
                'content' => ['application/json' => ['schema' => $schema]],
            ];
        }

        $pathItem['responses'] += [
            '401' => $this->errorResponse('Authentication required', 'AuthenticationError'),
            '403' => $this->errorResponse('Forbidden', 'ApiError'),
            '404' => $this->errorResponse('Not found', 'ApiError'),
            '429' => $this->errorResponse('Too many requests', 'ApiError'),
        ];
        if ($verb !== 'GET' && $verb !== 'HEAD') {
            $pathItem['responses'] += [
                '400' => $this->errorResponse('Bad request', 'ApiError'),
                '422' => $this->errorResponse('Validation failed', 'ValidationError'),
            ];
        }

        $this->addQueryParameters($pathItem, $source, $verb);

        return $pathItem;
    }

    private function controllerMethod(OutputEndpointData $endpoint): ?\ReflectionMethod
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $routeShape = preg_replace('/\{[^}]+\}/', '{}', $route->uri());
            $endpointShape = preg_replace('/\{[^}]+\}/', '{}', $endpoint->uri);
            if ($routeShape !== $endpointShape || !in_array($endpoint->httpMethods[0], $route->methods())) {
                continue;
            }
            $action = $route->getActionName();
            if (!str_contains($action, '@')) {
                $action .= '@__invoke';
            }
            [$class, $method] = explode('@', $action, 2);

            if ($class === 'Closure') {
                return null;
            }

            return method_exists($class, $method) ? new \ReflectionMethod($class, $method) : null;
        }

        return null;
    }

    private function methodSource(\ReflectionMethod $method): string
    {
        $lines = file($method->getFileName());

        return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }

    private function successStatus(string $source, string $verb): int
    {
        if (str_contains($source, 'returnNoContent(') || str_contains($source, 'HTTP_NO_CONTENT') || preg_match('/respond\(\s*204\s*\)|(?:JsonResponse|response)\(\s*(?:\[\s*\]|[\'\"]{2})\s*,\s*204\s*\)/', $source)) {
            return 204;
        }
        if (str_contains($source, 'HTTP_ACCEPTED') || preg_match('/(?:respond|JsonResponse)\(\s*(?:\[\s*\]\s*,\s*)?202\s*\)/', $source)) {
            return 202;
        }
        if (str_contains($source, 'HTTP_CREATED') || preg_match('/(?:respond|JsonResponse)\(\s*(?:\[\s*\]\s*,\s*)?201\s*\)/', $source)) {
            return 201;
        }

        return 200;
    }

    private function responseSchema(\ReflectionMethod $method, string $source): ?array
    {
        $transformer = $this->transformerClass($method, $source);
        if ($transformer) {
            $name = (new \ReflectionClass($transformer))->getShortName();
            $name = str_contains($transformer, '\\Client\\') ? 'Client' . $name : 'Application' . $name;
            if (!isset($this->schemas[$name])) {
                $this->schemas[$name] = $this->transformerSchema($transformer);
            }
            $resource = (new \ReflectionClass($transformer))->newInstanceWithoutConstructor()->getResourceName();
            $itemName = $name . 'Resource';
            $this->schemas[$itemName] ??= [
                'type' => 'object',
                'required' => ['object', 'attributes'],
                'properties' => [
                    'object' => ['type' => 'string', 'enum' => [$resource]],
                    'attributes' => ['$ref' => '#/components/schemas/' . $name],
                    'relationships' => ['type' => 'object', 'additionalProperties' => true],
                ],
            ];

            if (preg_match('/->collection\s*\(/', $source)) {
                $listName = $name . 'List';
                $this->schemas[$listName] ??= [
                    'type' => 'object',
                    'required' => ['object', 'data'],
                    'properties' => [
                        'object' => ['type' => 'string', 'enum' => ['list']],
                        'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/' . $itemName]],
                        'meta' => ['$ref' => '#/components/schemas/PaginationMeta'],
                    ],
                ];

                return ['$ref' => '#/components/schemas/' . $listName];
            }

            return ['$ref' => '#/components/schemas/' . $itemName];
        }

        // Literal response arrays also appear in JsonResponse and response()->json().
        $finder = new NodeFinder();
        $returns = $finder->find($this->methodNodes($method), fn ($node) => $node instanceof Node\Stmt\Return_);
        foreach ($returns as $return) {
            if (!$return instanceof Node\Stmt\Return_) {
                continue;
            }
            $array = $this->responseArray($return->expr);
            if ($array && $array->items) {
                $controller = str_replace('Pterodactyl\\Http\\Controllers\\Api\\', '', $method->getDeclaringClass()->getName());
                $name = str_replace('\\', '', $controller) . ucfirst($method->getName()) . 'Response';
                $this->schemas[$name] = $this->arraySchema($array);

                return ['$ref' => '#/components/schemas/' . $name];
            }
        }

        return null;
    }

    private function responseArray(?Node\Expr $expression): ?Node\Expr\Array_
    {
        if ($expression instanceof Node\Expr\Array_) {
            return $expression;
        }
        if ($expression instanceof Node\Expr\New_ && $expression->class instanceof Node\Name && $expression->class->getLast() === 'JsonResponse') {
            return ($expression->args[0]->value ?? null) instanceof Node\Expr\Array_ ? $expression->args[0]->value : null;
        }
        if ($expression instanceof Node\Expr\MethodCall && $expression->name instanceof Node\Identifier && $expression->name->toString() === 'json') {
            return ($expression->args[0]->value ?? null) instanceof Node\Expr\Array_ ? $expression->args[0]->value : null;
        }

        return null;
    }

    private function transformerClass(\ReflectionMethod $method, string $source): ?string
    {
        if (preg_match('/->transformWith\s*\(\s*\$this->(\w+)\s*\)/', $source, $property)) {
            $reflection = $method->getDeclaringClass();
            if ($reflection->hasProperty($property[1])) {
                $type = $reflection->getProperty($property[1])->getType();
                if ($type instanceof \ReflectionNamedType && is_subclass_of($type->getName(), BaseTransformer::class)) {
                    return $type->getName();
                }
            }
        }
        if (!preg_match('/(?:getTransformer|transformWith|makeTransformer)\s*\(\s*(\w+Transformer)::class/', $source, $match)) {
            return null;
        }
        $file = file_get_contents($method->getFileName());
        if (preg_match('/^use\s+([^;\\n]*\\\\' . $match[1] . ');/m', $file, $import) && is_subclass_of($import[1], BaseTransformer::class)) {
            return $import[1];
        }

        return null;
    }

    private function transformerSchema(string $transformer): array
    {
        $method = new \ReflectionMethod($transformer, 'transform');
        $modelType = $method->getParameters()[0]->getType();
        $modelProperties = [];
        if ($modelType instanceof \ReflectionNamedType && class_exists($modelType->getName())) {
            $modelDoc = (new \ReflectionClass($modelType->getName()))->getDocComment() ?: '';
            preg_match_all('/@property\s+([^\s]+)\s+\$(\w+)/', $modelDoc, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $modelProperties[$match[2]] = $match[1];
            }
        }
        foreach ($this->methodNodes($method) as $node) {
            if ($node instanceof Node\Stmt\Return_ && $node->expr instanceof Node\Expr\Array_) {
                return $this->arraySchema($node->expr, $modelProperties, $method->getParameters()[0]->getName());
            }
        }

        return ['type' => 'object', 'additionalProperties' => true];
    }

    private function methodNodes(\ReflectionMethod $method): array
    {
        $key = $method->getFileName() . ':' . $method->getName();
        if (!isset($this->methods[$key])) {
            $parser = (new ParserFactory())->createForNewestSupportedVersion();
            $ast = $parser->parse(file_get_contents($method->getFileName()));
            $finder = new NodeFinder();
            $methods = $finder->find($ast, fn ($node) => $node instanceof Node\Stmt\ClassMethod && $node->name->toString() === $method->getName());
            $this->methods[$key] = isset($methods[0]) && $methods[0] instanceof Node\Stmt\ClassMethod ? $methods[0]->stmts : [];
        }

        return $this->methods[$key];
    }

    private function arraySchema(Node\Expr\Array_ $array, array $modelProperties = [], ?string $modelVariable = null): array
    {
        if (!$array->items || $array->items[0]->key === null || $array->items[0]->key instanceof Node\Scalar\Int_) {
            return ['type' => 'array', 'items' => ['description' => 'Array item returned by the API.']];
        }
        $properties = [];
        foreach ($array->items as $item) {
            if (!$item->key instanceof Node\Scalar\String_) {
                continue;
            }
            $properties[$item->key->value] = $this->valueSchema($item->value, $modelProperties, $modelVariable);
        }

        $schema = ['type' => 'object', 'properties' => $properties ?: new \stdClass()];
        if ($properties) {
            $schema['required'] = array_keys($properties);
        }

        return $schema;
    }

    private function valueSchema(Node\Expr $value, array $modelProperties = [], ?string $modelVariable = null): array
    {
        if ($value instanceof Node\Expr\Array_) {
            return $this->arraySchema($value, $modelProperties, $modelVariable);
        }
        if ($value instanceof Node\Expr\PropertyFetch && $value->var instanceof Node\Expr\Variable && $value->var->name === $modelVariable && $value->name instanceof Node\Identifier) {
            $type = $modelProperties[$value->name->toString()] ?? null;
            if ($type) {
                $nullable = str_contains($type, 'null');
                $type = str_replace(['|null', 'null|'], '', $type);
                $schema = match ($type) {
                    'int', 'integer' => ['type' => 'integer'],
                    'bool', 'boolean' => ['type' => 'boolean'],
                    'float', 'double' => ['type' => 'number'],
                    'string' => ['type' => 'string'],
                    default => [],
                };
                if ($nullable) {
                    $schema['nullable'] = true;
                }

                return $schema;
            }
        }
        if ($value instanceof Node\Scalar\String_ || $value instanceof Node\Expr\Cast\String_) {
            return ['type' => 'string'];
        }
        if ($value instanceof Node\Scalar\Int_ || $value instanceof Node\Expr\Cast\Int_) {
            return ['type' => 'integer'];
        }
        if ($value instanceof Node\Scalar\Float_ || $value instanceof Node\Expr\Cast\Double) {
            return ['type' => 'number'];
        }
        if ($value instanceof Node\Expr\Cast\Bool_ || $value instanceof Node\Expr\BooleanNot || $value instanceof Node\Expr\BinaryOp\BooleanAnd || $value instanceof Node\Expr\BinaryOp\Identical) {
            return ['type' => 'boolean'];
        }
        if ($value instanceof Node\Expr\ConstFetch) {
            return match (strtolower($value->name->toString())) {
                'true', 'false' => ['type' => 'boolean'],
                'null' => ['nullable' => true],
                default => ['description' => 'Value returned by the API.'],
            };
        }
        if ($value instanceof Node\Expr\MethodCall && $value->name instanceof Node\Identifier && in_array($value->name->toString(), ['formatTimestamp', 'toAtomString', 'toIso8601String'])) {
            return ['type' => 'string', 'format' => 'date-time'];
        }

        // A dynamic value's type is deliberately unconstrained; its field name
        // still comes from the actual transformer return array.
        return ['description' => 'Value returned by the API.'];
    }

    private function normalizeEmptyProperties(array &$value): void
    {
        foreach ($value as $key => &$child) {
            if ($key === 'properties' && $child === []) {
                $child = new \stdClass();
            } elseif (is_array($child)) {
                $this->normalizeEmptyProperties($child);
            }
        }
    }

    private function addQueryParameters(array &$pathItem, string $source, string $verb): void
    {
        if ($verb !== 'GET') {
            return;
        }
        $names = [];
        if (str_contains($source, 'paginate(')) {
            $names['page'] = ['type' => 'integer', 'minimum' => 1];
            $names['per_page'] = ['type' => 'integer', 'minimum' => 1];
        }
        foreach (['allowedFilters' => 'filter', 'allowedSorts' => 'sort'] as $call => $name) {
            if (preg_match('/->' . $call . '\s*\(\s*\[(.*?)\]\s*\)/s', $source, $match)) {
                preg_match_all('/[\'\"]([a-zA-Z_][a-zA-Z0-9_]*)[\'\"]/', $match[1], $values);
                if ($values[1]) {
                    $values = array_unique($values[1]);
                    if ($name === 'filter') {
                        foreach ($values as $field) {
                            $names['filter[' . $field . ']'] = ['type' => 'string'];
                        }
                    } else {
                        $names[$name] = ['type' => 'string', 'description' => 'Comma-separated sort fields (prefix - for descending): ' . implode(', ', $values)];
                    }
                }
            }
        }
        foreach ($names as $name => $schema) {
            if (!collect($pathItem['parameters'] ?? [])->contains(fn ($parameter) => ($parameter['name'] ?? null) === $name)) {
                $pathItem['parameters'][] = ['name' => $name, 'in' => 'query', 'required' => false, 'schema' => $schema];
            }
        }
    }

    private function errorResponse(string $description, string $schema): array
    {
        return ['description' => $description, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $schema]]]];
    }

    private function commonSchemas(): array
    {
        $error = ['type' => 'object', 'required' => ['code', 'status', 'detail'], 'properties' => [
            'code' => ['type' => 'string'], 'status' => ['type' => 'string'], 'detail' => ['type' => 'string'],
            'meta' => ['type' => 'object', 'additionalProperties' => true],
        ]];

        return [
            'ApiErrorItem' => $error,
            'ApiError' => ['type' => 'object', 'required' => ['errors'], 'properties' => ['errors' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ApiErrorItem']]]],
            'AuthenticationError' => ['$ref' => '#/components/schemas/ApiError'],
            'ValidationErrorItem' => ['allOf' => [
                ['$ref' => '#/components/schemas/ApiErrorItem'],
                ['type' => 'object', 'required' => ['meta'], 'properties' => ['meta' => [
                    'type' => 'object', 'required' => ['source_field', 'rule'],
                    'properties' => ['source_field' => ['type' => 'string'], 'rule' => ['type' => 'string']],
                ]]],
            ]],
            'ValidationError' => ['type' => 'object', 'required' => ['errors'], 'properties' => ['errors' => [
                'type' => 'array', 'items' => ['$ref' => '#/components/schemas/ValidationErrorItem'],
            ]]],
            'PaginationMeta' => ['type' => 'object', 'properties' => ['pagination' => ['type' => 'object', 'properties' => [
                'total' => ['type' => 'integer'], 'count' => ['type' => 'integer'], 'per_page' => ['type' => 'integer'],
                'current_page' => ['type' => 'integer'], 'total_pages' => ['type' => 'integer'],
                'links' => ['type' => 'object', 'properties' => ['previous' => ['type' => 'string'], 'next' => ['type' => 'string']]],
            ]]]],
        ];
    }
}
