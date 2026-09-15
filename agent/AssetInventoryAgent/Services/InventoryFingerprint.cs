using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using AssetInventoryAgent.Models;

namespace AssetInventoryAgent.Services;

public static class InventoryFingerprint
{
    public static string Hash(InventoryPayload payload)
    {
        var normalized = new
        {
            payload.ComputerName,
            payload.Manufacturer,
            payload.Model,
            payload.SerialNumber,
            payload.DomainWorkgroup,
            payload.Os,
            payload.Bios,
            payload.Hardware,
            Network = payload.Network
                .OrderBy(item => item.MacAddress)
                .ThenBy(item => item.AdapterName)
                .ToList(),
            Software = payload.Software
                .OrderBy(item => item.Name)
                .ThenBy(item => item.Version)
                .ThenBy(item => item.Publisher)
                .ToList(),
            Drivers = payload.Drivers
                .OrderBy(item => item.DeviceName)
                .ThenBy(item => item.Version)
                .ThenBy(item => item.InfName)
                .ToList()
        };
        var json = JsonSerializer.Serialize(normalized, JsonDefaults.Options);
        return Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(json))).ToLowerInvariant();
    }
}
