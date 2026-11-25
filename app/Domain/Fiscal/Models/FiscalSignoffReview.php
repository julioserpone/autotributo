<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Models;

use App\Domain\Fiscal\Enums\FiscalSignoffDecision;
use App\Domain\Tenancy\Models\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Decisión inmutable del contador sobre una versión concreta de una etapa.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $reviewer_id
 * @property string $stage_key
 * @property int $stage_version
 * @property string $stage_fingerprint
 * @property FiscalSignoffDecision $decision
 * @property string|null $comments
 * @property Carbon $reviewed_at
 */
final class FiscalSignoffReview extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'fiscal_signoff_reviews';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'stage_version' => 'integer',
        'decision' => FiscalSignoffDecision::class,
        'reviewed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
