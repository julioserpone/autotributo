<?php

declare(strict_types=1);

namespace App\Domain\Classification\Models;

use App\Domain\Classification\Enums\LlmCallStatus;
use App\Domain\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Registro de una llamada (o intento) al clasificador de IA.
 *
 * Es una bitácora inmutable: solo `created_at`, sin `updated_at`. Guarda el
 * hash de la entrada, no la descripción del ítem, y el coste en micros de USD
 * para que el presupuesto mensual se calcule con enteros.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $company_id
 * @property string $purpose
 * @property string $provider
 * @property string $model
 * @property string|null $prompt_version
 * @property string $input_hash
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cost_micros
 * @property int $latency_ms
 * @property LlmCallStatus $status
 * @property string|null $error_code
 * @property Carbon $created_at
 */
final class LlmCall extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'llm_calls';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'tenant_id' => 'integer',
        'company_id' => 'integer',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'cost_micros' => 'integer',
        'latency_ms' => 'integer',
        'status' => LlmCallStatus::class,
        'created_at' => 'datetime',
    ];

    /**
     * Clasificación que se generó a partir de esta llamada.
     *
     * @return HasOne<Classification, $this>
     */
    public function classification(): HasOne
    {
        return $this->hasOne(Classification::class);
    }
}
