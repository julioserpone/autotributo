# ADR 0008: staging temporal en una instancia AWS EC2

- **Estado:** aceptado
- **Fecha:** 2026-11-10
- **Ámbito:** solo `staging`. Producción sigue prevista en Laravel Cloud
  (`docs/06-laravel-cloud-deployment.md`).

## Contexto

F1-11 necesita un entorno de staging para cerrar M1 y M2: provisión inicial,
login con 2FA, empresas, auditoría y sincronización SRI. Laravel Cloud no está
disponible por coste en este momento.

La organización ya tiene una cuenta de AWS con free tier, buckets S3 y el robot
`lambda` del SRI. El dominio `autotributo.com` todavía no está comprado y no se
quiere pagar una IP elástica ni Route 53 para un entorno temporal.

`docs/06` asume servicios gestionados (app cluster, colas administradas,
WebSockets, Postgres serverless). Para staging el tráfico es de UAT, no hay
requisitos de alta disponibilidad y un solo servidor es suficiente.

## Decisión

1. Desplegar staging en **una sola instancia EC2 `t3.micro`** (Ubuntu 24.04,
   `us-east-2`, 30 GB gp3) con **Docker Compose**. Sin ECS, Fargate, App Runner,
   Beanstalk, ALB ni autoescalado.
2. Servicios en la instancia:
   - `app` (PHP-FPM 8.5, target `staging` del Dockerfile).
   - `queue` (`queue:work` para `sri,ai,reports,default`).
   - `scheduler` (`schedule:work`).
   - `reverb` (WebSockets en la red interna).
   - `web` (Caddy 2 con TLS automático y proxy a PHP-FPM/Reverb).
   - Sin contenedor de base de datos ni Valkey: Postgres en **RDS**
     (`db.t3.micro`, free tier) y
     `CACHE_STORE`/`SESSION_DRIVER`/`QUEUE_CONNECTION=database`.
3. **IP pública dinámica sin Route 53.** El hostname estable se obtiene con
   **DuckDNS** (`autotributo-staging.duckdns.org`). Un timer de systemd actualiza
   el registro A al arrancar y cada 5 minutos. Caddy emite y renueva el
   certificado Let's Encrypt para ese hostname.
4. **El callback del robot no cambia.** `SRI_ROBOT_CALLBACK_URL` apunta al
   hostname de DuckDNS, no a la IP. La Lambda sigue llamando a la misma URL
   aunque la instancia se apague y encienda. Para M1 se usa
   `SRI_ROBOT_DRIVER=fake` y `SRI_ENVIRONMENT=1`; no se usan credenciales reales.
5. Base de datos: proyecto/rama **Neon `staging`** separada. Nunca se comparte
   con producción.
6. Almacenamiento: volumen Docker local para M1. Al activar M2/robot real se
   cambia a los buckets `autotributo-staging-app` y `autotributo-staging-sri`
   (`docs/aws-s3-setup.md`).
7. Secretos: `.env` en el servidor con permisos `600`, nunca en git. El deploy
   por CI usa GitHub Secrets. `APP_KEY`, `SRI_CREDENTIALS_KEY`, secretos de
   Reverb y HMAC se generan para staging.
8. Deploy: `scripts/staging/deploy.sh` (git pull, build de assets, imagen,
   migraciones y health check) y workflow manual `deploy-staging.yml`. Se puede
   activar en `push` a `main` cuando el entorno esté estable.
9. Cuando se compre el dominio, staging pasará a
   `staging.autotributo.com` con el DNS/TLS definitivo y se retirará DuckDNS.
   La migración a Laravel Cloud es el destino de producción.

## Consecuencias

**Positivas**

- Coste muy bajo: compute cubierto por free tier (verificar vigencia); el gasto
  fijo real es la IPv4 pública (~USD 3,65/mes) mientras la instancia está
  encendida.
- Paridad razonable con producción: mismo PHP, Laravel, colas, scheduler,
  Reverb, migraciones y contrato OpenAPI.
- Callback estable sin IP elástica ni Route 53.
- Se puede apagar la instancia fuera del horario de UAT para ahorrar compute e
  IPv4; al arrancar, DuckDNS vuelve a apuntar a la nueva IP.

**Negativas y límites**

- Punto único de fallo: sin HA, sin autoescalado, sin rolling deploy real.
- 1 GB de RAM es ajustado. Se mitiga con 2 GB de swap, `pm.max_children=4`,
  límites de memoria por servicio, un solo worker y builds de assets en el host.
- Dependencia de DuckDNS (tercero) para DNS/TLS. Aceptable para staging;
  inaceptable para producción.
- Deriva respecto a Cloud: colas `database` en vez de SQS, Reverb propio en vez
  de cluster gestionado. El runbook debe probar los mismos flujos, no la misma
  infraestructura.
- La IPv4 dinámica **también se cobra** mientras la instancia corre. No usar IP
  elástica ahorra la IP reservada, no el cobro de IPv4 pública.
- No se permiten datos reales, credenciales SRI de producción ni correos reales
  masivos. Staging usa datos sintéticos y el ambiente de pruebas del SRI.

## Alternativas consideradas

| Alternativa | Motivo para descartarla |
|---|---|
| Laravel Cloud | Coste no disponible en esta fase |
| AWS Lightsail | Válida si el free tier no aplica o se necesita más RAM; misma arquitectura, ~USD 5–7/mes |
| EC2 + IP elástica + Route 53 | Más estable, pero +USD 4/mes y no resuelve nada que DuckDNS no cubra en staging |
| ECS Fargate / App Runner | Más servicios, más complejidad y coste; no aporta para un solo servidor |
| `sslip.io` + actualizar la Lambda en cada arranque | Sin DNS de terceros, pero URL cambiante y más piezas móviles (IAM + hook de arranque) |
| Túnel desde una máquina local | No es un staging real: depende de que el equipo esté encendido |

## Migración y revisión

- Este ADR se revisa al comprar `autotributo.com`, al pasar a beta o si el free
  tier deja de cubrir el compute.
- Producción no se desplegará en este modelo sin un ADR nuevo que valore
  backups, HA, observabilidad y seguridad de datos reales.
- Los comandos de build/deploy y el contrato de variables se mantienen alineados
  con `docs/06` para que el traslado a Laravel Cloud sea principalmente de
  infraestructura.
