<?php

declare(strict_types=1);

/**
 * Sobre SOAP de autorización del SRI con la misma estructura que devuelve
 * `autorizacionComprobante` (sin datos fiscales reales).
 *
 * Estructura real: `soap:Body > ns2:autorizacionComprobanteResponse >
 * RespuestaAutorizacionComprobante`, con `comprobante` como XML escapado. Se
 * usa para probar el desenvuelto del sobre y la normalización de namespaces.
 */
$comprobante = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<factura id="comprobante" version="1.1.0">
    <infoTributaria>
        <ambiente>2</ambiente>
        <tipoEmision>1</tipoEmision>
        <razonSocial>EMISOR DE PRUEBA S.A.</razonSocial>
        <ruc>1790012345001</ruc>
        <claveAcceso>1709202601179001234500120010090019603451234567812</claveAcceso>
        <codDoc>01</codDoc>
        <estab>001</estab>
        <ptoEmi>009</ptoEmi>
        <secuencial>001960345</secuencial>
        <dirMatriz>AV. SIEMPRE VIVA</dirMatriz>
    </infoTributaria>
    <infoFactura>
        <fechaEmision>17/09/2026</fechaEmision>
        <tipoIdentificacionComprador>05</tipoIdentificacionComprador>
        <razonSocialComprador>RECEPTOR DE PRUEBA</razonSocialComprador>
        <identificacionComprador>1758871873</identificacionComprador>
        <totalSinImpuestos>23.48</totalSinImpuestos>
        <totalDescuento>0.00</totalDescuento>
        <totalConImpuestos>
            <totalImpuesto>
                <codigo>2</codigo>
                <codigoPorcentaje>4</codigoPorcentaje>
                <baseImponible>23.48</baseImponible>
                <valor>3.52</valor>
            </totalImpuesto>
        </totalConImpuestos>
        <propina>0.00</propina>
        <importeTotal>27.00</importeTotal>
        <moneda>DOLAR</moneda>
    </infoFactura>
    <detalles>
        <detalle>
            <codigoPrincipal>0103</codigoPrincipal>
            <descripcion>SUPER</descripcion>
            <cantidad>5.637000</cantidad>
            <precioUnitario>4.165217</precioUnitario>
            <descuento>0.00</descuento>
            <precioTotalSinImpuesto>23.48</precioTotalSinImpuesto>
            <impuestos>
                <impuesto>
                    <codigo>2</codigo>
                    <codigoPorcentaje>4</codigoPorcentaje>
                    <tarifa>15.00</tarifa>
                    <baseImponible>23.48</baseImponible>
                    <valor>3.52</valor>
                </impuesto>
            </impuestos>
        </detalle>
    </detalles>
</factura>
XML;

return sprintf(
    '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
    .'<ns2:autorizacionComprobanteResponse xmlns:ns2="http://ec.gob.sri.ws.autorizacion">'
    .'<RespuestaAutorizacionComprobante>'
    .'<claveAccesoConsultada>1709202601179001234500120010090019603451234567812</claveAccesoConsultada>'
    .'<numeroComprobantes>1</numeroComprobantes>'
    .'<autorizaciones><autorizacion>'
    .'<estado>AUTORIZADO</estado>'
    .'<numeroAutorizacion>1709202601179001234500120010090019603451234567812</numeroAutorizacion>'
    .'<fechaAutorizacion>2026-09-17T20:43:30-05:00</fechaAutorizacion>'
    .'<ambiente>PRODUCCIÓN</ambiente>'
    .'<comprobante>%s</comprobante>'
    .'<mensajes/>'
    .'</autorizacion></autorizaciones>'
    .'</RespuestaAutorizacionComprobante>'
    .'</ns2:autorizacionComprobanteResponse>'
    .'</soap:Body></soap:Envelope>',
    htmlspecialchars($comprobante, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
);
