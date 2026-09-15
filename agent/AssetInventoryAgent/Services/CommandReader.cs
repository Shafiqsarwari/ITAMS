using System.Diagnostics;

namespace AssetInventoryAgent.Services;

public static class CommandReader
{
    private static readonly TimeSpan CommandTimeout = TimeSpan.FromSeconds(45);

    public static async Task<string> PowerShellAsync(string command)
    {
        var start = new ProcessStartInfo
        {
            FileName = "powershell.exe",
            Arguments = $"-NoProfile -ExecutionPolicy Bypass -Command \"{command.Replace("\"", "\\\"")}\"",
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true
        };

        using var process = Process.Start(start);
        if (process == null) return string.Empty;

        var outputTask = process.StandardOutput.ReadToEndAsync();
        var errorTask = process.StandardError.ReadToEndAsync();
        using var timeout = new CancellationTokenSource(CommandTimeout);
        try
        {
            await process.WaitForExitAsync(timeout.Token);
        }
        catch (OperationCanceledException)
        {
            try
            {
                process.Kill(entireProcessTree: true);
            }
            catch (Exception ex)
            {
                LocalLog.Error("Timed out PowerShell inventory command could not be killed", ex);
            }

            LocalLog.Error($"PowerShell inventory command timed out after {CommandTimeout.TotalSeconds:n0} seconds: {command}");
            return string.Empty;
        }

        var output = await outputTask;
        var error = await errorTask;
        if (process.ExitCode != 0 && !string.IsNullOrWhiteSpace(error))
        {
            LocalLog.Error($"PowerShell inventory command failed with exit code {process.ExitCode}: {error.Trim()}");
        }

        return output.Trim();
    }
}
