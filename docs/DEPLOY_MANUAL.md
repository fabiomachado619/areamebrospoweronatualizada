# Deploy manual — Power On LMS (VPS)

Este documento descreve como publicar o código no GitHub e operar a plataforma em uma VPS Ubuntu, com **atualização manual e controlada**.

## Conceito

| Onde | O que fica |
|------|------------|
| **GitHub** | Somente código-fonte da aplicação |
| **VPS** | `.env`, banco MySQL, uploads, usuários, senhas, webhooks, certificados, backups |

O script `deploy-update.sh` puxa código novo do GitHub **sem apagar dados locais**.

---

## 1. Clonar o projeto na VPS (primeira vez)

```bash
# Como usuário deploy
sudo mkdir -p /var/www/getfy
sudo chown -R deploy:www-data /var/www/getfy
sudo -u deploy -i

cd /var/www/getfy
git clone https://github.com/fabiomachado619/areamebrospoweronatualizada.git .
git checkout main
```

Requisitos na VPS: PHP 8.2+, Composer, Node 20+, MySQL 8, Redis, Nginx, Supervisor.

---

## 2. Configurar `.env` na VPS

```bash
cp .env.example .env
nano .env
```

Valores mínimos de produção:

```dotenv
APP_NAME="Power On"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.seudominio.com
APP_INSTALLED=true
APP_AUTO_MIGRATE=false

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=getfy
DB_USERNAME=getfy
DB_PASSWORD=SENHA_FORTE

QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=database

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

Gerar chave:

```bash
php artisan key:generate --force
```

Proteger o arquivo:

```bash
chmod 640 .env
chown deploy:www-data .env
```

> **Nunca** commite `.env` no GitHub.

---

## 3. Primeira instalação na VPS

```bash
cd /var/www/getfy

# Banco (criar manualmente no MySQL antes)
composer install --no-dev --optimize-autoloader --no-interaction

php artisan migrate --force
php artisan storage:link

npm ci
npm run build

php artisan config:cache
php artisan route:cache
php artisan view:cache

# Permissões
sudo chown -R deploy:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 775 {} \;
sudo find storage bootstrap/cache -type f -exec chmod 664 {} \;
```

Configure Nginx com `root` apontando para `/var/www/getfy/public`.

Configure Supervisor para a fila:

```ini
command=php /var/www/getfy/artisan queue:work redis --sleep=3 --tries=3
user=deploy
```

Configure cron (usuário `deploy`):

```cron
* * * * * cd /var/www/getfy && php artisan schedule:run >> /dev/null 2>&1
```

---

## 4. Criar o administrador

1. Acesse `https://app.seudominio.com/criar-admin`
2. Crie o primeiro usuário administrador
3. Faça login em `https://app.seudominio.com/admin/login`

Após existir admin, `/criar-admin` deixa de estar disponível.

---

## 5. Atualização futura (manual)

Quando quiser atualizar o código:

```bash
cd /var/www/getfy
bash deploy-update.sh
```

Opcional — variáveis de ambiente:

```bash
APP_DIR=/var/www/getfy \
BRANCH=main \
PHP_FPM_SERVICE=php8.3-fpm \
SUPERVISOR_PROGRAM=getfy-worker \
ENABLE_DB_BACKUP=1 \
bash deploy-update.sh
```

---

## 6. O que o script atualiza

- Código-fonte via `git pull origin main`
- Dependências PHP (`composer install --no-dev`)
- Assets frontend (`npm ci` + `npm run build`)
- Migrations (`php artisan migrate --force`)
- Caches Laravel (`config`, `route`, `view`, `event`)
- Permissões de `storage/` e `bootstrap/cache/`
- Reinício da fila (Supervisor) e reload do PHP-FPM
- Modo manutenção durante o processo

Também registra log em `storage/backups/deploy/deploy_YYYYMMDD_HHMMSS.log` e, se disponível, backup SQL opcional em `.sql.gz`.

---

## 7. O que o script NÃO mexe

- `.env` e segredos locais
- `storage/app/` (uploads de cursos, capas, PDFs, etc.)
- `storage/app/public/` (arquivos de alunos)
- Banco de dados (exceto migrations incrementais)
- Usuários, alunos, cursos, progresso, certificados
- Webhooks configurados no painel
- Configurações de tenant / área de membros
- Plugins instalados via painel (`storage/app/plugins-installed`)

O script **não executa** `git clean`, `git reset --hard` destructivo em arquivos locais, nem apaga pastas de upload.

---

## 8. Como voltar se der erro

### Durante o deploy (modo manutenção ativo)

1. Anote o commit anterior no log: `storage/backups/deploy/deploy_*.log`
2. Volte o código:

```bash
cd /var/www/getfy
git log --oneline -5
git checkout <hash-anterior>
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize:clear && php artisan config:cache && php artisan route:cache
php artisan up
sudo supervisorctl restart getfy-worker:*
```

### Se migration falhou

1. Corrija o erro em `storage/logs/laravel.log`
2. Restaure backup SQL se necessário:

```bash
gunzip -c storage/backups/deploy/db_YYYYMMDD_HHMMSS.sql.gz | mysql -u getfy -p getfy
```

3. Volte o código para o commit anterior (passos acima)

### Se o site ficou em manutenção

```bash
php artisan up
```

---

## 9. Onde ficam os logs

| Log | Caminho |
|-----|---------|
| Aplicação Laravel | `storage/logs/laravel.log` |
| Worker (Supervisor) | `storage/logs/worker.log` (conforme config do Supervisor) |
| Deploy manual | `storage/backups/deploy/deploy_*.log` |
| Nginx | `/var/log/nginx/error.log` |
| PHP-FPM | `/var/log/php8.3-fpm.log` |

---

## 10. Checklist antes de atualizar

- [ ] Backup manual do banco (ou confirme `ENABLE_DB_BACKUP=1`)
- [ ] `git status` limpo ou alterações locais intencionais documentadas
- [ ] `.env` intacto na VPS
- [ ] Espaço em disco suficiente (`df -h`)
- [ ] Redis e MySQL ativos
- [ ] Supervisor com worker rodando
- [ ] Janela de manutenção comunicada (se produção com tráfego)
- [ ] Testar `/up` após deploy
- [ ] Testar login admin e área de membros
- [ ] Verificar fila: `supervisorctl status`

---

## Segurança — o que NUNCA subir para o GitHub

- `.env` e variantes (`.env.production`, etc.)
- Banco de dados e dumps `.sql`
- `storage/` real (logs, cache, sessões, uploads)
- Chaves, tokens, `APP_KEY`, senhas
- `vendor/` e `node_modules/` (instalar na VPS)
- Backups, arquivos `.zip`, credenciais de gateway
- Uploads de alunos e cursos

## O que permanece só na VPS

- `.env` local
- MySQL local
- Uploads em `storage/app/public/`
- Backups em `storage/backups/`
- Plugins instalados pelo painel
- Configurações de produção e integrações

---

## Repositório GitHub

```
https://github.com/fabiomachado619/areamebrospoweronatualizada.git
```

Branch principal: `main`

Atualização **manual** — sem GitHub Actions automático e sem deploy por push.
