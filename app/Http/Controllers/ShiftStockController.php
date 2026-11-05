<?php

namespace App\Http\Controllers;

use App\Services\ShiftStockService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

final class ShiftStockController extends Controller
{
    public function show(int $id, ShiftStockService $service): JsonResponse
    {
        return response()->json($service->report($id));
    }

    public function page(int $id, ShiftStockService $service): View
    {
        return view('shift-stock', ['report' => $service->report($id)]);
    }

    public function sheet(int $id, ShiftStockService $service): View
    {
        return view('shift-stock-sheet', ['report' => $service->report($id)]);
    }
}
