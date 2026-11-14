<?php

declare(strict_types=1);

namespace App\Domain\Classification\Services;

use App\Domain\Classification\Data\ClassificationCategory;
use App\Domain\Classification\Data\ClassificationRequest;
use RuntimeException;

/**
 * Prompt versionado del clasificador.
 *
 * La versión forma parte del contrato: se guarda en `classifications` y F3-04
 * la asociará a cada `llm_call`. El catálogo se interpola en el mensaje de
 * sistema; la descripción viaja aparte como datos no confiables para reducir
 * el riesgo de inyección de prompt.
 */
final class ClassifierPrompt
{
    public const VERSION = 'v1';

    private ?string $template = null;

    public function system(ClassificationRequest $request): string
    {
        return str_replace(
            '{{categories}}',
            $this->formatCategories($request->categories),
            $this->template(),
        );
    }

    /**
     * @return string JSON con la descripción y, si existe, la actividad del emisor.
     */
    public function user(ClassificationRequest $request): string
    {
        $payload = ['descripcion' => $request->description];
        $activity = $request->issuerActivity;

        if ($activity !== null && trim($activity) !== '') {
            $payload['actividad_emisor'] = $activity;
        }

        $encoded = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        if ($encoded === false) {
            throw new RuntimeException('No fue posible serializar la solicitud del clasificador.');
        }

        return $encoded;
    }

    /**
     * @param  list<ClassificationCategory>  $categories
     */
    private function formatCategories(array $categories): string
    {
        return implode("\n", array_map(
            static fn (ClassificationCategory $category): string => sprintf(
                '- %s: %s',
                $category->code,
                $category->name,
            ),
            $categories,
        ));
    }

    private function template(): string
    {
        if ($this->template !== null) {
            return $this->template;
        }

        $path = resource_path('prompts/classify-item.'.self::VERSION.'.md');
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("No fue posible leer el prompt del clasificador: {$path}.");
        }

        return $this->template = $contents;
    }
}
