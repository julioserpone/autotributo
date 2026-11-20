<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Companies\Models\Company;
use App\Domain\Sri\Actions\ImportManualSriFiscalDocument;
use App\Domain\Sri\Enums\SriImportOutcome;
use App\Domain\Sri\Exceptions\SriXmlException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sri\StoreManualFiscalDocumentRequest;
use App\Http\Resources\FiscalDocumentResource;
use App\Support\Http\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

/**
 * Carga manual de un XML autorizado (F2-13 / US-14).
 *
 * Es una importación síncrona de un solo documento: no crea un `SriSyncRun`.
 */
final class ManualFiscalDocumentController extends Controller
{
    public function __construct(private readonly ImportManualSriFiscalDocument $import) {}

    public function store(StoreManualFiscalDocumentRequest $request, Company $company): JsonResponse
    {
        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            return ProblemDetails::validation(['file' => ['Debe adjuntar un XML.']]);
        }

        $contents = $file->getContent();

        try {
            $result = $this->import->handle($company, $contents);
        } catch (SriXmlException $exception) {
            return ProblemDetails::make(
                422,
                'invalid-sri-xml',
                'XML inválido',
                $exception->getMessage(),
            );
        }

        $status = $result->outcome === SriImportOutcome::Created ? 201 : 200;

        return response()->json([
            'data' => new FiscalDocumentResource($result->fiscal_document),
            'meta' => ['outcome' => $result->outcome->value],
        ], $status);
    }
}
