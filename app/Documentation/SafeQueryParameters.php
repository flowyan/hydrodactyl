<?php

namespace Pterodactyl\Documentation;

use Illuminate\Routing\Route;
use Knuckles\Scribe\Tools\ConsoleOutputUtils as Console;
use Knuckles\Scribe\Extracting\Strategies\QueryParameters\GetFromFormRequest;

class SafeQueryParameters extends GetFromFormRequest
{
    public function getParametersFromFormRequest(\ReflectionFunctionAbstract $method, Route $route): array
    {
        try {
            return parent::getParametersFromFormRequest($method, $route);
        } catch (\Throwable $exception) {
            Console::warn("Query rules unavailable for {$route->uri()}: {$exception->getMessage()}");

            return [];
        }
    }
}
