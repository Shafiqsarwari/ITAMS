using System.Net.NetworkInformation;
using System.Net.Sockets;
using System.Runtime.InteropServices;
using System.Text.Json;
using System.Text.RegularExpressions;
using System.Xml.Linq;
using Microsoft.Win32;
using Microsoft.Win32.SafeHandles;
using AssetInventoryAgent.Models;

namespace AssetInventoryAgent.Services;

public sealed class InventoryCollector
{
    public async Task<InventoryPayload> CollectAsync()
    {
        var payload = new InventoryPayload
        {
            AgentVersion = typeof(InventoryCollector).Assembly.GetName().Version?.ToString() ?? "1.0.0",
            ComputerName = Environment.MachineName,
            DomainWorkgroup = Environment.UserDomainName,
            Os = new OsInfo
            {
                Name = await Ps("Get-CimInstance Win32_OperatingSystem | Select-Object -ExpandProperty Caption"),
                Version = WindowsDisplayVersion(),
                BuildNumber = WindowsBuildNumber()
            },
            Manufacturer = await Ps("Get-CimInstance Win32_ComputerSystem | Select-Object -ExpandProperty Manufacturer"),
            Model = await Ps("Get-CimInstance Win32_ComputerSystem | Select-Object -ExpandProperty Model"),
            SerialNumber = await Ps("Get-CimInstance Win32_BIOS | Select-Object -ExpandProperty SerialNumber"),
        };

        payload.Bios = new BiosInfo
        {
            Manufacturer = await Ps("Get-CimInstance Win32_BIOS | Select-Object -ExpandProperty Manufacturer"),
            Version = await Ps("Get-CimInstance Win32_BIOS | Select-Object -ExpandProperty SMBIOSBIOSVersion"),
            ReleaseDate = NormalizeDate(await Ps("(Get-CimInstance Win32_BIOS).ReleaseDate")),
            UpdateDate = NormalizeDate(await Ps("(Get-CimInstance Win32_BIOS).ReleaseDate"))
        };

        payload.Hardware = new HardwareInfo
        {
            CpuName = await CpuNameWithSpeed(),
            BatteryHealthPercent = await BatteryHealthPercent(),
            Motherboard = await Ps("Get-CimInstance Win32_BaseBoard | ForEach-Object { \"$($_.Manufacturer) $($_.Product)\" }"),
            RamBytes = ulong.TryParse(await Ps("(Get-CimInstance Win32_ComputerSystem).TotalPhysicalMemory"), out var ram) ? ram : null,
            Disks = await ReadInternalDisksAsync()
        };

        payload.Network = NetworkInterface.GetAllNetworkInterfaces()
            .Where(n => n.OperationalStatus == OperationalStatus.Up && n.NetworkInterfaceType != NetworkInterfaceType.Loopback)
            .Select(n => new NetworkInfo
            {
                AdapterName = n.Name,
                AdapterType = MapAdapterType(n),
                MacAddress = FormatMac(n.GetPhysicalAddress().ToString()),
                IpAddresses = n.GetIPProperties().UnicastAddresses
                    .Where(ip => ip.Address.AddressFamily is AddressFamily.InterNetwork or AddressFamily.InterNetworkV6)
                    .Select(ip => ip.Address.ToString())
                    .ToList()
            })
            .Where(n => !string.IsNullOrWhiteSpace(n.MacAddress))
            .ToList();

        payload.Software = await ReadInstalledSoftware();
        payload.Drivers = await ReadDriversAsync();
        return payload;
    }

    private static async Task<string?> CpuNameWithSpeed()
    {
        var name = await Ps("Get-CimInstance Win32_Processor | Select-Object -First 1 -ExpandProperty Name");
        if (string.IsNullOrWhiteSpace(name) || name.Contains("GHz", StringComparison.OrdinalIgnoreCase) || name.Contains("MHz", StringComparison.OrdinalIgnoreCase))
        {
            return name;
        }

        var speedText = await Ps("Get-CimInstance Win32_Processor | Select-Object -First 1 -ExpandProperty MaxClockSpeed");
        if (!int.TryParse(speedText, out var speedMhz) || speedMhz <= 0)
        {
            return name;
        }

        var speedGhz = speedMhz / 1000m;
        return $"{name} @ {speedGhz:0.##} GHz";
    }

    private static async Task<int?> BatteryHealthPercent()
    {
        var sample = await BatteryCapacityHealthFromWmi();
        sample ??= await BatteryCapacityHealthFromPowerCfg();
        LocalLog.Info(sample is not null
            ? $"Battery health collected from capacity data: {sample.HealthPercent}% (full charge capacity {sample.FullChargeCapacity:n0} / design capacity {sample.DesignCapacity:n0})."
            : "Battery health was not available from Windows battery capacity data.");
        return sample?.HealthPercent;
    }

    private static async Task<List<DiskInfo>> ReadInternalDisksAsync()
    {
        var json = await Ps(@"
$diskMetaByNumber = @{}
Get-Disk -ErrorAction SilentlyContinue | ForEach-Object {
    $diskMetaByNumber[[int]$_.Number] = $_
}

$internalDisks = @(Get-CimInstance Win32_DiskDrive -ErrorAction SilentlyContinue |
    Where-Object {
        $disk = $_
        $meta = $diskMetaByNumber[[int]$disk.Index]
        $busType = if ($meta) { [string]$meta.BusType } else { '' }
        $isExternalBus = $busType -match '^(USB|SD|MMC|Virtual|File Backed Virtual)$'
        $isExternalIdentity =
            $disk.InterfaceType -eq 'USB' -or
            $disk.MediaType -match 'Removable|External' -or
            $disk.PNPDeviceID -match '^(USBSTOR|USB\\)' -or
            $disk.Model -match '\b(USB|SD Card|Flash Drive|External)\b'

        $disk.Size -gt 0 -and -not $isExternalBus -and -not $isExternalIdentity
    })

$internalDisks |
    ForEach-Object {
        $disk = $_
        $logicalDisks = @()
        try {
            $partitions = @(Get-CimAssociatedInstance -InputObject $disk -Association Win32_DiskDriveToDiskPartition -ErrorAction SilentlyContinue)
            foreach ($partition in $partitions) {
                $logicalDisks += @(Get-CimAssociatedInstance -InputObject $partition -Association Win32_LogicalDiskToPartition -ErrorAction SilentlyContinue)
            }
        } catch {}

        $driveNames = @($logicalDisks | Where-Object { $_.DeviceID } | Select-Object -ExpandProperty DeviceID -Unique)
        $freeBytes = ($logicalDisks | Measure-Object -Property FreeSpace -Sum).Sum
        [pscustomobject]@{
            Name = if ($driveNames.Count -gt 0) { ($driveNames -join ', ') } elseif ($disk.Model) { $disk.Model } else { $disk.DeviceID }
            TotalBytes = [int64]$disk.Size
            FreeBytes = if ($freeBytes) { [int64]$freeBytes } else { 0 }
            Source = 'internal'
            BusType = if ($diskMetaByNumber[[int]$disk.Index]) { [string]$diskMetaByNumber[[int]$disk.Index].BusType } else { $disk.InterfaceType }
        }
    } |
    ConvertTo-Json -Compress
");
        if (string.IsNullOrWhiteSpace(json))
        {
            return [];
        }

        var disks = new List<DiskInfo>();
        try
        {
            using var document = JsonDocument.Parse(json);
            if (document.RootElement.ValueKind == JsonValueKind.Array)
            {
                foreach (var disk in document.RootElement.EnumerateArray())
                {
                    AddDisk(disks, disk);
                }
            }
            else if (document.RootElement.ValueKind == JsonValueKind.Object)
            {
                AddDisk(disks, document.RootElement);
            }
        }
        catch (JsonException ex)
        {
            LocalLog.Error("Could not parse internal disk inventory", ex);
        }

        return disks;
    }

    private static void AddDisk(List<DiskInfo> disks, JsonElement item)
    {
        if (!item.TryGetProperty("TotalBytes", out var totalProperty) || !totalProperty.TryGetInt64(out var totalBytes) || totalBytes <= 0)
        {
            return;
        }

        var freeBytes = 0L;
        if (item.TryGetProperty("FreeBytes", out var freeProperty))
        {
            freeProperty.TryGetInt64(out freeBytes);
        }

        disks.Add(new DiskInfo
        {
            Name = JsonString(item, "Name"),
            TotalBytes = totalBytes,
            FreeBytes = Math.Max(0, freeBytes),
            Source = JsonString(item, "Source") ?? "internal",
            BusType = JsonString(item, "BusType")
        });
    }

    private static async Task<BatteryCapacityHealth?> BatteryCapacityHealthFromWmi()
    {
        var capacityText = await Ps(@"
$static = Get-CimInstance -Namespace root\wmi -ClassName BatteryStaticData -ErrorAction SilentlyContinue
$full = Get-CimInstance -Namespace root\wmi -ClassName BatteryFullChargedCapacity -ErrorAction SilentlyContinue
$totalDesign = 0
$totalFull = 0
foreach ($battery in $static) {
    $match = $full | Where-Object { $_.InstanceName -eq $battery.InstanceName } | Select-Object -First 1
    if ($battery.DesignedCapacity -gt 0 -and $match.FullChargedCapacity -gt 0) {
        $totalDesign += [double]$battery.DesignedCapacity
        $totalFull += [double]$match.FullChargedCapacity
    }
}
if ($totalDesign -gt 0 -and $totalFull -gt 0) {
    ""$totalDesign|$totalFull""
}");
        return ParseBatteryCapacityHealth(capacityText);
    }

    private static async Task<BatteryCapacityHealth?> BatteryCapacityHealthFromPowerCfg()
    {
        var reportPath = Path.Combine(Path.GetTempPath(), $"battery-report-{Guid.NewGuid():N}.xml");
        try
        {
            var generated = await Ps($"powercfg /batteryreport /xml /output '{reportPath}' | Out-Null; if (Test-Path '{reportPath}') {{ '{reportPath}' }}");
            if (string.IsNullOrWhiteSpace(generated) || !File.Exists(reportPath))
            {
                return null;
            }

            var document = XDocument.Load(reportPath);
            var design = 0d;
            var full = 0d;
            foreach (var battery in document.Descendants().Where(element => element.Name.LocalName.Equals("Battery", StringComparison.OrdinalIgnoreCase)))
            {
                var batteryDesign = ParseCapacity(BatteryElementValue(battery, "DesignCapacity"));
                var batteryFull = ParseCapacity(BatteryElementValue(battery, "FullChargeCapacity"));
                if (batteryDesign > 0 && batteryFull > 0)
                {
                    design += batteryDesign;
                    full += batteryFull;
                }
            }

            if (design <= 0 || full <= 0)
            {
                LocalLog.Info($"Battery report did not include usable capacity values. Design={design:n0}, Full={full:n0}.");
                return null;
            }

            return CreateBatteryCapacityHealth(design, full);
        }
        catch (Exception ex)
        {
            LocalLog.Error("Battery health could not be read from powercfg battery report", ex);
            return null;
        }
        finally
        {
            try
            {
                if (File.Exists(reportPath))
                {
                    File.Delete(reportPath);
                }
            }
            catch (Exception ex)
            {
                LocalLog.Error("Temporary battery report could not be deleted", ex);
            }
        }
    }

    private static string? BatteryElementValue(XElement? battery, string name) =>
        battery?.Elements().FirstOrDefault(element => element.Name.LocalName.Equals(name, StringComparison.OrdinalIgnoreCase))?.Value;

    private static BatteryCapacityHealth? ParseBatteryCapacityHealth(string? value)
    {
        var parts = value?.Trim().Split('|', StringSplitOptions.TrimEntries | StringSplitOptions.RemoveEmptyEntries);
        if (parts?.Length != 2)
        {
            return null;
        }

        var design = ParseCapacity(parts[0]);
        var full = ParseCapacity(parts[1]);
        return CreateBatteryCapacityHealth(design, full);
    }

    private static BatteryCapacityHealth? CreateBatteryCapacityHealth(double design, double full)
    {
        if (design <= 0 || full <= 0)
        {
            return null;
        }

        var health = Math.Max(0, Math.Min(100, (int)Math.Round((full / design) * 100)));
        return new BatteryCapacityHealth(design, full, health);
    }

    private static double ParseCapacity(string? value)
    {
        if (string.IsNullOrWhiteSpace(value))
        {
            return 0;
        }

        var digits = new string(value.Where(c => char.IsDigit(c) || c == '.').ToArray());
        return double.TryParse(digits, System.Globalization.NumberStyles.Number, System.Globalization.CultureInfo.InvariantCulture, out var capacity)
            ? capacity
            : 0;
    }

    private sealed record BatteryCapacityHealth(double DesignCapacity, double FullChargeCapacity, int HealthPercent);

    private static async Task<string?> Ps(string command)
    {
        var value = await CommandReader.PowerShellAsync(command);
        return string.IsNullOrWhiteSpace(value) ? null : value;
    }

    private static string MapAdapterType(NetworkInterface adapter)
    {
        var name = adapter.Name.ToLowerInvariant();
        var description = adapter.Description.ToLowerInvariant();
        if (adapter.NetworkInterfaceType == NetworkInterfaceType.Wireless80211 || name.Contains("wi-fi") || description.Contains("wireless")) return "WiFi";
        if (adapter.NetworkInterfaceType == NetworkInterfaceType.Ethernet) return "LAN";
        if (description.Contains("virtual") || name.Contains("virtual")) return "Virtual";
        return "Other";
    }

    private static string? FormatMac(string raw)
    {
        if (string.IsNullOrWhiteSpace(raw)) return null;
        return string.Join(":", Enumerable.Range(0, raw.Length / 2).Select(i => raw.Substring(i * 2, 2)));
    }

    private static string? NormalizeDate(string? value)
    {
        return DateTime.TryParse(value, out var date) ? date.ToString("yyyy-MM-dd") : null;
    }

    private static string? WindowsDisplayVersion()
    {
        using var key = Registry.LocalMachine.OpenSubKey(@"SOFTWARE\Microsoft\Windows NT\CurrentVersion");
        return key?.GetValue("DisplayVersion")?.ToString()
            ?? key?.GetValue("ReleaseId")?.ToString();
    }

    private static string WindowsBuildNumber()
    {
        using var key = Registry.LocalMachine.OpenSubKey(@"SOFTWARE\Microsoft\Windows NT\CurrentVersion");
        var build = key?.GetValue("CurrentBuildNumber")?.ToString()
            ?? key?.GetValue("CurrentBuild")?.ToString()
            ?? Environment.OSVersion.Version.Build.ToString();
        var ubr = key?.GetValue("UBR")?.ToString();
        return string.IsNullOrWhiteSpace(ubr) ? build : $"{build}.{ubr}";
    }

    private static async Task<List<SoftwareInfo>> ReadInstalledSoftware()
    {
        var software = new Dictionary<string, SoftwareInfo>(StringComparer.OrdinalIgnoreCase);

        foreach (var root in OpenSoftwareRegistryRoots())
        {
            using (root)
            {
                foreach (var subKeyName in SafeSubKeyNames(root))
                {
                    using var key = SafeOpenSubKey(root, subKeyName);
                    if (key == null) continue;
                    var name = key.GetValue("DisplayName")?.ToString();
                    if (string.IsNullOrWhiteSpace(name) || IsHiddenUninstallEntry(key, name)) continue;
                    var registryLastWriteDate = RegistryKeyLastWriteDate(key);
                    var installDate = ParseRegistryDate(key.GetValue("InstallDate")?.ToString())
                        ?? InstallLocationDate(key, static item => item.CreationTime)
                        ?? registryLastWriteDate;
                    var updatedDate = registryLastWriteDate
                        ?? InstallLocationDate(key, static item => item.LastWriteTime);

                    AddOrReplaceSoftware(software, new SoftwareInfo
                    {
                        Name = name.Trim(),
                        Version = RegistryDisplayVersion(key),
                        Publisher = CleanRegistryValue(key.GetValue("Publisher")),
                        InstalledDate = installDate,
                        LastUpdatedDate = updatedDate
                    });
                }
            }
        }

        await AddAppxPackagesAsync(software);
        AddAppxAllUserStorePackages(software);
        AddWindowsAppsFromUserProfiles(software);
        FillMissingStateRepositoryPackageVersions(software);

        return software.Values.OrderBy(s => s.Name).ToList();
    }

    private static async Task<List<DriverInfo>> ReadDriversAsync()
    {
        var json = await Ps(@"
$driverRows = @(Get-CimInstance Win32_PnPSignedDriver -ErrorAction SilentlyContinue |
    Where-Object { $_.DeviceName -and $_.DriverVersion })
$driverDates = @{}
if ($driverRows.DeviceID) {
    Get-PnpDeviceProperty -InstanceId $driverRows.DeviceID -KeyName 'DEVPKEY_Device_FirstInstallDate','DEVPKEY_Device_InstallDate' -ErrorAction SilentlyContinue |
        ForEach-Object { $driverDates[""$($_.DeviceID)|$($_.KeyName)""] = $_.Data }
}
$driverRows |
    ForEach-Object {
        $firstInstallDate = $driverDates[""$($_.DeviceID)|DEVPKEY_Device_FirstInstallDate""]
        $lastInstallDate = $driverDates[""$($_.DeviceID)|DEVPKEY_Device_InstallDate""]
        if ($_.DeviceID -and (-not $firstInstallDate -or -not $lastInstallDate)) {
            $missingDates = Get-PnpDeviceProperty -InstanceId $_.DeviceID -KeyName 'DEVPKEY_Device_FirstInstallDate','DEVPKEY_Device_InstallDate' -ErrorAction SilentlyContinue
            if (-not $firstInstallDate) {
                $firstInstallDate = ($missingDates | Where-Object KeyName -eq 'DEVPKEY_Device_FirstInstallDate' | Select-Object -First 1).Data
            }
            if (-not $lastInstallDate) {
                $lastInstallDate = ($missingDates | Where-Object KeyName -eq 'DEVPKEY_Device_InstallDate' | Select-Object -First 1).Data
            }
        }
        [pscustomobject]@{
            DeviceName = $_.DeviceName
            Version = $_.DriverVersion
            Provider = $_.DriverProviderName
            Manufacturer = $_.Manufacturer
            DriverDate = if ($_.DriverDate) { ([datetime]$_.DriverDate).ToString('yyyy-MM-dd') } else { $null }
            InstalledDate = if ($firstInstallDate) { ([datetime]$firstInstallDate).ToString('yyyy-MM-dd') } else { $null }
            LastUpdatedDate = if ($lastInstallDate) { ([datetime]$lastInstallDate).ToString('yyyy-MM-dd') } else { $null }
            InfName = $_.InfName
            DeviceClass = $_.DeviceClass
        }
    } |
    ConvertTo-Json -Compress
");
        if (string.IsNullOrWhiteSpace(json))
        {
            return [];
        }

        var drivers = new Dictionary<string, DriverInfo>(StringComparer.OrdinalIgnoreCase);
        try
        {
            using var document = JsonDocument.Parse(json);
            if (document.RootElement.ValueKind == JsonValueKind.Array)
            {
                foreach (var driver in document.RootElement.EnumerateArray())
                {
                    AddDriver(drivers, driver);
                }
            }
            else if (document.RootElement.ValueKind == JsonValueKind.Object)
            {
                AddDriver(drivers, document.RootElement);
            }
        }
        catch (JsonException ex)
        {
            LocalLog.Error("Could not parse driver inventory", ex);
        }

        return drivers.Values
            .OrderBy(driver => driver.DeviceName)
            .ThenBy(driver => driver.Version)
            .ToList();
    }

    private static void AddDriver(Dictionary<string, DriverInfo> drivers, JsonElement item)
    {
        var deviceName = JsonString(item, "DeviceName");
        var version = JsonString(item, "Version");
        if (string.IsNullOrWhiteSpace(deviceName) || string.IsNullOrWhiteSpace(version))
        {
            return;
        }

        var infName = JsonString(item, "InfName");
        var deviceClass = JsonString(item, "DeviceClass");
        var key = $"{deviceName}|{version}|{infName}|{deviceClass}";
        var driver = new DriverInfo
        {
            DeviceName = deviceName,
            Version = version,
            Provider = JsonString(item, "Provider"),
            Manufacturer = JsonString(item, "Manufacturer"),
            DriverDate = NormalizeDate(JsonString(item, "DriverDate")),
            InstalledDate = NormalizeDate(JsonString(item, "InstalledDate")),
            LastUpdatedDate = NormalizeDate(JsonString(item, "LastUpdatedDate")),
            InfName = infName,
            DeviceClass = deviceClass
        };

        if (drivers.TryGetValue(key, out var existing))
        {
            drivers[key] = MergeDriver(existing, driver);
            return;
        }

        drivers[key] = driver;
    }

    private static DriverInfo MergeDriver(DriverInfo existing, DriverInfo item)
    {
        var primary = DriverCompletenessScore(item) >= DriverCompletenessScore(existing) ? item : existing;
        var fallback = ReferenceEquals(primary, item) ? existing : item;

        return new DriverInfo
        {
            DeviceName = primary.DeviceName ?? fallback.DeviceName,
            Version = primary.Version ?? fallback.Version,
            Provider = primary.Provider ?? fallback.Provider,
            Manufacturer = primary.Manufacturer ?? fallback.Manufacturer,
            DriverDate = primary.DriverDate ?? fallback.DriverDate,
            InstalledDate = primary.InstalledDate ?? fallback.InstalledDate,
            LastUpdatedDate = primary.LastUpdatedDate ?? fallback.LastUpdatedDate,
            InfName = primary.InfName ?? fallback.InfName,
            DeviceClass = primary.DeviceClass ?? fallback.DeviceClass
        };
    }

    private static int DriverCompletenessScore(DriverInfo item)
    {
        var score = 0;
        if (!string.IsNullOrWhiteSpace(item.Provider)) score++;
        if (!string.IsNullOrWhiteSpace(item.Manufacturer)) score++;
        if (!string.IsNullOrWhiteSpace(item.DriverDate)) score++;
        if (!string.IsNullOrWhiteSpace(item.InstalledDate)) score += 2;
        if (!string.IsNullOrWhiteSpace(item.LastUpdatedDate)) score += 2;
        return score;
    }

    private static async Task AddAppxPackagesAsync(Dictionary<string, SoftwareInfo> software)
    {
        var json = await Ps(@"
Get-AppxPackage -AllUsers -ErrorAction SilentlyContinue |
    Where-Object { -not $_.IsFramework -and $_.Name -and $_.InstallLocation } |
    ForEach-Object {
        $displayName = $null
        $publisherDisplayName = $null
        $hasVisibleApplication = $null
        $manifestPath = Join-Path $_.InstallLocation 'AppxManifest.xml'
        if (Test-Path $manifestPath) {
            try {
                [xml]$manifest = Get-Content $manifestPath -ErrorAction Stop
                $displayName = [string]$manifest.Package.Properties.DisplayName
                $publisherDisplayName = [string]$manifest.Package.Properties.PublisherDisplayName
                $applications = @($manifest.SelectNodes(""//*[local-name()='Applications']/*[local-name()='Application']""))
                $hasVisibleApplication = @($applications | Where-Object {
                    $visualElements = $_.SelectSingleNode(""./*[local-name()='VisualElements']"")
                    if (-not $visualElements) { return $false }
                    $appListEntry = $visualElements.Attributes['AppListEntry']
                    return -not $appListEntry -or $appListEntry.Value -ne 'none'
                }).Count -gt 0
            } catch {}
        }
        [pscustomobject]@{
            Name = $_.Name
            DisplayName = $displayName
            HasVisibleApplication = $hasVisibleApplication
            Version = $_.Version
            Publisher = $_.Publisher
            PublisherDisplayName = $publisherDisplayName
            InstallLocation = $_.InstallLocation
            PackageFullName = $_.PackageFullName
        }
    } |
    ConvertTo-Json -Compress
");
        if (string.IsNullOrWhiteSpace(json))
        {
            return;
        }

        try
        {
            using var document = JsonDocument.Parse(json);
            if (document.RootElement.ValueKind == JsonValueKind.Array)
            {
                foreach (var package in document.RootElement.EnumerateArray())
                {
                    AddAppxPackage(software, package);
                }
                return;
            }

            if (document.RootElement.ValueKind == JsonValueKind.Object)
            {
                AddAppxPackage(software, document.RootElement);
            }
        }
        catch (JsonException ex)
        {
            LocalLog.Error("Could not parse AppX/MSIX software inventory", ex);
        }
    }

    private static void AddAppxPackage(Dictionary<string, SoftwareInfo> software, JsonElement package)
    {
        var name = JsonString(package, "Name");
        if (string.IsNullOrWhiteSpace(name) || IsSystemPackageName(name))
        {
            return;
        }

        if (JsonBoolean(package, "HasVisibleApplication") == false)
        {
            return;
        }

        var installLocation = JsonString(package, "InstallLocation");
        AddOrReplaceSoftware(software, new SoftwareInfo
        {
            Name = FriendlyPackageName(UsableAppxText(JsonString(package, "DisplayName")) ?? name),
            Version = JsonString(package, "Version"),
            Publisher = FriendlyPublisher(JsonString(package, "PublisherDisplayName") ?? JsonString(package, "Publisher")),
            InstalledDate = PathDate(installLocation, static item => item.CreationTime),
            LastUpdatedDate = PathDate(installLocation, static item => item.LastWriteTime)
        });
    }

    private static string? JsonString(JsonElement element, string propertyName)
    {
        return element.TryGetProperty(propertyName, out var property) && property.ValueKind != JsonValueKind.Null
            ? CleanRegistryValue(property.GetString())
            : null;
    }

    private static bool? JsonBoolean(JsonElement element, string propertyName)
    {
        if (!element.TryGetProperty(propertyName, out var property))
        {
            return null;
        }

        return property.ValueKind switch
        {
            JsonValueKind.True => true,
            JsonValueKind.False => false,
            _ => null
        };
    }

    private static string? UsableAppxText(string? value)
    {
        if (string.IsNullOrWhiteSpace(value) || value.StartsWith("ms-resource:", StringComparison.OrdinalIgnoreCase))
        {
            return null;
        }

        return value;
    }

    private static string FriendlyPackageName(string name)
    {
        return name.Replace('_', ' ').Replace('.', ' ').Trim();
    }

    private static string? FriendlyPublisher(string? publisher)
    {
        if (string.IsNullOrWhiteSpace(publisher))
        {
            return null;
        }

        if (UsableAppxText(publisher) == null)
        {
            return null;
        }

        const string commonNamePrefix = "CN=";
        var commonNameIndex = publisher.IndexOf(commonNamePrefix, StringComparison.OrdinalIgnoreCase);
        if (commonNameIndex < 0)
        {
            return IsGuidText(publisher) ? null : publisher;
        }

        var commonName = publisher[(commonNameIndex + commonNamePrefix.Length)..];
        var commaIndex = commonName.IndexOf(',', StringComparison.Ordinal);
        var value = (commaIndex >= 0 ? commonName[..commaIndex] : commonName).Trim();
        return IsGuidText(value) ? null : value;
    }

    private static bool IsGuidText(string value)
    {
        return Guid.TryParse(value.Trim('{', '}'), out _);
    }

    private static void AddAppxAllUserStorePackages(Dictionary<string, SoftwareInfo> software)
    {
        using var root = SafeOpenSubKey(Registry.LocalMachine, @"SOFTWARE\Microsoft\Windows\CurrentVersion\Appx\AppxAllUserStore");
        if (root == null)
        {
            return;
        }

        foreach (var sid in SafeSubKeyNames(root))
        {
            if (!sid.StartsWith("S-1-5-", StringComparison.OrdinalIgnoreCase))
            {
                continue;
            }

            using var userPackages = SafeOpenSubKey(root, sid);
            if (userPackages == null)
            {
                continue;
            }

            foreach (var packageFullName in SafeSubKeyNames(userPackages))
            {
                using var packageKey = SafeOpenSubKey(userPackages, packageFullName);
                var package = ParsePackageFullName(packageFullName);
                if (package == null || IsSystemPackageName(package.Name))
                {
                    continue;
                }

                AddOrReplaceSoftware(software, new SoftwareInfo
                {
                    Name = FriendlyPackageName(package.Name),
                    Version = package.Version,
                    Publisher = PublisherFromPackageName(package.Name),
                    LastUpdatedDate = packageKey == null ? null : RegistryKeyLastWriteDate(packageKey)
                });
            }
        }
    }

    private static AppxPackageName? ParsePackageFullName(string packageFullName)
    {
        var parts = packageFullName.Split('_', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries);
        if (parts.Length < 2 || !Version.TryParse(parts[1], out _))
        {
            return null;
        }

        return new AppxPackageName(parts[0], parts[1]);
    }

    private sealed record AppxPackageName(string Name, string Version);

    private static void AddWindowsAppsFromUserProfiles(Dictionary<string, SoftwareInfo> software)
    {
        foreach (var profile in UserProfileDirectories())
        {
            var profileName = Path.GetFileName(profile);
            if (profileName.Equals("Public", StringComparison.OrdinalIgnoreCase)
                || profileName.Equals("Default", StringComparison.OrdinalIgnoreCase)
                || profileName.Equals("Default User", StringComparison.OrdinalIgnoreCase)
                || profileName.Equals("All Users", StringComparison.OrdinalIgnoreCase))
            {
                continue;
            }

            AddWindowsAppsFromDirectory(software, Path.Combine(profile, @"AppData\Local\Microsoft\WindowsApps"));
        }
    }

    private static IEnumerable<string> UserProfileDirectories()
    {
        var profiles = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        using var profileList = SafeOpenSubKey(Registry.LocalMachine, @"SOFTWARE\Microsoft\Windows NT\CurrentVersion\ProfileList");
        if (profileList != null)
        {
            foreach (var sid in SafeSubKeyNames(profileList))
            {
                using var profileKey = SafeOpenSubKey(profileList, sid);
                var profilePath = CleanRegistryValue(profileKey?.GetValue("ProfileImagePath"));
                if (string.IsNullOrWhiteSpace(profilePath))
                {
                    continue;
                }

                AddDirectoryIfPresent(profiles, Environment.ExpandEnvironmentVariables(profilePath));
            }
        }

        var currentUserRoot = Path.GetDirectoryName(Environment.GetFolderPath(Environment.SpecialFolder.UserProfile));
        if (!string.IsNullOrWhiteSpace(currentUserRoot))
        {
            foreach (var profile in SafeDirectories(currentUserRoot))
            {
                AddDirectoryIfPresent(profiles, profile);
            }
        }

        return profiles;
    }

    private static void AddDirectoryIfPresent(HashSet<string> directories, string path)
    {
        if (Directory.Exists(path))
        {
            directories.Add(path);
        }
    }

    private static void AddWindowsAppsFromDirectory(Dictionary<string, SoftwareInfo> software, string directory)
    {
        if (!Directory.Exists(directory))
        {
            return;
        }

        foreach (var appDirectory in SafeDirectories(directory))
        {
            var packageName = PackageNameFromFamilyName(Path.GetFileName(appDirectory));
            if (string.IsNullOrWhiteSpace(packageName) || IsSystemPackageName(packageName))
            {
                continue;
            }

            AddOrReplaceSoftware(software, new SoftwareInfo
            {
                Name = FriendlyPackageName(packageName),
                Publisher = PublisherFromPackageName(packageName),
                InstalledDate = PathDate(appDirectory, static item => item.CreationTime),
                LastUpdatedDate = PathDate(appDirectory, static item => item.LastWriteTime)
            });
        }
    }

    private static void FillMissingStateRepositoryPackageVersions(Dictionary<string, SoftwareInfo> software)
    {
        using var root = SafeOpenSubKey(Registry.LocalMachine, @"SOFTWARE\Microsoft\Windows\CurrentVersion\AppModel\StateRepository\Cache\Package\Data");
        if (root == null)
        {
            return;
        }

        foreach (var subKeyName in SafeSubKeyNames(root))
        {
            using var packageKey = SafeOpenSubKey(root, subKeyName);
            var packageFullName = CleanRegistryValue(packageKey?.GetValue("PackageFullName"));
            if (string.IsNullOrWhiteSpace(packageFullName) || packageFullName.Contains("_~_", StringComparison.Ordinal))
            {
                continue;
            }

            var package = ParsePackageFullName(packageFullName);
            if (package == null || IsSystemPackageName(package.Name))
            {
                continue;
            }

            FillMissingSoftwareVersion(software, FriendlyPackageName(package.Name), package.Version);
        }
    }

    private static void FillMissingSoftwareVersion(Dictionary<string, SoftwareInfo> software, string name, string? version)
    {
        var key = SoftwareInventoryKey(name, version);
        if (string.IsNullOrWhiteSpace(version)
            || !software.TryGetValue(key, out var existing)
            || !string.IsNullOrWhiteSpace(existing.Version))
        {
            return;
        }

        existing.Version = version;
    }

    private static IEnumerable<string> SafeDirectories(string path)
    {
        try
        {
            return Directory.EnumerateDirectories(path).ToArray();
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or System.Security.SecurityException)
        {
            LocalLog.Error($"Could not read directories: {path}", ex);
            return [];
        }
    }

    private static string PackageNameFromFamilyName(string value)
    {
        var underscoreIndex = value.LastIndexOf('_');
        return underscoreIndex > 0 ? value[..underscoreIndex] : value;
    }

    private static string? PublisherFromPackageName(string packageName)
    {
        var separatorIndex = packageName.IndexOf('.');
        return separatorIndex > 0 ? packageName[..separatorIndex] : null;
    }

    private static bool IsSystemPackageName(string packageName)
    {
        if (IsGuidText(packageName))
        {
            return true;
        }

        var systemPrefixes = new[]
        {
            "Microsoft.AAD.",
            "Microsoft.AccountsControl",
            "Microsoft.AsyncTextService",
            "Microsoft.BioEnrollment",
            "Microsoft.CredDialogHost",
            "Microsoft.ECApp",
            "Microsoft.LockApp",
            "Microsoft.MicrosoftEdge",
            "Microsoft.UI.",
            "Microsoft.VCLibs.",
            "Microsoft.Win32WebViewHost",
            "Microsoft.Windows.",
            "Microsoft.WindowsAppRuntime.",
            "Microsoft.WindowsStore",
            "Microsoft.XboxGameCallableUI",
            "MicrosoftWindows.",
            "Windows.",
            "WindowsApps.",
            "WindowsSubsystem.",
            "cr.sb.",
            "NcsiUwpApp",
            "E2A4F912-2574-4A75-9BB0-0D023378592B"
        };

        return systemPrefixes.Any(prefix => packageName.StartsWith(prefix, StringComparison.OrdinalIgnoreCase));
    }

    private static bool IsHiddenUninstallEntry(RegistryKey key, string displayName)
    {
        if (IsGuidText(displayName))
        {
            return true;
        }

        var systemComponent = key.GetValue("SystemComponent");
        if (systemComponent is int value && value != 0)
        {
            return true;
        }

        return string.Equals(CleanRegistryValue(key.GetValue("ReleaseType")), "Update", StringComparison.OrdinalIgnoreCase);
    }

    private static IEnumerable<RegistryKey> OpenSoftwareRegistryRoots()
    {
        var paths = new[]
        {
            @"SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall",
            @"SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall"
        };

        foreach (var path in paths)
        {
            var key = SafeOpenSubKey(Registry.LocalMachine, path);
            if (key != null) yield return key;
        }

        foreach (var path in paths)
        {
            var key = SafeOpenSubKey(Registry.CurrentUser, path);
            if (key != null) yield return key;
        }

        foreach (var userSid in SafeSubKeyNames(Registry.Users))
        {
            if (!userSid.StartsWith("S-1-5-", StringComparison.OrdinalIgnoreCase) || userSid.EndsWith("_Classes", StringComparison.OrdinalIgnoreCase))
            {
                continue;
            }

            foreach (var path in paths)
            {
                var key = SafeOpenSubKey(Registry.Users, $@"{userSid}\{path}");
                if (key != null) yield return key;
            }
        }
    }

    private static string[] SafeSubKeyNames(RegistryKey key)
    {
        try
        {
            return key.GetSubKeyNames();
        }
        catch (Exception ex) when (ex is UnauthorizedAccessException or IOException or System.Security.SecurityException)
        {
            LocalLog.Error($"Could not read registry subkeys: {key.Name}", ex);
            return [];
        }
    }

    private static RegistryKey? SafeOpenSubKey(RegistryKey key, string name)
    {
        try
        {
            return key.OpenSubKey(name);
        }
        catch (Exception ex) when (ex is UnauthorizedAccessException or IOException or System.Security.SecurityException)
        {
            LocalLog.Error($"Could not open registry key: {key.Name}\\{name}", ex);
            return null;
        }
    }

    private static string? CleanRegistryValue(object? value)
    {
        var text = value?.ToString()?.Trim();
        return string.IsNullOrWhiteSpace(text) ? null : text;
    }

    private static string? RegistryDisplayVersion(RegistryKey key)
    {
        var version = CleanRegistryValue(key.GetValue("DisplayVersion"));
        if (!string.IsNullOrWhiteSpace(version))
        {
            return version;
        }

        var major = CleanRegistryValue(key.GetValue("VersionMajor"));
        var minor = CleanRegistryValue(key.GetValue("VersionMinor"));
        if (string.IsNullOrWhiteSpace(major) && string.IsNullOrWhiteSpace(minor))
        {
            return null;
        }

        return string.IsNullOrWhiteSpace(minor) ? major : $"{major}.{minor}";
    }

    private static void AddOrReplaceSoftware(Dictionary<string, SoftwareInfo> software, SoftwareInfo item)
    {
        if (string.IsNullOrWhiteSpace(item.Name))
        {
            return;
        }

        var key = SoftwareInventoryKey(item.Name, item.Version);
        if (!software.TryGetValue(key, out var existing))
        {
            software[key] = item;
            return;
        }

        software[key] = MergeSoftware(existing, item);
    }

    private static SoftwareInfo MergeSoftware(SoftwareInfo existing, SoftwareInfo item)
    {
        var primary = PreferredSoftware(existing, item);
        var fallback = ReferenceEquals(primary, item) ? existing : item;

        return new SoftwareInfo
        {
            Name = primary.Name ?? fallback.Name,
            Version = primary.Version ?? fallback.Version,
            Publisher = primary.Publisher ?? fallback.Publisher,
            InstalledDate = primary.InstalledDate ?? fallback.InstalledDate,
            LastUpdatedDate = primary.LastUpdatedDate ?? fallback.LastUpdatedDate
        };
    }

    private static SoftwareInfo PreferredSoftware(SoftwareInfo existing, SoftwareInfo item)
    {
        if (Version.TryParse(existing.Version, out var existingVersion)
            && Version.TryParse(item.Version, out var itemVersion)
            && existingVersion != itemVersion)
        {
            return itemVersion > existingVersion ? item : existing;
        }

        return CompletenessScore(item) >= CompletenessScore(existing) ? item : existing;
    }

    private static int CompletenessScore(SoftwareInfo item)
    {
        var score = 0;
        if (!string.IsNullOrWhiteSpace(item.Version)) score++;
        if (!string.IsNullOrWhiteSpace(item.Publisher)) score++;
        if (!string.IsNullOrWhiteSpace(item.InstalledDate)) score++;
        if (!string.IsNullOrWhiteSpace(item.LastUpdatedDate)) score++;
        return score;
    }

    private static string SoftwareInventoryKey(string name, string? version = null)
    {
        var key = Regex.Replace(name.Trim().ToLowerInvariant(), @"[\u00ae\u2122]", "");
        key = Regex.Replace(key, @"\s+", " ");

        var cleanVersion = Regex.Escape(version?.Trim() ?? "");
        if (!string.IsNullOrWhiteSpace(cleanVersion))
        {
            key = Regex.Replace(key, $@"(?:\s+(?:version|ver\.?|v)|\s*\()\s*{cleanVersion}\)?$", "", RegexOptions.IgnoreCase);
        }

        return key.Trim();
    }

    private static string? ParseRegistryDate(string? value)
    {
        if (string.IsNullOrWhiteSpace(value)) return null;
        if (DateTime.TryParseExact(value, "yyyyMMdd", null, System.Globalization.DateTimeStyles.None, out var date)) return date.ToString("yyyy-MM-dd");
        return DateTime.TryParse(value, out date) ? date.ToString("yyyy-MM-dd") : null;
    }

    private static string? InstallLocationDate(RegistryKey key, Func<FileSystemInfo, DateTime> dateSelector)
    {
        var candidates = new[]
        {
            CleanRegistryValue(key.GetValue("InstallLocation")),
            ExecutablePathFromRegistryValue(key.GetValue("DisplayIcon")),
            ExecutablePathFromRegistryValue(key.GetValue("UninstallString"))
        };

        foreach (var candidate in candidates)
        {
            if (string.IsNullOrWhiteSpace(candidate)) continue;

            try
            {
                FileSystemInfo? item = null;
                if (Directory.Exists(candidate))
                {
                    item = new DirectoryInfo(candidate);
                }
                else if (File.Exists(candidate))
                {
                    item = new FileInfo(candidate);
                }
                else
                {
                    var directory = Path.GetDirectoryName(candidate);
                    if (!string.IsNullOrWhiteSpace(directory) && Directory.Exists(directory))
                    {
                        item = new DirectoryInfo(directory);
                    }
                }

                if (item == null) continue;
                return dateSelector(item).ToString("yyyy-MM-dd");
            }
            catch (Exception ex) when (ex is ArgumentException or IOException or UnauthorizedAccessException or System.Security.SecurityException)
            {
                LocalLog.Error($"Could not read software install path timestamp: {candidate}", ex);
            }
        }

        return null;
    }

    private static string? PathDate(string? path, Func<FileSystemInfo, DateTime> dateSelector)
    {
        if (string.IsNullOrWhiteSpace(path))
        {
            return null;
        }

        try
        {
            FileSystemInfo? item = null;
            if (Directory.Exists(path))
            {
                item = new DirectoryInfo(path);
            }
            else if (File.Exists(path))
            {
                item = new FileInfo(path);
            }

            return item == null ? null : dateSelector(item).ToString("yyyy-MM-dd");
        }
        catch (Exception ex) when (ex is ArgumentException or IOException or UnauthorizedAccessException or System.Security.SecurityException)
        {
            LocalLog.Error($"Could not read package install path timestamp: {path}", ex);
            return null;
        }
    }

    private static string? ExecutablePathFromRegistryValue(object? value)
    {
        var text = CleanRegistryValue(value);
        if (text == null) return null;

        text = Environment.ExpandEnvironmentVariables(text.Trim());
        if (text.StartsWith("\"", StringComparison.Ordinal))
        {
            var endQuote = text.IndexOf('"', 1);
            if (endQuote > 1) return text.Substring(1, endQuote - 1);
        }

        var comma = text.IndexOf(',', StringComparison.Ordinal);
        if (comma > 0) text = text.Substring(0, comma);

        var exeIndex = text.IndexOf(".exe", StringComparison.OrdinalIgnoreCase);
        if (exeIndex >= 0) text = text.Substring(0, exeIndex + 4);

        return text.Trim();
    }

    private static string? RegistryKeyLastWriteDate(RegistryKey key)
    {
        try
        {
            var result = RegQueryInfoKey(
                key.Handle,
                IntPtr.Zero,
                IntPtr.Zero,
                IntPtr.Zero,
                out _,
                out _,
                out _,
                out _,
                out _,
                out _,
                out _,
                out var lastWriteTime);

            if (result != 0 || lastWriteTime <= 0) return null;
            return DateTime.FromFileTimeUtc(lastWriteTime).ToLocalTime().ToString("yyyy-MM-dd");
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or System.Security.SecurityException)
        {
            LocalLog.Error($"Could not read registry key timestamp: {key.Name}", ex);
            return null;
        }
    }

    [DllImport("advapi32.dll", SetLastError = true)]
    private static extern int RegQueryInfoKey(
        SafeRegistryHandle hKey,
        IntPtr lpClass,
        IntPtr lpcchClass,
        IntPtr lpReserved,
        out uint lpcSubKeys,
        out uint lpcbMaxSubKeyLen,
        out uint lpcbMaxClassLen,
        out uint lpcValues,
        out uint lpcbMaxValueNameLen,
        out uint lpcbMaxValueLen,
        out uint lpcbSecurityDescriptor,
        out long lpftLastWriteTime);
}
