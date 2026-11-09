<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Tenancy\Enums\MembershipRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

final class Membership extends Model implements AuditableContract
{
    use Auditable;

    protected $table = 'memberships';

    protected $guarded = [];

    protected $casts = [
        'role' => MembershipRole::class,
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
