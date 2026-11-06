<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EmailVerificationController extends Controller
{
    public function send(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'data' => ['verified' => true],
            ]);
        }

        $user->sendEmailVerificationNotification();

        return response()->json([
            'data' => ['sent' => true],
        ]);
    }

    public function verify(Request $request, int $id, string $hash): JsonResponse
    {
        $user = User::query()->findOrFail($id);

        if ((int) $request->user()?->getAuthIdentifier() !== $user->getKey()) {
            abort(403);
        }

        if (! hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            abort(403);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'data' => ['verified' => true],
            ]);
        }

        $user->markEmailAsVerified();

        event(new Verified($user));

        return response()->json([
            'data' => ['verified' => true],
        ]);
    }
}
