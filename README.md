# IT Asset Maintenance Management System

Enterprise IT asset maintenance management platform with a PHP/MySQL server and a C# Windows background agent.

## Components

- `server/` - PHP 8 MVC web application with dashboard, RBAC, office hierarchy, device health views, reports, exports, audit logs, and agent API.
- `database/mysql.sql` - MySQL schema and seed data.
- `agent/AssetInventoryAgent/` - C# .NET Windows IT asset monitoring agent source.
- `agent/installer/AssetInventoryAgent.iss` - Inno Setup EXE installer configuration.
- `docs/` - API and deployment documentation.

## Sign In

Users sign in with a local username/password. Access is allowed only for active registered users.

After first login, create named administrator accounts, assign local usernames and passwords from the Users screen, and disable the seeded admin account.

## Server Quick Start with WampServer

1. Create a MySQL database and import `database/mysql.sql`.
2. Configure Apache to use `server/public` as the document root and enable `mod_rewrite`.
3. Set the database values and `AGENT_REGISTRATION_KEY` in the Apache/PHP environment.
4. Browse to the configured local host URL and append `/login`.

## Agent Build

Install .NET 8 SDK and Inno Setup on a Windows build machine.

```powershell
cd agent\AssetInventoryAgent
dotnet publish -c Release -r win-x64 --self-contained true
```

Then open `agent\installer\AssetInventoryAgent.iss` in Inno Setup and compile. The installer output will be created in `agent\installer\dist`.

## Agent Installation Values

During installation, enter:

- Server address: `https://your-server/asset-inventory/server/public`
- Port: `443`
- Registration key: value from `AGENT_REGISTRATION_KEY` or the Settings screen
- Monitoring interval in minutes

Use HTTPS for production and any non-private network address.

The installer creates a Windows Service display named `IT Asset Maintenance Management System Agent` running as `LocalSystem`.

## Production Notes

- Put Apache behind HTTPS or configure an SSL certificate directly in Apache.
- Use environment variables or the Settings screen for the agent registration key; do not store production secrets in source files.
- Schedule `php server/scripts/purge_sensitive_logs.php` and tune the retention environment variables for logs.
- Restrict firewall access to the server.
- Use a dedicated MySQL user with least privileges.
- Keep MySQL, PHP, and Windows endpoints patched.
- Prefer a virtual host pointing directly at `server/public`.

## Key Features

- Endpoint online/offline monitoring.
- Device profile, hardware, BIOS, network, and software visibility.
- Software change tracking and update history.
- Super Admin, Admin, and User roles.
- Users belong to one office, and devices can be assigned to employees.
- One employee can be assigned several devices.
- Agent registration, authenticated monitoring upload, heartbeat, and logs.
- Login logs and audit logs.
- Responsive Bootstrap 5 dashboard with charts and DataTables.
