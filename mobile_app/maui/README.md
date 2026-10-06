# Pia's Laundry Shop Customer App (.NET MAUI)

This is the .NET MAUI Android version of the customer app. It uses the existing
PHP API under `api/`; it does not replace the staff web app or database.

## Open and run

Open `LaundryCustomerMaui.csproj` in Visual Studio with the **.NET Multi-platform
App UI development** workload and Android SDK installed. Select an Android
emulator or USB-connected Android phone, then run the project.

Alternatively, from this directory in PowerShell:

```powershell
dotnet restore
dotnet build -f net10.0-android
dotnet build -t:Run -f net10.0-android
```

The machine must have the .NET MAUI Android workload and a compatible Android
SDK. Android emulators may need hardware acceleration enabled in firmware and
Windows. A real phone can be used instead, with USB debugging authorized.

## Connect to the PHP API

The sign-in screen lets you edit the API address. It defaults to
`http://10.0.2.2/laundry-pos/api` for an Android emulator. For a real phone,
enter the computer's LAN address, for example `http://192.168.1.20/laundry-pos/api`.
Keep XAMPP Apache running, connect both devices to the same Wi-Fi, and allow
Apache through Windows Firewall for the private network.

The Android manifest currently permits cleartext HTTP for local development.
Use HTTPS and disable cleartext traffic before distributing the app publicly.

The MAUI app supports customer registration/sign-in, secure token storage,
service selection and estimates, optional pickup suggestions, ticket submission,
ticket status/history, and sign-out.
