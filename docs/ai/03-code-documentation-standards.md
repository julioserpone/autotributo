# 03. Code Documentation Standards

## 1. Inline Comments
- Explican lógica compleja, workarounds de integración SRI o manejo de contexto tenant, en español o inglés.
- Evitar lo obvio; explicar **por qué** se tomó la decisión.

```php
// Bien: explica la razón de negocio o el workaround
// El SRI rechaza comprobantes con fecha posterior al cierre del período; se
// normaliza a la fecha de emisión del XML para evitar el error 43.
$issuedAt = $xml->fechaEmision;

// Mal: explica código PHP obvio
// Recorre el arreglo de facturas
foreach ($invoices as $invoice) { ... }
```

- No usar `sleep()` para rate limiting; preferir backoff configurable o colas con delay. Si es inevitable, documentar la razón y el límite.
- Logs estructurados incluyen `tenant_id` y `run_id` cuando aplique; nunca PII ni secretos.

## 2. PHPDoc Blocks
- Métodos públicos de Services, Actions, helpers y jobs tenant deben tener PHPDoc completo.
- Tags estándar (`@param`, `@return`, `@throws`) en inglés.

```php
/**
 * Dispatch invoice payload to the tax authority for electronic validation.
 *
 * @param Invoice $invoice
 * @param Tenant $tenant
 * @return SriResponseData
 *
 * @throws SriConnectionException
 * @throws InvalidTaxRecordException
 */
public function submitInvoice(Invoice $invoice, Tenant $tenant): SriResponseData
{
    // Implementation...
}
```
