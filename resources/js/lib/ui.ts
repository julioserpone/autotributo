/**
 * Clases compartidas por los controles de formulario.
 *
 * Centralizarlas evita que Input, Select y Combobox se desalineen cuando
 * cambie el token de borde, foco o altura.
 */
export const controlClass =
    'w-full rounded-control border border-line bg-canvas px-3 py-2 text-sm text-ink placeholder:text-faint transition focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30 disabled:cursor-not-allowed disabled:opacity-60';

export const invalidControlClass = 'border-danger focus:border-danger focus:ring-danger/30';
