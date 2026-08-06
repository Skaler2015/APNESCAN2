using System.Diagnostics;
using System.Drawing;
using System.Drawing.Printing;
using System.Net.Http;
using System.Reflection;
using System.Security.Cryptography;
using System.Text.Json;
using Microsoft.Web.WebView2.Core;
using Microsoft.Web.WebView2.WinForms;
using NAPS2.Images;
using NAPS2.Images.Gdi;
using NAPS2.Images.Transforms;
using NAPS2.ImportExport;
using NAPS2.Ocr;
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
    private bool _ocr;

    // One-click update: manifest published to the "latest" GitHub release.
    private const string UpdateManifestUrl =
        "https://github.com/Skaler2015/APNESCAN2/releases/latest/download/update.json";
    private string? _updateUrl;
    private string? _updateSha;

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
        try
        {
            // OCR via the bundled Tesseract executable + English language data.
            _ctx.OcrEngine = TesseractOcrEngine.Bundled(Path.Combine(AppContext.BaseDirectory, "tessdata"));
        }
        catch { /* OCR optional */ }
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
        // WebView2's default user-data folder is created next to the .exe. When
        // the app is installed under Program Files that folder isn't writable,
        // which fails with "Access is denied (E_ACCESSDENIED)". Point it at a
        // writable per-user location instead.
        var userData = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "ApneScan", "WebView2");
        Directory.CreateDirectory(userData);
        _web.CreationProperties = new CoreWebView2CreationProperties { UserDataFolder = userData };

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
        int dpi = 200;
        string color = "color";
        string source = "auto";
        bool on = false;
        try
        {
            using var doc = JsonDocument.Parse(e.TryGetWebMessageAsString() ?? "{}");
            var root = doc.RootElement;
            cmd = root.GetProperty("cmd").GetString() ?? "";
            if (root.TryGetProperty("device", out var d) && d.ValueKind == JsonValueKind.Number) deviceIndex = d.GetInt32();
            if (root.TryGetProperty("dpi", out var dp) && dp.ValueKind == JsonValueKind.Number) dpi = dp.GetInt32();
            if (root.TryGetProperty("color", out var cl) && cl.ValueKind == JsonValueKind.String) color = cl.GetString() ?? "color";
            if (root.TryGetProperty("source", out var sr) && sr.ValueKind == JsonValueKind.String) source = sr.GetString() ?? "auto";
            if (root.TryGetProperty("on", out var onEl) && (onEl.ValueKind == JsonValueKind.True || onEl.ValueKind == JsonValueKind.False)) on = onEl.GetBoolean();
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
                await ScanAsync(deviceIndex, dpi, color, source);
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
            case "import":
                await ImportFilesAsync();
                break;
            case "rotateLeft":
                RotatePage(-90);
                break;
            case "rotateRight":
                RotatePage(90);
                break;
            case "deletePage":
                DeleteLastPage();
                break;
            case "setOcr":
                _ocr = on;
                Status(_ocr ? "OCR on — saved PDFs will have searchable text" : "OCR off");
                break;
            case "checkUpdate":
                await CheckForUpdatesAsync();
                break;
            case "update":
                await RunUpdateAsync();
                break;
        }
    }

    private static BitDepth ParseColor(string c) => c switch
    {
        "gray" => BitDepth.Grayscale,
        "bw" => BitDepth.BlackAndWhite,
        _ => BitDepth.Color
    };

    private static NAPS2.Scan.PaperSource ParseSource(string s) => s switch
    {
        "flatbed" => NAPS2.Scan.PaperSource.Flatbed,
        "feeder" => NAPS2.Scan.PaperSource.Feeder,
        "duplex" => NAPS2.Scan.PaperSource.Duplex,
        _ => NAPS2.Scan.PaperSource.Auto
    };

    private void RotatePage(double degrees)
    {
        if (_pages.Count == 0)
        {
            Status("Nothing to rotate — scan a page first");
            return;
        }
        int idx = _pages.Count - 1;
        _pages[idx] = _pages[idx].WithTransform(new RotationTransform(degrees), disposeSelf: true);
        SendPreview();
        Status($"Rotated page {idx + 1}");
    }

    private void DeleteLastPage()
    {
        if (_pages.Count == 0)
        {
            Status("No pages to delete");
            return;
        }
        var last = _pages[^1];
        _pages.RemoveAt(_pages.Count - 1);
        last.Dispose();
        if (_pages.Count == 0)
        {
            Post(new { type = "cleared" });
            Status("All pages removed");
        }
        else
        {
            SendPreview();
            Status($"Page deleted — {_pages.Count} left");
        }
    }

    private static string CurrentVersion()
    {
        var v = Assembly.GetExecutingAssembly().GetName().Version ?? new Version(1, 0, 0);
        return $"{v.Major}.{v.Minor}.{Math.Max(v.Build, 0)}";
    }

    private async Task CheckForUpdatesAsync()
    {
        var current = CurrentVersion();
        try
        {
            using var http = new HttpClient();
            http.DefaultRequestHeaders.Add("User-Agent", "ApneScan");
            var json = await http.GetStringAsync(UpdateManifestUrl);
            using var doc = JsonDocument.Parse(json);
            var curVer = new Version(current);
            foreach (var rel in doc.RootElement.GetProperty("versions").EnumerateArray())
            {
                var name = rel.GetProperty("name").GetString();
                if (name == null || !Version.TryParse(name, out var v) || v <= curVer)
                {
                    continue;
                }
                var exe = rel.GetProperty("files").GetProperty("exe");
                _updateUrl = exe.GetProperty("url").GetString();
                _updateSha = exe.TryGetProperty("sha256", out var s) ? s.GetString() : null;
                Post(new { type = "update", version = name, current });
                return;
            }
            Post(new { type = "noupdate", current });
        }
        catch
        {
            // Offline or no release yet — just report the current version.
            Post(new { type = "noupdate", current });
        }
    }

    private async Task RunUpdateAsync()
    {
        if (string.IsNullOrEmpty(_updateUrl))
        {
            return;
        }
        try
        {
            Status("Downloading update…");
            using var http = new HttpClient();
            http.DefaultRequestHeaders.Add("User-Agent", "ApneScan");
            var bytes = await http.GetByteArrayAsync(_updateUrl);

            // Verify the download against the manifest's SHA-256 (base64) before running it.
            if (!string.IsNullOrEmpty(_updateSha))
            {
                var b64 = Convert.ToBase64String(SHA256.HashData(bytes));
                if (b64 != _updateSha)
                {
                    Status("Update failed: the download did not verify.");
                    return;
                }
            }

            var path = Path.Combine(Path.GetTempPath(), "ApneScan-Setup.exe");
            await File.WriteAllBytesAsync(path, bytes);

            Status("Installing update…");
            Process.Start(new ProcessStartInfo
            {
                FileName = path,
                Arguments = "/SILENT /CLOSEAPPLICATIONS",
                UseShellExecute = true
            });
            Application.Exit();
        }
        catch (Exception ex)
        {
            Status("Update error: " + ex.Message);
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

    private async Task ScanAsync(int deviceIndex, int dpi, string color, string source)
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
                PaperSource = ParseSource(source),
                // Scan the scanner's full area (the driver clamps to the device
                // maximum) so a page of any size is captured completely.
                PageSize = new PageSize(14m, 22m, PageSizeUnit.Inch),
                BitDepth = ParseColor(color),
                Dpi = dpi > 0 ? dpi : 200
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
            Status(_ocr ? "Saving PDF with OCR (this can take a moment)…" : "Saving PDF…");
            var exporter = new PdfExporter(_ctx);
            var ocrParams = _ocr ? new OcrParams("eng") : null;
            await exporter.Export(sfd.FileName, _pages, ocrParams: ocrParams);
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

    private async Task ImportFilesAsync()
    {
        using var ofd = new OpenFileDialog
        {
            Multiselect = true,
            Title = "Import files",
            Filter = "Documents & images (*.pdf;*.jpg;*.jpeg;*.png;*.tif;*.tiff;*.bmp)" +
                     "|*.pdf;*.jpg;*.jpeg;*.png;*.tif;*.tiff;*.bmp|All files (*.*)|*.*"
        };
        if (ofd.ShowDialog(this) != DialogResult.OK)
        {
            return;
        }
        try
        {
            Status("Importing…");
            var imageImporter = new ImageImporter(_ctx);
            var pdfImporter = new PdfImporter(_ctx);
            int added = 0;
            foreach (var file in ofd.FileNames)
            {
                var ext = Path.GetExtension(file).ToLowerInvariant();
                var importer = ext == ".pdf" ? pdfImporter.Import(file) : imageImporter.Import(file);
                await foreach (var img in importer)
                {
                    _pages.Add(img);
                    added++;
                }
            }
            if (added == 0)
            {
                Status("Nothing was imported");
                return;
            }
            SendPreview();
            Status($"Imported {added} page(s) — {_pages.Count} total");
        }
        catch (Exception ex)
        {
            Status("Import error: " + ex.Message);
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
