# Pia's Laundry Shop Customer App

This is the Android/iOS Flutter project for the customer and driver app. Its Dart source, Android project, and iOS project are all contained in this folder. It uses the same PHP API and MySQL database as the Expo app; it never connects to MySQL directly. See ../README.md for setup and APK build instructions.

The Android package id is `com.piaslaundry.customer`. The API address is compiled
into the app with `--dart-define=API_BASE_URL=...`; the customer screen does not
show it. A physical phone must use the computer's LAN IP, share its network, and
be able to reach Apache through Windows Firewall. Use HTTPS outside local testing.


