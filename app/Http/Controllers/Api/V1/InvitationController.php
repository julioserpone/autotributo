<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenancy\Actions\AcceptInvitation;
use App\Domain\Tenancy\Actions\InviteUser;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AcceptInvitationRequest;
use App\Http\Requests\Auth\InviteUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

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

        $invitation = $this->invite->handle(
            $tenant,
            $user,
            (string) $request->string('email'),
            (string) $request->string('role'),
        );

        return response()->json([
            'data' => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'expires_at' => $invitation->expires_at,
            ],
        ], 201);
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
