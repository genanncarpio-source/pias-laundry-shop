import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:geolocator_android/geolocator_android.dart';
import 'package:url_launcher/url_launcher.dart';

import 'shared_widgets.dart';
import 'shop_api.dart';

class DriverScreen extends StatefulWidget {
  const DriverScreen({super.key});
  @override
  State<DriverScreen> createState() => _DriverScreenState();
}

class _DriverScreenState extends State<DriverScreen> {
  final _formKey = GlobalKey<FormState>();
  final _username = TextEditingController();
  final _password = TextEditingController();
  Map<String, dynamic>? _driver;
  List<Map<String, dynamic>> _deliveries = [];
  Timer? _poller;
  StreamSubscription<Position>? _positionStream;
  bool _loading = true;
  bool _busy = false;
  bool _sharing = false;
  bool _obscure = true;
  String? _error;
  String? _notice;

  @override
  void initState() {
    super.initState();
    _restoreDriver();
  }

  @override
  void dispose() {
    _poller?.cancel();
    _positionStream?.cancel();
    _username.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _restoreDriver() async {
    final token = await ShopApi.driverToken();
    if (token != null) {
      try {
        final result = await ShopApi.request('driver_me.php', authenticated: true, driver: true);
        _driver = Map<String, dynamic>.from(result['driver']);
        await _loadDeliveries(showErrors: false);
        _beginPolling();
      } catch (_) {
        await ShopApi.clearDriverToken();
      }
    }
    if (mounted) setState(() => _loading = false);
  }

  void _beginPolling() {
    _poller?.cancel();
    _poller = Timer.periodic(const Duration(seconds: 10), (_) => _loadDeliveries());
  }

  Future<void> _loadDeliveries({bool showErrors = true}) async {
    if (_driver == null) return;
    try {
      final result = await ShopApi.request('driver_deliveries.php', authenticated: true, driver: true);
      final deliveries = (result['deliveries'] as List? ?? []).map((item) => Map<String, dynamic>.from(item)).toList();
      if (mounted) setState(() { _deliveries = deliveries; if (showErrors) _error = null; });
    } catch (error) {
      if (mounted && showErrors) setState(() => _error = _message(error));
    }
  }

  String _message(Object error) => error.toString().replaceFirst('Exception: ', '');

  Future<void> _signIn() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() { _busy = true; _error = null; });
    try {
      final result = await ShopApi.request('driver_login.php', method: 'POST', body: {
        'username': _username.text.trim(),
        'password': _password.text,
      });
      await ShopApi.saveDriverToken(result['token'].toString());
      if (!mounted) return;
      setState(() => _driver = Map<String, dynamic>.from(result['driver']));
      await _loadDeliveries();
      _beginPolling();
    } catch (error) {
      if (mounted) setState(() => _error = _message(error));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<bool> _ensureLocationPermission() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      if (mounted) setState(() => _error = 'Turn on Location Services to share your route with customers.');
      return false;
    }
    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) permission = await Geolocator.requestPermission();
    if (permission == LocationPermission.denied || permission == LocationPermission.deniedForever) {
      if (mounted) setState(() => _error = 'Allow location access in your phone settings to start delivery tracking.');
      return false;
    }
    return true;
  }

  Future<void> _startSharing() async {
    if (_deliveries.isEmpty || !await _ensureLocationPermission()) return;
    setState(() { _busy = true; _error = null; _notice = null; });
    try {
      late LocationSettings settings;
      if (!kIsWeb && defaultTargetPlatform == TargetPlatform.android) {
        settings = AndroidSettings(
          accuracy: LocationAccuracy.high,
          distanceFilter: 25,
          intervalDuration: const Duration(seconds: 15),
          foregroundNotificationConfig: const ForegroundNotificationConfig(
            notificationTitle: "Pia's Laundry Shop delivery",
            notificationText: 'Sharing the driver location for active deliveries.',
            enableWakeLock: true,
            setOngoing: true,
          ),
        );
      } else if (!kIsWeb && defaultTargetPlatform == TargetPlatform.iOS) {
        settings = AppleSettings(
          accuracy: LocationAccuracy.high,
          activityType: ActivityType.automotiveNavigation,
          distanceFilter: 25,
          pauseLocationUpdatesAutomatically: false,
          allowBackgroundLocationUpdates: true,
          showBackgroundLocationIndicator: true,
        );
      } else {
        settings = const LocationSettings(accuracy: LocationAccuracy.high, distanceFilter: 25);
      }
      final firstPosition = await Geolocator.getCurrentPosition(locationSettings: settings);
      await _sendLocation(firstPosition);
      _positionStream?.cancel();
      _positionStream = Geolocator.getPositionStream(locationSettings: settings).listen(
        _sendLocation,
        onError: (Object error) { if (mounted) setState(() => _error = 'Location updates stopped: ${_message(error)}'); },
      );
      if (mounted) setState(() { _sharing = true; _notice = 'Your location is being shared with customers who have an active assigned delivery.'; });
    } catch (error) {
      if (mounted) setState(() => _error = 'Could not start location sharing. ${_message(error)}');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _sendLocation(Position position) async {
    try {
      await ShopApi.request('driver_tracking.php', method: 'POST', authenticated: true, driver: true, body: {
        'action': 'update',
        'latitude': position.latitude,
        'longitude': position.longitude,
        'accuracy': position.accuracy,
        'heading': position.heading,
        'speed': position.speed,
      });
      if (mounted) setState(() { _error = null; _notice = 'Location updated ${DateTime.now().toLocal().toString().substring(11, 16)}.'; });
    } catch (error) {
      if (mounted) setState(() => _error = _message(error));
    }
  }

  Future<void> _stopSharing() async {
    await _positionStream?.cancel();
    _positionStream = null;
    try {
      await ShopApi.request('driver_tracking.php', method: 'POST', authenticated: true, driver: true, body: {'action': 'stop'});
    } catch (error) {
      if (mounted) setState(() => _error = _message(error));
    }
    if (mounted) setState(() { _sharing = false; _notice = 'Location sharing stopped.'; });
  }

  Future<void> _signOut() async {
    if (_sharing) await _stopSharing();
    try {
      await ShopApi.request('driver_logout.php', method: 'POST', authenticated: true, driver: true, body: {});
    } catch (_) {}
    await ShopApi.clearDriverToken();
    _poller?.cancel();
    if (mounted) setState(() { _driver = null; _deliveries = []; _sharing = false; });
  }

  Future<void> _openAddress(String address) async {
    final uri = Uri.parse('https://www.google.com/maps/search/?api=1&query=${Uri.encodeComponent(address)}');
    if (!await launchUrl(uri, mode: LaunchMode.externalApplication) && mounted) {
      setState(() => _error = 'Could not open Maps on this device.');
    }
  }

  Future<void> _callCustomer(String? phone) async {
    if (phone == null || phone.isEmpty) return;
    final uri = Uri(scheme: 'tel', path: phone);
    if (!await launchUrl(uri) && mounted) setState(() => _error = 'Could not open the phone app.');
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        backgroundColor: AppColors.ink,
        appBar: AppBar(
          backgroundColor: AppColors.ink,
          foregroundColor: AppColors.cream,
          title: const Text('Driver delivery mode', style: TextStyle(fontWeight: FontWeight.bold)),
          actions: [
            if (_driver != null) IconButton(tooltip: 'Sign out', onPressed: _signOut, icon: const Icon(Icons.logout)),
            IconButton(tooltip: 'Back', onPressed: () => Navigator.of(context).maybePop(), icon: const Icon(Icons.close)),
          ],
        ),
        body: _loading
            ? const Center(child: CircularProgressIndicator(color: AppColors.lilac))
            : _driver == null
                ? _loginForm()
                : _driverHome(),
      );

  Widget _loginForm() => Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(20),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 560),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              const ShopBrandHeader(),
              const SizedBox(height: 20),
              Text('Driver sign in', style: Theme.of(context).textTheme.headlineSmall?.copyWith(color: AppColors.cream, fontWeight: FontWeight.w800)),
              const SizedBox(height: 6),
              const Text('Sign in with the driver account created by shop staff or an administrator.', style: TextStyle(color: Color(0xffd5c9d7))),
              const SizedBox(height: 18),
              Card(color: AppColors.cream, child: Padding(padding: const EdgeInsets.all(20), child: Form(key: _formKey, child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                if (_error != null) ...[ErrorBanner(_error!), const SizedBox(height: 14)],
                TextFormField(controller: _username, textInputAction: TextInputAction.next, decoration: const InputDecoration(labelText: 'Driver username', prefixIcon: Icon(Icons.person_outline)), validator: (value) => value == null || value.trim().isEmpty ? 'Enter your driver username.' : null),
                const SizedBox(height: 14),
                TextFormField(controller: _password, obscureText: _obscure, onFieldSubmitted: (_) => _signIn(), decoration: InputDecoration(labelText: 'Password', prefixIcon: const Icon(Icons.lock_outline), suffixIcon: IconButton(onPressed: () => setState(() => _obscure = !_obscure), icon: Icon(_obscure ? Icons.visibility_outlined : Icons.visibility_off_outlined))), validator: (value) => value == null || value.isEmpty ? 'Enter your password.' : null),
                const SizedBox(height: 18),
                FilledButton(onPressed: _busy ? null : _signIn, child: _busy ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2)) : const Text('Sign in as driver')),
              ])))),
            ]),
          ),
        ),
      );

  Widget _driverHome() => RefreshIndicator(
        onRefresh: _loadDeliveries,
        child: ListView(padding: const EdgeInsets.all(16), children: [
          Text('Hello, ${_driver?['name'] ?? 'Driver'}', style: Theme.of(context).textTheme.headlineSmall?.copyWith(color: AppColors.cream, fontWeight: FontWeight.w800)),
          const SizedBox(height: 6),
          const Text('Assigned active deliveries and customer-shared locations appear here.', style: TextStyle(color: Color(0xffd5c9d7))),
          const SizedBox(height: 16),
          if (_error != null) ErrorBanner(_error!),
          if (_notice != null) Padding(padding: const EdgeInsets.only(top: 10), child: Text(_notice!, style: const TextStyle(color: Color(0xffddc4e1)))),
          const SizedBox(height: 10),
          Card(color: AppColors.cream, child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Row(children: [const Icon(Icons.my_location, color: AppColors.plum), const SizedBox(width: 8), Expanded(child: Text(_sharing ? 'Location sharing is on' : 'Share your delivery location', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)))]),
            const SizedBox(height: 8),
            Text(_deliveries.isEmpty ? 'You need an active delivery assigned before location sharing can start.' : 'Your location is sent to the shop about every 15 seconds while sharing is active.', style: const TextStyle(color: AppColors.muted)),
            const SizedBox(height: 12),
            FilledButton.icon(
              onPressed: _busy || _deliveries.isEmpty ? null : (_sharing ? _stopSharing : _startSharing),
              icon: Icon(_sharing ? Icons.location_disabled : Icons.location_searching),
              label: Text(_sharing ? 'Stop location sharing' : 'Start location sharing'),
            ),
          ]))),
          const SizedBox(height: 14),
          Row(children: [Expanded(child: Text('Active deliveries (${_deliveries.length})', style: Theme.of(context).textTheme.titleLarge?.copyWith(color: AppColors.cream, fontWeight: FontWeight.bold))), IconButton(onPressed: _loadDeliveries, color: AppColors.cream, tooltip: 'Refresh', icon: const Icon(Icons.refresh))]),
          if (_deliveries.isEmpty) const Card(color: AppColors.cream, child: Padding(padding: EdgeInsets.all(18), child: Text('No active deliveries are assigned to your account right now.'))),
          ..._deliveries.map(_deliveryCard),
          const SizedBox(height: 30),
        ]),
      );

  Widget _deliveryCard(Map<String, dynamic> delivery) {
    final location = delivery['customer_location'] is Map ? Map<String, dynamic>.from(delivery['customer_location']) : null;
    final address = delivery['delivery_address']?.toString() ?? '';
    final lat = location == null ? null : number(location['latitude'], double.nan);
    final lon = location == null ? null : number(location['longitude'], double.nan);
    return Card(color: AppColors.cream, margin: const EdgeInsets.only(bottom: 12), child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Row(children: [Expanded(child: Text(delivery['ticket_number']?.toString() ?? 'Delivery', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16))), const Chip(label: Text('Out for Delivery'))]),
      Text(delivery['customer_name']?.toString() ?? 'Customer', style: const TextStyle(fontSize: 16)),
      const SizedBox(height: 5),
      Text(address.isEmpty ? 'No delivery address was saved.' : address),
      if (delivery['expected_delivery'] != null) Padding(padding: const EdgeInsets.only(top: 6), child: Text('Scheduled: ${prettyDate(delivery['expected_delivery'])}')),
      Wrap(spacing: 8, children: [
        if (address.isNotEmpty) OutlinedButton.icon(onPressed: () => _openAddress(address), icon: const Icon(Icons.map_outlined), label: const Text('Open address in Maps')),
        if (delivery['customer_phone'] != null) OutlinedButton.icon(onPressed: () => _callCustomer(delivery['customer_phone']?.toString()), icon: const Icon(Icons.call_outlined), label: const Text('Call customer')),
      ]),
      const SizedBox(height: 8),
      if (location != null && lat != null && lon != null && lat.isFinite && lon.isFinite) ...[
        LocationMapCard(latitude: lat, longitude: lon, label: 'Customer'),
        const SizedBox(height: 6),
        Text(location['stale'] == true ? 'Customer location may be out of date. Ask them to share an update.' : 'Customer shared this location with the assigned driver.', style: const TextStyle(color: AppColors.muted)),
        Text('Shared at ${prettyDate(location['updated_at'])}', style: const TextStyle(color: AppColors.muted, fontSize: 12)),
      ] else
        const Text('Customer location has not been shared. The customer can share it from their ticket while the delivery is active.', style: TextStyle(color: AppColors.muted)),
    ])));
  }
}

