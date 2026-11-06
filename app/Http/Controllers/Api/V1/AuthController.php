<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenancy\Actions\LoginUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class AuthController extends Controller
{
    public function __construct(private readonly LoginUser $login) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->login->handle(
            (string) $request->string('email'),
            (string) $request->string('password'),
            (bool) $request->boolean('remember'),
        );

        if (! $user instanceof User) {
            return response()->json([
                'message' => 'Las credenciales no son válidas.',
            ], 422);
        }

        if ($user->hasTwoFactorEnabled()) {
            $request->session()->put('auth.two_factor.user_id', $user->id);
            $request->session()->put('auth.two_factor.remember', (bool) $request->boolean('remember'));

            return response()->json([
                'data' => ['two_factor_required' => true],
            ], 202);
        }

        Auth::guard('web')->login($user, (bool) $request->boolean('remember'));

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

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'memberships' => $user->memberships()->get(['tenant_id', 'role']),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['data' => ['logged_out' => true]]);
    }
}
