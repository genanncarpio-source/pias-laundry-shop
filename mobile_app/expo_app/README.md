# Pia's Laundry Shop (Expo)

This Expo / React Native app is a parallel customer-app implementation, based on
the existing Flutter screens and customer API. It includes sign-in and account
registration, live services and prices, pickup or delivery booking, customer ticket tracking, and live delivery location maps. The web layout follows the supplied Jasbrew reference with a dark shop
header, search and category chips, service cards, and bottom navigation.

## Delivery and live rider tracking

Customers choose **Shop pickup** or **Deliver to me** when booking. Delivery
requests require a delivery address. In the staff web POS, open **Delivery
Drivers** to create a driver username and temporary password. Then open a
delivery order, assign an active driver, and mark the order **Out for Delivery**.
Only staff and admins can manage driver accounts; only the assigned driver's
login can retrieve that active delivery.

On the driver's phone, open `piaslaundry://driver` in the installed app and sign
in with the account created by the shop. The driver can view the delivery
address and the customer's GPS only if the customer chooses **Share my live
location with driver** on that active ticket. Customer sharing updates while
the customer's app is open and can be stopped at any time. Driver sharing can
continue in the background when permission is granted; it stops when the order
is completed or cancelled, or when the driver stops sharing. Customer accounts
do not have a link to the driver screen and cannot access driver-only delivery
records.

A new Android APK is required for these app changes and native location
permissions. Expo Go and the browser preview cannot run background driver
location tracking. Build the Expo APK from this folder by running:

    npx.cmd eas-cli@latest build --platform android --profile preview

The preview EAS profile API URL must point to the computer's reachable
`http://<LAN-IP>/laundry-pias/api` address, and the computer and phone must be
on the same network. Use HTTPS for an internet-accessible production API.
## GCash payments

In the staff web POS, open **Settings** and enter the Admin GCash account name and mobile number. Upload its official QR image (PNG, JPG, or WebP, up to 3 MB), then save. Customers can choose **GCash transfer** while booking, transfer the exact amount shown, enter the completed GCash reference, and submit the booking. Staff review the order's **Payments** section and verify the transfer in the Admin GCash account before processing it. If staff reject a reference, the customer can submit a corrected one from the **Track** screen.

This is a manual GCash transfer and reference-verification flow. It does not charge a wallet or automatically confirm payment. An automatic GCash checkout requires a GCash Business checkout integration and its credentials.

## Preview in a browser

From this folder, run:

```powershell
npm.cmd run web
```

Open the local URL printed by Expo (normally `http://localhost:8081`). Keep
Apache and MySQL running in XAMPP. The API address is set automatically from
`EXPO_PUBLIC_API_BASE_URL`; the address is no longer shown in the customer UI.
For local work, create `.env.local` in this folder with the computer's current
LAN address:

```text
EXPO_PUBLIC_API_BASE_URL=http://192.168.100.165/laundry-pias/api
```

Expo Go on a physical phone and the computer must use the same Wi-Fi, and Apache
must be allowed through Windows Firewall. The preview EAS profile is currently
configured with this computer's LAN address. If that address changes, update
`.env.local` and the `preview` profile in `eas.json` before starting Expo or
building another APK. Reserve the computer's address in the router to keep it
stable.

## Run on Android

Install Expo Go on the phone and run:

```powershell
npm.cmd start
```

Scan the QR code while the computer and phone are on the same Wi-Fi. Expo Go on
a physical phone cannot use `localhost` or `10.0.2.2`; the configured API URL
must use the computer's LAN address. Allow Apache through Windows Firewall. The
app stores the API token using Android secure storage.

The Android development config allows local HTTP so the app can reach XAMPP.
Use HTTPS before publishing or exposing the service outside local testing.

## Build an installable APK

Expo's cloud build can produce an APK without a local Android emulator. Sign in
to an Expo account, then run:

```powershell
npm.cmd install --global eas-cli
eas login
eas build --platform android --profile preview
```

The `preview` EAS profile is configured to produce an installable APK and points
to the current local XAMPP API. This works only while the phone is on the same
network as the computer. A production build needs `EXPO_PUBLIC_API_BASE_URL` set
to the shop's publicly reachable HTTPS API; GitHub Pages hosts only the web app,
not this PHP API. The existing GitHub Actions workflow still builds the Flutter
app, while this EAS profile builds the Expo version.
