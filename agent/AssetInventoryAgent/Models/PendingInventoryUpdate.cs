namespace AssetInventoryAgent.Models;

public sealed class PendingInventoryUpdate
{
    public string EventId { get; set; } = string.Empty;
    public string ChangeType { get; set; } = "inventory.changed";
    public DateTimeOffset ObservedAtUtc { get; set; } = DateTimeOffset.UtcNow;
    public InventoryPayload Payload { get; set; } = new();
}

public sealed class AgentState
{
    public string? LastObservedInventoryHash { get; set; }
    public DateTimeOffset? LastSuccessfulHeartbeatUtc { get; set; }
    public DateTimeOffset? LastSuccessfulInventoryUploadUtc { get; set; }
}
