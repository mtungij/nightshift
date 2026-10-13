<?php

namespace App\Http\Controllers;

use App\Support\UiText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class LanguagePreferenceController extends Controller
{
    public function update(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', Rule::in(['en', 'sw'])], 'user_id' => ['prohibited']]);
        if ($user = $request->user()) {
            $user->language_preference = $data['locale'];
            $user->save();
        }
        $request->session()->put('locale', $data['locale']);
        app()->setLocale($data['locale']);

        return $request->expectsJson()
            ? response()->json(['locale' => $data['locale'], 'messages' => UiText::catalog()])
            : back();
    }
}
