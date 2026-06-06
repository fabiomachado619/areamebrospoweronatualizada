#!/usr/bin/env bash
#
# Atualização manual do Power On LMS na VPS.
# Uso: bash deploy-update.sh
#
# Este script atualiza APENAS o código da aplicação via git pull.
# NÃO apaga .env, banco, uploads, usuários nem configurações locais.
#
set -euo pipefail

# ---------------------------------------------------------------------------
# Configuração (ajuste na VPS se necessário)
# ---------------------------------------------------------------------------
APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)}"
BRANCH="${BRANCH:-main}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.3-fpm}"
SUPERVISOR_PROGRAM="${SUPERVISOR_PROGRAM:-getfy-worker}"
DEPLOY_USER="${DEPLOY_USER:-deploy}"
BACKUP_DIR="${BACKUP_DIR:-${APP_DIR}/storage/backups/deploy}"
DEPLOY_SECRET="${DEPLOY_SECRET:-deploy-$(date +%Y%m%d%H%M%S)}"
ENABLE_DB_BACKUP="${ENABLE_DB_BACKUP:-1}"

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"
}

fail() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERRO: $*" >&2
    exit 1
}

require_cmd() {
    command -v "$1" >/dev/null 2>&1 || fail "Comando obrigatório não encontrado: $1"
}

cd "$APP_DIR" || fail "Não foi possível entrar em ${APP_DIR}"

log "Iniciando atualização manual em ${APP_DIR} (branch: ${BRANCH})"

# ---------------------------------------------------------------------------
# Pré-requisitos
# ---------------------------------------------------------------------------
require_cmd git
require_cmd php
require_cmd composer
require_cmd npm

if [[ ! -f .env ]]; then
    fail "Arquivo .env não encontrado. Este script não cria .env — configure manualmente na VPS."
fi

# ---------------------------------------------------------------------------
# Backup simples antes de alterar código
# ---------------------------------------------------------------------------
mkdir -p "$BACKUP_DIR"
STAMP="$(date '+%Y%m%d_%H%M%S')"
BACKUP_LOG="${BACKUP_DIR}/deploy_${STAMP}.log"

{
    echo "=== Deploy backup log ==="
    echo "Data/hora: $(date -Iseconds)"
    echo "Diretório: ${APP_DIR}"
    echo "Branch: ${BRANCH}"
    echo "Git antes do pull:"
    git rev-parse HEAD 2>/dev/null || true
    git status --short 2>/dev/null || true
} > "$BACKUP_LOG"

log "Log de backup registrado em ${BACKUP_LOG}"

if [[ "$ENABLE_DB_BACKUP" == "1" ]] && command -v mysqldump >/dev/null 2>&1; then
    DB_HOST="$(grep -E '^DB_HOST=' .env | tail -1 | cut -d= -f2- | tr -d '"'"'" || true)"
    DB_PORT="$(grep -E '^DB_PORT=' .env | tail -1 | cut -d= -f2- | tr -d '"'"'" || true)"
    DB_DATABASE="$(grep -E '^DB_DATABASE=' .env | tail -1 | cut -d= -f2- | tr -d '"'"'" || true)"
    DB_USERNAME="$(grep -E '^DB_USERNAME=' .env | tail -1 | cut -d= -f2- | tr -d '"'"'" || true)"
    DB_PASSWORD="$(grep -E '^DB_PASSWORD=' .env | tail -1 | cut -d= -f2- | tr -d '"'"'" || true)"

    if [[ -n "$DB_DATABASE" && -n "$DB_USERNAME" ]]; then
        DB_DUMP="${BACKUP_DIR}/db_${STAMP}.sql.gz"
        log "Criando backup opcional do banco em ${DB_DUMP}"
        MYSQL_PWD="$DB_PASSWORD" mysqldump \
            -h "${DB_HOST:-127.0.0.1}" \
            -P "${DB_PORT:-3306}" \
            -u "$DB_USERNAME" \
            --single-transaction \
            --quick \
            --lock-tables=false \
            "$DB_DATABASE" | gzip -9 > "$DB_DUMP" || log "AVISO: backup do banco falhou; deploy continua."
    else
        log "AVISO: DB_DATABASE/DB_USERNAME ausentes no .env — backup SQL ignorado."
    fi
else
    log "Backup SQL desabilitado ou mysqldump indisponível."
fi

# ---------------------------------------------------------------------------
# Modo manutenção
# ---------------------------------------------------------------------------
log "Ativando modo manutenção"
php artisan down --retry=60 --secret="$DEPLOY_SECRET" || true
log "URL bypass manutenção: ${APP_URL:-}/${DEPLOY_SECRET} (se APP_URL estiver no .env)"

# ---------------------------------------------------------------------------
# Atualizar código (NÃO usa git clean — preserva .env e storage local)
# ---------------------------------------------------------------------------
log "Atualizando código: git pull origin ${BRANCH}"
git fetch origin "$BRANCH"
git checkout "$BRANCH"
git pull origin "$BRANCH"

# ---------------------------------------------------------------------------
# Dependências e build
# ---------------------------------------------------------------------------
log "Composer install (produção)"
composer install --no-dev --optimize-autoloader --no-interaction

log "Frontend build"
npm ci
npm run build

# ---------------------------------------------------------------------------
# Migrations e caches
# ---------------------------------------------------------------------------
log "Migrations"
php artisan migrate --force

log "Recarregar caches"
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# ---------------------------------------------------------------------------
# Permissões (storage e bootstrap/cache permanecem intactos em conteúdo)
# ---------------------------------------------------------------------------
log "Ajustando permissões de storage e bootstrap/cache"
if command -v sudo >/dev/null 2>&1; then
    sudo chown -R "${DEPLOY_USER}:www-data" storage bootstrap/cache || true
    sudo find storage bootstrap/cache -type d -exec chmod 775 {} \; || true
    sudo find storage bootstrap/cache -type f -exec chmod 664 {} \; || true
else
    chown -R "${DEPLOY_USER}:www-data" storage bootstrap/cache 2>/dev/null || true
    find storage bootstrap/cache -type d -exec chmod 775 {} \; 2>/dev/null || true
    find storage bootstrap/cache -type f -exec chmod 664 {} \; 2>/dev/null || true
fi

# ---------------------------------------------------------------------------
# Reiniciar serviços
# ---------------------------------------------------------------------------
if command -v supervisorctl >/dev/null 2>&1; then
    log "Reiniciando filas: ${SUPERVISOR_PROGRAM}:*"
    supervisorctl restart "${SUPERVISOR_PROGRAM}:"* || log "AVISO: falha ao reiniciar supervisor (verifique nome do programa)."
else
    log "AVISO: supervisorctl não encontrado — reinicie a fila manualmente."
fi

if command -v systemctl >/dev/null 2>&1; then
    log "Recarregando PHP-FPM: ${PHP_FPM_SERVICE}"
    if command -v sudo >/dev/null 2>&1; then
        sudo systemctl reload "$PHP_FPM_SERVICE" || log "AVISO: falha ao recarregar PHP-FPM."
    else
        systemctl reload "$PHP_FPM_SERVICE" || log "AVISO: falha ao recarregar PHP-FPM."
    fi
fi

# ---------------------------------------------------------------------------
# Finalizar
# ---------------------------------------------------------------------------
log "Desativando modo manutenção"
php artisan up

NEW_HASH="$(git rev-parse --short HEAD)"
log "Atualização concluída com sucesso. Commit atual: ${NEW_HASH}"
log "Logs da aplicação: ${APP_DIR}/storage/logs/laravel.log"
