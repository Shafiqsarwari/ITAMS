using System.Runtime.InteropServices;

namespace AssetInventoryAgent.Services;

public static class WindowsServiceHost
{
    private const string ServiceName = "EndpointHealthAgent";

    public static void Run(string configPath)
    {
        var host = new Host(configPath);
        host.Run();
    }

    private sealed class Host(string configPath)
    {
        private readonly AgentRunner _runner = new(configPath);
        private ServiceMainDelegate? _serviceMain;
        private HandlerExDelegate? _handler;
        private IntPtr _statusHandle;
        private CancellationTokenSource? _cancellation;
        private Task? _worker;
        private readonly ManualResetEventSlim _stopped = new(false);
        private int _checkpoint;

        public void Run()
        {
            _serviceMain = ServiceMain;
            var serviceTable = new[]
            {
                new ServiceTableEntry { ServiceName = ServiceName, ServiceMain = _serviceMain },
                new ServiceTableEntry()
            };

            if (!StartServiceCtrlDispatcher(serviceTable))
            {
                var error = Marshal.GetLastWin32Error();
                LocalLog.Error($"Service dispatcher could not start. Win32 error: {error}");
                throw new InvalidOperationException($"Service dispatcher could not start. Win32 error: {error}");
            }
        }

        private void ServiceMain(int argc, IntPtr argv)
        {
            _handler = ServiceControlHandler;
            _statusHandle = RegisterServiceCtrlHandlerEx(ServiceName, _handler, IntPtr.Zero);
            if (_statusHandle == IntPtr.Zero)
            {
                LocalLog.Error($"Service control handler could not be registered. Win32 error: {Marshal.GetLastWin32Error()}");
                return;
            }

            SetStatus(ServiceState.StartPending, waitHintMilliseconds: 30000);

            _cancellation = new CancellationTokenSource();
            _worker = Task.Run(async () =>
            {
                try
                {
                    LocalLog.Info("Windows service worker started.");
                    RuntimeLog("Windows service worker started.");
                    while (!_cancellation.IsCancellationRequested)
                    {
                        try
                        {
                            await _runner.RunContinuousAsync(_cancellation.Token);
                            if (!_cancellation.IsCancellationRequested)
                            {
                                RuntimeLog("Agent runner exited unexpectedly; restarting.");
                            }
                        }
                        catch (OperationCanceledException) when (_cancellation.IsCancellationRequested)
                        {
                            LocalLog.Info("Windows service worker stopped.");
                            RuntimeLog("Windows service worker stopped.");
                            break;
                        }
                        catch (Exception ex)
                        {
                            LocalLog.Error("Windows service runner failed; restarting automatically", ex);
                            RuntimeLog("Windows service runner failed; restarting automatically." + Environment.NewLine + ex);
                        }

                        if (!_cancellation.IsCancellationRequested)
                        {
                            await Task.Delay(TimeSpan.FromSeconds(15), _cancellation.Token);
                        }
                    }
                }
                catch (OperationCanceledException)
                {
                    LocalLog.Info("Windows service worker stopped.");
                    RuntimeLog("Windows service worker stopped.");
                }
                catch (Exception ex)
                {
                    LocalLog.Error("Windows service worker failed", ex);
                    RuntimeLog("Windows service worker failed." + Environment.NewLine + ex);
                }
                finally
                {
                    _stopped.Set();
                }
            });

            SetStatus(ServiceState.Running);
            _stopped.Wait();
            SetStatus(ServiceState.Stopped);
        }

        private int ServiceControlHandler(int control, int eventType, IntPtr eventData, IntPtr context)
        {
            if (control is ServiceControlStop or ServiceControlShutdown)
            {
                SetStatus(ServiceState.StopPending, waitHintMilliseconds: 30000);
                _cancellation?.Cancel();
                return 0;
            }

            if (control == ServiceControlInterrogate)
            {
                SetStatus(ServiceState.Running);
            }

            return 0;
        }

        private void SetStatus(ServiceState state, int waitHintMilliseconds = 0)
        {
            var status = new ServiceStatus
            {
                ServiceType = ServiceTypeWin32OwnProcess,
                CurrentState = state,
                ControlsAccepted = state == ServiceState.Running ? ServiceAcceptStop | ServiceAcceptShutdown : 0,
                Win32ExitCode = 0,
                ServiceSpecificExitCode = 0,
                CheckPoint = state is ServiceState.StartPending or ServiceState.StopPending ? ++_checkpoint : 0,
                WaitHint = waitHintMilliseconds
            };

            SetServiceStatus(_statusHandle, ref status);
        }

        private static void RuntimeLog(string message)
        {
            try
            {
                File.AppendAllText(
                    Path.Combine(AppContext.BaseDirectory, "service-runtime.log"),
                    $"{DateTimeOffset.Now:O} {message}{Environment.NewLine}");
            }
            catch
            {
                // LocalLog is still attempted above; never let diagnostic logging stop the service.
            }
        }
    }

    private const int ServiceTypeWin32OwnProcess = 0x00000010;
    private const int ServiceAcceptStop = 0x00000001;
    private const int ServiceAcceptShutdown = 0x00000004;
    private const int ServiceControlStop = 0x00000001;
    private const int ServiceControlInterrogate = 0x00000004;
    private const int ServiceControlShutdown = 0x00000005;

    private enum ServiceState
    {
        Stopped = 0x00000001,
        StartPending = 0x00000002,
        StopPending = 0x00000003,
        Running = 0x00000004
    }

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private struct ServiceTableEntry
    {
        public string? ServiceName;
        public ServiceMainDelegate? ServiceMain;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct ServiceStatus
    {
        public int ServiceType;
        public ServiceState CurrentState;
        public int ControlsAccepted;
        public int Win32ExitCode;
        public int ServiceSpecificExitCode;
        public int CheckPoint;
        public int WaitHint;
    }

    private delegate void ServiceMainDelegate(int argc, IntPtr argv);
    private delegate int HandlerExDelegate(int control, int eventType, IntPtr eventData, IntPtr context);

    [DllImport("advapi32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
    private static extern bool StartServiceCtrlDispatcher([In] ServiceTableEntry[] serviceTable);

    [DllImport("advapi32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
    private static extern IntPtr RegisterServiceCtrlHandlerEx(string serviceName, HandlerExDelegate handler, IntPtr context);

    [DllImport("advapi32.dll", SetLastError = true)]
    private static extern bool SetServiceStatus(IntPtr serviceStatusHandle, ref ServiceStatus serviceStatus);
}
