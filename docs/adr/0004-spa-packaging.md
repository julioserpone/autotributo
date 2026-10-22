# ADR-0004: SPA Vue empaquetada en el mismo repositorio Laravel

- **Estado:** aceptado
- **Fecha:** 2026-10-22
- **Decisores:** responsable técnico, frontend, backend

## Contexto

El plan original plantea un backend "API only" y un frontend Vue separado. Las alternativas son:

1. Monorepo con dos aplicaciones (`/api` y `/web`) desplegadas por separado.
2. Un solo repositorio Laravel con la SPA en `resources/js`, compilada por Vite y servida por Laravel.
3. Inertia.js (no aplica: se quiere SPA con API versionada).

El equipo es pequeño y el despliegue objetivo es Laravel Cloud, que construye y sirve una aplicación por entorno.

## Decisión

**Opción 2:** un único proyecto Laravel 13 con:

- API versionada en `/api/v1` (Sanctum por cookie de sesión en el mismo origen + tokens para integraciones).
- SPA Vue 3 + TypeScript en `resources/js`, compilada con Vite y servida por Laravel mediante una ruta catch-all que devuelve el shell.
- Sin CORS en el flujo principal (mismo origen), lo que simplifica CSRF y cookies.
- Contratos tipados: OpenAPI generado en el backend → tipos TypeScript generados en CI.

## Consecuencias

**Positivas**
- Un solo deploy, un solo dominio, un solo pipeline: menos coste operativo.
- Cookies `HttpOnly`/`SameSite` sin configuraciones cruzadas; CSRF estándar de Laravel.
- Subdominios por tenant sin problemas de CORS.
- Desarrollo local más simple (un contenedor web + Vite).
- La API sigue siendo reutilizable por una futura app móvil o integraciones.

**Negativas**
- Frontend y backend se despliegan juntos: un cambio de UI espera al pipeline de Laravel.
- El bundle inicial debe cuidarse (code splitting por ruta).
- Escalar frontend y backend por separado no es posible; para este producto no es una necesidad.

**Mitigaciones**
- Rutas con *lazy loading* y presupuesto de bundle en CI (< 250 KB gzip inicial).
- Assets con hash y caché larga en el edge de Cloud.
- Si en el futuro se necesita un sitio de marketing o un equipo de frontend independiente, se extrae `resources/js` a un repo propio sin cambiar la API (la frontera ya está en `/api/v1`).

## Alternativas consideradas

| Alternativa | Por qué no |
|---|---|
| Monorepo `api` + `web` | Duplica despliegues, CORS, cookies cross-site, configuración de previews y secretos; no aporta valor con un equipo pequeño |
| Inertia + Vue | Acopla las páginas al backend y complica una futura API pública/móvil; el equipo quiere separación clara |
| Livewire | No encaja con el diseño tipo dashboard SPA ni con el estilo visual objetivo |
| SPA en CDN separado | Ventaja de caché, pero suma CORS, dominios y un segundo pipeline |

## Criterios para revisar esta decisión

- El equipo de frontend crece y necesita releases independientes.
- Se requiere un sitio público/marketing con SEO propio (se puede resolver con un proyecto aparte).
- El bundle inicial supera de forma sostenida el presupuesto y el code splitting no alcanza.
