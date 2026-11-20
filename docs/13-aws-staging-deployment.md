# 13 — Staging efímero en AWS (una sola instancia EC2)

> **Alcance:** entorno de integración y UAT para cerrar M1/M2. **No** es la
> arquitectura de producción: producción sigue prevista en Laravel Cloud
> (`docs/06`). La decisión y sus límites están en el
> [ADR-0008](adr/0008-aws-staging-single-instance.md) y su enmienda del
> 2026-11-18.
>
> **Estado:** desplegado y validado. El entorno **no** es 24/7: se crea con
> `ec2-control.sh create` cuando se va a probar y se destruye con
> `destroy --yes` al terminar, de modo que el coste en reposo es **USD 0**.

## 1. Resumen de la arquitectura

| Pieza | Decisión |
|---|---|
| Cómputo | 1 × EC2 `t3.micro` (Ubuntu 24.04, `us-east-2`, 30 GB gp3), **efímera** |
| Imagen de app | `autotributo/autotributo-staging:staging` (GHCR, pública): código + assets ya compilados; la instancia solo descarga y monta |
| Ciclo de vida | `scripts/staging/ec2-control.sh {create,destroy,status}` |
| Orquestación | Docker Compose (`docker-compose.staging.yml`) |
| Servicios | `app` (PHP-FPM 8.5), `queue`, `scheduler`, `reverb`, `web` (Caddy 2) |
| Base de datos | **Neon** free tier (PostgreSQL 18, host directo, `sslmode=require`), proyecto `autotributo-staging` |
| Caché / sesiones / colas | Postgres (`database`) — sin Valkey |
| Assets | compilados en CI (imagen de GHCR) o en caliente con `npm run build` dentro del contenedor; `public/` se monta en `app` y Caddy |
| WebSockets | Reverb en la red interna; Caddy hace proxy de `/app/*` y `/apps/*` |
| TLS | Let's Encrypt automático de Caddy |
| DNS | DuckDNS (`autotributo-staging.duckdns.org`), IP dinámica |
| Almacenamiento | Disco de la instancia en M1; S3 `autotributo-staging-*` al activar M2 |
| Robot SRI | `SRI_ROBOT_DRIVER=fake`, `SRI_ENVIRONMENT=1` (sin credenciales reales) |
| Correo | `MAIL_MAILER=log` (sin envíos reales) |

**Por qué IP dinámica:** no se paga IP elástica ni Route 53. El hostname de
DuckDNS es estable y el callback del robot apunta a él, no a la IP. Un timer de
systemd actualiza el registro A al arrancar y cada 5 minutos.

**Por qué efímera:** apagar (`stop`) la instancia sigue pagando el volumen EBS y
la IPv4 reservada de la instancia detenida. Terminar (`destroy`) elimina
instancia, volumen y dirección pública, que es la única forma de llegar a
USD 0. La base de datos ya no vive en AWS, así que destruir el cómputo no
pierde datos.

```
Internet
   │  HTTPS (443) / HTTP (80)
   ▼
Caddy (contenedor web) ──► PHP-FPM (app) ──► Neon
   │                         │
   │                         ├─ queue (queue:work)
   │                         ├─ scheduler (schedule:work)
   │                         └─ reverb (WebSockets)
   │
   └─ /app/*, /apps/* ──► reverb:8080
```

## 2. Coste y free tier

El coste se paga **solo mientras la instancia existe**. Con `destroy` no queda
ningún recurso facturable de staging en AWS.

| Recurso | Coste |
|---|---|
| EC2 `t3.micro` (on-demand, `us-east-2`) | ~USD 0,0104/h |
| EBS 30 GB gp3 | ~USD 0,0033/h (~USD 2,40/mes) |
| IPv4 pública de la instancia | ~USD 0,005/h (~USD 3,65/mes) |
| **Total encendida** | **~USD 0,019/h ≈ USD 0,45/día** |
| Instancia destruida | **USD 0** |
| Neon free tier | USD 0 dentro de los límites |
| DuckDNS / Let's Encrypt | USD 0 |
| S3 (solo M2) | céntimos con lifecycle |

Si la **organización** es elegible para free tier, las 750 h/mes de `t3.micro`,
los 30 GB de EBS y las 750 h de IPv4 pública pueden quedar en USD 0. El free
tier es **uno por organización** (cuenta pagadora), no uno por cuenta miembro:
verifícalo en **Billing → Free Tier** desde la cuenta pagadora. Aun así, el
modelo efímero es la garantía: en reposo no hay consumo que pueda agotar un
crédito.

Crea **AWS Budgets** con alarmas a USD 5 y USD 10 antes de provisionar.

## 3. Prerrequisitos

- Cuenta AWS con MFA en el usuario root y un usuario IAM administrativo.
- Par de claves SSH (ed25519) para `ubuntu`.
- Cuenta gratuita en [DuckDNS](https://www.duckdns.org) con el subdominio
  `autotributo-staging`.
- Proyecto en [Neon](https://neon.tech) con una rama `staging`.
- Acceso al repositorio `julioserpone/autotributo`.
- Dominio `autotributo.com` **no** necesario para este entorno.
- Perfil AWS `autotributo-staging` con un usuario IAM que pueda
  `RunInstances`, `TerminateInstances`, `PassRole` y describir imágenes,
  subredes, security groups, claves y volúmenes. **No** necesita permisos de
  facturación, snapshots ni direcciones elásticas.
- Respaldos locales **fuera del repositorio** (permisos `600`):
  `~/.config/autotributo/staging.env` (el `.env` de Laravel) y
  `~/.config/autotributo/duckdns.env` (el token de DuckDNS). `create` los exige
  y `destroy` los actualiza antes de terminar la instancia.

## 4. Provisión paso a paso

### 4.1 Presupuesto y alarmas

1. **Billing → Budgets → Create budget**.
2. Presupuesto mensual de USD 5, alertas al 80 % y 100 %.
3. Añade una segunda alerta a USD 10 por si el free tier no cubre algo.

### 4.2 Crear la instancia

```bash
./scripts/staging/ec2-control.sh create           # imagen de GHCR + código de main
./scripts/staging/ec2-control.sh create --source # sin imagen: build en la instancia
```

El script hace todo el ciclo y no necesita consola de AWS:

1. Resuelve la **AMI pública más reciente** de Ubuntu 24.04 (`ami-…`) y la
   subred por defecto de `us-east-2a`.
2. Crea una `t3.micro` con 30 GB gp3 cifrado y `DeleteOnTermination=true`,
   `CpuCredits=standard`, IMDSv2 obligatorio y sin Elastic IP. Antes comprueba
   que la imagen de GHCR se pueda descargar sin login (el paquete es público).
3. Espera SSH, clona el repositorio en `/opt/autotributo`, copia el `.env` y el
   token de DuckDNS desde `~/.config/autotributo/`.
4. Ejecuta `provision-host.sh` (Docker, swap, UFW, timer DuckDNS). Ni Node ni
   Composer se instalan en el host: viven en la imagen.
5. Fuerza la actualización de DuckDNS y **espera a que el registro A apunte a la
   IP nueva**.
6. Ejecuta `deploy.sh` (pull de la imagen, Composer/Vite en caliente si hace
   falta, migraciones, healthchecks y `/up`).
7. Comprueba `https://<hostname>/up` desde tu máquina (DNS público + TLS) y
   termina.

#### Panel de consola

Mientras corre, el script ocupa **una sola pantalla** (buffer alterno, sin
scroll) con dos secciones de `scripts/lib/dashboard.sh`:

- **Etapas**: la lista completa desde el principio. Cada etapa muestra su icono
  (`✔` hecha, spinner en curso, `○` pendiente, `!` aviso, `✖` falló) y su
  duración. En terminales bajas la lista se resume en una línea
  (`✔ ✔ ⠹ ○ ○ ○ 3/6 Provisionar host…`) para dejarle espacio al panel.
- **En curso**: el comando que se está ejecutando (`$ scp …`, `$ aws ec2 …`,
  `$ ssh ubuntu@… deploy.sh`) y las últimas líneas de su salida. Las líneas con
  `error`, `failed` o `denied` se pintan en rojo y dos líneas de comando se
  envuelven sin romper argumentos.

Al salir se recupera la pantalla normal y queda un resumen en el scrollback con
el estado y la duración de cada etapa. Si algo falla, el resumen incluye el
mensaje, las últimas líneas del log y la ruta del log completo en
`$TMPDIR/autotributo-dash.*/session.log`.

Con stdout redirigido (CI, `| tee`, `cron`) el panel cambia solo a texto plano
equivalente (`▶ etapa`, `$ comando`, `ok`), y `--inline` lo fuerza también en
una terminal. `NO_COLOR=1` desactiva el color.

### 4.2.1 Qué hace exactamente `deploy.sh`

Nunca compila PHP ni instala Node o Composer en la instancia. La imagen base
(`autotributo-php-base:8.5`) ya es el toolchain completo: PHP 8.5 + extensiones
compiladas + Composer 2 + Node 24 + git + rsync.

1. `git fetch --depth 1 origin main` + `git reset --hard`: el código servido es
   siempre el de `origin/main`, así que una instancia nueva recibe los cambios
   nuevos del repositorio sin que tú hagas nada. `storage/` no se toca (git lo
   ignora) y `build-app.sh` recrea las carpetas de escritura si el volume nace
   vacío.
2. `docker pull` de `APP_IMAGE`: se usa como semilla (trae `vendor/` y
   `public/build` del último commit publicado) y para el contenedor `app`. Si el
   host no tiene `vendor/`, se siembra desde la imagen.
3. `scripts/staging/build-app.sh`, dentro del contenedor (como root, porque
   escribe en el bind mount) y solo si hace falta:
   - `composer install` si cambió `composer.lock` o falta `vendor/`;
   - `rm -rf node_modules && npm ci && npm run build` si cambió
     `package-lock.json`, falta `public/build` o hay recursos más nuevos que el
     último deploy;
   - `php artisan package:discover` + `php artisan optimize`.
   Los sellos se guardan en `storage/.deploy/stamps` (volumen), así que un deploy
   que solo toca PHP no recompila assets y uno que solo toca el frontend no
   reinstala Composer. Al final se normalizan permisos (`public/` legible para
   PHP-FPM, `node_modules` escribible).
4. Migraciones, recrea servicios y verifica `/up`.

El ciclo `create` completo tarda ~4–6 minutos la primera vez de la imagen y
unos 2 si todo está caliente; un `deploy.sh` sobre una instancia existente,
~30 s si solo cambió PHP y ~1–2 min si hubo que recompilar Vite.

**Sobre «traer los cambios después de la imagen»:** sí. La imagen es solo el
punto de partida (toolchain + último snapshot); el código se actualiza con
`git fetch` contra `origin/main` en cada deploy y Composer/Vite corren en
caliente con las herramientas que ya trae la imagen. Para probar un commit sin
fusionar: publica el repo en una rama y usa `REPO_URL`/`BRANCH`, o construye la
imagen con `workflow_dispatch` desde esa rama y apunta `APP_IMAGE` a su tag `:<sha>`.

`build-staging-image.yml` publica primero la base (toolchain) y después la
imagen completa, y solo cuando **CI terminó en verde** (`workflow_run`). Si `CI`
no ha corrido desde un cambio de `docker/php/Dockerfile`, ejecuta
`build-php-base.yml` a mano antes de `create`.

### 4.2.2 Refrescar la imagen sin reconstruir el toolchain

Para publicar un código nuevo en la imagen sin pagar la instalación de PHP
extensiones o Node (`scripts/staging/image-refresh.sh`, se ejecuta en tu máquina):

```bash
REF=main IMAGE_TAG_REF=ghcr.io/julioserpone/autotributo-staging:main \
  PUSH=1 ./scripts/staging/image-refresh.sh
```

Abre la imagen `:staging` como contenedor, sincroniza el código con `rsync`,
ejecuta Composer/Vite solo si los locks cambiaron y hace `docker commit` del
delta. Cambiar una línea de PHP cuesta segundos; un cambio de frontend, un par de
minutos de Vite. La instancia lo consume con `APP_IMAGE=…:main ./scripts/staging/deploy.sh`.

Referencia del security group `autotributo-staging-sg` (ya creado; el script lo
reutiliza):

| Puerto | Origen | Uso |
|---|---|---|
| 22/tcp | **Tu IP /32** | SSH |
| 80/tcp | 0.0.0.0/0 | HTTP-01 de Let's Encrypt y redirección |
| 443/tcp | 0.0.0.0/0 | HTTPS |
| 443/udp | 0.0.0.0/0 | HTTP/3 (opcional) |

**No abras 8080 ni 5432.** Reverb y Postgres no se publican. Si cambias de red,
actualiza la regla 22 antes de `create`: sin ella el script no puede provisionar.

**No asignes Elastic IP.** La instancia recibe una IPv4 pública temporal que se
libera al destruirla; DuckDNS la actualiza.

### 4.2.3 Si el sitio no carga desde tu máquina

Un `create` puede terminar bien y el sitio no abrir. Casi siempre es la **red de
origen**, no la instancia ni DuckDNS. Orden de diagnóstico (de dentro hacia fuera):

```bash
# 1. ¿DuckDNS apunta a la IP de la instancia?
dig +short autotributo-staging.duckdns.org @1.1.1.1
./scripts/staging/ec2-control.sh status      # IP real

# 2. ¿Responde dentro de la instancia? (descarta la app, Caddy y el certificado)
ssh ubuntu@<IP> 'curl -s -o /dev/null -w "%{http_code}\n" \
  --resolve autotributo-staging.duckdns.org:443:127.0.0.1 \
  https://autotributo-staging.duckdns.org/up'

# 3. ¿Sale tráfico desde tu red? Si esto se cuelga, el problema es de origen
nc -z -G 8 <IP> 443; echo "tcp=\$?"
curl -sS -m 20 -o /dev/null -w '%{http_code}\n' https://autotributo-staging.duckdns.org/up
```

Interpretación:

| Síntoma | Causa | Acción |
|---|---|---|
| `dig` devuelve otra IP | DuckDNS no se actualizó | `sudo systemctl start autotributo-duckdns.service` y espera |
| Paso 2 falla | app/Caddy/certificado | `docker compose … ps` y logs de `web`/`app` |
| Paso 3 se cuelga pero el 2 funciona | **tu red de origen bloquea o rompe la ruta** | Cambia de red (datos móviles) o usa otra salida |
| SSH entra y web no (o al revés) | regla 22 con la IP vieja | actualiza la regla 22 del SG |

**Caso real (2026-11-18):** el sitio no cargaba desde la oficina y sí desde datos
móviles. DuckDNS resolvía bien, el SG tenía 80/443 abiertos a `0.0.0.0/0`, el
certificado estaba emitido y `/up` daba 200 dentro de la instancia: era la red
de origen. No es un fallo del despliegue ni de DuckDNS.

**Caso real (2026-11-18, SSH):** `create` falló a mitad porque la **IP pública
del operador cambió** (WiFi → nueva sesión) y la regla 22 permitía solo la
anterior. Síntoma: `ssh: connect to host … port 22: Operation timed out`. El SG
de staging solo permite `RevokeSecurityGroupIngress` desde un usuario admin;
con el usuario de staging hay que **añadir** la IP nueva y revocar la vieja
manualmente:

```bash
MYIP=$(curl -s https://api.ipify.org)
aws ec2 authorize-security-group-ingress --group-id "$SG_ID" \
  --protocol tcp --port 22 --cidr "$MYIP/32"
```

Para evitar el problema, ejecuta `create` desde la misma red en la que vas a
usar el entorno, o abre temporalmente 22 desde una IP administrativa fija.

### 4.3 DuckDNS

Solo se prepara una vez: el token queda en el respaldo local y `create` lo
inyecta en cada instancia nueva.

1. Entra en DuckDNS, crea el subdominio `autotributo-staging` y copia el token.
2. Guárdalo en local, nunca en el chat ni en git:

   ```bash
   mkdir -p ~/.config/autotributo && chmod 700 ~/.config/autotributo
   printf 'DUCKDNS_DOMAIN=autotributo-staging\nDUCKDNS_TOKEN=%s\n' 'TU-TOKEN' \
     > ~/.config/autotributo/duckdns.env
   chmod 600 ~/.config/autotributo/duckdns.env
   ```

3. En la instancia ya creada, verifica:

   ```bash
   sudo systemctl start autotributo-duckdns.service
   journalctl -u autotributo-duckdns.service -n 20 --no-pager
   dig +short autotributo-staging.duckdns.org
   ```

   El `dig` debe devolver la IP pública actual (la imprime `status`). El timer la
   refresca cada 5 minutos.

### 4.4 Base de datos en Neon

La base **no** vive en AWS: es un proyecto Neon free tier en `us-east-2`, así
que destruir la EC2 no la afecta.

1. En **console.neon.tech**, proyecto `autotributo-staging`, región AWS
   `us-east-2`.
2. **Connect** → cadena de conexión. Usa el host **directo**, sin `-pooler`:
   `ep-xxxxxxxx-xxxxxx.c-8.us-east-2.aws.neon.tech`. El pooler no admite bien
   `pg_restore` ni migraciones largas.
3. Copia `DB_HOST`, `DB_DATABASE`, `DB_USERNAME` y `DB_PASSWORD` al `.env`
   (`DB_SSLMODE=require` es obligatorio). Verifica desde la instancia:

   ```bash
   docker run --rm -e PGPASSWORD="$DB_PASSWORD" postgres:18 \
     psql -h "$DB_HOST" -U "$DB_USERNAME" -d "$DB_DATABASE" -c 'select version();'
   ```

Desde tu Mac puedes usar cualquier cliente PostgreSQL contra el host de Neon
(es público con TLS); no hace falta túnel SSH.

### 4.5 Despliegue manual (referencia)

`create` ya ejecuta `provision-host.sh` y `deploy.sh`. Los pasos manuales, para
depurar o provisionar a mano, son:

```bash
sudo apt-get update && sudo apt-get install -y git
git clone https://github.com/julioserpone/autotributo.git /opt/autotributo
cd /opt/autotributo
cp .env.staging.example .env && chmod 600 .env
sudo ./scripts/staging/provision-host.sh
./scripts/staging/deploy.sh
```

`provision-host.sh` instala Docker, 2 GB de swap, UFW (22/80/443) y el timer de
DuckDNS. `deploy.sh` actualiza el código, aplica migraciones, recrea los
servicios y comprueba `/up`.

En el `.env`, además de `DB_*` (Neon), ajusta:

- `STAGING_HOSTNAME` y `ACME_EMAIL`.
- `APP_URL` y `SRI_ROBOT_CALLBACK_URL` con el hostname de DuckDNS.
- `UID`/`GID` si el usuario de la instancia no es `1000` (ubuntu por defecto).

> No pongas comentarios al final de una línea de valor (`APP_ENV=staging # ...`):
> Docker Compose los pasa como parte del valor. Usa comentarios en línea propia.

### 4.6 Secretos del `.env`

Si usas `create`, el `.env` restaurado ya trae los secretos de la etapa
anterior y este paso solo aplica a un entorno creado desde cero (o tras rotar
claves).

Genera los secretos **en la instancia** (no los pegues en el chat ni en git):

```bash
sed -i "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" .env
sed -i "s|^SRI_CREDENTIALS_KEY=.*|SRI_CREDENTIALS_KEY=$(openssl rand -base64 32)|" .env
sed -i "s|^SRI_ROBOT_CALLBACK_SECRET=.*|SRI_ROBOT_CALLBACK_SECRET=$(openssl rand -hex 32)|" .env
sed -i "s|^REVERB_APP_KEY=.*|REVERB_APP_KEY=$(openssl rand -hex 16)|" .env
sed -i "s|^REVERB_APP_SECRET=.*|REVERB_APP_SECRET=$(openssl rand -hex 32)|" .env
sed -i "s|^VITE_REVERB_APP_KEY=.*|VITE_REVERB_APP_KEY=$(grep '^REVERB_APP_KEY=' .env | cut -d= -f2-)|" .env
```

Guarda una copia de `APP_KEY`, `SRI_CREDENTIALS_KEY` y
`SRI_ROBOT_CALLBACK_SECRET` en el gestor de secretos de la organización.
`SRI_CREDENTIALS_KEY` debe decodificar a 32 bytes exactos.

Valida el compose antes de construir:

```bash
docker compose -f docker-compose.staging.yml --env-file .env config --quiet
```

### 4.7 Primer deploy

```bash
./scripts/staging/deploy.sh
```

El script:

1. Actualiza el código a `origin/main` (`git fetch --depth 1`).
2. Descarga la imagen de GHCR (`APP_IMAGE`) o construye el contenedor `app`
   sobre el toolchain de GHCR (segundos).
3. Ejecuta Composer y Vite en caliente **solo si** cambió el lock o los recursos
   (`scripts/staging/build-app.sh`).
4. Aplica migraciones con `migrate --force`.
5. Recrea los servicios y espera los healthchecks.
6. Verifica `https://autotributo-staging.duckdns.org/up`.

La primera vez, Caddy emite el certificado de Let's Encrypt. Si falla, revisa
que DuckDNS ya resuelva a la IP correcta y que el puerto 80 esté abierto.

### 4.8 Provisionar el estudio

```bash
docker compose -f docker-compose.staging.yml --env-file .env exec app \
  php artisan app:provision \
    --name="Estudio Staging" \
    --owner-email=owner@staging.example \
    --slug=staging \
    --no-invite
```

Con `--no-invite` no se envía correo (staging usa `MAIL_MAILER=log`). Asigna una
contraseña temporal:

```bash
docker compose -f docker-compose.staging.yml --env-file .env exec app \
  php artisan tinker --execute="
    \$user = App\Models\User::where('email', 'owner@staging.example')->firstOrFail();
    \$user->forceFill(['password' => Illuminate\Support\Facades\Hash::make('CAMBIAR-ESTA-CLAVE')])->save();
    echo 'password actualizada', PHP_EOL;
  "
```

Al entrar por primera vez, el owner deberá configurar 2FA (F1-03f) y guardar los
códigos de recuperación.

## 5. Smoke tests

```bash
# Salud
curl -fsS https://autotributo-staging.duckdns.org/up

# Login (sin 2FA configurado debe devolver two_factor_setup_required)
curl -fsS -X POST https://autotributo-staging.duckdns.org/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"owner@staging.example","password":"CAMBIAR-ESTA-CLAVE"}'

# Estado de servicios
docker compose -f docker-compose.staging.yml --env-file .env ps
docker compose -f docker-compose.staging.yml --env-file .env exec app php artisan about
docker compose -f docker-compose.staging.yml --env-file .env exec app php artisan schedule:list
docker compose -f docker-compose.staging.yml --env-file .env exec app php artisan queue:failed
```

En la UI valida: login → 2FA → panel → crear empresa → auditoría. El estado de
Reverb se consulta con `GET /api/v1/realtime/status` autenticado.

## 6. Operación diaria

### Levantar y destruir

Staging no se apaga: se destruye. Desde tu máquina local, sin consola de AWS:

```bash
./scripts/staging/ec2-control.sh create           # crear y desplegar (~4–6 min)
./scripts/staging/ec2-control.sh status           # estado, IP, AZ y coste/h
./scripts/staging/ec2-control.sh destroy --yes    # respaldar .env y terminar todo
./scripts/staging/ec2-control.sh create --inline  # panel desactivado (texto plano)
```

`create --source` despliega el código del repositorio (build del target `app` en
la instancia): úsalo solo para depurar un commit que aún no está en GHCR.

`destroy` exige `--yes` porque borra la instancia **y su disco**. Antes de
terminar, copia el `.env` de la instancia y el token de DuckDNS a
`~/.config/autotributo/`, que es lo que `create` usa en el siguiente ciclo.
No crea AMI ni snapshot a propósito: la base está en Neon y lo demás es
desechable.

Si el certificado de Let's Encrypt falla por límite de emisiones, espera a que
pase la ventana (5 certificados duplicados por semana) o reutiliza un certificado
existente; no reintentes en bucle.

### Logs

```bash
cd /opt/autotributo
docker compose -f docker-compose.staging.yml --env-file .env logs -f --tail=100
docker compose -f docker-compose.staging.yml --env-file .env logs -f app web reverb
journalctl -u autotributo-duckdns.service -f
```

### Actualizar

```bash
cd /opt/autotributo
./scripts/staging/deploy.sh
```

También se puede lanzar desde GitHub Actions:
**Actions → Deploy staging → Run workflow** (requiere los secretos/variables de
la sección 10).

### Reiniciar sin desplegar

```bash
docker compose -f docker-compose.staging.yml --env-file .env restart app queue scheduler reverb web
```

### Disco y limpieza

```bash
df -h
docker system df
docker image prune -f
docker builder prune -f
```

## 7. Backups y restauración

- **Neon** aplica PITR en la rama `staging` (según el plan). Es la primera
  línea de defensa y **no depende** de la EC2.
- Backup lógico local antes de cualquier operación destructiva (volcado desde
  la instancia con la imagen de Postgres 18, la misma versión mayor que Neon):

  ```bash
  docker run --rm -v /tmp:/tmp postgres:18 \
    pg_dump --no-owner --no-acl --format=custom \
    --file=/tmp/staging.dump "$DATABASE_URL"
  scp ubuntu@<IP>:/tmp/staging.dump ~/.config/autotributo/staging-backup.dump
  chmod 600 ~/.config/autotributo/staging-backup.dump
  ```

  En este repositorio el respaldo de la migración a Neon está en
  `~/.config/autotributo/neon-staging-backup.dump` (fuera de git).

- Restauración de ensayo (nunca sobre producción):

  ```bash
  docker run --rm -v "$PWD:/backup" postgres:18 \
    pg_restore --no-owner --no-acl --dbname="$DATABASE_URL" /backup/staging.dump
  ```

  `pg_restore` devuelve 0 incluso con avisos; valida los conteos de las tablas
  clave (`users`, `tenants`, `memberships`, `audit_logs`, `migrations`) contra
  el origen antes de dar el restore por bueno.

## 8. Rollback

El código servido es el de `origin/main` (o `BRANCH`). Para volver a un commit
anterior en la instancia:

```bash
cd /opt/autotributo
git log --oneline -10
git reset --hard <sha-anterior>
SKIP_GIT_PULL=1 ./scripts/staging/deploy.sh
```

El SHA desplegado se anuncia en `GET /api/v1/health` (`version`). Si `vendor/` o
`public/build` quedaron de otro commit, `build-app.sh` los regenera solo (los
sellos de `storage/.deploy` no coinciden con el lock nuevo).

Si quieres que la instancia arranque directamente desde una imagen concreta,
publica su tag `:<sha>` y ejecuta
`APP_IMAGE=ghcr.io/…/autotributo-staging:<sha> SKIP_GIT_PULL=1 ./scripts/staging/deploy.sh`.

Las migraciones son expand/contract (`docs/03`): no se revierte una migración
destructiva; se corrige hacia adelante.

## 9. Seguridad

- SSH solo desde tu IP; usuario `ubuntu` con clave, sin contraseña. Si cambia tu
  IP pública (otra red, reinicio del router), actualiza la regla 22 antes de
  volver a usar `create`/`destroy` (ver 4.2.3).
- Sin usuario root para operar; MFA en la cuenta AWS.
- `APP_DEBUG=false`, `APP_ENV=staging`, `SESSION_SECURE_COOKIE=true`.
- `.env` con permisos `600`; secretos fuera del repositorio y de las capturas.
- Puertos publicados: solo 80/443. Reverb queda en la red interna; la base es
  Neon, accesible solo con TLS y credenciales del `.env`.
- UFW activo además del security group.
- No usar datos reales, XML reales ni credenciales SRI de producción.
- Revisa `docker compose ps` y `ufw status` tras cada cambio de infraestructura.
- Rota `SRI_ROBOT_CALLBACK_SECRET`, `REVERB_APP_SECRET` y las claves AWS si
  sospechas una fuga; `SRI_CREDENTIALS_KEY` tiene rotación por `key_version`.

## 10. Secretos y variables de GitHub Actions

El workflow `.github/workflows/deploy-staging.yml` es manual y usa:

| Tipo | Nombre | Valor |
|---|---|---|
| Variable | `STAGING_URL` | `https://autotributo-staging.duckdns.org` |
| Variable | `STAGING_SSH_HOST` | IP pública o hostname de la instancia |
| Variable | `STAGING_SSH_USER` | `ubuntu` |
| Secreto | `STAGING_SSH_KEY` | Clave privada ed25519 (contenido completo) |
| Secreto | `STAGING_SSH_KNOWN_HOSTS` | Salida de `ssh-keyscan -H <host>` |

Configúralos en **Settings → Secrets and variables → Actions**. El entorno
`staging` puede exigir aprobación manual.

## 11. Teardown

Para dejar de pagar basta `destroy --yes`: no quedan EC2, EBS ni IPv4. El
teardown completo (dar de baja el entorno) añade:

1. `./scripts/staging/ec2-control.sh destroy --yes`.
2. Borra el security group `autotributo-staging-sg` y su clave SSH si no se
   reutilizan. El SG de RDS (`autotributo-staging-rds-sg`) y el subnet group
   `autotributo-staging-subnet-group` son gratuitos pero quedan huérfanos; el
   usuario IAM de staging no tiene permiso para borrar SGs, así que se hace
   desde la consola con un usuario administrador.
3. Borra el subdominio de DuckDNS y el respaldo local
   `~/.config/autotributo/duckdns.env`.
4. Borra el proyecto de Neon `autotributo-staging` (conserva `main` si se
   migrará a Cloud) y los respaldos locales asociados.
5. Borra los buckets `autotributo-staging-*` si se crearon.
6. Elimina las variables/secretos de GitHub y el entorno `staging`.
7. Revisa **Billing → Cost Explorer** durante los días siguientes.

## 12. Migración a Laravel Cloud

Cuando se compre `autotributo.com` y se habilite Cloud:

1. Crea el entorno `staging` en Cloud con Neon, Valkey/SQS, S3 y Reverb
   gestionados (`docs/06`).
2. Reutiliza el `.env.staging.example` como contrato de variables; cambia
   `CACHE_STORE`, `SESSION_DRIVER` y `QUEUE_CONNECTION` a los servicios de
   Cloud.
3. Apunta `staging.autotributo.com` a Cloud y retira DuckDNS.
4. Ejecuta los mismos smoke tests de la sección 5.
5. Apaga y elimina la EC2 según la sección 11.

## 13. Checklist de aceptación de F1-11

- [ ] Presupuesto AWS con alarmas a USD 5/10 (pendiente; se crea desde la
      cuenta pagadora).
- [x] EC2 `t3.micro` con IMDSv2, 30 GB gp3 y security group 22/80/443.
- [x] `ec2-control.sh create` reconstruye el entorno desde cero (sin AMI ni
      snapshot propios) y `destroy --yes` deja el coste en USD 0.
- [x] DuckDNS resuelve al hostname y el timer lo actualiza en cada `create`.
- [x] Neon `staging` responde con `sslmode=require` y migraciones al día.
- [x] `docker compose -f docker-compose.staging.yml config --quiet` sin errores.
- [x] `deploy.sh` completa y `/up` devuelve 200 por HTTPS (build de la app sobre la imagen base de GHCR, ~1–2 min).
- [ ] `create` en menos de 10 min, sin compilar PHP ni instalar Node/Composer en
      la instancia, con `/api/v1/health` devolviendo el SHA de `origin/main`
      (pendiente de validar con la imagen nueva).
- [x] Certificado Let's Encrypt válido y renovable.
- [x] `app`, `queue`, `scheduler`, `reverb` y `web` healthy.
- [x] `app:provision` crea el estudio y el owner puede configurar 2FA.
- [x] Cola `database` procesa un job de prueba y `schedule:list` muestra tareas.
- [x] Login, empresa, auditoría y `realtime/status` funcionan en la UI.
- [x] `.env` con permisos 600 y secretos copiados al gestor.
- [x] Workflow manual de GitHub Actions desplegando por SSH.
- [x] Sin datos reales ni credenciales SRI de producción.
- [ ] Coste mensual de staging en USD 0 verificado en Billing tras un ciclo
      completo `create` → `destroy`.

### 4.4 Credenciales AWS y SSH en `destroy`

- **Credenciales AWS caducadas.** El perfil `autotributo-staging` usa claves estáticas
  en `~/.aws/credentials`. Si `aws sts get-caller-identity` falla con `AuthFailure`,
  regenera las claves en IAM y actualiza el archivo (no uses `aws sso login`, el
  perfil no está configurado para SSO).
- **SSH inaccesible.** `destroy` ahora falla rápido (60 s) en `wait_ssh` y omite
  el respaldo del `.env` con una advertencia, pero **sigue terminando la instancia**
  con las credenciales AWS válidas. Si tu IP cambió, añádela al SG (ver §4.2).
- **BD desincronizada tras un refactor.** Cuando cambian nombres de tablas o
  campos, usa `create --recreate-db`, que ejecuta `migrate:fresh --seed` en lugar
  de `migrate --force`. Verifica antes que no haya datos que quieras conservar.
