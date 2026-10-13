<?php

namespace App\Http\Controllers;

use App\Http\Requests\BusinessProfileRequest;
use App\Services\BusinessProfileService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BusinessProfileController extends Controller
{
    public function edit(Request $request): View
    {
        Gate::authorize('manage-company');

        return view('business.profile', ['company' => $request->user()->company]);
    }

    public function update(BusinessProfileRequest $request, BusinessProfileService $profile): RedirectResponse
    {
        $profile->update($request->validated());

        return redirect()->route('business.edit')->with('status', 'Business profile saved.');
    }

    public function logo(Request $request): StreamedResponse
    {
        $path = $request->user()->company->fresh()->logo_path;
        abort_unless($path && str_starts_with($path, 'business-logos/') && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, no-cache', 'X-Content-Type-Options' => 'nosniff']);
    }
}
