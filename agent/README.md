# IT Asset Maintenance Management System Agent

The agent is a .NET 8 Windows executable designed to run silently as a Windows Service.

It reports endpoint health, device status, hardware profile, network details, installed software, and heartbeat check-ins to the IT Asset Maintenance Management System server.

## Build

```powershell
cd AssetInventoryAgent
dotnet publish -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -p:EnableCompressionInSingleFile=true
```

## Configure

```powershell
AssetInventoryAgent.exe --configure --server "https://monitor.example.gov" --port 443 --registration-key "change-this-registration-key" --interval 60
```

## Run Once

```powershell
AssetInventoryAgent.exe --once
```

## Data Locations

For upgrade compatibility, local state is stored in:

- Config: `C:\ProgramData\AssetInventoryAgent\config.json`
- Log: `C:\ProgramData\AssetInventoryAgent\agent.log`

## Installer

Compile `installer\AssetInventoryAgent.iss` with Inno Setup after publishing the agent.

The installer creates a Windows Service display named `IT Asset Maintenance Management System Agent`, configures automatic restart on failure, and starts the first monitoring cycle immediately.
