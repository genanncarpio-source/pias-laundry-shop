# Pia's Laundry Shop Customer App (Flutter)

This is the Flutter customer app for Pia's Laundry Shop. Customers can register, sign in, browse active services, submit a laundry service request, and track their own tickets. The shop confirms requests and takes payment in the staff management system.

## Install Flutter on Windows

1. Install [Git for Windows](https://git-scm.com/download/win) and [Visual Studio Code](https://code.visualstudio.com/).
2. In VS Code, open Extensions and install the official **Flutter** extension (it also installs Dart support).
3. Press `Ctrl+Shift+P`, select **Flutter: New Project**, then choose **Download SDK** when VS Code asks where Flutter is. Choose a writable folder such as `C:\src\flutter` and let VS Code download it.
4. When prompted, add Flutter to `PATH`. If you are not prompted, add `C:\src\flutter\bin` (or your chosen SDK folder's `bin`) in Windows **Environment Variables > User variables > Path**, then close and reopen VS Code and terminals.
5. To save disk space, use your Android phone instead of installing an emulator. Install the Android SDK command-line tools, Platform-Tools, and the Android platform/build tools required by `flutter doctor`. Set `ANDROID_HOME` to the SDK folder (for example `C:\Android\Sdk`) and add `platform-tools` and `cmdline-tools\latest\bin` to `PATH`. On the phone, enable USB debugging and approve the computer's authorization prompt. Android Studio and the emulator are optional.
6. Open a new VS Code terminal and run:

   ```powershell
   flutter --version
   flutter doctor
   flutter doctor --android-licenses
   ```

   Read and accept the Android SDK license prompts. If `flutter doctor` reports missing components, install the named SDK packages with `sdkmanager` and run `flutter doctor` again.

The Flutter SDK and VS Code extension are separate: the extension alone is not enough. Android Studio supplies the Android build tools; it does not replace the Flutter SDK. For the current official installation steps, see [Flutter's Windows install guide](https://docs.flutter.dev/install/with-vs-code) and [Android setup guide](https://docs.flutter.dev/platform-integration/android/setup).

## Requirements

- Flutter SDK installed and available as `flutter` in your terminal.
- XAMPP Apache and MySQL running with this project installed as `laundry-pos`.
- Android SDK command-line tools; emulator is optional. An iOS development machine is required for iOS builds.

## Configure the API address

Enter the API address in the **Shop API address** field on the sign-in/register screen.
The app remembers this address securely on the phone:

- Android emulator on the same computer as XAMPP: `http://10.0.2.2/laundry-pos/api`
- Physical Android phone on the same Wi-Fi: `http://YOUR_COMPUTER_LAN_IP/laundry-pos/api` (for example `http://192.168.1.20/laundry-pos/api`)
- Deployed server: use the HTTPS API URL, such as `https://example.com/api`.

For a physical phone, allow Apache through the computer firewall and ensure the phone can reach the computer. Local HTTP is suitable only for development on a trusted network. Use HTTPS for public or production use.

## Run on Android

The Android/iOS project is already generated in `native`. From a terminal:

```powershell
cd C:\xampp\htdocs\laundry-pos\mobile_app\native
flutter doctor
flutter doctor --android-licenses
flutter pub get
flutter run
```

The Android manifest grants internet access and permits HTTP for local XAMPP development so the release APK can connect to a phone-accessible LAN API. Use HTTPS before distributing publicly. iOS local HTTP testing may require an ATS exception in its build configuration.

## Build an Android APK

After setting the API URL and testing on an Android emulator or device:

```powershell
cd C:\xampp\htdocs\laundry-pos\mobile_app\native
flutter build apk --release
```

The APK is created under `build/app/outputs/flutter-apk/`. Store release and app-store signing still need to be configured before distributing the app publicly.

## Build the APK in GitHub Actions (no local Android SDK)

The project includes `.github/workflows/build-customer-apk.yml`. To use it:

1. Create an empty repository on GitHub and sign in to GitHub from the computer.
2. In PowerShell, go to the project root and push the source (the root `.gitignore`
   excludes generated build output and machine-specific SDK paths):

   ```powershell
   cd C:\xampp\htdocs\laundry-pos
   git init
   git add .
   git commit -m "Prepare Pia's Laundry Shop"
   git branch -M main
   git remote add origin https://github.com/YOUR-USERNAME/pias-laundry-shop.git
   git push -u origin main
   ```

   Replace `YOUR-USERNAME` with the GitHub account name and the repository path
   with the one GitHub gave you.
3. The first push to `main` starts the APK build automatically. For later builds,
   either push an update to `main` or open **Actions**, select **Build Pia's
   Laundry Shop APK**, and click **Run workflow**.
4. When the run completes, download the `pias-laundry-shop-customer-apk` artifact
   from that workflow run.

This produces a release-mode APK signed with Flutter's debug key for installation
and testing; it is not signed for Google Play publishing. A private release
signing key must be configured before store distribution.

## Customer API

The app uses the PHP JSON endpoints documented in `../docs/04-customer-app.md`. It stores the bearer token using the device's secure storage and sends it only for authenticated customer requests.
