using AssetInventoryAgent.Models;
using AssetInventoryAgent.Services;

var configPath = AgentConfig.DefaultPath;
var once = args.Contains("--once", StringComparer.OrdinalIgnoreCase);
var service = args.Contains("--service", StringComparer.OrdinalIgnoreCase);
var markOffline = args.Contains("--mark-offline", StringComparer.OrdinalIgnoreCase);
var configureIndex = Array.FindIndex(args, a => a.Equals("--configure", StringComparison.OrdinalIgnoreCase));

if (configureIndex >= 0)
{
    var server = GetArg(args, "--server")?.Trim();
    if (string.IsNullOrWhiteSpace(server))
    {
        FailConfigure("Missing required --server value.");
        return;
    }

    if (!int.TryParse(GetArg(args, "--port"), out var port) || port is < 1 or > 65535)
    {
        FailConfigure("Missing or invalid required --port value.");
        return;
    }

    var key = GetArg(args, "--registration-key")?.Trim();
    if (string.IsNullOrWhiteSpace(key))
    {
        FailConfigure("Missing required --registration-key value.");
        return;
    }

    var minutes = int.TryParse(GetArg(args, "--interval"), out var parsedInterval) ? parsedInterval : 60;
    var heartbeatSeconds = int.TryParse(GetArg(args, "--heartbeat-seconds"), out var parsedHeartbeat) ? parsedHeartbeat : 30;
    var changeScanSeconds = int.TryParse(GetArg(args, "--change-scan-seconds"), out var parsedScan) ? parsedScan : 60;
    AgentConfig.Save(new AgentConfig(server, port, key, null, minutes, heartbeatSeconds, changeScanSeconds), configPath);
    LocalLog.Info("Configuration saved.");
    return;
}

var runner = new AgentRunner(configPath);
if (service)
{
    WindowsServiceHost.Run(configPath);
    return;
}

if (markOffline)
{
    await runner.MarkOfflineAsync(GetArg(args, "--reason") ?? "agent_stopped");
    return;
}

if (once)
{
    await runner.RunOnceAsync();
    return;
}

using var singleInstance = new Mutex(false, @"Global\EndpointHealthAgent");
if (!singleInstance.WaitOne(0))
{
    LocalLog.Info("Another agent instance is already running.");
    return;
}

try
{
    await runner.RunContinuousAsync();
}
finally
{
    singleInstance.ReleaseMutex();
}

static string? GetArg(string[] args, string name)
{
    var index = Array.FindIndex(args, a => a.Equals(name, StringComparison.OrdinalIgnoreCase));
    return index >= 0 && index + 1 < args.Length ? args[index + 1] : null;
}

static void FailConfigure(string message)
{
    Console.Error.WriteLine(message);
    LocalLog.Error(message);
    Environment.ExitCode = 1;
}
