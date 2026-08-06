using System.Drawing;
using System.Drawing.Printing;
using System.Text.Json;
using Microsoft.Web.WebView2.Core;
using Microsoft.Web.WebView2.WinForms;
using NAPS2.Images;
using NAPS2.Images.Gdi;
using NAPS2.Images.Transforms;
using NAPS2.Pdf;
using NAPS2.Scan;

namespace ApneScan.Studio;

/// <summary>
/// Hosts the ApneScan web UI inside a WebView2 control and bridges it to the
/// real NAPS2.Sdk scanning engine. The HTML sends JSON commands via
/// window.chrome.webview.postMessage; this form runs them (scan, save PDF,
/// print, clear) and posts status/preview/page-count back to the page.
/// </summary>
public class MainForm : Form
{
    private readonly WebView2 _web = new() { Dock = DockStyle.Fill };
    private readonly ScanningContext _ctx = new(new GdiImageContext());
    private readonly List<ProcessedImage> _pages = new();
    private List<ScanDevice> _devices = new();
    private bool _busy;

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
        FormClosed += (_, _) =>
        {
            foreach (var p in _pages) p.Dispose();
            _ctx.Dispose();
        };
    }

    private async Task InitAsync()
    {
        await _web.EnsureCoreWebView2Async();
        var core = _web.CoreWebView2;
        core.Settings.AreDefaultContextMenusEnabled = false;
        core.Settings.IsStatusBarEnabled = false;
        core.WebMessageReceived += OnMessage;

        var htmlPath = Path.Combine(AppContext.BaseDirectory, "ui.html");
        core.NavigateToString(File.ReadAllText(htmlPath));
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
            case "savePdf":
                await SavePdfAsync();
                break;
            case "print":
                PrintPages();
                break;
            case "clear":
                ClearPages();
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
            Status(_devices.Count == 0 ? "No scanner found" : $"{_devices[0].Name} · Ready");
        }
        catch (Exception ex)
        {
            Status("Error: " + ex.Message);
        }
    }

    private async Task ScanAsync(int deviceIndex)
    {
        if (_busy)
        {
            return;
        }
        if (_devices.Count == 0)
        {
            Status("No scanner found");
            return;
        }
        if (deviceIndex < 0 || deviceIndex >= _devices.Count)
        {
            deviceIndex = 0;
        }

        _busy = true;
        try
        {
            Status("Scanning…");
            var controller = new ScanController(_ctx);
            var options = new ScanOptions
            {
                Device = _devices[deviceIndex],
                PaperSource = PaperSource.Auto,
                // Scan the scanner's full area (the driver clamps to the device
                // maximum) so a page of any size is captured completely.
                PageSize = new PageSize(14m, 22m, PageSizeUnit.Inch),
                Dpi = 200
            };

            int added = 0;
            await foreach (var image in controller.Scan(options))
            {
                _pages.Add(image);
                added++;
            }

            if (added == 0)
            {
                Status("Nothing was scanned");
                return;
            }

            SendPreview();
            Status($"{_pages.Count} page(s) ready. Use Save or Print.");
        }
        catch (Exception ex)
        {
            Status("Scan error: " + ex.Message);
        }
        finally
        {
            _busy = false;
        }
    }

    private async Task SavePdfAsync()
    {
        if (_pages.Count == 0)
        {
            Status("Nothing to save — scan a page first");
            return;
        }
        using var sfd = new SaveFileDialog
        {
            Filter = "PDF document (*.pdf)|*.pdf",
            FileName = "scan.pdf"
        };
        if (sfd.ShowDialog(this) != DialogResult.OK)
        {
            return;
        }
        try
        {
            Status("Saving PDF…");
            var exporter = new PdfExporter(_ctx);
            await exporter.Export(sfd.FileName, _pages);
            Post(new { type = "done", path = sfd.FileName });
            Status("Saved: " + sfd.FileName);
        }
        catch (Exception ex)
        {
            Status("Save error: " + ex.Message);
        }
    }

    private void PrintPages()
    {
        if (_pages.Count == 0)
        {
            Status("Nothing to print — scan a page first");
            return;
        }
        try
        {
            var doc = new PrintDocument();
            int i = 0;
            doc.PrintPage += (_, e) =>
            {
                var image = _pages[i].Render();
                try
                {
                    // MarginBounds is the printable area inside the printer's
                    // hardware margins, so the sides of the scan are not clipped.
                    var pb = e.MarginBounds;
                    if (Math.Sign(image.Width - image.Height) != Math.Sign(pb.Width - pb.Height))
                    {
                        image = image.PerformTransform(new RotationTransform(90));
                    }
                    // AsBitmap() exposes the underlying GDI bitmap; it is freed
                    // when the rendered image is disposed below, so don't dispose
                    // it separately here.
                    var bmp = image.AsBitmap();
                    double scale = Math.Min((double) pb.Width / bmp.Width, (double) pb.Height / bmp.Height);
                    int w = (int) Math.Round(bmp.Width * scale);
                    int h = (int) Math.Round(bmp.Height * scale);
                    int x = pb.Left + (pb.Width - w) / 2;
                    int y = pb.Top + (pb.Height - h) / 2;
                    e.Graphics!.DrawImage(bmp, new Rectangle(x, y, w, h));
                }
                finally
                {
                    image.Dispose();
                }
                e.HasMorePages = ++i < _pages.Count;
            };

            using var pd = new PrintDialog { Document = doc, UseEXDialog = true };
            if (pd.ShowDialog(this) == DialogResult.OK)
            {
                doc.PrinterSettings = pd.PrinterSettings;
                Status("Printing…");
                doc.Print();
                Status($"Printed {_pages.Count} page(s)");
            }
        }
        catch (Exception ex)
        {
            Status("Print error: " + ex.Message);
        }
    }

    private void ClearPages()
    {
        foreach (var p in _pages)
        {
            p.Dispose();
        }
        _pages.Clear();
        Post(new { type = "cleared" });
        Status("Cleared. Ready to scan.");
    }

    private void SendPreview()
    {
        try
        {
            var previewPath = Path.Combine(Path.GetTempPath(), "apnescan_preview.png");
            _pages[^1].Save(previewPath);
            var b64 = Convert.ToBase64String(File.ReadAllBytes(previewPath));
            Post(new { type = "preview", dataUrl = "data:image/png;base64," + b64, pages = _pages.Count });
        }
        catch { /* preview is best-effort */ }
    }

    private void Status(string text) => Post(new { type = "status", text, pages = _pages.Count });

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
