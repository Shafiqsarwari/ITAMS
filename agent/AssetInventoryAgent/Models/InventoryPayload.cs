namespace AssetInventoryAgent.Models;

public sealed class InventoryPayload
{
    public string AgentVersion { get; set; } = "1.0.0";
    public string ComputerName { get; set; } = Environment.MachineName;
    public string? Manufacturer { get; set; }
    public string? Model { get; set; }
    public string? SerialNumber { get; set; }
    public string? DomainWorkgroup { get; set; }
    public OsInfo Os { get; set; } = new();
    public BiosInfo Bios { get; set; } = new();
    public HardwareInfo Hardware { get; set; } = new();
    public List<NetworkInfo> Network { get; set; } = [];
    public List<SoftwareInfo> Software { get; set; } = [];
    public List<DriverInfo> Drivers { get; set; } = [];
}

public sealed class OsInfo
{
    public string? Name { get; set; }
    public string? Version { get; set; }
    public string? BuildNumber { get; set; }
}

public sealed class BiosInfo
{
    public string? Manufacturer { get; set; }
    public string? Version { get; set; }
    public string? ReleaseDate { get; set; }
    public string? UpdateDate { get; set; }
}

public sealed class HardwareInfo
{
    public string? CpuName { get; set; }
    public int? BatteryHealthPercent { get; set; }
    public ulong? RamBytes { get; set; }
    public string? Motherboard { get; set; }
    public List<DiskInfo> Disks { get; set; } = [];
}

public sealed class DiskInfo
{
    public string? Name { get; set; }
    public long TotalBytes { get; set; }
    public long FreeBytes { get; set; }
    public string Source { get; set; } = "internal";
    public string? BusType { get; set; }
}

public sealed class NetworkInfo
{
    public string? AdapterName { get; set; }
    public string AdapterType { get; set; } = "Other";
    public string? MacAddress { get; set; }
    public string Hostname { get; set; } = Environment.MachineName;
    public List<string> IpAddresses { get; set; } = [];
}

public sealed class SoftwareInfo
{
    public string? Name { get; set; }
    public string? Version { get; set; }
    public string? InstalledDate { get; set; }
    public string? LastUpdatedDate { get; set; }
    public string? Publisher { get; set; }
}

public sealed class DriverInfo
{
    public string? DeviceName { get; set; }
    public string? Version { get; set; }
    public string? Provider { get; set; }
    public string? Manufacturer { get; set; }
    public string? DriverDate { get; set; }
    public string? InstalledDate { get; set; }
    public string? LastUpdatedDate { get; set; }
    public string? InfName { get; set; }
    public string? DeviceClass { get; set; }
}
