# Easy Dashboard

A self-hosted PHP start page for the services and links you use regularly. Group services, add icons and notes, and share selected cards with signed-in guests. Easy Dashboard stores its data in JSON files and needs no database or Composer dependencies.

## Install

Use PHP 8.2 or later. Enable PHP GD if you want PNG or JPEG uploads. For deployment, use an HTTPS-enabled web server and a dedicated PHP worker where possible.

1. Clone the repository and deploy `index.php` and `storage.php` to the dashboard's web directory.
2. Create a private directory **outside** the web root. Replace `www-data` with the PHP worker user and group in these commands.

   ```bash
   sudo install -d -m 0700 -o www-data -g www-data /var/lib/easy-dashboard
   sudo install -d -m 0700 -o www-data -g www-data /var/lib/easy-dashboard/icons
   ```

3. Set `DASHBOARD_DATA_DIR=/var/lib/easy-dashboard` in the PHP worker's environment. In PHP-FPM, add `env[DASHBOARD_DATA_DIR] = /var/lib/easy-dashboard` to the pool and reload PHP-FPM. If TLS terminates at a reverse proxy, configure the web server so PHP receives the trusted HTTPS request status.
4. Create the `admin` password from the deployed directory, using Bash. Use 12–72 bytes.

   ```bash
   read -rsp 'New dashboard administrator password: ' dashboard_password
   printf '\n'
   printf '%s\n' "$dashboard_password" |
     sudo -u www-data env DASHBOARD_DATA_DIR=/var/lib/easy-dashboard php index.php set-admin-password
   unset dashboard_password
   ```

5. Open the dashboard URL and sign in as `admin`. Run the same command to reset the administrator password.

For local use, create a private directory alongside the checkout, run the password setup as your own user, and start the development server from the checkout.

```bash
install -d -m 0700 ../easy-dashboard-data ../easy-dashboard-data/icons
export DASHBOARD_DATA_DIR="$(realpath ../easy-dashboard-data)"
php -S 127.0.0.1:8080
```

## Use the dashboard

- In **Admin Controls**, add a group such as *Work*. Open the group menu, choose **Add Service**, and enter a title, description and HTTP or HTTPS URL.
- Choose a Font Awesome class, emoji, remote HTTP(S) image URL or PNG/JPEG upload. Uploaded images are limited to 2 MB and 2048 pixels on each side.
- Use **Create Guest User**, then edit a service. Turn on **Share with all signed-in guests** or leave it off and select individual guests. Leave both settings empty for a private service. Anyone who can see a card can read its note.
- Use **Export Config** to export the dashboard layout, sharing rules and notes. **Import Config** replaces the dashboard configuration. It expects an export in the Easy Dashboard format. Guest accounts and uploaded images are stored separately.
- Back up the private data directory to preserve `dashboard_config.json`, `users.json` and `icons/` together. Treat configuration exports and full backups as private. If the panel is reachable beyond a private network, configure request rate limits for sign-in at the web server or reverse proxy.

## Upgrade an existing installation

Take the old installation offline and back up the existing data before upgrading. Copy `dashboard_config.json` and `users.json` from the old installation's actual storage location. Earlier versions could store them in the checkout, its `data/` directory, or a system temporary directory. Copy the original `icons/` PNG files into the new private `icons/` directory. Give the PHP worker ownership of the copied files, set file permissions to `0600`, and remove the old public copies before bringing the site back online. Reset the administrator password if the installation still uses the original default. Review imported guest sharing rules against the accounts in the destination installation.
