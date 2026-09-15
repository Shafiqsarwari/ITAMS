namespace AssetInventoryAgent.Services;

public static class LocalLog
{
    private static readonly string LogPath = Path.Combine(AssetInventoryAgent.Models.AgentConfig.DataDirectory, "agent.log");

    public static void Info(string message) => Write("INFO", message);
    public static void Error(string message, Exception? exception = null) => Write("ERROR", exception == null ? message : $"{message}: {exception}");

    private static void Write(string level, string message)
    {
        var line = $"{DateTimeOffset.Now:u} [{level}] {message}{Environment.NewLine}";
        try
        {
            Directory.CreateDirectory(Path.GetDirectoryName(LogPath)!);
            File.AppendAllText(LogPath, line);
        }
        catch
        {
            var fallback = Path.Combine(Path.GetTempPath(), "EndpointHealthAgent.log");
            File.AppendAllText(fallback, line);
        }
    }
}
