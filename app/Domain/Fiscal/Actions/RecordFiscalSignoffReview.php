<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Actions;

use App\Domain\Fiscal\Catalogs\FiscalSignoffStageCatalog;
use App\Domain\Fiscal\Enums\FiscalSignoffDecision;
use App\Domain\Fiscal\Exceptions\FiscalSignoffStageChanged;
use App\Domain\Fiscal\Models\FiscalSignoffReview;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

final readonly class RecordFiscalSignoffReview
{
    public function handle(
        User $reviewer,
        string $stageKey,
        int $stageVersion,
        string $stageFingerprint,
        FiscalSignoffDecision $decision,
        ?string $comments,
        TenantContext $tenantContext,
    ): FiscalSignoffReview {
        $stage = FiscalSignoffStageCatalog::find($stageKey);

        if ($stage === null) {
            abort(404);
        }

        if ($stage['version'] !== $stageVersion || $stage['fingerprint'] !== $stageFingerprint) {
            throw FiscalSignoffStageChanged::staleSnapshot();
        }

        return FiscalSignoffReview::query()->create([
            'tenant_id' => $tenantContext->id(),
            'reviewer_id' => $reviewer->id,
            'stage_key' => $stage['key'],
            'stage_version' => $stageVersion,
            'stage_fingerprint' => $stageFingerprint,
            'decision' => $decision,
            'comments' => $comments,
            'reviewed_at' => now(),
        ]);
    }
}
