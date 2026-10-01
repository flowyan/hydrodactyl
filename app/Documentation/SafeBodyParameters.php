<?php

namespace Pterodactyl\Documentation;

use Illuminate\Routing\Route;
use Knuckles\Scribe\Tools\ConsoleOutputUtils as Console;
use Knuckles\Scribe\Extracting\Strategies\BodyParameters\GetFromFormRequest;

class SafeBodyParameters extends GetFromFormRequest
{
    public function getParametersFromFormRequest(\ReflectionFunctionAbstract $method, Route $route): array
    {
        try {
            return parent::getParametersFromFormRequest($method, $route);
        } catch (\Throwable $exception) {
            // Some rules depend on a bound database model. Keep the operation in
            // the spec and let an explicit annotation document those fields.
            Console::warn("Body rules unavailable for {$route->uri()}: {$exception->getMessage()}");

            return [];
        }
    }
}
