<?php

namespace App\Livewire;

use App\Services\MyOrdersService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class Workspace extends Component
{
    public function mount(): void
    {
        session()->forget(MyOrdersService::SESSION_KEY);
        abort_unless(auth()->user()->active && auth()->user()->company_id, 403);
    }

    public function render(): View
    {
        return view('livewire.workspace')->layout('layouts.nightshift');
    }
}
