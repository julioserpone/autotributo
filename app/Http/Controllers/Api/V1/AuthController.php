<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tenancy\Actions\ChangePassword;
use App\Domain\Tenancy\Actions\LoginUser;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class AuthController extends Controller
{
    public function __construct(
        private readonly LoginUser $login,
        private readonly ChangePassword $changePassword,
    ) {}

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
                'id' => (int) $user->id,
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
                'id' => (int) $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'memberships' => $user->memberships()->get(['tenant_id', 'role']),
            ],
        ]);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        try {
            $this->changePassword->handle(
                $user,
                (string) $request->string('current_password'),
                (string) $request->string('password'),
                $request->session()->getId(),
            );
        } catch (\InvalidArgumentException) {
            return response()->json([
                'message' => 'La contraseña actual no es válida.',
            ], 422);
        }

        return response()->json(['data' => ['password_changed' => true]]);
    }

    public function sessions(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $currentId = $request->session()->getId();

        $sessions = DB::table('sessions')
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get()
            ->map(static function (object $session) use ($currentId): array {
                return [
                    'id' => $session->id,
                    'ip_address' => $session->ip_address,
                    'user_agent' => $session->user_agent,
                    'last_activity' => Carbon::createFromTimestamp((int) $session->last_activity)->toIso8601String(),
                    'is_current' => $session->id === $currentId,
                ];
            });

        return response()->json(['data' => $sessions]);
    }

    public function revokeSession(Request $request, string $session): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $deleted = DB::table('sessions')
            ->where('id', $session)
            ->where('user_id', $user->id)
            ->delete();

        if ($deleted === 0) {
            abort(404);
        }

        if ($session === $request->session()->getId()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    public function loginHistory(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $perPage = max(1, min((int) $request->integer('per_page', 25), 100));

        $logs = AuditLog::query()
            ->where('user_id', $user->id)
            ->where('tenant_id', app(TenantContext::class)->id())
            ->whereIn('event', ['login', 'login_failed', 'logout', 'password_changed'])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return AuditLogResource::collection($logs);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['data' => ['logged_out' => true]]);
    }
}
