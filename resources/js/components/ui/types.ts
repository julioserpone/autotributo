/**
 * Tipos compartidos por los componentes del design system.
 */

export type Tone = 'neutral' | 'accent' | 'success' | 'warning' | 'danger';

export interface SelectOption {
    value: string | number;
    label: string;
    disabled?: boolean;
}

export interface ComboboxOption extends SelectOption {
    description?: string;
}

export interface DateRangeValue {
    from: string | null;
    to: string | null;
}

export interface TableColumn {
    key: string;
    label: string;
    align?: 'left' | 'right' | 'center';
    width?: string;
    class?: string;
}

export type TableRow = Record<string, unknown>;

export type ToastTone = 'info' | 'success' | 'warning' | 'error';

export interface ToastMessage {
    id: number;
    tone: ToastTone;
    title: string;
    description?: string | undefined;
    duration: number;
}

export interface ToastOptions {
    description?: string | undefined;
    duration?: number | undefined;
}
