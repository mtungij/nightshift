<?php

namespace App\Http\Responses;

use App\Domain\OperationalShift;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\Fortify;

final class StaffLoginResponse implements LoginResponse, TwoFactorLoginResponse
{
    public function toResponse($request)
    {
        $shifts = app(OperationalShift::class);
        $user = $request->user();
        if (! $shifts->type($user)) {
            return $request->wantsJson()
                ? response()->json(['two_factor' => false])
                : redirect()->intended(Fortify::redirects('login'));
        }
        // Operational staff always follow the shift flow, even with an intended URL.
        $request->session()->forget('url.intended');
        $destination = $shifts->destination($user);

        return $request->wantsJson()
            ? response()->json(['two_factor' => false, 'redirect' => $destination])
            : redirect($destination);
    }
}
