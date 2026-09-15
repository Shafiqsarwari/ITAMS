using System.Net;
using System.Xml;
using System.Xml.Linq;

namespace AssetInventoryAgent.Services;

// HP LEDM ProductConfigDyn: read-only identification, never printer configuration.
internal static class HpPrinterIdentity
{
    internal sealed record Identity(string Model, string Firmware, string Serial, string Datecode);
    internal sealed record Supply(string Name, double Percent);

    internal static Identity? Read(string address, TimeSpan budget, CancellationToken stop)
    {
        var ip = IPAddress.Parse(address);
        var b = ip.GetAddressBytes();
        if (b.Length != 4 || !(b[0] == 10 || b[0] == 192 && b[1] == 168 || b[0] == 172 && b[1] >= 16 && b[1] <= 31)) return null;
        if (budget <= TimeSpan.Zero) return null;
        try
        {
            using var handler = new HttpClientHandler { AllowAutoRedirect = false, UseProxy = false, UseCookies = false };
            using var client = new HttpClient(handler) { Timeout = budget, MaxResponseContentBufferSize = 262144 };
            // No credentials or community are sent; do not follow device-provided URLs.
            using var response = client.GetAsync($"http://{ip}/DevMgmt/ProductConfigDyn.xml", stop).GetAwaiter().GetResult();
            if (!response.IsSuccessStatusCode) return null;
            return Parse(response.Content.ReadAsStringAsync(stop).GetAwaiter().GetResult());
        }
        catch (OperationCanceledException) when (stop.IsCancellationRequested) { throw; }
        catch { return null; } // SNMP inventory still uploads if EWS is disabled/unreachable.
    }

    internal static IReadOnlyList<Supply> ReadSupplies(string address, TimeSpan budget, CancellationToken stop)
    {
        var ip = IPAddress.Parse(address);
        var b = ip.GetAddressBytes();
        if (b.Length != 4 || !(b[0] == 10 || b[0] == 192 && b[1] == 168 || b[0] == 172 && b[1] >= 16 && b[1] <= 31)) return [];
        if (budget <= TimeSpan.Zero) return [];
        try
        {
            using var handler = new HttpClientHandler { AllowAutoRedirect = false, UseProxy = false, UseCookies = false };
            using var client = new HttpClient(handler) { Timeout = budget, MaxResponseContentBufferSize = 262144 };
            using var response = client.GetAsync($"http://{ip}/DevMgmt/ConsumableConfigDyn.xml", stop).GetAwaiter().GetResult();
            if (!response.IsSuccessStatusCode) return [];
            return ParseSupplies(response.Content.ReadAsStringAsync(stop).GetAwaiter().GetResult());
        }
        catch (OperationCanceledException) when (stop.IsCancellationRequested) { throw; }
        catch { return []; }
    }

    internal static Identity? Parse(string xml)
    {
        using var input = new StringReader(xml);
        using var reader = XmlReader.Create(input, new XmlReaderSettings {
            DtdProcessing = DtdProcessing.Prohibit, XmlResolver = null, MaxCharactersInDocument = 262144
        });
        var root = XDocument.Load(reader).Root;
        if (root?.Name.LocalName != "ProductConfigDyn" ||
            !root.Name.NamespaceName.StartsWith("http://www.hp.com/schemas/imaging/con/ledm/productconfigdyn/", StringComparison.Ordinal)) return null;
        var product = root.Elements().FirstOrDefault(e => e.Name.LocalName == "ProductInformation");
        if (product == null) return null;
        XNamespace dd = "http://www.hp.com/schemas/imaging/con/dictionaries/1.0/";
        string Value(XElement? element) => (element?.Value ?? "").Trim();
        // The root Version is the schema revision, NOT the printer firmware.
        var version = product.Element(dd + "Version");
        return new Identity(Value(product.Element(dd + "MakeAndModel")),
            Value(version?.Element(dd + "Revision")), Value(product.Element(dd + "SerialNumber")),
            Value(version?.Element(dd + "Date")));
    }

    internal static IReadOnlyList<Supply> ParseSupplies(string xml)
    {
        using var input = new StringReader(xml);
        using var reader = XmlReader.Create(input, new XmlReaderSettings {
            DtdProcessing = DtdProcessing.Prohibit, XmlResolver = null, MaxCharactersInDocument = 262144
        });
        var document = XDocument.Load(reader);
        var result = new List<Supply>();
        foreach (var item in document.Descendants().Where(e => e.Name.LocalName == "ConsumableInfo"))
        {
            string Value(string localName) => item.Descendants().FirstOrDefault(e => e.Name.LocalName == localName)?.Value.Trim() ?? "";
            if (!double.TryParse(Value("ConsumablePercentageLevelRemaining"), System.Globalization.NumberStyles.Number,
                    System.Globalization.CultureInfo.InvariantCulture, out var percent) || percent is < 0 or > 100) continue;
            var family = Value("ConsumableFamilyName");
            var label = Value("ConsumableLabelCode");
            var product = Value("ProductNumber");
            var color = label.ToUpperInvariant() switch { "K" => "Black", "C" => "Cyan", "M" => "Magenta", "Y" => "Yellow", _ => label };
            var name = string.Join(' ', new[] { color, family, product }.Where(v => !string.IsNullOrWhiteSpace(v)).Distinct(StringComparer.OrdinalIgnoreCase));
            result.Add(new Supply(string.IsNullOrWhiteSpace(name) ? "Cartridge" : name, Math.Round(percent, 1)));
        }
        return result;
    }
}
