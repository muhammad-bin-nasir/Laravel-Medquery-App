<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DbBrowserController extends Controller
{
    public function index(): View
    {
        return view('db_browser');
    }

    public function tables(): JsonResponse
    {
        $tables = Schema::getTableListing();
        sort($tables);

        return response()->json([
            'count' => count($tables),
            'tables' => array_values($tables),
        ]);
    }

    public function showTable(string $table): JsonResponse
    {
        $tables = Schema::getTableListing();
        if (!in_array($table, $tables, true)) {
            return response()->json(['detail' => 'Table not found'], 404);
        }

        $columns = Schema::getColumnListing($table);
        $rows = DB::table($table)->get()->map(static fn ($row): array => (array) $row)->values();

        return response()->json([
            'table' => $table,
            'count' => $rows->count(),
            'columns' => $columns,
            'rows' => $rows,
        ]);
    }
}
