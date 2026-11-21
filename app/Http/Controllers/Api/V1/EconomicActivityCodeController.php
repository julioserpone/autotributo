<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Companies\Models\EconomicActivityCode;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EconomicActivityCodeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = EconomicActivityCode::query()->orderBy('code');

        $includeAll = (bool) $request->boolean('all');

        if (! $includeAll) {
            $query->where('is_selectable', true);
        }

        $search = trim((string) $request->query('q', ''));

        if ($search !== '') {
            $needle = '%'.strtolower($search).'%';

            $query->where(function ($builder) use ($needle): void {
                $builder->whereRaw('LOWER(code) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(description) LIKE ?', [$needle]);
            });
        }

        $limit = max(1, min((int) $request->integer('limit', 50), 100));
        $offset = max(0, (int) $request->integer('offset', 0));

        $codes = $query->skip($offset)->take($limit)->get(['code', 'description']);

        return response()->json([
            'data' => $codes->map(static fn (EconomicActivityCode $code): array => [
                'code' => $code->code,
                'description' => $code->description,
            ])->values(),
            'meta' => [
                'limit' => $limit,
                'offset' => $offset,
            ],
        ]);
    }
}
