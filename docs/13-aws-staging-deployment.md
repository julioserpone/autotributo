# 13 — Staging en AWS (una sola instancia EC2)

> **Alcance:** entorno de integración y UAT para cerrar M1/M2. **No** es la
> arquitectura de producción: producción sigue prevista en Laravel Cloud
> (`docs/06`). La decisión y sus límites están en el
> [ADR-0008](adr/0008-aws-staging-single-instance.md).
>
> **Estado:** runbook y artefactos listos. La provisión real queda pendiente de
> configurar credenciales de AWS válidas y una cuenta de DuckDNS.

## 1. Resumen de la arquitectura

| Pieza | Decisión |
|---|---|
| Cómputo | 1 × EC2 `t3.micro` (Ubuntu 24.04, `us-east-2`, 30 GB gp3) |
| Orquestación | Docker Compose (`docker-compose.staging.yml`) |
| Servicios | `app` (PHP-FPM 8.5), `queue`, `scheduler`, `reverb`, `web` (Caddy 2) |
| Base de datos | RDS PostgreSQL 16, `db.t3.micro`, instancia `autotributo-staging-db` (misma VPC, no pública) |
| Caché / sesiones / colas | Postgres (`database`) — sin Valkey |
| Assets | `npm ci && npm run build` en el host; `public/` se monta en `app` y Caddy |
| WebSockets | Reverb en la red interna; Caddy hace proxy de `/app/*` y `/apps/*` |
| TLS | Let's Encrypt automático de Caddy |
| DNS | DuckDNS (`autotributo-staging.duckdns.org`), IP dinámica |
| Almacenamiento | Disco de la instancia en M1; S3 `autotributo-staging-*` al activar M2 |
| Robot SRI | `SRI_ROBOT_DRIVER=fake`, `SRI_ENVIRONMENT=1` (sin credenciales reales) |
| Correo | `MAIL_MAILER=log` (sin envíos reales) |

**Por qué IP dinámica:** no se paga IP elástica ni Route 53. El hostname de
DuckDNS es estable y el callback del robot apunta a él, no a la IP. Un timer de
systemd actualiza el registro A al arrancar y cada 5 minutos.

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

Con la cuenta en free tier de 12 meses:

| Recurso | Coste |
|---|---|
| EC2 `t3.micro` (750 h/mes) | USD 0 |
| EBS 30 GB gp3 | USD 0 (hasta 30 GB) |
| IPv4 pública de la instancia | **~USD 3,65/mes** mientras esté encendida |
| DuckDNS / Let's Encrypt | USD 0 |
| Neon free tier | USD 0 dentro de los límites |
| S3 (solo M2) | céntimos con lifecycle |

La IPv4 pública **se cobra aunque sea dinámica**. Apagar la instancia detiene el
cómputo y la IPv4; el EBS sigue existiendo (gratis dentro del free tier). Crea
**AWS Budgets** con alarmas a USD 5 y USD 10 antes de provisionar.

> Verifica en **Billing → Free Tier** que la cuenta conserva el modelo de 12
> meses. Las cuentas creadas después del 15-jul-2025 usan créditos por 6 meses.

## 3. Prerrequisitos

- Cuenta AWS con MFA en el usuario root y un usuario IAM administrativo.
- Par de claves SSH (ed25519) para `ubuntu`.
- Cuenta gratuita en [DuckDNS](https://www.duckdns.org) con el subdominio
  `autotributo-staging`.
- Proyecto en [Neon](https://neon.tech) con una rama `staging`.
- Acceso al repositorio `julioserpone/autotributo`.
- Dominio `autotributo.com` **no** necesario para este entorno.

## 4. Provisión paso a paso

### 4.1 Presupuesto y alarmas

1. **Billing → Budgets → Create budget**.
2. Presupuesto mensual de USD 5, alertas al 80 % y 100 %.
3. Añade una segunda alerta a USD 10 por si el free tier no cubre algo.

### 4.2 Instancia EC2

En **EC2 → Launch instance**:

| Campo | Valor |
|---|---|
| Name | `autotributo-staging` |
| AMI | Ubuntu Server 24.04 LTS (x86_64) |
| Instance type | `t3.micro` |
| Key pair | `autotributo-staging` (ed25519) |
| Network | VPC por defecto, subred pública |
| Auto-assign public IP | **Enable** |
| Storage | 30 GiB gp3, cifrado |
| IMDS | **IMDSv2 obligatorio** (`HttpTokens=required`) |

Security group `autotributo-staging-sg`:

| Puerto | Origen | Uso |
|---|---|---|
| 22/tcp | **Tu IP /32** | SSH |
| 80/tcp | 0.0.0.0/0 | HTTP-01 de Let's Encrypt y redirección |
| 443/tcp | 0.0.0.0/0 | HTTPS |
| 443/udp | 0.0.0.0/0 | HTTP/3 (opcional) |

**No abras 8080 ni 5432.** Reverb y Postgres no se publican.

**No asignes Elastic IP.** La IP pública cambia al detener/iniciar la instancia;
DuckDNS la actualiza.

### 4.3 DuckDNS

1. Entra en DuckDNS, crea el subdominio `autotributo-staging` y copia el token.
2. Conéctate a la instancia y edita el archivo de configuración:

   ```bash
   sudo nano /etc/autotributo/duckdns.env
   ```

   ```dotenv
   DUCKDNS_DOMAIN=autotributo-staging
   DUCKDNS_TOKEN=tu-token-de-duckdns
   ```

3. Fuerza una actualización y verifica:

   ```bash
   sudo systemctl start autotributo-duckdns.service
   journalctl -u autotributo-duckdns.service -n 20 --no-pager
   dig +short autotributo-staging.duckdns.org
   ```

   El `dig` debe devolver la IP pública actual de la instancia (la ves en la
   consola de EC2). El timer la refresca cada 5 minutos.

### 4.4 RDS (PostgreSQL)

La base de datos se creó en AWS RDS (PostgreSQL 16, `db.t3.micro`, free tier).
No es públicamente accesible: solo la EC2 puede conectarse (misma VPC).

1. Verifica la conexión desde la instancia:

   ```bash
   psql -h autotributo-staging-db.c78i02wm4b1g.us-east-2.rds.amazonaws.com \
     -U autotributo -d autotributo
   ```

2. Para conectarte desde tu Mac, usa un túnel SSH:

   ```bash
   ssh -i ~/.ssh/autotributo-staging.pem \
     -L 5432:autotributo-staging-db.c78i02wm4b1g.us-east-2.rds.amazonaws.com:5432 \
     ubuntu@<IP_PUBLICA> -N
   ```

   Luego conecta a `localhost:5432` con cualquier cliente PostgreSQL.

La RDS se apaga/enciende junto con la EC2 usando `ec2-control.sh`.

### 4.5 Preparar el host

Conéctate por SSH y ejecuta:

```bash
sudo apt-get update
sudo apt-get install -y git
git clone https://github.com/julioserpone/autotributo.git /opt/autotributo
cd /opt/autotributo
sudo ./scripts/staging/provision-host.sh
```

El script instala Docker, Node 24, 2 GB de swap, UFW (22/80/443) y el timer de
DuckDNS. Cierra la sesión y vuelve a entrar para usar `docker` sin `sudo`.
Verifica:

```bash
docker version
docker compose version
node -v          # v24.x
free -h          # swap 2.0Gi
sudo ufw status
```

### 4.6 Configurar `.env`

```bash
cd /opt/autotributo
cp .env.staging.example .env
chmod 600 .env
nano .env
```

Edita como mínimo:

- `STAGING_HOSTNAME` y `ACME_EMAIL`.
- `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD`, `DB_DATABASE` (Neon).
- `APP_URL` y `SRI_ROBOT_CALLBACK_URL` con el hostname de DuckDNS.
- `UID`/`GID` si el usuario de la instancia no es `1000` (ubuntu por defecto).

> No pongas comentarios al final de una línea de valor (`APP_ENV=staging # ...`):
> Docker Compose los pasa como parte del valor. Usa comentarios en línea propia.

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

1. Actualiza el código a `origin/main`.
2. Compila los assets (`npm ci && npm run build`).
3. Construye la imagen `autotributo/php:staging`.
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

### Apagar y encender

Desde tu máquina local, sin ir a la consola de AWS:

```bash
./scripts/staging/ec2-control.sh stop     # apagar (deja de cobrar compute + IPv4)
./scripts/staging/ec2-control.sh start    # encender (obtiene nueva IP, DuckDNS se actualiza en ≤5 min)
./scripts/staging/ec2-control.sh reboot   # reiniciar
./scripts/staging/ec2-control.sh status   # ver estado e IP pública
```

O desde la instancia por SSH/SSM:

```bash
sudo shutdown -h now
```

Al arrancar de nuevo, la IP pública puede cambiar. El timer de DuckDNS la
actualiza en ≤ 5 minutos. El certificado de Caddy persiste en el volumen
`caddy_data`, no se vuelve a emitir.

Si necesitas forzar la actualización:

```bash
sudo systemctl start autotributo-duckdns.service
```

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

No borres el volumen `caddy_data` salvo que quieras volver a emitir el
certificado (hay límite de emisiones de Let's Encrypt).

## 7. Backups y restauración

- **Neon** aplica PITR en la rama `staging` (según el plan). Es la primera
  línea de defensa.
- Backup lógico opcional a S3 (solo si se activa S3):

  ```bash
  pg_dump "$DATABASE_URL" --format=custom --no-owner \
    | gzip > "/tmp/autotributo-staging-$(date +%F).dump.gz"
  aws s3 cp /tmp/autotributo-staging-*.dump.gz \
    s3://autotributo-staging-app/backups/staging/
  ```

- Restauración de ensayo:

  ```bash
  createdb -h HOST -U USUARIO autotributo_restore
  gunzip -c backup.dump.gz | pg_restore -h HOST -U USUARIO -d autotributo_restore
  ```

  Cambia `DB_DATABASE` al destino y reinicia; nunca restaures sobre producción.

## 8. Rollback

El deploy normal siempre va a `origin/main`. Para volver a un commit anterior:

```bash
cd /opt/autotributo
git log --oneline -10
git reset --hard <sha-anterior>
SKIP_GIT_PULL=1 ./scripts/staging/deploy.sh
```

Las migraciones son expand/contract (`docs/03`): no se revierte una migración
destructiva; se corrige hacia adelante.

## 9. Seguridad

- SSH solo desde tu IP; usuario `ubuntu` con clave, sin contraseña.
- Sin usuario root para operar; MFA en la cuenta AWS.
- `APP_DEBUG=false`, `APP_ENV=staging`, `SESSION_SECURE_COOKIE=true`.
- `.env` con permisos `600`; secretos fuera del repositorio y de las capturas.
- Puertos publicados: solo 80/443. Reverb y Postgres quedan en la red interna.
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

Cuando el entorno ya no se necesite:

1. Detén y termina la instancia EC2 (`Terminate instance`).
2. Borra el security group, la clave SSH y cualquier snapshot/EBS sobrante.
3. Borra el subdominio de DuckDNS.
4. Borra la rama `staging` de Neon (conserva `main` si se migrará a Cloud).
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

- [ ] Presupuesto AWS con alarmas a USD 5/10.
- [ ] EC2 `t3.micro` con IMDSv2, 30 GB gp3 y security group 22/80/443.
- [ ] DuckDNS resuelve al hostname y el timer actualiza tras un stop/start.
- [ ] Neon `staging` responde con `sslmode=require`.
- [ ] `docker compose -f docker-compose.staging.yml config --quiet` sin errores.
- [ ] `deploy.sh` completa y `/up` devuelve 200 por HTTPS.
- [ ] Certificado Let's Encrypt válido y renovable.
- [ ] `app`, `queue`, `scheduler`, `reverb` y `web` healthy.
- [ ] `app:provision` crea el estudio y el owner puede configurar 2FA.
- [ ] Cola `database` procesa un job de prueba y `schedule:list` muestra tareas.
- [ ] Login, empresa, auditoría y `realtime/status` funcionan en la UI.
- [ ] `.env` con permisos 600 y secretos copiados al gestor.
- [ ] Workflow manual de GitHub Actions desplegando por SSH.
- [ ] Sin datos reales ni credenciales SRI de producción.
