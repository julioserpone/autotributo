import { type ClassValue, clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

/**
 * Une clases condicionales y resuelve conflictos de Tailwind.
 *
 * `clsx` permite pasar strings, arrays y objetos; `tailwind-merge` garantiza
 * que una clase del consumidor (por ejemplo `w-full`) gane sobre la del
 * componente sin depender del orden de las hojas de estilo.
 */
export function cn(...inputs: ClassValue[]): string {
    return twMerge(clsx(inputs));
}
