import type { CommandPaletteItem, NavigationMenuItem } from '@nuxt/ui';

/**
 * Navegación lateral de la aplicación.
 *
 * Vive fuera de los componentes para que la barra lateral y la paleta de
 * comandos (⌘K) compartan exactamente las mismas entradas. Los ítems marcados
 * como `soon` se muestran deshabilitados con la etiqueta «Pronto».
 */
export interface AppNavItem {
    label: string;
    icon: string;
    route?: string;
    soon?: boolean;
}

export const primaryNav: AppNavItem[] = [
    { label: 'Panel', icon: 'i-lucide-layout-dashboard', route: 'dashboard' },
    { label: 'Comprobantes', icon: 'i-lucide-file-text', route: 'fiscal_documents' },
    { label: 'Hallazgos', icon: 'i-lucide-shield-alert', route: 'fiscal-findings' },
    { label: 'Contribuyentes', icon: 'i-lucide-building-2', route: 'companies' },
    { label: 'Revisión de clasificación', icon: 'i-lucide-tags', route: 'classifications' },
    { label: 'Sincronización SRI', icon: 'i-lucide-refresh-cw', route: 'sync' },
    { label: 'Formulario 104', icon: 'i-lucide-chart-column', route: 'form-104' },
];

export const systemNav: AppNavItem[] = [
    { label: 'Seguridad (2FA)', icon: 'i-lucide-shield-check', route: 'two-factor-setup' },
    { label: 'Equipo e invitaciones', icon: 'i-lucide-users', route: 'invitations' },
    { label: 'Firma electrónica (.p12)', icon: 'i-lucide-key-round', soon: true },
    { label: 'Configuración', icon: 'i-lucide-settings', soon: true },
];

function toNavigationItem(item: AppNavItem): NavigationMenuItem {
    return {
        label: item.label,
        icon: item.icon,
        ...(item.route !== undefined ? { to: { name: item.route } } : {}),
        ...(item.soon === true ? { disabled: true, badge: 'Pronto' } : {}),
    };
}

function toPaletteItem(item: AppNavItem): CommandPaletteItem {
    return {
        label: item.label,
        icon: item.icon,
        ...(item.route !== undefined ? { to: { name: item.route } } : {}),
    };
}

export const primaryMenu: NavigationMenuItem[] = primaryNav.map(toNavigationItem);
export const systemMenu: NavigationMenuItem[] = systemNav.map(toNavigationItem);

/** Grupos de la paleta de comandos: solo entradas navegables. */
export const navigationGroups = [
    {
        id: 'plataforma',
        label: 'Plataforma tributaria',
        items: primaryNav.filter((item) => item.route !== undefined).map(toPaletteItem),
    },
    {
        id: 'sistema',
        label: 'Sistema',
        items: systemNav.filter((item) => item.route !== undefined).map(toPaletteItem),
    },
];
