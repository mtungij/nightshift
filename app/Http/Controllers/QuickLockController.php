<?php

namespace App\Http\Controllers;

use App\Services\StaffPinService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class QuickLockController extends Controller
{
    private function authorizeStaff(Request $request): void
    {
        abort_unless($request->user()->active && $request->user()->operational_pin, 403);
    }

    public function show(Request $request): View
    {
        $this->authorizeStaff($request);
        $request->session()->put('staff_locked', true);

        return view('quick-lock');
    }

    public function lock(Request $request): RedirectResponse
    {
        $this->authorizeStaff($request);
        $request->session()->put('staff_locked', true);

        return redirect()->route('quick-lock');
    }

    public function unlock(Request $request, StaffPinService $pins): RedirectResponse
    {
        $this->authorizeStaff($request);
        $data = $request->validate(['pin' => ['required', 'string', 'regex:/^[0-9]{4}$/D']]);
        $pins->verify($request->user()->id, $data['pin']);
        $request->session()->regenerate();
        $request->session()->forget('staff_locked');
        $request->session()->put('staff_last_activity', time());

        return redirect()->route('dashboard');
    }
}
