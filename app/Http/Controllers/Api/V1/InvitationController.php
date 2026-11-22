<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenancy\Actions\AcceptInvitation;
use App\Domain\Tenancy\Actions\InviteUser;
use App\Domain\Tenancy\Models\Invitation;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AcceptInvitationRequest;
use App\Http\Requests\Auth\InviteUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InvitationController extends Controller
{
    public function __construct(
        private readonly InviteUser $invite,
        private readonly AcceptInvitation $accept,
    ) {}

    public function store(InviteUserRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $tenant = Tenant::query()->findOrFail(app(TenantContext::class)->id());

        $result = $this->invite->handle(
            $tenant,
            $user,
            (string) $request->string('email'),
            (string) $request->string('role'),
        );

        $invitation = $result['invitation'];

        return response()->json([
            'data' => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'expires_at' => $invitation->expires_at,
                'resent' => $result['resent'],
            ],
        ], 201);
    }

    public function validate(Request $request): JsonResponse
    {
        $token = (string) $request->query('token', '');

        if ($token === '') {
            return response()->json([
                'message' => 'La invitación no es válida o ha expirado.',
            ], 422);
        }

        $invitation = Invitation::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if (! $invitation instanceof Invitation
            || $invitation->accepted_at !== null
            || $invitation->expires_at->isPast()) {
            return response()->json([
                'message' => 'La invitación no es válida, fue anulada o ha expirado.',
            ], 422);
        }

        return response()->json(['data' => ['valid' => true]], 200);
    }

    public function accept(AcceptInvitationRequest $request): JsonResponse
    {
        $user = $this->accept->handle(
            (string) $request->string('token'),
            (string) $request->string('name'),
            (string) $request->string('password'),
        );

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ], 201);
    }
}
