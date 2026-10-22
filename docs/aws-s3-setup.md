# AWS S3: buckets por ambiente e IAM

Guía operativa para usar **tu cuenta de AWS** como object storage de Autotributo. En local el almacenamiento por defecto es el **disco**; S3 es opcional para probar el flujo real.

## 1. Regla de oro: un bucket por ambiente

Nunca se comparte un bucket entre entornos. El nombre incluye el ambiente y la función:

| Ambiente | App (`AWS_BUCKET`) | Robot (`SRI_ROBOT_OUTPUT_BUCKET`) | Uso |
|---|---|---|---|
| Local (opcional) | `autotributo-local-app` | `autotributo-local-sri` | Pruebas manuales contra AWS |
| Test / integración | `autotributo-test-app` | `autotributo-test-sri` | Solo si un test necesita S3 real |
| Staging | `autotributo-staging-app` | `autotributo-staging-sri` | UAT y ensayos |
| Producción | `autotributo-prod-app` | `autotributo-prod-sri` | Datos reales |

Además:

- Un usuario IAM por ambiente y por componente (app / robot). Sin credenciales compartidas.
- Credenciales de producción **jamás** en una máquina local ni en un `.env` versionado.
- La aplicación debe fallar al arrancar si `AWS_BUCKET` no corresponde a `APP_ENV`.
- Región recomendada: `us-east-1` (menor latencia y coste para Ecuador). Usa la misma región para ambos buckets.

## 2. Crear los buckets

```bash
export AWS_REGION=us-east-1
export ENV=prod                    # local | test | staging | prod
export APP_BUCKET="autotributo-${ENV}-app"
export SRI_BUCKET="autotributo-${ENV}-sri"

# En us-east-1 no se pasa LocationConstraint. En otras regiones, añade:
#   --create-bucket-configuration LocationConstraint="$AWS_REGION"
aws s3api create-bucket --bucket "$APP_BUCKET" --region "$AWS_REGION"
aws s3api create-bucket --bucket "$SRI_BUCKET" --region "$AWS_REGION"
```

### Bloqueo de acceso público

```bash
for BUCKET in "$APP_BUCKET" "$SRI_BUCKET"; do
  aws s3api put-public-access-block \
    --bucket "$BUCKET" \
    --public-access-block-configuration \
      BlockPublicAcls=true,IgnorePublicAcls=true,BlockPublicPolicy=true,RestrictPublicBuckets=true
done
```

### Versionado y cifrado

```bash
for BUCKET in "$APP_BUCKET" "$SRI_BUCKET"; do
  aws s3api put-bucket-versioning \
    --bucket "$BUCKET" \
    --versioning-configuration Status=Enabled

  aws s3api put-bucket-encryption \
    --bucket "$BUCKET" \
    --server-side-encryption-configuration '{
      "Rules": [{
        "ApplyServerSideEncryptionByDefault": { "SSEAlgorithm": "AES256" },
        "BucketKeyEnabled": false
      }]
    }'
done
```

Si un cliente exige claves gestionadas, sustituye `AES256` por `aws:kms` y añade `KMSMasterKeyID`. SSE-KMS tiene coste por uso de KMS; SSE-S3 es suficiente para el MVP.

### Política TLS obligatorio

Crea `tls-only-policy.json`:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "DenyInsecureTransport",
      "Effect": "Deny",
      "Principal": "*",
      "Action": "s3:*",
      "Resource": [
        "arn:aws:s3:::BUCKET",
        "arn:aws:s3:::BUCKET/*"
      ],
      "Condition": { "Bool": { "aws:SecureTransport": "false" } }
    }
  ]
}
```

Aplícala a ambos buckets:

```bash
for BUCKET in "$APP_BUCKET" "$SRI_BUCKET"; do
  sed "s/BUCKET/$BUCKET/g" tls-only-policy.json > "/tmp/tls-policy-$BUCKET.json"
  aws s3api put-bucket-policy \
    --bucket "$BUCKET" --policy "file:///tmp/tls-policy-$BUCKET.json"
done
```

## 3. Lifecycle

Crea `lifecycle-app.json`:

```json
{
  "Rules": [
    {
      "ID": "documents-to-cold-storage",
      "Status": "Enabled",
      "Filter": { "Prefix": "tenants/" },
      "Transitions": [
        { "Days": 90, "StorageClass": "STANDARD_IA" },
        { "Days": 365, "StorageClass": "GLACIER_IR" }
      ],
      "AbortIncompleteMultipartUpload": { "DaysAfterInitiation": 7 }
    }
  ]
}
```

```bash
aws s3api put-bucket-lifecycle-configuration \
  --bucket "$APP_BUCKET" --lifecycle-configuration file://lifecycle-app.json
```

Notas:

- **No** configures expiración automática de los XML ni de versiones anteriores: la retención fiscal es ≥ 7 años y debe revisarse con el contador.
- Los exports temporales se borran con un comando programado a los 7 días. No uses una regla de prefijo para borrarlos si viven bajo `tenants/`, porque podrías borrar documentos.
- El bucket `-sri` solo necesita `AbortIncompleteMultipartUpload` y transición a `STANDARD_IA`; su contenido se rige por la misma retención. Ejemplo: `lifecycle-sri.json` con `"Filter": { "Prefix": "runs/" }`, transición a `STANDARD_IA` a los 90 días y aborto de multipart a los 7 días.

## 4. IAM de mínimo privilegio

### Usuario de la aplicación

`app-policy.json` (reemplaza el nombre del bucket por el del ambiente):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "ObjectsUnderTenants",
      "Effect": "Allow",
      "Action": [
        "s3:PutObject",
        "s3:GetObject",
        "s3:DeleteObject",
        "s3:AbortMultipartUpload",
        "s3:ListMultipartUploadParts",
        "s3:GetObjectAttributes"
      ],
      "Resource": "arn:aws:s3:::autotributo-prod-app/tenants/*"
    },
    {
      "Sid": "ListOwnPrefix",
      "Effect": "Allow",
      "Action": "s3:ListBucket",
      "Resource": "arn:aws:s3:::autotributo-prod-app",
      "Condition": { "StringLike": { "s3:prefix": ["tenants/*", "backups/*"] } }
    },
    {
      "Sid": "BucketLocation",
      "Effect": "Allow",
      "Action": "s3:GetBucketLocation",
      "Resource": "arn:aws:s3:::autotributo-prod-app"
    },
    {
      "Sid": "BackupObjects",
      "Effect": "Allow",
      "Action": [
        "s3:PutObject",
        "s3:GetObject",
        "s3:DeleteObject",
        "s3:AbortMultipartUpload"
      ],
      "Resource": "arn:aws:s3:::autotributo-prod-app/backups/*"
    },
    {
      "Sid": "InvokeSriRobot",
      "Effect": "Allow",
      "Action": "lambda:InvokeFunction",
      "Resource": "arn:aws:lambda:us-east-1:ACCOUNT_ID:function:sri-txt-robot-prod"
    }
  ]
}
```

```bash
aws iam create-user --user-name autotributo-prod-app
aws iam put-user-policy \
  --user-name autotributo-prod-app \
  --policy-name autotributo-prod-app-s3 \
  --policy-document file://app-policy.json
aws iam create-access-key --user-name autotributo-prod-app
```

Reemplaza `ACCOUNT_ID` por el ID de tu cuenta AWS. Guarda `AccessKeyId`/`SecretAccessKey` en el gestor de secretos y configura el entorno de Laravel Cloud. No los pegues en el repositorio ni en capturas.

### Rol del robot SRI

El robot solo escribe la salida:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": ["s3:PutObject", "s3:AbortMultipartUpload"],
      "Resource": "arn:aws:s3:::autotributo-prod-sri/runs/*"
    }
  ]
}
```

Si el robot corre como Lambda, adjunta esta política a su **execution role**. La app solo necesita `lambda:InvokeFunction` sobre esa función.

## 5. Configuración de Laravel

### Local con disco (por defecto)

```dotenv
FILESYSTEM_DISK=local
# No se necesitan credenciales AWS.
```

Los archivos quedan en `storage/app/private`. Es el modo recomendado para el día a día y para los tests.

### Local con AWS S3 (opcional)

```dotenv
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=...              # usuario IAM autotributo-local-app
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=autotributo-local-app
AWS_ENDPOINT=
AWS_USE_PATH_STYLE_ENDPOINT=false
SRI_ROBOT_OUTPUT_BUCKET=autotributo-local-sri
```

### Staging / producción (Laravel Cloud)

Configura las mismas variables en el panel de Cloud, con el usuario y bucket del entorno:

```dotenv
FILESYSTEM_DISK=s3
AWS_BUCKET=autotributo-prod-app
SRI_ROBOT_OUTPUT_BUCKET=autotributo-prod-sri
AWS_DEFAULT_REGION=us-east-1
AWS_ENDPOINT=
AWS_USE_PATH_STYLE_ENDPOINT=false
```

No definas `AWS_ENDPOINT` ni `AWS_USE_PATH_STYLE_ENDPOINT=true` en Cloud: son ajustes para emuladores locales.

### Tests

- Por defecto: `FILESYSTEM_DISK=local` y `Storage::fake()` cuando se prueba lógica de archivos.
- Si un test de integración necesita S3 real, usa `autotributo-test-app` y un usuario IAM de test. **Nunca** staging/producción.
- Los tests no deben depender de AWS para pasar en CI: el pipeline usa disco local.

## 6. Verificación

```bash
# Escritura, lectura y borrado
echo "healthcheck $(date -u +%FT%TZ)" > /tmp/healthcheck.txt
aws s3 cp /tmp/healthcheck.txt "s3://$APP_BUCKET/tenants/healthcheck/healthcheck.txt"
aws s3api head-object --bucket "$APP_BUCKET" --key tenants/healthcheck/healthcheck.txt
aws s3 rm "s3://$APP_BUCKET/tenants/healthcheck/healthcheck.txt"

# Cifrado y versionado
aws s3api get-bucket-encryption --bucket "$APP_BUCKET"
aws s3api get-bucket-versioning --bucket "$APP_BUCKET"

# Bloqueo público
aws s3api get-public-access-block --bucket "$APP_BUCKET"
```

Prueba también desde Laravel cuando exista el esqueleto:

```bash
php artisan tinker
>>> Storage::disk('s3')->put('tenants/healthcheck/test.txt', 'ok');
>>> Storage::disk('s3')->exists('tenants/healthcheck/test.txt');
>>> Storage::disk('s3')->delete('tenants/healthcheck/test.txt');
```

## 7. Operación y seguridad

- **CloudTrail** activo para auditoría de accesos (los data events de S3 tienen coste; actívalos si el cliente lo exige).
- **AWS Budgets** con alerta al 80 % del presupuesto mensual.
- **Rotación de claves** cada 90 días o ante incidente; dos claves activas durante la transición.
- **Sin acceso público**, sin ACLs públicas, sin políticas `Principal: "*"` salvo la de TLS.
- **MFA** en la cuenta raíz y prohibido crear access keys de root.
- **CORS**: no es necesario para operaciones servidor-a-servidor ni para URLs firmadas de descarga. Si en el futuro el navegador sube directo a S3, añade una regla CORS limitada al dominio de la app.
- **Fuga de credenciales:** revocar la clave en IAM, rotar, revisar CloudTrail y notificar según el runbook.

## 8. Coste orientativo

Con precios de referencia de `us-east-1`:

| Concepto | Precio aproximado | Ejemplo |
|---|---|---|
| Almacenamiento Standard | USD 0,023 / GB-mes | 50 GB de XML ≈ USD 1,15/mes |
| Standard-IA | USD 0,0125 / GB-mes | Ahorro a partir de 90 días |
| Requests PUT/GET | USD 0,005 / 1.000 PUT; USD 0,0004 / 1.000 GET | 1 M comprobantes ≈ pocos dólares |
| Egress | USD 0,09 / GB | Solo descargas fuera de AWS |

El coste real se monitorea con Cost Explorer y AWS Budgets. El almacenamiento no es el gasto dominante del producto; la IA y la operación sí.

## 9. Checklist

- [ ] Buckets `-app` y `-sri` creados por ambiente, en la misma región.
- [ ] Bloqueo de acceso público y política TLS aplicados.
- [ ] Versionado y SSE activos.
- [ ] Lifecycle de documentos y limpieza de exports definidos.
- [ ] Usuario IAM por ambiente/componente con política mínima.
- [ ] Credenciales guardadas en el gestor de secretos y cargadas en Cloud.
- [ ] `AWS_BUCKET` coincide con `APP_ENV` (guarda de arranque).
- [ ] Prueba de escritura/lectura/borrado y alerta de presupuesto.
- [ ] Rotación y runbook de fuga de credenciales documentados.
