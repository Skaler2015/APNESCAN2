using System.Diagnostics;
using System.Drawing;
using System.Drawing.Printing;
using System.Net;
using System.Net.Http;
using System.Net.NetworkInformation;
using System.Net.Sockets;
using System.Reflection;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Threading;
using Microsoft.Web.WebView2.Core;
using Microsoft.Web.WebView2.WinForms;
using QRCoder;
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
    private int _selected = -1;
    private readonly List<List<ProcessedImage>> _undo = new();
    // Auto-detected document name per page (from OCR of the page's top area).
    private readonly List<string> _pageNames = new();
    private bool _naming;

    private int Sel() => (_selected >= 0 && _selected < _pages.Count) ? _selected : _pages.Count - 1;

    private void PushUndo()
    {
        try
        {
            _undo.Add(_pages.Select(p => p.Clone()).ToList());
            while (_undo.Count > 8)
            {
                foreach (var p in _undo[0]) p.Dispose();
                _undo.RemoveAt(0);
            }
        }
        catch { /* undo is best-effort */ }
    }

    private async Task UndoAsync()
    {
        if (_undo.Count == 0)
        {
            Status("Nothing to undo");
            return;
        }
        var snap = _undo[^1];
        _undo.RemoveAt(_undo.Count - 1);
        foreach (var p in _pages) p.Dispose();
        _pages.Clear();
        _pages.AddRange(snap);
        _pageNames.Clear();
        _selected = _pages.Count - 1;
        await RefreshAsync(false);
        _ = AutoNameAsync();
        Status("Undone");
    }

    // One-click update: manifest published to the "latest" GitHub release.
    private const string UpdateManifestUrl =
        "https://github.com/Skaler2015/APNESCAN2/releases/latest/download/update.json";
    private string? _updateUrl;
    private string? _updateSha;

    // Phone-to-PC: a tiny HTTP server phones on the same WiFi upload photos to.
    private TcpListener? _phoneServer;
    private const int PhonePort = 8765;

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
            StopPhoneServer();
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

        // Auto-grant camera/microphone so in-page photo capture works.
        core.PermissionRequested += (_, e) =>
        {
            if (e.PermissionKind == CoreWebView2PermissionKind.Camera ||
                e.PermissionKind == CoreWebView2PermissionKind.Microphone)
            {
                e.State = CoreWebView2PermissionState.Allow;
            }
        };

        // Serve the UI from a virtual https host so it runs in a secure context
        // (getUserMedia / camera only works on a secure origin).
        core.SetVirtualHostNameToFolderMapping(
            "apnescan.app", AppContext.BaseDirectory, CoreWebView2HostResourceAccessKind.Allow);
        core.Navigate("https://apnescan.app/ui.html");
    }

    private async void OnMessage(object? sender, CoreWebView2WebMessageReceivedEventArgs e)
    {
        string cmd;
        int deviceIndex = 0;
        int dpi = 200;
        string color = "color";
        string source = "auto";
        bool on = false;
        string dataUrl = "";
        int index = -1;
        double x0 = 0, y0 = 0, x1 = 0, y1 = 0;
        string filePath = "";
        string name = "";
        string format = "";
        string deviceName = "";
        try
        {
            using var doc = JsonDocument.Parse(e.TryGetWebMessageAsString() ?? "{}");
            var root = doc.RootElement;
            cmd = root.GetProperty("cmd").GetString() ?? "";
            if (root.TryGetProperty("path", out var fp) && fp.ValueKind == JsonValueKind.String) filePath = fp.GetString() ?? "";
            if (root.TryGetProperty("name", out var nm) && nm.ValueKind == JsonValueKind.String) name = nm.GetString() ?? "";
            if (root.TryGetProperty("format", out var ft) && ft.ValueKind == JsonValueKind.String) format = ft.GetString() ?? "";
            if (root.TryGetProperty("deviceName", out var dn) && dn.ValueKind == JsonValueKind.String) deviceName = dn.GetString() ?? "";
            if (root.TryGetProperty("device", out var d) && d.ValueKind == JsonValueKind.Number) deviceIndex = d.GetInt32();
            if (root.TryGetProperty("index", out var ix) && ix.ValueKind == JsonValueKind.Number) index = ix.GetInt32();
            if (root.TryGetProperty("x0", out var vx0) && vx0.ValueKind == JsonValueKind.Number) x0 = vx0.GetDouble();
            if (root.TryGetProperty("y0", out var vy0) && vy0.ValueKind == JsonValueKind.Number) y0 = vy0.GetDouble();
            if (root.TryGetProperty("x1", out var vx1) && vx1.ValueKind == JsonValueKind.Number) x1 = vx1.GetDouble();
            if (root.TryGetProperty("y1", out var vy1) && vy1.ValueKind == JsonValueKind.Number) y1 = vy1.GetDouble();
            if (root.TryGetProperty("dpi", out var dp) && dp.ValueKind == JsonValueKind.Number) dpi = dp.GetInt32();
            if (root.TryGetProperty("color", out var cl) && cl.ValueKind == JsonValueKind.String) color = cl.GetString() ?? "color";
            if (root.TryGetProperty("source", out var sr) && sr.ValueKind == JsonValueKind.String) source = sr.GetString() ?? "auto";
            if (root.TryGetProperty("on", out var onEl) && (onEl.ValueKind == JsonValueKind.True || onEl.ValueKind == JsonValueKind.False)) on = onEl.GetBoolean();
            if (root.TryGetProperty("dataUrl", out var du) && du.ValueKind == JsonValueKind.String) dataUrl = du.GetString() ?? "";
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
            case "addPhoto":
                await AddPhotoAsync(dataUrl);
                break;
            case "share":
                await SharePdfAsync();
                break;
            case "getText":
                await GetTextAsync();
                break;
            case "getAnalytics":
                SendAnalytics();
                break;
            case "undo":
                await UndoAsync();
                break;
            case "saveImages":
                await SaveImagesAsync(format);
                break;
            case "openUrl":
                OpenUrl(filePath);
                break;
            case "getStorage":
                SendStorage();
                break;
            case "getProfiles":
                SendProfiles();
                break;
            case "saveProfile":
                SaveProfile(name, dpi, color, source, on, deviceName);
                break;
            case "deleteProfile":
                DeleteProfile(name);
                break;
            case "getSettings":
                SendSettings();
                break;
            case "saveSettings":
                SaveSettings(dpi, color, source, on, deviceName);
                break;
            case "getHistory":
                SendHistory();
                break;
            case "openFile":
                OpenFile(filePath);
                break;
            case "listFolder":
                SendFolder(filePath);
                break;
            case "startPhone":
                StartPhoneServer();
                break;
            case "stopPhone":
                StopPhoneServer();
                break;
            case "rotateLeft":
                PushUndo();
                await RotatePageAsync(-90);
                break;
            case "rotateRight":
                PushUndo();
                await RotatePageAsync(90);
                break;
            case "deletePage":
                PushUndo();
                await DeletePageAsync();
                break;
            case "select":
                _selected = index;
                SendPreview();
                break;
            case "moveLeft":
                PushUndo();
                await MovePageAsync(-1);
                break;
            case "moveRight":
                PushUndo();
                await MovePageAsync(1);
                break;
            case "crop":
                PushUndo();
                await CropPageAsync(x0, y0, x1, y1);
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

    private async Task RotatePageAsync(double degrees)
    {
        int idx = Sel();
        if (idx < 0)
        {
            Status("Nothing to rotate — scan a page first");
            return;
        }
        _pages[idx] = _pages[idx].WithTransform(new RotationTransform(degrees), disposeSelf: true);
        await RefreshAsync(false);
        Status($"Rotated page {idx + 1}");
    }

    private async Task DeletePageAsync()
    {
        int idx = Sel();
        if (idx < 0)
        {
            Status("No pages to delete");
            return;
        }
        var pg = _pages[idx];
        _pages.RemoveAt(idx);
        pg.Dispose();
        if (idx < _pageNames.Count) _pageNames.RemoveAt(idx);
        if (_selected >= _pages.Count)
        {
            _selected = _pages.Count - 1;
        }
        if (_pages.Count == 0)
        {
            Post(new { type = "cleared" });
            Status("All pages removed");
        }
        else
        {
            await RefreshAsync(false);
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

            // The rolling release is briefly unavailable while a new build
            // republishes it, so retry a few times on a transient failure.
            byte[]? bytes = null;
            for (int attempt = 1; attempt <= 3; attempt++)
            {
                try
                {
                    bytes = await http.GetByteArrayAsync(_updateUrl);
                    break;
                }
                catch when (attempt < 3)
                {
                    Status($"Download busy, retrying ({attempt}/3)…");
                    await Task.Delay(2500 * attempt);
                }
            }
            if (bytes == null)
            {
                Status("Update is being published right now — please try again in a minute.");
                return;
            }

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

            Status("Updating in the background… ApneScan will reopen automatically.");
            // /VERYSILENT hides the installer completely (no windows, no prompts);
            // the per-user install needs no UAC, so the whole update runs in the
            // background. The installer's [Run] entry relaunches ApneScan once the
            // new files are in place, so the app just reopens on the new version.
            Process.Start(new ProcessStartInfo
            {
                FileName = path,
                Arguments = "/VERYSILENT /SUPPRESSMSGBOXES /NORESTART /CLOSEAPPLICATIONS",
                UseShellExecute = true,
                CreateNoWindow = true,
                WindowStyle = ProcessWindowStyle.Hidden
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
            PushUndo();
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

            await RefreshAsync(true);
            _ = AutoNameAsync();
            Bump("scan", added);
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
        var suggested = _pageNames.FirstOrDefault(n => !string.IsNullOrWhiteSpace(n));
        using var sfd = new SaveFileDialog
        {
            Filter = "PDF document (*.pdf)|*.pdf",
            FileName = (string.IsNullOrWhiteSpace(suggested) ? "scan" : SanitizeFileName(suggested)) + ".pdf"
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
            AddHistory(sfd.FileName, _pages.Count);
            Bump("pdf", 1);
            Post(new { type = "done", path = sfd.FileName });
            Status("Saved: " + sfd.FileName);
        }
        catch (Exception ex)
        {
            Status("Save error: " + ex.Message);
        }
    }

    private async Task SharePdfAsync()
    {
        if (_pages.Count == 0)
        {
            Status("Nothing to share — scan or import first");
            return;
        }
        try
        {
            Status("Preparing PDF to share…");
            var path = Path.Combine(Path.GetTempPath(),
                "ApneScan_" + Guid.NewGuid().ToString("N")[..8] + ".pdf");
            var exporter = new PdfExporter(_ctx);
            var ocrParams = _ocr ? new OcrParams("eng") : null;
            await exporter.Export(path, _pages, ocrParams: ocrParams);

            // Open Explorer with the PDF selected so the user can right-click it
            // and choose Windows "Share" (WhatsApp, Mail, etc.).
            Process.Start(new ProcessStartInfo
            {
                FileName = "explorer.exe",
                Arguments = $"/select,\"{path}\"",
                UseShellExecute = true
            });
            Status("PDF ready — right-click it and choose Share (WhatsApp, Email…)");
        }
        catch (Exception ex)
        {
            Status("Share error: " + ex.Message);
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
                Bump("print", 1);
                Status($"Printed {_pages.Count} page(s)");
            }
        }
        catch (Exception ex)
        {
            Status("Print error: " + ex.Message);
        }
    }

    private async Task AddPhotoAsync(string dataUrl)
    {
        if (string.IsNullOrEmpty(dataUrl))
        {
            return;
        }
        try
        {
            var comma = dataUrl.IndexOf(',');
            var b64 = comma >= 0 ? dataUrl[(comma + 1)..] : dataUrl;
            var bytes = Convert.FromBase64String(b64);
            var temp = Path.Combine(Path.GetTempPath(), "apnescan_cam_" + Guid.NewGuid().ToString("N") + ".jpg");
            await File.WriteAllBytesAsync(temp, bytes);
            PushUndo();
            var importer = new ImageImporter(_ctx);
            int added = 0;
            await foreach (var img in importer.Import(temp))
            {
                _pages.Add(img);
                added++;
            }
            try { File.Delete(temp); } catch { /* temp cleanup best-effort */ }
            if (added > 0)
            {
                await RefreshAsync(true);
                _ = AutoNameAsync();
                Bump("camera", 1);
                Status($"Photo added — {_pages.Count} page(s)");
            }
        }
        catch (Exception ex)
        {
            Status("Camera error: " + ex.Message);
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
            PushUndo();
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
            await RefreshAsync(true);
            _ = AutoNameAsync();
            Bump("import", added);
            Status($"Imported {added} page(s) — {_pages.Count} total");
        }
        catch (Exception ex)
        {
            Status("Import error: " + ex.Message);
        }
    }

    private void StartPhoneServer()
    {
        var url = $"http://{GetLocalIp()}:{PhonePort}/";
        try
        {
            if (_phoneServer == null)
            {
                _phoneServer = new TcpListener(IPAddress.Any, PhonePort);
                _phoneServer.Start();
                _ = Task.Run(PhoneServerLoop);
            }
            var gen = new QRCodeGenerator();
            var data = gen.CreateQrCode(url, QRCodeGenerator.ECCLevel.M);
            var png = new PngByteQRCode(data).GetGraphic(8);
            var qr = "data:image/png;base64," + Convert.ToBase64String(png);
            Post(new { type = "phone", qr, url });
            Status("Phone-to-PC ready — scan the QR with your phone (same WiFi)");
        }
        catch (Exception ex)
        {
            Post(new { type = "phone", qr = (string?) null, url });
            Status("Phone-to-PC error: " + ex.Message);
        }
    }

    private void StopPhoneServer()
    {
        try { _phoneServer?.Stop(); } catch { /* ignore */ }
        _phoneServer = null;
    }

    private async Task PhoneServerLoop()
    {
        var server = _phoneServer;
        while (server != null && ReferenceEquals(server, _phoneServer))
        {
            TcpClient client;
            try { client = await server.AcceptTcpClientAsync(); }
            catch { break; }
            _ = Task.Run(() => HandlePhoneClient(client));
        }
    }

    private async Task HandlePhoneClient(TcpClient client)
    {
        try
        {
            using (client)
            {
                var stream = client.GetStream();
                var header = new List<byte>();
                var one = new byte[1];
                while (true)
                {
                    int r = await stream.ReadAsync(one.AsMemory(0, 1));
                    if (r == 0) return;
                    header.Add(one[0]);
                    int n = header.Count;
                    if (n >= 4 && header[n - 4] == 13 && header[n - 3] == 10 && header[n - 2] == 13 && header[n - 1] == 10) break;
                    if (n > 32768) return;
                }
                var headerText = Encoding.ASCII.GetString(header.ToArray());
                var firstLine = headerText.Split("\r\n")[0];
                var parts = firstLine.Split(' ');
                var method = parts.Length > 0 ? parts[0] : "";
                var pathReq = parts.Length > 1 ? parts[1] : "/";

                if (method == "POST" && pathReq.StartsWith("/upload"))
                {
                    int len = ParseContentLength(headerText);
                    if (len <= 0 || len > 40 * 1024 * 1024)
                    {
                        await WriteResponse(stream, "400 Bad Request", "text/plain", Encoding.UTF8.GetBytes("bad"));
                        return;
                    }
                    var buf = new byte[len];
                    int read = 0;
                    while (read < len)
                    {
                        int r = await stream.ReadAsync(buf.AsMemory(read, len - read));
                        if (r == 0) break;
                        read += r;
                    }
                    var temp = Path.Combine(Path.GetTempPath(), "apnescan_phone_" + Guid.NewGuid().ToString("N")[..8] + ".jpg");
                    await File.WriteAllBytesAsync(temp, read == len ? buf : buf[..read]);
                    if (IsHandleCreated)
                    {
                        BeginInvoke(new Action(() => { _ = AddPhoneFileAsync(temp); }));
                    }
                    await WriteResponse(stream, "200 OK", "text/plain", Encoding.UTF8.GetBytes("OK"));
                }
                else
                {
                    await WriteResponse(stream, "200 OK", "text/html; charset=utf-8", Encoding.UTF8.GetBytes(PhoneUploadPage()));
                }
            }
        }
        catch { /* per-connection errors are non-fatal */ }
    }

    private async Task AddPhoneFileAsync(string path)
    {
        try
        {
            PushUndo();
            var importer = new ImageImporter(_ctx);
            int added = 0;
            await foreach (var img in importer.Import(path))
            {
                _pages.Add(img);
                added++;
            }
            try { File.Delete(path); } catch { /* best-effort */ }
            if (added > 0)
            {
                await RefreshAsync(true);
                _ = AutoNameAsync();
                Bump("camera", added);
                Status($"Photo received from phone — {_pages.Count} page(s)");
                Post(new { type = "phonePhoto", pages = _pages.Count });
            }
        }
        catch (Exception ex)
        {
            Status("Phone receive error: " + ex.Message);
        }
    }

    private static async Task WriteResponse(NetworkStream stream, string status, string contentType, byte[] body)
    {
        var head = $"HTTP/1.1 {status}\r\nContent-Type: {contentType}\r\nContent-Length: {body.Length}\r\n" +
                   "Connection: close\r\nAccess-Control-Allow-Origin: *\r\n\r\n";
        await stream.WriteAsync(Encoding.ASCII.GetBytes(head));
        await stream.WriteAsync(body);
        await stream.FlushAsync();
    }

    private static int ParseContentLength(string header)
    {
        foreach (var line in header.Split("\r\n"))
        {
            if (line.StartsWith("Content-Length:", StringComparison.OrdinalIgnoreCase)
                && int.TryParse(line["Content-Length:".Length..].Trim(), out var v))
            {
                return v;
            }
        }
        return 0;
    }

    private static string GetLocalIp()
    {
        try
        {
            foreach (var ni in NetworkInterface.GetAllNetworkInterfaces())
            {
                if (ni.OperationalStatus != OperationalStatus.Up ||
                    ni.NetworkInterfaceType == NetworkInterfaceType.Loopback)
                {
                    continue;
                }
                foreach (var ua in ni.GetIPProperties().UnicastAddresses)
                {
                    if (ua.Address.AddressFamily == AddressFamily.InterNetwork)
                    {
                        var s = ua.Address.ToString();
                        if (!s.StartsWith("169.254") && s != "127.0.0.1")
                        {
                            return s;
                        }
                    }
                }
            }
        }
        catch { /* fall through */ }
        return "127.0.0.1";
    }

    private static string PhoneUploadPage() => """
<!doctype html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><title>ApneScan</title>
<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:0;background:#eef0f6;color:#1f2430}
.card{background:#fff;margin:18px;padding:24px;border-radius:16px;box-shadow:0 6px 18px rgba(30,30,60,.1);text-align:center}
h1{color:#6d28d9;margin:0 0 6px}p{color:#6b7280}
.btn{display:block;width:100%;box-sizing:border-box;padding:16px;margin:14px 0;border-radius:12px;background:#6d28d9;color:#fff;font-size:18px;font-weight:700;border:0}
input{display:none}#log{color:#16a34a;font-weight:700;margin-top:10px;min-height:20px}</style></head>
<body><div class="card"><h1>ApneScan</h1><p>Send photos to your PC</p>
<label class="btn">Take / choose photo<input type="file" accept="image/*" multiple capture="environment" id="f"></label>
<div id="log"></div></div>
<script>
var f=document.getElementById('f'),log=document.getElementById('log');
f.addEventListener('change',function(){var files=f.files,done=0,total=files.length;log.textContent='Sending...';
for(var i=0;i<files.length;i++){(function(file){fetch('/upload',{method:'POST',body:file}).then(function(){done++;log.textContent='Sent '+done+'/'+total+' photo(s) to PC';}).catch(function(){log.textContent='Failed - same WiFi as PC?';});})(files[i]);}});
</script></body></html>
""";

    private void ClearPages()
    {
        if (_pages.Count > 0) PushUndo();
        foreach (var p in _pages)
        {
            p.Dispose();
        }
        _pages.Clear();
        _pageNames.Clear();
        Post(new { type = "cleared" });
        Status("Cleared. Ready to scan.");
    }

    private void SendPreview()
    {
        try
        {
            if (_pages.Count == 0)
            {
                Post(new { type = "cleared" });
                return;
            }
            var i = Sel();
            var previewPath = Path.Combine(Path.GetTempPath(), "apnescan_preview.png");
            _pages[i].Save(previewPath);
            var b64 = Convert.ToBase64String(File.ReadAllBytes(previewPath));
            Post(new { type = "preview", dataUrl = "data:image/png;base64," + b64, pages = _pages.Count, index = i });
        }
        catch { /* preview is best-effort */ }
    }

    private async Task SendThumbsAsync()
    {
        try
        {
            var renderer = new ThumbnailRenderer(_ctx.ImageContext);
            var thumbs = new List<string>();
            foreach (var p in _pages)
            {
                using var thumb = await renderer.Render(p, 150);
                var tmp = Path.Combine(Path.GetTempPath(), "apnescan_th_" + Guid.NewGuid().ToString("N")[..8] + ".png");
                thumb.Save(tmp, ImageFileFormat.Png);
                thumbs.Add("data:image/png;base64," + Convert.ToBase64String(await File.ReadAllBytesAsync(tmp)));
                try { File.Delete(tmp); } catch { /* best-effort */ }
            }
            SyncNames();
            Post(new { type = "pages", thumbs, selected = Sel(), count = _pages.Count, names = _pageNames.ToArray() });
        }
        catch { /* thumbnails best-effort */ }
    }

    // Keep the per-page name list the same length as the page list.
    private void SyncNames()
    {
        while (_pageNames.Count < _pages.Count) _pageNames.Add("");
        while (_pageNames.Count > _pages.Count) _pageNames.RemoveAt(_pageNames.Count - 1);
    }

    // Read the top of each page with OCR and use the first strong line as the
    // document's name. Runs in the background; names stream back one by one.
    private async Task AutoNameAsync()
    {
        if (_ctx.OcrEngine == null || _naming) return;
        _naming = true;
        try
        {
            SyncNames();
            for (int i = 0; i < _pages.Count; i++)
            {
                if (i < _pageNames.Count && !string.IsNullOrEmpty(_pageNames[i])) continue;
                string name = "";
                try { name = await DetectNameAsync(_pages[i]); } catch { /* per-page best-effort */ }
                if (i < _pageNames.Count) _pageNames[i] = name;
                Post(new { type = "pageName", index = i, name });
            }
        }
        finally { _naming = false; }
    }

    private async Task<string> DetectNameAsync(ProcessedImage page)
    {
        int w, h;
        using (var r = page.Render()) { w = r.Width; h = r.Height; }
        // Keep only the top ~38% (where a title/letterhead usually is) — faster
        // and more accurate than OCR-ing the whole page.
        var top = page.WithTransform(
            new CropTransform(0, 0, 0, (int) (h * 0.62), w, h), disposeSelf: false);
        try
        {
            var tmp = System.IO.Path.Combine(System.IO.Path.GetTempPath(),
                "apnescan_name_" + Guid.NewGuid().ToString("N")[..8] + ".png");
            top.Save(tmp);
            var result = await _ctx.OcrEngine!.ProcessImage(_ctx, tmp, new OcrParams("eng"), CancellationToken.None);
            try { File.Delete(tmp); } catch { /* best-effort */ }
            return CleanName(result);
        }
        finally { top.Dispose(); }
    }

    private static string CleanName(OcrResult? result)
    {
        if (result == null) return "";
        foreach (var line in result.Lines)
        {
            var t = System.Text.RegularExpressions.Regex.Replace((line.Text ?? "").Trim(), @"\s+", " ");
            if (t.Count(char.IsLetter) >= 3 && t.Length >= 4)
            {
                if (t.Length > 42) t = t[..42].Trim();
                return t;
            }
        }
        return "";
    }

    private static string SanitizeFileName(string name)
    {
        foreach (var c in System.IO.Path.GetInvalidFileNameChars())
        {
            name = name.Replace(c, ' ');
        }
        name = System.Text.RegularExpressions.Regex.Replace(name, @"\s+", " ").Trim();
        return name.Length == 0 ? "scan" : name;
    }

    private async Task RefreshAsync(bool selectLast)
    {
        if (selectLast)
        {
            _selected = _pages.Count - 1;
        }
        if (_selected >= _pages.Count)
        {
            _selected = _pages.Count - 1;
        }
        SendPreview();
        await SendThumbsAsync();
    }

    private async Task MovePageAsync(int delta)
    {
        int i = Sel();
        int j = i + delta;
        if (i < 0 || j < 0 || j >= _pages.Count)
        {
            return;
        }
        (_pages[i], _pages[j]) = (_pages[j], _pages[i]);
        SyncNames();
        if (i < _pageNames.Count && j < _pageNames.Count) (_pageNames[i], _pageNames[j]) = (_pageNames[j], _pageNames[i]);
        _selected = j;
        await RefreshAsync(false);
        Status($"Moved to position {j + 1}");
    }

    private async Task CropPageAsync(double x0, double y0, double x1, double y1)
    {
        int i = Sel();
        if (i < 0 || i >= _pages.Count)
        {
            return;
        }
        double lx = Math.Clamp(Math.Min(x0, x1), 0, 1), rx = Math.Clamp(Math.Max(x0, x1), 0, 1);
        double ty = Math.Clamp(Math.Min(y0, y1), 0, 1), by = Math.Clamp(Math.Max(y0, y1), 0, 1);
        if (rx - lx < 0.02 || by - ty < 0.02)
        {
            Status("Crop area is too small");
            return;
        }
        int w, h;
        using (var rendered = _pages[i].Render())
        {
            w = rendered.Width;
            h = rendered.Height;
        }
        int left = (int) (lx * w), right = (int) ((1 - rx) * w);
        int top = (int) (ty * h), bottom = (int) ((1 - by) * h);
        _pages[i] = _pages[i].WithTransform(new CropTransform(left, right, top, bottom, w, h), disposeSelf: true);
        await RefreshAsync(false);
        Status("Cropped");
    }

    private sealed class AnalyticsData
    {
        public Dictionary<string, int> Total { get; set; } = new();
        public Dictionary<string, int> Today { get; set; } = new();
        public string TodayDate { get; set; } = "";
    }

    private static readonly (string Key, string Label)[] AnalyticsRows =
    {
        ("scan", "Scan"), ("pdf", "PDF Save"), ("image", "Image Save"), ("print", "Print"), ("import", "Import"), ("camera", "Camera")
    };

    private static string AnalyticsFile => System.IO.Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "ApneScan", "analytics.json");

    private static AnalyticsData LoadAnalytics()
    {
        try
        {
            if (File.Exists(AnalyticsFile))
            {
                return JsonSerializer.Deserialize<AnalyticsData>(File.ReadAllText(AnalyticsFile)) ?? new();
            }
        }
        catch { /* non-fatal */ }
        return new();
    }

    private void Bump(string key, int n)
    {
        if (n <= 0)
        {
            return;
        }
        try
        {
            var a = LoadAnalytics();
            var today = DateTime.Now.ToString("yyyy-MM-dd");
            if (a.TodayDate != today)
            {
                a.Today = new();
                a.TodayDate = today;
            }
            a.Total[key] = a.Total.GetValueOrDefault(key) + n;
            a.Today[key] = a.Today.GetValueOrDefault(key) + n;
            Directory.CreateDirectory(System.IO.Path.GetDirectoryName(AnalyticsFile)!);
            File.WriteAllText(AnalyticsFile, JsonSerializer.Serialize(a));
            SendAnalytics(a);
        }
        catch { /* best-effort */ }
    }

    private void SendAnalytics(AnalyticsData? a = null)
    {
        try
        {
            a ??= LoadAnalytics();
            var today = DateTime.Now.ToString("yyyy-MM-dd");
            var todayMap = a.TodayDate == today ? a.Today : new Dictionary<string, int>();
            var rows = AnalyticsRows.Select(r => new
            {
                label = r.Label,
                world = a.Total.GetValueOrDefault(r.Key),
                today = todayMap.GetValueOrDefault(r.Key)
            }).ToArray();
            Post(new { type = "analytics", rows });
        }
        catch { /* best-effort */ }
    }

    private async Task GetTextAsync()
    {
        if (_pages.Count == 0)
        {
            Post(new { type = "text", text = "" });
            return;
        }
        if (_ctx.OcrEngine == null)
        {
            Post(new { type = "text", text = "OCR is not available." });
            return;
        }
        try
        {
            Status("Reading text (OCR)…");
            int i = Sel();
            var tmp = System.IO.Path.Combine(System.IO.Path.GetTempPath(), "apnescan_ocr_" + Guid.NewGuid().ToString("N")[..8] + ".png");
            _pages[i].Save(tmp);
            var result = await _ctx.OcrEngine.ProcessImage(_ctx, tmp, new OcrParams("eng"), CancellationToken.None);
            try { File.Delete(tmp); } catch { /* best-effort */ }
            var text = result == null ? "" : string.Join("\n", result.Lines.Select(l => l.Text));
            Post(new { type = "text", text });
            Status(string.IsNullOrWhiteSpace(text) ? "No text found on this page" : "Text ready");
        }
        catch (Exception ex)
        {
            Post(new { type = "text", text = "" });
            Status("OCR error: " + ex.Message);
        }
    }

    private sealed class AppSettings
    {
        public int Dpi { get; set; } = 200;
        public string Color { get; set; } = "color";
        public string Source { get; set; } = "auto";
        public bool Ocr { get; set; }
        // Preferred scanner (by name) — remembered across sessions.
        public string Device { get; set; } = "";
    }

    private static string SettingsFile => System.IO.Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "ApneScan", "settings.json");

    private static AppSettings LoadSettings()
    {
        try
        {
            if (File.Exists(SettingsFile))
            {
                return JsonSerializer.Deserialize<AppSettings>(File.ReadAllText(SettingsFile)) ?? new();
            }
        }
        catch { /* non-fatal */ }
        return new();
    }

    private void SendSettings()
    {
        var s = LoadSettings();
        _ocr = s.Ocr;
        Post(new { type = "settings", dpi = s.Dpi, color = s.Color, source = s.Source, ocr = s.Ocr, device = s.Device });
    }

    private void SaveSettings(int dpi, string color, string source, bool ocr, string device)
    {
        try
        {
            var s = new AppSettings { Dpi = dpi > 0 ? dpi : 200, Color = color, Source = source, Ocr = ocr, Device = device ?? "" };
            Directory.CreateDirectory(System.IO.Path.GetDirectoryName(SettingsFile)!);
            File.WriteAllText(SettingsFile, JsonSerializer.Serialize(s));
            _ocr = ocr;
        }
        catch { /* best-effort */ }
    }

    private sealed class HistoryItem
    {
        public string Name { get; set; } = "";
        public string Path { get; set; } = "";
        public string Date { get; set; } = "";
        public string Size { get; set; } = "";
        public int Pages { get; set; }
    }

    private static string HistoryFile => System.IO.Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "ApneScan", "history.json");

    private static List<HistoryItem> LoadHistory()
    {
        try
        {
            if (File.Exists(HistoryFile))
            {
                return JsonSerializer.Deserialize<List<HistoryItem>>(File.ReadAllText(HistoryFile)) ?? new();
            }
        }
        catch { /* corrupt/missing history is non-fatal */ }
        return new();
    }

    private void AddHistory(string path, int pages)
    {
        try
        {
            var fi = new FileInfo(path);
            var list = LoadHistory();
            list.RemoveAll(h => string.Equals(h.Path, fi.FullName, StringComparison.OrdinalIgnoreCase));
            list.Insert(0, new HistoryItem
            {
                Name = fi.Name,
                Path = fi.FullName,
                Date = fi.LastWriteTime.ToString("dd-MMM · hh:mm tt"),
                Size = FormatSize(fi.Length),
                Pages = pages
            });
            if (list.Count > 50)
            {
                list = list.GetRange(0, 50);
            }
            Directory.CreateDirectory(System.IO.Path.GetDirectoryName(HistoryFile)!);
            File.WriteAllText(HistoryFile, JsonSerializer.Serialize(list));
            SendHistory();
        }
        catch { /* history is best-effort */ }
    }

    private void SendHistory()
    {
        try { Post(new { type = "history", items = LoadHistory() }); }
        catch { /* best-effort */ }
    }

    private static string FormatSize(long bytes)
    {
        if (bytes >= 1024 * 1024) return $"{bytes / 1024.0 / 1024.0:0.0} MB";
        if (bytes >= 1024) return $"{bytes / 1024.0:0} KB";
        return $"{bytes} B";
    }

    private void OpenFile(string path)
    {
        try
        {
            if (string.IsNullOrEmpty(path) || !File.Exists(path))
            {
                Status("File not found (it may have been moved or deleted)");
                SendHistory();
                return;
            }
            Process.Start(new ProcessStartInfo { FileName = path, UseShellExecute = true });
        }
        catch (Exception ex)
        {
            Status("Open error: " + ex.Message);
        }
    }

    // ---- Save current pages as JPG / PNG image files ------------------------

    private async Task SaveImagesAsync(string format)
    {
        if (_pages.Count == 0)
        {
            Status("Nothing to save — scan a page first");
            return;
        }
        bool png = string.Equals(format, "png", StringComparison.OrdinalIgnoreCase);
        var ext = png ? "png" : "jpg";
        var fmt = png ? ImageFileFormat.Png : ImageFileFormat.Jpeg;
        var baseSuggested = _pageNames.FirstOrDefault(n => !string.IsNullOrWhiteSpace(n));
        var stem = string.IsNullOrWhiteSpace(baseSuggested) ? "scan" : SanitizeFileName(baseSuggested);
        using var sfd = new SaveFileDialog
        {
            Filter = png ? "PNG image (*.png)|*.png" : "JPEG image (*.jpg)|*.jpg",
            FileName = _pages.Count > 1 ? $"{stem}_1.{ext}" : $"{stem}.{ext}"
        };
        if (sfd.ShowDialog(this) != DialogResult.OK)
        {
            return;
        }
        try
        {
            Status("Saving image(s)…");
            var dir = System.IO.Path.GetDirectoryName(sfd.FileName) ?? ".";
            var baseName = System.IO.Path.GetFileNameWithoutExtension(sfd.FileName);
            // A single page keeps the chosen name; multiple pages get _1, _2, …
            if (_pages.Count == 1)
            {
                _pages[0].Save(sfd.FileName, fmt);
            }
            else
            {
                for (int i = 0; i < _pages.Count; i++)
                {
                    var p = System.IO.Path.Combine(dir, $"{baseName}_{i + 1}.{ext}");
                    _pages[i].Save(p, fmt);
                }
            }
            Bump("image", _pages.Count);
            Post(new { type = "done", path = sfd.FileName });
            Status($"Saved {_pages.Count} image(s) to {dir}");
        }
        catch (Exception ex)
        {
            Status("Save error: " + ex.Message);
        }
    }

    // ---- Open a URL (help / repo / info links) ------------------------------

    private void OpenUrl(string url)
    {
        try
        {
            if (string.IsNullOrWhiteSpace(url) ||
                !(url.StartsWith("http://") || url.StartsWith("https://")))
            {
                return;
            }
            Process.Start(new ProcessStartInfo { FileName = url, UseShellExecute = true });
        }
        catch (Exception ex)
        {
            Status("Open error: " + ex.Message);
        }
    }

    // ---- Storage: how much room ApneScan's files use ------------------------

    private void SendStorage()
    {
        try
        {
            var appDir = System.IO.Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "ApneScan");
            long used = 0;
            int files = 0;
            if (Directory.Exists(appDir))
            {
                foreach (var f in Directory.EnumerateFiles(appDir, "*", SearchOption.AllDirectories))
                {
                    try { used += new FileInfo(f).Length; files++; } catch { /* skip */ }
                }
            }
            long free = 0, total = 0;
            try
            {
                var drive = new DriveInfo(System.IO.Path.GetPathRoot(appDir) ?? "C:\\");
                free = drive.AvailableFreeSpace;
                total = drive.TotalSize;
            }
            catch { /* drive info best-effort */ }
            Post(new
            {
                type = "storage",
                used = FormatSize(used),
                usedBytes = used,
                files,
                free = FormatSize(free),
                total = FormatSize(total),
                percent = total > 0 ? (int) Math.Round((total - free) * 100.0 / total) : 0
            });
        }
        catch { /* best-effort */ }
    }

    // ---- Scan profiles: named presets of DPI/colour/source ------------------

    private sealed class Profile
    {
        public string Name { get; set; } = "";
        public int Dpi { get; set; } = 200;
        public string Color { get; set; } = "color";
        public string Source { get; set; } = "auto";
        public bool Ocr { get; set; }
        public string Device { get; set; } = "";
    }

    private static string ProfilesFile => System.IO.Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "ApneScan", "profiles.json");

    private static List<Profile> LoadProfiles()
    {
        try
        {
            if (File.Exists(ProfilesFile))
            {
                return JsonSerializer.Deserialize<List<Profile>>(File.ReadAllText(ProfilesFile)) ?? new();
            }
        }
        catch { /* non-fatal */ }
        return new();
    }

    private static void StoreProfiles(List<Profile> list)
    {
        Directory.CreateDirectory(System.IO.Path.GetDirectoryName(ProfilesFile)!);
        File.WriteAllText(ProfilesFile, JsonSerializer.Serialize(list));
    }

    private void SendProfiles()
    {
        try { Post(new { type = "profiles", items = LoadProfiles() }); }
        catch { /* best-effort */ }
    }

    private void SaveProfile(string name, int dpi, string color, string source, bool ocr, string device)
    {
        try
        {
            name = (name ?? "").Trim();
            if (name.Length == 0)
            {
                Status("Give the profile a name first");
                return;
            }
            var list = LoadProfiles();
            list.RemoveAll(p => string.Equals(p.Name, name, StringComparison.OrdinalIgnoreCase));
            list.Insert(0, new Profile { Name = name, Dpi = dpi > 0 ? dpi : 200, Color = color, Source = source, Ocr = ocr, Device = device ?? "" });
            if (list.Count > 20)
            {
                list = list.GetRange(0, 20);
            }
            StoreProfiles(list);
            SendProfiles();
            Status($"Profile “{name}” saved");
        }
        catch (Exception ex)
        {
            Status("Profile error: " + ex.Message);
        }
    }

    private void DeleteProfile(string name)
    {
        try
        {
            var list = LoadProfiles();
            list.RemoveAll(p => string.Equals(p.Name, name, StringComparison.OrdinalIgnoreCase));
            StoreProfiles(list);
            SendProfiles();
            Status($"Profile “{name}” removed");
        }
        catch { /* best-effort */ }
    }

    // ---- Browse the user's Documents folder inside the sidebar --------------

    private void SendFolder(string path)
    {
        try
        {
            if (string.IsNullOrWhiteSpace(path))
            {
                path = Environment.GetFolderPath(Environment.SpecialFolder.MyDocuments);
            }
            var di = new DirectoryInfo(path);
            if (!di.Exists)
            {
                path = Environment.GetFolderPath(Environment.SpecialFolder.MyDocuments);
                di = new DirectoryInfo(path);
            }

            var exts = new HashSet<string>(StringComparer.OrdinalIgnoreCase)
            { ".pdf", ".jpg", ".jpeg", ".png", ".tif", ".tiff", ".bmp" };

            var entries = new List<object>();
            try
            {
                foreach (var d in di.GetDirectories())
                {
                    if ((d.Attributes & (FileAttributes.Hidden | FileAttributes.System)) != 0) continue;
                    entries.Add(new { name = d.Name, path = d.FullName, dir = true });
                    if (entries.Count >= 400) break;
                }
                foreach (var f in di.GetFiles())
                {
                    if ((f.Attributes & FileAttributes.Hidden) != 0) continue;
                    if (!exts.Contains(f.Extension)) continue;
                    entries.Add(new { name = f.Name, path = f.FullName, dir = false });
                    if (entries.Count >= 400) break;
                }
            }
            catch { /* some subfolders may deny access — show what we can */ }

            Post(new
            {
                type = "folder",
                path = di.FullName,
                name = di.Name,
                parent = di.Parent?.FullName,
                entries
            });
        }
        catch (Exception ex)
        {
            Status("Folder error: " + ex.Message);
        }
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
