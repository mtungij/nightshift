<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterBusinessRequest;
use App\Services\BusinessOnboardingService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

final class BusinessOnboardingController extends Controller
{
    public function create(): View
    {
        return view('business.register');
    }

    public function store(RegisterBusinessRequest $request, BusinessOnboardingService $onboarding): RedirectResponse
    {
        $owner = $onboarding->register($request->validated());
        Auth::login($owner);
        $request->session()->regenerate();

        return redirect('/dashboard#setup')->with('status', 'Your business account is ready.');
    }
}
