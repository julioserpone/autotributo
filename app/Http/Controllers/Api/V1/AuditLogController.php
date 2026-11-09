<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Historial de auditoría de solo lectura (F1-07).
 *
 * El `TenantScope` no aplica a `AuditLog` (el paquete escribe fuera del
 * contexto en algunos eventos), así que el filtro por tenant es explícito y
 * obligatorio: sin él, un estudio vería cambios de otro.
 */
final class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', AuditLog::class);

        $query = AuditLog::query()
            ->where('tenant_id', app(TenantContext::class)->id())
            ->orderByDesc('id');

        $event = trim((string) $request->query('event', ''));

        if ($event !== '') {
            $query->where('event', $event);
        }

        $type = trim((string) $request->query('auditable_type', ''));

        if ($type !== '') {
            $query->where('auditable_type', 'like', '%\\'.$type);
        }

        $from = trim((string) $request->query('date_from', ''));

        if ($from !== '') {
            $query->where('created_at', '>=', $from);
        }

        $to = trim((string) $request->query('date_to', ''));

        if ($to !== '') {
            $query->where('created_at', '<=', $to.' 23:59:59');
        }

        $perPage = max(1, min((int) $request->integer('per_page', 25), 100));

        return AuditLogResource::collection($query->paginate($perPage));
    }
}
