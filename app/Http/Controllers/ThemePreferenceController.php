<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ThemePreferenceController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['theme' => ['required', Rule::in(['light', 'dark'])]]);
        $user = $request->user();
        $user->theme_preference = $data['theme'];
        $user->save();

        return response()->json(['theme' => $user->preferredTheme()]);
    }
}
