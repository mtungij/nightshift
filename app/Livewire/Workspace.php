<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;

final class Workspace extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()->active && auth()->user()->company_id, 403);
    }

    public function render(): View
    {
        return view('livewire.workspace')->layout('layouts.nightshift');
    }
}
