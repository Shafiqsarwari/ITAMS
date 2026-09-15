#define MyAppName "ITAMS"
#define MyAppVersion "1.3.17"
#define MyAppPublisher "ITAMS"
#define MyAppExeName "ITAMS.exe"

[Setup]
AppId={{A7D9AA21-79C2-4EB5-B366-4A6DD39D18AA}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
DefaultDirName={autopf}\EndpointHealthAgent
DisableProgramGroupPage=yes
OutputDir=dist
OutputBaseFilename=ITAssetMonitoringSystemAgentSetup
Compression=lzma
SolidCompression=yes
WizardStyle=modern
PrivilegesRequired=admin
SetupLogging=yes
UninstallDisplayName={#MyAppName}
VersionInfoCompany={#MyAppPublisher}
VersionInfoDescription={#MyAppName} Setup
VersionInfoProductName={#MyAppName}
VersionInfoProductVersion={#MyAppVersion}

[Messages]
WelcomeLabel1=Welcome to the [name] Setup Wizard
WelcomeLabel2=This wizard will install ITAMS on this computer.%n%nITAMS runs silently in the background and reports endpoint health, device status, and installed software details to your monitoring server.
FinishedHeadingLabel=Setup has finished installing [name]
FinishedLabel=The agent is configured and will start monitoring this endpoint automatically.

[Files]
Source: "payload-1.3.17\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs
Source: "Install-AgentService.ps1"; DestDir: "{app}"; Flags: ignoreversion
Source: "Configure-NetworkDiscovery.ps1"; DestDir: "{app}"; Flags: ignoreversion
Source: "SNMP-NOTICES.txt"; DestDir: "{app}"; Flags: ignoreversion

[Dirs]
Name: "{commonappdata}\AssetInventoryAgent"; Permissions: system-full admins-full

[InstallDelete]
Type: files; Name: "{app}\AssetInventoryAgent.exe"
Type: files; Name: "{app}\AssetInventoryAgent.pdb"

[Run]
Filename: "{sys}\icacls.exe"; Parameters: """{commonappdata}\AssetInventoryAgent"" /inheritance:r /grant:r ""SYSTEM:(OI)(CI)F"" ""Administrators:(OI)(CI)F"" ""Users:(OI)(CI)M"" /T /C"; Flags: runhidden waituntilterminated
Filename: "{app}\{#MyAppExeName}"; Parameters: "--configure --server ""{code:GetServerAddress}"" --port ""{code:GetServerPort}"" --registration-key ""{code:GetRegistrationKey}"" --interval ""{code:GetInterval}"" --heartbeat-seconds ""{code:GetHeartbeatSeconds}"" --change-scan-seconds ""{code:GetChangeScanSeconds}"" --collector ""{code:GetCollector}"""; Flags: runhidden waituntilterminated
Filename: "{app}\{#MyAppExeName}"; Parameters: "--once"; Flags: runhidden waituntilterminated
Filename: "{sys}\icacls.exe"; Parameters: """{commonappdata}\AssetInventoryAgent"" /inheritance:r /grant:r ""SYSTEM:(OI)(CI)F"" ""Administrators:(OI)(CI)F"" /T /C"; Flags: runhidden waituntilterminated
Filename: "{sys}\WindowsPowerShell\v1.0\powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\Install-AgentService.ps1"" -ExePath ""{app}\{#MyAppExeName}"""; Flags: runhidden waituntilterminated

[UninstallRun]
Filename: "{app}\{#MyAppExeName}"; Parameters: "--mark-offline --reason agent_uninstalled"; Flags: runhidden waituntilterminated skipifdoesntexist
Filename: "{sys}\sc.exe"; Parameters: "stop ""Endpoint Health Agent"""; Flags: runhidden waituntilterminated
Filename: "{sys}\sc.exe"; Parameters: "delete ""Endpoint Health Agent"""; Flags: runhidden waituntilterminated
Filename: "{sys}\sc.exe"; Parameters: "stop ""EndpointHealthAgent"""; Flags: runhidden waituntilterminated
Filename: "{sys}\sc.exe"; Parameters: "delete ""EndpointHealthAgent"""; Flags: runhidden waituntilterminated
Filename: "{sys}\sc.exe"; Parameters: "stop ""ITAMS"""; Flags: runhidden waituntilterminated
Filename: "{sys}\sc.exe"; Parameters: "delete ""ITAMS"""; Flags: runhidden waituntilterminated
Filename: "{sys}\sc.exe"; Parameters: "stop ""Asset Inventory Agent"""; Flags: runhidden waituntilterminated
Filename: "{sys}\sc.exe"; Parameters: "delete ""Asset Inventory Agent"""; Flags: runhidden waituntilterminated
Filename: "{sys}\taskkill.exe"; Parameters: "/F /T /IM AssetInventoryAgent.exe"; Flags: runhidden waituntilterminated
Filename: "{sys}\taskkill.exe"; Parameters: "/F /T /IM ITAMS.exe"; Flags: runhidden waituntilterminated
Filename: "{sys}\schtasks.exe"; Parameters: "/End /TN ""Asset Inventory Agent"""; Flags: runhidden waituntilterminated
Filename: "{sys}\schtasks.exe"; Parameters: "/End /TN ""Asset Inventory Agent Retry"""; Flags: runhidden waituntilterminated
Filename: "{sys}\schtasks.exe"; Parameters: "/Delete /TN ""Asset Inventory Agent"" /F"; Flags: runhidden waituntilterminated
Filename: "{sys}\schtasks.exe"; Parameters: "/Delete /TN ""Asset Inventory Agent Retry"" /F"; Flags: runhidden waituntilterminated

[Code]
var
  ServerPage: TInputQueryWizardPage;
  TimingPage: TInputQueryWizardPage;
  CollectorPage: TInputOptionWizardPage;
  ServerAddress: String;
  ServerPort: String;
  RegistrationKey: String;
  IntervalMinutes: String;
  HeartbeatSeconds: String;
  ChangeScanSeconds: String;
  CollectorComputer: String;

function ReadParam(Name: String; DefaultValue: String): String;
var
  Value: String;
begin
  Value := ExpandConstant('{param:' + Name + '|}');
  if Value = '' then
    Result := DefaultValue
  else
    Result := Value;
end;

procedure StopExistingAgent;
var
  ResultCode: Integer;
begin
  Exec(ExpandConstant('{sys}\sc.exe'), 'stop "ITAMS"', '', SW_HIDE, ewWaitUntilTerminated, ResultCode);
  Exec(ExpandConstant('{sys}\sc.exe'), 'delete "ITAMS"', '', SW_HIDE, ewWaitUntilTerminated, ResultCode);
  Exec(ExpandConstant('{sys}\sc.exe'), 'stop "Endpoint Health Agent"', '', SW_HIDE, ewWaitUntilTerminated, ResultCode);
  Exec(ExpandConstant('{sys}\sc.exe'), 'delete "Endpoint Health Agent"', '', SW_HIDE, ewWaitUntilTerminated, ResultCode);
  Exec(ExpandConstant('{sys}\sc.exe'), 'stop "EndpointHealthAgent"', '', SW_HIDE, ewWaitUntilTerminated, ResultCode);
  Exec(ExpandConstant('{sys}\sc.exe'), 'delete "EndpointHealthAgent"', '', SW_HIDE, ewWaitUntilTerminated, ResultCode);
  Exec(ExpandConstant('{sys}\sc.exe'), 'stop "Asset Inventory Agent"', '', SW_HIDE, ewWaitUntilTerminated, ResultCode);
  Exec(ExpandConstant('{sys}\sc.exe'), 'delete "Asset Inventory Agent"', '', SW_HIDE, ewWaitUntilTerminated, ResultCode);
  Exec(ExpandConstant('{sys}\schtasks.exe'), '/End /TN "Asset Inventory Agent"', '', SW_HIDE, ewWaitUntilTerminated, ResultCode);
  Exec(ExpandConstant('{sys}\schtasks.exe'), '/End /TN "Asset Inventory Agent Retry"', '', SW_HIDE, ewWaitUntilTerminated, ResultCode);
  Exec(ExpandConstant('{sys}\schtasks.exe'), '/Delete /TN "Asset Inventory Agent" /F', '', SW_HIDE, ewWaitUntilTerminated, ResultCode);
  Exec(ExpandConstant('{sys}\schtasks.exe'), '/Delete /TN "Asset Inventory Agent Retry" /F', '', SW_HIDE, ewWaitUntilTerminated, ResultCode);
  Exec(ExpandConstant('{sys}\taskkill.exe'), '/F /T /IM AssetInventoryAgent.exe', '', SW_HIDE, ewWaitUntilTerminated, ResultCode);
  Exec(ExpandConstant('{sys}\taskkill.exe'), '/F /T /IM ITAMS.exe', '', SW_HIDE, ewWaitUntilTerminated, ResultCode);
end;

function PrepareToInstall(var NeedsRestart: Boolean): String;
begin
  StopExistingAgent;
  Result := '';
end;

procedure InitializeWizard;
begin
  ServerAddress := ReadParam('SERVER', '');
  ServerPort := ReadParam('PORT', '');
  RegistrationKey := ReadParam('KEY', '');
  IntervalMinutes := ReadParam('INTERVAL', '60');
  HeartbeatSeconds := ReadParam('HEARTBEAT', '10');
  ChangeScanSeconds := ReadParam('SCAN', '60');
  CollectorComputer := ReadParam('COLLECTOR', '0');

  ServerPage := CreateInputQueryPage(wpSelectDir,
    'Server Configuration',
    'Connect this computer to the monitoring server',
    'Enter the server details supplied by your administrator.');
  ServerPage.Add('Server IP/Hostname or full URL:', False);
  ServerPage.Add('Port:', False);
  ServerPage.Add('Registration Key:', True);
  ServerPage.Values[0] := ServerAddress;
  ServerPage.Values[1] := ServerPort;
  ServerPage.Values[2] := RegistrationKey;

  TimingPage := CreateInputQueryPage(ServerPage.ID,
    'Agent Timing',
    'Choose how often this computer reports changes',
    'These values control full inventory uploads, heartbeat checks, and quick change scans.');
  TimingPage.Add('Inventory Interval Minutes:', False);
  TimingPage.Add('Heartbeat Seconds:', False);
  TimingPage.Add('Change Scan Seconds:', False);
  TimingPage.Values[0] := IntervalMinutes;
  TimingPage.Values[1] := HeartbeatSeconds;
  TimingPage.Values[2] := ChangeScanSeconds;

  CollectorPage := CreateInputOptionPage(TimingPage.ID,
    'Collector Computer',
    'Choose whether this computer can collect network devices',
    'Collector computers can monitor printers, switches, access points, and other SNMP devices for their office.',
    False, False);
  CollectorPage.Add('Make this device a collector computer');
  CollectorPage.Values[0] := CollectorComputer = '1';
end;

function NextButtonClick(CurPageID: Integer): Boolean;
var
  PortNumber: Integer;
  IntervalNumber: Integer;
  HeartbeatNumber: Integer;
  ScanNumber: Integer;
begin
  Result := True;
  if CurPageID = ServerPage.ID then
  begin
    if Trim(ServerPage.Values[0]) = '' then
    begin
      MsgBox('Please enter the server address.', mbError, MB_OK);
      Result := False;
      Exit;
    end;

    PortNumber := StrToIntDef(ServerPage.Values[1], -1);
    if (PortNumber < 1) or (PortNumber > 65535) then
    begin
      MsgBox('Please enter a valid port number between 1 and 65535.', mbError, MB_OK);
      Result := False;
      Exit;
    end;

    if Trim(ServerPage.Values[2]) = '' then
    begin
      MsgBox('Please enter the registration key.', mbError, MB_OK);
      Result := False;
      Exit;
    end;

  end;

  if CurPageID = TimingPage.ID then
  begin
    IntervalNumber := StrToIntDef(TimingPage.Values[0], -1);
    if IntervalNumber < 5 then
    begin
      MsgBox('Monitoring interval must be at least 5 minutes.', mbError, MB_OK);
      Result := False;
      Exit;
    end;

    HeartbeatNumber := StrToIntDef(TimingPage.Values[1], -1);
    if HeartbeatNumber < 10 then
    begin
      MsgBox('Heartbeat interval must be at least 10 seconds.', mbError, MB_OK);
      Result := False;
      Exit;
    end;

    ScanNumber := StrToIntDef(TimingPage.Values[2], -1);
    if ScanNumber < 30 then
    begin
      MsgBox('Change scan interval must be at least 30 seconds.', mbError, MB_OK);
      Result := False;
      Exit;
    end;
  end;
end;

function GetServerAddress(Param: String): String;
begin
  if WizardSilent then
    Result := ServerAddress
  else
    Result := ServerPage.Values[0];
end;

function GetServerPort(Param: String): String;
begin
  if WizardSilent then
    Result := ServerPort
  else
    Result := ServerPage.Values[1];
end;

function GetRegistrationKey(Param: String): String;
begin
  if WizardSilent then
    Result := RegistrationKey
  else
    Result := ServerPage.Values[2];
end;

function GetInterval(Param: String): String;
begin
  if WizardSilent then
    Result := IntervalMinutes
  else
    Result := TimingPage.Values[0];
end;

function GetHeartbeatSeconds(Param: String): String;
begin
  if WizardSilent then
    Result := HeartbeatSeconds
  else
    Result := TimingPage.Values[1];
end;

function GetChangeScanSeconds(Param: String): String;
begin
  if WizardSilent then
    Result := ChangeScanSeconds
  else
    Result := TimingPage.Values[2];
end;

function GetCollector(Param: String): String;
begin
  if WizardSilent then
    Result := CollectorComputer
  else if CollectorPage.Values[0] then
    Result := '1'
  else
    Result := '0';
end;
