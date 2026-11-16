# ADR 0009: adopción de Nuxt UI como base de la SPA

- **Estado:** aceptado
- **Fecha:** 2026-11-16
- **Ámbito:** frontend (Vue 3 + Vite).

## Contexto

F4-02 construyó el design system sobre shadcn-vue + Reka UI (11 componentes
`App*`, tokens dark-first). Para F4-04 se valoró cambiar la base a Nuxt UI,
que internamente usa Reka UI y aporta un catálogo completo (modales, tablas,
formularios, toasts) coherente con Tailwind 4.

## Decisión

1. Adoptar `@nuxt/ui@^4.12` como base de componentes de la SPA, integrado con
   Vite (`@nuxt/ui/vite`) y el plugin Vue (`@nuxt/ui/vue-plugin`), sin framework
   Nuxt.
2. Eliminar `reka-ui`, `class-variance-authority`, `clsx` y `tailwind-merge` como
   dependencias directas; Nuxt UI las gestiona internamente.
3. Los tokens propios (`canvas`, `surface`, `elevated`, `line`, `ink`, `muted`,
   `accent`, `brand`) se mantienen en `resources/css/app.css` y se mapean a
   `--ui-*` para que Nuxt UI herede el tema.
4. El tema claro/oscuro se gestiona con un composable propio (`useTheme`) con
   persistencia en `localStorage` (`autotributo.theme`), default oscuro, sin
   depender del color-mode de Nuxt UI (`colorMode: false`).
5. Los componentes `App*` se reescriben como wrappers ligeros sobre `U*` o se
   eliminan; se conserva `AppStatusBadge` y `AppThemeToggle`.

## Consecuencias

- La integración exige generar `auto-imports.d.ts` y `components.d.ts` en el
  build previo al typecheck (`npm run typecheck` = `vite build && vue-tsc`).
- Se actualiza `docs/01-architecture.md` (stack UI) y `docs/14-design-system.md`.
- Los tests de componentes usan el plugin de Nuxt UI en `vitest.config.ts`.
EOF
echo done