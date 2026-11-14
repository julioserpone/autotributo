<?php

declare(strict_types=1);

namespace App\Integrations\Ai;

use App\Domain\Classification\Contracts\Classifier;
use App\Domain\Classification\Data\ClassificationRequest;
use App\Domain\Classification\Data\ClassificationSuggestion;
use App\Domain\Classification\Data\ClassifierResult;
use App\Domain\Classification\Services\ClassifierPrompt;
use Throwable;

/**
 * Doble determinista del clasificador para desarrollo y pruebas.
 *
 * Por defecto no propone categoría: así los tests del motor de reglas siguen
 * viendo los ítems sin coincidencia como `pending`. Los tests encolan
 * sugerencias o excepciones con `pushSuggestion()` / `pushException()`; el
 * proveedor real nunca se instancia en ese modo.
 */
final class FakeClassifier implements Classifier
{
    /** @var list<ClassifierResult|Throwable> */
    private array $responses = [];

    /** @var list<ClassificationRequest> */
    public array $requests = [];

    public function pushSuggestion(
        string $categoryCode,
        float $confidence,
        string $reason = 'Sugerencia simulada para pruebas.',
    ): self {
        $this->responses[] = new ClassifierResult(
            suggestion: new ClassificationSuggestion($categoryCode, $confidence, $reason),
            model: 'fake',
            promptVersion: ClassifierPrompt::VERSION,
            provider: 'fake',
        );

        return $this;
    }

    public function pushException(Throwable $exception): self
    {
        $this->responses[] = $exception;

        return $this;
    }

    public function push(ClassifierResult|Throwable $response): self
    {
        $this->responses[] = $response;

        return $this;
    }

    public function reset(): void
    {
        $this->responses = [];
        $this->requests = [];
    }

    public function classify(ClassificationRequest $request): ClassifierResult
    {
        $this->requests[] = $request;

        $next = array_shift($this->responses);

        if ($next instanceof Throwable) {
            throw $next;
        }

        if ($next instanceof ClassifierResult) {
            return $next;
        }

        return ClassifierResult::withoutSuggestion('fake', ClassifierPrompt::VERSION, 'fake');
    }
}
