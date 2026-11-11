@echo off
chcp 65001 >nul
echo Створюю ярлик на робочому столі...

powershell -NoProfile -Command ^
  "$ws = New-Object -ComObject WScript.Shell;" ^
  "$desktop = [Environment]::GetFolderPath('Desktop');" ^
  "$lnk = $ws.CreateShortcut([IO.Path]::Combine($desktop, 'UA Ads Bot.lnk'));" ^
  "$lnk.TargetPath = '%~dp03_zapustyty_robota.bat';" ^
  "$lnk.WorkingDirectory = '%~dp0';" ^
  "$lnk.Description = 'Запуск робота автопостингу UA Ads';" ^
  "$lnk.IconLocation = '%~dp0icon.ico,0';" ^
  "$lnk.Save();" ^
  "Write-Host 'Ярлик створено на робочому столі!'"

echo.
echo Готово. Тепер на робочому столі є ярлик "UA Ads Bot".
pause