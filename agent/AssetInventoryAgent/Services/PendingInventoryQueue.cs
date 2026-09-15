using System.Text.Json;
using AssetInventoryAgent.Models;

namespace AssetInventoryAgent.Services;

public sealed class PendingInventoryQueue
{
    private readonly string _path = Path.Combine(AgentConfig.DataDirectory, "pending-inventory.json");
    private readonly string _fallbackPath = Path.Combine(AppContext.BaseDirectory, "pending-inventory.json");
    private readonly SemaphoreSlim _sync = new(1, 1);

    public IReadOnlyList<PendingInventoryUpdate> Load()
    {
        _sync.Wait();
        try
        {
            return LoadCore();
        }
        finally
        {
            _sync.Release();
        }
    }

    public void Enqueue(PendingInventoryUpdate update)
    {
        _sync.Wait();
        try
        {
            var updates = LoadCore().ToList();
            if (updates.Any(item => item.EventId == update.EventId))
            {
                return;
            }

            updates.Add(update);
            SaveCore(updates);
        }
        finally
        {
            _sync.Release();
        }
    }

    public async Task FlushAsync(Func<PendingInventoryUpdate, Task> sender)
    {
        await _sync.WaitAsync();
        try
        {
            var updates = LoadCore().ToList();
            if (updates.Count == 0)
            {
                return;
            }

            var remaining = new List<PendingInventoryUpdate>();
            foreach (var update in updates)
            {
                try
                {
                    await sender(update);
                }
                catch (Exception ex)
                {
                    LocalLog.Error($"Pending inventory update {update.EventId} could not be sent", ex);
                    remaining.Add(update);
                }
            }

            SaveCore(remaining);
        }
        finally
        {
            _sync.Release();
        }
    }

    private IReadOnlyList<PendingInventoryUpdate> LoadCore()
    {
        try
        {
            if (!File.Exists(_path))
            {
                return LoadFallbackCore();
            }

            return JsonSerializer.Deserialize<List<PendingInventoryUpdate>>(File.ReadAllText(_path), JsonDefaults.Options) ?? [];
        }
        catch (UnauthorizedAccessException)
        {
            return LoadFallbackCore();
        }
        catch (Exception ex)
        {
            LocalLog.Error("Pending inventory queue could not be loaded", ex);
            return [];
        }
    }

    private void SaveCore(IReadOnlyList<PendingInventoryUpdate> updates)
    {
        var json = JsonSerializer.Serialize(updates, JsonDefaults.Options);
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

    private IReadOnlyList<PendingInventoryUpdate> LoadFallbackCore()
    {
        try
        {
            if (!File.Exists(_fallbackPath))
            {
                return [];
            }

            return JsonSerializer.Deserialize<List<PendingInventoryUpdate>>(File.ReadAllText(_fallbackPath), JsonDefaults.Options) ?? [];
        }
        catch (Exception ex)
        {
            LocalLog.Error("Fallback pending inventory queue could not be loaded", ex);
            return [];
        }
    }
}
