<?php

namespace App\Http\Controllers;

use App\Services\GdprExportService;
use Illuminate\Http\Response;

class GdprExportController extends Controller
{
    public function __invoke(GdprExportService $service): Response
    {
        $data     = $service->export(auth()->user());
        $filename = 'mina-uppgifter-' . now()->format('Y-m-d') . '.json';

        return response(
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            200,
            [
                'Content-Type'        => 'application/json; charset=utf-8',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ]
        );
    }
}
