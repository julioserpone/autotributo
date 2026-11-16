# 14 — Design system

> Referencia de los tokens y componentes base de la SPA (F4-02). La fuente de
> verdad del alcance sigue siendo `docs/02-implementation-plan.md` §6.

## Principios

- **Dark-first.** El producto se usa de noche y durante jornadas largas: el
  lienzo `#09090b` reduce fatiga y hace que los importes y estados destaquen.
- **Tokens semánticos, no colores crudos.** Los componentes usan `bg-surface`,
  `border-line` o `text-muted`; nunca `zinc-800` o `violet-600`. Cambiar el tema
  es editar `:root` en `resources/css/app.css`.
- **Accesible por defecto.** Foco visible, etiquetas asociadas, `aria-invalid`,
  `role="alert"` en errores, contraste AA y `prefers-reduced-motion`.
- **Sin lógica de negocio.** Los componentes reciben datos y emiten eventos;
  las decisiones fiscales viven en el backend.

## Tokens

Definidos en `resources/css/app.css` (`:root` + `@theme inline`).

| Token | Valor | Uso |
|---|---|---|
| `--canvas` | `#09090b` | Fondo de la aplicación y de los controles |
| `--surface` | `#18181b` | Tarjetas, cabecera, paneles y modales |
| `--raised` | `#27272a` | Filas, botones secundarios y hover |
| `--line` | `#262626` | Bordes por defecto |
| `--line-strong` | `#3f3f46` | Bordes de controles y separadores fuertes |
| `--ink` | `#fafafa` | Texto principal |
| `--muted` | `#a1a1aa` | Texto secundario |
| `--faint` | `#71717a` | Placeholders, ayudas y metadatos |
| `--accent` | `#7c3aed` | Acción primaria y foco |
| `--accent-hover` | `#6d28d9` | Hover de la acción primaria |
| `--accent-soft` | `#2e1065` | Fondo de badges/avisos violeta |
| `--accent-ink` | `#c4b5fd` | Texto violeta sobre fondo oscuro |
| `--danger` | `#dc2626` | Acción destructiva y error |
| `--danger-soft` | `#450a0a` | Fondo de error |
| `--danger-ink` | `#fca5a5` | Texto de error sobre fondo oscuro |
| `--success` | `#10b981` | Éxito y estados completados |
| `--success-soft` | `#052e2b` | Fondo de éxito |
| `--success-ink` | `#6ee7b7` | Texto de éxito |
| `--warning` | `#f59e0b` | Advertencia y estados en curso |
| `--warning-soft` | `#451a03` | Fondo de advertencia |
| `--warning-ink` | `#fcd34d` | Texto de advertencia |

Utilidades derivadas: `bg-canvas`, `bg-surface`, `bg-raised`, `border-line`,
`text-ink`, `text-muted`, `text-accent-ink`, `rounded-control`,
`rounded-card`, `shadow-panel` y `text-2xs`.

La tipografía usa la pila del sistema con `Inter` al frente (`--font-sans`) y
una monoespaciada para claves de acceso y códigos (`--font-mono`).

## Componentes

| Componente | Archivo | Notas |
|---|---|---|
| `AppField` | `components/ui/AppField.vue` | Etiqueta, ayuda y error con `aria-describedby` compartido |
| `AppButton` | `components/ui/AppButton.vue` | Variantes `primary`, `secondary`, `ghost`, `danger`; `loading` con `aria-busy` |
| `AppInput` | `components/ui/AppInput.vue` | `v-model`, `label`, `hint`, `error`, `required`, `maxlength` |
| `AppSelect` | `components/ui/AppSelect.vue` | Select nativo accesible; conserva el tipo del valor |
| `AppCombobox` | `components/ui/AppCombobox.vue` | Búsqueda y filtrado con `reka-ui`; limpieza opcional |
| `AppDateRange` | `components/ui/AppDateRange.vue` | Rango `{from,to}` con validación y botón Limpiar |
| `AppTable` | `components/ui/AppTable.vue` | Estados loading/empty/error, slots por celda y selección múltiple |
| `AppBadge` | `components/ui/AppBadge.vue` | Tonos `neutral`, `accent`, `success`, `warning`, `danger` |
| `AppModal` | `components/ui/AppModal.vue` | Diálogo con foco atrapado, Escape y portal |
| `AppDrawer` | `components/ui/AppDrawer.vue` | Panel lateral izquierdo/derecho sobre el mismo diálogo |
| `AppToastHost` | `components/ui/AppToastHost.vue` | Pila de notificaciones; se monta una vez en `App.vue` |

Los tipos compartidos (`Tone`, `TableColumn`, `DateRangeValue`, etc.) viven en
`components/ui/types.ts` y se reexportan desde `components/ui/index.ts`.

### Ejemplos

```vue
<AppInput
  v-model="email"
  label="Correo"
  type="email"
  autocomplete="username"
  :error="errors.email"
/>

<AppTable
  :columns="columns"
  :rows="invoices"
  :loading="loading"
  :error="error"
  selectable
  @update:selected="selected = $event"
>
  <template #cell-total="{ value }">
    <span class="tabular-nums">{{ formatMoney(value) }}</span>
  </template>
</AppTable>

<AppModal v-model:open="confirmOpen" title="Confirmar revisión">
  <p>¿Aceptar la categoría sugerida?</p>
  <template #footer>
    <AppButton variant="ghost" @click="confirmOpen = false">Cancelar</AppButton>
    <AppButton :loading="saving" @click="accept">Aceptar</AppButton>
  </template>
</AppModal>
```

Notificaciones:

```ts
import { useToast } from '@/lib/toast';

const toast = useToast();
toast.success('Comprobante importado', { description: 'Factura 001-001-000000123' });
toast.error('No se pudo guardar la revisión');
```

## Accesibilidad

- Todo control tiene etiqueta asociada (`for`/`id`) y el error se anuncia con
  `role="alert"`.
- Los diálogos usan `role="dialog"`, `aria-modal`, título obligatorio y foco
  atrapado; Escape y el backdrop cierran salvo `dismissible=false`.
- Las notificaciones usan `role="status"`/`aria-live="polite"` y los errores
  `role="alert"`/`aria-live="assertive"`.
- El foco visible usa el token `--accent` con `outline-offset: 2px`.
- `prefers-reduced-motion` reduce animaciones y transiciones a 0,01 ms.
- La cabecera incluye un enlace «Saltar al contenido» visible al enfocar.

## Dependencias

Se añadieron las bases de shadcn-vue que exige `docs/01-architecture.md`:

- `reka-ui` (Radix Vue) para diálogos, portales y combobox accesibles.
- `class-variance-authority` para variantes tipadas de Button y Badge.
- `clsx` + `tailwind-merge` para el helper `cn()`.

No se añade una librería de iconos: los pocos iconos necesarios son SVG
inline y no engordan el bundle.

## Pruebas

`npm run test:unit -- --run` cubre 50 casos de componentes: variantes, estados
loading/empty/error, validación, teclado, selección y cierre de overlays. El
entorno de jsdom completa las APIs de layout que `reka-ui` necesita
(`resources/js/test/setup.ts`).
