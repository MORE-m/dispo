<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class AdministrationAccessController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $this->authorize('access-administration');

        return response()->json([
            'ok' => true,
        ]);
    }
}
