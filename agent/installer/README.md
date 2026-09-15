# IT Asset Maintenance Management System Agent Installer

This folder builds a user-friendly setup wizard named:

`ITAssetMaintenanceManagementSystemAgentSetup.exe`

## Build Requirements

Install these on the build computer:

- .NET 8 SDK
- Inno Setup 6

## Build the EXE

Double-click:

`BUILD-INSTALLER.bat`

Or run:

```powershell
.\BUILD-INSTALLER.ps1
```

The final installer will be created here:

`agent\installer\dist\ITAssetMaintenanceManagementSystemAgentSetup.exe`

## Installer UI

The setup wizard asks the user for:

- Server IP/hostname or full URL
- Server port
- Registration key
- Monitoring interval in minutes

Use the server hostname or IP address configured by the administrator. Use HTTPS for production and any non-private network address.

After install, it:

- Saves configuration to `C:\ProgramData\AssetInventoryAgent\config.json`
- Stores logs, queue, and state in `C:\ProgramData\AssetInventoryAgent`
- Protects the configuration folder for SYSTEM and Administrators
- Removes old scheduled-task based agent runners
- Creates a Windows Service display named `IT Asset Maintenance Management System Agent` that starts automatically with Windows
- Configures service recovery so Windows restarts the agent if it crashes
- Runs one monitoring scan immediately, so the endpoint appears in the server during installation
- Sends regular heartbeats and uploads endpoint health/software changes when detected
- Lets the server mark endpoints offline naturally after missed heartbeats
- Adds a normal Windows uninstall entry

## Silent Install

```powershell
ITAssetMaintenanceManagementSystemAgentSetup.exe /VERYSILENT /SERVER="https://inventory.example.gov" /PORT=443 /KEY="<unique-registration-key>" /INTERVAL=60 /HEARTBEAT=30 /SCAN=60
```
