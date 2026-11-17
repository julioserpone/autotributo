import { describe, expect, it } from "vitest";

import {
  documentTypeOptions,
  fileErrorLabel,
  fileStatusLabel,
  formatDocumentTypes,
  formatPeriods,
  importOutcomeLabel,
  syncErrorLabel,
} from "@/lib/sri";

describe("syncErrorLabel", () => {
  it("traduce los códigos de error del run a texto legible", () => {
    expect(syncErrorLabel("partial_import")).toBe("Importación parcial");
    expect(syncErrorLabel("robot_timeout")).toBe("El robot no respondió");
    expect(syncErrorLabel("no_documents")).toBe(
      "Sin comprobantes en el período",
    );
  });

  it("nunca devuelve convenciones snake_case, ni siquiera para códigos desconocidos", () => {
    expect(syncErrorLabel("codigo_nuevo_del_backend")).toBe(
      "Error de sincronización",
    );
    expect(syncErrorLabel(null)).toBe("Error de sincronización");
    expect(syncErrorLabel(undefined)).toBe("Error de sincronización");
  });
});

describe("formatDocumentTypes", () => {
  it("traduce los códigos del SRI", () => {
    expect(formatDocumentTypes(["01", "04"])).toBe(
      "Facturas, Notas de crédito",
    );
  });

  it("trata la lista vacía como todos los tipos", () => {
    expect(formatDocumentTypes([])).toBe("Todos los tipos");
    expect(formatDocumentTypes(null)).toBe("Todos los tipos");
  });
});

describe("formatPeriods", () => {
  it("prefiere los períodos y cae al rango de fechas", () => {
    expect(formatPeriods({ periods: ["2026-09", "2026-10"] })).toBe(
      "2026-09, 2026-10",
    );
    expect(
      formatPeriods({
        periods: [],
        date_from: "2026-09-01",
        date_to: "2026-09-30",
      }),
    ).toBe("2026-09-01 → 2026-09-30");
  });
});

describe("documentTypeOptions", () => {
  it("no repite etiquetas entre opciones", () => {
    const labels = documentTypeOptions.map((option) => option.label);

    expect(new Set(labels).size).toBe(labels.length);
  });

  it("ofrece solo tipos con etiqueta propia", () => {
    expect(documentTypeOptions.map((option) => option.value)).toEqual(["01", "03", "04", "05", "07"]);
    expect(documentTypeOptions.map((option) => option.label)).toContain("Comprobantes de retención");
  });
});

describe("etiquetas de archivo", () => {
  it("traduce estados e import_outcome sin snake_case", () => {
    expect(fileStatusLabel("processed")).toBe("Procesado");
    expect(fileStatusLabel("invalid")).toBe("Inválido");
    expect(importOutcomeLabel("skipped")).toBe("Omitido (ya registrado)");
    expect(importOutcomeLabel("created")).toBe("Importado");
  });

  it("no devuelve códigos crudos para valores desconocidos", () => {
    expect(fileStatusLabel("estado_nuevo")).toBe("Estado desconocido");
    expect(importOutcomeLabel("outcome_nuevo")).toBe("Resultado desconocido");
    expect(fileErrorLabel("unexpected_error")).toBe("Error inesperado");
    expect(fileErrorLabel("codigo_nuevo")).toBe("Error al procesar el archivo");
  });

  it("devuelve null cuando no hay resultado ni error que mostrar", () => {
    expect(importOutcomeLabel(null)).toBeNull();
    expect(fileErrorLabel(null)).toBeNull();
  });
});
