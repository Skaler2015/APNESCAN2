using System.Text.Json;
using Microsoft.Web.WebView2.Core;
using Microsoft.Web.WebView2.WinForms;
using NAPS2.Images.Gdi;
using NAPS2.Pdf;
using NAPS2.Scan;

namespace ApneScan.Studio;

/// <summary>
/// Hosts the ApneScan web UI inside a WebView2 control and bridges it to the
/// real NAPS2.Sdk scanning engine. The HTML sends JSON commands via
/// window.chrome.webview.postMessage; this form runs them (device list, scan,
/// save PDF) and posts results/status back to the page.
/// </summary>
public class MainForm : Form
{
    private readonly WebView2 _web = new() { Dock = DockStyle.Fill };
    private readonly ScanningContext _ctx = new(new GdiImageContext());
    private List<ScanDevice> _devices = new();

    public MainForm()
    {
        Text = "ApneScan";
        Width = 1280;
        Height = 820;
        StartPosition = FormStartPosition.CenterScreen;
        WindowState = FormWindowState.Maximized;
        try
        {
            Icon = new Icon(Path.Combine(AppContext.BaseDirectory, "favicon.ico"));
        }
        catch { /* icon optional */ }
        Controls.Add(_web);
        Load += async (_, _) => await InitAsync();
        FormClosed += (_, _) => _ctx.Dispose();
    }

    private async Task InitAsync()
    {
        await _web.EnsureCoreWebView2Async();
        var core = _web.CoreWebView2;
        core.Settings.AreDefaultContextMenusEnabled = false;
        core.Settings.IsStatusBarEnabled = false;
        core.WebMessageReceived += OnMessage;

        var htmlPath = Path.Combine(AppContext.BaseDirectory, "ui.html");
        var html = File.ReadAllText(htmlPath);
        core.NavigateToString(html);
        // The page asks for the device list itself once it has loaded.
    }

    private async void OnMessage(object? sender, CoreWebView2WebMessageReceivedEventArgs e)
    {
        string cmd;
        int deviceIndex = 0;
        try
        {
            using var doc = JsonDocument.Parse(e.TryGetWebMessageAsString() ?? "{}");
            cmd = doc.RootElement.GetProperty("cmd").GetString() ?? "";
            if (doc.RootElement.TryGetProperty("device", out var d) && d.ValueKind == JsonValueKind.Number)
            {
                deviceIndex = d.GetInt32();
            }
        }
        catch
        {
            return;
        }

        switch (cmd)
        {
            case "getDevices":
                await SendDevicesAsync();
                break;
            case "scan":
                await ScanAsync(deviceIndex);
                break;
        }
    }

    private async Task SendDevicesAsync()
    {
        try
        {
            Status("Looking for scanners…");
            var controller = new ScanController(_ctx);
            _devices = await controller.GetDeviceList();
            Post(new { type = "devices", devices = _devices.Select(d => d.Name).ToArray() });
            Status(_devices.Count == 0
                ? "No scanner found"
                : $"{_devices[0].Name} · Ready");
        }
        catch (Exception ex)
        {
            Status("Error: " + ex.Message);
        }
    }

    private async Task ScanAsync(int deviceIndex)
    {
        if (_devices.Count == 0)
        {
            Status("No scanner selected");
            return;
        }
        if (deviceIndex < 0 || deviceIndex >= _devices.Count)
        {
            deviceIndex = 0;
        }

        try
        {
            Status("Scanning…");
            var controller = new ScanController(_ctx);
            var options = new ScanOptions
            {
                Device = _devices[deviceIndex],
                PaperSource = PaperSource.Auto,
                PageSize = PageSize.A4,
                Dpi = 200
            };

            var images = await controller.Scan(options).ToListAsync();
            if (images.Count == 0)
            {
                Status("Nothing was scanned");
                return;
            }

            // Send the first page as a preview image to the UI.
            try
            {
                var previewPath = Path.Combine(Path.GetTempPath(), "apnescan_preview.png");
                images[0].Save(previewPath);
                var b64 = Convert.ToBase64String(await File.ReadAllBytesAsync(previewPath));
                Post(new { type = "preview", dataUrl = "data:image/png;base64," + b64, pages = images.Count });
            }
            catch { /* preview is best-effort */ }

            Status($"Scanned {images.Count} page(s). Choose where to save the PDF…");

            using var sfd = new SaveFileDialog
            {
                Filter = "PDF document (*.pdf)|*.pdf",
                FileName = "scan.pdf"
            };
            if (sfd.ShowDialog(this) == DialogResult.OK)
            {
                Status("Saving PDF…");
                var exporter = new PdfExporter(_ctx);
                await exporter.Export(sfd.FileName, images);
                Post(new { type = "done", path = sfd.FileName });
                Status("Saved: " + sfd.FileName);
            }
            else
            {
                Status("Ready");
            }
        }
        catch (Exception ex)
        {
            Status("Scan error: " + ex.Message);
        }
    }

    private void Status(string text) => Post(new { type = "status", text });

    private void Post(object payload)
    {
        if (_web.CoreWebView2 == null)
        {
            return;
        }
        var json = JsonSerializer.Serialize(payload);
        if (InvokeRequired)
        {
            BeginInvoke(() => _web.CoreWebView2.PostWebMessageAsJson(json));
        }
        else
        {
            _web.CoreWebView2.PostWebMessageAsJson(json);
        }
    }
}
