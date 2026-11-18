import { mount } from "@vue/test-utils";
import {
  afterEach,
  beforeEach,
  describe,
  expect,
  it,
  vi,
  type Mock,
} from "vitest";
import { reactive } from "vue";

import SriSyncRunDetailDrawer from "@/components/sri/SriSyncRunDetailDrawer.vue";
import { activeSyncStatuses } from "@/stores/sync";

/**
 * El store se mockea (mismo patrón que `DashboardPage.spec.ts`) para montar el
 * drawer sin tocar la API. El borrador va dentro de un `reactive` porque
 * `USlideover` recibe `open` por `v-model` y necesita una fuente reactiva.
 */
const store = reactive({
  selectedRun: null as Record<string, unknown> | null,
  selectedFiles: [] as Array<Record<string, unknown>>,
  selectedLoading: false,
  polling: false,
  activeStatuses: activeSyncStatuses,
  clearSelection: vi.fn() as Mock,
  statusLabel: (status: string) =>
    status === "partial" ? "Parcial" : "Completado",
  statusColor: () => "success" as const,
});

vi.mock("@/stores/sync", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/stores/sync")>();

  return {
    ...actual,
    useSyncStore: () => store,
  };
});

const baseRun = {
  id: "01M7N1R255M0197A07769R8XQQ",
  company_id: null,
  status: "partial",
  is_active: false,
  poll_after_ms: null,
  date_from: "2026-09-01",
  date_to: "2026-09-30",
  periods: ["2026-09"],
  document_types: ["01", "04"],
  totals: { found: 44, imported: 43, skipped: 1, failed: 0 },
  progress: null,
  error_code: "partial_import",
  error_message: "43 comprobante(s) no se pudieron importar.",
  started_at: null,
  finished_at: null,
  created_at: null,
};

/**
 * El `USlideover` real se monta con su portal de reka-ui y una animación de
 * entrada; `wrapper.text()` no lo incluye. Se monta en `document.body` y se
 * consulta el dialogo ya renderizado.
 */
async function mountDrawer(): Promise<{
  text: () => string;
  /** Título del `UAlert` de error (Nuxt UI lo expone como `[data-slot=title]`). */
  alertTitle: () => HTMLElement | null;
}> {
  const wrapper = mount(SriSyncRunDetailDrawer, {
    props: { open: true },
    attachTo: document.body,
  });

  for (let attempt = 0; attempt < 10 && wrapper.text() === ""; attempt += 1) {
    await new Promise((resolve) => setTimeout(resolve, 20));
  }

  const dialog = (): HTMLElement | null =>
    document.querySelector("[role=dialog]");

  return {
    text: () => dialog()?.textContent ?? "",
    alertTitle: () => {
      const titles = dialog()?.querySelectorAll("[data-slot=title]") ?? [];
      const alert = titles[titles.length - 1] ?? null;

      return alert instanceof HTMLElement ? alert : null;
    },
  };
}

describe("SriSyncRunDetailDrawer", () => {
  beforeEach(() => {
    store.selectedRun = null;
    store.selectedFiles = [];
    store.selectedLoading = false;
  });

  afterEach(() => {
    document.body.innerHTML = "";
  });

  it("muestra el título del error en texto legible, no el código snake_case", async () => {
    store.selectedRun = { ...baseRun };

    const drawer = await mountDrawer();

    expect(drawer.alertTitle()?.textContent).toBe("Importación parcial");
    expect(drawer.text()).toContain("43 comprobante(s) no se pudieron importar.");
    expect(drawer.text()).not.toContain("partial_import");
  });

  it("no muestra la alerta cuando el run terminó sin error", async () => {
    store.selectedRun = {
      ...baseRun,
      status: "completed",
      error_code: null,
      error_message: null,
    };

    const drawer = await mountDrawer();

    // Sin `UAlert`, el único título del drawer es el del propio slideover.
    expect(drawer.alertTitle()?.textContent).toBe("Detalle de la sincronización");
    expect(drawer.text()).toContain("Completado");
  });

  it("no cuenta como avance los XML que todavía no están en S3", async () => {
    store.selectedRun = {
      ...baseRun,
      status: "downloading",
      is_active: true,
      poll_after_ms: 5000,
      totals: { found: 0, imported: 0, skipped: 0, failed: 0 },
      progress: null,
      error_code: null,
      error_message: null,
    };
    store.selectedFiles = [
      { id: 1, status: "available", error_code: null, import_outcome: null },
      { id: 2, status: "available", error_code: null, import_outcome: null },
      ...Array.from({ length: 24 }, (_, index) => ({
        id: index + 3,
        status: "missing",
        error_code: null,
        import_outcome: null,
      })),
    ];

    const drawer = await mountDrawer();

    // 2 archivos disponibles de 26: la descarga va por 22 %, no por 92 %.
    expect(drawer.text()).toContain("22%");
    expect(drawer.text()).not.toContain("92%");
  });

  it("no muestra el aviso transitorio del parser mientras el run sigue activo", async () => {
    store.selectedRun = {
      ...baseRun,
      status: "downloading",
      is_active: true,
      poll_after_ms: 5000,
      error_code: "incomplete_package",
      error_message: "El paquete del robot tiene archivos vacíos o claves inconsistentes.",
    };

    const drawer = await mountDrawer();

    expect(drawer.alertTitle()?.textContent).toBe("Detalle de la sincronización");
    expect(drawer.text()).not.toContain("Paquete del robot incompleto");
  });

  it("no marca 100 % un run fallido que no encontró comprobantes", async () => {
    store.selectedRun = {
      ...baseRun,
      status: "failed",
      is_active: false,
      poll_after_ms: null,
      totals: { found: 0, imported: 0, skipped: 0, failed: 0 },
      progress: null,
      error_code: "no_documents",
      error_message: "El robot no encontró comprobantes recibidos para el período consultado.",
    };

    const drawer = await mountDrawer();

    expect(drawer.text()).toContain("0%");
    expect(drawer.text()).not.toContain("100%");
  });

  it("muestra el loader mientras carga y no reutiliza el run anterior", async () => {
    store.selectedLoading = true;

    const drawer = await mountDrawer();

    expect(drawer.text()).toContain("Cargando detalle de la sincronización");
    expect(drawer.text()).not.toContain(baseRun.id);
  });
});
