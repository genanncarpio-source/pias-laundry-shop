# Pia's Laundry Shop Customer App and API

The project includes an English-language Flutter customer app source project in
`mobile_app/`, a .NET MAUI Android app in `mobile_app/maui/`, and a JSON API.
The web-based staff system remains where staff manage laundry service tickets.
`customer-app/` is an optional browser prototype.

## .NET MAUI app

Open `mobile_app/maui/LaundryCustomerMaui.csproj` in Visual Studio with the .NET
MAUI workload and Android SDK. See `mobile_app/maui/README.md` for run instructions
and how to set the emulator or physical phone API address.

## Flutter app setup

Follow `mobile_app/README.md` to install Flutter/Android command-line tools, run
the existing Android project in `mobile_app/native`, and build the APK. The sign-in
screen stores the API base URL on the device. Android emulator uses
`http://10.0.2.2/laundry-pos/api`. A physical phone and the XAMPP computer must be
on the same Wi-Fi; set the API URL to the computer's LAN IP, for example:

```text
http://192.168.1.20/laundry-pos/api
```

Apache must allow connections from the local network and the computer firewall must
allow Apache traffic. Use HTTPS before exposing the API outside the local network.
The Flutter app stores its bearer token using device secure storage. If local SDK
installation is impractical, the repository includes the GitHub Actions workflow
`.github/workflows/build-customer-apk.yml` for cloud APK and web preview builds.

## Flutter web preview

The same GitHub Actions workflow publishes the Flutter customer app to GitHub Pages
on every push to `main`. In the repository settings, open **Pages** and set the
deployment source to **GitHub Actions**. After the workflow succeeds, open the
`web-preview` deployment environment or the Pages URL shown in the workflow run.
For this repository, the URL will be:

```text
https://genanncarpio-source.github.io/pias-laundry-shop/
```

This is a browser preview of the customer app. GitHub Pages hosts only the Flutter
web files; it does not host the PHP API or MySQL database. To sign in or register
from the hosted preview, configure the app to use an internet-accessible HTTPS API
and configure that API to allow browser requests from the Pages origin. A local
XAMPP HTTP address such as `localhost`, `10.0.2.2`, or a LAN IP will not work as a
production API for the hosted HTTPS page.

## Browser prototype

With Apache and MySQL running in XAMPP, open:

```text
http://localhost/laundry-pos/customer-app/
```

Register using a name, email, phone number and password of at least 10 characters.
The account signs in automatically after registration. Existing customer records
without a password cannot sign in. If their email is already attached to a guest
customer record, they need to register with a different email for now.

## Customer flow

1. The customer registers or signs in. Customer accounts are separate from staff accounts.
2. The app loads active services and current prices from the web system.
3. The customer selects services grouped as Wash, Dry, Full Service, and Add-ons,
   enters quantities, and can suggest a pickup date and time. The customer request
   flow is pickup-only; it has no delivery choice. Prices come from the active
   services configured in the staff **Services** page.
4. The app submits a ticket request. The server calculates prices from the service
   catalogue; it does not trust prices sent by the app.
5. The ticket appears in the staff **Laundry Tickets** page as Pending and Unpaid.
   Staff process the laundry and record payment from the ticket details page.
6. The customer can sign back in to see their own tickets and status.

Customer registration creates a customer record, not a staff or admin account.
Customer tokens expire after 30 days, and only their hashes are stored in the
database. Signing out revokes the current token. Customer sessions are not backed
up, so customers must sign in again after a database restore.

## API endpoints

Base URL during local development:

```text
http://localhost/laundry-pos/api/
```

| Method | Endpoint | Sign-in required | Purpose |
|---|---|---:|---|
| `POST` | `register.php` | No | Create customer account and sign in |
| `POST` | `login.php` | No | Sign in with email and password |
| `POST` | `logout.php` | Yes | Revoke current session token |
| `GET` | `me.php` | Yes | Get the signed-in customer profile |
| `GET` | `services.php` | No | List active laundry services |
| `POST` | `tickets.php` | Yes | Submit a service ticket request |
| `GET` | `my_tickets.php` | Yes | List that customer's tickets |

Send JSON with `Content-Type: application/json`. Signed-in requests send the token
returned by registration or login in this header:

```text
Authorization: Bearer <token>
```

Example service request:

```json
{
  "services": [
    { "service_id": 1, "quantity": 3.5 },
    { "service_id": 4, "quantity": 2 }
  ],
  "expected_pickup": "2026-10-02T09:00:00.000Z",
  "notes": "Please separate the white clothes."
}
```

The ticket is a request with an estimated total. The app does not take payment and
does not promise that the requested pickup time is confirmed. Staff confirm and
process the request in the web system.

Email ownership is not verified and password reset is not available in this first
version. Add email verification or phone OTP before opening registration to the
public internet.
