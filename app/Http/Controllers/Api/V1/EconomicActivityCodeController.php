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

        $search = trim((string) $request->query('q', ''));

        if ($search !== '') {
            $needle = '%'.strtolower($search).'%';

            $query->where(function ($builder) use ($needle): void {
                $builder->whereRaw('LOWER(code) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(description) LIKE ?', [$needle]);
            });
        }

        $codes = $query->get(['code', 'description']);

        return response()->json([
            'data' => $codes->map(static fn (EconomicActivityCode $code): array => [
                'code' => $code->code,
                'description' => $code->description,
            ])->values(),
        ]);
    }
}
