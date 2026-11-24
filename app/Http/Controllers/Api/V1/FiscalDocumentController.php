<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Companies\Models\Company;
use App\Domain\FiscalDocuments\Models\FiscalDocument;
use App\Domain\Sri\Enums\SriDocumentType;
use App\Http\Controllers\Controller;
use App\Http\Resources\FiscalDocumentResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Bandeja de comprobantes del tenant (F4-05).
 *
 * Listado paginado por cursor (recomendado para grandes volúmenes) con filtros
 * por empresa, estado, tipo de comprobante, emisor y rango de fechas.
 */
final class FiscalDocumentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', FiscalDocument::class);

        $query = FiscalDocument::query()->orderByDesc('issue_date')->orderByDesc('id');

        $filters = (array) $request->input('filter', []);

        $companyId = $filters['company_id'] ?? $request->query('company_id');

        if ($companyId !== null && $companyId !== '') {
            $company = is_numeric($companyId)
                ? Company::query()->find((int) $companyId)
                : Company::query()->where('public_id', (string) $companyId)->first();

            if ($company !== null) {
                $query->where('company_id', $company->id);
            }
        }

        $status = trim((string) ($filters['status'] ?? $request->query('status', '')));

        if ($status !== '') {
            $query->where('status', $status);
        }

        $documentType = trim((string) ($filters['document_type'] ?? $request->query('document_type', '')));

        if ($documentType !== '' && SriDocumentType::tryFrom($documentType) !== null) {
            $query->where('document_type', $documentType);
        }

        $issuer = trim((string) ($filters['issuer'] ?? $request->query('issuer', '')));

        if ($issuer !== '') {
            $query->where(function (Builder $builder) use ($issuer): void {
                $builder->where('issuer_name', 'like', "%{$issuer}%")
                    ->orWhere('issuer_ruc', 'like', "%{$issuer}%")
                    ->orWhere('access_key', 'like', "%{$issuer}%");
            });
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? $request->query('date_from', '')));

        if ($dateFrom !== '') {
            $query->whereDate('issue_date', '>=', $dateFrom);
        }

        $dateTo = trim((string) ($filters['date_to'] ?? $request->query('date_to', '')));

        if ($dateTo !== '') {
            $query->whereDate('issue_date', '<=', $dateTo);
        }

        $perPage = max(1, min((int) $request->integer('per_page', 25), 100));

        return FiscalDocumentResource::collection(
            $query->cursorPaginate($perPage, ['*'], 'cursor', $request->query('cursor')),
        );
    }

    public function show(FiscalDocument $fiscal_document): FiscalDocumentResource
    {
        Gate::authorize('view', $fiscal_document);

        return new FiscalDocumentResource($fiscal_document->load([
            'items.expenseCategory',
            'items.classification',
            'taxes',
            'retentions',
        ]));
    }
}
