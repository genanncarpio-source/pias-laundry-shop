# Pia's Laundry Shop Customer App (Flutter)

This Flutter app mirrors the Expo customer and driver flows. Customers can register, sign in, browse active services, book pickup or delivery, submit GCash payment references, follow ticket status, view an active driver's location, and optionally share their own location during an active delivery. Drivers have a separate sign-in and can share their route for assigned deliveries.

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

## Expo-matched features

- Uses the same PHP API and MySQL database as Expo; Flutter never connects directly to MySQL.
- Customer sign-in/registration, active service catalog, pickup/delivery booking, scheduled date/time, and ticket status.
- GCash account/QR instructions and reference submission; staff must verify the payment in the web management system.
- Delivery location maps, optional customer location sharing, and a separate driver sign-in with assigned deliveries and live driver location.
- The shop must create driver accounts and assign active delivery orders in the web management system. GCash account details are configured in web settings.

## Requirements

- Flutter SDK installed and available as `flutter` in your terminal.
- XAMPP Apache and MySQL running with this project installed as `laundry-pias`.
- Android SDK command-line tools; emulator is optional. An iOS development machine is required for iOS builds.

## Configure the API automatically

The API address is hidden from customers and set at build time with the
`API_BASE_URL` Dart define. The GitHub Actions APK build is configured to use
this computer's current XAMPP LAN address (`192.168.100.165`). The phone and
computer must be on the same Wi-Fi, and Apache must be allowed through Windows
Firewall. If the computer's IP changes, update the `API_BASE_URL` value in
`.github/workflows/build-customer-apk.yml` and build a new APK. Reserve the IP
in the router to keep it stable.

For local emulator testing, use `http://10.0.2.2/laundry-pias/api`. Flutter web on the same computer defaults to `http://localhost/laundry-pias/api`. For hosted web previews or public releases, build with the shop's publicly reachable HTTPS API URL instead of a local XAMPP address.

## Run on Android

The Flutter Android/iOS project is in `flutter_app`. From a terminal:

```powershell
cd C:\xampp\htdocs\laundry-pias\mobile_app\flutter_app
flutter doctor
flutter doctor --android-licenses
flutter pub get
flutter run --dart-define=API_BASE_URL=http://192.168.100.165/laundry-pias/api
```

That command targets this computer's current LAN address for a physical phone.
For an Android emulator, omit the `--dart-define` argument to use
`http://10.0.2.2/laundry-pias/api`.

The Android manifest grants internet access and permits HTTP for local XAMPP development so the release APK can connect to a phone-accessible LAN API. Use HTTPS before distributing publicly. iOS local HTTP testing may require an ATS exception in its build configuration.

## Build an Android APK

Build with the API URL for the target device/network:

```powershell
cd C:\xampp\htdocs\laundry-pias\mobile_app\flutter_app
flutter build apk --release --dart-define=API_BASE_URL=http://192.168.100.165/laundry-pias/api
```

The APK is created under `build/app/outputs/flutter-apk/`. Store release and app-store signing still need to be configured before distributing the app publicly.

## Build the APK in GitHub Actions (no local Android SDK)

The project includes `.github/workflows/build-customer-apk.yml`. To use it:

1. Create an empty repository on GitHub and sign in to GitHub from the computer.
2. In PowerShell, go to the project root and push the source (the Expo and Flutter project `.gitignore` files exclude generated build output and machine-specific SDK paths):

   ```powershell
   cd C:\xampp\htdocs\laundry-pias
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

## Expo / React Native version

An Expo version of the customer app is available in `expo_app/`. It recreates
the booking and ticket flow with the web layout shown in the supplied reference.
To preview it at `http://localhost:8081`, follow `expo_app/README.md` and run
`npm.cmd run web` from that directory. The current Flutter app and its APK
workflow are kept in place while the Expo version is reviewed.

## Moving the mobile app folder

The mobile client source and build manifests are grouped here: `expo_app/` is the Expo app, and `flutter_app/` is the Flutter Android/iOS app. You can copy or move the whole `mobile_app` folder outside `C:\xampp\htdocs` (for example, to `C:\Users\<you>\Documents\mobile_app`). To start the Expo browser preview, double-click `start-expo-web.bat`; it installs the locked npm dependencies the first time and starts Expo.

Keep the laundry shop PHP project, especially its `api/`, `config/`, and `includes/` folders, under XAMPP's `htdocs` so Apache can serve the API. Moving only `mobile_app` does not break the API URL. The computer and phone still need network access to the XAMPP computer. If you move the PHP project too, XAMPP will no longer serve it until Apache is configured for the new location.

For a different computer or network, recreate `expo_app/.env.local` with that computer's reachable API URL, for example `EXPO_PUBLIC_API_BASE_URL=http://<COMPUTER-LAN-IP>/laundry-pias/api`, and update the `preview` URL in `expo_app/eas.json` before making an EAS build. Do not copy `node_modules`, `.expo`, `dist`, Flutter `build`/`.dart_tool`, or Android `local.properties`; these are generated or machine-specific. Run `flutter pub get` from `flutter_app/` on the new computer if building the Flutter version.

The GitHub Actions workflow used for the Flutter APK must remain in the repository root at `.github/workflows/build-customer-apk.yml`; GitHub will not discover it if it is moved into this folder. It is not needed to run the Expo preview locally.





