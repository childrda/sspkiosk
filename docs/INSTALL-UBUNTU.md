# SSP Kiosk — Ubuntu Server Install Guide

Step-by-step production install on a **clean Ubuntu 22.04 or 24.04 LTS** server using:

- **Apache** (web server)
- **MySQL** (database)
- **PHP 8.2+** (application runtime)
- **Composer** and **Node.js** (build assets)

Application install path: **`/var/www/sspkiosk`**

Google service account JSON path: **`/var/www/sspkiosk/storage/app/google/service-account.json`**

Replace every placeholder (`yourdistrict.org`, passwords, tokens) with your district values before going live.

---

## Table of contents

1. [Before you begin](#1-before-you-begin)
2. [Prepare the Ubuntu server](#2-prepare-the-ubuntu-server)
3. [Install Apache, MySQL, PHP, Composer, and Node](#3-install-apache-mysql-php-composer-and-node)
4. [Configure MySQL](#4-configure-mysql)
5. [Configure Apache](#5-configure-apache)
6. [Deploy the Laravel application](#6-deploy-the-laravel-application)
7. [Configure `.env`](#7-configure-env)
8. [Google credentials on the server](#8-google-credentials-on-the-server)
9. [Google Workspace setup (step by step)](#9-google-workspace-setup-step-by-step)
10. [Slack setup (step by step)](#10-slack-setup-step-by-step)
11. [Queue worker and scheduler](#11-queue-worker-and-scheduler)
12. [Admin account, kiosks, and verification](#12-admin-account-kiosks-and-verification)
13. [HTTPS with Let's Encrypt](#13-https-with-lets-encrypt)
14. [Troubleshooting](#14-troubleshooting)

---

## 1. Before you begin

Gather these before installing:

| Item | Example | Used for |
|------|---------|----------|
| Public hostname | `kiosk.yourdistrict.org` | `APP_URL`, OAuth redirect, Slack interactivity URL |
| Student Google domain | `students.yourdistrict.org` | Registration sign-in |
| Google Cloud project | `district-ssp-kiosk` | OAuth + Admin SDK |
| Google super-admin email | `admin@yourdistrict.org` | Service account impersonation |
| Slack workspace | District tech workspace | Approvals |
| Kiosk network ranges | `10.10.0.0/16` | `KIOSK_ALLOWED_NETWORKS` |

**Security model (do not skip):**

- Students never get a direct “reset my password” API.
- Flow: validated kiosk → **encrypted pending password** (inactive) → **Slack approval** → Google reset job applies that password.
- Students may leave the kiosk after seeing the pending password once (`temporary_generated`) or after submitting their chosen password (`student_selected_pending_approval`). They do **not** wait on a screen for Slack approval.
- Passwords are never posted to Slack, never logged, and never shown in the admin dashboard (only metadata such as “encrypted pending password exists: yes/no”).

---

## 2. Prepare the Ubuntu server

### Step 2.1 — Log in and update packages

```bash
sudo apt update && sudo apt upgrade -y
```

### Step 2.2 — Set timezone and hostname (recommended)

```bash
sudo timedatectl set-timezone America/New_York
sudo hostnamectl set-hostname sspkiosk-prod
```

Use your district timezone.

### Step 2.3 — Create a deploy user (optional)

```bash
sudo adduser deploy
sudo usermod -aG www-data deploy
```

Log in as `deploy` for git/composer work; Apache runs as `www-data`.

### Step 2.4 — Configure firewall (if UFW is enabled)

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Apache Full'
sudo ufw enable
sudo ufw status
```

---

## 3. Install Apache, MySQL, PHP, Composer, and Node

### Step 3.1 — Install Apache and MySQL

```bash
sudo apt install -y apache2 mysql-server
```

### Step 3.2 — Install PHP and extensions

**Ubuntu 24.04** (PHP 8.3):

```bash
sudo apt install -y \
  php php-cli libapache2-mod-php \
  php-mysql php-mbstring php-xml php-curl \
  php-zip php-gd php-intl php-bcmath php-readline
```

**Ubuntu 22.04** (PHP 8.2 — use versioned packages if `php` meta-package is older):

```bash
sudo apt install -y \
  php8.2 php8.2-cli libapache2-mod-php8.2 \
  php8.2-mysql php8.2-mbstring php8.2-xml php8.2-curl \
  php8.2-zip php8.2-gd php8.2-intl php8.2-bcmath php8.2-readline
```

Confirm PHP is **8.2 or newer**:

```bash
php -v
```

### Step 3.3 — Enable required Apache modules

```bash
sudo a2enmod rewrite ssl headers
sudo systemctl enable apache2
sudo systemctl restart apache2
```

`rewrite` is required for Laravel routing (`public/.htaccess`).

### Step 3.4 — Install Composer

```bash
cd ~
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer --version
```

### Step 3.5 — Install Node.js (for Vite build)

```bash
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
node -v
npm -v
```

---

## 4. Configure MySQL

### Step 4.1 — Run MySQL secure installation

```bash
sudo mysql_secure_installation
```

Answer the prompts (set root password, remove anonymous users, disallow remote root login, remove test DB).

### Step 4.2 — Create database and application user

```bash
sudo mysql
```

In the MySQL shell:

```sql
CREATE DATABASE sspkiosk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'sspkiosk'@'localhost' IDENTIFIED BY 'REPLACE_WITH_STRONG_DB_PASSWORD';

GRANT ALL PRIVILEGES ON sspkiosk.* TO 'sspkiosk'@'localhost';

FLUSH PRIVILEGES;

EXIT;
```

Save the password for `.env` (`DB_PASSWORD`).

### Step 4.3 — Test the database login

```bash
mysql -u sspkiosk -p sspkiosk -e "SELECT 1;"
```

---

## 5. Configure Apache

### Step 5.1 — Create the site configuration file

```bash
sudo nano /etc/apache2/sites-available/sspkiosk.conf
```

Paste (replace `kiosk.yourdistrict.org`):

```apache
<VirtualHost *:80>
    ServerName kiosk.yourdistrict.org
    ServerAdmin admin@yourdistrict.org

    DocumentRoot /var/www/sspkiosk/public

    <Directory /var/www/sspkiosk/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/sspkiosk-error.log
    CustomLog ${APACHE_LOG_DIR}/sspkiosk-access.log combined
</VirtualHost>
```

`AllowOverride All` lets Laravel’s `public/.htaccess` route all requests through `index.php`.

### Step 5.2 — Enable the site and disable the default (optional)

```bash
sudo a2ensite sspkiosk.conf
sudo a2dissite 000-default.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

### Step 5.3 — DNS

Create an **A record** (or internal DNS) pointing `kiosk.yourdistrict.org` to this server’s IP.

### Step 5.4 — Kiosk networks and reverse proxies

If kiosks reach the app through a load balancer or reverse proxy, configure Laravel **trusted proxies** so `$request->ip()` matches the real kiosk IP for `KIOSK_ALLOWED_NETWORKS`. See [Laravel trusted proxies](https://laravel.com/docs/requests#configuring-trusted-proxies).

Restrict `/kiosk/*` at the firewall to district networks when possible.

---

## 6. Deploy the Laravel application

### Step 6.1 — Create the install directory

```bash
sudo mkdir -p /var/www/sspkiosk
sudo chown deploy:www-data /var/www/sspkiosk
```

Use your deploy username instead of `deploy` if different.

### Step 6.2 — Copy application files

**Option A — Git:**

```bash
cd /var/www/sspkiosk
git clone YOUR_REPO_URL .
```

**Option B — Upload a release archive** into `/var/www/sspkiosk`.

### Step 6.3 — Install PHP dependencies and build assets

```bash
cd /var/www/sspkiosk

composer install --no-dev --optimize-autoloader

cp .env.example .env
php artisan key:generate

npm ci
npm run build
```

### Step 6.4 — Run migrations

```bash
php artisan migrate --force
```

This creates all application tables, including pending-password columns on `password_reset_requests` (`reset_mode`, `encrypted_pending_password`, and related timestamps). Run again after deploying updates that add migrations.

### Step 6.5 — Cache configuration (after `.env` is complete)

Do this again after editing `.env` in [section 7](#7-configure-env):

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Step 6.6 — Set storage permissions

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rwx storage bootstrap/cache
```

Student photos are stored under `storage/app/private` (not web-accessible).

---

## 7. Configure `.env`

```bash
nano /var/www/sspkiosk/.env
```

### Step 7.1 — Core application

```env
APP_NAME="SSP Kiosk"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://kiosk.yourdistrict.org
```

Use `http://` only until HTTPS is configured in [section 13](#13-https-with-lets-encrypt); then switch to `https://`.

### Step 7.2 — Database

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sspkiosk
DB_USERNAME=sspkiosk
DB_PASSWORD=REPLACE_WITH_STRONG_DB_PASSWORD
```

### Step 7.3 — Session, queue, and cache

```env
SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
```

### Step 7.4 — Google (filled in detail in section 9)

```env
STUDENT_GOOGLE_DOMAIN=students.yourdistrict.org
ALLOWED_STUDENT_ORG_UNITS=/Students
BLOCKED_STAFF_ORG_UNITS=/Staff,/Domain Admins

GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=https://kiosk.yourdistrict.org/auth/google/callback

GOOGLE_SERVICE_ACCOUNT_JSON_PATH=/var/www/sspkiosk/storage/app/google/service-account.json
GOOGLE_ADMIN_IMPERSONATION_EMAIL=admin@yourdistrict.org
GOOGLE_DIRECTORY_SCOPES=https://www.googleapis.com/auth/admin.directory.user
```

### Step 7.5 — Slack (filled in detail in section 10)

```env
SLACK_BOT_TOKEN=
SLACK_SIGNING_SECRET=
SLACK_RESET_CHANNEL_ID=
SLACK_APPROVER_USERGROUP_ID=
```

### Step 7.6 — Password reset mode

Choose how students provide the password that will be applied **only if** staff approve the request in Slack.

```env
RESET_PASSWORD_MODE=temporary_generated
# allowed: temporary_generated, student_selected_pending_approval

GOOGLE_FORCE_CHANGE_AT_NEXT_LOGIN_TEMPORARY=true
GOOGLE_FORCE_CHANGE_AT_NEXT_LOGIN_STUDENT_SELECTED=false

PENDING_PASSWORD_ENCRYPTION_ENABLED=true
DELETE_PENDING_PASSWORD_ON_APPROVAL=true
DELETE_PENDING_PASSWORD_ON_DENIAL=true
DELETE_PENDING_PASSWORD_ON_EXPIRATION=true
DELETE_PENDING_PASSWORD_ON_GOOGLE_FAILURE=true
RETAIN_PENDING_PASSWORD_ON_GOOGLE_FAILURE=false

PENDING_PASSWORD_DISPLAY_SECONDS=90
PENDING_PASSWORD_COPY_NOTICE_ENABLED=true

PASSWORD_MIN_LENGTH=12
PASSWORD_REQUIRE_UPPERCASE=true
PASSWORD_REQUIRE_LOWERCASE=true
PASSWORD_REQUIRE_NUMBER=true
PASSWORD_REQUIRE_SYMBOL=false
PASSWORD_PREVENT_EMAIL_PARTS=true
PASSWORD_PREVENT_NAME_PARTS=true
```

| Mode | Kiosk behavior after correct challenge answers |
|------|------------------------------------------------|
| `temporary_generated` (default) | System generates a temp password, encrypts it in the database, shows it **once** at `/kiosk/reset/pending-password/{id}`, then queues Slack approval. Student may leave. |
| `student_selected_pending_approval` | Student enters/confirms a password at `/kiosk/reset/password`, sees `/kiosk/reset/submitted/{id}`, then Slack approval is queued. Student may leave. |

If `RESET_PASSWORD_MODE` is missing or invalid, kiosk reset routes fail closed and `php artisan ssp:config-check` reports an error.

See [SETUP.md](SETUP.md) for full flow details.

### Step 7.7 — Kiosk security

```env
KIOSK_ALLOWED_NETWORKS=10.10.0.0/16,192.168.50.0/24
KIOSK_REQUIRE_ACTIVE_HEARTBEAT=true
RESET_REQUIRES_KIOSK=true
```

Comma-separated IPs or CIDR blocks for networks where kiosks are allowed.

### Step 7.8 — Admin dashboard

```env
ADMIN_ROUTE_PREFIX=admin
ADMIN_ALLOWED_EMAILS=tech1@yourdistrict.org,tech2@yourdistrict.org
```

Leave `ADMIN_ALLOWED_EMAILS` empty to allow any user with `is_admin=true`.

### Step 7.9 — Re-cache and validate configuration

```bash
cd /var/www/sspkiosk
php artisan config:clear
php artisan config:cache
php artisan ssp:config-check
```

Fix any missing variables reported before going live.

---

## 8. Google credentials on the server

**Never** place service account JSON under `public/`.

### Step 8.1 — Create the credentials directory

```bash
sudo mkdir -p /var/www/sspkiosk/storage/app/google
sudo chown www-data:www-data /var/www/sspkiosk/storage/app/google
sudo chmod 750 /var/www/sspkiosk/storage/app/google
```

### Step 8.2 — Install the service account key

After downloading JSON from Google Cloud (section 9):

```bash
sudo cp ~/Downloads/your-service-account-key.json \
  /var/www/sspkiosk/storage/app/google/service-account.json

sudo chown www-data:www-data /var/www/sspkiosk/storage/app/google/service-account.json
sudo chmod 640 /var/www/sspkiosk/storage/app/google/service-account.json
```

### Step 8.3 — Confirm `.env` path

```env
GOOGLE_SERVICE_ACCOUNT_JSON_PATH=/var/www/sspkiosk/storage/app/google/service-account.json
```

---

## 9. Google Workspace setup (step by step)

You will configure **Google Cloud Console** (APIs, OAuth, service account) and **Google Workspace Admin** (domain-wide delegation).

### Part A — Google Cloud project

#### Step 9.1 — Create a project

1. Open [Google Cloud Console](https://console.cloud.google.com/).
2. Click the project dropdown → **New project**.
3. Name: `district-ssp-kiosk` (or your standard).
4. Click **Create** and select the new project.

#### Step 9.2 — Enable the Admin SDK API

1. Go to **APIs & Services → Library**.
2. Search for **Admin SDK API**.
3. Click **Admin SDK API** → **Enable**.

This API provides Directory access for org-unit checks and password resets.

---

### Part B — OAuth (student registration sign-in)

Students sign in with Google during registration (`/register` → Google → callback).

#### Step 9.3 — Configure the OAuth consent screen

1. Go to **APIs & Services → OAuth consent screen**.
2. **User type:** choose **Internal** (only users in your Google Workspace organization).
3. Click **Create**.
4. Fill in:
   - **App name:** `SSP Kiosk`
   - **User support email:** your tech team address
   - **Developer contact:** your tech team address
5. Click **Save and continue**.
6. On **Scopes**, click **Add or remove scopes** and add:
   - `openid`
   - `.../auth/userinfo.email`
   - `.../auth/userinfo.profile`
7. Save through the remaining screens (test users not required for Internal).

#### Step 9.4 — Create an OAuth Web client

1. Go to **APIs & Services → Credentials**.
2. Click **Create credentials → OAuth client ID**.
3. **Application type:** Web application.
4. **Name:** `SSP Kiosk Web`.
5. **Authorized JavaScript origins** (optional): `https://kiosk.yourdistrict.org`
6. **Authorized redirect URIs** — add exactly:
   ```
   https://kiosk.yourdistrict.org/auth/google/callback
   ```
   Use `http://` only for temporary testing before HTTPS.
7. Click **Create**.
8. Copy the **Client ID** and **Client secret** into `.env`:
   ```env
   GOOGLE_CLIENT_ID=123456789-xxxx.apps.googleusercontent.com
   GOOGLE_CLIENT_SECRET=GOCSPX-xxxxxxxx
   GOOGLE_REDIRECT_URI=https://kiosk.yourdistrict.org/auth/google/callback
   ```

The redirect URI must match **character-for-character** in Cloud Console and `.env`.

---

### Part C — Service account (Admin SDK: directory lookup + password reset)

#### Step 9.5 — Create a service account

1. **APIs & Services → Credentials**.
2. **Create credentials → Service account**.
3. **Name:** `ssp-kiosk-admin-sdk`.
4. **Service account ID:** auto-filled → **Create and continue**.
5. Skip optional role grants (Workspace delegation is configured in Admin Console) → **Done**.

#### Step 9.6 — Create and download a JSON key

1. Click the new service account in the list.
2. Open the **Keys** tab.
3. **Add key → Create new key → JSON → Create**.
4. Save the downloaded file securely (you will upload it to the server in [section 8](#8-google-credentials-on-the-server)).

#### Step 9.7 — Enable domain-wide delegation

1. On the service account details page, open **Advanced settings** (or **Details**).
2. Under **Domain-wide delegation**, click **Enable Google Workspace Domain-wide Delegation**.
3. Note the **Client ID** (numeric) — you need it in Admin Console.

---

### Part D — Google Workspace Admin Console

#### Step 9.8 — Authorize the service account (domain-wide delegation)

1. Sign in to [Google Admin Console](https://admin.google.com/) as a super admin.
2. Go to **Security → Access and data control → API controls**.
3. Under **Domain-wide delegation**, click **Manage Domain Wide Delegation**.
4. Click **Add new**.
5. **Client ID:** paste the service account numeric Client ID from step 9.7.
6. **OAuth scopes** — paste (comma-separated, no spaces):

   ```
   https://www.googleapis.com/auth/admin.directory.user,https://www.googleapis.com/auth/admin.directory.user.readonly
   ```

   Password reset requires the `admin.directory.user` scope. Use the minimum scopes your security team approves.

7. Click **Authorize**.

#### Step 9.9 — Choose the impersonation admin account

The application impersonates a Workspace admin when calling the Directory API.

1. Use a **dedicated** admin account (not a personal mailbox), e.g. `ssp-kiosk-admin@yourdistrict.org`.
2. The account must be able to read student users and reset passwords.
3. Set in `.env`:

   ```env
   GOOGLE_ADMIN_IMPERSONATION_EMAIL=ssp-kiosk-admin@yourdistrict.org
   ```

#### Step 9.10 — Organize student org units

Registration rejects staff and out-of-scope accounts when org units are configured.

1. In Admin Console, go to **Directory → Organizational units**.
2. Place **students** under a path such as `/Students` (or `/Students/High School`, etc.).
3. Set `.env` to match:

   ```env
   ALLOWED_STUDENT_ORG_UNITS=/Students
   BLOCKED_STAFF_ORG_UNITS=/Staff,/Domain Admins
   ```

4. Set the student hosted domain:

   ```env
   STUDENT_GOOGLE_DOMAIN=students.yourdistrict.org
   ```

---

### Part E — Verify Google on the server

```bash
cd /var/www/sspkiosk
php artisan tinker
```

```php
app(\App\Services\GoogleWorkspaceDirectoryLookupService::class)
    ->findUserByEmail('astudent@students.yourdistrict.org');
```

- Expect an object with user data if the account exists and delegation works.
- Expect `null` if the user does not exist.
- If you get an exception, re-check JSON path, delegation scopes, and impersonation email.

Test registration in a browser: `https://kiosk.yourdistrict.org/register` → sign in with a **student** test account.

---

## 10. Slack setup (step by step)

Slack is where technology staff **approve, deny, or escalate** reset requests. The pending password (generated or student-selected) is **never** sent to Slack — messages only state that an encrypted password is waiting for approval.

### Part A — Create the Slack app

#### Step 10.1 — Create the app

1. Go to [Slack API — Your apps](https://api.slack.com/apps).
2. Click **Create New App → From scratch**.
3. **App name:** `SSP Kiosk`.
4. **Workspace:** select your district workspace → **Create app**.

#### Step 10.2 — Add bot token scopes

1. In the left sidebar, open **OAuth & Permissions**.
2. Under **Scopes → Bot Token Scopes**, click **Add an OAuth Scope** and add:

   | Scope | Purpose |
   |-------|---------|
   | `chat:write` | Post and update approval messages |
   | `files:write` | Upload registration/reset photos to the channel |
   | `usergroups:read` | Verify approvers belong to the tech user group |

3. Save changes.

#### Step 10.3 — Install the app to the workspace

1. Scroll to **OAuth Tokens for Your Workspace**.
2. Click **Install to Workspace** → allow permissions.
3. Copy the **Bot User OAuth Token** (starts with `xoxb-`).
4. Add to `.env`:

   ```env
   SLACK_BOT_TOKEN=xoxb-your-token-here
   ```

#### Step 10.4 — Copy the signing secret

1. Open **Basic Information** in the left sidebar.
2. Under **App Credentials**, copy **Signing Secret**.
3. Add to `.env`:

   ```env
   SLACK_SIGNING_SECRET=your-signing-secret-here
   ```

The application verifies every interactive request using this secret ([Slack request signing](https://api.slack.com/authentication/verifying-requests-from-slack)).

---

### Part B — Interactivity (Approve / Deny buttons)

#### Step 10.5 — Enable interactivity

1. In the app settings, open **Interactivity & Shortcuts**.
2. Turn **Interactivity** **On**.
3. **Request URL:**

   ```
   https://kiosk.yourdistrict.org/slack/interactions
   ```

   Slack must reach this URL over HTTPS from the public internet (use your production hostname).

4. Click **Save Changes**.

Slack may send a verification request; the endpoint must return successfully once the app is deployed and `SLACK_SIGNING_SECRET` is set.

---

### Part C — Approval channel and approver group

#### Step 10.6 — Create or choose a private channel

1. In Slack, create a channel such as `#student-password-resets` (private recommended).
2. Invite the **SSP Kiosk** bot to the channel (`/invite @SSP Kiosk`).
3. Get the **channel ID**:
   - Open the channel → click the channel name → scroll to the bottom of **About**, or
   - Right-click the channel → **View channel details** → copy the ID at the bottom (starts with `C`).

4. Add to `.env`:

   ```env
   SLACK_RESET_CHANNEL_ID=C0123456789
   ```

#### Step 10.7 — Create a Slack user group for approvers

Only members of this group may approve resets in Slack.

1. In Slack, click your workspace name → **Tools & settings → Manage members** (or **User groups**).
2. Create a user group, e.g. `password-reset-approvers`.
3. Add technology staff who may approve requests.
4. Get the **user group ID** (starts with `S`):
   - From Slack API, or admin tools; often visible in the group URL or via `usergroups.list` API.

5. Add to `.env`:

   ```env
   SLACK_APPROVER_USERGROUP_ID=S0123456789
   ```

---

### Part D — Test Slack end-to-end

1. Ensure the queue worker is running ([section 11](#11-queue-worker-and-scheduler)).
2. Complete a kiosk reset request through challenge questions (and password entry if using `student_selected_pending_approval`).
3. **Before approving in Slack:**
   - `temporary_generated`: confirm the student saw the pending password once at `/kiosk/reset/pending-password/{id}` (they do not need to stay at the kiosk).
   - `student_selected_pending_approval`: confirm the submitted confirmation screen at `/kiosk/reset/submitted/{id}`.
4. Confirm a Block Kit message appears in the reset channel with photos and **Approve / Deny / Needs Office Verification** buttons.
5. Confirm the Slack message text mentions an encrypted pending password and does **not** contain the actual password.
6. Click **Approve** as a user in the approver group.
7. Confirm the message updates and `ResetGooglePasswordJob` runs (check `storage/logs/laravel.log` and queue worker logs). The request should reach status **completed**.
8. In the admin dashboard, open the request detail: verify reset mode and pending-password metadata are shown, with **no** password reveal.
9. Confirm the student can sign in to Google with the pending password only **after** approval (and `changePasswordAtNextLogin` behavior matches your mode settings).

---

## 11. Queue worker and scheduler

Slack notifications and Google password resets run on the queue. **The queue worker must run continuously.**

### Step 11.1 — Create a systemd service

```bash
sudo nano /etc/systemd/system/sspkiosk-queue.service
```

```ini
[Unit]
Description=SSP Kiosk Queue Worker
After=network.target mysql.service apache2.service

[Service]
User=www-data
Group=www-data
Restart=always
RestartSec=5
WorkingDirectory=/var/www/sspkiosk
ExecStart=/usr/bin/php /var/www/sspkiosk/artisan queue:work database --sleep=3 --tries=3 --max-time=3600

[Install]
WantedBy=multi-user.target
```

### Step 11.2 — Enable and start the worker

```bash
sudo systemctl daemon-reload
sudo systemctl enable sspkiosk-queue
sudo systemctl start sspkiosk-queue
sudo systemctl status sspkiosk-queue
```

### Step 11.3 — Laravel scheduler (optional)

```bash
sudo crontab -u www-data -e
```

Add:

```cron
* * * * * cd /var/www/sspkiosk && php artisan schedule:run >> /dev/null 2>&1
```

---

## 12. Admin account, kiosks, and verification

### Step 12.1 — Create an admin user

```bash
cd /var/www/sspkiosk
php artisan admin:create-user admin@yourdistrict.org --name="SSP Admin"
```

Enter a strong password when prompted.

Sign in at: `https://kiosk.yourdistrict.org/admin/login`

### Step 12.2 — Create a kiosk (dashboard or CLI)

**Admin dashboard:**

1. Sign in at `https://kiosk.yourdistrict.org/admin/login`.
2. Open **Kiosks → Create kiosk**.
3. Save the **one-time enrollment code** shown after creation.

**CLI:**

```bash
php artisan kiosk:create "Library Front Desk" --school="Main School" --location="Library" --subnet=10.10.20.0/24
php artisan kiosk:enrollment-code KIOSK_UUID_OR_ID
```

### Step 12.3 — Register (enroll) the physical kiosk

**Recommended:** DHCP reservation per kiosk, `allowed_ip` on the kiosk record, and the **`sspkiosk-agent`** heartbeat service.

1. In admin, create the kiosk and set **allowed IP** to the DHCP reservation.
2. Issue a one-time enrollment code.
3. On the kiosk PC:

   ```bash
   sudo bash /var/www/sspkiosk/agent/install.sh
   sudo sspkiosk-agent enroll --code XXXX-XXXX-XXXX --server https://kiosk.yourdistrict.org
   sudo systemctl enable --now sspkiosk-agent
   sspkiosk-agent check
   ```

4. Open `https://kiosk.yourdistrict.org/kiosk/reset` in kiosk mode. With `allowed_ip` set, a missing browser cookie is recovered from source IP automatically.

**Browser enrollment (alternative):**

1. On the kiosk, open `https://kiosk.yourdistrict.org/kiosk/enroll`.
2. Enter the enrollment code and submit.
3. Copy the **one-time secret** on `/kiosk/enroll/complete`, install the agent, then continue to `/kiosk/reset`.
4. Set the browser home page to `/kiosk/reset`. Do not clear cookies for this profile.

**Admin:** After enrollment or secret rotation, download `agent.conf` once from the kiosk detail page (provisioning bundle) while the secret is flashed.

If students will **register** on this kiosk and you cannot use DHCP + `allowed_ip`, call `POST /kiosk/bind-session` with HMAC headers and a browser session cookie.

Full details: [SETUP.md — Registering a kiosk](SETUP.md#registering-a-kiosk).

### Step 12.4 — Verification checklist

| Check | How |
|-------|-----|
| App health | `curl -I https://kiosk.yourdistrict.org/up` |
| Configuration | `php artisan ssp:config-check` (includes valid `RESET_PASSWORD_MODE`) |
| Kiosk enrollment | `sspkiosk-agent check` succeeds; admin shows kiosk **Online** |
| Heartbeat agent | `sudo systemctl status sspkiosk-agent` active |
| Registration | Open `/register`, sign in with a student test account |
| Kiosk reset | Complete a test reset through pending-password or submitted screen |
| Reset mode | `.env` has `RESET_PASSWORD_MODE=temporary_generated` or `student_selected_pending_approval` |
| Pending password | After a test reset, admin request detail shows encrypted pending password metadata (not the password) |
| Slack approval | Approve a test request; queue worker processes `ResetGooglePasswordJob` |
| Admin | Open `/admin/dashboard` |
| Queue | `sudo systemctl status sspkiosk-queue` |
| Logs | `tail -f /var/www/sspkiosk/storage/logs/laravel.log` (no plaintext passwords) |

---

## 13. HTTPS with Let's Encrypt

Production requires HTTPS for Google OAuth, Slack interactivity, and kiosk cameras (browser permissions).

### Step 13.1 — Install Certbot for Apache

```bash
sudo apt install -y certbot python3-certbot-apache
```

### Step 13.2 — Obtain a certificate

```bash
sudo certbot --apache -d kiosk.yourdistrict.org
```

Follow the prompts (email, agree to terms, redirect HTTP to HTTPS recommended).

### Step 13.3 — Update `.env` to HTTPS

```env
APP_URL=https://kiosk.yourdistrict.org
GOOGLE_REDIRECT_URI=https://kiosk.yourdistrict.org/auth/google/callback
```

Update Google OAuth redirect URI and Slack Request URL if you used `http://` during testing.

```bash
cd /var/www/sspkiosk
php artisan config:cache
```

Certbot installs a renewal timer automatically. Test renewal:

```bash
sudo certbot renew --dry-run
```

---

## 14. Troubleshooting

| Problem | What to check |
|---------|----------------|
| Apache 403/404 on routes | `AllowOverride All`, `a2enmod rewrite`, `DocumentRoot` is `.../public` |
| Apache 500 | `storage/logs/laravel.log`, permissions on `storage/` and `bootstrap/cache` |
| Blank page / white screen | `APP_DEBUG=true` temporarily, `php artisan config:clear` |
| MySQL connection refused | `DB_*` in `.env`, `sudo systemctl status mysql` |
| Google OAuth redirect error | Redirect URI exact match in Cloud Console and `.env`; HTTPS in production |
| `redirect_uri_mismatch` | Same URI in Console, `.env`, and browser address bar scheme (`https`) |
| Directory lookup fails | Domain-wide delegation, scopes, impersonation email, JSON file path and permissions |
| Google password reset fails | Service account delegation, admin impersonation rights, student email in Workspace |
| Slack buttons do nothing | Request URL reachable, `SLACK_SIGNING_SECRET`, queue worker running |
| Slack “not authorized” | User is in `SLACK_APPROVER_USERGROUP_ID` group |
| Slack photos missing | `files:write` scope, bot invited to channel |
| Jobs not processing | `sudo systemctl status sspkiosk-queue`, `QUEUE_CONNECTION=database`, `jobs` table |
| Kiosk 401 Unauthorized | HMAC headers, clock sync (`ntp`), correct secret after enrollment |
| Kiosk reset unavailable / redirect to `/kiosk/reset/unavailable` | Invalid or missing `RESET_PASSWORD_MODE`; run `php artisan ssp:config-check` |
| Pending password screen unavailable | Request missing `encrypted_pending_password`, already displayed once, or wrong kiosk session |
| Student password form errors | `PASSWORD_*` policy in `.env`; server-side validation always applies |
| Kiosk “not available” / IP blocked | `KIOSK_ALLOWED_NETWORKS`, Apache/proxy real client IP |
| Photos not saving | `php-gd` installed, `storage/app/private` writable by `www-data` |
| Admin login loop | `SESSION_DRIVER=database`, sessions table migrated, cookies over HTTPS |

**Useful commands:**

```bash
# Apache error log
sudo tail -f /var/log/apache2/sspkiosk-error.log

# Application log
tail -f /var/www/sspkiosk/storage/logs/laravel.log

# Queue worker
sudo journalctl -u sspkiosk-queue -f

# Configuration report
cd /var/www/sspkiosk && php artisan ssp:config-check
```

---

## Related documentation

- [SETUP.md](SETUP.md) — day-to-day configuration, kiosk HMAC signing, feature overview
- [README.md](../README.md) — project overview

**Official references:**

- [Google OpenID Connect](https://developers.google.com/identity/openid-connect/openid-connect)
- [Google Admin SDK — users](https://developers.google.com/workspace/admin/directory/reference/rest/v1/users)
- [Slack Block Kit](https://api.slack.com/block-kit)
- [Slack request signing](https://api.slack.com/authentication/verifying-requests-from-slack)
