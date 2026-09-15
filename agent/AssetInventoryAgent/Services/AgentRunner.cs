using AssetInventoryAgent.Models;
using System.Net;

namespace AssetInventoryAgent.Services;

public sealed class AgentRunner(string configPath)
{
    private readonly InventoryCollector _collector = new();
    private readonly AgentApiClient _api = new();
    private readonly PendingInventoryQueue _queue = new();
    private readonly AgentStateStore _stateStore = new();
    private readonly SemaphoreSlim _inventoryScanLock = new(1, 1);

    public async Task RunOnceAsync()
    {
        try
        {
            var config = await EnsureRegisteredAsync(AgentConfig.Load(configPath));
            await SendHeartbeatAsync(config);
            await FlushQueueAsync(config);
            await ScanAndSendInventoryAsync(config, forceUpload: true);
        }
        catch (Exception ex)
        {
            LocalLog.Error("Inventory run failed", ex);
        }
    }

    public async Task RunContinuousAsync(CancellationToken cancellationToken = default)
    {
        LocalLog.Info($"Agent started in continuous mode for {Environment.MachineName}.");
        var nextInventoryScan = DateTimeOffset.MinValue;
        var nextFullInventoryUpload = DateTimeOffset.MinValue;
        var startupInventoryCompleted = false;

        while (!cancellationToken.IsCancellationRequested)
        {
            var config = AgentConfig.Load(configPath);
            try
            {
                config = await EnsureRegisteredAsync(config);
                var heartbeat = await SendHeartbeatAsync(config);
                await FlushQueueAsync(config);

                var now = DateTimeOffset.UtcNow;
                if (!startupInventoryCompleted)
                {
                    await ScanAndSendInventoryAsync(config, forceUpload: true);
                    startupInventoryCompleted = true;
                    nextFullInventoryUpload = now.AddMinutes(Math.Max(config.IntervalMinutes, 5));
                    nextInventoryScan = now.AddSeconds(config.ChangeScanSeconds);
                }
                else if (heartbeat.SyncInventory)
                {
                    LocalLog.Info("Server requested inventory sync; uploading full inventory reconciliation.");
                    await ScanAndSendInventoryAsync(config, forceUpload: true);
                    nextFullInventoryUpload = now.AddMinutes(Math.Max(config.IntervalMinutes, 5));
                    nextInventoryScan = now.AddSeconds(config.ChangeScanSeconds);
                }
                else if (now >= nextFullInventoryUpload)
                {
                    _ = Task.Run(() => TryScanAndSendInventoryAsync(config, forceUpload: true), CancellationToken.None);
                    nextFullInventoryUpload = now.AddMinutes(Math.Max(config.IntervalMinutes, 5));
                    nextInventoryScan = now.AddSeconds(config.ChangeScanSeconds);
                }
                else if (now >= nextInventoryScan)
                {
                    _ = Task.Run(() => TryScanAndSendInventoryAsync(config, forceUpload: false), CancellationToken.None);
                    nextInventoryScan = now.AddSeconds(config.ChangeScanSeconds);
                }
            }
            catch (Exception ex)
            {
                LocalLog.Error("Agent communication cycle failed; retrying automatically", ex);
                await TryUploadErrorLogAsync(AgentConfig.Load(configPath), "Agent communication cycle failed; retrying automatically", ex);
            }

            config = AgentConfig.Load(configPath);
            await Task.Delay(TimeSpan.FromSeconds(Math.Max(config.HeartbeatSeconds, 10)), cancellationToken);
        }
    }

    public async Task MarkOfflineAsync(string reason)
    {
        var config = AgentConfig.Load(configPath);
        if (string.IsNullOrWhiteSpace(config.AgentToken))
        {
            LocalLog.Info("Cannot mark offline because no agent token is available.");
            return;
        }

        try
        {
            await _api.MarkOfflineAsync(config, reason);
            LocalLog.Info($"Agent marked offline: {reason}");
        }
        catch (Exception ex)
        {
            LocalLog.Error("Agent could not notify server that it is offline", ex);
        }
    }

    private async Task<AgentConfig> EnsureRegisteredAsync(AgentConfig config)
    {
        if (!string.IsNullOrWhiteSpace(config.AgentToken))
        {
            return config;
        }

        LocalLog.Info($"Registering agent for {Environment.MachineName}.");
        var payload = await _collector.CollectAsync();
        config = await _api.EnsureRegisteredAsync(config, payload);
        AgentConfig.Save(config, configPath);
        await UploadCollectedInventoryAsync(config, payload, forceUpload: true);
        return config;
    }

    private async Task<AgentApiClient.HeartbeatResponse> SendHeartbeatAsync(AgentConfig config)
    {
        AgentApiClient.HeartbeatResponse response;
        try
        {
            response = await _api.HeartbeatAsync(config);
        }
        catch (HttpRequestException ex) when (ex.StatusCode == HttpStatusCode.Unauthorized)
        {
            AgentConfig.Save(config with { AgentToken = null }, configPath);
            LocalLog.Error("Agent token was rejected by the server; registration will be retried", ex);
            throw;
        }

        _stateStore.Update(state => state.LastSuccessfulHeartbeatUtc = DateTimeOffset.UtcNow);
        return response;
    }

    private async Task FlushQueueAsync(AgentConfig config)
    {
        await _queue.FlushAsync(async update =>
        {
            await _api.UploadInventoryAsync(config, update.Payload, update.EventId, update.ObservedAtUtc);
            _stateStore.Update(state => state.LastSuccessfulInventoryUploadUtc = DateTimeOffset.UtcNow);
        });
    }

    private async Task TryScanAndSendInventoryAsync(AgentConfig config, bool forceUpload)
    {
        if (!_inventoryScanLock.Wait(0))
        {
            LocalLog.Info("Inventory scan skipped because a previous scan is still running.");
            return;
        }

        try
        {
            await ScanAndSendInventoryAsync(config, forceUpload);
        }
        catch (Exception ex)
        {
            LocalLog.Error("Background inventory scan failed", ex);
        }
        finally
        {
            _inventoryScanLock.Release();
        }
    }

    private async Task ScanAndSendInventoryAsync(AgentConfig config, bool forceUpload = false)
    {
        LocalLog.Info($"Scanning inventory for {Environment.MachineName}.");
        var payload = await _collector.CollectAsync();
        var hash = InventoryFingerprint.Hash(payload);
        var state = _stateStore.Load();

        if (!forceUpload && state.LastObservedInventoryHash == hash)
        {
            return;
        }

        var update = new PendingInventoryUpdate
        {
            EventId = forceUpload ? $"inventory-reconcile-{Guid.NewGuid():N}" : $"inventory-{hash}",
            ObservedAtUtc = DateTimeOffset.UtcNow,
            Payload = payload
        };

        try
        {
            await UploadInventoryUpdateAsync(config, update, hash, forceUpload);
            LocalLog.Info(forceUpload ? "Full inventory reconciliation uploaded successfully." : "Inventory change uploaded successfully.");
        }
        catch (Exception ex)
        {
            _queue.Enqueue(update);
            LocalLog.Error("Inventory change queued because server upload failed", ex);
            await TryUploadErrorLogAsync(config, "Inventory upload failed and was queued", ex);
        }

        _stateStore.Update(current => current.LastObservedInventoryHash = hash);
    }

    private async Task UploadCollectedInventoryAsync(AgentConfig config, InventoryPayload payload, bool forceUpload)
    {
        var hash = InventoryFingerprint.Hash(payload);
        var update = new PendingInventoryUpdate
        {
            EventId = forceUpload ? $"inventory-reconcile-{Guid.NewGuid():N}" : $"inventory-{hash}",
            ObservedAtUtc = DateTimeOffset.UtcNow,
            Payload = payload
        };

        try
        {
            await UploadInventoryUpdateAsync(config, update, hash, forceUpload);
            LocalLog.Info("Initial inventory uploaded immediately after registration.");
        }
        catch (Exception ex)
        {
            _queue.Enqueue(update);
            LocalLog.Error("Initial inventory upload failed and was queued", ex);
            await TryUploadErrorLogAsync(config, "Initial inventory upload failed and was queued", ex);
        }
    }

    private async Task UploadInventoryUpdateAsync(AgentConfig config, PendingInventoryUpdate update, string hash, bool forceUpload)
    {
        await _api.UploadInventoryAsync(config, update.Payload, forceUpload ? null : update.EventId, update.ObservedAtUtc);
        var uploadedAtUtc = DateTimeOffset.UtcNow;
        _stateStore.Update(current =>
        {
            current.LastSuccessfulInventoryUploadUtc = uploadedAtUtc;
            current.LastObservedInventoryHash = hash;
        });
    }

    private async Task TryUploadErrorLogAsync(AgentConfig config, string message, Exception exception)
    {
        if (string.IsNullOrWhiteSpace(config.AgentToken))
        {
            return;
        }

        try
        {
            await _api.UploadLogsAsync(config, new[]
            {
                new
                {
                    level = "error",
                    message,
                    context = new
                    {
                        exception = exception.ToString(),
                        machine = Environment.MachineName,
                        observedAtUtc = DateTimeOffset.UtcNow
                    }
                }
            });
        }
        catch
        {
            // Local logging already captured the original error; avoid recursive log upload failures.
        }
    }
}
