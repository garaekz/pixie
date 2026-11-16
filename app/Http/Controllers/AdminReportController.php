<?php

namespace App\Http\Controllers;

use App\Actions\BuildAdminReport;
use App\Http\Requests\AdminReportRequest;
use Inertia\Inertia;
use Inertia\Response;

class AdminReportController extends Controller
{
    public function __invoke(AdminReportRequest $request, BuildAdminReport $report): Response
    {
        return Inertia::render('Admin/Report', $report->handle($request->validated()));
    }
}
