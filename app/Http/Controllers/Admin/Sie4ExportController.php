<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Accounting\FiscalYear;
use App\Services\Accounting\Sie4Exporter;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class Sie4ExportController extends Controller
{
    public function __invoke(FiscalYear $fiscalYear): Response
    {
        abort_unless(Auth::user()->can('accounting.admin'), 403);

        $exporter = new Sie4Exporter($fiscalYear);
        $content  = $exporter->generate();
        $filename = $exporter->filename();

        return response($content, 200, [
            'Content-Type'        => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length'      => strlen($content),
        ]);
    }
}
