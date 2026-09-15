using System.Net;
using System.Net.Sockets;
using System.Text.Json;
using AssetInventoryAgent.Services;

namespace AssetInventoryAgent.Models;

public sealed record AgentConfig(
    string ServerAddress,
    int ServerPort,
    string RegistrationKey,
    string? AgentToken,
    int IntervalMinutes,
    int HeartbeatSeconds = 30,
    int ChangeScanSeconds = 60)
{
    public static string DefaultPath =>
        Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
            "AssetInventoryAgent",
            "config.json");

    public static string DataDirectory =>
        Path.GetDirectoryName(DefaultPath)!;

    public string BaseUrl
    {
        get
        {
            var hasScheme = ServerAddress.StartsWith("http://", StringComparison.OrdinalIgnoreCase)
                || ServerAddress.StartsWith("https://", StringComparison.OrdinalIgnoreCase);
            var raw = hasScheme
                ? ServerAddress.TrimEnd('/')
                : $"{(ServerPort == 80 ? "http" : "https")}://{ServerAddress.TrimEnd('/')}";
            var builder = new UriBuilder(raw) { Port = ServerPort };
            return builder.Uri.ToString().TrimEnd('/');
        }
    }

    public static AgentConfig Load(string path)
    {
        if (!File.Exists(path))
        {
            var legacyPath = Path.Combine(AppContext.BaseDirectory, "config.json");
            if (File.Exists(legacyPath))
            {
                return Normalize(JsonSerializer.Deserialize<AgentConfig>(File.ReadAllText(legacyPath), JsonDefaults.Options));
            }

            return Defaults();
        }

        string json;
        try
        {
            json = File.ReadAllText(path);
        }
        catch (UnauthorizedAccessException) when (path.Equals(DefaultPath, StringComparison.OrdinalIgnoreCase))
        {
            var legacyPath = Path.Combine(AppContext.BaseDirectory, "config.json");
            if (!File.Exists(legacyPath))
            {
                throw;
            }

            json = File.ReadAllText(legacyPath);
        }
        return Normalize(JsonSerializer.Deserialize<AgentConfig>(json, JsonDefaults.Options));
    }

    public static void Save(AgentConfig config, string path)
    {
        Directory.CreateDirectory(Path.GetDirectoryName(path)!);
        var json = JsonSerializer.Serialize(Normalize(config), JsonDefaults.Options);
        try
        {
            File.WriteAllText(path, json);
        }
        catch (UnauthorizedAccessException) when (path.Equals(DefaultPath, StringComparison.OrdinalIgnoreCase))
        {
            LocalLog.Error($"Could not write primary config path; writing install-folder fallback instead: {path}");
        }

        if (path.Equals(DefaultPath, StringComparison.OrdinalIgnoreCase))
        {
            var installConfigPath = Path.Combine(AppContext.BaseDirectory, "config.json");
            if (!installConfigPath.Equals(path, StringComparison.OrdinalIgnoreCase))
            {
                File.WriteAllText(installConfigPath, json);
            }
        }
    }

    private static AgentConfig Defaults() => new("", 0, "", null, 60, 30, 60);

    private static bool IsLocalHttpHost(string host)
    {
        if (host.Equals("localhost", StringComparison.OrdinalIgnoreCase)
            || host.Equals("127.0.0.1", StringComparison.OrdinalIgnoreCase)
            || host.Equals("::1", StringComparison.OrdinalIgnoreCase))
        {
            return true;
        }

        if (!IPAddress.TryParse(host, out var address))
        {
            return false;
        }

        if (IPAddress.IsLoopback(address))
        {
            return true;
        }

        var bytes = address.GetAddressBytes();
        if (address.AddressFamily == AddressFamily.InterNetwork)
        {
            return bytes[0] == 10
                || bytes[0] == 192 && bytes[1] == 168
                || bytes[0] == 172 && bytes[1] >= 16 && bytes[1] <= 31;
        }

        return address.AddressFamily == AddressFamily.InterNetworkV6
            && (bytes[0] & 0xfe) == 0xfc;
    }

    private static AgentConfig Normalize(AgentConfig? config)
    {
        config ??= Defaults();
        if (string.IsNullOrWhiteSpace(config.ServerAddress))
        {
            throw new InvalidOperationException("Agent server address is not configured.");
        }

        if (config.ServerPort is < 1 or > 65535)
        {
            throw new InvalidOperationException("Agent server port is not configured.");
        }

        if (string.IsNullOrWhiteSpace(config.RegistrationKey))
        {
            throw new InvalidOperationException("Agent registration key is not configured.");
        }

        return config with
        {
            ServerAddress = config.ServerAddress.Trim(),
            RegistrationKey = config.RegistrationKey.Trim(),
            IntervalMinutes = config.IntervalMinutes > 0 ? config.IntervalMinutes : 60,
            HeartbeatSeconds = config.HeartbeatSeconds >= 10 ? config.HeartbeatSeconds : 30,
            ChangeScanSeconds = config.ChangeScanSeconds >= 30 ? config.ChangeScanSeconds : 60
        };
    }
}
