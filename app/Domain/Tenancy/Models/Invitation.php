<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

final class Invitation extends Model implements AuditableContract
{
    use Auditable;

    protected $table = 'invitations';

    protected $guarded = [];

    /**
     * El hash del token no debe quedar en el historial.
     *
     * @var list<string>
     */
    protected $auditExclude = ['token_hash'];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
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
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
