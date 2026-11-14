<?php

declare(strict_types=1);

namespace App\Domain\Classification\Services;

use App\Domain\Classification\Data\ClassificationRequest;

/**
 * Huella estable de la entrada del clasificador.
 *
 * La caché y la deduplicación de `llm_calls` usan esta huella. Incluye la
 * descripción normalizada (sin acentos, mayúsculas y espacios colapsados), el
 * rubro del emisor, el catálogo de categorías y el modelo con la versión del
 * prompt: cambiar cualquiera de ellos invalida la entrada anterior.
 */
final readonly class LlmInputHash
{
    public function __construct(private ClassificationRuleMatcher $normalizer) {}

    public function for(ClassificationRequest $request, string $model, string $promptVersion): string
    {
        $codes = $request->categoryCodes();
        sort($codes);

        $payload = [
            'description' => $this->normalizer->normalize($request->description),
            'issuer_activity' => $request->issuerActivity === null
                ? null
                : $this->normalizer->normalize($request->issuerActivity),
            'categories' => $codes,
            'model' => $model,
            'prompt_version' => $promptVersion,
        ];

        return hash(
            'sha256',
            (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );
    }
}
