<?php

class ReportController extends Controller
{
    public function index(): void
    {
        $this->requirePermission('reports.view');
        $this->view('reports/index', ['title' => 'Reports']);
    }

    public function export(): void
    {
        $this->requirePermission('reports.export');
        Response::redirect('/reports');
    }
}
