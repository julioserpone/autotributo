<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Sri\Models\SriSyncRunFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Archivo asociado a un run de sincronización (F2-09a).
 *
 * Expone el estado por archivo para que el monitor muestre qué documentos
 * fallaron y por qué, sin filtrar la clave de acceso completa.
 *
 * @property-read SriSyncRunFile $resource
 */
final class SriSyncRunFileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $file = $this->resource;

        return [
            'id' => (int) $file->id,
            'file_name' => $file->file_name,
            's3_key' => $file->s3_key,
            'checksum' => $file->checksum,
            'document_type' => $file->document_type?->value,
            'status' => $file->status->value,
            'error_code' => $file->error_code,
            'error_message' => $file->error_message,
            'import_outcome' => $file->import_outcome?->value,
            'source' => $file->source,
            'processed_at' => $file->processed_at?->toIso8601String(),
        ];
    }
}
