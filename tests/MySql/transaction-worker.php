<?php

use App\Domain\TenantContext;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\SalesTicketService;
use App\Services\ShiftService;
use App\Services\StockService;
use App\Services\TransactionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../bootstrap-mysql.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'mysql_validation') {
    fwrite(STDERR, "Worker requires mysql_validation.\n");
    exit(1);
}
$request = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$user = User::findOrFail($request['user_id']);
echo "READY\n";
flush();
$deadline = microtime(true) + 15;
while (! is_file($request['barrier'])) {
    if (microtime(true) > $deadline) {
        fwrite(STDERR, "Concurrency barrier timed out.\n");
        exit(1);
    }
    usleep(10000);
}
try {
    $result = app(TenantContext::class)->run($user, function () use ($request) {
        if ($request['action'] === 'issue') {
            return app(OrderService::class)->issue($request['order_id']);
        }
        if ($request['action'] === 'send') {
            $result = app(OrderService::class)->send($request['payload'], $request['key']);
            $batch = app(SalesTicketService::class)->prepare($result['id'], $request['key']);

            return [...$result, 'print_batch' => $batch?->id];
        }
        if ($request['action'] === 'ticket-dispatch') {
            return ['id' => 0, 'print' => app(SalesTicketService::class)->dispatch($request['token'])];
        }
        if ($request['action'] === 'ticket-reprint') {
            return app(SalesTicketService::class)->prepare($request['order_id'], $request['key'], true);
        }
        if ($request['action'] === 'waiter-payment') {
            return app(TransactionService::class)->idempotent($request['key'], 'waiter-payment', $request['payload'], fn () => app(PaymentService::class)->receiveWaiter($request['payload']['waiter_id'], $request['payload']['stock_location_id'], $request['payload']['amount'], 'Cash'));
        }
        if ($request['action'] === 'recon-close') {
            return app(TransactionService::class)->idempotent($request['key'], 'shift-close', ['id' => $request['shift_id']], fn () => ['id' => app(ShiftService::class)->close($request['shift_id'], ['actual_cash' => '300000'])->id]);
        }
        if ($request['action'] === 'recon-move') {
            return app(TransactionService::class)->run(fn () => app(StockService::class)->change($request['location_id'], $request['product_id'], '1', 'GRN', 'Shift', $request['shift_id']));
        }
        if ($request['action'] === 'payment') {
            $payload = ['id' => $request['order_id'], 'lines' => [['method' => 'Cash', 'amount' => $request['amount']]]];

            return app(TransactionService::class)->idempotent($request['key'], 'payment', $payload, function () use ($payload) {
                $order = app(PaymentService::class)->receive($payload['id'], $payload['lines']);

                return ['id' => $order->id, 'status' => $order->status];
            });
        }
        throw new RuntimeException('Unknown validation worker action.');
    });
    echo json_encode(['code' => 200, 'id' => is_array($result) ? ($result['id'] ?? $result['waiter_id']) : $result->id, 'print_batch' => is_array($result) ? ($result['print_batch'] ?? null) : null, 'print' => is_array($result) ? ($result['print'] ?? null) : null], JSON_THROW_ON_ERROR)."\n";
} catch (ValidationException $error) {
    echo json_encode(['code' => 422, 'errors' => $error->errors()], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Validation worker failed: '.get_class($error)."\n");
    exit(1);
}
