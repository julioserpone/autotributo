<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PragmaRX\Google2FA\Google2FA;

final class TwoFactorController extends Controller
{
    public function enable(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $google2fa = new Google2FA;
        $secret = $google2fa->generateSecretKey();

        $user->two_factor_secret = $secret;
        $user->two_factor_confirmed_at = null;
        $user->two_factor_recovery_codes = null;
        $user->save();

        return response()->json([
            'data' => [
                'secret' => $secret,
                'otpauth_url' => $google2fa->getQRCodeUrl((string) config('app.name'), $user->email, $secret),
            ],
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $request->validate([
            'code' => ['required', 'string'],
        ]);

        $secret = (string) $user->two_factor_secret;

        if ($secret === '' || ! (new Google2FA)->verifyKey($secret, (string) $request->string('code'))) {
            return response()->json([
                'message' => 'El código de verificación no es válido.',
            ], 422);
        }

        $recoveryCodes = collect(range(1, 8))
            ->map(static fn (): string => bin2hex(random_bytes(5)).'-'.bin2hex(random_bytes(5)))
            ->all();

        $user->two_factor_confirmed_at = now();
        $user->two_factor_recovery_codes = (string) json_encode($recoveryCodes);
        $user->save();

        return response()->json([
            'data' => [
                'recovery_codes' => $recoveryCodes,
            ],
        ]);
    }

    public function disable(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $user->two_factor_secret = null;
        $user->two_factor_recovery_codes = null;
        $user->two_factor_confirmed_at = null;
        $user->save();

        return response()->json([
            'data' => ['disabled' => true],
        ]);
    }

    public function recoveryCodes(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->hasTwoFactorEnabled()) {
            abort(422);
        }

        $recoveryCodes = collect(range(1, 8))
            ->map(static fn (): string => bin2hex(random_bytes(5)).'-'.bin2hex(random_bytes(5)))
            ->all();

        $user->two_factor_recovery_codes = (string) json_encode($recoveryCodes);
        $user->save();

        return response()->json([
            'data' => ['recovery_codes' => $recoveryCodes],
        ]);
    }

    public function challenge(Request $request): JsonResponse
    {
        $userId = $request->session()->get('auth.two_factor.user_id');

        if (! is_int($userId) && ! is_string($userId)) {
            return response()->json([
                'message' => 'No hay un desafío de segundo factor pendiente.',
            ], 422);
        }

        $user = User::query()->find($userId);

        if (! $user instanceof User || ! $user->hasTwoFactorEnabled()) {
            return response()->json([
                'message' => 'No hay un desafío de segundo factor pendiente.',
            ], 422);
        }

        $google2fa = new Google2FA;
        $valid = false;

        if ($request->filled('code')) {
            $valid = $google2fa->verifyKey((string) $user->two_factor_secret, (string) $request->string('code'));
        } elseif ($request->filled('recovery_code')) {
            $codes = json_decode((string) $user->two_factor_recovery_codes, true);

            if (is_array($codes) && in_array($request->string('recovery_code')->toString(), $codes, true)) {
                $codes = array_values(array_diff($codes, [$request->string('recovery_code')->toString()]));
                $user->two_factor_recovery_codes = (string) json_encode($codes);
                $user->save();
                $valid = true;
            }
        }

        if (! $valid) {
            return response()->json([
                'message' => 'El código de verificación no es válido.',
            ], 422);
        }

        $remember = (bool) $request->session()->get('auth.two_factor.remember', false);

        Auth::guard('web')->login($user, $remember);

        $request->session()->forget(['auth.two_factor.user_id', 'auth.two_factor.remember']);
        $request->session()->regenerate();

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'memberships' => $user->memberships()->get(['tenant_id', 'role']),
            ],
        ]);
    }
}
