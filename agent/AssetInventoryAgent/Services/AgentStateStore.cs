using System.Text.Json;
using AssetInventoryAgent.Models;

namespace AssetInventoryAgent.Services;

public sealed class AgentStateStore
{
    private readonly string _path = Path.Combine(AgentConfig.DataDirectory, "state.json");
    private readonly string _fallbackPath = Path.Combine(AppContext.BaseDirectory, "state.json");
    private readonly object _sync = new();

    public AgentState Load()
    {
        lock (_sync)
        {
            return LoadCore();
        }
    }

    public void Save(AgentState state)
    {
        lock (_sync)
        {
            SaveCore(state);
        }
    }

    public void Update(Action<AgentState> update)
    {
        lock (_sync)
        {
            var state = LoadCore();
            update(state);
            SaveCore(state);
        }
    }

    private AgentState LoadCore()
    {
        try
        {
            if (!File.Exists(_path))
            {
                return LoadFallbackCore();
            }

            return JsonSerializer.Deserialize<AgentState>(File.ReadAllText(_path), JsonDefaults.Options) ?? new AgentState();
        }
        catch (UnauthorizedAccessException)
        {
            return LoadFallbackCore();
        }
        catch (Exception ex)
        {
            LocalLog.Error("Agent state could not be loaded", ex);
            return new AgentState();
        }
    }

    private void SaveCore(AgentState state)
    {
        var json = JsonSerializer.Serialize(state, JsonDefaults.Options);
        try
        {
            Directory.CreateDirectory(Path.GetDirectoryName(_path)!);
            File.WriteAllText(_path, json);
        }
        catch (UnauthorizedAccessException)
        {
            Directory.CreateDirectory(Path.GetDirectoryName(_fallbackPath)!);
            File.WriteAllText(_fallbackPath, json);
        }
    }

    private AgentState LoadFallbackCore()
    {
        try
        {
            if (!File.Exists(_fallbackPath))
            {
                return new AgentState();
            }

            return JsonSerializer.Deserialize<AgentState>(File.ReadAllText(_fallbackPath), JsonDefaults.Options) ?? new AgentState();
        }
        catch (Exception ex)
        {
            LocalLog.Error("Fallback agent state could not be loaded", ex);
            return new AgentState();
        }
    }
}
