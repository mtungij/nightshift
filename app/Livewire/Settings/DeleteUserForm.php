<?php

namespace App\Livewire\Settings;

use Illuminate\Contracts\View\View;
use Livewire\Component;

class DeleteUserForm extends Component
{
    public function render(): View
    {
        return view('livewire.settings.delete-user-form')->layout('layouts.app');
    }
}
