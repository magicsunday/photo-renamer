' Silent launcher for photo-select.ps1 — prevents the PowerShell window flash.
' Called by the photo-select:// protocol handler registered in the Windows Registry.

Dim shell, scriptDir, uri
Set shell = CreateObject("WScript.Shell")

scriptDir = Left(WScript.ScriptFullName, InStrRev(WScript.ScriptFullName, "\"))
uri = WScript.Arguments(0)

shell.Run "powershell.exe -ExecutionPolicy Bypass -WindowStyle Hidden -File """ & scriptDir & "photo-select.ps1"" """ & uri & """", 0, False
