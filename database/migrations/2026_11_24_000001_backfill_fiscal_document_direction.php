<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F5-01b: hasta ahora `fiscal_documents.direction` se fijaba en `received`
 * para todos los comprobantes. La dirección real se deriva comparando el RUC
 * del emisor con el RUC de la empresa: si coinciden, la empresa emitió el
 * comprobante (`issued`); si no, lo recibió (`received`).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "UPDATE fiscal_documents
             SET direction = CASE
                 WHEN issuer_ruc = (SELECT ruc FROM companies WHERE companies.id = fiscal_documents.company_id)
                     THEN 'issued'
                 ELSE 'received'
             END",
        );
    }

    public function down(): void
    {
        DB::table('fiscal_documents')->update(['direction' => 'received']);
    }
};
