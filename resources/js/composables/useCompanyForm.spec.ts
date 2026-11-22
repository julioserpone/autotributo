import { describe, expect, it } from 'vitest';

import {
    emptyCompanyForm,
    formToPayload,
    validateCompanyForm,
} from '@/composables/useCompanyForm';

describe('validateCompanyForm', () => {
    it('exige RUC de 13 caracteres y razón social', () => {
        const errors = validateCompanyForm(emptyCompanyForm());

        expect(errors.ruc).toEqual(['El RUC debe tener 13 caracteres.']);
        expect(errors.legal_name).toEqual(['La razón social es obligatoria.']);
    });

    it('rechaza RUC con caracteres no numéricos', () => {
        const form = {
            ...emptyCompanyForm(),
            ruc: '179001691900A',
            legal_name: 'Empresa Uno',
        };

        expect(validateCompanyForm(form).ruc).toEqual(['El RUC debe contener solo dígitos.']);
    });

    it('valida el formato del correo electrónico', () => {
        const form = {
            ...emptyCompanyForm(),
            ruc: '1790016919001',
            legal_name: 'Empresa Uno',
            email: 'no-es-correo',
        };

        expect(validateCompanyForm(form).email).toEqual(['El correo electrónico no es válido.']);
    });

    it('valida el código de actividad económica', () => {
        const form = {
            ...emptyCompanyForm(),
            ruc: '1790016919001',
            legal_name: 'Empresa Uno',
            economic_activity_code: 'M7490100!',
        };

        expect(validateCompanyForm(form).economic_activity_code).toEqual([
            'El código debe ser alfanumérico de hasta 10 caracteres.',
        ]);
    });

    it('exige resolución cuando es agente de retención', () => {
        const form = {
            ...emptyCompanyForm(),
            ruc: '1790016919001',
            legal_name: 'Empresa Uno',
            is_withholding_agent: true,
        };

        expect(validateCompanyForm(form).withholding_resolution).toEqual([
            'La resolución es obligatoria para agentes de retención.',
        ]);
    });

    it('no devuelve errores con un formulario válido', () => {
        const form = {
            ...emptyCompanyForm(),
            ruc: '1790016919001',
            legal_name: 'Empresa Uno',
            email: 'contacto@empresa.test',
            economic_activity_code: 'M74901001',
        };

        expect(validateCompanyForm(form)).toEqual({});
    });
});

describe('formToPayload', () => {
    it('recorta textos y convierte vacíos en nulos', () => {
        const payload = formToPayload({
            ...emptyCompanyForm(),
            ruc: ' 1790016919001 ',
            legal_name: ' Empresa Uno ',
            trade_name: '  ',
            address: '',
            email: ' contacto@empresa.test ',
        });

        expect(payload.ruc).toBe('1790016919001');
        expect(payload.legal_name).toBe('Empresa Uno');
        expect(payload.trade_name).toBeNull();
        expect(payload.address).toBeNull();
        expect(payload.email).toBe('contacto@empresa.test');
    });

    it('descarta la resolución si no es agente de retención', () => {
        const payload = formToPayload({
            ...emptyCompanyForm(),
            withholding_resolution: '123',
            is_withholding_agent: false,
        });

        expect(payload.withholding_resolution).toBeNull();
    });

    it('conserva la resolución si es agente de retención', () => {
        const payload = formToPayload({
            ...emptyCompanyForm(),
            is_withholding_agent: true,
            withholding_resolution: ' 123 ',
        });

        expect(payload.withholding_resolution).toBe('123');
    });
});
