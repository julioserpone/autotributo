<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Models;

use App\Domain\Fiscal\Models\Concerns\VersionedCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tope anual de gastos personales deducibles.
 *
 * El tope se expresa en canastas básicas familiares (CBF) y varía por número
 * de cargas; `dependents = 5` representa "cinco o más". La categoría
 * `catastrophic_illness` aplica el tope especial de 100 canastas.
 *
 * @property int $id
 * @property int $fiscal_year
 * @property string $category_code
 * @property int $dependents
 * @property int $cap_cents
 * @property int|null $baskets
 * @property int $basic_food_basket_cents
 * @property Carbon $valid_from
 * @property Carbon|null $valid_to
 * @property string $source
 * @property Carbon|null $published_at
 * @property string|null $checksum
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PersonalExpenseCap extends Model
{
    use VersionedCatalog;

    protected $table = 'personal_expense_caps';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'fiscal_year' => 'integer',
        'dependents' => 'integer',
        'cap_cents' => 'integer',
        'baskets' => 'integer',
        'basic_food_basket_cents' => 'integer',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'published_at' => 'date',
    ];
}
