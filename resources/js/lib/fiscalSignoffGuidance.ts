export interface FiscalSignoffGuidance {
    purpose: string;
    checklist: string[];
    currentOperation: string;
    expectedOutcome: string;
    evidenceGap: string;
    sources: string[];
}

export const fiscalSignoffGuidance: Record<string, FiscalSignoffGuidance> = {
    'iva-and-retention-catalogs': {
        purpose: 'Revisar que los códigos de IVA y retenciones tengan la descripción, porcentaje y fechas de vigencia que corresponden.',
        checklist: [
            'Compara cada código ATS con su descripción y porcentaje en la Ficha Técnica vigente del SRI.',
            'Comprueba desde qué fecha y hasta qué fecha aplica cada tarifa; los códigos pueden cambiar de significado o porcentaje según el período.',
            'Distingue los códigos de porcentaje de IVA de los códigos de retención y de los números de casillero del formulario.',
            'Señala cualquier código desconocido, duplicado o con fechas que se crucen.',
        ],
        currentOperation: 'La aplicación tiene catálogos internos con códigos y vigencias. Esta tarjeta muestra un resumen, pero no presenta la tabla completa ni permite comparar las filas con el documento oficial.',
        expectedOutcome: 'Cada código usado en un período debe corresponder a una única descripción y porcentaje respaldados por la norma vigente para ese período.',
        evidenceGap: 'Antes de dar OK, necesitas revisar la tabla del catálogo junto con la Ficha Técnica del SRI. Esa comparación todavía no se muestra en esta pantalla.',
        sources: ['Ficha Técnica Transaccional Simplificado (ATS) del SRI', 'Normativa del SRI vigente para el período revisado'],
    },
    'expense-categories': {
        purpose: 'Revisar que las categorías de gastos sean comprensibles y que su tratamiento como deducible o no deducible sea correcto.',
        checklist: [
            'Comprueba que cada categoría describa un tipo de gasto identificable en comprobantes reales.',
            'Revisa por separado si la categoría puede ser gasto del negocio y si puede ser deducible para fines tributarios.',
            'Busca categorías duplicadas, demasiado generales o que mezclen gastos con tratamientos distintos.',
            'Confirma que los casos dudosos queden para revisión humana y no se marquen automáticamente como deducibles.',
        ],
        currentOperation: 'La aplicación mantiene categorías y marcas internas de deducibilidad. Esta tarjeta no muestra el árbol completo ni ejemplos de comprobantes asociados.',
        expectedOutcome: 'Las categorías deben ser claras, no solaparse y tener un tratamiento tributario sustentado; la categoría por sí sola no debe sustituir el criterio contable.',
        evidenceGap: 'Solicita el listado de categorías con sus marcas y ejemplos de gastos antes de aprobar. Esa evidencia no está incluida en esta tarjeta.',
        sources: ['Normativa tributaria aplicable al tipo de contribuyente', 'Políticas contables del negocio'],
    },
    'form104-proportionality': {
        purpose: 'Confirmar la fórmula del factor de proporcionalidad y cómo afecta el crédito tributario de compras en el Formulario 104.',
        checklist: [
            'Revisa la fórmula oficial del casillero 563 y qué casilleros de ventas entran en el numerador y denominador.',
            'Comprueba escenarios de ventas solo con tarifa general, solo con tarifa 0 % y ventas mixtas.',
            'Verifica el tratamiento de un período sin ventas y el redondeo del factor.',
            'Confirma cómo se aplica el factor al IVA de compras y al casillero 564, sin asumir que todo el IVA de compras da derecho a crédito.',
        ],
        currentOperation: 'Existe un cálculo interno del factor con seis decimales. La aprobación contable no detiene ni modifica ese cálculo, y el resultado debe considerarse provisional hasta confirmar su correspondencia con el formulario oficial.',
        expectedOutcome: 'La fórmula y sus resultados deben coincidir con la guía vigente del Formulario 104 y con ejemplos calculados de forma independiente.',
        evidenceGap: 'La tarjeta no presenta ejemplos con resultado del sistema y cálculo manual. Además, debe conciliarse la fórmula interna con la fórmula oficial de los casilleros 563 y 564.',
        sources: ['Guía del Formulario 104 del SRI', 'Cálculo manual independiente para los escenarios de prueba'],
    },
    'fiscal-risk-rules': {
        purpose: 'Revisar qué problemas fiscales detecta la aplicación, qué gravedad asigna y cuándo permite resolverlos automáticamente.',
        checklist: [
            'Confirma que un total distinto entre XML y comprobante se trate como crítico.',
            'Revisa que los comprobantes duplicados y los RUC inválidos se presenten como advertencias y puedan investigarse.',
            'Confirma que una empresa identificada como fantasma se trate con la gravedad adecuada.',
            'Distingue los hallazgos que ya se detectan de los que todavía no están implementados.',
        ],
        currentOperation: 'Hoy se detectan cuatro casos: totales inconsistentes, empresas fantasma, RUC inválido y comprobantes duplicados. Algunas reglas previstas, como comprobantes fuera de período o retenciones faltantes, aún no se ejecutan.',
        expectedOutcome: 'Cada hallazgo debe explicar el problema, señalar el comprobante y evitar que una inconsistencia crítica pase inadvertida.',
        evidenceGap: 'La tarjeta no contiene ejemplos de comprobantes ni muestra el resultado de cada regla. Puedes consultar los hallazgos disponibles en la sección “Hallazgos”, pero las reglas pendientes necesitan desarrollarse.',
        sources: ['Sección “Hallazgos” de Autotributo', 'Criterios de revisión y control contable del negocio'],
    },
    'form104-official-lines': {
        purpose: 'Confirmar que la estructura y los nombres de los casilleros correspondan al Formulario 104 oficial vigente.',
        checklist: [
            'Compara nombres, números y orden de las secciones con el formulario publicado por el SRI.',
            'Comprueba que los campos que requieren información manual estén identificados como tales.',
            'En “Formulario 104”, trata “No disponible” como dato pendiente, nunca como cero.',
            'Comprueba que los importes tengan una fuente identificable y que los subtotales no dupliquen valores.',
        ],
        currentOperation: 'La pantalla “Formulario 104” presenta la estructura oficial y un borrador por período. Algunos campos se muestran como no disponibles o manuales porque la aplicación todavía no tiene sus datos de origen.',
        expectedOutcome: 'La estructura coincide con el formulario del SRI; cada importe tiene un origen claro y los campos sin datos se mantienen pendientes.',
        evidenceGap: 'La tarjeta de aprobación no muestra el formulario completo. Ábrelo desde el menú lateral “Formulario 104” y revisa la sección y período correspondientes.',
        sources: ['Formulario 104 vigente del SRI', 'Resolución NAC-DGERCGC16-00000210', 'Sección “Formulario 104” de Autotributo'],
    },
    'period-aggregate-rate-resolution': {
        purpose: 'Confirmar cómo se obtiene la tarifa correcta cuando el resumen del comprobante trae un código de porcentaje, pero no informa la tarifa numérica.',
        checklist: [
            'Comprueba que el código del comprobante se busque en la tarifa vigente para la fecha del período.',
            'Revisa períodos en que cambió una tarifa y confirma que se use la tarifa histórica correcta.',
            'Compara por separado base e IVA y verifica que facturas con códigos o tarifas diferentes no se mezclen.',
            'Confirma que los códigos desconocidos queden identificados para revisión.',
        ],
        currentOperation: 'Al recalcular, la aplicación resuelve la tarifa desde el catálogo vigente al cierre del período cuando el resumen del XML no trae una tarifa numérica. Después agrupa los importes por dirección, tipo de comprobante, impuesto, código y tarifa.',
        expectedOutcome: 'Cada agregado debe conservar su código y tarifa correctos, y la suma de bases e IVA debe poder reconciliarse con los comprobantes de origen.',
        evidenceGap: 'Esta tarjeta no muestra el detalle de comprobantes ni una conciliación de importes. Se necesita un reporte de comparación para verificar el resultado antes de aprobar.',
        sources: ['Ficha Técnica del comprobante electrónico del SRI', 'Comprobantes del período y reporte de agregados'],
    },
    'form104-complete-scope': {
        purpose: 'Definir qué información debe alimentar cada grupo de casilleros del Formulario 104 y qué datos requieren ingreso manual.',
        checklist: [
            'Revisa los casilleros de ventas, compras, liquidación, crédito tributario, retenciones y pago.',
            'Separa los datos que vienen de comprobantes de los que requieren información adicional de la contadora.',
            'Confirma el tratamiento de activos fijos, notas de crédito y débito, saldos de períodos anteriores y ajustes.',
            'No apruebes una cifra repetida en casilleros distintos ni un campo pendiente presentado como cero.',
        ],
        currentOperation: 'La aplicación genera un borrador parcial. La clasificación de compras por naturaleza fiscal, activo fijo y derecho a crédito aún necesita datos adicionales; los créditos tributarios se cargarán mediante una interfaz futura.',
        expectedOutcome: 'Cada casillero debe tener una regla y fuente explícitas, o quedar claramente marcado como pendiente/manual hasta recibir los datos necesarios.',
        evidenceGap: 'El alcance completo no está implementado. El borrador puede mostrar campos no disponibles y no debe usarse como declaración lista para presentar.',
        sources: ['Guía del Formulario 104 del SRI', 'Sección “Formulario 104” de Autotributo', 'Información de créditos tributarios entregada por la contadora'],
    },
    'ats-iva-nature-mapping': {
        purpose: 'Definir qué significa fiscalmente cada código de IVA ATS usado en las compras y qué información adicional hace falta para clasificarlo.',
        checklist: [
            'Comprueba la descripción y tarifa de cada código en la tabla ATS vigente.',
            'No deduzcas del porcentaje si una compra da derecho a crédito tributario.',
            'Distingue compras corrientes, activos fijos y compras de uso mixto; requieren información propia.',
            'Revisa ejemplos de compras y confirma que el sistema conserve el código aplicado en cada línea del comprobante.',
        ],
        currentOperation: 'El XML puede incluir impuestos por ítem, pero el importador actual no conserva ese detalle en la información disponible para el cálculo. El código de tarifa, por sí solo, no determina el derecho a crédito ni si se trata de un activo fijo.',
        expectedOutcome: 'La aplicación debe conservar el impuesto de cada línea y combinar el código ATS con datos explícitos sobre uso, activo fijo y derecho a crédito.',
        evidenceGap: 'Aún no existe evidencia por línea en esta pantalla y falta ampliar la importación y persistencia de impuestos por ítem. No es posible aprobar una clasificación automática completa de compras todavía.',
        sources: ['Ficha Técnica Transaccional Simplificado (ATS) del SRI', 'XML de comprobantes de compra', 'Criterio contable sobre uso y derecho a crédito'],
    },
    'ats-retention-mapping': {
        purpose: 'Vincular los códigos ATS de retención de IVA, sus porcentajes y vigencias con los casilleros correctos del Formulario 104.',
        checklist: [
            'Confirma que cada código ATS se identifique como retención de IVA y no como retención de impuesto a la renta.',
            'Compara porcentaje, descripción y vigencia con la tabla oficial del ATS.',
            'Verifica la correspondencia con los casilleros 721, 723, 725, 727, 729 y 731 del formulario vigente.',
            'Prueba al menos un comprobante por porcentaje y comprueba la suma del total retenido.',
        ],
        currentOperation: 'El catálogo interno y el formulario contienen referencias de retención, pero su correspondencia con los códigos ATS y los casilleros todavía requiere conciliación. La pantalla no muestra esa tabla comparativa.',
        expectedOutcome: 'Cada retención debe clasificarse por tipo, código ATS, porcentaje, vigencia y casillero correcto, con importes conciliados contra los comprobantes.',
        evidenceGap: 'Existe una discrepancia pendiente de validar entre el catálogo y los casilleros del formulario. No des OK hasta que se presente una tabla comparativa respaldada por la Ficha Técnica vigente.',
        sources: ['Ficha Técnica Transaccional Simplificado (ATS), tabla de retenciones', 'Formulario 104 vigente del SRI', 'Comprobantes de retención del período'],
    },
};
