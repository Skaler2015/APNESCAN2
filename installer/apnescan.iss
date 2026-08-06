; Inno Setup script for ApneScan.
; Builds ApneScan-Setup.exe from the self-contained publish folder.
; Supports silent install (/SILENT /CLOSEAPPLICATIONS) so the in-app
; one-click updater can install a new version over a running instance.

#define AppName "ApneScan"
#define AppExe "ApneScan.exe"
#ifndef AppVersion
  #define AppVersion GetEnv("APNESCAN_VERSION")
#endif
#if AppVersion == ""
  #define AppVersion "1.0.0"
#endif

[Setup]
; A fixed AppId keeps upgrades installing over the previous version.
AppId={{9C4B7E2A-APNE-4C31-9F55-APNESCAN0001}
AppName={#AppName}
AppVersion={#AppVersion}
AppVerName={#AppName} {#AppVersion}
AppPublisher=ApneScan
DefaultDirName={autopf}\ApneScan
DefaultGroupName=ApneScan
UninstallDisplayName=ApneScan
UninstallDisplayIcon={app}\{#AppExe}
DisableProgramGroupPage=yes
; Paths are relative to this .iss file (the installer/ folder), so ".." is repo root.
OutputDir=..\installer-out
OutputBaseFilename=ApneScan-Setup
Compression=lzma2
SolidCompression=yes
ArchitecturesInstallIn64BitMode=x64compatible
ArchitecturesAllowed=x64compatible
; Let the updater close a running instance before upgrading.
CloseApplications=yes
RestartApplications=no
PrivilegesRequired=admin
WizardStyle=modern

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon"; Description: "Create a desktop shortcut"; GroupDescription: "Additional icons:"

[Files]
Source: "..\publish\ApneScan\*"; DestDir: "{app}"; Flags: recursesubdirs createallsubdirs ignoreversion

[Icons]
Name: "{group}\ApneScan"; Filename: "{app}\{#AppExe}"
Name: "{group}\Uninstall ApneScan"; Filename: "{uninstallexe}"
Name: "{commondesktop}\ApneScan"; Filename: "{app}\{#AppExe}"; Tasks: desktopicon

[Run]
Filename: "{app}\{#AppExe}"; Description: "Launch ApneScan"; Flags: nowait postinstall skipifsilent
