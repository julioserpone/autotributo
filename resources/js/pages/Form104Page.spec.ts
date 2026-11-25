import { mount } from "@vue/test-utils";
import { createMemoryHistory, createRouter } from "vue-router";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";

import Form104Page from "@/pages/Form104Page.vue";
import { useForm104Store } from "@/stores/form104";

const flags = {
  loaded: true,
  loading: false,
  hasCompany: false,
  activeCompany: null as { id: string; legal_name: string } | null,
};

/** Doble plano del store de empresas: la plantilla lee banderas sin reactividad. */
const companyStoreMock = {
  get loaded() {
    return flags.loaded;
  },
  get loading() {
    return flags.loading;
  },
  get hasCompany() {
    return flags.hasCompany;
  },
  get activeCompany() {
    return flags.activeCompany;
  },
  fetchCompanies: vi.fn(async () => undefined),
};

vi.mock("@/stores/company", () => ({
  useCompanyStore: () => companyStoreMock,
}));

function buildRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/declarations/104", name: "form-104", component: Form104Page },
      {
        path: "/fiscal-findings",
        name: "fiscal-findings",
        component: { template: "<div />" },
      },
      {
        path: "/fiscal-documents",
        name: "fiscal_documents",
        component: { template: "<div />" },
      },
      {
        path: "/companies",
        name: "companies",
        component: { template: "<div />" },
      },
    ],
  });
}

function mountPage(
  router: ReturnType<typeof buildRouter>,
  pinia: ReturnType<typeof createPinia>,
) {
  return mount(Form104Page, {
    global: {
      plugins: [pinia, router],
      stubs: { AppSidebar: true, UDashboardSearch: true },
    },
  });
}

function draft(overrides: Record<string, unknown> = {}) {
  return {
    company: {
      public_id: "company-1",
      legal_name: "ACME C.A.",
      ruc: "1790012345001",
    },
    period: {
      form_code: "104",
      resolution: "NAC-DGERCGC16-00000210",
      year: 2026,
      month: 9,
      from: "2026-09-01",
      to: "2026-09-30",
      status: "open" as string | null,
      locked_at: null,
      computed_at: null as string | null,
      has_period: true,
    },
    status: {
      draft: true,
      editable: false,
      source: "period_aggregates",
      requires_signoff: true,
      notice: "Borrador derivado de los comprobantes importados.",
    },
    lines: [
      {
        field: "401",
        line_code: "401",
        section: "ventas",
        label: "Ventas locales gravadas tarifa diferente de cero",
        data_type: "amount",
        is_required: true,
        value_cents: 1_000_000,
        amount_cents: 1_000_000,
        base_cents: null,
        rate: null,
        applies_to: "ventas",
        sources: [
          {
            type: "fiscal_documents",
            key: "ventas",
            label: "Ver comprobantes de venta del período",
            filter: { date_from: "2026-09-01", date_to: "2026-09-30" },
          },
        ],
        requires_signoff: false,
        unavailable: false,
        manual: false,
        note: null,
      },
      {
        field: "429",
        line_code: "429",
        section: "ventas",
        label: "Total impuesto generado",
        data_type: "amount",
        is_required: true,
        value_cents: 150_000,
        amount_cents: 150_000,
        base_cents: null,
        rate: null,
        applies_to: "ventas",
        sources: [],
        requires_signoff: false,
        unavailable: false,
        manual: false,
        note: null,
      },
      {
        field: "529",
        line_code: "529",
        section: "compras",
        label: "Total IVA generado en adquisiciones y pagos",
        data_type: "amount",
        is_required: false,
        value_cents: 60_000,
        amount_cents: 60_000,
        base_cents: null,
        rate: null,
        applies_to: "compras",
        sources: [],
        requires_signoff: false,
        unavailable: false,
        manual: false,
        note: null,
      },
      {
        field: "564",
        line_code: "564",
        section: "compras",
        label: "Crédito tributario aplicable en este período",
        data_type: "amount",
        is_required: true,
        value_cents: 50_000,
        amount_cents: 50_000,
        base_cents: null,
        rate: null,
        applies_to: "compras",
        sources: [],
        requires_signoff: true,
        unavailable: false,
        manual: false,
        note: null,
      },
      {
        field: "799",
        line_code: "799",
        section: "retencion",
        label: "Total impuesto retenido",
        data_type: "amount",
        is_required: true,
        value_cents: 10_000,
        amount_cents: 10_000,
        base_cents: null,
        rate: null,
        applies_to: "compras",
        sources: [],
        requires_signoff: false,
        unavailable: false,
        manual: false,
        note: null,
      },
      {
        field: "890",
        line_code: "890",
        section: "pago",
        label: "Pago previo",
        data_type: "amount",
        is_required: false,
        value_cents: null,
        amount_cents: null,
        base_cents: null,
        rate: null,
        applies_to: "manual",
        sources: [],
        requires_signoff: false,
        unavailable: true,
        manual: true,
        note: "Dato que no proviene de comprobantes.",
      },
    ],
    proportionality: {
      sales_zero: 0,
      sales_general: 1_000_000,
      proportionality_factor: "0.000000",
      proportionality_complement: "1.000000",
      purchases_common_tax_to_credit: 60_000,
    },
    previous_periods: [],
    findings: [] as {
      code: string;
      label: string;
      severity: string;
      total: number;
    }[],
    official_form: {
      form_code: "104",
      resolution: "NAC-DGERCGC16-00000210",
      sections: [
        {
          key: "ventas",
          title: "Resumen de ventas y otras operaciones del período",
        },
        { key: "compras", title: "Resumen de adquisiciones y pagos" },
        { key: "retencion", title: "Agente de retención del IVA" },
        { key: "pago", title: "Total consolidado y pago" },
      ],
    },
    ...overrides,
  };
}

describe("Form104Page", () => {
  let pinia: ReturnType<typeof createPinia>;

  beforeEach(() => {
    pinia = createPinia();
    setActivePinia(pinia);
    flags.loaded = true;
    flags.loading = false;
    flags.hasCompany = false;
    flags.activeCompany = null;
  });

  it("muestra el estado vacío cuando no hay empresas", async () => {
    const router = buildRouter();
    await router.push("/declarations/104");
    await router.isReady();

    const wrapper = mountPage(router, pinia);

    expect(wrapper.text()).toContain(
      "Sin empresas no hay comprobantes que consolidar en el 104",
    );
  });

  it("pide el borrador de la empresa activa y pinta las secciones del formulario", async () => {
    flags.hasCompany = true;
    flags.activeCompany = { id: "company-1", legal_name: "ACME C.A." };

    const store = useForm104Store();
    store.setPeriod(2026, 9);
    store.draft = draft() as never;

    const fetchSpy = vi.spyOn(store, "fetchDraft").mockResolvedValue(undefined);

    const router = buildRouter();
    await router.push("/declarations/104");
    await router.isReady();

    const wrapper = mountPage(router, pinia);
    await new Promise((resolve) => setTimeout(resolve, 0));

    expect(fetchSpy).toHaveBeenCalledWith("company-1");
    expect(wrapper.text()).toContain("ACME C.A.");
    expect(wrapper.text()).toContain("NAC-DGERCGC16-00000210");
    expect(wrapper.text()).toContain(
      "Resumen de ventas y otras operaciones del período",
    );
    expect(wrapper.text()).toContain("Total consolidado y pago");
    expect(wrapper.text()).toContain("Borrador, no una declaración");
    expect(wrapper.text()).toContain("$10,000.00");
    expect(wrapper.text()).toContain("$1,500.00");
    // Los casilleros sin cifra derivada se muestran pendientes, no en cero.
    expect(wrapper.text()).toContain("Entrada manual");
    expect(wrapper.text()).toContain("Ver comprobantes");
  });

  it("avisa de los hallazgos abiertos que bloquean el cierre", async () => {
    flags.hasCompany = true;
    flags.activeCompany = { id: "company-1", legal_name: "ACME C.A." };

    const store = useForm104Store();
    store.draft = draft({
      findings: [
        {
          code: "TOTALS_MISMATCH",
          label: "TOTALS_MISMATCH",
          severity: "critical",
          total: 3,
        },
      ],
    }) as never;
    vi.spyOn(store, "fetchDraft").mockResolvedValue(undefined);

    const router = buildRouter();
    await router.push("/declarations/104");
    await router.isReady();

    const wrapper = mountPage(router, pinia);
    await new Promise((resolve) => setTimeout(resolve, 0));

    expect(wrapper.text()).toContain("3 hallazgo(s) abierto(s) en el período");
  });

  it("explica cuando el período no tiene comprobantes procesados", async () => {
    flags.hasCompany = true;
    flags.activeCompany = { id: "company-1", legal_name: "ACME C.A." };

    const store = useForm104Store();
    store.draft = draft({
      period: { ...draft().period, has_period: false, status: null },
    }) as never;
    vi.spyOn(store, "fetchDraft").mockResolvedValue(undefined);

    const router = buildRouter();
    await router.push("/declarations/104");
    await router.isReady();

    const wrapper = mountPage(router, pinia);
    await new Promise((resolve) => setTimeout(resolve, 0));

    expect(wrapper.text()).toContain("Período sin comprobantes procesados");
  });

  it("muestra el error de carga con opción de reintentar", async () => {
    flags.hasCompany = true;
    flags.activeCompany = { id: "company-1", legal_name: "ACME C.A." };

    const store = useForm104Store();
    store.error = "No se pudo cargar el borrador del 104.";
    vi.spyOn(store, "fetchDraft").mockResolvedValue(undefined);

    const router = buildRouter();
    await router.push("/declarations/104");
    await router.isReady();

    const wrapper = mountPage(router, pinia);
    await new Promise((resolve) => setTimeout(resolve, 0));

    expect(wrapper.text()).toContain("No se pudo cargar el formulario");
    expect(wrapper.text()).toContain("No se pudo cargar el borrador del 104.");
    expect(wrapper.text()).toContain("Reintentar");
  });
});
