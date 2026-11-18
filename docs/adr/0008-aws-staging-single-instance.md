# ADR 0008: staging temporal en una instancia AWS EC2

- **Estado:** aceptado (enmendado 2026-11-18)
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
   - Sin contenedor de base de datos ni Valkey: Postgres en **Neon** (free tier,
     rama `staging`) y `CACHE_STORE`/`SESSION_DRIVER`/`QUEUE_CONNECTION=database`.
3. **Instancia efímera, no 24/7.** `scripts/staging/ec2-control.sh create`
   levanta el entorno desde la AMI pública de Ubuntu 24.04 y `destroy --yes`
   termina la instancia y borra su volumen. La base vive en Neon y la
   configuración en respaldos locales (`600`) fuera del repositorio; no se crean
   AMI ni snapshots propios.
4. **IP pública dinámica sin Route 53 ni Elastic IP.** El hostname estable se
   obtiene con **DuckDNS** (`autotributo-staging.duckdns.org`). Un timer de
   systemd actualiza el registro A al arrancar y cada 5 minutos. Caddy emite y
   renueva el certificado Let's Encrypt para ese hostname.
5. **El callback del robot no cambia.** `SRI_ROBOT_CALLBACK_URL` apunta al
   hostname de DuckDNS, no a la IP. La Lambda sigue llamando a la misma URL
   aunque la instancia se destruya y se recree. Para M1 se usa
   `SRI_ROBOT_DRIVER=fake` y `SRI_ENVIRONMENT=1`; no se usan credenciales reales.
6. Base de datos: proyecto **Neon `staging`** separado, host directo (sin
   `-pooler`) y `DB_SSLMODE=require`. Nunca se comparte con producción.
7. Almacenamiento: volumen Docker local para M1. Al activar M2/robot real se
   cambia a los buckets `autotributo-staging-app` y `autotributo-staging-sri`
   (`docs/aws-s3-setup.md`).
8. Secretos: `.env` en el servidor con permisos `600`, nunca en git. El deploy
   por CI usa GitHub Secrets. `APP_KEY`, `SRI_CREDENTIALS_KEY`, secretos de
   Reverb y HMAC se generan para staging.
9. Deploy: `scripts/staging/deploy.sh` (git pull, build de assets, imagen,
   migraciones y health check) y workflow manual `deploy-staging.yml`. Se puede
   activar en `push` a `main` cuando el entorno esté estable.
10. Cuando se compre el dominio, staging pasará a
    `staging.autotributo.com` con el DNS/TLS definitivo y se retirará DuckDNS.
    La migración a Laravel Cloud es el destino de producción.

## Consecuencias

**Positivas**

- Coste en reposo **USD 0**: sin EC2, sin EBS y sin IPv4 no hay nada que
  facturar. Encendida cuesta ~USD 0,019/h (compute + EBS + IPv4) y el free tier
  de la organización puede cubrirlo; el modelo efímero no depende de eso.
- Paridad razonable con producción: mismo PHP, Laravel, colas, scheduler,
  Reverb, migraciones y contrato OpenAPI.
- Callback estable sin IP elástica ni Route 53.
- Destruir la instancia es seguro: los datos están en Neon y la configuración
  en respaldos locales, así que no se pierde nada al recrear.

**Negativas y límites**

- Punto único de fallo: sin HA, sin autoescalado, sin rolling deploy real.
- 1 GB de RAM es ajustado. Se mitiga con 2 GB de swap, `pm.max_children=4`,
  límites de memoria por servicio y un solo worker. Los assets ya no se compilan
  en el host salvo cuando cambia el frontend (ver enmienda 2).
- Recrear el entorno tarda ~4–6 minutos (arranque, `apt`, certificado y
  `docker pull`), así que no sirve para pruebas improvisadas de un minuto.
  Antes de la enmienda 2 tardaba 20–30 min.
- El certificado de Let's Encrypt se vuelve a emitir en cada `create`; el límite
  de 5 duplicados por semana obliga a espaciar los ciclos.
- Dependencia de DuckDNS (tercero) para DNS/TLS. Aceptable para staging;
  inaceptable para producción.
- Deriva respecto a Cloud: colas `database` en vez de SQS, Reverb propio en vez
  de cluster gestionado. El runbook debe probar los mismos flujos, no la misma
  infraestructura.
- La IPv4 pública **también se cobra** mientras la instancia corre. No usar IP
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

## Enmienda 2026-11-18: staging efímero y base en Neon

**Motivo.** El crédito de AWS (~USD 177,92) está reservado para el lanzamiento
BETA. El staging 24/7 consumía ~USD 29/mes entre RDS (~15), EC2, EBS e IPv4;
`stop` no bastaba porque el volumen y la IPv4 de la instancia detenida siguen
facturando.

**Cambios.**

1. **RDS eliminada.** La base se migró a un proyecto Neon free tier
   (`autotributo_staging`, PostgreSQL 18, host directo) con `pg_dump`/`pg_restore`.
   Se validaron los conteos de 12 tablas clave antes de borrar la instancia RDS
   sin snapshot final. El esquema y los datos siguen versionados por migraciones;
   el respaldo lógico local queda fuera de git.
2. **`ec2-control.sh` pasa a `create` / `destroy` / `status`.** `destroy --yes`
   respalda `.env` y `duckdns.env` en `~/.config/autotributo/` y termina la
   instancia; al tener `DeleteOnTermination=true`, el EBS desaparece con ella.
   `create` reconstruye desde la AMI pública de Ubuntu 24.04, sin AMI propia, sin
   snapshot y sin Elastic IP, y ejecuta provisión + deploy completos.
3. **Free tier y organización.** El free tier es uno por organización (cuenta
   pagadora), no por cuenta miembro; se verifica en Billing desde el payer. El
   modelo efímero hace que el coste sea USD 0 en reposo con independencia de esa
   elegibilidad.

**Consecuencia operativa.** Ya no existe `stop`/`start` como estrategia de
ahorro: el ciclo es `create` → probar → `destroy --yes`. El detalle vive en
`docs/13-aws-staging-deployment.md`.

## Enmienda 2026-11-18 (2): la imagen de GHCR es el toolchain; el código sale de git

**Motivo.** El primer intento de imagen completa no aceleraba `create` porque
`docker-compose.staging.yml` seguía construyendo el target `staging` (con el
stage `assets`: `npm ci` + Vite) en la t3.micro, y el flujo sin `APP_IMAGE`
repetía ese build en cada ciclo y en cada instancia nueva. Resultado: 20–30 min
por `create` en vez de los 4–6 esperados.

**Cambios.**

1. **La imagen base es el toolchain completo.** `autotributo-php-base:8.5` =
   PHP 8.5 + extensiones compiladas + Composer 2 + Node 24 + git + rsync +
   cliente de PostgreSQL. Se publica desde CI y se refresca por
   `apk upgrade`/nuevo build, no por instancia.
2. **La imagen completa es un snapshot, no la fuente del código.**
   `autotributo-staging:staging` = toolchain + código + `vendor/` +
   `public/build` del último `main`. La instancia la usa como semilla y para el
   contenedor `app`, pero cada deploy hace `git fetch --depth 1` de
   `origin/main`: la instancia sí recibe los cambios del repositorio después de
   lanzarse.
3. **Composer y Vite en caliente, solo cuando cambian.**
   `scripts/staging/build-app.sh` ejecuta `composer install` si cambió
   `composer.lock` (o falta `vendor/`) y `npm ci && npm run build` si cambió
   `package-lock.json`, falta `public/build` o hay recursos más nuevos. Guarda
   sellos en `storage/.deploy/stamps`, así que no se repite trabajo entre
   deploys. La instancia nunca compila PHP ni instala Node/Composer: esas
   herramientas ya están en la imagen.
4. **Dos targets en `docker/php/Dockerfile`.** `app` (código sobre la base; lo
   construye el compose, segundos) y `staging` (imagen completa publicada en
   GHCR). El build del contenedor no ejecuta Composer, npm ni `artisan`.
5. **`:staging` solo se publica con CI verde** (`workflow_run` de `CI`) y el
   workflow publica primero la base y luego la imagen completa. `create`
   verifica que el paquete sea descargable sin login antes de crear la instancia.
6. **`scripts/staging/image-refresh.sh`** regenera la imagen desde un commit
   reutilizando el toolchain (rsync + Composer/Vite condicionales +
   `docker commit`), para no esperar un build completo por un cambio pequeño.

**Límite aceptado.** La imagen solo garantiza el toolchain y un snapshot
reproducible; la verdad del código es `origin/main`. Probar una rama exige
publicar esa rama (`workflow_dispatch`) y apuntar `APP_IMAGE` a su tag `:<sha>`.
