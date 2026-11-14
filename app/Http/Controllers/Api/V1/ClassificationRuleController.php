<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Classification\Actions\CreateClassificationRule;
use App\Domain\Classification\Actions\DeleteClassificationRule;
use App\Domain\Classification\Actions\PreviewClassificationRule;
use App\Domain\Classification\Actions\UpdateClassificationRule;
use App\Domain\Classification\Enums\MatchType;
use App\Domain\Classification\Models\ClassificationRule;
use App\Http\Controllers\Controller;
use App\Http\Requests\Classification\PreviewClassificationRuleRequest;
use App\Http\Requests\Classification\StoreClassificationRuleRequest;
use App\Http\Requests\Classification\UpdateClassificationRuleRequest;
use App\Http\Resources\ClassificationRuleResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Reglas de clasificación automática (F3-02 / US-16).
 *
 * Las reglas globales se listan junto con las del tenant, pero solo las del
 * tenant se pueden editar. El dry-run permite ver el impacto antes de crear o
 * cambiar una regla (US-19).
 */
final class ClassificationRuleController extends Controller
{
    public function __construct(
        private readonly CreateClassificationRule $createRule,
        private readonly UpdateClassificationRule $updateRule,
        private readonly DeleteClassificationRule $deleteRule,
        private readonly PreviewClassificationRule $previewRule,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', ClassificationRule::class);

        $query = ClassificationRule::query()->with('expenseCategory');
        $filters = (array) $request->input('filter', []);

        $matchType = trim((string) ($filters['match_type'] ?? $request->query('match_type', '')));

        if ($matchType !== '' && MatchType::tryFrom($matchType) !== null) {
            $query->where('match_type', $matchType);
        }

        $isActive = $filters['is_active'] ?? $request->query('is_active');

        if ($isActive !== null && $isActive !== '') {
            $query->where('is_active', filter_var($isActive, FILTER_VALIDATE_BOOLEAN));
        }

        $search = trim((string) ($filters['search'] ?? $request->query('search', '')));

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('pattern', 'like', "%{$search}%");
            });
        }

        $perPage = max(1, min((int) $request->integer('per_page', 25), 100));

        return ClassificationRuleResource::collection(
            $query->orderBy('priority')->orderByRaw('tenant_id is null')->orderBy('id')->paginate($perPage),
        );
    }

    public function show(ClassificationRule $rule): ClassificationRuleResource
    {
        Gate::authorize('view', $rule);

        return new ClassificationRuleResource($rule->load('expenseCategory'));
    }

    public function store(StoreClassificationRuleRequest $request): JsonResponse
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        $rule = $this->createRule->handle($request->validated(), $actor);

        return (new ClassificationRuleResource($rule->load('expenseCategory')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateClassificationRuleRequest $request, ClassificationRule $rule): ClassificationRuleResource
    {
        $updated = $this->updateRule->handle($rule, $request->validated());

        return new ClassificationRuleResource($updated->load('expenseCategory'));
    }

    public function destroy(ClassificationRule $rule): Response
    {
        Gate::authorize('delete', $rule);

        $this->deleteRule->handle($rule);

        return response()->noContent();
    }

    public function dryRun(PreviewClassificationRuleRequest $request): JsonResponse
    {
        $preview = $this->previewRule->handle($request->validated());

        return response()->json(['data' => $preview->toArray()]);
    }
}
