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
using Windows.ApplicationModel.DataTransfer;
using Windows.Storage;
using WinRT;
// Disambiguate types that now collide with the WinRT namespaces above.
using Clipboard = System.Windows.Forms.Clipboard;
using FileAttributes = System.IO.FileAttributes;

namespace ApneScan.Studio;

// Interop to show the WinRT Share sheet from a Win32/WinForms window.
[System.Runtime.InteropServices.ComImport]
[System.Runtime.InteropServices.Guid("3A3DCD6C-3EAB-43DC-BCDE-45671CE800C8")]
[System.Runtime.InteropServices.InterfaceType(System.Runtime.InteropServices.ComInterfaceType.InterfaceIsIUnknown)]
internal interface IDataTransferManagerInterop
{
    IntPtr GetForWindow([System.Runtime.InteropServices.In] IntPtr appWindow, [System.Runtime.InteropServices.In] ref Guid riid);
    void ShowShareUIForWindow(IntPtr appWindow);
}

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
    private CancellationTokenSource? _scanCts;   // lets the user cancel a scan mid-way
    private bool _ocr;
    // OCR recognition language passed to Tesseract: "eng", "hin", or "eng+hin".
    // Only languages with a bundled tessdata file are ever used.
    private string _ocrLang = "eng";
    // Keep the language honest — fall back to any part that is actually installed.
    private static string SanitizeOcrLang(string? lang)
    {
        var allowed = new HashSet<string>(StringComparer.OrdinalIgnoreCase) { "eng", "hin" };
        var parts = (lang ?? "eng").Split('+', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
            .Where(p => allowed.Contains(p)).Distinct(StringComparer.OrdinalIgnoreCase).ToList();
        return parts.Count > 0 ? string.Join("+", parts) : "eng";
    }
    // OCR engine: "tesseract" (default, fast, printed text) or "paddle"
    // (offline PaddleOCR PP-OCRv5 AI — stronger on printed & neat handwriting).
    private string _ocrEngine = "tesseract";
    private static string SanitizeEngine(string? e) =>
        string.Equals(e, "paddle", StringComparison.OrdinalIgnoreCase) ? "paddle" : "tesseract";
    // Lazily-created PaddleOCR pipeline (expensive to build; not thread-safe, so
    // all access is serialised through _paddleLock). Held as object to avoid
    // pulling Sdcb/OpenCvSharp namespaces into the rest of the file.
    private object? _paddleAll;
    private bool _paddleBroken;                       // set if the native engine failed to load
    private readonly object _paddleLock = new();
    private string PaddleOcrTextFromFile(string path)
    {
        lock (_paddleLock)
        {
            if (_paddleBroken) return "";
            try
            {
                if (_paddleAll is not Sdcb.PaddleOCR.PaddleOcrAll all)
                {
                    var model = Sdcb.PaddleOCR.Models.Local.LocalFullModels.EnglishV5;
                    all = new Sdcb.PaddleOCR.PaddleOcrAll(model, Sdcb.PaddleInference.PaddleDevice.Mkldnn())
                    {
                        AllowRotateDetection = true,
                        Enable180Classification = false
                    };
                    _paddleAll = all;
                }
                using OpenCvSharp.Mat src = OpenCvSharp.Cv2.ImRead(path, OpenCvSharp.ImreadModes.Color);
                if (src.Empty()) return "";
                var res = all.Run(src);
                return res?.Text ?? "";
            }
            catch
            {
                // Native failure (e.g. CPU without AVX) — disable and fall back.
                _paddleBroken = true;
                return "";
            }
        }
    }
    // Unified text extraction that honours the selected OCR engine, with a
    // graceful fall-back to Tesseract when the AI engine yields nothing.
    private async Task<string> ExtractTextAsync(string tmpPath, CancellationToken token)
    {
        if (_ocrEngine == "paddle")
        {
            try
            {
                var t = await Task.Run(() => PaddleOcrTextFromFile(tmpPath), token);
                if (!string.IsNullOrWhiteSpace(t)) return t;
            }
            catch { /* fall through to Tesseract */ }
        }
        if (_ctx.OcrEngine == null) return "";
        var r = await _ctx.OcrEngine.ProcessImage(_ctx, tmpPath, new OcrParams(_ocrLang), token);
        return r == null ? "" : string.Join("\n", r.Lines.Select(l => l.Text));
    }
    private int _selected = -1;
    private readonly List<List<ProcessedImage>> _undo = new();
    private readonly List<List<ProcessedImage>> _redo = new();
    // Internal page clipboard for copy/paste within the Scanned Pages area.
    private List<ProcessedImage> _copiedPages = new();
    // Auto-detected document name per page (from OCR of the page's top area).
    private readonly List<string> _pageNames = new();
    private bool _naming;
    private bool _autoName = true;
    private bool _clearAfter;
    private bool _autoCrop = true;
    private bool _skipBlank;
    private int _compressPercent; // 0 = off; higher = smaller PDFs on save

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
            // Any new edit invalidates the redo stack.
            ClearRedo();
        }
        catch { /* undo is best-effort */ }
    }

    private void ClearRedo()
    {
        foreach (var snap in _redo)
            foreach (var p in snap) p.Dispose();
        _redo.Clear();
    }

    // Discard the most recent undo snapshot (used when an edit turned out to be a no-op).
    private void PopUndo()
    {
        if (_undo.Count == 0) return;
        foreach (var p in _undo[^1]) p.Dispose();
        _undo.RemoveAt(_undo.Count - 1);
    }

    private async Task UndoAsync()
    {
        if (_undo.Count == 0)
        {
            Status("Nothing to undo");
            return;
        }
        // Remember the current state so Redo can bring it back.
        _redo.Add(_pages.Select(p => p.Clone()).ToList());
        while (_redo.Count > 8)
        {
            foreach (var p in _redo[0]) p.Dispose();
            _redo.RemoveAt(0);
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

    private async Task RedoAsync()
    {
        if (_redo.Count == 0)
        {
            Status("Nothing to redo");
            return;
        }
        _undo.Add(_pages.Select(p => p.Clone()).ToList());
        while (_undo.Count > 8)
        {
            foreach (var p in _undo[0]) p.Dispose();
            _undo.RemoveAt(0);
        }
        var snap = _redo[^1];
        _redo.RemoveAt(_redo.Count - 1);
        foreach (var p in _pages) p.Dispose();
        _pages.Clear();
        _pages.AddRange(snap);
        _pageNames.Clear();
        _selected = _pages.Count - 1;
        await RefreshAsync(false);
        _ = AutoNameAsync();
        Status("Redone");
    }

    // One-click update: manifest published to the "latest" GitHub release.
    private const string UpdateManifestUrl =
        "https://github.com/Skaler2015/APNESCAN2/releases/latest/download/update.json";
    private string? _updateUrl;
    private string? _updateSha;

    // Phone <-> PC bridge over the internet relay (works on any network, both ways).
    private const string PhoneRelay = "https://apnescan.subhashkaler.com/api/phone.php";
    private const string PhonePageBase = "https://apnescan.subhashkaler.com/phone/";
    private static readonly HttpClient _relay = new() { Timeout = TimeSpan.FromSeconds(60) };
    private string _phoneCode = "";
    private System.Windows.Forms.Timer? _phonePollTimer;
    private long _lastPhoneTs = 0;
    // Legacy same-WiFi server fields (kept for compatibility; no longer started).
    private TcpListener? _phoneServer;
    private const int PhonePort = 8765;
    private readonly Dictionary<string, string> _phoneShares = new();

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
            try
            {
                _pingTimer?.Stop();
                var mins = (int)Math.Round((DateTime.UtcNow - _sessionStart).TotalMinutes);
                if (mins > 0) SendTelemetrySync("session_min", Math.Min(mins, 100000));
            }
            catch { }
            StopPhoneServer();
            foreach (var p in _pages) p.Dispose();
            foreach (var p in _copiedPages) p.Dispose();
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

        _telemetry = LoadSettings().Telemetry;
        SendTelemetry("app_open");
        PingLive();

        // Heartbeat every 60s so the dashboard's "online now" stays current.
        _pingTimer = new System.Windows.Forms.Timer { Interval = 60_000 };
        _pingTimer.Tick += (_, _) => PingLive();
        _pingTimer.Start();

        // Fetch broadcast / force-update / feature flags from the server.
        _ = PollRemoteConfigAsync();

        // Report a coarse device profile (Phase 2 analytics).
        SendDeviceProfile();
    }

    // ---- Anonymous usage telemetry (opt-out) ----
    // Sends only which feature was used + app version + OS. No document
    // content, filenames or personal data ever leaves the device.
    private static readonly HttpClient _tele = new() { Timeout = TimeSpan.FromSeconds(8) };
    private string? _installId;
    private bool _telemetry = true;
    private const string TelemetryUrl = "https://apnescan.subhashkaler.com/api/track.php";
    private readonly DateTime _sessionStart = DateTime.UtcNow;
    private System.Windows.Forms.Timer? _pingTimer;

    private string InstallId()
    {
        if (_installId != null) return _installId;
        try
        {
            var f = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                "ApneScan", "install.id");
            if (File.Exists(f)) _installId = File.ReadAllText(f).Trim();
            if (string.IsNullOrWhiteSpace(_installId))
            {
                _installId = Guid.NewGuid().ToString("N");
                Directory.CreateDirectory(Path.GetDirectoryName(f)!);
                File.WriteAllText(f, _installId);
            }
        }
        catch { _installId = "anon"; }
        return _installId!;
    }

    private const string ConfigUrl = "https://apnescan.subhashkaler.com/api/config-app.php";
    private const string FeedbackUrl = "https://apnescan.subhashkaler.com/api/feedback.php";
    private const string DeviceUrl = "https://apnescan.subhashkaler.com/api/device.php";
    private string _lastScanner = "";
    private static string AppVer() => (Assembly.GetExecutingAssembly().GetName().Version ?? new Version(1, 0, 0)).ToString(3);
    private static string OsStr() => "Win " + Environment.OSVersion.Version.Major + "." + Environment.OSVersion.Version.Build;

    // Coarse, non-identifying device profile for the admin Devices page.
    private void SendDeviceProfile()
    {
        if (!_telemetry) return;
        _ = Task.Run(() =>
        {
            try
            {
                var arch = System.Runtime.InteropServices.RuntimeInformation.OSArchitecture.ToString();
                long ramMb = 0;
                try { ramMb = GC.GetGCMemoryInfo().TotalAvailableMemoryBytes / (1024 * 1024); } catch { }
                string screen = ""; int monitors = 0;
                try
                {
                    var b = Screen.PrimaryScreen?.Bounds;
                    if (b.HasValue) screen = b.Value.Width + "x" + b.Value.Height;
                    monitors = Screen.AllScreens.Length;
                }
                catch { }
                string lang = ""; string tz = "";
                try { lang = System.Globalization.CultureInfo.CurrentUICulture.Name; } catch { }
                try { tz = TimeZoneInfo.Local.Id; } catch { }
                var payload = new
                {
                    key = "apnescan-telemetry-v1", install = InstallId(), os = OsStr(), arch,
                    cpu_cores = Environment.ProcessorCount, ram_mb = ramMb, screen, monitors, lang, tz, scanner = _lastScanner
                };
                using var content = new StringContent(JsonSerializer.Serialize(payload), Encoding.UTF8, "application/json");
                _tele.PostAsync(DeviceUrl, content).GetAwaiter().GetResult();
            }
            catch { }
        });
    }

    private void SendTelemetry(string ev, int count = 1)
    {
        if (!_telemetry) return;
        _ = Task.Run(async () =>
        {
            try
            {
                var payload = new { key = "apnescan-telemetry-v1", install = InstallId(), @event = ev, count, version = AppVer(), os = OsStr() };
                using var content = new StringContent(JsonSerializer.Serialize(payload), Encoding.UTF8, "application/json");
                await _tele.PostAsync(TelemetryUrl, content);
            }
            catch { /* telemetry is best-effort; never disturb the user */ }
        });
    }

    // Blocking send — used on shutdown when there's no time for a background task.
    private void SendTelemetrySync(string ev, int count = 1)
    {
        if (!_telemetry) return;
        try
        {
            var payload = new { key = "apnescan-telemetry-v1", install = InstallId(), @event = ev, count, version = AppVer(), os = OsStr() };
            using var content = new StringContent(JsonSerializer.Serialize(payload), Encoding.UTF8, "application/json");
            _tele.PostAsync(TelemetryUrl, content).GetAwaiter().GetResult();
        }
        catch { }
    }

    // Live-presence heartbeat so the dashboard can show "online now".
    private void PingLive()
    {
        if (!_telemetry) return;
        _ = Task.Run(async () =>
        {
            try
            {
                var payload = new { key = "apnescan-telemetry-v1", install = InstallId(), @event = "ping", count = 1, version = AppVer(), os = OsStr() };
                using var content = new StringContent(JsonSerializer.Serialize(payload), Encoding.UTF8, "application/json");
                await _tele.PostAsync(TelemetryUrl, content);
            }
            catch { }
        });
    }

    // Send free-text feedback the user typed in the app.
    private void SendFeedback(string message, string contact)
    {
        if (string.IsNullOrWhiteSpace(message)) { Status("Please type your feedback first."); return; }
        _ = Task.Run(async () =>
        {
            try
            {
                var payload = new { key = "apnescan-telemetry-v1", install = InstallId(), version = AppVer(), message, contact };
                using var content = new StringContent(JsonSerializer.Serialize(payload), Encoding.UTF8, "application/json");
                await _tele.PostAsync(FeedbackUrl, content);
            }
            catch { }
        });
        Status("Thanks! Your feedback was sent. 🙏");
    }

    // Compare two dotted versions. Returns <0, 0 or >0.
    private static int CompareVersions(string a, string b)
    {
        try { return (Version.TryParse(a, out var va) ? va : new Version(0, 0)).CompareTo(Version.TryParse(b, out var vb) ? vb : new Version(0, 0)); }
        catch { return 0; }
    }

    // Pull remote config (broadcast banner, min version, feature flags) and hand
    // it to the UI. Skipped entirely when the user opted out of phoning home.
    private async Task PollRemoteConfigAsync()
    {
        if (!_telemetry) return;
        try
        {
            var url = ConfigUrl + "?install=" + Uri.EscapeDataString(InstallId()) + "&version=" + Uri.EscapeDataString(AppVer());
            var json = await _tele.GetStringAsync(url);
            using var doc = JsonDocument.Parse(json);
            var root = doc.RootElement;
            if (!root.TryGetProperty("ok", out var ok) || ok.ValueKind != JsonValueKind.True) return;

            string msg = root.TryGetProperty("message", out var m) ? (m.GetString() ?? "") : "";
            int msgId = root.TryGetProperty("messageId", out var mi) && mi.ValueKind == JsonValueKind.Number ? mi.GetInt32() : 0;
            string style = root.TryGetProperty("messageType", out var mt) ? (mt.GetString() ?? "info") : "info";
            string minV = root.TryGetProperty("minVersion", out var mv) ? (mv.GetString() ?? "") : "";
            bool force = root.TryGetProperty("forceUpdate", out var fu) && fu.ValueKind == JsonValueKind.True;
            string dl = root.TryGetProperty("downloadUrl", out var du) ? (du.GetString() ?? "") : "";
            string flags = root.TryGetProperty("flags", out var fl) ? fl.GetRawText() : "{}";

            if (msg.Length > 0)
                Post(new { type = "banner", id = msgId, style, text = msg });
            if (!string.IsNullOrEmpty(minV) && CompareVersions(AppVer(), minV) < 0)
                Post(new { type = "forceUpdate", force, url = dl, min = minV });
            Post(new { type = "flags", flags });
        }
        catch { }
    }

    // Static crash reporter (called from Program's global handler). Reads the
    // install id + telemetry opt-out straight from disk since the form may be
    // in an unusable state. Best-effort and short-timeout.
    public static void ReportCrash(Exception? ex)
    {
        try
        {
            var dir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "ApneScan");
            var setFile = Path.Combine(dir, "settings.json");
            if (File.Exists(setFile))
            {
                var txt = File.ReadAllText(setFile).Replace(" ", "");
                if (txt.Contains("\"telemetry\":false")) return; // respect opt-out
            }
            var install = "anon";
            var idf = Path.Combine(dir, "install.id");
            if (File.Exists(idf)) { var s = File.ReadAllText(idf).Trim(); if (s.Length > 0) install = s; }
            var payload = new { key = "apnescan-telemetry-v1", install, @event = "crash", count = 1, version = AppVer(), os = OsStr() };
            using var c = new HttpClient { Timeout = TimeSpan.FromSeconds(4) };
            using var content = new StringContent(JsonSerializer.Serialize(payload), Encoding.UTF8, "application/json");
            c.PostAsync(TelemetryUrl, content).GetAwaiter().GetResult();
        }
        catch { }
    }

    // Commands that are passive UI reads / polling — not user actions worth
    // tracking. Everything else that comes through the bridge is reported.
    private static readonly HashSet<string> _noTrack = new(StringComparer.OrdinalIgnoreCase)
    {
        "getDevices", "getSettings", "getStorage", "getProfiles", "getAnalytics",
        "getHistory", "getNames", "getFavs", "getShortcuts", "getText", "getRecent",
        "getThumb", "getSubfolders", "listFolder", "previewFile", "select",
        "checkUpdate", "browseFolder", "cancelScan"
    };

    private void TrackAction(string cmd)
    {
        if (string.IsNullOrEmpty(cmd) || _noTrack.Contains(cmd)) return;
        SendTelemetry(cmd);
    }

    private async void OnMessage(object? sender, CoreWebView2WebMessageReceivedEventArgs e)
    {
        string cmd;
        int deviceIndex = 0;
        int dpi = 200;
        string color = "color";
        string source = "auto";
        string pageSize = "auto";
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
        bool telemetry = true;
        bool autoCrop = true, skipBlank = false;
        int compressPercent = 0;
        string data = "";
        string ctx = "";
        string op = "";
        string footerText = "";
        string uiExtra = "";   // opaque JSON blob of extra interface prefs (accent, thumb size, density, toggles…)
        string ocrLang = "eng";
        string ocrEngine = "tesseract";
        int amount = 0;
        long target = 0;
        var indices = new List<int>();
        List<double>? pts = null;   // perspective corner points (8 normalized values)
        int adjB = 0, adjC = 0, adjS = 0;   // live brightness / contrast / sharpen amounts
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
            if (root.TryGetProperty("pageSize", out var psz) && psz.ValueKind == JsonValueKind.String) pageSize = psz.GetString() ?? "auto";
            if (root.TryGetProperty("on", out var onEl) && (onEl.ValueKind == JsonValueKind.True || onEl.ValueKind == JsonValueKind.False)) on = onEl.GetBoolean();
            if (root.TryGetProperty("theme", out var thEl) && thEl.ValueKind == JsonValueKind.String) theme = thEl.GetString() ?? "default";
            if (root.TryGetProperty("saveDefault", out var sdEl) && sdEl.ValueKind == JsonValueKind.String) saveDefault = sdEl.GetString() ?? "ask";
            if (root.TryGetProperty("showNums", out var snEl) && (snEl.ValueKind == JsonValueKind.True || snEl.ValueKind == JsonValueKind.False)) showNums = snEl.GetBoolean();
            if (root.TryGetProperty("showProfiles", out var spEl) && (spEl.ValueKind == JsonValueKind.True || spEl.ValueKind == JsonValueKind.False)) showProfiles = spEl.GetBoolean();
            if (root.TryGetProperty("autoName", out var anEl) && (anEl.ValueKind == JsonValueKind.True || anEl.ValueKind == JsonValueKind.False)) autoName = anEl.GetBoolean();
            if (root.TryGetProperty("clearAfter", out var caEl) && (caEl.ValueKind == JsonValueKind.True || caEl.ValueKind == JsonValueKind.False)) clearAfter = caEl.GetBoolean();
            if (root.TryGetProperty("autoCrop", out var acEl) && (acEl.ValueKind == JsonValueKind.True || acEl.ValueKind == JsonValueKind.False)) autoCrop = acEl.GetBoolean();
            if (root.TryGetProperty("skipBlank", out var sbEl) && (sbEl.ValueKind == JsonValueKind.True || sbEl.ValueKind == JsonValueKind.False)) skipBlank = sbEl.GetBoolean();
            if (root.TryGetProperty("telemetry", out var tmEl) && (tmEl.ValueKind == JsonValueKind.True || tmEl.ValueKind == JsonValueKind.False)) telemetry = tmEl.GetBoolean();
            if (root.TryGetProperty("compressPercent", out var cpEl) && cpEl.ValueKind == JsonValueKind.Number) compressPercent = cpEl.GetInt32();
            if (root.TryGetProperty("footerText", out var fxEl) && fxEl.ValueKind == JsonValueKind.String) footerText = fxEl.GetString() ?? "";
            if (root.TryGetProperty("uiExtra", out var uxEl) && uxEl.ValueKind == JsonValueKind.String) uiExtra = uxEl.GetString() ?? "";
            if (root.TryGetProperty("ocrLang", out var olEl) && olEl.ValueKind == JsonValueKind.String) ocrLang = olEl.GetString() ?? "eng";
            if (root.TryGetProperty("ocrEngine", out var oeEl) && oeEl.ValueKind == JsonValueKind.String) ocrEngine = oeEl.GetString() ?? "tesseract";
            if (root.TryGetProperty("target", out var tgEl) && tgEl.ValueKind == JsonValueKind.Number) target = tgEl.GetInt64();
            if (root.TryGetProperty("op", out var opEl) && opEl.ValueKind == JsonValueKind.String) op = opEl.GetString() ?? "";
            if (root.TryGetProperty("amount", out var amtEl) && amtEl.ValueKind == JsonValueKind.Number) amount = amtEl.GetInt32();
            if (root.TryGetProperty("b", out var adjBEl) && adjBEl.ValueKind == JsonValueKind.Number) adjB = adjBEl.GetInt32();
            if (root.TryGetProperty("c", out var adjCEl) && adjCEl.ValueKind == JsonValueKind.Number) adjC = adjCEl.GetInt32();
            if (root.TryGetProperty("s", out var adjSEl) && adjSEl.ValueKind == JsonValueKind.Number) adjS = adjSEl.GetInt32();
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
            if (root.TryGetProperty("pts", out var ptArr) && ptArr.ValueKind == JsonValueKind.Array)
            {
                pts = new List<double>();
                foreach (var el in ptArr.EnumerateArray())
                    if (el.ValueKind == JsonValueKind.Number) pts.Add(el.GetDouble());
            }
        }
        catch
        {
            return;
        }

        // Report every real user action to the anonymous analytics endpoint so
        // the admin dashboard reflects what people actually do. Passive UI
        // queries / polling (reading settings, thumbnails, lists) are skipped so
        // the stats stay meaningful and low-noise.
        TrackAction(cmd);

        switch (cmd)
        {
            case "getDevices":
                await SendDevicesAsync();
                break;
            case "scan":
                await ScanAsync(deviceIndex, dpi, color, source, pageSize);
                break;
            case "cancelScan":
                if (_scanCts != null)
                {
                    Status("Cancelling scan…");
                    ScanStatus("busy", "Cancelling…");
                    try { _scanCts.Cancel(); } catch { }
                }
                break;
            case "savePdf":
                await SavePdfAsync();
                break;
            case "savePdfSelected":
                await SavePdfSelectedAsync(indices);
                break;
            case "print":
                PrintPages(string.IsNullOrEmpty(op) ? "all" : op, indices);
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
            case "shareWhatsapp":
                await ShareWhatsAppAsync(filePath, indices);
                break;
            case "shareWindows":
                await ShareWindowsAsync(filePath, indices);
                break;
            case "detectTypes":
                await DetectTypesAsync();
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
            case "redo":
                await RedoAsync();
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
                    Dpi = dpi, Color = color, Source = source, PageSize = pageSize, Ocr = on, Device = deviceName,
                    Theme = theme, ShowNums = showNums, ShowProfiles = showProfiles,
                    SaveDefault = saveDefault, AutoName = autoName, ClearAfter = clearAfter,
                    AutoCrop = autoCrop, SkipBlank = skipBlank, CompressPercent = compressPercent,
                    FooterText = footerText, Telemetry = telemetry, UiExtra = uiExtra,
                    OcrLang = SanitizeOcrLang(ocrLang), OcrEngine = SanitizeEngine(ocrEngine)
                });
                _ocrLang = SanitizeOcrLang(ocrLang);
                _ocrEngine = SanitizeEngine(ocrEngine);
                break;
            case "getLibraryStats":
                await SendLibraryStatsAsync();
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
            case "compressPdf":
                if (target > 0) await CompressPdfToTargetAsync(filePath, target);
                else await CompressPdfFileAsync(filePath, amount);
                break;
            case "renameItem":
                RenameItem(filePath, name);
                break;
            case "deleteFile":
                DeleteItems(PathsFrom(data, filePath));
                break;
            case "moveFile":
                MoveOrCopyItems(PathsFrom(data, filePath), move: true);
                break;
            case "copyFile":
                MoveOrCopyItems(PathsFrom(data, filePath), move: false);
                break;
            case "duplicateFile":
                DuplicateItem(filePath);
                break;
            case "revealFile":
                RevealItem(filePath);
                break;
            case "copyPath":
                CopyPathToClipboard(filePath);
                break;
            case "mergePdfs":
                await MergePdfsAsync(PathsFrom(data, filePath));
                break;
            case "splitPdf":
                await SplitPdfAsync(filePath);
                break;
            case "pdfToImages":
                await PdfToImagesAsync(filePath);
                break;
            case "imagesToPdf":
                await ImagesToPdfAsync(PathsFrom(data, filePath));
                break;
            case "addScanned":
                await AddScannedToPdfAsync(filePath);
                break;
            case "printFile":
                await PrintFileAsync(filePath);
                break;
            case "sharePhone":
                SharePhoneFile(filePath);
                break;
            case "getThumb":
                await SendFileThumbAsync(filePath);
                break;
            case "fileInfo":
                SendFileInfo(filePath);
                break;
            case "searchAll":
                await SearchAllAsync(filePath, name);
                break;
            case "getRecent":
                await GetRecentAsync();
                break;
            case "getSubfolders":
                SendSubfolders(filePath);
                break;
            case "contentSearch":
                await ContentSearchAsync(filePath, name);
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
            case "savePagesToFolder":
                await SavePagesToFolderAsync(filePath, indices);
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
            case "renameName":
                RenameName(filePath, name);
                break;
            case "toggleNameFav":
                ToggleNameFav(name);
                break;
            case "setNameCat":
                SetNameCat(name, data);
                break;
            case "reorderNames":
                ReorderNames(data);
                break;
            case "bulkAddNames":
                BulkAddNames(data);
                break;
            case "exportNames":
                ExportNames();
                break;
            case "importNames":
                ImportNames();
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
            case "perspective":
                await PerspectiveWarpAsync(pts);
                break;
            case "detectCorners":
                await DetectCornersAsync();
                break;
            case "adjustPreview":
                await AdjustPreviewAsync(adjB, adjC, adjS);
                break;
            case "adjustApply":
                await AdjustApplyAsync(adjB, adjC, adjS);
                break;
            case "applyImage":
                await ReplacePageImageAsync(dataUrl);
                break;
            case "pageOp":
                await PageOpAsync(op, amount, indices);
                break;
            case "exportPageImage":
                await ExportPagesImageAsync(indices, format);
                break;
            case "copyPages":
                CopyPages(indices);
                break;
            case "pastePages":
                await PastePagesAsync();
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
            case "sendFeedback":
                SendFeedback(data, name);
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

    // Map a page-size key to physical dimensions. "auto" captures the scanner's
    // full area (driver clamps to the device maximum).
    private static PageSize ParsePageSize(string s) => s switch
    {
        "a4" => new PageSize(210m, 297m, PageSizeUnit.Millimetre),
        "a5" => new PageSize(148m, 210m, PageSizeUnit.Millimetre),
        "letter" => new PageSize(8.5m, 11m, PageSizeUnit.Inch),
        "legal" => new PageSize(8.5m, 14m, PageSizeUnit.Inch),
        _ => new PageSize(14m, 22m, PageSizeUnit.Inch)
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

    private static string DriverTag(Driver d) => d switch
    {
        Driver.Wia => "WIA",
        Driver.Twain => "TWAIN",
        Driver.Escl => "Network",
        Driver.Sane => "SANE",
        _ => d.ToString()
    };

    // Human label for a device — adds the driver tag only when the same scanner
    // name shows up under more than one driver, so the list stays clean.
    private string DeviceLabel(ScanDevice d)
    {
        bool dup = _devices.Count(x => string.Equals(x.Name, d.Name, StringComparison.OrdinalIgnoreCase)) > 1;
        return dup ? $"{d.Name} · {DriverTag(d.Driver)}" : d.Name;
    }

    // The WIA API version each discovered WIA device was found under, aligned
    // 1:1 with _devices (Default for non-WIA), so a device enumerated only under
    // WIA 1.0 (often the fast USB path) is also scanned under WIA 1.0.
    private List<WiaApiVersion> _deviceWia = new();

    // Enumerate with the given options and a timeout so a slow/hanging backend
    // (TWAIN or network discovery) never blocks the whole scan-list refresh.
    private async Task<List<ScanDevice>> EnumOptsAsync(ScanController controller, ScanOptions options, int timeoutMs)
    {
        var found = new List<ScanDevice>();
        using var cts = new CancellationTokenSource(timeoutMs);
        try
        {
            await foreach (var d in controller.GetDevices(options, cts.Token))
                found.Add(d);
        }
        catch { /* driver unavailable / timed out — return whatever was found */ }
        return found;
    }

    private async Task SendDevicesAsync()
    {
        try
        {
            Status("Looking for scanners…");
            var controller = new ScanController(_ctx);
            // Scan every Windows path so USB (WIA 1.0/2.0, TWAIN) and network
            // (ESCL) scanners all show up — not just the default WIA 2.0 list.
            var merged = new List<(ScanDevice dev, WiaApiVersion wia)>();
            void AddAll(IEnumerable<ScanDevice> list, WiaApiVersion wia)
            {
                foreach (var d in list)
                    if (!merged.Any(m => m.dev.Driver == d.Driver && m.dev.ID == d.ID))
                        merged.Add((d, wia));
            }
            // Both WIA versions — the same scanner's USB and network endpoints
            // often live under different WIA versions.
            foreach (var wv in new[] { WiaApiVersion.Wia20, WiaApiVersion.Wia10 })
            {
                var opts = new ScanOptions { Driver = Driver.Wia };
                opts.WiaOptions.WiaApiVersion = wv;
                AddAll(await EnumOptsAsync(controller, opts, 12000), wv);
            }
            AddAll(await EnumOptsAsync(controller, new ScanOptions { Driver = Driver.Twain }, 12000), WiaApiVersion.Default);
            AddAll(await EnumOptsAsync(controller, new ScanOptions { Driver = Driver.Escl }, 6000), WiaApiVersion.Default);

            _devices = merged.Select(m => m.dev).ToList();
            _deviceWia = merged.Select(m => m.wia).ToList();
            Post(new { type = "devices", devices = _devices.Select(DeviceLabel).ToArray() });
            if (_devices.Count == 0) { Status("No scanner found"); ScanStatus("offline", "No scanner"); }
            else { Status($"{DeviceLabel(_devices[0])} · Ready"); ScanStatus("ready", $"{_devices.Count} scanner(s) · Ready"); }
        }
        catch (Exception ex)
        {
            Status("Error: " + ex.Message);
            ScanStatus("error", "Error: " + FriendlyScanError(ex));
        }
    }

    // Turn a driver exception into a short, human message for the status badge.
    private static string FriendlyScanError(Exception ex)
    {
        var m = (ex.Message ?? "").ToLowerInvariant();
        var type = ex.GetType().Name.ToLowerInvariant();
        if (m.Contains("in use") || m.Contains("busy") || m.Contains("locked")) return "Busy — in use by another app";
        if (m.Contains("paper") && (m.Contains("jam"))) return "Paper jam";
        if (m.Contains("no paper") || m.Contains("empty") || type.Contains("paperempty")) return "No paper in feeder";
        if (m.Contains("cover") || m.Contains("lid")) return "Cover is open";
        if (m.Contains("offline") || m.Contains("not available") || m.Contains("disconnect") || type.Contains("offline")) return "Scanner offline";
        if (m.Contains("no device") || type.Contains("nodevices")) return "No scanner found";
        if (m.Contains("cancel")) return "Scan cancelled";
        var one = (ex.Message ?? "Scanner error").Split('\n')[0];
        return one.Length > 60 ? one[..60] + "…" : one;
    }

    private async Task ScanAsync(int deviceIndex, int dpi, string color, string source, string pageSize = "auto")
    {
        if (_busy)
        {
            return;
        }
        if (_devices.Count == 0)
        {
            Status("No scanner found");
            ScanStatus("offline", "No scanner");
            return;
        }
        if (deviceIndex < 0 || deviceIndex >= _devices.Count)
        {
            deviceIndex = 0;
        }

        _busy = true;
        _scanCts = new CancellationTokenSource();
        var token = _scanCts.Token;
        ScanStatus("busy", "Busy · Scanning…");
        bool duplex = (source ?? "").IndexOf("duplex", StringComparison.OrdinalIgnoreCase) >= 0;
        // Rich "scan begin" event drives the premium full-screen progress overlay.
        Post(new
        {
            type = "scanBegin",
            scanner = _devices[deviceIndex].Name,
            dpi = dpi > 0 ? dpi : 200,
            color = string.IsNullOrEmpty(color) ? "color" : color,
            source = string.IsNullOrEmpty(source) ? "auto" : source,
            duplex,
            startCount = _pages.Count
        });
        Post(new { type = "scanProgress", count = 0, done = false });   // legacy hero progress (fallback)
        Post(new { type = "scanStage", stage = "feeding", op = "Feeding paper…" });
        bool cancelled = false;
        int added = 0, skipped = 0;
        try
        {
            Status("Scanning…");
            PushUndo();
            var controller = new ScanController(_ctx);
            var options = new ScanOptions
            {
                Device = _devices[deviceIndex],
                // Use the driver the chosen device was discovered under, otherwise
                // a TWAIN/network device would be scanned with the wrong (WIA) driver.
                Driver = _devices[deviceIndex].Driver,
                PaperSource = ParseSource(source),
                // "auto" scans the scanner's full area (driver clamps to the
                // device max); named sizes constrain to that paper size.
                PageSize = ParsePageSize(pageSize),
                BitDepth = ParseColor(color),
                Dpi = dpi > 0 ? dpi : 200
            };
            // Scan a WIA device under the same WIA version it was discovered on,
            // so the fast USB (often WIA 1.0) path is used instead of the slower
            // network one.
            if (options.Driver == Driver.Wia && deviceIndex >= 0 && deviceIndex < _deviceWia.Count)
                options.WiaOptions.WiaApiVersion = _deviceWia[deviceIndex];

            var scanSw = Stopwatch.StartNew();
            // The background page grid is rebuilt (all thumbnails) on each
            // RefreshAsync, so doing it per page is O(n²) and stalls the ADF
            // between pages. Throttle it to ~1.2s during the scan — the live
            // overlay is driven by the lightweight scanPage events below, and a
            // final RefreshAsync after the loop rebuilds the grid exactly once.
            var refreshSw = Stopwatch.StartNew();
            await foreach (var image in controller.Scan(options, token))
            {
                Post(new { type = "scanStage", stage = "capturing", op = "Capturing page…" });
                Post(new { type = "scanStage", stage = "process", op = "Auto-crop · straighten · blank check…" });
                var (proc, blank) = await PostProcessScanAsync(image);
                if (blank && _skipBlank)
                {
                    proc.Dispose();
                    skipped++;
                    Post(new { type = "scanProgress", count = added, skipped, done = false });
                    Post(new { type = "scanPage", blank = true, skipped, index = added });
                    continue;
                }
                _pages.Add(proc);
                added++;
                _selected = _pages.Count - 1;
                // Live thumbnail into the overlay strip (cheap, single image) and
                // the progress counter — this is what the user sees while scanning.
                var thumb = await PageThumbAsync(proc, 240);
                ScanStatus("busy", $"Busy · Scanning… {added} page(s)");
                Post(new { type = "scanProgress", count = added, skipped, done = false });
                Post(new { type = "scanPage", index = added - 1, page = added, thumb, blank = false, skipped });
                Post(new { type = "scanStage", stage = "feeding", op = "Ready for next page…" });
                // Only occasionally refresh the (hidden) main grid so a user who
                // chose "Continue working" still sees pages appear.
                if (refreshSw.ElapsedMilliseconds > 1200) { await RefreshAsync(true); refreshSw.Restart(); }
            }

            if (added == 0)
            {
                Status(skipped > 0 ? $"Only blank page(s) found — skipped {skipped}" : "Nothing was scanned");
                if (skipped > 0) SendTelemetry("blank_skipped", skipped);
                Post(new { type = "scanDone", added = 0, skipped, ms = (int)scanSw.ElapsedMilliseconds, cancelled = false, empty = true });
                return;
            }
            if (skipped > 0)
            {
                Status($"Skipped {skipped} blank page(s)");
            }

            scanSw.Stop();
            Post(new { type = "scanStage", stage = "saving", op = "Finishing…" });
            await RefreshAsync(true);
            _ = AutoNameAsync();
            Bump("scan", added);
            AddLifetimePages(added);                                     // persist running total for Settings stats
            SendTelemetry("pages_scanned", added);                       // total pages captured
            if (skipped > 0) SendTelemetry("blank_skipped", skipped);    // blank pages auto-skipped
            SendTelemetry("src_" + (string.IsNullOrEmpty(source) ? "auto" : source)); // flatbed/feeder/auto/duplex
            SendTelemetry("dpi_" + (dpi > 0 ? dpi : 200));               // scan resolution
            SendTelemetry("color_" + (string.IsNullOrEmpty(color) ? "color" : color)); // color/gray/bw
            var scanMs = (int)Math.Min(scanSw.ElapsedMilliseconds, 100000);
            if (scanMs > 0) SendTelemetry("scan_ms", scanMs);           // total scan time (avg = scan_ms / scan)
            _lastScanner = _devices[deviceIndex].Name;                   // remember scanner model
            SendDeviceProfile();
            Status($"{_pages.Count} page(s) ready. Use Save or Print.");
            ScanStatus("ready", "Free · Ready");
            int ppm = scanMs > 0 ? (int)Math.Round(added / (scanMs / 60000.0)) : 0;
            Post(new { type = "scanDone", added, skipped, ms = scanMs, ppm, scanner = _devices[deviceIndex].Name, dpi = dpi > 0 ? dpi : 200, color = string.IsNullOrEmpty(color) ? "color" : color, duplex, cancelled = false, empty = false });
        }
        catch (OperationCanceledException)
        {
            // User pressed Cancel mid-scan. Keep whatever pages already came in.
            cancelled = true;
            await RefreshAsync(true);
            Status(added > 0 ? $"Scan cancelled — kept {added} page(s)" : "Scan cancelled");
            ScanStatus("ready", "Free · Ready");
            Post(new { type = "scanDone", added, skipped, ms = 0, cancelled = true, empty = added == 0 });
        }
        catch (Exception ex)
        {
            Status("Scan error: " + ex.Message);
            ScanStatus("error", "Error: " + FriendlyScanError(ex));
            Post(new { type = "scanFail", message = FriendlyScanError(ex), added, skipped });
        }
        finally
        {
            Post(new { type = "scanProgress", count = added, skipped, done = true, cancelled });
            _busy = false;
            _scanCts?.Dispose();
            _scanCts = null;
        }
    }

    // Render a page to a small PNG data URL for the live scan-progress strip.
    private async Task<string> PageThumbAsync(ProcessedImage p, int size)
    {
        try
        {
            var renderer = new ThumbnailRenderer(_ctx.ImageContext);
            using var thumb = await renderer.Render(p, size);
            var tmp = System.IO.Path.Combine(System.IO.Path.GetTempPath(),
                "apnescan_pt_" + Guid.NewGuid().ToString("N")[..8] + ".png");
            thumb.Save(tmp);
            var bytes = await File.ReadAllBytesAsync(tmp);
            try { File.Delete(tmp); } catch { /* best-effort */ }
            return "data:image/png;base64," + Convert.ToBase64String(bytes);
        }
        catch { return ""; }
    }

    // Auto-crop blank borders and detect blank pages by analysing a small
    // rendering of the scanned page.
    private async Task<(ProcessedImage img, bool blank)> PostProcessScanAsync(ProcessedImage p)
    {
        double l = 0, t = 0, r = 1, b = 1, coverage = 1, strong = 1;
        try
        {
            var renderer = new ThumbnailRenderer(_ctx.ImageContext);
            using var thumb = await renderer.Render(p, 500);
            using var bmp = ToBitmap24(thumb);
            (l, t, r, b, coverage, strong) = ContentBounds(bmp);
        }
        catch
        {
            return (p, false);
        }

        // Blank = almost no genuinely dark (content) pixels away from the edges.
        // Using a strict darkness cut-off ignores paper tint, scanner noise and
        // edge shadows that used to keep truly blank pages from being skipped.
        if (strong < 0.004)
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
    private static (double l, double t, double r, double b, double coverage, double strong) ContentBounds(System.Drawing.Bitmap bmp)
    {
        int w = bmp.Width, h = bmp.Height;
        var data = bmp.LockBits(new System.Drawing.Rectangle(0, 0, w, h),
            System.Drawing.Imaging.ImageLockMode.ReadOnly, System.Drawing.Imaging.PixelFormat.Format24bppRgb);
        int stride = data.Stride;
        var buf = new byte[stride * h];
        System.Runtime.InteropServices.Marshal.Copy(data.Scan0, buf, 0, buf.Length);
        bmp.UnlockBits(data);

        var rowCount = new int[h];   // pixels lum<215 per row (light-ish content)
        var colCount = new int[w];
        // Per-row / per-col luminance min, max and sum — used to detect flat,
        // uniform scanner "bands" (a solid strip of one colour) at the edges.
        var rowMin = new int[h]; var rowMax = new int[h]; var rowSum = new long[h];
        var colMin = new int[w]; var colMax = new int[w]; var colSum = new long[w];
        for (int y = 0; y < h; y++) { rowMin[y] = 255; }
        for (int x = 0; x < w; x++) { colMin[x] = 255; }
        long dark = 0;
        for (int y = 0; y < h; y++)
        {
            int row = y * stride;
            for (int x = 0; x < w; x++)
            {
                int o = row + x * 3;
                int lum = (buf[o] + buf[o + 1] + buf[o + 2]) / 3;
                if (lum < 215) { rowCount[y]++; colCount[x]++; dark++; }
                if (lum < rowMin[y]) rowMin[y] = lum; if (lum > rowMax[y]) rowMax[y] = lum; rowSum[y] += lum;
                if (lum < colMin[x]) colMin[x] = lum; if (lum > colMax[x]) colMax[x] = lum; colSum[x] += lum;
            }
        }

        // A "band" row/col is a flat, uniform strip whose colour differs from the
        // white paper — i.e. the scanner's grey/black backing showing past a short
        // sheet, or an edge shadow. It is uniform (small max−min) and clearly not
        // paper-white (mean < 205). Structured content (text, X-ray anatomy) has a
        // wide max−min, so it is never mistaken for a band. Contiguous from the edge.
        bool RowBand(int y) { return (rowMax[y] - rowMin[y]) <= 55 && (rowSum[y] / (double)w) < 205; }
        bool ColBand(int x) { return (colMax[x] - colMin[x]) <= 55 && (colSum[x] / (double)h) < 205; }
        int maxBandY = (int)(h * 0.35), maxBandX = (int)(w * 0.35);
        int topB = 0;    for (int y = 0; y < maxBandY; y++) { if (RowBand(y)) topB = y + 1; else break; }
        int botB = 0;    for (int y = h - 1; y >= h - maxBandY && y >= 0; y--) { if (RowBand(y)) botB = h - y; else break; }
        int leftB = 0;   for (int x = 0; x < maxBandX; x++) { if (ColBand(x)) leftB = x + 1; else break; }
        int rightB = 0;  for (int x = w - 1; x >= w - maxBandX && x >= 0; x--) { if (ColBand(x)) rightB = w - x; else break; }

        // Analysis region excludes the detected bands.
        int y0 = topB, y1 = h - botB, x0 = leftB, x1 = w - rightB;
        if (y1 - y0 < 8 || x1 - x0 < 8) { y0 = 0; y1 = h; x0 = 0; x1 = w; topB = botB = leftB = rightB = 0; }

        // Ignore a 2% border (relative to the region) when judging "blank".
        int rw = x1 - x0, rh = y1 - y0;
        int mx = (int)(rw * 0.02), my = (int)(rh * 0.02);
        long strong = 0; // genuinely dark content inside the region, for blank detection
        for (int y = y0 + my; y < y1 - my; y++)
        {
            int row = y * stride;
            for (int x = x0 + mx; x < x1 - mx; x++)
            {
                int o = row + x * 3;
                int lum = (buf[o] + buf[o + 1] + buf[o + 2]) / 3;
                if (lum < 150) strong++;
            }
        }

        int rowThresh = Math.Max(2, (int) (rw * 0.004));
        int colThresh = Math.Max(2, (int) (rh * 0.004));
        int minX = -1, maxX = -1, minY = -1, maxY = -1;
        for (int y = y0; y < y1; y++) { if (rowCount[y] > rowThresh) { if (minY < 0) minY = y; maxY = y; } }
        for (int x = x0; x < x1; x++) { if (colCount[x] > colThresh) { if (minX < 0) minX = x; maxX = x; } }
        double coverage = (double) dark / ((long) w * h);
        long inner = Math.Max(1, (long)(rw - 2 * mx) * (rh - 2 * my));
        double strongCov = (double) strong / inner;
        if (minX < 0 || minY < 0)
        {
            // No light content in the region → treat the region (sans bands) as the crop box.
            return ((double) x0 / w, (double) y0 / h, (double) x1 / w, (double) y1 / h, coverage, strongCov);
        }
        return ((double) minX / w, (double) minY / h, (double) (maxX + 1) / w, (double) (maxY + 1) / h, coverage, strongCov);
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
            var ocrParams = _ocr ? new OcrParams(_ocrLang) : null;
            await ExportPdf(sfd.FileName, _pages, ocrParams);
            AddHistory(sfd.FileName, _pages.Count);
            Bump("pdf", 1);
            try { var kb = (int)Math.Min(new FileInfo(sfd.FileName).Length / 1024, 100000); if (kb > 0) SendTelemetry("pdf_kb", kb); } catch { }
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
            var ocrParams = _ocr ? new OcrParams(_ocrLang) : null;
            await ExportPdf(sfd.FileName, pages, ocrParams);
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
            var ocrParams = _ocr ? new OcrParams(_ocrLang) : null;
            await ExportPdf(path, _pages, ocrParams);

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

    // Share a document to WhatsApp. WhatsApp has no link that can pre-attach a
    // file, so the file is placed on the clipboard and WhatsApp is opened
    // directly (the desktop app if installed, otherwise WhatsApp Web). The user
    // picks a chat and presses Ctrl+V to attach it, then sends.
    private async Task ShareWhatsAppAsync(string path, List<int> indices)
    {
        string file = path ?? "";
        try
        {
            // From the Scanned Pages area no path is given — export the selected
            // page(s) to a temporary PDF first.
            if (string.IsNullOrWhiteSpace(file))
            {
                if (_pages.Count == 0) { Status("Nothing to share — scan a page first"); return; }
                var idx = indices.Where(i => i >= 0 && i < _pages.Count).Distinct().OrderBy(i => i).ToList();
                if (idx.Count == 0) { int s = Sel(); if (s >= 0) idx.Add(s); }
                if (idx.Count == 0) { Status("No pages to share"); return; }
                SyncNames();
                var nm = (idx[0] < _pageNames.Count && !string.IsNullOrWhiteSpace(_pageNames[idx[0]]))
                    ? SanitizeFileName(_pageNames[idx[0]]) : "scan";
                file = Path.Combine(Path.GetTempPath(), nm + "_" + Guid.NewGuid().ToString("N")[..6] + ".pdf");
                var pages = idx.Select(i => _pages[i]).ToList();
                var ocrParams = _ocr ? new OcrParams(_ocrLang) : null;
                Status("Preparing file for WhatsApp…");
                await ExportPdf(file, pages, ocrParams);
            }
            if (string.IsNullOrWhiteSpace(file) || !File.Exists(file)) { Status("File not found to share"); return; }

            // Put the file on the clipboard so a single Ctrl+V attaches it.
            bool onClip = false;
            try
            {
                var col = new System.Collections.Specialized.StringCollection { file };
                Clipboard.SetFileDropList(col);
                onClip = true;
            }
            catch { /* clipboard is best-effort */ }

            // Open the WhatsApp desktop app if its URL protocol is registered,
            // otherwise fall back to WhatsApp Web in the browser.
            bool desktop = false;
            try { using var k = Microsoft.Win32.Registry.ClassesRoot.OpenSubKey("whatsapp"); desktop = k != null; } catch { }
            try
            {
                Process.Start(new ProcessStartInfo
                {
                    FileName = desktop ? "whatsapp://" : "https://web.whatsapp.com/",
                    UseShellExecute = true
                });
            }
            catch
            {
                Process.Start(new ProcessStartInfo { FileName = "https://web.whatsapp.com/", UseShellExecute = true });
            }

            Status(onClip
                ? "WhatsApp opened — pick a chat and press Ctrl+V to attach, then Send"
                : "WhatsApp opened — attach the file to a chat and send");
        }
        catch (Exception ex)
        {
            Status("WhatsApp share error: " + ex.Message);
        }
    }

    private static readonly Guid DataTransferManagerIid =
        new(0xA5CAEE9B, 0x8708, 0x49D1, 0x8D, 0x36, 0x67, 0xD2, 0x5A, 0x8D, 0xA0, 0x0C);

    // Open the native Windows Share sheet with the file pre-attached — every
    // installed share target (WhatsApp, Mail, Bluetooth…) appears; pick one.
    private async Task ShareWindowsAsync(string path, List<int> indices)
    {
        string file = path ?? "";
        try
        {
            // From the Scanned Pages area, export the selected page(s) to a PDF.
            if (string.IsNullOrWhiteSpace(file))
            {
                if (_pages.Count == 0) { Status("Nothing to share — scan a page first"); return; }
                var idx = indices.Where(i => i >= 0 && i < _pages.Count).Distinct().OrderBy(i => i).ToList();
                if (idx.Count == 0) { int s = Sel(); if (s >= 0) idx.Add(s); }
                if (idx.Count == 0) { Status("No pages to share"); return; }
                SyncNames();
                var nm = (idx[0] < _pageNames.Count && !string.IsNullOrWhiteSpace(_pageNames[idx[0]]))
                    ? SanitizeFileName(_pageNames[idx[0]]) : "scan";
                file = System.IO.Path.Combine(System.IO.Path.GetTempPath(), nm + "_" + Guid.NewGuid().ToString("N")[..6] + ".pdf");
                Status("Preparing file to share…");
                await ExportPdf(file, idx.Select(i => _pages[i]).ToList(), _ocr ? new OcrParams(_ocrLang) : null);
            }
            if (string.IsNullOrWhiteSpace(file) || !File.Exists(file)) { Status("File not found to share"); return; }

            var storageFile = await StorageFile.GetFileFromPathAsync(file);
            var interop = DataTransferManager.As<IDataTransferManagerInterop>();
            IntPtr hwnd = Handle;
            var iid = DataTransferManagerIid;
            IntPtr abi = interop.GetForWindow(hwnd, ref iid);
            var dtm = MarshalInterface<DataTransferManager>.FromAbi(abi);
            dtm.DataRequested += (_, args) =>
            {
                var req = args.Request;
                req.Data.Properties.Title = System.IO.Path.GetFileName(file);
                req.Data.SetStorageItems(new List<IStorageItem> { storageFile });
            };
            interop.ShowShareUIForWindow(hwnd);
            Status("Windows Share — pick where to send it");
        }
        catch (Exception ex)
        {
            Status("Windows Share error: " + ex.Message);
        }
    }

    // mode: "all" | "selected" | "id" | "idSelected".
    //  - all/selected : one page per sheet (fit to the printable area)
    //  - id/idSelected: each page printed at real ID-card size (85.6×54 mm),
    //                   tiled onto the sheet with a light cut border.
    private void PrintPages(string mode = "all", List<int>? indices = null)
    {
        if (_pages.Count == 0)
        {
            Status("Nothing to print — scan a page first");
            return;
        }
        bool idMode = mode == "id" || mode == "idSelected";
        bool selectedOnly = mode == "selected" || mode == "idSelected";

        List<int> pages;
        if (selectedOnly)
        {
            pages = (indices ?? new List<int>()).Where(i => i >= 0 && i < _pages.Count).Distinct().OrderBy(i => i).ToList();
            if (pages.Count == 0) { int s = Sel(); if (s >= 0) pages.Add(s); }
            if (pages.Count == 0) { Status("No page selected — select page(s) first"); return; }
        }
        else
        {
            pages = Enumerable.Range(0, _pages.Count).ToList();
        }

        try
        {
            var doc = new PrintDocument();

            if (!idMode)
            {
                int i = 0;
                doc.PrintPage += (_, e) =>
                {
                    var image = _pages[pages[i]].Render();
                    try
                    {
                        var pb = e.MarginBounds;
                        if (Math.Sign(image.Width - image.Height) != Math.Sign(pb.Width - pb.Height))
                            image = image.PerformTransform(new RotationTransform(90));
                        var bmp = image.AsBitmap();
                        double scale = Math.Min((double)pb.Width / bmp.Width, (double)pb.Height / bmp.Height);
                        int w = (int)Math.Round(bmp.Width * scale);
                        int h = (int)Math.Round(bmp.Height * scale);
                        e.Graphics!.DrawImage(bmp, new Rectangle(pb.Left + (pb.Width - w) / 2, pb.Top + (pb.Height - h) / 2, w, h));
                    }
                    finally { image.Dispose(); }
                    e.HasMorePages = ++i < pages.Count;
                };
            }
            else
            {
                // ID mode: both parts of the card (front + back) on ONE sheet,
                // enlarged to fill the page — two slots stacked vertically, each
                // image maximised inside its half (aspect preserved, centered).
                const int perPage = 2, gap = 40;
                int idx = 0;
                doc.PrintPage += (_, e) =>
                {
                    var pb = e.MarginBounds;
                    int rowH = (pb.Height - gap * (perPage - 1)) / perPage;
                    for (int slot = 0; slot < perPage && idx < pages.Count; slot++, idx++)
                    {
                        int top = pb.Top + slot * (rowH + gap);
                        var image = _pages[pages[idx]].Render();
                        try
                        {
                            // Rotate a portrait scan so the (landscape) card uses the width.
                            if (image.Height > image.Width)
                                image = image.PerformTransform(new RotationTransform(90));
                            var bmp = image.AsBitmap();
                            double scale = Math.Min((double)pb.Width / bmp.Width, (double)rowH / bmp.Height);
                            int w = (int)Math.Round(bmp.Width * scale);
                            int h = (int)Math.Round(bmp.Height * scale);
                            e.Graphics!.DrawImage(bmp, new Rectangle(pb.Left + (pb.Width - w) / 2, top + (rowH - h) / 2, w, h));
                        }
                        finally { image.Dispose(); }
                    }
                    e.HasMorePages = idx < pages.Count;
                };
            }

            using var pd = new PrintDialog { Document = doc, UseEXDialog = true };
            if (pd.ShowDialog(this) == DialogResult.OK)
            {
                doc.PrinterSettings = pd.PrinterSettings;
                Status("Printing…");
                doc.Print();
                Bump("print", 1);
                Status($"Printed {pages.Count} page(s)" + (idMode ? " as ID card(s)" : ""));
            }
        }
        catch (Exception ex)
        {
            Status("Print error: " + ex.Message);
        }
    }

    // Print a PDF/image file straight from the My Documents sidebar.
    private async Task PrintFileAsync(string path)
    {
        if (!File.Exists(path)) { Status("File not found"); return; }
        List<ProcessedImage> pages;
        try { pages = await ImportPagesAsync(path); }
        catch (Exception ex) { Status("Print error: " + ex.Message); return; }
        if (pages.Count == 0) { Status("Nothing to print"); return; }
        try
        {
            var doc = new PrintDocument();
            int i = 0;
            doc.PrintPage += (_, e) =>
            {
                var image = pages[i].Render();
                try
                {
                    var pb = e.MarginBounds;
                    if (Math.Sign(image.Width - image.Height) != Math.Sign(pb.Width - pb.Height))
                        image = image.PerformTransform(new RotationTransform(90));
                    var bmp = image.AsBitmap();
                    double scale = Math.Min((double) pb.Width / bmp.Width, (double) pb.Height / bmp.Height);
                    int w = (int) Math.Round(bmp.Width * scale), h = (int) Math.Round(bmp.Height * scale);
                    int x = pb.Left + (pb.Width - w) / 2, y = pb.Top + (pb.Height - h) / 2;
                    e.Graphics!.DrawImage(bmp, new Rectangle(x, y, w, h));
                }
                finally { image.Dispose(); }
                e.HasMorePages = ++i < pages.Count;
            };
            using var pd = new PrintDialog { Document = doc, UseEXDialog = true };
            if (pd.ShowDialog(this) == DialogResult.OK)
            {
                doc.PrinterSettings = pd.PrinterSettings;
                Status("Printing…");
                doc.Print();
                Bump("print", 1);
                Status($"Printed {pages.Count} page(s)");
            }
        }
        catch (Exception ex) { Status("Print error: " + ex.Message); }
        finally { foreach (var p in pages) p.Dispose(); }
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
    private static bool IsPreviewable(string ext) => ext is
        ".pdf" or ".jpg" or ".jpeg" or ".png" or ".tif" or ".tiff" or ".bmp";

    private async Task ImportPathAsync(string path)
    {
        if (string.IsNullOrEmpty(path) || !File.Exists(path))
        {
            Status("File not found");
            return;
        }
        var iext = System.IO.Path.GetExtension(path).ToLowerInvariant();
        if (!IsPreviewable(iext))
        {
            Status("Only PDF and image files can be imported");
            return;
        }
        try
        {
            Status("Importing…");
            PushUndo();
            var ext = iext;
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

    private string PhoneCode()
    {
        if (string.IsNullOrEmpty(_phoneCode)) _phoneCode = Guid.NewGuid().ToString("N")[..8];
        return _phoneCode;
    }

    private static string QrDataUri(string url)
    {
        var gen = new QRCodeGenerator();
        var data = gen.CreateQrCode(url, QRCodeGenerator.ECCLevel.M);
        var png = new PngByteQRCode(data).GetGraphic(8);
        return "data:image/png;base64," + Convert.ToBase64String(png);
    }

    // Show the QR to a page the phone opens (any network). Also start polling
    // the relay for files the phone sends back to this PC.
    private void StartPhoneServer()
    {
        var url = PhonePageBase + "?s=" + PhoneCode();
        try
        {
            Post(new { type = "phone", qr = QrDataUri(url), url });
            Status("Phone-to-PC ready — scan the QR with your phone (any network)");
            _lastPhoneTs = 0;
            if (_phonePollTimer == null)
            {
                _phonePollTimer = new System.Windows.Forms.Timer { Interval = 3000 };
                _phonePollTimer.Tick += (_, _) => { _ = PhonePollAsync(); };
            }
            _phonePollTimer.Start();
        }
        catch (Exception ex)
        {
            Post(new { type = "phone", qr = (string?)null, url });
            Status("Phone-to-PC error: " + ex.Message);
        }
    }

    private void StopPhoneServer()
    {
        try { _phonePollTimer?.Stop(); } catch { /* ignore */ }
        try { _phoneServer?.Stop(); } catch { /* legacy */ }
        _phoneServer = null;
    }

    // Poll the relay for files the phone sent to this PC and import them.
    private async Task PhonePollAsync()
    {
        var code = _phoneCode;
        if (string.IsNullOrEmpty(code)) return;
        try
        {
            var listUrl = PhoneRelay + "?action=list&s=" + Uri.EscapeDataString(code) + "&dir=toPC&since=" + _lastPhoneTs;
            var json = await _relay.GetStringAsync(listUrl);
            using var doc = JsonDocument.Parse(json);
            if (!doc.RootElement.TryGetProperty("items", out var items) || items.ValueKind != JsonValueKind.Array) return;
            foreach (var it in items.EnumerateArray())
            {
                string id = it.TryGetProperty("id", out var idv) ? (idv.GetString() ?? "") : "";
                string name = it.TryGetProperty("name", out var nv) ? (nv.GetString() ?? "file") : "file";
                long ts = it.TryGetProperty("ts", out var tv) && tv.ValueKind == JsonValueKind.Number ? tv.GetInt64() : 0;
                if (id == "") continue;
                if (ts > _lastPhoneTs) _lastPhoneTs = ts;
                var bytes = await _relay.GetByteArrayAsync(PhoneRelay + "?action=dl&s=" + Uri.EscapeDataString(code) + "&dir=toPC&id=" + Uri.EscapeDataString(id));
                var ext = System.IO.Path.GetExtension(name);
                if (string.IsNullOrEmpty(ext)) ext = ".jpg";
                var temp = System.IO.Path.Combine(System.IO.Path.GetTempPath(), "apnescan_phone_" + Guid.NewGuid().ToString("N")[..8] + ext);
                await File.WriteAllBytesAsync(temp, bytes);
                if (IsHandleCreated) BeginInvoke(new Action(() => { _ = AddPhoneFileAsync(temp); }));
            }
        }
        catch { /* transient network errors are fine */ }
    }

    private async Task<bool> UploadToRelayAsync(string path, string dir)
    {
        try
        {
            using var form = new MultipartFormDataContent();
            var bytes = await File.ReadAllBytesAsync(path);
            var fc = new ByteArrayContent(bytes);
            fc.Headers.ContentType = new System.Net.Http.Headers.MediaTypeHeaderValue(ContentTypeFor(path));
            form.Add(fc, "file", System.IO.Path.GetFileName(path));
            var url = PhoneRelay + "?action=up&s=" + Uri.EscapeDataString(PhoneCode()) + "&dir=" + dir;
            var resp = await _relay.PostAsync(url, form);
            return resp.IsSuccessStatusCode;
        }
        catch { return false; }
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

    // Start the phone listener without showing the upload QR (used for sending).
    private bool EnsurePhoneServer()
    {
        if (_phoneServer != null) return true;
        try
        {
            _phoneServer = new TcpListener(IPAddress.Any, PhonePort);
            _phoneServer.Start();
            _ = Task.Run(PhoneServerLoop);
            return true;
        }
        catch { _phoneServer = null; return false; }
    }

    // Upload the file to the relay so the phone can download it from anywhere.
    private async void SharePhoneFile(string path)
    {
        if (!File.Exists(path)) { Status("File not found"); return; }
        Status("Uploading to the phone bridge…");
        try
        {
            if (!await UploadToRelayAsync(path, "toPhone"))
            {
                Status("Could not upload — check your internet connection");
                return;
            }
            var url = PhonePageBase + "?s=" + PhoneCode();
            Post(new { type = "phoneShare", qr = QrDataUri(url), url, name = System.IO.Path.GetFileName(path) });
            Status("Scan the QR — the file is ready on the phone (any network)");
        }
        catch (Exception ex) { Status("Phone share error: " + ex.Message); }
    }

    private static string ContentTypeFor(string name)
    {
        var e = System.IO.Path.GetExtension(name).ToLowerInvariant();
        return e switch
        {
            ".pdf" => "application/pdf",
            ".jpg" or ".jpeg" => "image/jpeg",
            ".png" => "image/png",
            ".tif" or ".tiff" => "image/tiff",
            ".bmp" => "image/bmp",
            _ => "application/octet-stream"
        };
    }

    private static async Task WriteFileResponse(NetworkStream stream, byte[] body, string contentType, string fileName)
    {
        var head = $"HTTP/1.1 200 OK\r\nContent-Type: {contentType}\r\n" +
                   $"Content-Disposition: attachment; filename=\"{fileName}\"\r\n" +
                   $"Content-Length: {body.Length}\r\nConnection: close\r\nAccess-Control-Allow-Origin: *\r\n\r\n";
        await stream.WriteAsync(Encoding.ASCII.GetBytes(head));
        await stream.WriteAsync(body);
        await stream.FlushAsync();
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
                else if (method == "GET" && pathReq.StartsWith("/get/"))
                {
                    var token = pathReq["/get/".Length..];
                    int q = token.IndexOf('?');
                    if (q >= 0) token = token[..q];
                    string? filePath = null;
                    lock (_phoneShares) _phoneShares.TryGetValue(token, out filePath);
                    if (filePath != null && File.Exists(filePath))
                    {
                        var bytes = await File.ReadAllBytesAsync(filePath);
                        var fname = System.IO.Path.GetFileName(filePath);
                        await WriteFileResponse(stream, bytes, ContentTypeFor(fname), fname);
                    }
                    else
                    {
                        await WriteResponse(stream, "404 Not Found", "text/plain", Encoding.UTF8.GetBytes("File no longer available"));
                    }
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
            var ext = System.IO.Path.GetExtension(path).ToLowerInvariant();
            int added = 0;
            var importer = ext == ".pdf" ? new PdfImporter(_ctx).Import(path) : new ImageImporter(_ctx).Import(path);
            await foreach (var img in importer)
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
            var learned = LoadLearned();
            for (int i = 0; i < _pages.Count; i++)
            {
                if (i < _pageNames.Count && !string.IsNullOrEmpty(_pageNames[i])) continue;
                string name = "";
                try
                {
                    var text = await OcrTopTextAsync(_pages[i]);
                    // 1) The exact name you gave a matching document last time wins.
                    // 2) Else a remembered name that appears in the page.
                    // 3) Else the first strong line of text.
                    name = LearnedMatch(text, learned) ?? MatchName(text, suggestions) ?? FirstStrongLine(text);
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
            var text = await ExtractTextAsync(tmp, CancellationToken.None);
            try { File.Delete(tmp); } catch { /* best-effort */ }
            return text;
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

    // ---- Learned document names ----
    // Remembers what you named a document last time (by an OCR content
    // signature) so the same name comes back automatically next time you scan
    // a matching document.
    private sealed class LearnedName
    {
        public string Sig { get; set; } = "";
        public string Name { get; set; } = "";
    }

    private static string LearnedFile => System.IO.Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "ApneScan", "learned.json");

    private static List<LearnedName> LoadLearned()
    {
        try
        {
            if (File.Exists(LearnedFile))
                return JsonSerializer.Deserialize<List<LearnedName>>(File.ReadAllText(LearnedFile)) ?? new();
        }
        catch { /* non-fatal */ }
        return new();
    }

    private static void SaveLearned(List<LearnedName> list)
    {
        try
        {
            Directory.CreateDirectory(System.IO.Path.GetDirectoryName(LearnedFile)!);
            File.WriteAllText(LearnedFile, JsonSerializer.Serialize(list));
        }
        catch { /* non-fatal */ }
    }

    // A content signature from the OCR'd top text: lowercase words with digits
    // and tiny tokens dropped, first ~16 words kept. The letterhead/title stays
    // stable across scans while dates/amounts/names vary.
    private static string DocSignature(string text)
    {
        var norm = Norm(text);
        if (norm.Length == 0) return "";
        var toks = norm.Split(' ', StringSplitOptions.RemoveEmptyEntries)
            .Where(t => t.Length >= 3 && !t.All(char.IsDigit))
            .Take(16);
        return string.Join(' ', toks);
    }

    private static HashSet<string> SigTokens(string sig) =>
        new(sig.Split(' ', StringSplitOptions.RemoveEmptyEntries));

    private static double Similarity(HashSet<string> a, HashSet<string> b)
    {
        if (a.Count == 0 || b.Count == 0) return 0;
        int inter = a.Count(b.Contains);
        int union = a.Count + b.Count - inter;
        return union == 0 ? 0 : (double) inter / union;
    }

    // Best remembered name for a document whose signature closely matches.
    private static string? LearnedMatch(string text, List<LearnedName> list)
    {
        var toks = SigTokens(DocSignature(text));
        if (toks.Count < 2) return null;
        string? best = null; double bestScore = 0; int bestShared = 0;
        foreach (var e in list)
        {
            var et = SigTokens(e.Sig);
            double score = Similarity(toks, et);
            if (score > bestScore) { bestScore = score; best = e.Name; bestShared = toks.Count(et.Contains); }
        }
        return (bestScore >= 0.55 && bestShared >= 2) ? best : null;
    }

    private static void LearnName(string text, string name)
    {
        name = (name ?? "").Trim();
        if (name.Length == 0) return;
        var sig = DocSignature(text);
        var toks = SigTokens(sig);
        if (toks.Count < 2) return; // too little content to match reliably later
        var list = LoadLearned();
        LearnedName? closest = null; double bestScore = 0;
        foreach (var e in list)
        {
            double score = Similarity(toks, SigTokens(e.Sig));
            if (score > bestScore) { bestScore = score; closest = e; }
        }
        // Update the name for a document we've seen before, else remember a new one.
        if (closest != null && bestScore >= 0.7)
        {
            closest.Name = name;
            closest.Sig = sig;
        }
        else
        {
            list.Add(new LearnedName { Sig = sig, Name = name });
        }
        while (list.Count > 800) list.RemoveAt(0);
        SaveLearned(list);
    }

    // On rename: OCR the page and remember its signature → the chosen name.
    private async Task LearnFromRenameAsync(int index, string name)
    {
        try
        {
            if (_ctx.OcrEngine == null) return;
            if (index < 0 || index >= _pages.Count) return;
            var text = await OcrTopTextAsync(_pages[index]);
            LearnName(text, name);
        }
        catch { /* learning is best-effort */ }
    }

    // ---- Remembered / suggested names ---------------------------------------

    private static string NamesFile => System.IO.Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "ApneScan", "names.json");

    private sealed class NameEntry
    {
        public string Name { get; set; } = "";
        public int Count { get; set; }
        public bool Fav { get; set; }
        public string Cat { get; set; } = "";   // user-assigned category (colour badge)
    }

    // Loads names, transparently upgrading the old "array of strings" format.
    private static List<NameEntry> LoadNameEntries()
    {
        try
        {
            if (File.Exists(NamesFile))
            {
                using var doc = JsonDocument.Parse(File.ReadAllText(NamesFile));
                if (doc.RootElement.ValueKind == JsonValueKind.Array)
                {
                    var list = new List<NameEntry>();
                    foreach (var el in doc.RootElement.EnumerateArray())
                    {
                        if (el.ValueKind == JsonValueKind.String)
                        {
                            var s = el.GetString();
                            if (!string.IsNullOrWhiteSpace(s)) list.Add(new NameEntry { Name = s! });
                        }
                        else if (el.ValueKind == JsonValueKind.Object)
                        {
                            var n = el.TryGetProperty("Name", out var nm) ? nm.GetString() : null;
                            if (string.IsNullOrWhiteSpace(n)) continue;
                            int c = el.TryGetProperty("Count", out var cc) && cc.ValueKind == JsonValueKind.Number ? cc.GetInt32() : 0;
                            bool f = el.TryGetProperty("Fav", out var ff) && ff.ValueKind == JsonValueKind.True;
                            string cat = el.TryGetProperty("Cat", out var ct) && ct.ValueKind == JsonValueKind.String ? (ct.GetString() ?? "") : "";
                            list.Add(new NameEntry { Name = n!, Count = c, Fav = f, Cat = cat });
                        }
                    }
                    return list;
                }
            }
        }
        catch { /* non-fatal */ }
        return new();
    }

    private void StoreEntries(List<NameEntry> list)
    {
        Directory.CreateDirectory(System.IO.Path.GetDirectoryName(NamesFile)!);
        File.WriteAllText(NamesFile, JsonSerializer.Serialize(list));
    }

    // Plain name strings (for the rename autocomplete and OCR matching).
    private static List<string> LoadNames() => LoadNameEntries().Select(e => e.Name).ToList();

    private void SendNames() => Post(new
    {
        type = "names",
        items = LoadNameEntries().Select(e => new { name = e.Name, count = e.Count, fav = e.Fav, cat = e.Cat }).ToArray()
    });

    private void AddName(string n)
    {
        n = (n ?? "").Trim();
        if (n.Length == 0) return;
        var l = LoadNameEntries();
        if (!l.Any(x => string.Equals(x.Name, n, StringComparison.OrdinalIgnoreCase)))
        {
            l.Insert(0, new NameEntry { Name = n });
            if (l.Count > 400) l = l.GetRange(0, 400);
            StoreEntries(l);
        }
        SendNames();
    }

    private void RemoveName(string n)
    {
        var l = LoadNameEntries();
        l.RemoveAll(x => string.Equals(x.Name, n, StringComparison.OrdinalIgnoreCase));
        StoreEntries(l);
        SendNames();
    }

    private void ClearNames()
    {
        StoreEntries(new());
        SendNames();
    }

    private void RenameName(string oldName, string newName)
    {
        newName = (newName ?? "").Trim();
        if (string.IsNullOrWhiteSpace(oldName) || newName.Length == 0) { SendNames(); return; }
        var l = LoadNameEntries();
        var e = l.FirstOrDefault(x => string.Equals(x.Name, oldName, StringComparison.OrdinalIgnoreCase));
        if (e != null && !l.Any(x => string.Equals(x.Name, newName, StringComparison.OrdinalIgnoreCase)))
        {
            e.Name = newName;
            StoreEntries(l);
        }
        SendNames();
    }

    private void ToggleNameFav(string n)
    {
        var l = LoadNameEntries();
        var e = l.FirstOrDefault(x => string.Equals(x.Name, n, StringComparison.OrdinalIgnoreCase));
        if (e != null) { e.Fav = !e.Fav; StoreEntries(l); }
        SendNames();
    }

    // Assign a colour category (Medical/Identity/Finance/Legal/Education/Custom or "").
    private void SetNameCat(string n, string cat)
    {
        var l = LoadNameEntries();
        var e = l.FirstOrDefault(x => string.Equals(x.Name, n, StringComparison.OrdinalIgnoreCase));
        if (e != null) { e.Cat = cat ?? ""; StoreEntries(l); }
        SendNames();
    }

    private void BumpName(string n)
    {
        n = (n ?? "").Trim();
        if (n.Length == 0) return;
        var l = LoadNameEntries();
        var e = l.FirstOrDefault(x => string.Equals(x.Name, n, StringComparison.OrdinalIgnoreCase));
        if (e != null) { e.Count++; StoreEntries(l); SendNames(); }
    }

    private void ReorderNames(string json)
    {
        try
        {
            var order = JsonSerializer.Deserialize<List<string>>(json) ?? new();
            var l = LoadNameEntries();
            var result = new List<NameEntry>();
            foreach (var name in order)
            {
                var e = l.FirstOrDefault(x => string.Equals(x.Name, name, StringComparison.OrdinalIgnoreCase));
                if (e != null && !result.Contains(e)) result.Add(e);
            }
            foreach (var e in l) if (!result.Contains(e)) result.Add(e);
            StoreEntries(result);
        }
        catch { /* non-fatal */ }
        SendNames();
    }

    private void BulkAddNames(string text)
    {
        if (string.IsNullOrWhiteSpace(text)) { SendNames(); return; }
        var l = LoadNameEntries();
        foreach (var raw in text.Replace("\r", "").Split('\n'))
        {
            var n = raw.Trim();
            if (n.Length == 0) continue;
            if (!l.Any(x => string.Equals(x.Name, n, StringComparison.OrdinalIgnoreCase)))
                l.Insert(0, new NameEntry { Name = n });
        }
        if (l.Count > 400) l = l.GetRange(0, 400);
        StoreEntries(l);
        SendNames();
    }

    private void ExportNames()
    {
        try
        {
            using var sfd = new SaveFileDialog { Filter = "Text file (*.txt)|*.txt", FileName = "apnescan-names.txt" };
            if (sfd.ShowDialog(this) != DialogResult.OK) return;
            File.WriteAllLines(sfd.FileName, LoadNameEntries().Select(e => e.Name));
            Status("Names exported: " + sfd.FileName);
        }
        catch (Exception ex) { Status("Export error: " + ex.Message); }
    }

    private void ImportNames()
    {
        try
        {
            using var ofd = new OpenFileDialog { Filter = "Text file (*.txt)|*.txt|All files (*.*)|*.*" };
            if (ofd.ShowDialog(this) != DialogResult.OK) return;
            BulkAddNames(File.ReadAllText(ofd.FileName));
            Status("Names imported");
        }
        catch (Exception ex) { Status("Import error: " + ex.Message); }
    }

    private void RenamePage(int index, string name, bool remember)
    {
        SyncNames();
        if (index < 0 || index >= _pageNames.Count) return;
        name = (name ?? "").Trim();
        _pageNames[index] = name;
        Post(new { type = "pageName", index, name });
        if (name.Length > 0)
        {
            if (remember) AddName(name);
            BumpName(name); // track usage of known names
            _ = LearnFromRenameAsync(index, name); // remember this name for next time
        }
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

    // Perspective-correct the current page: warp the quad given by 4 normalized
    // corner points (TL,TR,BR,BL as x0,y0,…,x3,y3) into a flat rectangle.
    private async Task PerspectiveWarpAsync(List<double>? pts)
    {
        int i = Sel();
        if (i < 0 || i >= _pages.Count) return;
        if (pts == null || pts.Count < 8) { Status("Set 4 corners first"); return; }
        Status("Correcting perspective…");
        try
        {
            PushUndo();
            string outPath = await Task.Run(() =>
            {
                using var src = ToBitmap24Render(_pages[i]);
                int sw = src.Width, sh = src.Height;
                // Corner pixel coords.
                double[] px = new double[4], py = new double[4];
                for (int k = 0; k < 4; k++) { px[k] = Math.Clamp(pts[k * 2], 0, 1) * sw; py[k] = Math.Clamp(pts[k * 2 + 1], 0, 1) * sh; }
                double Dist(int a, int b) => Math.Sqrt((px[a] - px[b]) * (px[a] - px[b]) + (py[a] - py[b]) * (py[a] - py[b]));
                int outW = (int)Math.Round(Math.Max(Dist(0, 1), Dist(3, 2)));
                int outH = (int)Math.Round(Math.Max(Dist(0, 3), Dist(1, 2)));
                outW = Math.Clamp(outW, 16, 6000); outH = Math.Clamp(outH, 16, 8000);
                // Projective map: unit square (0,0)(1,0)(1,1)(0,1) -> quad.
                double x0 = px[0], y0 = py[0], x1 = px[1], y1 = py[1], x2 = px[2], y2 = py[2], x3 = px[3], y3 = py[3];
                double dx1 = x1 - x2, dx2 = x3 - x2, sx = x0 - x1 + x2 - x3;
                double dy1 = y1 - y2, dy2 = y3 - y2, sy = y0 - y1 + y2 - y3;
                double a, b, c, d, e, f, g, hh;
                double den = dx1 * dy2 - dx2 * dy1;
                if (Math.Abs(sx) < 1e-6 && Math.Abs(sy) < 1e-6)
                { a = x1 - x0; b = x2 - x1; c = x0; d = y1 - y0; e = y2 - y1; f = y0; g = 0; hh = 0; }
                else
                {
                    g = (sx * dy2 - dx2 * sy) / den;
                    hh = (dx1 * sy - sx * dy1) / den;
                    a = x1 - x0 + g * x1; b = x3 - x0 + hh * x3; c = x0;
                    d = y1 - y0 + g * y1; e = y3 - y0 + hh * y3; f = y0;
                }
                var outBmp = new System.Drawing.Bitmap(outW, outH, System.Drawing.Imaging.PixelFormat.Format24bppRgb);
                var sd = src.LockBits(new System.Drawing.Rectangle(0, 0, sw, sh), System.Drawing.Imaging.ImageLockMode.ReadOnly, System.Drawing.Imaging.PixelFormat.Format24bppRgb);
                var od = outBmp.LockBits(new System.Drawing.Rectangle(0, 0, outW, outH), System.Drawing.Imaging.ImageLockMode.WriteOnly, System.Drawing.Imaging.PixelFormat.Format24bppRgb);
                try
                {
                    int ss = sd.Stride, os = od.Stride;
                    var sbuf = new byte[ss * sh]; System.Runtime.InteropServices.Marshal.Copy(sd.Scan0, sbuf, 0, sbuf.Length);
                    var obuf = new byte[os * outH];
                    for (int oy = 0; oy < outH; oy++)
                    {
                        double v = (oy + 0.5) / outH;
                        int orow = oy * os;
                        for (int ox = 0; ox < outW; ox++)
                        {
                            double u = (ox + 0.5) / outW;
                            double denom = g * u + hh * v + 1.0;
                            double fx = (a * u + b * v + c) / denom;
                            double fy = (d * u + e * v + f) / denom;
                            // Bilinear sample.
                            int xi = (int)Math.Floor(fx), yi = (int)Math.Floor(fy);
                            int o = orow + ox * 3;
                            if (xi < 0 || yi < 0 || xi >= sw - 1 || yi >= sh - 1)
                            {
                                if (xi >= 0 && yi >= 0 && xi < sw && yi < sh)
                                { int so = yi * ss + xi * 3; obuf[o] = sbuf[so]; obuf[o + 1] = sbuf[so + 1]; obuf[o + 2] = sbuf[so + 2]; }
                                else { obuf[o] = obuf[o + 1] = obuf[o + 2] = 255; }
                                continue;
                            }
                            double tx = fx - xi, ty2 = fy - yi;
                            int p00 = yi * ss + xi * 3, p10 = p00 + 3, p01 = p00 + ss, p11 = p01 + 3;
                            for (int ch = 0; ch < 3; ch++)
                            {
                                double top = sbuf[p00 + ch] * (1 - tx) + sbuf[p10 + ch] * tx;
                                double bot = sbuf[p01 + ch] * (1 - tx) + sbuf[p11 + ch] * tx;
                                obuf[o + ch] = (byte)Math.Clamp(top * (1 - ty2) + bot * ty2, 0, 255);
                            }
                        }
                    }
                    System.Runtime.InteropServices.Marshal.Copy(obuf, 0, od.Scan0, obuf.Length);
                }
                finally { src.UnlockBits(sd); outBmp.UnlockBits(od); }
                var tmp = System.IO.Path.Combine(System.IO.Path.GetTempPath(), "apnescan_pw_" + Guid.NewGuid().ToString("N")[..8] + ".png");
                outBmp.Save(tmp, System.Drawing.Imaging.ImageFormat.Png);
                outBmp.Dispose();
                return tmp;
            });

            ProcessedImage? newImg = null;
            await foreach (var im in new ImageImporter(_ctx).Import(outPath)) { newImg = im; break; }
            try { File.Delete(outPath); } catch { }
            if (newImg != null)
            {
                _pages[i].Dispose();
                _pages[i] = newImg;
                await RefreshAsync(false);
                Banner("Perspective corrected", "ok");
                Status("Perspective corrected");
            }
            else { PopUndo(); Status("Could not correct perspective"); }
        }
        catch (Exception ex) { PopUndo(); Status("Perspective error: " + ex.Message); }
    }

    // Best-effort auto document-corner detection: extreme points of the content
    // mask (min/max of x+y and x−y). Returns 4 normalized points to the editor.
    private async Task DetectCornersAsync()
    {
        int i = Sel();
        if (i < 0 || i >= _pages.Count) { Post(new { type = "corners", ok = false }); return; }
        try
        {
            var (tl, tr, br, bl) = await Task.Run(() =>
            {
                using var bmp = ScaledBitmap24(_pages[i], 700);
                int w = bmp.Width, h = bmp.Height;
                var data = bmp.LockBits(new System.Drawing.Rectangle(0, 0, w, h), System.Drawing.Imaging.ImageLockMode.ReadOnly, System.Drawing.Imaging.PixelFormat.Format24bppRgb);
                int stride = data.Stride; var buf = new byte[stride * h];
                System.Runtime.InteropServices.Marshal.Copy(data.Scan0, buf, 0, buf.Length);
                bmp.UnlockBits(data);
                // Foreground = pixels that differ from the border (background) colour.
                long bl0 = 0, cnt = 0;
                for (int x = 0; x < w; x++) { int t = x * 3, bo = (h - 1) * stride + x * 3; bl0 += (buf[t] + buf[t + 1] + buf[t + 2]) / 3 + (buf[bo] + buf[bo + 1] + buf[bo + 2]) / 3; cnt += 2; }
                int bg = (int)(bl0 / Math.Max(1, cnt));
                double sumTL = double.MaxValue, sumBR = double.MinValue, difTR = double.MinValue, difBL = double.MaxValue;
                int[] pTL = { 0, 0 }, pBR = { w - 1, h - 1 }, pTR = { w - 1, 0 }, pBL = { 0, h - 1 };
                for (int y = 0; y < h; y++)
                {
                    int row = y * stride;
                    for (int x = 0; x < w; x++)
                    {
                        int o = row + x * 3; int lum = (buf[o] + buf[o + 1] + buf[o + 2]) / 3;
                        if (Math.Abs(lum - bg) < 28) continue;   // background-like → skip
                        double s = x + y, dfp = x - y;
                        if (s < sumTL) { sumTL = s; pTL = new[] { x, y }; }
                        if (s > sumBR) { sumBR = s; pBR = new[] { x, y }; }
                        if (dfp > difTR) { difTR = dfp; pTR = new[] { x, y }; }
                        if (dfp < difBL) { difBL = dfp; pBL = new[] { x, y }; }
                    }
                }
                (double, double) N(int[] p) => ((double)p[0] / w, (double)p[1] / h);
                return (N(pTL), N(pTR), N(pBR), N(pBL));
            });
            Post(new { type = "corners", ok = true, pts = new[] { tl.Item1, tl.Item2, tr.Item1, tr.Item2, br.Item1, br.Item2, bl.Item1, bl.Item2 } });
        }
        catch { Post(new { type = "corners", ok = false }); }
    }

    // Render a ProcessedImage to a 24bpp bitmap (full resolution).
    private static System.Drawing.Bitmap ToBitmap24Render(ProcessedImage p)
    {
        using var rendered = p.Render();
        return ToBitmap24(rendered);
    }
    // Render a ProcessedImage to a 24bpp bitmap scaled so the long side ~= size.
    private System.Drawing.Bitmap ScaledBitmap24(ProcessedImage p, int size)
    {
        var renderer = new ThumbnailRenderer(_ctx.ImageContext);
        using var thumb = renderer.Render(p, size).GetAwaiter().GetResult();
        return ToBitmap24(thumb);
    }

    private volatile bool _adjBusy;   // drops overlapping live-preview requests
    // Live brightness / contrast / sharpen — renders a scaled preview of the
    // current page with the given amounts applied, WITHOUT modifying the page.
    private async Task AdjustPreviewAsync(int b, int c, int s)
    {
        int i = Sel();
        if (i < 0 || i >= _pages.Count) return;
        if (_adjBusy) return;
        _adjBusy = true;
        try
        {
            var temps = new List<ProcessedImage>();
            var img = _pages[i];
            if (b != 0) { img = img.WithTransform(new BrightnessTransform(b), disposeSelf: false); temps.Add(img); }
            if (c != 0) { img = img.WithTransform(new TrueContrastTransform(c), disposeSelf: false); temps.Add(img); }
            if (s != 0) { img = img.WithTransform(new SharpenTransform(s), disposeSelf: false); temps.Add(img); }
            string dataUrl;
            try
            {
                var renderer = new ThumbnailRenderer(_ctx.ImageContext);
                using var thumb = await renderer.Render(img, 1100);
                var tmp = System.IO.Path.Combine(System.IO.Path.GetTempPath(), "apnescan_adj_" + Guid.NewGuid().ToString("N")[..8] + ".png");
                thumb.Save(tmp);
                dataUrl = "data:image/png;base64," + Convert.ToBase64String(await File.ReadAllBytesAsync(tmp));
                try { File.Delete(tmp); } catch { }
            }
            finally { foreach (var t in temps) t.Dispose(); }
            Post(new { type = "adjustPreview", dataUrl });
        }
        catch { }
        finally { _adjBusy = false; }
    }

    // Replace the current page with a flattened image (used by annotations —
    // the marks are composited onto the page in the browser and sent here).
    private async Task ReplacePageImageAsync(string dataUrl)
    {
        int i = Sel();
        if (i < 0 || i >= _pages.Count) return;
        if (string.IsNullOrEmpty(dataUrl)) return;
        try
        {
            var comma = dataUrl.IndexOf(',');
            var b64 = comma >= 0 ? dataUrl[(comma + 1)..] : dataUrl;
            var bytes = Convert.FromBase64String(b64);
            var tmp = System.IO.Path.Combine(System.IO.Path.GetTempPath(), "apnescan_an_" + Guid.NewGuid().ToString("N")[..8] + ".png");
            await File.WriteAllBytesAsync(tmp, bytes);
            ProcessedImage? ni = null;
            await foreach (var im in new ImageImporter(_ctx).Import(tmp)) { ni = im; break; }
            try { File.Delete(tmp); } catch { }
            if (ni != null)
            {
                PushUndo();
                _pages[i].Dispose();
                _pages[i] = ni;
                await RefreshAsync(false);
                Banner("Annotations applied", "ok");
                Status("Annotations applied");
            }
        }
        catch (Exception ex) { Status("Apply error: " + ex.Message); }
    }

    // Commit the live adjustments to the current page (undoable).
    private async Task AdjustApplyAsync(int b, int c, int s)
    {
        int i = Sel();
        if (i < 0 || i >= _pages.Count) return;
        if (b == 0 && c == 0 && s == 0) return;
        PushUndo();
        if (b != 0) _pages[i] = _pages[i].WithTransform(new BrightnessTransform(b), disposeSelf: true);
        if (c != 0) _pages[i] = _pages[i].WithTransform(new TrueContrastTransform(c), disposeSelf: true);
        if (s != 0) _pages[i] = _pages[i].WithTransform(new SharpenTransform(s), disposeSelf: true);
        await RefreshAsync(false);
        Banner("Adjustments applied", "ok");
        Status("Adjustments applied");
    }

    // Resolve which pages an op should act on: the explicit selection if any,
    // otherwise the currently-previewed page.
    private List<int> Targets(List<int> indices)
    {
        var t = indices.Where(i => i >= 0 && i < _pages.Count).Distinct().OrderBy(i => i).ToList();
        if (t.Count == 0)
        {
            int s = Sel();
            if (s >= 0) t.Add(s);
        }
        return t;
    }

    // Unified page operation dispatcher for the Scanned-Pages toolbar and
    // right-click menu: rotate/deskew/enhance selected pages, or reorder them.
    private async Task PageOpAsync(string op, int amount, List<int> indices)
    {
        if (_pages.Count == 0)
        {
            Status("Scan or import a page first");
            return;
        }

        switch (op)
        {
            case "reverse":
            {
                PushUndo();
                SyncNames();
                _pages.Reverse();
                _pageNames.Reverse();
                _selected = _pages.Count - 1;
                await RefreshAsync(false);
                Status("Page order reversed");
                return;
            }
            case "duplicate":
            {
                var t = Targets(indices);
                if (t.Count == 0) { Status("No page selected"); return; }
                PushUndo();
                SyncNames();
                // Insert from the end so earlier indices stay valid.
                foreach (var i in t.OrderByDescending(x => x))
                {
                    _pages.Insert(i + 1, _pages[i].Clone());
                    _pageNames.Insert(i + 1, i < _pageNames.Count ? _pageNames[i] : "");
                }
                await RefreshAsync(false);
                Status($"Duplicated {t.Count} page(s)");
                return;
            }
            case "top":
            case "bottom":
            {
                var t = Targets(indices);
                if (t.Count == 0) { Status("No page selected"); return; }
                PushUndo();
                SyncNames();
                var ordered = t.OrderBy(x => x).ToList();
                var movedPages = ordered.Select(i => _pages[i]).ToList();
                var movedNames = ordered.Select(i => i < _pageNames.Count ? _pageNames[i] : "").ToList();
                foreach (var i in ordered.OrderByDescending(x => x))
                {
                    _pages.RemoveAt(i);
                    if (i < _pageNames.Count) _pageNames.RemoveAt(i);
                }
                if (op == "top")
                {
                    _pages.InsertRange(0, movedPages);
                    _pageNames.InsertRange(0, movedNames);
                    _selected = 0;
                }
                else
                {
                    _pages.AddRange(movedPages);
                    _pageNames.AddRange(movedNames);
                    _selected = _pages.Count - 1;
                }
                await RefreshAsync(false);
                Status(op == "top" ? "Moved to top" : "Moved to bottom");
                return;
            }
            case "reorder":
            {
                // amount holds the destination index; indices[0] the source.
                int from = indices.Count > 0 ? indices[0] : -1;
                int to = amount;
                if (from < 0 || from >= _pages.Count) return;
                to = Math.Clamp(to, 0, _pages.Count - 1);
                if (from == to) return;
                PushUndo();
                SyncNames();
                var pg = _pages[from];
                var nm = from < _pageNames.Count ? _pageNames[from] : "";
                _pages.RemoveAt(from);
                if (from < _pageNames.Count) _pageNames.RemoveAt(from);
                _pages.Insert(to, pg);
                _pageNames.Insert(Math.Min(to, _pageNames.Count), nm);
                _selected = to;
                await RefreshAsync(false);
                Status($"Moved page to position {to + 1}");
                return;
            }
            case "reorderMulti":
            {
                // Drag any number of selected pages to a new spot.
                // indices = source page indices; amount = insertion point in
                // the ORIGINAL index space (0..count, "before element N").
                var srcs = indices.Where(x => x >= 0 && x < _pages.Count).Distinct().OrderBy(x => x).ToList();
                if (srcs.Count == 0) return;
                int insertAt = Math.Clamp(amount, 0, _pages.Count);
                // No-op: dropping the block back exactly where it already is.
                bool contiguous = srcs[srcs.Count - 1] - srcs[0] == srcs.Count - 1;
                if (contiguous && (insertAt == srcs[0] || insertAt == srcs[srcs.Count - 1] + 1)) return;
                PushUndo();
                SyncNames();
                var movedPages = srcs.Select(x => _pages[x]).ToList();
                var movedNames = srcs.Select(x => x < _pageNames.Count ? _pageNames[x] : "").ToList();
                int beforeCount = srcs.Count(x => x < insertAt);
                int adj = Math.Clamp(insertAt - beforeCount, 0, _pages.Count - srcs.Count);
                foreach (var x in srcs.OrderByDescending(v => v))
                {
                    _pages.RemoveAt(x);
                    if (x < _pageNames.Count) _pageNames.RemoveAt(x);
                }
                adj = Math.Clamp(adj, 0, _pages.Count);
                _pages.InsertRange(adj, movedPages);
                _pageNames.InsertRange(Math.Min(adj, _pageNames.Count), movedNames);
                _selected = adj;
                await RefreshAsync(false);
                Status($"Moved {srcs.Count} page(s)");
                return;
            }
        }

        // Image-transform ops applied to each target page.
        var targets = Targets(indices);
        if (targets.Count == 0) { Status("No page selected"); return; }
        PushUndo();
        int changed = 0;
        foreach (var i in targets)
        {
            Transform? tr = op switch
            {
                "rotate" => new RotationTransform(amount),
                "bw" => new BlackWhiteTransform(),
                "gray" => new GrayscaleTransform(),
                "auto" => new CorrectionTransform(CorrectionMode.Document),
                "brightness" => new BrightnessTransform(amount),
                "contrast" => new TrueContrastTransform(amount),
                "sharpen" => new SharpenTransform(amount == 0 ? 400 : amount),
                _ => null
            };
            if (op == "deskew")
            {
                try { using var rendered = _pages[i].Render(); tr = Deskewer.GetDeskewTransform(rendered); }
                catch { tr = null; }
            }
            if (tr == null || tr.IsNull) continue;
            _pages[i] = _pages[i].WithTransform(tr, disposeSelf: true);
            changed++;
        }
        if (changed == 0)
        {
            PopUndo();
            Status(op == "deskew" ? "Pages already straight" : "No change");
            return;
        }
        await RefreshAsync(false);
        Status($"{OpLabel(op)} — {changed} page(s)");
    }

    private static string OpLabel(string op) => op switch
    {
        "rotate" => "Rotated",
        "bw" => "Black & white",
        "gray" => "Grayscale",
        "auto" => "Auto-enhanced",
        "brightness" => "Brightness",
        "contrast" => "Contrast",
        "sharpen" => "Sharpened",
        "deskew" => "Deskewed",
        _ => "Done"
    };

    // Export selected pages as image files (drag-out / "save to Desktop").
    private async Task ExportPagesImageAsync(List<int> indices, string format)
    {
        if (_pages.Count == 0) { Status("Nothing to export — scan a page first"); return; }
        var targets = Targets(indices);
        if (targets.Count == 0) { Status("No page selected"); return; }
        bool png = !string.Equals(format, "jpg", StringComparison.OrdinalIgnoreCase)
                   && !string.Equals(format, "jpeg", StringComparison.OrdinalIgnoreCase);
        var ext = png ? "png" : "jpg";
        var fmt = png ? ImageFileFormat.Png : ImageFileFormat.Jpeg;
        string desktop = Environment.GetFolderPath(Environment.SpecialFolder.DesktopDirectory);
        if (string.IsNullOrWhiteSpace(desktop) || !Directory.Exists(desktop))
            desktop = Environment.GetFolderPath(Environment.SpecialFolder.MyDocuments);
        try
        {
            SyncNames();
            string? lastFile = null;
            int n = 0;
            foreach (var i in targets)
            {
                var nm = (i < _pageNames.Count && !string.IsNullOrWhiteSpace(_pageNames[i]))
                    ? SanitizeFileName(_pageNames[i]) : $"page {i + 1}";
                var file = System.IO.Path.Combine(desktop, $"{nm}.{ext}");
                int k = 1;
                while (File.Exists(file)) file = System.IO.Path.Combine(desktop, $"{nm} ({++k}).{ext}");
                await Task.Run(() => _pages[i].Save(file, fmt));
                lastFile = file;
                n++;
            }
            Bump("image", n);
            if (lastFile != null)
            {
                // Reveal the exported file in Explorer.
                try { Process.Start(new ProcessStartInfo { FileName = "explorer.exe", Arguments = $"/select,\"{lastFile}\"", UseShellExecute = true }); }
                catch { }
            }
            Status($"Exported {n} page(s) to Desktop");
        }
        catch (Exception ex)
        {
            Status("Export error: " + ex.Message);
        }
    }

    // Copy the selected pages into the internal page clipboard.
    private void CopyPages(List<int> indices)
    {
        if (_pages.Count == 0) { Status("Nothing to copy"); return; }
        var t = Targets(indices);
        if (t.Count == 0) { Status("Select a page to copy"); return; }
        foreach (var p in _copiedPages) p.Dispose();
        _copiedPages = t.Select(i => _pages[i].Clone()).ToList();
        Status($"Copied {_copiedPages.Count} page(s) — Ctrl+V to paste");
    }

    // Paste: prefer pages copied inside the app; otherwise pull an image or
    // files from the Windows clipboard (so you can copy from anywhere and
    // paste into the Scanned Pages area).
    private async Task PastePagesAsync()
    {
        // 1. Pages copied within ApneScan.
        if (_copiedPages.Count > 0)
        {
            PushUndo();
            SyncNames();
            int at = Sel();
            int insert = (at >= 0 ? at + 1 : _pages.Count);
            insert = Math.Clamp(insert, 0, _pages.Count);
            var clones = _copiedPages.Select(p => p.Clone()).ToList();
            _pages.InsertRange(insert, clones);
            for (int k = 0; k < clones.Count; k++)
                _pageNames.Insert(Math.Min(insert + k, _pageNames.Count), "");
            _selected = insert + clones.Count - 1;
            await RefreshAsync(false);
            _ = AutoNameAsync();
            Status($"Pasted {clones.Count} page(s)");
            return;
        }
        // 2. Windows clipboard — image, then file list.
        try
        {
            if (Clipboard.ContainsImage())
            {
                using var img = Clipboard.GetImage();
                if (img != null)
                {
                    var tmp = Path.Combine(Path.GetTempPath(), "apnescan_paste_" + Guid.NewGuid().ToString("N")[..8] + ".png");
                    img.Save(tmp, System.Drawing.Imaging.ImageFormat.Png);
                    await ImportPathAsync(tmp);
                    try { File.Delete(tmp); } catch { }
                    return;
                }
            }
            if (Clipboard.ContainsFileDropList())
            {
                var files = Clipboard.GetFileDropList();
                int done = 0;
                foreach (var f in files)
                {
                    if (!string.IsNullOrWhiteSpace(f) && File.Exists(f)) { await ImportPathAsync(f); done++; }
                }
                if (done > 0) return;
            }
        }
        catch (Exception ex) { Status("Paste error: " + ex.Message); return; }
        Status("Clipboard is empty — copy a page or image first");
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
            // Server telemetry is sent centrally from TrackAction() for every
            // bridge command, so Bump() only keeps the local in-app counters.
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
            var ocrSw = Stopwatch.StartNew();
            var result = await _ctx.OcrEngine.ProcessImage(_ctx, tmp, new OcrParams(_ocrLang), CancellationToken.None);
            ocrSw.Stop();
            try { File.Delete(tmp); } catch { /* best-effort */ }
            var text = result == null ? "" : string.Join("\n", result.Lines.Select(l => l.Text));
            var ocrMs = (int)Math.Min(ocrSw.ElapsedMilliseconds, 100000);
            if (ocrMs > 0) SendTelemetry("ocr_ms", ocrMs);                        // avg = ocr_ms / ocr_run
            SendTelemetry("ocr_lang_eng");                                        // language used
            SendTelemetry(string.IsNullOrWhiteSpace(text) ? "ocr_fail" : "ocr_ok"); // success/blank
            Post(new { type = "text", text });
            Status(string.IsNullOrWhiteSpace(text) ? "No text found on this page" : "Text ready");
        }
        catch (Exception ex)
        {
            Post(new { type = "text", text = "" });
            Status("OCR error: " + ex.Message);
        }
    }

    // Classify a document from its OCR text using keyword matching. Real:
    // the type comes from words actually found on the page, not a guess.
    private static (string type, int conf) ClassifyDocType(string text)
    {
        if (string.IsNullOrWhiteSpace(text)) return ("", 0);
        var t = " " + text.ToLowerInvariant().Replace("\n", " ").Replace("\r", " ") + " ";
        int Score(params string[] kws) { int n = 0; foreach (var k in kws) if (t.Contains(k)) n++; return n; }
        var cands = new List<(string type, int score)>
        {
            ("Aadhaar",         Score("aadhaar", "aadhar", "uidai", "unique identification", "आधार", "भारतीय विशिष्ट पहचान")),
            ("PAN Card",        Score("permanent account number", "income tax department", "पर्मानेंट", "स्थायी खाता संख्या", "आयकर विभाग")),
            ("Passport",        Score("passport", "republic of india", "given name", "place of issue", "पासपोर्ट", "भारत गणराज्य")),
            ("Driving Licence", Score("driving licence", "driving license", "transport department", "motor vehicle", "dl no", "ड्राइविंग लाइसेंस", "परिवहन विभाग", "मोटर वाहन")),
            ("ECHS Card",       Score("echs", "ex-servicemen", "contributory health", "smart card", "cghs", "पूर्व सैनिक", "स्वास्थ्य योजना")),
            ("Prescription",    Score("prescription", "rx ", "tablet", "capsule", " mg ", "dosage", "physician", "diagnosis", "दवा", "गोली", "कैप्सूल", "खुराक", "चिकित्सक", "रोगी")),
            ("Lab Report",      Score("laboratory", "lab report", "haemoglobin", "hemoglobin", "wbc", "rbc", "reference range", "specimen", "pathology", "प्रयोगशाला", "रक्त", "जांच", "हीमोग्लोबिन", "मूत्र")),
            ("Invoice / Bill",  Score("invoice", "tax invoice", "gstin", " gst ", "total amount", "grand total", "amount payable", "bill no", "receipt", "बिल", "चालान", "रसीद", "कुल राशि", "भुगतान", "जीएसटी")),
            ("Certificate",     Score("certificate", "this is to certify", "certified that", "hereby certify", "प्रमाण पत्र", "प्रमाणपत्र", "प्रमाणित किया जाता")),
            ("Referral",        Score("referral", "referred to", "reference slip", "refer to", "रेफरल", "संदर्भित", "रेफर")),
            ("Agreement",       Score("agreement", "terms and conditions", "hereby agree", "party of the first", "अनुबंध", "समझौता", "नियम और शर्तें")),
        };
        var best = cands.OrderByDescending(c => c.score).First();
        if (best.score == 0) return ("Document", 0);
        int conf = Math.Min(96, 55 + best.score * 12);
        return (best.type, conf);
    }

    // OCR every scanned page and post a detected document type per page.
    private async Task DetectTypesAsync()
    {
        if (_pages.Count == 0) { Status("Nothing to detect — scan a page first"); return; }
        if (_ctx.OcrEngine == null) { Banner("OCR is not available for type detection", "warn"); return; }
        Status("Detecting document types…");
        int n = _pages.Count;
        for (int i = 0; i < n && i < _pages.Count; i++)
        {
            try
            {
                var tmp = System.IO.Path.Combine(System.IO.Path.GetTempPath(), "apnescan_dt_" + Guid.NewGuid().ToString("N")[..8] + ".png");
                _pages[i].Save(tmp);
                var text = await ExtractTextAsync(tmp, CancellationToken.None);
                try { File.Delete(tmp); } catch { }
                var (type, conf) = ClassifyDocType(text);
                Post(new { type = "pageType", index = i, docType = type, conf });
            }
            catch { Post(new { type = "pageType", index = i, docType = "", conf = 0 }); }
        }
        Status("Document types detected");
        Banner("Document types detected", "ok");
    }

    private sealed class AppSettings
    {
        public int Dpi { get; set; } = 200;
        public string Color { get; set; } = "color";
        public string Source { get; set; } = "auto";
        public string PageSize { get; set; } = "auto";
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
        public int CompressPercent { get; set; }
        // Free-text shown in the footer bar (name, phone, address, etc.).
        public string FooterText { get; set; } = "";
        // Anonymous usage telemetry (opt-out). Default on.
        public bool Telemetry { get; set; } = true;
        // Opaque JSON blob of extra interface prefs owned by the web UI
        // (accent colour, thumbnail size, density, startup, interface toggles…).
        public string UiExtra { get; set; } = "";
        // Lifetime count of pages captured — shown in Settings statistics.
        public long PagesLifetime { get; set; }
        // OCR recognition language(s): "eng", "hin", or "eng+hin".
        public string OcrLang { get; set; } = "eng";
        // OCR engine: "tesseract" or "paddle" (offline AI).
        public string OcrEngine { get; set; } = "tesseract";
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
        _compressPercent = s.CompressPercent;
        _telemetry = s.Telemetry;
        _ocrLang = SanitizeOcrLang(s.OcrLang);
        _ocrEngine = SanitizeEngine(s.OcrEngine);
        Post(new
        {
            type = "settings",
            dpi = s.Dpi, color = s.Color, source = s.Source, pageSize = s.PageSize, ocr = s.Ocr, device = s.Device,
            theme = s.Theme, showNums = s.ShowNums, showProfiles = s.ShowProfiles,
            saveDefault = s.SaveDefault, autoName = s.AutoName, clearAfter = s.ClearAfter,
            autoCrop = s.AutoCrop, skipBlank = s.SkipBlank, compressPercent = s.CompressPercent,
            footerText = s.FooterText, telemetry = s.Telemetry, uiExtra = s.UiExtra, ocrLang = _ocrLang, ocrEngine = _ocrEngine
        });
    }

    // Real, cheap library statistics for the Settings preview panel: number of
    // document files, their total size on disk, and the lifetime page count.
    // Nothing is fabricated — an empty library reports zeros.
    private async Task SendLibraryStatsAsync()
    {
        long pages = LoadSettings().PagesLifetime;
        int docs = 0;
        long bytes = 0;
        try
        {
            var roots = new List<string>();
            foreach (var sf in new[] { Environment.SpecialFolder.MyDocuments, Environment.SpecialFolder.Desktop, Environment.SpecialFolder.MyPictures })
            {
                var p = Environment.GetFolderPath(sf);
                if (Directory.Exists(p)) roots.Add(p);
            }
            var downloads = System.IO.Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.UserProfile), "Downloads");
            if (Directory.Exists(downloads)) roots.Add(downloads);

            await Task.Run(() =>
            {
                var opts = new EnumerationOptions
                {
                    RecurseSubdirectories = true,
                    IgnoreInaccessible = true,
                    AttributesToSkip = FileAttributes.Hidden | FileAttributes.System
                };
                var seen = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
                foreach (var r in roots)
                {
                    try
                    {
                        foreach (var f in Directory.EnumerateFiles(r, "*", opts))
                        {
                            try
                            {
                                var ext = System.IO.Path.GetExtension(f);
                                if (!DocExts.Contains(ext)) continue;
                                if (!seen.Add(f)) continue;
                                docs++;
                                bytes += new FileInfo(f).Length;
                                if (docs >= 50000) return;
                            }
                            catch { }
                        }
                    }
                    catch { }
                }
            });
        }
        catch { /* non-fatal — report what we have */ }
        Post(new { type = "libStats", documents = docs, storageBytes = bytes, pages });
    }

    // Add to the persisted lifetime page total (best-effort, never throws).
    private void AddLifetimePages(int n)
    {
        if (n <= 0) return;
        try
        {
            var s = LoadSettings();
            s.PagesLifetime += n;
            Directory.CreateDirectory(System.IO.Path.GetDirectoryName(SettingsFile)!);
            File.WriteAllText(SettingsFile, JsonSerializer.Serialize(s));
        }
        catch { /* best-effort */ }
    }

    private void SaveSettings(AppSettings s)
    {
        try
        {
            if (s.Dpi <= 0) s.Dpi = 200;
            s.Device ??= "";
            s.UiExtra ??= "";
            s.OcrLang = SanitizeOcrLang(s.OcrLang);
            s.OcrEngine = SanitizeEngine(s.OcrEngine);
            // The lifetime page counter is owned by the scan pipeline, not the
            // settings dialog — carry the existing value across a settings save.
            s.PagesLifetime = LoadSettings().PagesLifetime;
            Directory.CreateDirectory(System.IO.Path.GetDirectoryName(SettingsFile)!);
            File.WriteAllText(SettingsFile, JsonSerializer.Serialize(s));
            _ocr = s.Ocr;
            _autoName = s.AutoName;
            _clearAfter = s.ClearAfter;
            _autoCrop = s.AutoCrop;
            _skipBlank = s.SkipBlank;
            _compressPercent = s.CompressPercent;
            _telemetry = s.Telemetry;
            _ocrLang = SanitizeOcrLang(s.OcrLang);
            _ocrEngine = SanitizeEngine(s.OcrEngine);
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
    // Export pages to PDF, applying the compression setting if enabled.
    private async Task ExportPdf(string file, ICollection<ProcessedImage> pages, OcrParams? ocr)
    {
        if (_compressPercent > 0)
        {
            int quality = Math.Clamp(100 - _compressPercent, 20, 95);
            await BuildCompressedPdfAsync(pages, file, quality, ocr);
        }
        else
        {
            var exporter = new PdfExporter(_ctx);
            await exporter.Export(file, pages, ocrParams: ocr);
        }
    }

    // Build a smaller PDF by re-encoding each page as a JPEG at the given
    // quality and embedding it directly (keeps visual quality, cuts size).
    private async Task BuildCompressedPdfAsync(IEnumerable<ProcessedImage> sourcePages, string outFile, int quality, OcrParams? ocr)
    {
        var tempDir = System.IO.Path.Combine(System.IO.Path.GetTempPath(), "apnescan_cz_" + Guid.NewGuid().ToString("N")[..8]);
        Directory.CreateDirectory(tempDir);
        var compCtx = new ScanningContext(new GdiImageContext())
        {
            FileStorageManager = FileStorageManager.CreateFolder(tempDir),
            OcrEngine = _ctx.OcrEngine
        };
        var newPages = new List<ProcessedImage>();
        try
        {
            var importer = new ImageImporter(compCtx);
            foreach (var src in sourcePages)
            {
                var jpg = System.IO.Path.Combine(tempDir, "p_" + Guid.NewGuid().ToString("N")[..8] + ".jpg");
                using (var rendered = src.Render())
                {
                    rendered.Save(jpg, ImageFileFormat.Jpeg, new ImageSaveOptions { Quality = quality });
                }
                await foreach (var ni in importer.Import(jpg)) newPages.Add(ni);
                try { File.Delete(jpg); } catch { /* the importer keeps its own copy */ }
            }
            var exporter = new PdfExporter(compCtx);
            await exporter.Export(outFile, newPages, ocrParams: ocr);
        }
        finally
        {
            foreach (var p in newPages) p.Dispose();
            compCtx.Dispose();
            try { Directory.Delete(tempDir, true); } catch { /* best-effort */ }
        }
    }

    private async Task CompressPdfFileAsync(string path, int explicitPercent = 0)
    {
        if (string.IsNullOrEmpty(path) || !File.Exists(path) ||
            System.IO.Path.GetExtension(path).ToLowerInvariant() != ".pdf")
        {
            Status("Select a PDF to compress");
            return;
        }
        try
        {
            Status("Compressing PDF…");
            int percent = explicitPercent > 0 ? explicitPercent : (_compressPercent > 0 ? _compressPercent : 40);
            int quality = Math.Clamp(100 - percent, 20, 95);
            var src = new List<ProcessedImage>();
            await foreach (var img in new PdfImporter(_ctx).Import(path)) src.Add(img);
            if (src.Count == 0) { Status("Could not read the PDF"); return; }
            var dir = System.IO.Path.GetDirectoryName(path)!;
            var stem = System.IO.Path.GetFileNameWithoutExtension(path) + "_small";
            var outFile = System.IO.Path.Combine(dir, stem + ".pdf");
            int k = 1;
            while (File.Exists(outFile)) outFile = System.IO.Path.Combine(dir, $"{stem} ({++k}).pdf");
            try
            {
                await BuildCompressedPdfAsync(src, outFile, quality, null);
            }
            finally { foreach (var p in src) p.Dispose(); }
            long oldS = new FileInfo(path).Length, newS = new FileInfo(outFile).Length;
            SendFolder(dir);
            Status($"Compressed → {System.IO.Path.GetFileName(outFile)} ({FormatSize(oldS)} → {FormatSize(newS)})");
        }
        catch (Exception ex)
        {
            Status("Compress error: " + ex.Message);
        }
    }

    // Compress a PDF to (approximately) a target size by searching JPEG quality.
    private async Task CompressPdfToTargetAsync(string path, long targetBytes)
    {
        if (string.IsNullOrEmpty(path) || !File.Exists(path) ||
            System.IO.Path.GetExtension(path).ToLowerInvariant() != ".pdf")
        {
            Status("Select a PDF to compress");
            return;
        }
        try
        {
            long origSize = new FileInfo(path).Length;
            Status("Reading PDF…");
            var src = new List<ProcessedImage>();
            await foreach (var img in new PdfImporter(_ctx).Import(path)) src.Add(img);
            if (src.Count == 0) { Status("Could not read the PDF"); return; }

            var dir = System.IO.Path.GetDirectoryName(path)!;
            var stem = System.IO.Path.GetFileNameWithoutExtension(path) + "_small";
            var outFile = System.IO.Path.Combine(dir, stem + ".pdf");
            int k = 1;
            while (File.Exists(outFile)) outFile = System.IO.Path.Combine(dir, $"{stem} ({++k}).pdf");

            string? bestFile = null;
            try
            {
                // Binary-search quality for the best one whose output fits the target.
                int lo = 20, hi = 92;
                for (int it = 0; it < 6 && lo <= hi; it++)
                {
                    int q = (lo + hi) / 2;
                    Status($"Compressing… (try {it + 1})");
                    var probe = System.IO.Path.Combine(System.IO.Path.GetTempPath(), "apnescan_probe_" + Guid.NewGuid().ToString("N")[..8] + ".pdf");
                    await BuildCompressedPdfAsync(src, probe, q, null);
                    long sz = new FileInfo(probe).Length;
                    if (sz <= targetBytes)
                    {
                        if (bestFile != null) { try { File.Delete(bestFile); } catch { } }
                        bestFile = probe;
                        lo = q + 1; // try higher quality (bigger, still under target)
                    }
                    else { try { File.Delete(probe); } catch { } hi = q - 1; }
                }
                if (bestFile == null)
                {
                    // Even the lowest quality overshoots — keep the smallest we can make.
                    var probe = System.IO.Path.Combine(System.IO.Path.GetTempPath(), "apnescan_probe_" + Guid.NewGuid().ToString("N")[..8] + ".pdf");
                    await BuildCompressedPdfAsync(src, probe, 20, null);
                    bestFile = probe;
                }
                File.Move(bestFile, outFile);
                bestFile = null;
            }
            finally
            {
                if (bestFile != null) { try { File.Delete(bestFile); } catch { } }
                foreach (var p in src) p.Dispose();
            }

            long newS = new FileInfo(outFile).Length;
            SendFolder(dir);
            string note = newS <= targetBytes ? "" : " — couldn't go smaller";
            Status($"Compressed → {System.IO.Path.GetFileName(outFile)} ({FormatSize(origSize)} → {FormatSize(newS)}){note}");
        }
        catch (Exception ex)
        {
            Status("Compress error: " + ex.Message);
        }
    }

    // Rename a file or folder on disk (from the My Documents panel).
    // Given a desired name that is already taken in dir, returns the first free
    // "Name (2)", "Name (3)"… variant (keeping the extension for files).
    private static string NextFreeName(string dir, string desired, bool isDir)
    {
        string ext = isDir ? "" : System.IO.Path.GetExtension(desired);
        string baseName = isDir ? desired : System.IO.Path.GetFileNameWithoutExtension(desired);
        for (int n = 2; n < 100000; n++)
        {
            var candidate = System.IO.Path.Combine(dir, $"{baseName} ({n}){ext}");
            bool taken = isDir ? Directory.Exists(candidate) : File.Exists(candidate);
            if (!taken) return candidate;
        }
        return System.IO.Path.Combine(dir, $"{baseName} ({Guid.NewGuid().ToString("N")[..6]}){ext}");
    }

    private void RenameItem(string path, string newName)
    {
        try
        {
            if (string.IsNullOrWhiteSpace(path) || newName == null) return;
            newName = newName.Trim();
            if (newName.Length == 0) { Banner("Give the file a name", "warn"); return; }
            var dir = System.IO.Path.GetDirectoryName(path);
            if (dir == null) return;
            bool isDir = Directory.Exists(path);
            if (!isDir && !File.Exists(path)) { Banner("That file no longer exists", "warn"); return; }
            var safe = SanitizeFileName(newName);
            if (!isDir && string.IsNullOrEmpty(System.IO.Path.GetExtension(safe)))
            {
                safe += System.IO.Path.GetExtension(path); // keep original extension
            }
            var target = System.IO.Path.Combine(dir, safe);
            // Same name (or only case changed) — nothing to do.
            if (string.Equals(target, path, StringComparison.OrdinalIgnoreCase)) { SendFolder(dir); return; }
            if (isDir)
            {
                if (Directory.Exists(target))
                {
                    // Auto-number a duplicate folder name: "Name (2)", "Name (3)"…
                    target = NextFreeName(dir, safe, true);
                    safe = System.IO.Path.GetFileName(target);
                }
                Directory.Move(path, target);
            }
            else
            {
                if (File.Exists(target))
                {
                    // Name taken → auto-append a number: "Bills (2).pdf", "Bills (3).pdf"…
                    target = NextFreeName(dir, safe, false);
                    safe = System.IO.Path.GetFileName(target);
                }
                // The file may be momentarily locked (e.g. it is the page shown in
                // the preview). Retry briefly, then fall back to copy + delete.
                Exception? last = null;
                bool moved = false;
                for (int attempt = 0; attempt < 4 && !moved; attempt++)
                {
                    try { File.Move(path, target); moved = true; }
                    catch (IOException ex) { last = ex; System.Threading.Thread.Sleep(150); }
                    catch (UnauthorizedAccessException ex) { last = ex; System.Threading.Thread.Sleep(150); }
                }
                if (!moved)
                {
                    try { File.Copy(path, target, false); File.Delete(path); moved = true; }
                    catch (Exception ex) { last = ex; }
                }
                if (!moved)
                {
                    Banner("Couldn’t rename — the file is open elsewhere. Close it and try again.", "error");
                    Status("Rename failed: " + (last?.Message ?? "file in use"));
                    return;
                }
            }
            SendFolder(dir);
            Post(new { type = "renamed", path = target, name = safe });   // re-select the renamed item
            Banner("Renamed to “" + safe + "”", "ok");
            Status("Renamed to " + safe);
        }
        catch (Exception ex)
        {
            Banner("Rename error: " + ex.Message, "error");
            Status("Rename error: " + ex.Message);
        }
    }

    // Parse a JSON array of paths (bulk selection); fall back to a single path.
    private static List<string> PathsFrom(string data, string single)
    {
        var list = new List<string>();
        if (!string.IsNullOrWhiteSpace(data))
        {
            try
            {
                using var doc = JsonDocument.Parse(data);
                if (doc.RootElement.ValueKind == JsonValueKind.Array)
                    foreach (var el in doc.RootElement.EnumerateArray())
                        if (el.ValueKind == JsonValueKind.String)
                        {
                            var s = el.GetString();
                            if (!string.IsNullOrWhiteSpace(s)) list.Add(s!);
                        }
            }
            catch { /* fall through to single */ }
        }
        if (list.Count == 0 && !string.IsNullOrWhiteSpace(single)) list.Add(single);
        return list;
    }

    // Delete file(s)/folder(s) to the Recycle Bin.
    private void DeleteItems(List<string> paths)
    {
        if (paths.Count == 0) { Status("Nothing to delete"); return; }
        string? parent = null;
        int done = 0;
        foreach (var p in paths)
        {
            try
            {
                if (Directory.Exists(p))
                {
                    parent ??= System.IO.Path.GetDirectoryName(p.TrimEnd(System.IO.Path.DirectorySeparatorChar));
                    Microsoft.VisualBasic.FileIO.FileSystem.DeleteDirectory(p,
                        Microsoft.VisualBasic.FileIO.UIOption.OnlyErrorDialogs,
                        Microsoft.VisualBasic.FileIO.RecycleOption.SendToRecycleBin);
                    done++;
                }
                else if (File.Exists(p))
                {
                    parent ??= System.IO.Path.GetDirectoryName(p);
                    Microsoft.VisualBasic.FileIO.FileSystem.DeleteFile(p,
                        Microsoft.VisualBasic.FileIO.UIOption.OnlyErrorDialogs,
                        Microsoft.VisualBasic.FileIO.RecycleOption.SendToRecycleBin);
                    done++;
                }
            }
            catch (Exception ex) { Status("Delete error: " + ex.Message); }
        }
        if (parent != null) SendFolder(parent);
        if (done > 0) Status($"Moved {done} item(s) to Recycle Bin");
    }

    // Move or copy file(s)/folder(s) into a folder chosen by the user.
    private void MoveOrCopyItems(List<string> paths, bool move)
    {
        if (paths.Count == 0) { Status("Nothing selected"); return; }
        var start = System.IO.Path.GetDirectoryName(paths[0]);
        string? dest = null;
        try
        {
            using var fbd = new FolderBrowserDialog
            {
                UseDescriptionForTitle = true,
                Description = move ? "Move to which folder?" : "Copy to which folder?",
                ShowNewFolderButton = true
            };
            if (!string.IsNullOrWhiteSpace(start) && Directory.Exists(start)) fbd.SelectedPath = start;
            if (fbd.ShowDialog(this) == DialogResult.OK) dest = fbd.SelectedPath;
        }
        catch { }
        if (string.IsNullOrWhiteSpace(dest) || !Directory.Exists(dest)) return;

        int done = 0;
        string? sourceParent = System.IO.Path.GetDirectoryName(paths[0]);
        foreach (var p in paths)
        {
            try
            {
                bool isDir = Directory.Exists(p);
                var name = isDir ? new DirectoryInfo(p).Name : System.IO.Path.GetFileName(p);
                var target = UniquePath(System.IO.Path.Combine(dest, name), isDir);
                if (string.Equals(System.IO.Path.GetFullPath(p), System.IO.Path.GetFullPath(target), StringComparison.OrdinalIgnoreCase)) continue;
                if (isDir)
                {
                    if (move) Directory.Move(p, target);
                    else CopyDirectory(p, target);
                }
                else
                {
                    if (move) File.Move(p, target);
                    else File.Copy(p, target);
                }
                done++;
            }
            catch (Exception ex) { Status((move ? "Move" : "Copy") + " error: " + ex.Message); }
        }
        // Show the destination so the user sees the result.
        SendFolder(dest);
        if (done > 0) Status($"{(move ? "Moved" : "Copied")} {done} item(s)");
    }

    // Make a copy of a file (or folder) in the same folder, "(copy)" suffixed.
    private void DuplicateItem(string path)
    {
        try
        {
            bool isDir = Directory.Exists(path);
            if (!isDir && !File.Exists(path)) { Status("File not found"); return; }
            var dir = System.IO.Path.GetDirectoryName(path)!;
            var stem = isDir ? new DirectoryInfo(path).Name : System.IO.Path.GetFileNameWithoutExtension(path);
            var ext = isDir ? "" : System.IO.Path.GetExtension(path);
            var target = UniquePath(System.IO.Path.Combine(dir, stem + " (copy)" + ext), isDir);
            if (isDir) CopyDirectory(path, target);
            else File.Copy(path, target);
            SendFolder(dir);
            Status("Duplicated: " + System.IO.Path.GetFileName(target));
        }
        catch (Exception ex) { Status("Duplicate error: " + ex.Message); }
    }

    private void RevealItem(string path)
    {
        try
        {
            if (!File.Exists(path) && !Directory.Exists(path)) { Status("Not found"); return; }
            Process.Start(new ProcessStartInfo { FileName = "explorer.exe", Arguments = $"/select,\"{path}\"", UseShellExecute = true });
            Status("Shown in File Explorer");
        }
        catch (Exception ex) { Status("Reveal error: " + ex.Message); }
    }

    private void CopyPathToClipboard(string path)
    {
        try { Clipboard.SetText(path ?? ""); Status("Path copied to clipboard"); }
        catch (Exception ex) { Status("Copy error: " + ex.Message); }
    }

    // Append " (2)", " (3)", … until the path is free.
    private static string UniquePath(string path, bool isDir)
    {
        if (isDir ? !Directory.Exists(path) : !File.Exists(path)) return path;
        var dir = System.IO.Path.GetDirectoryName(path)!;
        var stem = isDir ? new DirectoryInfo(path).Name : System.IO.Path.GetFileNameWithoutExtension(path);
        var ext = isDir ? "" : System.IO.Path.GetExtension(path);
        int k = 1;
        string candidate;
        do { candidate = System.IO.Path.Combine(dir, $"{stem} ({++k}){ext}"); }
        while (isDir ? Directory.Exists(candidate) : File.Exists(candidate));
        return candidate;
    }

    private static void CopyDirectory(string src, string dest)
    {
        Directory.CreateDirectory(dest);
        foreach (var file in Directory.GetFiles(src))
            File.Copy(file, System.IO.Path.Combine(dest, System.IO.Path.GetFileName(file)), false);
        foreach (var sub in Directory.GetDirectories(src))
            CopyDirectory(sub, System.IO.Path.Combine(dest, System.IO.Path.GetFileName(sub)));
    }

    // Render a small thumbnail (first page for PDF) for the grid view.
    private async Task SendFileThumbAsync(string path)
    {
        if (string.IsNullOrEmpty(path) || !File.Exists(path)) return;
        var ext = System.IO.Path.GetExtension(path).ToLowerInvariant();
        if (!IsPreviewable(ext)) return;
        ProcessedImage? first = null;
        try
        {
            var importer = ext == ".pdf" ? new PdfImporter(_ctx).Import(path) : new ImageImporter(_ctx).Import(path);
            await foreach (var img in importer) { first = img; break; }
            if (first == null) return;
            var renderer = new ThumbnailRenderer(_ctx.ImageContext);
            using var thumb = await renderer.Render(first, 200);
            var tmp = System.IO.Path.Combine(System.IO.Path.GetTempPath(), "apnescan_dth_" + Guid.NewGuid().ToString("N")[..8] + ".png");
            thumb.Save(tmp, ImageFileFormat.Png);
            var dataUrl = "data:image/png;base64," + Convert.ToBase64String(await File.ReadAllBytesAsync(tmp));
            try { File.Delete(tmp); } catch { }
            Post(new { type = "thumb", path, dataUrl });
        }
        catch { /* thumbnails are best-effort */ }
        finally { first?.Dispose(); }
    }

    private static readonly HashSet<string> DocExts = new(StringComparer.OrdinalIgnoreCase)
    { ".pdf", ".jpg", ".jpeg", ".png", ".tif", ".tiff", ".bmp" };

    // Recursively search filenames under a root folder.
    private async Task SearchAllAsync(string root, string query)
    {
        query = (query ?? "").Trim();
        if (query.Length < 1) { Status("Type something to search"); return; }
        if (string.IsNullOrWhiteSpace(root) || !Directory.Exists(root))
            root = Environment.GetFolderPath(Environment.SpecialFolder.MyDocuments);
        Status($"Searching “{query}” in all subfolders…");
        var favs = LoadFavs();
        var entries = new List<object>();
        await Task.Run(() =>
        {
            try
            {
                var opts = new EnumerationOptions
                {
                    RecurseSubdirectories = true,
                    IgnoreInaccessible = true,
                    AttributesToSkip = FileAttributes.Hidden | FileAttributes.System
                };
                foreach (var f in Directory.EnumerateFiles(root, "*", opts))
                {
                    try
                    {
                        var fn = System.IO.Path.GetFileName(f);
                        if (fn.IndexOf(query, StringComparison.OrdinalIgnoreCase) < 0) continue;
                        var fi = new FileInfo(f);
                        entries.Add(new
                        {
                            name = fn,
                            path = f,
                            dir = false,
                            date = fi.LastWriteTime.ToString("dd MMM yyyy"),
                            ms = new DateTimeOffset(fi.LastWriteTime).ToUnixTimeMilliseconds(),
                            count = 0,
                            size = fi.Length,
                            fav = favs.Contains(f),
                            prev = DocExts.Contains(fi.Extension)
                        });
                        if (entries.Count >= 3000) break;
                    }
                    catch { }
                }
            }
            catch { }
        });
        Post(new
        {
            type = "folder",
            path = root,
            name = $"🔎 “{query}” — {entries.Count} result(s)",
            parent = root,
            curFav = false,
            ctx = "",
            search = true,
            entries
        });
        Status($"Found {entries.Count} file(s)");
    }

    // Recent document files across the user's common folders.
    private async Task GetRecentAsync()
    {
        Status("Finding recent files…");
        var favs = LoadFavs();
        var roots = new List<string>();
        foreach (var sf in new[] { Environment.SpecialFolder.MyDocuments, Environment.SpecialFolder.Desktop, Environment.SpecialFolder.MyPictures })
        {
            var p = Environment.GetFolderPath(sf);
            if (Directory.Exists(p)) roots.Add(p);
        }
        var downloads = System.IO.Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.UserProfile), "Downloads");
        if (Directory.Exists(downloads)) roots.Add(downloads);

        var found = new List<FileInfo>();
        await Task.Run(() =>
        {
            var opts = new EnumerationOptions
            {
                RecurseSubdirectories = true,
                IgnoreInaccessible = true,
                AttributesToSkip = FileAttributes.Hidden | FileAttributes.System
            };
            foreach (var r in roots)
            {
                try
                {
                    foreach (var f in Directory.EnumerateFiles(r, "*", opts))
                    {
                        try
                        {
                            var fi = new FileInfo(f);
                            if (DocExts.Contains(fi.Extension)) found.Add(fi);
                            if (found.Count >= 20000) break;
                        }
                        catch { }
                    }
                }
                catch { }
            }
        });
        var entries = found.OrderByDescending(f => f.LastWriteTime).Take(80).Select(fi => (object)new
        {
            name = fi.Name,
            path = fi.FullName,
            dir = false,
            date = fi.LastWriteTime.ToString("dd MMM yyyy"),
            ms = new DateTimeOffset(fi.LastWriteTime).ToUnixTimeMilliseconds(),
            count = 0,
            size = fi.Length,
            fav = favs.Contains(fi.FullName),
            prev = DocExts.Contains(fi.Extension)
        }).ToList();
        Post(new
        {
            type = "folder",
            path = Environment.GetFolderPath(Environment.SpecialFolder.MyDocuments),
            name = "🕘 Recent files",
            parent = (string?) null,
            curFav = false,
            ctx = "",
            search = true,
            entries
        });
        Status($"{entries.Count} recent file(s)");
    }

    // ---- Content search (OCR text inside PDFs/images), with a cache ----
    private sealed class OcrEntry
    {
        public long Mtime { get; set; }
        public string Text { get; set; } = "";
    }

    private static string OcrIndexFile => System.IO.Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "ApneScan", "ocr_index.json");

    private static Dictionary<string, OcrEntry> LoadOcrIndex()
    {
        try
        {
            if (File.Exists(OcrIndexFile))
                return JsonSerializer.Deserialize<Dictionary<string, OcrEntry>>(File.ReadAllText(OcrIndexFile))
                       ?? new(StringComparer.OrdinalIgnoreCase);
        }
        catch { }
        return new(StringComparer.OrdinalIgnoreCase);
    }

    private static void SaveOcrIndex(Dictionary<string, OcrEntry> idx)
    {
        try
        {
            if (idx.Count > 4000)
                idx = idx.OrderByDescending(kv => kv.Value.Mtime).Take(4000)
                         .ToDictionary(k => k.Key, v => v.Value, StringComparer.OrdinalIgnoreCase);
            Directory.CreateDirectory(System.IO.Path.GetDirectoryName(OcrIndexFile)!);
            File.WriteAllText(OcrIndexFile, JsonSerializer.Serialize(idx));
        }
        catch { }
    }

    private async Task<string> OcrPageFullAsync(ProcessedImage page)
    {
        var tmp = System.IO.Path.Combine(System.IO.Path.GetTempPath(), "apnescan_cs_" + Guid.NewGuid().ToString("N")[..8] + ".png");
        try
        {
            page.Save(tmp);
            var result = await _ctx.OcrEngine!.ProcessImage(_ctx, tmp, new OcrParams(_ocrLang), CancellationToken.None);
            return result == null ? "" : string.Join("\n", result.Lines.Select(l => l.Text));
        }
        finally { try { File.Delete(tmp); } catch { } }
    }

    private async Task ContentSearchAsync(string folder, string query)
    {
        query = (query ?? "").Trim();
        if (query.Length < 2) { Status("Type at least 2 letters to search inside files"); return; }
        if (_ctx.OcrEngine == null) { Status("OCR is not available"); return; }
        if (string.IsNullOrWhiteSpace(folder) || !Directory.Exists(folder)) { Status("Open a folder first"); return; }

        var files = new List<FileInfo>();
        try
        {
            foreach (var f in new DirectoryInfo(folder).GetFiles())
            {
                if ((f.Attributes & FileAttributes.Hidden) != 0) continue;
                if (DocExts.Contains(f.Extension)) files.Add(f);
            }
        }
        catch { }
        files = files.OrderByDescending(f => f.LastWriteTime).Take(60).ToList();
        if (files.Count == 0) { Status("No PDF/image files here to search"); return; }

        var index = LoadOcrIndex();
        var favs = LoadFavs();
        var entries = new List<object>();
        int done = 0;
        foreach (var f in files)
        {
            done++;
            Status($"Reading text {done}/{files.Count}: {f.Name}…");
            long mt = new DateTimeOffset(f.LastWriteTimeUtc).ToUnixTimeMilliseconds();
            string text;
            if (index.TryGetValue(f.FullName, out var cached) && cached.Mtime == mt)
            {
                text = cached.Text;
            }
            else
            {
                var sb = new StringBuilder();
                try
                {
                    var pages = await ImportPagesAsync(f.FullName);
                    try
                    {
                        int pc = 0;
                        foreach (var p in pages) { sb.Append('\n').Append(await OcrPageFullAsync(p)); if (++pc >= 3) break; }
                    }
                    finally { foreach (var p in pages) p.Dispose(); }
                }
                catch { }
                text = sb.ToString();
                index[f.FullName] = new OcrEntry { Mtime = mt, Text = text };
            }
            if (text.IndexOf(query, StringComparison.OrdinalIgnoreCase) >= 0)
            {
                entries.Add(new
                {
                    name = f.Name,
                    path = f.FullName,
                    dir = false,
                    date = f.LastWriteTime.ToString("dd MMM yyyy"),
                    ms = new DateTimeOffset(f.LastWriteTime).ToUnixTimeMilliseconds(),
                    count = 0,
                    size = f.Length,
                    fav = favs.Contains(f.FullName),
                    prev = true
                });
            }
        }
        SaveOcrIndex(index);
        Post(new
        {
            type = "folder",
            path = folder,
            name = $"🔤 “{query}” in text — {entries.Count} found",
            parent = folder,
            curFav = false,
            ctx = "",
            search = true,
            entries
        });
        Status($"Content search done — {entries.Count} match(es)");
    }

    // Immediate subfolders of a folder, for the tree view.
    private void SendSubfolders(string path)
    {
        var folders = new List<object>();
        try
        {
            if (!string.IsNullOrWhiteSpace(path) && Directory.Exists(path))
            {
                foreach (var d in new DirectoryInfo(path).GetDirectories())
                {
                    if ((d.Attributes & (FileAttributes.Hidden | FileAttributes.System)) != 0) continue;
                    bool hasChildren = false;
                    try { hasChildren = d.EnumerateDirectories().Any(sd => (sd.Attributes & (FileAttributes.Hidden | FileAttributes.System)) == 0); }
                    catch { }
                    folders.Add(new { name = d.Name, path = d.FullName, hasChildren });
                    if (folders.Count >= 2000) break;
                }
            }
        }
        catch { }
        Post(new { type = "subfolders", path, folders });
    }

    private void SendFileInfo(string path)
    {
        try
        {
            bool isDir = Directory.Exists(path);
            if (!isDir && !File.Exists(path)) { Status("Not found"); return; }
            var name = System.IO.Path.GetFileName(path.TrimEnd(System.IO.Path.DirectorySeparatorChar));
            long size = 0;
            string dims = "";
            int items = 0;
            string modified;
            if (isDir)
            {
                var di = new DirectoryInfo(path);
                modified = di.LastWriteTime.ToString("dd MMM yyyy, HH:mm");
                try { items = di.GetFiles().Length + di.GetDirectories().Length; } catch { }
            }
            else
            {
                var fi = new FileInfo(path);
                size = fi.Length;
                modified = fi.LastWriteTime.ToString("dd MMM yyyy, HH:mm");
                var e = fi.Extension.ToLowerInvariant();
                if (e is ".jpg" or ".jpeg" or ".png" or ".bmp" or ".tif" or ".tiff")
                {
                    try { using var im = System.Drawing.Image.FromFile(path); dims = $"{im.Width} × {im.Height} px"; } catch { }
                }
            }
            var kind = isDir ? "Folder" : (System.IO.Path.GetExtension(path).TrimStart('.').ToUpperInvariant() + " file");
            Post(new { type = "fileInfo", name, path, kind, size, dims, items, modified, dir = isDir });
        }
        catch (Exception ex) { Status("Info error: " + ex.Message); }
    }

    // ---- PDF tools (from the My Documents right-click menu) ----
    private static bool IsImageExt(string p)
    {
        var e = System.IO.Path.GetExtension(p).ToLowerInvariant();
        return e is ".jpg" or ".jpeg" or ".png" or ".tif" or ".tiff" or ".bmp";
    }

    private async Task<List<ProcessedImage>> ImportPagesAsync(string path)
    {
        var list = new List<ProcessedImage>();
        var ext = System.IO.Path.GetExtension(path).ToLowerInvariant();
        var importer = ext == ".pdf" ? new PdfImporter(_ctx).Import(path) : new ImageImporter(_ctx).Import(path);
        await foreach (var img in importer) list.Add(img);
        return list;
    }

    private string? AskSavePath(string dir, string suggested)
    {
        try
        {
            using var sfd = new SaveFileDialog { Filter = "PDF (*.pdf)|*.pdf", FileName = suggested };
            if (!string.IsNullOrWhiteSpace(dir) && Directory.Exists(dir)) sfd.InitialDirectory = dir;
            return sfd.ShowDialog(this) == DialogResult.OK ? sfd.FileName : null;
        }
        catch { return null; }
    }

    private async Task MergePdfsAsync(List<string> paths)
    {
        var pdfs = paths.Where(p => System.IO.Path.GetExtension(p).Equals(".pdf", StringComparison.OrdinalIgnoreCase) && File.Exists(p)).ToList();
        if (pdfs.Count < 2) { Status("Select 2 or more PDFs to merge"); return; }
        var dir = System.IO.Path.GetDirectoryName(pdfs[0])!;
        var outFile = AskSavePath(dir, "Merged.pdf");
        if (outFile == null) return;
        var all = new List<ProcessedImage>();
        try
        {
            Status("Merging PDFs…");
            foreach (var p in pdfs) all.AddRange(await ImportPagesAsync(p));
            await ExportPdf(outFile, all, _ocr ? new OcrParams(_ocrLang) : null);
            AddHistory(outFile, all.Count);
            SendFolder(System.IO.Path.GetDirectoryName(outFile)!);
            Status($"Merged {pdfs.Count} PDFs → {System.IO.Path.GetFileName(outFile)}");
        }
        catch (Exception ex) { Status("Merge error: " + ex.Message); }
        finally { foreach (var p in all) p.Dispose(); }
    }

    private async Task SplitPdfAsync(string path)
    {
        if (!File.Exists(path)) { Status("File not found"); return; }
        var pages = await ImportPagesAsync(path);
        try
        {
            if (pages.Count <= 1) { Status("This PDF has only one page"); return; }
            var dir = System.IO.Path.GetDirectoryName(path)!;
            var stem = System.IO.Path.GetFileNameWithoutExtension(path);
            Status("Splitting…");
            for (int i = 0; i < pages.Count; i++)
            {
                var outFile = UniquePath(System.IO.Path.Combine(dir, $"{stem}_{i + 1}.pdf"), false);
                await ExportPdf(outFile, new[] { pages[i] }, null);
            }
            SendFolder(dir);
            Status($"Split into {pages.Count} PDF files");
        }
        catch (Exception ex) { Status("Split error: " + ex.Message); }
        finally { foreach (var p in pages) p.Dispose(); }
    }

    private async Task PdfToImagesAsync(string path)
    {
        if (!File.Exists(path)) { Status("File not found"); return; }
        var pages = await ImportPagesAsync(path);
        try
        {
            if (pages.Count == 0) { Status("Nothing to export"); return; }
            var dir = System.IO.Path.GetDirectoryName(path)!;
            var stem = System.IO.Path.GetFileNameWithoutExtension(path);
            Status("Exporting images…");
            for (int i = 0; i < pages.Count; i++)
            {
                var outFile = UniquePath(System.IO.Path.Combine(dir, $"{stem}_{i + 1}.jpg"), false);
                await Task.Run(() => pages[i].Save(outFile, ImageFileFormat.Jpeg));
            }
            SendFolder(dir);
            Status($"Saved {pages.Count} image(s)");
        }
        catch (Exception ex) { Status("Export error: " + ex.Message); }
        finally { foreach (var p in pages) p.Dispose(); }
    }

    private async Task ImagesToPdfAsync(List<string> paths)
    {
        var imgs = paths.Where(p => IsImageExt(p) && File.Exists(p)).ToList();
        if (imgs.Count == 0) { Status("Select image files to combine"); return; }
        var dir = System.IO.Path.GetDirectoryName(imgs[0])!;
        var outFile = AskSavePath(dir, "Images.pdf");
        if (outFile == null) return;
        var all = new List<ProcessedImage>();
        try
        {
            Status("Making PDF…");
            foreach (var p in imgs) all.AddRange(await ImportPagesAsync(p));
            await ExportPdf(outFile, all, _ocr ? new OcrParams(_ocrLang) : null);
            AddHistory(outFile, all.Count);
            SendFolder(System.IO.Path.GetDirectoryName(outFile)!);
            Status($"Made a PDF from {imgs.Count} image(s)");
        }
        catch (Exception ex) { Status("Convert error: " + ex.Message); }
        finally { foreach (var p in all) p.Dispose(); }
    }

    // Append the currently-scanned pages to an existing PDF, saved as a new file.
    private async Task AddScannedToPdfAsync(string path)
    {
        if (!File.Exists(path)) { Status("File not found"); return; }
        if (_pages.Count == 0) { Status("No scanned pages to add — scan or import first"); return; }
        var all = new List<ProcessedImage>();
        try
        {
            Status("Adding pages…");
            all.AddRange(await ImportPagesAsync(path));
            all.AddRange(_pages.Select(p => p.Clone()));
            var dir = System.IO.Path.GetDirectoryName(path)!;
            var stem = System.IO.Path.GetFileNameWithoutExtension(path);
            var outFile = UniquePath(System.IO.Path.Combine(dir, $"{stem} (updated).pdf"), false);
            await ExportPdf(outFile, all, _ocr ? new OcrParams(_ocrLang) : null);
            AddHistory(outFile, all.Count);
            SendFolder(dir);
            Status($"Added {_pages.Count} page(s) → {System.IO.Path.GetFileName(outFile)}");
        }
        catch (Exception ex) { Status("Add pages error: " + ex.Message); }
        finally { foreach (var p in all) p.Dispose(); }
    }

    private async Task PreviewFileAsync(string path)
    {
        if (string.IsNullOrEmpty(path) || !File.Exists(path))
        {
            Status("File not found");
            return;
        }
        var pext = System.IO.Path.GetExtension(path).ToLowerInvariant();
        if (!IsPreviewable(pext))
        {
            Status("Preview is only for PDF and image files");
            return;
        }
        try
        {
            Status("Loading preview…");
            var ext = pext;
            var importer = ext == ".pdf"
                ? new PdfImporter(_ctx).Import(path)
                : new ImageImporter(_ctx).Import(path);
            var pages = new List<string>();
            await foreach (var img in importer)
            {
                try
                {
                    var tmp = System.IO.Path.Combine(System.IO.Path.GetTempPath(),
                        "apnescan_fp_" + Guid.NewGuid().ToString("N")[..8] + ".png");
                    img.Save(tmp);
                    pages.Add("data:image/png;base64," + Convert.ToBase64String(await File.ReadAllBytesAsync(tmp)));
                    try { File.Delete(tmp); } catch { /* best-effort */ }
                }
                finally { img.Dispose(); }
                if (pages.Count >= 40) break; // cap very large PDFs
            }
            if (pages.Count == 0)
            {
                Status("Could not preview this file");
                return;
            }
            Post(new
            {
                type = "filePreview",
                name = System.IO.Path.GetFileName(path),
                pages = pages.ToArray()
            });
            Status("Preview: " + System.IO.Path.GetFileName(path) + (pages.Count > 1 ? $" ({pages.Count} pages)" : ""));
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
                        cms = new DateTimeOffset(d.CreationTime).ToUnixTimeMilliseconds(),  // date created
                        ext = "",
                        count = cnt,
                        size = 0L,
                        fav = favs.Contains(d.FullName)
                    });
                    if (entries.Count >= 6000) break;
                }
                foreach (var f in di.GetFiles())
                {
                    if ((f.Attributes & FileAttributes.Hidden) != 0) continue;
                    // Show every document in the folder; only PDFs/images are
                    // marked "prev" (previewable and importable).
                    entries.Add(new
                    {
                        name = f.Name,
                        path = f.FullName,
                        dir = false,
                        date = f.LastWriteTime.ToString("dd MMM yyyy"),
                        ms = new DateTimeOffset(f.LastWriteTime).ToUnixTimeMilliseconds(),
                        cms = new DateTimeOffset(f.CreationTime).ToUnixTimeMilliseconds(),  // date created
                        ext = f.Extension.TrimStart('.').ToLowerInvariant(),
                        count = 0,
                        size = f.Length,
                        fav = favs.Contains(f.FullName),
                        prev = exts.Contains(f.Extension)
                    });
                    if (entries.Count >= 6000) break;
                }
            }
            catch { /* some subfolders may deny access — show what we can */ }

            Post(new
            {
                type = "folder",
                path = di.FullName,
                name = di.Name,
                parent = di.Parent?.FullName,
                curFav = favs.Contains(di.FullName),
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

    // Save dragged scanned pages into a folder as PDF(s), grouped by their
    // (auto-detected/renamed) name — same name → one PDF, different names →
    // separate PDFs, all in one drop.
    private async Task SavePagesToFolderAsync(string folder, List<int> indices)
    {
        if (_pages.Count == 0)
        {
            Status("Nothing to save — scan a page first");
            return;
        }
        if (string.IsNullOrWhiteSpace(folder) || !Directory.Exists(folder))
        {
            Status("Folder not found");
            return;
        }
        var idx = indices.Where(i => i >= 0 && i < _pages.Count).Distinct().OrderBy(i => i).ToList();
        if (idx.Count == 0)
        {
            int s = Sel();
            if (s >= 0) idx.Add(s);
        }
        if (idx.Count == 0)
        {
            Status("No pages to save");
            return;
        }

        SyncNames();
        // Group the chosen pages by name, preserving first-seen order.
        var order = new List<string>();
        var map = new Dictionary<string, List<int>>(StringComparer.OrdinalIgnoreCase);
        foreach (var i in idx)
        {
            var nm = (i < _pageNames.Count && !string.IsNullOrWhiteSpace(_pageNames[i])) ? _pageNames[i] : "scan";
            if (!map.TryGetValue(nm, out var lst)) { lst = new List<int>(); map[nm] = lst; order.Add(nm); }
            lst.Add(i);
        }

        try
        {
            var ocrParams = _ocr ? new OcrParams(_ocrLang) : null;
            int made = 0;
            foreach (var nm in order)
            {
                var pages = map[nm].Select(i => _pages[i]).ToList();
                var stem = SanitizeFileName(nm);
                var file = System.IO.Path.Combine(folder, stem + ".pdf");
                int k = 1;
                while (File.Exists(file)) file = System.IO.Path.Combine(folder, $"{stem} ({++k}).pdf");
                Status($"Saving {stem}.pdf …");
                await ExportPdf(file, pages, ocrParams);
                AddHistory(file, pages.Count);
                made++;
            }
            Bump("pdf", made);
            SendFolder(folder);
            Status($"Saved {made} PDF(s) to “{System.IO.Path.GetFileName(folder)}”");
            Banner($"Saved {made} PDF(s) to “{System.IO.Path.GetFileName(folder)}”", "ok");
            // Drag-drop save clears the thumbnail area too when the setting is on.
            if (_clearAfter) ClearPages();
        }
        catch (Exception ex)
        {
            Status("Save error: " + ex.Message);
        }
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
            if (string.IsNullOrWhiteSpace(path))
            {
                path = Environment.GetFolderPath(Environment.SpecialFolder.MyDocuments);
            }
            name = SanitizeFileName(name);
            if (name.Length == 0) { Status("Give the folder a name"); return; }
            var full = System.IO.Path.Combine(path, name);
            Directory.CreateDirectory(full);
            Post(new { type = "openDocsPanel" });
            SendFolder(full);   // open the newly created folder
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
            var ocrParams = _ocr ? new OcrParams(_ocrLang) : null;
            await ExportPdf(file, _pages, ocrParams);
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

    // A prominent, self-dismissing banner (style: "ok" | "warn" | "error" | "info").
    private void Banner(string text, string style = "info") => Post(new { type = "banner", style, text });

    // Scanner state for the top bar: "ready" (free) | "busy" | "error" | "offline".
    private void ScanStatus(string state, string text) => Post(new { type = "scanStatus", state, text });

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
