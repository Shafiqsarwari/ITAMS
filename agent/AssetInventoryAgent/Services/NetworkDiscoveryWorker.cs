using System.Net;
using System.Text.Json;
using System.Text.RegularExpressions;
using AssetInventoryAgent.Models;
using Lextm.SharpSnmpLib;
using Lextm.SharpSnmpLib.Messaging;

namespace AssetInventoryAgent.Services;

// Opt-in, bounded, read-only polling. No community strings are sent to ITAMS.
public sealed class NetworkDiscoveryWorker
{
    private int _running;
    private DateTimeOffset _nextRun;
    private DateTimeOffset _nextFullScan;
    private string[] _managedAddresses = [];
    private string[] _syncAddresses = [];
    private HashSet<string> _reportedAddresses = [];
    public void Tick(AgentConfig agent, CancellationToken stop)
    {
        if (DateTimeOffset.UtcNow < _nextRun || Interlocked.CompareExchange(ref _running, 1, 0) != 0) return;
        _ = Task.Run(async () =>
        {
            _nextRun = DateTimeOffset.UtcNow.AddMinutes(1);
            try
            {
                if (!agent.CollectorComputer)
                {
                    _managedAddresses = [];
                    _syncAddresses = [];
                    _reportedAddresses.Clear();
                    return;
                }

                var path = Path.Combine(AgentConfig.DataDirectory, "network-discovery.json");
                var config = File.Exists(path)
                    ? JsonSerializer.Deserialize<DiscoverySettings>(File.ReadAllText(path), JsonDefaults.Options)
                    : null;
                // Selecting collector mode is sufficient for centrally managed devices.
                // The optional local file only supplies custom targets/community settings.
                config ??= new DiscoverySettings(Enabled: true);
                if (config.Targets.Length > 64) throw new InvalidOperationException("At most 64 discovery targets are allowed.");
                try {
                    var managedTargets = await new AgentApiClient().GetNetworkPrinterTargetsAsync(agent, stop);
                    _managedAddresses = managedTargets.Addresses;
                    _syncAddresses = managedTargets.SyncAddresses;
                } catch (OperationCanceledException) when (stop.IsCancellationRequested) { throw; }
                catch (Exception ex) {
                    // An unavailable managed-list endpoint must not stop existing local monitoring.
                    LocalLog.Info("Managed network device list unavailable; retaining previous targets: " + ex.GetType().Name);
                }
                var targets = MergeTargets(config.Targets, _managedAddresses, config.ManagedPrinterCommunity);
                if (config.Targets.Length + _managedAddresses.Except(config.Targets.Select(t => t.Address)).Count() > 64)
                    LocalLog.Info("Network discovery target limit reached; only the first 64 addresses can be monitored.");
                foreach (var target in targets)
                {
                    stop.ThrowIfCancellationRequested();
                    if (!IPAddress.TryParse(target.Address, out var ip) || !IsPrivate(ip))
                        throw new InvalidOperationException("Discovery targets must be private IPv4 addresses.");
                    if (string.IsNullOrWhiteSpace(target.Community) || target.Community.Length > 128)
                        throw new InvalidOperationException("A read-only community is required for each target.");
                }
                var devices = new List<object>();
                var fullScan = DateTimeOffset.UtcNow >= _nextFullScan;
                var requested = _syncAddresses.ToHashSet(StringComparer.Ordinal);
                var detailedAddresses = targets
                    .Where(t => fullScan || !_reportedAddresses.Contains(t.Address) || requested.Contains(t.Address))
                    .Select(t => t.Address)
                    .ToHashSet(StringComparer.Ordinal);
                // Probe every managed target each minute so online/offline state follows
                // network reachability without requiring a manual Sync. Detailed SNMP
                // inventory remains bounded by the configured interval or an explicit Sync.
                foreach (var target in targets)
                {
                    stop.ThrowIfCancellationRequested();
                    devices.Add(detailedAddresses.Contains(target.Address)
                        ? ReadDevice(target, stop)
                        : ProbeDevice(target, stop));
                }
                if (devices.Count > 0) await new AgentApiClient().UploadNetworkDevicesAsync(agent, devices, stop);
                if (devices.Count > 0) LocalLog.Info($"Network discovery reported {devices.Count} configured devices.");
                _reportedAddresses.IntersectWith(targets.Select(t => t.Address));
                _reportedAddresses.UnionWith(detailedAddresses);
                if (fullScan) _nextFullScan = DateTimeOffset.UtcNow.AddMinutes(Math.Clamp(config.IntervalMinutes, 5, 1440));
            }
            catch (OperationCanceledException) { }
            catch (Exception ex) { LocalLog.Error("Network discovery failed; computer inventory continues", new Exception(ex.GetType().Name)); }
            finally { Volatile.Write(ref _running, 0); }
        }, CancellationToken.None);
    }

    internal static DiscoveryTarget[] MergeTargets(DiscoveryTarget[] local, string[] managed, string community)
    {
        var result = local.ToDictionary(t => t.Address, StringComparer.Ordinal);
        foreach (var address in managed) {
            if (!IPAddress.TryParse(address, out var ip) || !IsPrivate(ip)) throw new InvalidOperationException("Managed network device address is not a private IPv4 address.");
            if (result.Count >= 64) break;
            result.TryAdd(address, new DiscoveryTarget(address, community, "network"));
        }
        return result.Values.Take(64).ToArray();
    }

    private static bool IsPrivate(IPAddress ip)
    {
        var b = ip.GetAddressBytes();
        return b.Length == 4 && (b[0] == 10 || b[0] == 192 && b[1] == 168 || b[0] == 172 && b[1] >= 16 && b[1] <= 31);
    }

    private static object ReadDevice(DiscoveryTarget target, CancellationToken stop, int port = 161)
    {
        var end = new IPEndPoint(IPAddress.Parse(target.Address), port);
        var community = new OctetString(target.Community);
        var deadline = DateTimeOffset.UtcNow.AddSeconds(30);
        string Get(string oid, bool hpText = false)
        {
            stop.ThrowIfCancellationRequested();
            if (DateTimeOffset.UtcNow >= deadline) return "";
            try
            {
                var data = Messenger.Get(VersionCode.V2, end, community, new List<Variable> { new(new ObjectIdentifier(oid)) }, 1000)[0].Data;
                if (data.TypeCode is SnmpType.NoSuchInstance or SnmpType.NoSuchObject or SnmpType.EndOfMibView) return "";
                var value = data.ToString() ?? "";
                // HP PML strings can start with a two-byte symbol-set marker.
                if (hpText && data is OctetString octets) {
                    var bytes = octets.GetRaw();
                    if (bytes.Length >= 2 && bytes[0] == 1 && bytes[1] == 0x15)
                        value = System.Text.Encoding.Latin1.GetString(bytes, 2, bytes.Length - 2);
                    else if (bytes.Length >= 2 && bytes[0] == 0xfd && bytes[1] == 0xe8)
                        value = System.Text.Encoding.UTF8.GetString(bytes, 2, bytes.Length - 2);
                }
                return value.Trim('\0', ' ', '\r', '\n', '\t');
            }
            catch { return ""; }
        }
        // Limited GETNEXT, never an unbounded walk.
        Dictionary<string, string> Walk(string root, int limit)
        {
            var result = new Dictionary<string, string>();
            var cursor = root;
            for (var i = 0; i < limit && DateTimeOffset.UtcNow < deadline; i++)
            {
                stop.ThrowIfCancellationRequested();
                try
                {
                    var request = new GetNextRequestMessage(Messenger.NextRequestId, VersionCode.V2, community, new List<Variable> { new(new ObjectIdentifier(cursor)) });
                    var response = request.GetResponse(1000, end);
                    if (response.Pdu().ErrorStatus.ToInt32() != 0) break;
                    var v = response.Pdu().Variables[0];
                    var oid = v.Id.ToString();
                    if (!oid.StartsWith(root + ".", StringComparison.Ordinal) || oid == cursor || v.Data.TypeCode == SnmpType.EndOfMibView) break;
                    result[oid[(root.Length + 1)..]] = v.Data.ToString() ?? "";
                    cursor = oid;
                }
                catch { break; }
            }
            return result;
        }
        var description = Get("1.3.6.1.2.1.1.1.0");
        if (description == "") return new { address = target.Address, reachable = false };
        var name = Get("1.3.6.1.2.1.1.5.0");
        var sysObjectId = Get("1.3.6.1.2.1.1.2.0");
        // Some HP JetDirect printers identify themselves only as
        // "HP ETHERNET MULTI-ENVIRONMENT". Printer-MIB serial support is a
        // stronger printer signal than the generic sysDescr text.
        var printerSerial = Get("1.3.6.1.2.1.43.5.1.1.17.1");
        var configuredKind = target.Kind.ToLowerInvariant();
        var isPrinter = configuredKind == "printer" ||
            !string.IsNullOrWhiteSpace(printerSerial) ||
            description.Contains("HP ETHERNET MULTI-ENVIRONMENT", StringComparison.OrdinalIgnoreCase) ||
            description.Contains("printer", StringComparison.OrdinalIgnoreCase) ||
            description.Contains("laserjet", StringComparison.OrdinalIgnoreCase) ||
            description.Contains("officejet", StringComparison.OrdinalIgnoreCase) ||
            description.Contains("deskjet", StringComparison.OrdinalIgnoreCase);
        var detectedKind = isPrinter ? "printer" : configuredKind == "network"
            ? (description.Contains("access point", StringComparison.OrdinalIgnoreCase) || description.Contains("wireless", StringComparison.OrdinalIgnoreCase) ? "ap"
                : description.Contains("switch", StringComparison.OrdinalIgnoreCase) || description.Contains("procurve", StringComparison.OrdinalIgnoreCase) ? "switch"
                : "network")
            : configuredKind;
        var isHp = isPrinter && (description.StartsWith("HP ", StringComparison.OrdinalIgnoreCase) ||
            description.Contains("Hewlett-Packard", StringComparison.OrdinalIgnoreCase) ||
            sysObjectId.StartsWith("1.3.6.1.4.1.11.", StringComparison.Ordinal));
        string? model = null;
        string? firmware = null;
        string? hpDatecode = null;
        if (isHp) {
            model = NullIfEmpty(Get("1.3.6.1.4.1.11.2.3.9.4.2.1.1.3.2.0", true));
            firmware = NullIfEmpty(Get("1.3.6.1.4.1.11.2.3.9.4.2.1.1.18.7.0", true));
            // This HP PML object is the Firmware Datecode shown by the printer's
            // own configuration page. Read it independently: some models return
            // a non-empty Jenkins build path from the revision object.
            hpDatecode = NormalizeHpDatecode(Get("1.3.6.1.4.1.11.2.3.9.4.2.1.1.3.5.0", true));
            if (model == null || firmware == null || hpDatecode == null) {
                var remaining = deadline - DateTimeOffset.UtcNow;
                var identity = HpPrinterIdentity.Read(target.Address, remaining < TimeSpan.FromSeconds(3) ? remaining : TimeSpan.FromSeconds(3), stop);
                model ??= NullIfEmpty(identity?.Model);
                firmware ??= NullIfEmpty(identity?.Firmware);
                hpDatecode ??= NormalizeHpDatecode(identity?.Datecode);
            }
        }
        // Prefer the exact per-printer datecode displayed by HP. Fall back to a
        // genuine revision only when the printer does not publish a datecode.
        firmware = hpDatecode ?? (PlausibleFirmware(firmware) ? firmware : null);
        // Entity-MIB contains revisions for many internal components. Only use
        // the row identified as the printer or chassis; never take the first row.
        var entityClasses = Walk("1.3.6.1.2.1.47.1.1.1.1.5", 24);
        var deviceEntity = entityClasses.FirstOrDefault(entry => entry.Value.Trim() is "11" or "3"
            || entry.Value.EndsWith("(11)", StringComparison.Ordinal)
            || entry.Value.EndsWith("(3)", StringComparison.Ordinal));
        if (!string.IsNullOrWhiteSpace(deviceEntity.Key))
        {
            firmware ??= NullIfEmpty(Get("1.3.6.1.2.1.47.1.1.1.1.9." + deviceEntity.Key));
            model ??= NullIfEmpty(Get("1.3.6.1.2.1.47.1.1.1.1.13." + deviceEntity.Key));
        }
        firmware = PlausibleFirmware(firmware) ? firmware : null;
        firmware ??= FirmwareFromDescription(description);
        model ??= Walk("1.3.6.1.2.1.47.1.1.1.1.13", 8).Values.FirstOrDefault(v => !string.IsNullOrWhiteSpace(v));
        if (model == null && isPrinter) {
            foreach (var (index, type) in Walk("1.3.6.1.2.1.25.3.2.1.2", 8)) {
                if (type != "1.3.6.1.2.1.25.3.1.5") continue;
                model = NullIfEmpty(Get("1.3.6.1.2.1.25.3.2.1.3." + index));
                if (model != null) break;
            }
        }
        var serial = NullIfEmpty(printerSerial)
            ?? Walk("1.3.6.1.2.1.47.1.1.1.1.11", 8).Values.FirstOrDefault(v => !string.IsNullOrWhiteSpace(v));
        var supplies = new List<SupplyReading>();
        if (isPrinter)
        {
            serial ??= Walk("1.3.6.1.2.1.43.5.1.1.17", 4).Values.FirstOrDefault(v => !string.IsNullOrWhiteSpace(v));
            const string root = "1.3.6.1.2.1.43.11.1.1";
            foreach (var (index, label) in Walk(root + ".6", 16))
            {
                var levelText = Get(root + ".9." + index);
                var maxText = Get(root + ".8." + index);
                var unitText = Get(root + ".7." + index);
                var percent = SupplyPercent(levelText, maxText, unitText);
                supplies.Add(new SupplyReading(label, percent));
            }
            if (isHp && !supplies.Any(supply => supply.Percent != null))
            {
                var remaining = deadline - DateTimeOffset.UtcNow;
                var ewsSupplies = HpPrinterIdentity.ReadSupplies(target.Address,
                    remaining < TimeSpan.FromSeconds(4) ? remaining : TimeSpan.FromSeconds(4), stop);
                if (ewsSupplies.Count > 0)
                {
                    supplies.Clear();
                    supplies.AddRange(ewsSupplies.Select(supply => new SupplyReading(supply.Name, supply.Percent)));
                }
            }
        }
        return new { address = target.Address, reachable = true, kind = detectedKind, name, description, model = model ?? "", serial = serial ?? "", firmware = firmware ?? "", supplies };
    }

    private static object ProbeDevice(DiscoveryTarget target, CancellationToken stop, int port = 161)
    {
        stop.ThrowIfCancellationRequested();
        try
        {
            var end = new IPEndPoint(IPAddress.Parse(target.Address), port);
            var response = Messenger.Get(
                VersionCode.V2,
                end,
                new OctetString(target.Community),
                new List<Variable> { new(new ObjectIdentifier("1.3.6.1.2.1.1.1.0")) },
                1000);
            var data = response[0].Data;
            var reachable = data.TypeCode is not (SnmpType.NoSuchInstance or SnmpType.NoSuchObject or SnmpType.EndOfMibView);
            return new { address = target.Address, reachable };
        }
        catch
        {
            return new { address = target.Address, reachable = false };
        }
    }
    private static string? NullIfEmpty(string? value) => string.IsNullOrWhiteSpace(value) ? null : value;
    internal static double? SupplyPercent(string levelText, string maxText, string unitText = "")
    {
        if (!long.TryParse(levelText, out var level) || level < 0) return null;
        // RFC 3805 unit 19 means the level is already a percentage. Several
        // printers correctly report this while leaving maximum capacity unknown.
        if (unitText.Trim() == "19" && level <= 100) return level;
        if (long.TryParse(maxText, out var max) && max > 0 && level <= max)
            return Math.Round((double)level / max * 100, 1);
        return null;
    }

    internal static bool PlausibleFirmware(string? value)
    {
        if (string.IsNullOrWhiteSpace(value)) return false;
        var candidate = value.Trim();
        if (candidate.Length > 80 || !candidate.Any(char.IsDigit)) return false;
        return !candidate.Contains("jenkins", StringComparison.OrdinalIgnoreCase)
            && !candidate.Contains("gitbuilder", StringComparison.OrdinalIgnoreCase)
            && !candidate.Contains("workspace", StringComparison.OrdinalIgnoreCase)
            && !candidate.StartsWith('/')
            && !candidate.Contains(":\\", StringComparison.Ordinal);
    }

    internal static string? NormalizeHpDatecode(string? value)
    {
        var candidate = value?.Trim();
        return candidate?.Length == 8
            && candidate.All(char.IsDigit)
            && DateTime.TryParseExact(candidate, "yyyyMMdd", System.Globalization.CultureInfo.InvariantCulture,
                System.Globalization.DateTimeStyles.None, out _)
            ? candidate
            : null;
    }

    private static string? FirmwareFromDescription(string description)
    {
        var match = Regex.Match(description, @"\b(?:firmware|fw|version|ver\.?)[\s:=_-]+([A-Za-z0-9][A-Za-z0-9._-]{1,39})", RegexOptions.IgnoreCase);
        return match.Success && PlausibleFirmware(match.Groups[1].Value) ? match.Groups[1].Value : null;
    }
    public sealed record DiscoverySettings(bool Enabled = false, int IntervalMinutes = 15)
    {
        public DiscoveryTarget[] Targets { get; init; } = [];
        public string ManagedPrinterCommunity { get; init; } = "public";
    }
    public sealed record DiscoveryTarget(string Address, string Community, string Kind = "network");
    private sealed record SupplyReading(string Name, double? Percent);
}
