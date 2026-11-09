<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Companies\Actions\Credentials\DeleteSriCredential;
use App\Domain\Companies\Actions\Credentials\RotateSriCredential;
use App\Domain\Companies\Actions\Credentials\StoreSriCredential;
use App\Domain\Companies\Models\Company;
use App\Domain\Tenancy\Permissions\RolePermissions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StoreSriCredentialRequest;
use App\Http\Resources\SriCredentialResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * CRUD de la credencial del portal SRI de una empresa.
 *
 * El controlador solo orquesta: el cifrado, el registro de acceso y la
 * rotación viven en las Actions, y el secreto nunca cruza esta capa.
 */
final class SriCredentialController extends Controller
{
    public function __construct(
        private readonly StoreSriCredential $store,
        private readonly RotateSriCredential $rotate,
        private readonly DeleteSriCredential $delete,
    ) {}

    public function show(Company $company): SriCredentialResource
    {
        $this->authorizeTenant($company);
        Gate::authorize('credentials.view', $company);

        $credential = $company->credential()->firstOrFail();

        return new SriCredentialResource($credential);
    }

    public function update(StoreSriCredentialRequest $request, Company $company): JsonResponse
    {
        $this->authorizeTenant($company);
        Gate::authorize('credentials.update', $company);

        $existed = $company->credential()->exists();

        $credential = $this->store->handle(
            $company,
            (string) $request->string('username'),
            (string) $request->string('password'),
            $request->user()?->getKey(),
            $request->ip(),
        );

        return (new SriCredentialResource($credential->load('company')))
            ->response()
            ->setStatusCode($existed ? 200 : 201);
    }

    public function rotate(StoreSriCredentialRequest $request, Company $company): JsonResponse
    {
        $this->authorizeTenant($company);
        Gate::authorize('credentials.update', $company);

        $credential = $this->rotate->handle(
            $company,
            (string) $request->string('password'),
            $request->user()?->getKey(),
            $request->ip(),
        );

        return (new SriCredentialResource($credential->load('company')))
            ->response()
            ->setStatusCode(200);
    }

    public function destroy(Request $request, Company $company): Response
    {
        $this->authorizeTenant($company);
        Gate::authorize('credentials.delete', $company);

        $this->delete->handle($company, $request->user()?->getKey(), $request->ip());

        return response()->noContent();
    }

    /**
     * Un recurso de otro tenant no existe para esta petición: 404, no 403.
     * El `TenantScope` ya lo filtra; esto es defensa en profundidad.
     */
    private function authorizeTenant(Company $company): void
    {
        abort_unless(RolePermissions::sameTenant($company), 404);
    }
}
