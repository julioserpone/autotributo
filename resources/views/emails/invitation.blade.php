<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invitación</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#18181b;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5;padding:40px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #e4e4e7;box-shadow:0 1px 3px rgba(0,0,0,0.06);">
                    <tr>
                        <td style="padding:32px 40px 24px;text-align:center;border-bottom:1px solid #f4f4f5;">
                            <span style="font-size:18px;font-weight:700;color:#14b8a6;letter-spacing:0.02em;">Autotributo</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px 40px;">
                            <h1 style="margin:0 0 12px;font-size:20px;font-weight:600;color:#18181b;">
                                Invitación
                            </h1>
                            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#3f3f46;">
                                Has sido invitado a unirte al estudio <strong style="color:#18181b;">{{ $tenantName }}</strong>
                                en la plataforma Autotributo. Acepta la invitación para crear tu cuenta y empezar a trabajar.
                            </p>
                            <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#3f3f46;">
                                Invitado por: <strong style="color:#18181b;">{{ $inviterName }}</strong>
                                ({{ $inviterEmail }})
                            </p>
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0;">
                                <tr>
                                    <td align="center" style="border-radius:6px;background-color:#14b8a6;">
                                        <a href="{{ $url }}" style="display:inline-block;padding:12px 28px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:6px;">
                                            Aceptar invitación
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:0 0 8px;font-size:13px;line-height:1.6;color:#71717a;">
                                O copia y pega este enlace en tu navegador:
                            </p>
                            <p style="margin:0 0 20px;font-size:12px;line-height:1.6;color:#a1a1aa;word-break:break-all;">
                                {{ $url }}
                            </p>
                            <p style="margin:0;font-size:13px;line-height:1.6;color:#71717a;">
                                Este enlace expira en 7 días. Si no esperabas esta invitación, puedes ignorar este correo.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 40px;background-color:#fafafa;border-top:1px solid #f4f4f5;text-align:center;">
                            <p style="margin:0;font-size:12px;color:#a1a1aa;">
                                Autotributo · Gestión tributaria
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>