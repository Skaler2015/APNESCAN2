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
    private bool _autoName = true;
    private bool _clearAfter;
    private bool _autoCrop = true;
    private bool _skipBlank;

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
        string theme = "default";
        string saveDefault = "ask";
        bool showNums = true, showProfiles = true, autoName = true, clearAfter = false;
        bool autoCrop = true, skipBlank = false;
        string data = "";
        string ctx = "";
        var indices = new List<int>();
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
            if (root.TryGetProperty("theme", out var thEl) && thEl.ValueKind == JsonValueKind.String) theme = thEl.GetString() ?? "default";
            if (root.TryGetProperty("saveDefault", out var sdEl) && sdEl.ValueKind == JsonValueKind.String) saveDefault = sdEl.GetString() ?? "ask";
            if (root.TryGetProperty("showNums", out var snEl) && (snEl.ValueKind == JsonValueKind.True || snEl.ValueKind == JsonValueKind.False)) showNums = snEl.GetBoolean();
            if (root.TryGetProperty("showProfiles", out var spEl) && (spEl.ValueKind == JsonValueKind.True || spEl.ValueKind == JsonValueKind.False)) showProfiles = spEl.GetBoolean();
            if (root.TryGetProperty("autoName", out var anEl) && (anEl.ValueKind == JsonValueKind.True || anEl.ValueKind == JsonValueKind.False)) autoName = anEl.GetBoolean();
            if (root.TryGetProperty("clearAfter", out var caEl) && (caEl.ValueKind == JsonValueKind.True || caEl.ValueKind == JsonValueKind.False)) clearAfter = caEl.GetBoolean();
            if (root.TryGetProperty("autoCrop", out var acEl) && (acEl.ValueKind == JsonValueKind.True || acEl.ValueKind == JsonValueKind.False)) autoCrop = acEl.GetBoolean();
            if (root.TryGetProperty("skipBlank", out var sbEl) && (sbEl.ValueKind == JsonValueKind.True || sbEl.ValueKind == JsonValueKind.False)) skipBlank = sbEl.GetBoolean();
            if (root.TryGetProperty("data", out var dtEl) && dtEl.ValueKind == JsonValueKind.String) data = dtEl.GetString() ?? "";
            if (root.TryGetProperty("ctx", out var cxEl) && cxEl.ValueKind == JsonValueKind.String) ctx = cxEl.GetString() ?? "";
            if (root.TryGetProperty("indices", out var ixArr) && ixArr.ValueKind == JsonValueKind.Array)
            {
                foreach (var el in ixArr.EnumerateArray())
                {
                    if (el.ValueKind == JsonValueKind.Number) indices.Add(el.GetInt32());
                }
            }
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
            case "savePdfSelected":
                await SavePdfSelectedAsync(indices);
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
            case "importPath":
                await ImportPathAsync(filePath);
                break;
            case "importDropped":
                await ImportDroppedAsync(dataUrl, name);
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
                SaveSettings(new AppSettings
                {
                    Dpi = dpi, Color = color, Source = source, Ocr = on, Device = deviceName,
                    Theme = theme, ShowNums = showNums, ShowProfiles = showProfiles,
                    SaveDefault = saveDefault, AutoName = autoName, ClearAfter = clearAfter,
                    AutoCrop = autoCrop, SkipBlank = skipBlank
                });
                break;
            case "getHistory":
                SendHistory();
                break;
            case "openFile":
                OpenFile(filePath);
                break;
            case "previewFile":
                await PreviewFileAsync(filePath);
                break;
            case "listFolder":
                SendFolder(filePath, ctx);
                break;
            case "makeFolder":
                MakeFolder(filePath, name);
                break;
            case "toggleFav":
                ToggleFav(filePath);
                break;
            case "getFavs":
                SendFavs();
                break;
            case "openFolder":
                OpenFolder(filePath);
                break;
            case "browseFolder":
                BrowseFolder(filePath);
                break;
            case "getShortcuts":
                SendShortcuts();
                break;
            case "saveShortcuts":
                SaveShortcuts(data);
                break;
            case "savePdfHere":
                await SavePdfHereAsync(filePath);
                break;
            case "renamePage":
                RenamePage(index, name, on);
                break;
            case "getNames":
                SendNames();
                break;
            case "addName":
                AddName(name);
                break;
            case "removeName":
                RemoveName(name);
                break;
            case "clearNames":
                ClearNames();
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
                if (indices.Count > 0) await DeletePagesAsync(indices);
                else await DeletePageAsync();
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

            int added = 0, skipped = 0;
            await foreach (var image in controller.Scan(options))
            {
                var (proc, blank) = await PostProcessScanAsync(image);
                if (blank && _skipBlank)
                {
                    proc.Dispose();
                    skipped++;
                    continue;
                }
                _pages.Add(proc);
                added++;
            }

            if (added == 0)
            {
                Status(skipped > 0 ? $"Only blank page(s) found — skipped {skipped}" : "Nothing was scanned");
                return;
            }
            if (skipped > 0)
            {
                Status($"Skipped {skipped} blank page(s)");
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

    // Auto-crop blank borders and detect blank pages by analysing a small
    // rendering of the scanned page.
    private async Task<(ProcessedImage img, bool blank)> PostProcessScanAsync(ProcessedImage p)
    {
        double l = 0, t = 0, r = 1, b = 1, coverage = 1;
        try
        {
            var renderer = new ThumbnailRenderer(_ctx.ImageContext);
            using var thumb = await renderer.Render(p, 500);
            using var bmp = ToBitmap24(thumb);
            (l, t, r, b, coverage) = ContentBounds(bmp);
        }
        catch
        {
            return (p, false);
        }

        // Almost nothing on the page → blank.
        if (coverage < 0.0035)
        {
            return (p, true);
        }

        if (_autoCrop)
        {
            // Keep a small margin around the detected content.
            const double m = 0.012;
            l = Math.Max(0, l - m); t = Math.Max(0, t - m);
            r = Math.Min(1, r + m); b = Math.Min(1, b + m);
            // Only crop if it actually removes a meaningful border.
            if ((r - l) < 0.985 || (b - t) < 0.985)
            {
                int w, h;
                using (var full = p.Render()) { w = full.Width; h = full.Height; }
                int left = (int) (l * w), right = (int) ((1 - r) * w);
                int top = (int) (t * h), bottom = (int) ((1 - b) * h);
                if (left >= 0 && right >= 0 && top >= 0 && bottom >= 0 &&
                    (w - left - right) > 10 && (h - top - bottom) > 10)
                {
                    try { p = p.WithTransform(new CropTransform(left, right, top, bottom, w, h), disposeSelf: true); }
                    catch { /* keep original on failure */ }
                }
            }
        }
        return (p, false);
    }

    private static System.Drawing.Bitmap ToBitmap24(IMemoryImage img)
    {
        var src = img.AsBitmap();
        var bmp = new System.Drawing.Bitmap(src.Width, src.Height,
            System.Drawing.Imaging.PixelFormat.Format24bppRgb);
        using (var g = System.Drawing.Graphics.FromImage(bmp))
        {
            g.DrawImage(src, 0, 0, src.Width, src.Height);
        }
        return bmp;
    }

    // Returns the content bounding box as fractions (left, top, right, bottom)
    // plus the fraction of dark pixels (used for blank-page detection).
    private static (double l, double t, double r, double b, double coverage) ContentBounds(System.Drawing.Bitmap bmp)
    {
        int w = bmp.Width, h = bmp.Height;
        var data = bmp.LockBits(new System.Drawing.Rectangle(0, 0, w, h),
            System.Drawing.Imaging.ImageLockMode.ReadOnly, System.Drawing.Imaging.PixelFormat.Format24bppRgb);
        int stride = data.Stride;
        var buf = new byte[stride * h];
        System.Runtime.InteropServices.Marshal.Copy(data.Scan0, buf, 0, buf.Length);
        bmp.UnlockBits(data);

        var rowCount = new int[h];
        var colCount = new int[w];
        long dark = 0;
        for (int y = 0; y < h; y++)
        {
            int row = y * stride;
            for (int x = 0; x < w; x++)
            {
                int o = row + x * 3;
                int lum = (buf[o] + buf[o + 1] + buf[o + 2]) / 3;
                if (lum < 215)
                {
                    rowCount[y]++;
                    colCount[x]++;
                    dark++;
                }
            }
        }
        int rowThresh = Math.Max(2, (int) (w * 0.004));
        int colThresh = Math.Max(2, (int) (h * 0.004));
        int minX = -1, maxX = -1, minY = -1, maxY = -1;
        for (int y = 0; y < h; y++) { if (rowCount[y] > rowThresh) { if (minY < 0) minY = y; maxY = y; } }
        for (int x = 0; x < w; x++) { if (colCount[x] > colThresh) { if (minX < 0) minX = x; maxX = x; } }
        double coverage = (double) dark / ((long) w * h);
        if (minX < 0 || minY < 0)
        {
            return (0, 0, 1, 1, coverage);
        }
        return ((double) minX / w, (double) minY / h, (double) (maxX + 1) / w, (double) (maxY + 1) / h, coverage);
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
            if (_clearAfter) ClearPages();
        }
        catch (Exception ex)
        {
            Status("Save error: " + ex.Message);
        }
    }

    private async Task SavePdfSelectedAsync(List<int> indices)
    {
        if (_pages.Count == 0)
        {
            Status("Nothing to save — scan a page first");
            return;
        }
        var sel = indices.Where(i => i >= 0 && i < _pages.Count).Distinct().OrderBy(i => i).ToList();
        if (sel.Count == 0)
        {
            int s = Sel();
            if (s >= 0) sel.Add(s);
        }
        if (sel.Count == 0)
        {
            Status("No page selected");
            return;
        }
        var first = sel[0];
        var nm = (first < _pageNames.Count && !string.IsNullOrWhiteSpace(_pageNames[first]))
            ? SanitizeFileName(_pageNames[first]) : "scan";
        using var sfd = new SaveFileDialog
        {
            Filter = "PDF document (*.pdf)|*.pdf",
            FileName = nm + ".pdf"
        };
        if (sfd.ShowDialog(this) != DialogResult.OK)
        {
            return;
        }
        try
        {
            Status(_ocr ? "Saving selected page(s) (OCR)…" : "Saving selected page(s)…");
            var pages = sel.Select(i => _pages[i]).ToList();
            var exporter = new PdfExporter(_ctx);
            var ocrParams = _ocr ? new OcrParams("eng") : null;
            await exporter.Export(sfd.FileName, pages, ocrParams: ocrParams);
            AddHistory(sfd.FileName, pages.Count);
            Bump("pdf", 1);
            Post(new { type = "done", path = sfd.FileName });
            Status($"Saved {pages.Count} page(s): " + sfd.FileName);
            if (_clearAfter) ClearPages();
        }
        catch (Exception ex)
        {
            Status("Save error: " + ex.Message);
        }
    }

    private async Task DeletePagesAsync(List<int> indices)
    {
        var uniq = indices.Where(i => i >= 0 && i < _pages.Count).Distinct().OrderByDescending(i => i).ToList();
        if (uniq.Count == 0)
        {
            Status("No pages selected");
            return;
        }
        foreach (var i in uniq)
        {
            _pages[i].Dispose();
            _pages.RemoveAt(i);
            if (i < _pageNames.Count) _pageNames.RemoveAt(i);
        }
        if (_selected >= _pages.Count) _selected = _pages.Count - 1;
        if (_pages.Count == 0)
        {
            Post(new { type = "cleared" });
            Status("All pages removed");
        }
        else
        {
            await RefreshAsync(false);
            Status($"Deleted {uniq.Count} page(s) — {_pages.Count} left");
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

    // Import a single file by path (used by drag-and-drop from the sidebar).
    private async Task ImportPathAsync(string path)
    {
        if (string.IsNullOrEmpty(path) || !File.Exists(path))
        {
            Status("File not found");
            return;
        }
        try
        {
            Status("Importing…");
            PushUndo();
            var ext = System.IO.Path.GetExtension(path).ToLowerInvariant();
            var importer = ext == ".pdf"
                ? new PdfImporter(_ctx).Import(path)
                : new ImageImporter(_ctx).Import(path);
            int added = 0;
            await foreach (var img in importer)
            {
                _pages.Add(img);
                added++;
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

    // Import a file dropped from Windows Explorer (sent as a data URL).
    private async Task ImportDroppedAsync(string dataUrl, string name)
    {
        if (string.IsNullOrEmpty(dataUrl)) return;
        try
        {
            var comma = dataUrl.IndexOf(',');
            var b64 = comma >= 0 ? dataUrl[(comma + 1)..] : dataUrl;
            var bytes = Convert.FromBase64String(b64);
            var ext = System.IO.Path.GetExtension(name ?? "");
            if (string.IsNullOrEmpty(ext)) ext = ".jpg";
            var temp = System.IO.Path.Combine(System.IO.Path.GetTempPath(),
                "apnescan_drop_" + Guid.NewGuid().ToString("N")[..8] + ext);
            await File.WriteAllBytesAsync(temp, bytes);
            await ImportPathAsync(temp);
            try { File.Delete(temp); } catch { /* best-effort */ }
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
        if (_ctx.OcrEngine == null || _naming || !_autoName) return;
        _naming = true;
        try
        {
            SyncNames();
            var suggestions = LoadNames();
            for (int i = 0; i < _pages.Count; i++)
            {
                if (i < _pageNames.Count && !string.IsNullOrEmpty(_pageNames[i])) continue;
                string name = "";
                try
                {
                    var text = await OcrTopTextAsync(_pages[i]);
                    // A remembered name that appears in the page wins (clean label);
                    // otherwise fall back to the first strong line of text.
                    name = MatchName(text, suggestions) ?? FirstStrongLine(text);
                }
                catch { /* per-page best-effort */ }
                if (i < _pageNames.Count) _pageNames[i] = name;
                Post(new { type = "pageName", index = i, name });
            }
        }
        finally { _naming = false; }
    }

    private async Task<string> OcrTopTextAsync(ProcessedImage page)
    {
        int w, h;
        using (var r = page.Render()) { w = r.Width; h = r.Height; }
        // OCR only the top ~38% (letterhead/title area) — faster and cleaner.
        var top = page.WithTransform(
            new CropTransform(0, 0, 0, (int) (h * 0.62), w, h), disposeSelf: false);
        try
        {
            var tmp = System.IO.Path.Combine(System.IO.Path.GetTempPath(),
                "apnescan_name_" + Guid.NewGuid().ToString("N")[..8] + ".png");
            top.Save(tmp);
            var result = await _ctx.OcrEngine!.ProcessImage(_ctx, tmp, new OcrParams("eng"), CancellationToken.None);
            try { File.Delete(tmp); } catch { /* best-effort */ }
            return result == null ? "" : string.Join("\n", result.Lines.Select(l => l.Text));
        }
        finally { top.Dispose(); }
    }

    private static string Norm(string s) =>
        System.Text.RegularExpressions.Regex.Replace((s ?? "").ToLowerInvariant(), "[^a-z0-9]+", " ").Trim();

    private static string? MatchName(string text, List<string> names)
    {
        var nt = Norm(text);
        if (nt.Length == 0) return null;
        foreach (var n in names)
        {
            var nn = Norm(n);
            if (nn.Length >= 3 && nt.Contains(nn)) return n;
        }
        return null;
    }

    private static string FirstStrongLine(string text)
    {
        foreach (var raw in (text ?? "").Split('\n'))
        {
            var t = System.Text.RegularExpressions.Regex.Replace(raw.Trim(), @"\s+", " ");
            if (t.Count(char.IsLetter) >= 3 && t.Length >= 4)
            {
                if (t.Length > 42) t = t[..42].Trim();
                return t;
            }
        }
        return "";
    }

    // ---- Remembered / suggested names ---------------------------------------

    private static string NamesFile => System.IO.Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "ApneScan", "names.json");

    private static List<string> LoadNames()
    {
        try
        {
            if (File.Exists(NamesFile))
            {
                return JsonSerializer.Deserialize<List<string>>(File.ReadAllText(NamesFile)) ?? new();
            }
        }
        catch { /* non-fatal */ }
        return new();
    }

    private void StoreNames(List<string> list)
    {
        Directory.CreateDirectory(System.IO.Path.GetDirectoryName(NamesFile)!);
        File.WriteAllText(NamesFile, JsonSerializer.Serialize(list));
    }

    private void SendNames() => Post(new { type = "names", items = LoadNames() });

    private void AddName(string n)
    {
        n = (n ?? "").Trim();
        if (n.Length == 0) return;
        var l = LoadNames();
        if (!l.Any(x => string.Equals(x, n, StringComparison.OrdinalIgnoreCase)))
        {
            l.Insert(0, n);
            if (l.Count > 300) l = l.GetRange(0, 300);
            StoreNames(l);
        }
        SendNames();
    }

    private void RemoveName(string n)
    {
        var l = LoadNames();
        l.RemoveAll(x => string.Equals(x, n, StringComparison.OrdinalIgnoreCase));
        StoreNames(l);
        SendNames();
    }

    private void ClearNames()
    {
        StoreNames(new());
        SendNames();
    }

    private void RenamePage(int index, string name, bool remember)
    {
        SyncNames();
        if (index < 0 || index >= _pageNames.Count) return;
        name = (name ?? "").Trim();
        _pageNames[index] = name;
        Post(new { type = "pageName", index, name });
        if (remember && name.Length > 0) AddName(name);
        Status(name.Length > 0 ? $"Page {index + 1} named “{name}”" : $"Page {index + 1} name cleared");
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
        // Interface / application preferences
        public string Theme { get; set; } = "default";
        public bool ShowNums { get; set; } = true;
        public bool ShowProfiles { get; set; } = true;
        public string SaveDefault { get; set; } = "ask";
        public bool AutoName { get; set; } = true;
        public bool ClearAfter { get; set; }
        public bool AutoCrop { get; set; } = true;
        public bool SkipBlank { get; set; }
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
        _autoName = s.AutoName;
        _clearAfter = s.ClearAfter;
        _autoCrop = s.AutoCrop;
        _skipBlank = s.SkipBlank;
        Post(new
        {
            type = "settings",
            dpi = s.Dpi, color = s.Color, source = s.Source, ocr = s.Ocr, device = s.Device,
            theme = s.Theme, showNums = s.ShowNums, showProfiles = s.ShowProfiles,
            saveDefault = s.SaveDefault, autoName = s.AutoName, clearAfter = s.ClearAfter,
            autoCrop = s.AutoCrop, skipBlank = s.SkipBlank
        });
    }

    private void SaveSettings(AppSettings s)
    {
        try
        {
            if (s.Dpi <= 0) s.Dpi = 200;
            s.Device ??= "";
            Directory.CreateDirectory(System.IO.Path.GetDirectoryName(SettingsFile)!);
            File.WriteAllText(SettingsFile, JsonSerializer.Serialize(s));
            _ocr = s.Ocr;
            _autoName = s.AutoName;
            _clearAfter = s.ClearAfter;
            _autoCrop = s.AutoCrop;
            _skipBlank = s.SkipBlank;
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

    // Render the first page of a PDF/image to show in the right preview panel
    // (without importing it into the current document).
    private async Task PreviewFileAsync(string path)
    {
        if (string.IsNullOrEmpty(path) || !File.Exists(path))
        {
            Status("File not found");
            return;
        }
        try
        {
            Status("Loading preview…");
            var ext = System.IO.Path.GetExtension(path).ToLowerInvariant();
            var importer = ext == ".pdf"
                ? new PdfImporter(_ctx).Import(path)
                : new ImageImporter(_ctx).Import(path);
            ProcessedImage? first = null;
            await foreach (var img in importer)
            {
                first = img;
                break; // first page is enough for a preview
            }
            if (first == null)
            {
                Status("Could not preview this file");
                return;
            }
            var tmp = System.IO.Path.Combine(System.IO.Path.GetTempPath(),
                "apnescan_fp_" + Guid.NewGuid().ToString("N")[..8] + ".png");
            first.Save(tmp);
            first.Dispose();
            var b64 = Convert.ToBase64String(await File.ReadAllBytesAsync(tmp));
            try { File.Delete(tmp); } catch { /* best-effort */ }
            Post(new
            {
                type = "filePreview",
                dataUrl = "data:image/png;base64," + b64,
                name = System.IO.Path.GetFileName(path)
            });
            Status("Preview: " + System.IO.Path.GetFileName(path));
        }
        catch (Exception ex)
        {
            Status("Preview error: " + ex.Message);
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
            if (_clearAfter) ClearPages();
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

    private void SendFolder(string path, string ctx = "")
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
            var favs = LoadFavs();

            var entries = new List<object>();
            try
            {
                foreach (var d in di.GetDirectories())
                {
                    if ((d.Attributes & (FileAttributes.Hidden | FileAttributes.System)) != 0) continue;
                    int cnt = 0;
                    try { cnt = d.GetFiles().Length; } catch { /* access denied */ }
                    entries.Add(new
                    {
                        name = d.Name,
                        path = d.FullName,
                        dir = true,
                        date = d.LastWriteTime.ToString("dd MMM yyyy"),
                        ms = new DateTimeOffset(d.LastWriteTime).ToUnixTimeMilliseconds(),
                        count = cnt,
                        size = 0L,
                        fav = favs.Contains(d.FullName)
                    });
                    if (entries.Count >= 600) break;
                }
                foreach (var f in di.GetFiles())
                {
                    if ((f.Attributes & FileAttributes.Hidden) != 0) continue;
                    if (!exts.Contains(f.Extension)) continue;
                    entries.Add(new
                    {
                        name = f.Name,
                        path = f.FullName,
                        dir = false,
                        date = f.LastWriteTime.ToString("dd MMM yyyy"),
                        ms = new DateTimeOffset(f.LastWriteTime).ToUnixTimeMilliseconds(),
                        count = 0,
                        size = f.Length,
                        fav = favs.Contains(f.FullName)
                    });
                    if (entries.Count >= 600) break;
                }
            }
            catch { /* some subfolders may deny access — show what we can */ }

            Post(new
            {
                type = "folder",
                path = di.FullName,
                name = di.Name,
                parent = di.Parent?.FullName,
                ctx,
                entries
            });
        }
        catch (Exception ex)
        {
            Status("Folder error: " + ex.Message);
        }
    }

    private static string FavFile => System.IO.Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "ApneScan", "favorites.json");

    private static HashSet<string> LoadFavs()
    {
        try
        {
            if (File.Exists(FavFile))
            {
                return new HashSet<string>(
                    JsonSerializer.Deserialize<List<string>>(File.ReadAllText(FavFile)) ?? new(),
                    StringComparer.OrdinalIgnoreCase);
            }
        }
        catch { /* non-fatal */ }
        return new HashSet<string>(StringComparer.OrdinalIgnoreCase);
    }

    private void ToggleFav(string path)
    {
        try
        {
            if (string.IsNullOrWhiteSpace(path)) return;
            var s = LoadFavs();
            if (!s.Add(path)) s.Remove(path);
            Directory.CreateDirectory(System.IO.Path.GetDirectoryName(FavFile)!);
            File.WriteAllText(FavFile, JsonSerializer.Serialize(s.ToList()));
            SendFavs();
        }
        catch { /* best-effort */ }
    }

    // The pinned favorites shown in the sidebar (folders/files the user starred).
    private void SendFavs()
    {
        try
        {
            var items = new List<object>();
            foreach (var p in LoadFavs())
            {
                bool dir = Directory.Exists(p);
                bool file = File.Exists(p);
                if (!dir && !file) continue;
                items.Add(new { path = p, name = System.IO.Path.GetFileName(p.TrimEnd('\\', '/')), dir });
            }
            Post(new { type = "favs", items });
        }
        catch { /* best-effort */ }
    }

    private static string ShortcutsFile => System.IO.Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "ApneScan", "shortcuts.json");

    private void SendShortcuts()
    {
        string data = "";
        try { if (File.Exists(ShortcutsFile)) data = File.ReadAllText(ShortcutsFile); }
        catch { /* non-fatal */ }
        Post(new { type = "shortcuts", data });
    }

    private void SaveShortcuts(string data)
    {
        try
        {
            Directory.CreateDirectory(System.IO.Path.GetDirectoryName(ShortcutsFile)!);
            File.WriteAllText(ShortcutsFile, data ?? "");
        }
        catch { /* best-effort */ }
    }

    private void OpenFolder(string path)
    {
        try
        {
            if (string.IsNullOrWhiteSpace(path) || !Directory.Exists(path))
            {
                Status("Folder not found");
                return;
            }
            Process.Start(new ProcessStartInfo { FileName = path, UseShellExecute = true });
            Status("Opened in File Explorer");
        }
        catch (Exception ex)
        {
            Status("Open error: " + ex.Message);
        }
    }

    // Native Windows "choose folder" dialog; the pick opens in the sidebar panel.
    private void BrowseFolder(string start)
    {
        try
        {
            string? picked = null;
            // FolderBrowserDialog uses the modern Vista-style folder picker by
            // default (AutoUpgradeEnabled), letting the user pick any folder
            // including This PC / Network locations.
            using (var fbd = new FolderBrowserDialog())
            {
                fbd.UseDescriptionForTitle = true;
                fbd.Description = "Select a folder to open";
                fbd.ShowNewFolderButton = true;
                if (!string.IsNullOrWhiteSpace(start) && Directory.Exists(start)) fbd.SelectedPath = start;
                if (fbd.ShowDialog(this) == DialogResult.OK) picked = fbd.SelectedPath;
            }
            if (!string.IsNullOrEmpty(picked))
            {
                Post(new { type = "openDocsPanel" });
                SendFolder(picked);
            }
        }
        catch (Exception ex)
        {
            Status("Open error: " + ex.Message);
        }
    }

    private void MakeFolder(string path, string name)
    {
        try
        {
            if (string.IsNullOrWhiteSpace(path)) return;
            name = SanitizeFileName(name);
            Directory.CreateDirectory(System.IO.Path.Combine(path, name));
            SendFolder(path);
            Status("Folder created: " + name);
        }
        catch (Exception ex)
        {
            Status("Create folder error: " + ex.Message);
        }
    }

    private async Task SavePdfHereAsync(string folder)
    {
        if (_pages.Count == 0)
        {
            Status("Nothing to save — scan a page first");
            return;
        }
        try
        {
            if (string.IsNullOrWhiteSpace(folder) || !Directory.Exists(folder))
            {
                folder = Environment.GetFolderPath(Environment.SpecialFolder.MyDocuments);
            }
            var suggested = _pageNames.FirstOrDefault(n => !string.IsNullOrWhiteSpace(n));
            var stem = string.IsNullOrWhiteSpace(suggested) ? "scan" : SanitizeFileName(suggested);
            var file = System.IO.Path.Combine(folder, stem + ".pdf");
            int k = 1;
            while (File.Exists(file)) file = System.IO.Path.Combine(folder, $"{stem} ({++k}).pdf");

            Status(_ocr ? "Saving PDF with OCR…" : "Saving PDF…");
            var exporter = new PdfExporter(_ctx);
            var ocrParams = _ocr ? new OcrParams("eng") : null;
            await exporter.Export(file, _pages, ocrParams: ocrParams);
            AddHistory(file, _pages.Count);
            Bump("pdf", 1);
            SendFolder(folder);
            Post(new { type = "done", path = file });
            Status("Saved: " + file);
            if (_clearAfter) ClearPages();
        }
        catch (Exception ex)
        {
            Status("Save error: " + ex.Message);
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
