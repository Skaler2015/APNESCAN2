namespace ApneScan.Studio;

static class Program
{
    [STAThread]
    static void Main()
    {
        ApplicationConfiguration.Initialize();

        // Report unhandled crashes anonymously (respects the telemetry opt-out).
        // ThrowException mode routes WinForms UI-thread exceptions to the same
        // AppDomain handler, so a single hook covers both.
        Application.SetUnhandledExceptionMode(UnhandledExceptionMode.ThrowException);
        AppDomain.CurrentDomain.UnhandledException += (_, e) =>
            MainForm.ReportCrash(e.ExceptionObject as Exception);

        Application.Run(new MainForm());
    }
}
