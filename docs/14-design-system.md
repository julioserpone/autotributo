# 14 — Design system

> Referencia de los tokens y componentes base de la SPA (F4-02, F4-04). La fuente
> de verdad del alcance sigue siendo `docs/02-implementation-plan.md` §6.

## Base de componentes (F4-04)

La SPA usa **Nuxt UI 4.13** (`@nuxt/ui`) integrado con Vite, sin framework Nuxt
(ADR-0009). Los componentes se usan con el prefijo `U` (`UButton`, `UInput`,
`UModal`, `UBadge`, `USlideover`, `UDropdownMenu`, `UNavigationMenu`, `UAlert`,
`UFormField`, `UCheckbox`, `UIcon`) y el layout se apoya en los componentes
`UDashboard*`. Los wrappers propios viven en `components/layout/` y
`components/` (`AppShell`, `AppPage`, `AppSidebar`, `AppWorkspaceMenu`,
`AppUserMenu`, `AuthLayout`, `AppStatusBadge`, `FiscalDocumentTable`).

## Tema «Mint»

El tema replica el preset **Mint** del editor de temas de Nuxt UI:

| Aspecto | Valor |
|---|---|
| Primario | `teal` |
| Neutral | `olive` |
| Radio base | `0.75rem` (`--ui-radius`) |
| Tipografía | `Nunito Variable` (500/600/700/800) |
| Monoespaciada | `JetBrains Mono Variable` |
| Controles | Botones, inputs y selects tipo píldora (`rounded-full`) |
| Inputs/selects | Variante `soft` por defecto |

La configuración de color y variantes vive en el bloque `ui` de
`vite.config.ts`. En `resources/css/app.css` solo quedan los overrides que el
preset expresa en CSS: el lienzo claro tintado (fondo `neutral-50`, superficies
suaves `neutral-100`), los pesos tipográficos y el radio.

## Principios

- **Tokens semánticos, no colores crudos.** Los componentes usan
  `bg-default`, `bg-elevated`, `border-default`, `text-highlighted`, `text-muted`
  o `text-dimmed`; nunca `teal-500` u `olive-800`. Cambiar de tema es editar el
  bloque `ui` de Vite y los overrides de `app.css`.
- **Claro y oscuro con la misma jerarquía.** `useTheme` alterna `.dark`/`.light`
  en `<html>`; el tema oscuro es el valor por defecto.
- **Accesible por defecto.** Foco visible, etiquetas asociadas, `aria-invalid`,
  `role="alert"` en errores, contraste AA y `prefers-reduced-motion`.
- **Sin lógica de negocio.** Los componentes reciben datos y emiten eventos;
  las decisiones fiscales viven en el backend.

## Layout

`AppShell` envuelve la aplicación autenticada:

```
UDashboardGroup
├── AppSidebar (UDashboardSidebar colapsable y redimensionable)
│   ├── AppWorkspaceMenu (empresa activa)
│   ├── UDashboardSearchButton
│   ├── UNavigationMenu (plataforma)
│   ├── UNavigationMenu (sistema, anclado abajo)
│   └── AppUserMenu (tema + cerrar sesión)
├── UDashboardSearch (⌘K, grupos desde lib/navigation.ts)
└── <slot> → AppPage
```

`AppPage` (`components/layout/AppPage.vue`) aporta:

- `UDashboardPanel` con `id` estable según el nombre de ruta.
- `UDashboardNavbar` con título, `UDashboardSidebarCollapse`, acciones
  (`#actions`) y `AppStatusBadge`.
- `UDashboardToolbar` opcional (`#toolbar` / `#toolbar-right`).
- `<main id="contenido">` como destino del enlace «Saltar al contenido».

La navegación compartida entre la barra lateral y la paleta de comandos está en
`resources/js/lib/navigation.ts`; una entrada sin `route` se muestra con la
etiqueta «Pronto» y deshabilitada.

## Componentes propios

| Componente | Archivo | Notas |
|---|---|---|
| `AppShell` | `layouts/AppShell.vue` | Grupo Dashboard, sidebar, paleta y skip link |
| `AppPage` | `components/layout/AppPage.vue` | Panel + navbar + toolbar + `main` |
| `AppSidebar` | `components/layout/AppSidebar.vue` | Menús, búsqueda y pie de usuario |
| `AppWorkspaceMenu` | `components/layout/AppWorkspaceMenu.vue` | Selector de empresa activa |
| `AppUserMenu` | `components/layout/AppUserMenu.vue` | Usuario, tema y cierre de sesión |
| `AuthLayout` | `layouts/AuthLayout.vue` | Tarjeta centrada para login y 2FA |
| `AppStatusBadge` | `components/AppStatusBadge.vue` | Estado de la API en la navbar |
| `FiscalDocumentTable` | `components/fiscal_documents/FiscalDocumentTable.vue` | Tabla de comprobantes con filtros |
| `SriSyncRun*` | `components/sri/` | Historial, estado y detalle de sincronizaciones |

### Ejemplo de página

```vue
<template>
  <AppShell>
    <AppPage
      title="Bandeja de comprobantes"
      description="Comprobantes recibidos y emitidos ante el SRI."
    >
      <template #actions>
        <UButton
          color="primary"
          icon="i-lucide-plus"
          label="Nueva consulta"
        />
      </template>

      <FiscalDocumentTable :fiscal_documents="rows" />
    </AppPage>
  </AppShell>
</template>
```

## Accesibilidad

- Todo control tiene etiqueta asociada (`for`/`id`) y el error se anuncia con
  `role="alert"`.
- Los diálogos usan `role="dialog"`, `aria-modal`, título obligatorio y foco
  atrapado; Escape y el backdrop cierran salvo configuración contraria.
- Las notificaciones usan `role="status"`/`aria-live="polite"` y los errores
  `role="alert"`/`aria-live="assertive"`.
- El foco visible usa el color primario con `outline-offset: 2px`.
- `prefers-reduced-motion` reduce animaciones y transiciones a 0,01 ms.
- `AppShell` incluye un enlace «Saltar al contenido» visible al enfocar.

## Dependencias

- `@nuxt/ui@^4.13` (incluye Reka UI, Tailwind Variants y iconos como
  dependencias internas).
- `@fontsource-variable/nunito` y `@fontsource-variable/jetbrains-mono`.
- `@iconify-json/lucide` para el set de iconos `i-lucide-*`.
- `pinia`, `vue-router`, `uqr` (QR del 2FA).

## Pruebas

`npx vitest run` cubre layouts, páginas y componentes con jsdom. Las páginas se
montan con `stubs: { AppSidebar: true, UDashboardSearch: true }` para aislar el
contenido. `vitest.config.ts` amplía `testTimeout`/`hookTimeout` a 20 s porque
los overlays de Nuxt UI tardan en montar bajo carga.
