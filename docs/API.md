# IT Asset Maintenance Management System Agent API Documentation

Base URL examples:

- `https://inventory.example.gov`
- `http://localhost/asset-inventory/server/public`

All payloads are JSON.

## Register Agent

`POST /api/agent/register`

Headers:

- `X-Registration-Key: <server registration key>`
- `Content-Type: application/json`

Payload:

```json
{
  "computerName": "PC-001",
  "serialNumber": "ABC123",
  "agentVersion": "1.0.0"
}
```

Response:

```json
{
  "token": "agent bearer token",
  "intervalMinutes": 60,
  "heartbeatSeconds": 30,
  "changeScanSeconds": 60
}
```

Store the returned token securely on the endpoint. Future requests use:

`Authorization: Bearer <token>`

## Upload Inventory

`POST /api/agent/inventory`

Payload shape:

```json
{
  "agentVersion": "1.0.0",
  "computerName": "PC-001",
  "manufacturer": "Dell Inc.",
  "model": "Latitude 5440",
  "serialNumber": "ABC123",
  "domainWorkgroup": "CONTOSO",
  "os": {
    "name": "Windows 11 Pro",
    "version": "Microsoft Windows NT 10.0.26100.0",
    "buildNumber": "26100"
  },
  "bios": {
    "manufacturer": "Dell Inc.",
    "version": "1.8.0",
    "releaseDate": "2026-01-15",
    "updateDate": "2026-01-15"
  },
  "hardware": {
    "cpuName": "Intel Core i7",
    "ramBytes": 17179869184,
    "motherboard": "Dell 0ABC",
    "disks": [
      { "name": "C:\\", "totalBytes": 512000000000, "freeBytes": 200000000000 }
    ]
  },
  "network": [
    {
      "adapterName": "Ethernet",
      "adapterType": "LAN",
      "macAddress": "00:11:22:33:44:55",
      "hostname": "PC-001",
      "ipAddresses": ["192.168.1.20"]
    }
  ],
  "software": [
    {
      "name": "Microsoft Office",
      "version": "16.0",
      "publisher": "Microsoft Corporation",
      "installedDate": "2025-10-01",
      "lastUpdatedDate": "2026-04-12"
    }
  ],
  "drivers": [
    {
      "deviceName": "Intel(R) Wi-Fi 6E AX211 160MHz",
      "version": "23.120.0.3",
      "provider": "Intel",
      "manufacturer": "Intel Corporation",
      "driverDate": "2026-02-06",
      "installedDate": "2025-09-12",
      "lastUpdatedDate": "2026-04-17",
      "infName": "oem42.inf",
      "deviceClass": "NET"
    }
  ]
}
```

Response:

```json
{ "ok": true }
```

## Heartbeat

`POST /api/agent/heartbeat`

Payload:

```json
{
  "status": "online",
  "computerName": "PC-001",
  "agentVersion": "1.0.0",
  "observedAtUtc": "2026-05-18T09:00:00Z"
}
```

Agents should send heartbeats continuously while the computer is powered on and connected. The server marks a device offline when `last_checkin_at` is older than the configured timeout.

## Inventory Change Events

Inventory uploads may include idempotency headers:

- `X-Agent-Event-Id: inventory-<sha256>`
- `X-Agent-Observed-At: <ISO-8601 UTC timestamp>`

The server records processed event IDs in `agent_events` and ignores duplicate uploads with the same event ID.

## Agent Logs

`POST /api/agent/logs`

Payload:

```json
{
  "logs": [
    { "level": "info", "message": "Inventory completed", "context": {} }
  ]
}
```

## Errors

The API returns standard HTTP codes:

- `401` unauthorized token or registration key
- `422` validation error
- `500` server error
