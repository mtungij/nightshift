<?php

namespace App\Http\Controllers;

use App\Services\MyOrdersService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class MyOrdersController extends Controller
{
    public function staff(Request $request, MyOrdersService $orders): JsonResponse
    {
        $data = $request->validate(['stock_location_id' => ['required', 'integer', 'min:1']]);

        return $this->json($orders->open($request, (int) $data['stock_location_id']));
    }

    public function verify(Request $request, MyOrdersService $orders): JsonResponse
    {
        $data = $request->validate(['staff_id' => ['required', 'integer', 'min:1'], 'pin' => ['required', 'string', 'regex:/^[0-9]{4}$/D']]);

        return $this->json($orders->verify($request, (int) $data['staff_id'], $data['pin']));
    }

    public function index(Request $request, MyOrdersService $orders): JsonResponse
    {
        $data = $request->validate(['filter' => ['sometimes', Rule::in(['all', 'unpaid', 'paid'])], 'page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);

        return $this->json($orders->orders($request, $data['filter'] ?? 'all', (int) ($data['page'] ?? 1)));
    }

    public function show(Request $request, int $id, MyOrdersService $orders): JsonResponse
    {
        return $this->json($orders->orders($request, 'all', 1, $id));
    }

    public function close(Request $request, MyOrdersService $orders): JsonResponse
    {
        $orders->close($request);

        return $this->json(['closed' => true]);
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }
}
