<?php

namespace Pterodactyl\Http\Controllers\Api\Client;

use Illuminate\Http\JsonResponse;

class VersionController extends ClientApiController
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['version' => config('app.version')]);
    }
}
