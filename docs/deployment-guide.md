# Deployment Guide: Migrating to Docker

This guide covers migrating the existing Crockenhill Baptist Church website from the current server to a fresh DigitalOcean droplet running Docker.

---

## Before You Start

### Gather credentials from your current production server

SSH to your existing server and copy these values from your `.env` file. You'll need them later:

```
APP_KEY=base64:...
DO_SPACES_KEY=...
DO_SPACES_SECRET=...
DO_SPACES_REGION=...
DO_SPACES_BUCKET=...
DO_SPACES_ENDPOINT=...
MAILGUN_DOMAIN=...
MAILGUN_SECRET=...
OPENAI_API_KEY=...
LIVESTREAM_ADMIN_EMAIL=...
```

Save these somewhere secure - you'll paste them into the new server's `.env.production` file.

---

## Phase 1: Local Preparation

### 1.1 Update docker-compose.prod.yml with your GitHub username

Edit `docker-compose.prod.yml` and change:

```yaml
image: ghcr.io/OWNER/crockenhill:${IMAGE_TAG:-latest}
```

To:

```yaml
image: ghcr.io/garethclarridge/crockenhill:${IMAGE_TAG:-latest}
```

### 1.2 Commit the deployment files

```bash
git add Dockerfile Caddyfile docker-compose.prod.yml \
    docker/production/ .github/workflows/deploy.yml scripts/
git commit -m "Add production deployment configuration"
# Don't push yet - wait until GitHub Environment is configured
```

---

## Phase 2: GitHub Setup

### 2.1 Create the GitHub Environment

1. Go to your repository on GitHub
2. **Settings → Environments → New environment**
3. Name it: `production`
4. (Optional) Enable **Required reviewers** if you want manual approval before deploys

### 2.2 Prepare environment secrets

You'll add these secrets after creating the droplet:

| Secret | Value |
|--------|-------|
| `PROD_HOST` | Droplet IP address (from Phase 3) |
| `PROD_USER` | `deploy` |
| `PROD_SSH_KEY` | Private key (from Phase 4) |

---

## Phase 3: Create DigitalOcean Droplet

### 3.1 Create the droplet

1. Log in to DigitalOcean
2. **Create → Droplets**
3. Settings:
   - **Region**: London (or nearest to your users)
   - **Image**: Ubuntu 24.04 LTS
   - **Size**: Basic $9/month (1 vCPU, 2GB RAM, 50GB SSD)
   - **Authentication**: SSH key (use your existing key)
   - **Hostname**: `crockenhill-prod`
4. **Create Droplet**
5. Note the IP address (you'll need this for GitHub secrets)

### 3.2 Enable backups

1. Go to the droplet → **Backups**
2. Enable weekly backups ($1.80/month)

---

## Phase 4: Server Setup

### 4.1 SSH to the new droplet as root

```bash
ssh root@NEW_DROPLET_IP
```

### 4.2 Run the setup script

```bash
curl -fsSL https://get.docker.com | sh
systemctl enable --now docker

useradd -m -s /bin/bash -G docker deploy
mkdir -p /home/deploy/.ssh
cp /root/.ssh/authorized_keys /home/deploy/.ssh/
chown -R deploy:deploy /home/deploy/.ssh
chmod 700 /home/deploy/.ssh
chmod 600 /home/deploy/.ssh/authorized_keys

mkdir -p /srv/crockenhill
chown deploy:deploy /srv/crockenhill

ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable
```

### 4.3 Generate SSH key for GitHub Actions

```bash
su - deploy
ssh-keygen -t ed25519 -C "github-actions-deploy" -f ~/.ssh/github_actions -N ""
cat ~/.ssh/github_actions.pub >> ~/.ssh/authorized_keys
```

### 4.4 Copy the private key for GitHub

```bash
cat ~/.ssh/github_actions
```

Copy the entire output (including `-----BEGIN OPENSSH PRIVATE KEY-----` and `-----END OPENSSH PRIVATE KEY-----`). You'll add this to GitHub in the next phase.

```bash
exit  # Back to root
exit  # Disconnect from server
```

---

## Phase 5: Complete GitHub Secrets

Go to GitHub → **Settings → Environments → production → Environment secrets** and add:

| Secret | Value |
|--------|-------|
| `PROD_HOST` | Your new droplet's IP address |
| `PROD_USER` | `deploy` |
| `PROD_SSH_KEY` | The private key from Phase 4.4 |

---

## Phase 6: Export Data from Old Server

### 6.1 SSH to your current production server

```bash
ssh user@OLD_SERVER_IP
```

### 6.2 Export the database

```bash
mysqldump -u YOUR_DB_USER -p YOUR_DATABASE > ~/crockenhill_backup.sql
```

### 6.3 Export local storage

Page images and other local files (sermons are already in Spaces, so they don't need exporting):

```bash
cd /path/to/your/laravel/project
tar -czf ~/storage_backup.tar.gz storage/app/public
```

### 6.4 Download backups to your local machine

```bash
# Run these from your local machine
scp user@OLD_SERVER_IP:~/crockenhill_backup.sql ./
scp user@OLD_SERVER_IP:~/storage_backup.tar.gz ./
```

---

## Phase 7: Prepare Production Files on New Server

### 7.1 SSH as deploy user

```bash
ssh deploy@NEW_DROPLET_IP
cd /srv/crockenhill
```

### 7.2 Copy docker-compose.prod.yml from your local machine

On your local machine:

```bash
scp docker-compose.prod.yml deploy@NEW_DROPLET_IP:/srv/crockenhill/
scp Caddyfile deploy@NEW_DROPLET_IP:/srv/crockenhill/
```

### 7.3 Create .env.production on the server

Back on the server (`ssh deploy@NEW_DROPLET_IP`):

```bash
cd /srv/crockenhill
nano .env.production
```

Paste the following, filling in values from your existing production `.env`:

```bash
APP_NAME="Crockenhill Baptist Church"
APP_ENV=production
APP_DEBUG=false
# During testing, set this to the IP address (e.g., http://123.123.123.123)
# After DNS switch, change to https://crockenhill.org
APP_URL=https://crockenhill.org

# COPY FROM EXISTING .env - this must match or encrypted data will break
APP_KEY=base64:YOUR_EXISTING_APP_KEY

LOG_CHANNEL=stack
LOG_LEVEL=warning

# New database credentials (for the new MySQL container)
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=crockenhill
DB_USERNAME=crockenhill
DB_PASSWORD=GENERATE_NEW_SECURE_PASSWORD

# Redis (container name as hostname)
REDIS_HOST=redis
REDIS_PORT=6379
CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

# COPY FROM EXISTING .env
FILESYSTEM_DISK=do_spaces
SERMON_STORAGE_DISK=do_spaces
DO_SPACES_KEY=your_existing_value
DO_SPACES_SECRET=your_existing_value
DO_SPACES_REGION=your_existing_value
DO_SPACES_BUCKET=your_existing_value
DO_SPACES_ENDPOINT=your_existing_value
DO_SPACES_CDN_ENDPOINT=your_existing_value

# COPY FROM EXISTING .env
MAIL_MAILER=mailgun
MAILGUN_DOMAIN=your_existing_value
MAILGUN_SECRET=your_existing_value
MAIL_FROM_ADDRESS=admin@crockenhill.org
MAIL_FROM_NAME="Crockenhill Baptist Church"

# COPY FROM EXISTING .env
OPENAI_API_KEY=your_existing_value
TRANSCRIPTION_SERVICE_TYPE=openai
ANALYSIS_SERVICE=openai

# Media Processing
FFMPEG_PATH=/usr/bin/ffmpeg
FFPROBE_PATH=/usr/bin/ffprobe
LIVESTREAM_ADMIN_EMAIL=your_existing_value
```

Generate a new database password:

```bash
openssl rand -base64 24
```

Use this for `DB_PASSWORD` above. This password is only for the new MySQL container - it doesn't need to match your old server.

---

## Phase 8: First Deployment

### 8.1 Push your changes to trigger the build

On your local machine:

```bash
cd /path/to/crockenhill
git push origin master
```

This will:
1. Run tests
2. Build the Docker image
3. Push to GitHub Container Registry
4. **The deploy step will fail** - that's expected, we haven't started services yet

### 8.2 Log in to GitHub Container Registry

On the new server, you need to authenticate to pull the Docker image.

**For a private repository**, create a Personal Access Token:
1. GitHub → Settings → Developer settings → Personal access tokens → Tokens (classic)
2. Generate new token with `read:packages` scope
3. On the server:

```bash
ssh deploy@NEW_DROPLET_IP
echo "YOUR_PERSONAL_ACCESS_TOKEN" | docker login ghcr.io -u garethclarridge --password-stdin
```

**For a public repository**, you can skip this step - no authentication needed.

### 8.3 Start the containers

```bash
cd /srv/crockenhill
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml up -d
```

Wait for MySQL to be ready:

```bash
docker compose -f docker-compose.prod.yml logs -f mysql
# Wait until you see "ready for connections"
# Press Ctrl+C to exit
```

### 8.4 Import the database

Upload the backup to the server (from your local machine):

```bash
scp crockenhill_backup.sql deploy@NEW_DROPLET_IP:/srv/crockenhill/
```

Import it (on the server):

```bash
cd /srv/crockenhill

# Source the .env.production to get DB credentials
export $(grep -E '^DB_(DATABASE|USERNAME|PASSWORD)=' .env.production | xargs)

# Import the database
docker compose -f docker-compose.prod.yml exec -T mysql \
  mysql -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" < crockenhill_backup.sql
```

### 8.5 Verify the database import

```bash
# Check that tables exist and have data
docker compose -f docker-compose.prod.yml exec mysql \
  mysql -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" -e "SHOW TABLES;"

# Check sermon count matches what you expect
docker compose -f docker-compose.prod.yml exec mysql \
  mysql -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" -e "SELECT COUNT(*) FROM sermons;"

# Check user count
docker compose -f docker-compose.prod.yml exec mysql \
  mysql -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" -e "SELECT COUNT(*) FROM users;"
```

### 8.6 Import local storage

Upload the backup (from your local machine):

```bash
scp storage_backup.tar.gz deploy@NEW_DROPLET_IP:/srv/crockenhill/
```

Extract it into the container (on the server):

```bash
cd /srv/crockenhill

# Extract the tar file locally first
tar -xzf storage_backup.tar.gz

# Copy the contents into the Docker volume
docker compose -f docker-compose.prod.yml cp \
  storage/app/public/. app:/var/www/html/storage/app/public/

# Fix permissions
docker compose -f docker-compose.prod.yml exec app \
  chown -R www:www /var/www/html/storage/app/public

# Clean up
rm -rf storage storage_backup.tar.gz
```

### 8.7 Run migrations and optimize

```bash
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
docker compose -f docker-compose.prod.yml exec app php artisan optimize
```

---

## Phase 9: Test Before DNS Switch

### 9.1 Temporarily configure Caddy for IP-based testing

Edit the Caddyfile to allow testing without SSL:

```bash
cd /srv/crockenhill
cp Caddyfile Caddyfile.production  # Save the production version

cat > Caddyfile << 'EOF'
:80 {
    reverse_proxy app:80
}
EOF

docker compose -f docker-compose.prod.yml restart caddy
```

### 9.2 Test via IP address

Visit `http://NEW_DROPLET_IP` in your browser (note: HTTP, not HTTPS).

> [!TIP]
> If links on the site redirect you to the real URL, make sure `APP_URL` in `.env.production` is set to `http://NEW_DROPLET_IP` and you have run `php artisan optimize`.

Verify:
- [ ] Homepage loads
- [ ] Sermons page shows existing sermons (data from Spaces)
- [ ] Page images display correctly (data from local storage)
- [ ] Can log in to members area
- [ ] Admin panel works (if applicable)

### 9.3 Restore production Caddyfile

```bash
cd /srv/crockenhill
# Also update APP_URL to the real domain
nano .env.production
# APP_URL=https://crockenhill.org

docker compose -f docker-compose.prod.yml exec app php artisan optimize
mv Caddyfile.production Caddyfile
docker compose -f docker-compose.prod.yml restart caddy
```

---

## Phase 10: DNS Switch

### 10.1 Update DNS records

In your domain registrar or DNS provider, update:

| Type | Name | Value | TTL |
|------|------|-------|-----|
| A | @ | NEW_DROPLET_IP | 300 |
| A | www | NEW_DROPLET_IP | 300 |

### 10.2 Wait for propagation

```bash
# Check if DNS has updated
dig crockenhill.org +short
# Should show your new IP

# Or use an online tool like https://dnschecker.org
```

### 10.3 Verify SSL

Once DNS propagates, Caddy will automatically obtain Let's Encrypt certificates.

Visit `https://crockenhill.org` - it should load with a valid SSL certificate.

If SSL isn't working, check Caddy logs:

```bash
docker compose -f docker-compose.prod.yml logs caddy
```

---

## Phase 11: Post-Migration

### 11.1 Verify automated deployments work

Make a small change (e.g., update a comment), commit, and push to master. Confirm the GitHub Action completes successfully and the change appears on the live site.

### 11.2 Clean up the database backup on the server

```bash
ssh deploy@NEW_DROPLET_IP
rm /srv/crockenhill/crockenhill_backup.sql
```

### 11.3 Set up uptime monitoring

1. Go to [UptimeRobot](https://uptimerobot.com/) (free)
2. Add a new monitor:
   - **Monitor Type**: HTTP(s)
   - **URL**: `https://crockenhill.org/up`
   - **Monitoring Interval**: 5 minutes
3. Set alert email

### 11.4 Keep old server as backup

Keep the old droplet running for ~1 week. If anything goes wrong, you can switch DNS back immediately.

After confirming everything works, delete the old droplet.

---

## Quick Reference

### View logs

```bash
# All containers
docker compose -f docker-compose.prod.yml logs -f

# Specific service
docker compose -f docker-compose.prod.yml logs -f app

# Laravel logs
docker compose -f docker-compose.prod.yml exec app tail -f storage/logs/laravel.log
```

### Run artisan commands

```bash
docker compose -f docker-compose.prod.yml exec app php artisan <command>
```

### Manual deployment

```bash
cd /srv/crockenhill
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml up -d
docker compose -f docker-compose.prod.yml exec app php artisan optimize
```

### Restart services

```bash
docker compose -f docker-compose.prod.yml restart
```

### Database backup

```bash
cd /srv/crockenhill
export $(grep -E '^DB_(DATABASE|USERNAME|PASSWORD)=' .env.production | xargs)
docker compose -f docker-compose.prod.yml exec -T mysql \
  mysqldump -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" > backup-$(date +%Y%m%d).sql
```

### Rollback to previous version

```bash
# Find previous image tags
docker images ghcr.io/garethclarridge/crockenhill

# Deploy specific version (first 7 chars of commit SHA)
IMAGE_TAG=abc1234 docker compose -f docker-compose.prod.yml up -d
```
