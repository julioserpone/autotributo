<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Companies\Actions\ArchiveCompany;
use App\Domain\Companies\Actions\CreateCompany;
use App\Domain\Companies\Actions\UpdateCompany;
use App\Domain\Companies\Models\Company;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StoreCompanyRequest;
use App\Http\Requests\Company\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class CompanyController extends Controller
{
    public function __construct(
        private readonly CreateCompany $createCompany,
        private readonly UpdateCompany $updateCompany,
        private readonly ArchiveCompany $archiveCompany,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Company::class);

        $query = Company::query();

        $status = trim((string) $request->query('status', ''));

        if ($status !== '') {
            $query->where('status', $status);
        }

        $search = trim((string) $request->query('search', ''));

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('ruc', 'like', "%{$search}%")
                    ->orWhere('legal_name', 'like', "%{$search}%");
            });
        }

        $perPage = max(1, min((int) $request->integer('per_page', 25), 100));

        return CompanyResource::collection(
            $query->orderBy('legal_name')->paginate($perPage),
        );
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        $company = $this->createCompany->handle($request->validated(), $actor);

        return (new CompanyResource($company))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Company $company): CompanyResource
    {
        Gate::authorize('view', $company);

        return new CompanyResource($company);
    }

    public function update(UpdateCompanyRequest $request, Company $company): CompanyResource
    {
        $updated = $this->updateCompany->handle($company, $request->validated());

        return new CompanyResource($updated);
    }

    public function destroy(Company $company): Response
    {
        Gate::authorize('delete', $company);

        $this->archiveCompany->handle($company);

        return response()->noContent();
    }
}
