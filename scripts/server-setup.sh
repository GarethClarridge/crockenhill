#!/bin/bash
set -e

echo "=== Installing Docker ==="
curl -fsSL https://get.docker.com | sh
systemctl enable --now docker

echo "=== Creating deploy user ==="
useradd -m -s /bin/bash -G docker deploy
mkdir -p /home/deploy/.ssh
cp /root/.ssh/authorized_keys /home/deploy/.ssh/
chown -R deploy:deploy /home/deploy/.ssh
chmod 700 /home/deploy/.ssh
chmod 600 /home/deploy/.ssh/authorized_keys

echo "=== Creating application directory ==="
mkdir -p /srv/crockenhill
chown deploy:deploy /srv/crockenhill

echo "=== Configuring firewall ==="
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

echo "=== Setup complete ==="
echo ""
echo "Next steps:"
echo "1. Add the deploy user's SSH private key to GitHub Actions secrets as PROD_SSH_KEY (the public key stays on the server in authorized_keys)"
echo "2. Copy docker-compose.prod.yml and Caddyfile to /srv/crockenhill/"
echo "3. Create /srv/crockenhill/.env.production with your credentials"
echo "4. Run: docker compose -f docker-compose.prod.yml pull"
echo "5. Run: docker compose -f docker-compose.prod.yml up -d"
