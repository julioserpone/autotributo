# 04. AI Prompt Skills Kit (Autotributo)

Usa estos prompts preformateados para generar código alineado con la arquitectura del proyecto.

---

### Skill Prompt 1: Generate Full Endpoint Stack
```
Actúa como Senior Laravel Architect. Genera el stack completo para la feature: "[FEATURE_NAME]".

Sigue estrictamente AGENTS.md y docs/ai/01-architecture-and-coding-standards.md:
1. FormRequest en app/Http/Requests (o app/Domain/<Module>/Http/Requests) con strict types y authorize() vía policy.
2. Action en app/Domain/<Module>/Actions, final readonly, con handle().
3. JsonResource en app/Http/Resources (o módulo) para la respuesta.
4. Controller delgado (invoca Action + Resource).
5. Pest feature test con aislamiento tenant A vs B.

Montos en centavos enteros (*_cents), nunca float. Scoping por tenant_id en Rule::unique/exists.
Código y BD en inglés; explica la solución en español.
```

---

### Skill Prompt 2: Code Review & Architecture Audit
```
Audita el siguiente código contra docs/ai/01-architecture-and-coding-standards.md:

[PASTE_CODE_HERE]

Verifica:
1. Controllers con lógica de negocio o queries.
2. Falta de FormRequest o returns crudos.
3. Fugas multi-tenant o falta de contexto tenant.
4. Falta de declare(strict_types=1);.
5. Uso de float para dinero.
6. authorize() que devuelve true sin policy.
7. Rule::unique/exists sin filtro por tenant_id.

Responde en español; correcciones de código en inglés.
```

---

### Skill Prompt 3: Create PR Description
```
Con base en los archivos modificados de esta sesión, genera la descripción de PR siguiendo .github/pull_request_template.md (multitenancy, firma fiscal, PII/secrets, tests, docs).
```
