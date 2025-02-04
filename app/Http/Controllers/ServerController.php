<?php

declare(strict_types=1);

namespace Kami\Cocktail\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Kami\Cocktail\Search\SearchActionsAdapter;

class ServerController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'status' => 'available'
        ]);
    }

    public function version(SearchActionsAdapter $searchAdapter): JsonResponse
    {
        $search = $searchAdapter->getActions();

        return response()->json([
            'data' => [
                'name' => config('app.name'),
                'version' => config('bar-assistant.version'),
                'type' => config('app.env'),
                'search_host' => $search->getHost(),
                'search_version' => $search->getVersion(),
            ]
        ]);
    }

    public function openApi(): Response
    {
        return response(
            file_get_contents(base_path('docs/open-api-spec.yml')),
            200,
            ['Content-Type' => 'application/x-yaml']
        );
    }
}
