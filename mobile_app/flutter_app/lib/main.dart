import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:geolocator_android/geolocator_android.dart';
import 'package:url_launcher/url_launcher.dart';

import 'driver_screen.dart';
import 'shared_widgets.dart';
import 'shop_api.dart';

const currencySymbol = '\u20B1';
void main() => runApp(const LaundryCustomerApp());

class LaundryCustomerApp extends StatelessWidget {
  const LaundryCustomerApp({super.key});
  @override
  Widget build(BuildContext context) => MaterialApp(
        title: "Pia's Laundry Shop",
        debugShowCheckedModeBanner: false,
        theme: ThemeData(
          useMaterial3: true,
          colorScheme: ColorScheme.fromSeed(seedColor: AppColors.purple, brightness: Brightness.light, surface: AppColors.cream),
          scaffoldBackgroundColor: AppColors.ink,
          appBarTheme: const AppBarTheme(backgroundColor: AppColors.ink, foregroundColor: AppColors.cream, elevation: 0),
          cardTheme: CardThemeData(color: AppColors.cream, elevation: 1, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18))),
          inputDecorationTheme: InputDecorationTheme(
            filled: true, fillColor: AppColors.cream,
            border: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: const BorderSide(color: AppColors.line)),
            enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: const BorderSide(color: AppColors.line)),
            focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: const BorderSide(color: AppColors.purple, width: 1.5)),
            contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
          ),
          filledButtonTheme: FilledButtonThemeData(style: FilledButton.styleFrom(backgroundColor: AppColors.plum, foregroundColor: Colors.white, padding: const EdgeInsets.symmetric(vertical: 14), shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)))),
        ),
        home: const SessionGate(),
      );
}

class SessionGate extends StatefulWidget {
  const SessionGate({super.key});
  @override
  State<SessionGate> createState() => _SessionGateState();
}
class _SessionGateState extends State<SessionGate> {
  bool _checking = true;
  Map<String, dynamic>? _customer;
  @override
  void initState() { super.initState(); _restoreSession(); }
  Future<void> _restoreSession() async {
    if (await ShopApi.customerToken() != null) {
      try { _customer = Map<String, dynamic>.from((await ShopApi.request('me.php', authenticated: true))['customer']); }
      catch (_) { await ShopApi.clearCustomerToken(); }
    }
    if (mounted) setState(() => _checking = false);
  }
  void _signedIn(Map<String, dynamic> customer) => setState(() => _customer = customer);
  Future<void> _signedOut() async {
    try { await ShopApi.request('logout.php', method: 'POST', body: {}, authenticated: true); } catch (_) {}
    await ShopApi.clearCustomerToken();
    if (mounted) setState(() => _customer = null);
  }
  @override
  Widget build(BuildContext context) {
    if (_checking) return const Scaffold(backgroundColor: AppColors.ink, body: Center(child: CircularProgressIndicator(color: AppColors.lilac)));
    return _customer == null ? AuthScreen(onSignedIn: _signedIn) : CustomerHome(customer: _customer!, onSignOut: _signedOut);
  }
}

class AuthScreen extends StatefulWidget {
  const AuthScreen({required this.onSignedIn, super.key});
  final ValueChanged<Map<String, dynamic>> onSignedIn;
  @override
  State<AuthScreen> createState() => _AuthScreenState();
}
class _AuthScreenState extends State<AuthScreen> {
  final _formKey = GlobalKey<FormState>();
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _phone = TextEditingController();
  final _address = TextEditingController();
  final _password = TextEditingController();
  bool _registering = false, _busy = false, _obscurePassword = true;
  String? _error;
  @override
  void dispose() { _name.dispose(); _email.dispose(); _phone.dispose(); _address.dispose(); _password.dispose(); super.dispose(); }
  String _message(Object error) => error.toString().replaceFirst('Exception: ', '');
  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() { _busy = true; _error = null; });
    try {
      final result = await ShopApi.request(_registering ? 'register.php' : 'login.php', method: 'POST', body: {
        if (_registering) 'name': _name.text.trim(),
        if (_registering) 'phone': _phone.text.trim(),
        if (_registering) 'address': _address.text.trim(),
        'email': _email.text.trim(), 'password': _password.text,
      });
      await ShopApi.saveCustomerToken(result['token'].toString());
      widget.onSignedIn(Map<String, dynamic>.from(result['customer']));
    } catch (error) { if (mounted) setState(() => _error = _message(error)); }
    finally { if (mounted) setState(() => _busy = false); }
  }
  @override
  Widget build(BuildContext context) => Scaffold(body: SafeArea(child: Center(child: SingleChildScrollView(padding: const EdgeInsets.all(20), child: ConstrainedBox(
    constraints: const BoxConstraints(maxWidth: 540),
    child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      const ShopBrandHeader(), const SizedBox(height: 24),
      Text(_registering ? 'Create your account' : 'Welcome back', textAlign: TextAlign.center, style: Theme.of(context).textTheme.headlineMedium?.copyWith(color: AppColors.cream, fontWeight: FontWeight.w900)),
      const SizedBox(height: 8),
      Text(_registering ? 'Create an account to book a service and track your laundry.' : 'Sign in to book a service and follow your laundry ticket.', textAlign: TextAlign.center, style: const TextStyle(color: Color(0xffd6c9d8), fontSize: 15)),
      const SizedBox(height: 20),
      Card(child: Padding(padding: const EdgeInsets.all(20), child: Form(key: _formKey, child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        SegmentedButton<bool>(segments: const [ButtonSegment(value: false, label: Text('Sign in')), ButtonSegment(value: true, label: Text('Create account'))], selected: {_registering}, onSelectionChanged: _busy ? null : (value) => setState(() { _registering = value.first; _error = null; })),
        if (_error != null) ...[const SizedBox(height: 14), ErrorBanner(_error!)],
        const SizedBox(height: 16),
        if (_registering) ...[
          TextFormField(controller: _name, textCapitalization: TextCapitalization.words, autofillHints: const [AutofillHints.name], decoration: const InputDecoration(labelText: 'Full name', prefixIcon: Icon(Icons.person_outline)), validator: (value) => value == null || value.trim().isEmpty ? 'Enter your full name.' : null),
          const SizedBox(height: 12),
        ],
        TextFormField(controller: _email, keyboardType: TextInputType.emailAddress, autofillHints: const [AutofillHints.email], decoration: const InputDecoration(labelText: 'Email address', prefixIcon: Icon(Icons.email_outlined)), validator: (value) => value == null || !value.contains('@') ? 'Enter a valid email address.' : null),
        if (_registering) ...[
          const SizedBox(height: 12),
          TextFormField(controller: _phone, keyboardType: TextInputType.phone, autofillHints: const [AutofillHints.telephoneNumber], decoration: const InputDecoration(labelText: 'Phone number', prefixIcon: Icon(Icons.phone_outlined)), validator: (value) => value == null || value.trim().isEmpty ? 'Enter your phone number.' : null),
          const SizedBox(height: 12),
          TextFormField(controller: _address, textCapitalization: TextCapitalization.sentences, minLines: 2, maxLines: 3, decoration: const InputDecoration(labelText: 'Home or pickup address', prefixIcon: Icon(Icons.home_outlined)), validator: (value) => value == null || value.trim().isEmpty ? 'Enter your pickup address.' : null),
        ],
        const SizedBox(height: 12),
        TextFormField(controller: _password, obscureText: _obscurePassword, autofillHints: _registering ? const [AutofillHints.newPassword] : const [AutofillHints.password], decoration: InputDecoration(labelText: 'Password', helperText: _registering ? 'Use at least 10 characters' : null, prefixIcon: const Icon(Icons.lock_outline), suffixIcon: IconButton(tooltip: _obscurePassword ? 'Show password' : 'Hide password', onPressed: () => setState(() => _obscurePassword = !_obscurePassword), icon: Icon(_obscurePassword ? Icons.visibility_outlined : Icons.visibility_off_outlined))), validator: (value) => value == null || value.isEmpty || (_registering && value.length < 10) ? (_registering ? 'Use at least 10 characters.' : 'Enter your password.') : null, onFieldSubmitted: (_) => _submit()),
        const SizedBox(height: 18),
        FilledButton(onPressed: _busy ? null : _submit, child: _busy ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2)) : Text(_registering ? 'Create account' : 'Sign in')),
        const SizedBox(height: 12),
        const Text('The shop confirms your service, price, and pickup or delivery schedule.', textAlign: TextAlign.center, style: TextStyle(color: AppColors.muted, fontSize: 12)),
      ])))),
      const SizedBox(height: 16),
      TextButton.icon(onPressed: () => Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => const DriverScreen())), icon: const Icon(Icons.local_shipping_outlined, color: Color(0xffe1c9e5)), label: const Text('Driver sign in', style: TextStyle(color: Color(0xffe1c9e5), fontWeight: FontWeight.bold))),
    ]),
  )))));
}

class CustomerHome extends StatefulWidget {
  const CustomerHome({required this.customer, required this.onSignOut, super.key});
  final Map<String, dynamic> customer;
  final Future<void> Function() onSignOut;
  @override
  State<CustomerHome> createState() => _CustomerHomeState();
}
class _CustomerHomeState extends State<CustomerHome> {
  List<Map<String, dynamic>> _services = [], _tickets = [];
  Map<String, dynamic> _payment = {};
  final Map<int, TextEditingController> _quantities = {}, _retryReferences = {};
  final Map<int, DateTime> _lastLocationSent = {};
  final _notes = TextEditingController(), _search = TextEditingController(), _deliveryAddress = TextEditingController(), _gcashReference = TextEditingController();
  StreamSubscription<Position>? _customerLocationStream;
  Timer? _trackingPoller;
  DateTime? _scheduledAt;
  bool _loading = true, _sending = false, _trackingBusy = false, _sharingLocation = false;
  int _tab = 0;
  String _category = 'All', _fulfillment = 'pickup', _paymentMethod = 'at_shop';
  String? _error;
  static const _categories = ['All', 'Wash', 'Dry', 'Full service', 'Add-ons'];
  static const _serviceSections = ['Wash', 'Dry', 'Full service', 'Add-ons', 'Other services'];

  @override
  void initState() { super.initState(); _deliveryAddress.text = widget.customer['address']?.toString() ?? ''; _load(); }
  @override
  void dispose() {
    _trackingPoller?.cancel(); _customerLocationStream?.cancel();
    for (final controller in _quantities.values) { controller.dispose(); }
    for (final controller in _retryReferences.values) { controller.dispose(); }
    _notes.dispose(); _search.dispose(); _deliveryAddress.dispose(); _gcashReference.dispose(); super.dispose();
  }
  String _message(Object error) => error.toString().replaceFirst('Exception: ', '');
  String _sectionFor(Map<String, dynamic> service) {
    final name = (service['name'] ?? '').toString().toLowerCase();
    final category = (service['category'] ?? '').toString().toLowerCase();
    if (category.contains('add') || name.contains('detergent') || name.contains('fabcon') || name.contains('fold')) return 'Add-ons';
    if (name.contains('full service') || name.contains('wash-dry-fold') || category.contains('full service') || category.contains('wash & fold')) return 'Full service';
    if (name == 'wash' || category == 'wash') return 'Wash';
    if (name == 'dry' || name.contains('dry clean') || category == 'dry') return 'Dry';
    return 'Other services';
  }
  List<Map<String, dynamic>> get _visibleServices {
    final query = _search.text.trim().toLowerCase();
    return _services.where((service) {
      final section = _sectionFor(service);
      return (_category == 'All' || section.toLowerCase() == _category.toLowerCase()) &&
          (query.isEmpty || '${service['name']} ${service['description']} ${service['category']}'.toLowerCase().contains(query));
    }).toList();
  }
  double get _estimate => _services.fold<double>(0, (sum, service) {
    final id = int.tryParse(service['id'].toString()) ?? 0;
    return sum + (double.tryParse(_quantities[id]?.text ?? '') ?? 0) * number(service['price']);
  });

  Future<void> _load({bool showLoading = true}) async {
    if (showLoading && mounted) setState(() { _loading = true; _error = null; });
    try {
      final response = await Future.wait([
        ShopApi.request('services.php'),
        ShopApi.request('my_tickets.php', authenticated: true),
        ShopApi.request('payment_info.php', authenticated: true),
      ]);
      final services = (response[0]['services'] as List? ?? []).map((item) => Map<String, dynamic>.from(item)).toList();
      final tickets = (response[1]['tickets'] as List? ?? []).map((item) => Map<String, dynamic>.from(item)).toList();
      for (final service in services) {
        final id = int.tryParse(service['id'].toString());
        if (id != null) _quantities.putIfAbsent(id, TextEditingController.new);
      }
      for (final ticket in tickets) {
        final id = int.tryParse(ticket['id']?.toString() ?? '');
        if (id != null && ticket['order_type'] == 'delivery_request' && ticket['status'] == 'out_for_delivery') {
          try {
            ticket['tracking'] = Map<String, dynamic>.from((await ShopApi.request('delivery_tracking.php?order_id=$id', authenticated: true))['tracking']);
          } catch (_) { ticket['tracking'] = {'active': false}; }
        }
      }
      if (!mounted) return;
      setState(() { _services = services; _tickets = tickets; _payment = Map<String, dynamic>.from(response[2]['payment'] ?? {}); _error = null; });
      _syncCustomerLocationStream();
    } catch (error) { if (mounted) setState(() => _error = _message(error)); }
    finally { if (mounted && showLoading) setState(() => _loading = false); }
  }
  void _selectTab(int value) {
    setState(() => _tab = value);
    if (value == 1) {
      _load(showLoading: false);
      _trackingPoller ??= Timer.periodic(const Duration(seconds: 12), (_) => _load(showLoading: false));
    } else { _trackingPoller?.cancel(); _trackingPoller = null; }
  }
  Future<void> _chooseSchedule() async {
    final now = DateTime.now();
    final initial = _scheduledAt != null && _scheduledAt!.isAfter(now) ? _scheduledAt! : now.add(const Duration(days: 1));
    final date = await showDatePicker(context: context, initialDate: initial, firstDate: now, lastDate: now.add(const Duration(days: 365)));
    if (date == null || !mounted) return;
    final time = await showTimePicker(context: context, initialTime: _scheduledAt == null ? const TimeOfDay(hour: 9, minute: 0) : TimeOfDay.fromDateTime(_scheduledAt!));
    if (time != null && mounted) setState(() => _scheduledAt = DateTime(date.year, date.month, date.day, time.hour, time.minute));
  }
  IconData _sectionIcon(String section) => switch (section) {
    'Wash' => Icons.local_laundry_service, 'Dry' => Icons.air, 'Full service' => Icons.checkroom, 'Add-ons' => Icons.add_circle_outline, _ => Icons.cleaning_services,
  };
  Uri? _qrUri() {
    final path = _payment['qr_path']?.toString() ?? '';
    return path.isEmpty ? null : Uri.parse('${ShopApi.siteRoot}/$path');
  }
  String? _validateChosenServices(List<Map<String, dynamic>> chosen) {
    if (chosen.isEmpty) return 'Select at least one service and quantity.';
    for (final line in chosen) {
      final service = _services.firstWhere((item) => int.tryParse(item['id'].toString()) == line['service_id']);
      final unit = service['unit']?.toString() ?? '';
      if ((unit == 'piece' || unit == 'load') && (line['quantity'] as double) % 1 != 0) return '${service['name']} uses whole $unit quantities.';
    }
    if (_fulfillment == 'delivery' && _deliveryAddress.text.trim().isEmpty) return 'Enter the address where the shop should deliver your laundry.';
    if (_fulfillment == 'delivery' && _deliveryAddress.text.trim().length > 255) return 'Delivery address must be 255 characters or fewer.';
    if (_paymentMethod == 'gcash' && _payment['configured'] != true) return 'The shop has not configured GCash payments yet. Choose pay at shop.';
    if (_paymentMethod == 'gcash' && _gcashReference.text.trim().length < 6) return 'Enter the GCash reference number after completing the transfer.';
    return null;
  }
  Future<void> _sendRequest() async {
    final chosen = <Map<String, dynamic>>[];
    for (final service in _services) {
      final id = int.tryParse(service['id'].toString()) ?? 0;
      final qty = double.tryParse(_quantities[id]?.text.trim() ?? '') ?? 0;
      if (qty > 0) chosen.add({'service_id': id, 'quantity': qty});
    }
    final validation = _validateChosenServices(chosen);
    if (validation != null) { setState(() => _error = validation); return; }
    setState(() { _sending = true; _error = null; });
    try {
      final result = await ShopApi.request('tickets.php', method: 'POST', authenticated: true, body: {
        'services': chosen, 'fulfillment_type': _fulfillment,
        if (_fulfillment == 'delivery') 'delivery_address': _deliveryAddress.text.trim(),
        'expected_pickup': _scheduledAt?.toUtc().toIso8601String(), 'notes': _notes.text.trim(),
        'payment_method': _paymentMethod,
        if (_paymentMethod == 'gcash') 'payment_amount': double.parse(_estimate.toStringAsFixed(2)),
        if (_paymentMethod == 'gcash') 'payment_reference': _gcashReference.text.trim(),
      });
      if (!mounted) return;
      final number = result['ticket']?['ticket_number']?.toString() ?? '';
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Service booked${number.isEmpty ? '.' : ' · Ticket $number'}')));
      for (final controller in _quantities.values) { controller.clear(); }
      _notes.clear(); _gcashReference.clear();
      setState(() { _scheduledAt = null; _fulfillment = 'pickup'; _paymentMethod = 'at_shop'; });
      await _load();
      _selectTab(1);
    } catch (error) { if (mounted) setState(() => _error = _message(error)); }
    finally { if (mounted) setState(() => _sending = false); }
  }
  Future<bool> _ensureLocationPermission() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      if (mounted) setState(() => _error = 'Turn on Location Services to share your current address with the driver.');
      return false;
    }
    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) permission = await Geolocator.requestPermission();
    if (permission == LocationPermission.denied || permission == LocationPermission.deniedForever) {
      if (mounted) setState(() => _error = 'Allow location access in your phone settings before sharing your location.');
      return false;
    }
    return true;
  }
  Future<void> _setLocationSharing(Map<String, dynamic> ticket, {required bool share}) async {
    final id = int.tryParse(ticket['id']?.toString() ?? '');
    if (id == null) return;
    setState(() { _trackingBusy = true; _error = null; });
    try {
      final body = <String, dynamic>{'order_id': id, 'action': share ? 'share' : 'revoke'};
      if (share) {
        if (!await _ensureLocationPermission()) return;
        final position = await Geolocator.getCurrentPosition(locationSettings: const LocationSettings(accuracy: LocationAccuracy.high));
        body.addAll({'latitude': position.latitude, 'longitude': position.longitude, 'accuracy': position.accuracy});
      }
      await ShopApi.request('customer_delivery_location.php', method: 'POST', authenticated: true, body: body);
      await _load(showLoading: false);
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(share ? 'Your location is shared with the assigned driver.' : 'Location sharing stopped.')));
    } catch (error) { if (mounted) setState(() => _error = _message(error)); }
    finally { if (mounted) setState(() => _trackingBusy = false); }
  }
  void _syncCustomerLocationStream() {
    final shared = _tickets.any((ticket) {
      final tracking = ticket['tracking'];
      return tracking is Map && tracking['active'] == true && tracking['customer_location_shared'] == true;
    });
    if (!shared) {
      _customerLocationStream?.cancel(); _customerLocationStream = null; _sharingLocation = false;
    } else if (_customerLocationStream == null) { _startCustomerLocationStream(); }
  }
  Future<void> _startCustomerLocationStream() async {
    if (!await _ensureLocationPermission()) return;
    late LocationSettings settings;
    if (!kIsWeb && defaultTargetPlatform == TargetPlatform.android) {
      settings = AndroidSettings(accuracy: LocationAccuracy.high, distanceFilter: 25, intervalDuration: const Duration(seconds: 15), foregroundNotificationConfig: const ForegroundNotificationConfig(
        notificationTitle: "Pia's Laundry Shop", notificationText: 'Sharing your location with your active delivery driver.', enableWakeLock: true, setOngoing: true,
      ));
    } else if (!kIsWeb && defaultTargetPlatform == TargetPlatform.iOS) {
      settings = AppleSettings(accuracy: LocationAccuracy.high, activityType: ActivityType.fitness, distanceFilter: 25, pauseLocationUpdatesAutomatically: false, allowBackgroundLocationUpdates: true, showBackgroundLocationIndicator: true);
    } else { settings = const LocationSettings(accuracy: LocationAccuracy.high, distanceFilter: 25); }
    try {
      _customerLocationStream = Geolocator.getPositionStream(locationSettings: settings).listen(_sendSharedCustomerLocation, onError: (Object error) {
        if (mounted) setState(() => _error = 'Location sharing paused: ${_message(error)}');
      });
      if (mounted) setState(() => _sharingLocation = true);
    } catch (error) { if (mounted) setState(() => _error = 'Could not start location sharing: ${_message(error)}'); }
  }
  Future<void> _sendSharedCustomerLocation(Position position) async {
    final now = DateTime.now();
    final sharedTickets = _tickets.where((ticket) {
      final tracking = ticket['tracking'];
      return tracking is Map && tracking['active'] == true && tracking['customer_location_shared'] == true;
    }).toList();
    for (final ticket in sharedTickets) {
      final id = int.tryParse(ticket['id']?.toString() ?? '');
      if (id == null || (_lastLocationSent[id] != null && now.difference(_lastLocationSent[id]!) < const Duration(seconds: 15))) continue;
      _lastLocationSent[id] = now;
      try {
        await ShopApi.request('customer_delivery_location.php', method: 'POST', authenticated: true, body: {'order_id': id, 'action': 'share', 'latitude': position.latitude, 'longitude': position.longitude, 'accuracy': position.accuracy});
      } catch (_) {}
    }
  }
  Future<void> _retryGcash(Map<String, dynamic> ticket) async {
    final id = int.tryParse(ticket['id']?.toString() ?? '');
    if (id == null) return;
    final controller = _retryReferences.putIfAbsent(id, TextEditingController.new);
    final submitted = await showDialog<String>(context: context, builder: (context) => AlertDialog(
      title: const Text('Resubmit GCash reference'),
      content: TextField(controller: controller, autofocus: true, decoration: const InputDecoration(labelText: 'New GCash reference number'), textCapitalization: TextCapitalization.characters),
      actions: [TextButton(onPressed: () => Navigator.pop(context), child: const Text('Cancel')), FilledButton(onPressed: () => Navigator.pop(context, controller.text.trim()), child: const Text('Submit'))],
    ));
    if (submitted == null || submitted.length < 6) return;
    setState(() { _sending = true; _error = null; });
    try {
      await ShopApi.request('gcash_payment.php', method: 'POST', authenticated: true, body: {'order_id': id, 'payment_amount': number(ticket['total']), 'reference_number': submitted});
      controller.clear(); await _load(showLoading: false);
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('New GCash reference sent for shop verification.')));
    } catch (error) { if (mounted) setState(() => _error = _message(error)); }
    finally { if (mounted) setState(() => _sending = false); }
  }
  Future<void> _openGcashApp() async {
    if (!await launchUrl(Uri.parse('https://www.gcash.com/'), mode: LaunchMode.externalApplication) && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Send the payment to the shop GCash number: ${_payment['number'] ?? ''}')));
    }
  }
  Future<void> _signOut() async {
    await _customerLocationStream?.cancel();
    _customerLocationStream = null;
    for (final ticket in _tickets) {
      final tracking = ticket['tracking'];
      final id = int.tryParse(ticket['id']?.toString() ?? '');
      if (id != null && tracking is Map && tracking['customer_location_shared'] == true) {
        try {
          await ShopApi.request('customer_delivery_location.php', method: 'POST', authenticated: true, body: {'order_id': id, 'action': 'revoke'});
        } catch (_) {}
      }
    }
    await widget.onSignOut();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text("Pia's Laundry Shop", style: TextStyle(fontWeight: FontWeight.bold)), actions: [IconButton(tooltip: 'Sign out', onPressed: _signOut, icon: const Icon(Icons.logout))]),
    body: _loading ? const Center(child: CircularProgressIndicator(color: AppColors.lilac)) : RefreshIndicator(onRefresh: _load, child: _tab == 0 ? _bookScreen() : _trackScreen()),
    bottomNavigationBar: NavigationBar(selectedIndex: _tab, onDestinationSelected: _selectTab, backgroundColor: AppColors.cream, indicatorColor: const Color(0xffeadbed), destinations: const [
      NavigationDestination(icon: Icon(Icons.local_laundry_service_outlined), selectedIcon: Icon(Icons.local_laundry_service), label: 'Book'),
      NavigationDestination(icon: Icon(Icons.receipt_long_outlined), selectedIcon: Icon(Icons.receipt_long), label: 'Track'),
    ]),
  );

  Widget _bookScreen() => ListView(padding: const EdgeInsets.fromLTRB(16, 8, 16, 28), children: [
    Text('Hello, ${widget.customer['name'] ?? 'Customer'}', style: Theme.of(context).textTheme.titleLarge?.copyWith(color: AppColors.cream, fontWeight: FontWeight.w800)),
    const SizedBox(height: 12), const ShopBrandHeader(), const SizedBox(height: 16),
    Row(children: [Expanded(child: Text('Fresh laundry, made easy.', style: Theme.of(context).textTheme.titleMedium?.copyWith(color: AppColors.cream, fontWeight: FontWeight.bold))), const Chip(avatar: Icon(Icons.shopping_bag_outlined, size: 16), label: Text('PICKUP & DELIVERY'))]),
    const Text('Choose your service, book pickup or delivery, then follow your ticket.', style: TextStyle(color: Color(0xffd6c9d8))),
    const SizedBox(height: 14),
    TextField(controller: _search, onChanged: (_) => setState(() {}), decoration: InputDecoration(hintText: 'Search laundry services...', prefixIcon: const Icon(Icons.search), suffixIcon: IconButton(tooltip: 'Clear search', onPressed: () { _search.clear(); setState(() {}); }, icon: const Icon(Icons.refresh)))),
    const SizedBox(height: 10),
    SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: Row(children: _categories.map((category) => Padding(
        padding: const EdgeInsets.only(right: 8),
        child: ChoiceChip(label: Text(category), selected: _category == category, onSelected: (_) => setState(() => _category = category)),
      )).toList()),
    ),
    const SizedBox(height: 12),
    if (_services.isEmpty) const Card(child: Padding(padding: EdgeInsets.all(18), child: Text('No services are currently available.'))),
    if (_services.isNotEmpty && _visibleServices.isEmpty) const Card(child: Padding(padding: EdgeInsets.all(18), child: Text('No services match your search.'))),
    ..._serviceSections.map((section) {
      final items = _visibleServices.where((item) => _sectionFor(item) == section).toList();
      if (items.isEmpty) return const SizedBox.shrink();
      return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        const SizedBox(height: 8), Row(children: [Icon(_sectionIcon(section), color: const Color(0xffd6a0df)), const SizedBox(width: 8), Text(section, style: const TextStyle(color: Color(0xffeddbf0), fontSize: 17, fontWeight: FontWeight.bold))]),
        const SizedBox(height: 6), ...items.map(_serviceCard),
      ]);
    }),
    const SizedBox(height: 10), _fulfillmentCard(), const SizedBox(height: 12), _paymentCard(), const SizedBox(height: 12),
    TextField(controller: _notes, maxLength: 255, maxLines: 2, style: const TextStyle(color: AppColors.ink), decoration: const InputDecoration(labelText: 'Notes (optional)', hintText: 'Special instructions for the shop')),
    Align(alignment: Alignment.centerRight, child: Text('Estimated total: $currencySymbol${_estimate.toStringAsFixed(2)}', style: const TextStyle(color: AppColors.cream, fontWeight: FontWeight.bold, fontSize: 17))),
    if (_error != null) ...[const SizedBox(height: 10), ErrorBanner(_error!)], const SizedBox(height: 10),
    FilledButton.icon(onPressed: _sending || _estimate <= 0 ? null : _sendRequest, icon: _sending ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.send), label: Text(_sending ? 'Submitting...' : 'Book Service')),
    const SizedBox(height: 7), const Text('The shop confirms your final service, price, and schedule.', textAlign: TextAlign.center, style: TextStyle(color: Color(0xffc7b9ca), fontSize: 12)),
  ]);
  Widget _serviceCard(Map<String, dynamic> service) {
    final id = int.tryParse(service['id'].toString()) ?? 0;
    final unit = service['unit']?.toString() ?? 'unit';
    final price = number(service['price']);
    final description = service['description']?.toString() ?? '';
    return Card(margin: const EdgeInsets.symmetric(vertical: 5), clipBehavior: Clip.antiAlias, child: Row(children: [
      Container(width: 8, height: 104, color: AppColors.purple),
      Expanded(child: Padding(padding: const EdgeInsets.fromLTRB(12, 10, 10, 10), child: Row(children: [
        const CircleAvatar(backgroundColor: AppColors.palePlum, child: Icon(Icons.local_laundry_service, color: AppColors.purple)),
        const SizedBox(width: 11),
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(service['name']?.toString() ?? 'Service', style: const TextStyle(fontWeight: FontWeight.w800, color: AppColors.ink)),
          Text(description.isEmpty ? 'Price per $unit' : description, style: const TextStyle(color: AppColors.muted, fontSize: 12)),
          Text('$currencySymbol${price.toStringAsFixed(2)} / $unit', style: const TextStyle(color: AppColors.purple, fontWeight: FontWeight.w800)),
        ])),
        const SizedBox(width: 8),
        SizedBox(width: 78, child: TextField(controller: _quantities[id], keyboardType: TextInputType.numberWithOptions(decimal: unit != 'piece' && unit != 'load'), textAlign: TextAlign.center, onChanged: (_) => setState(() {}), decoration: InputDecoration(labelText: unit, hintText: '0', isDense: true, contentPadding: const EdgeInsets.symmetric(horizontal: 7, vertical: 12)))),
      ]))),
    ]));
  }

  Widget _fulfillmentCard() => Card(child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
    Row(children: [const Icon(Icons.local_shipping_outlined, color: AppColors.purple), const SizedBox(width: 8), Expanded(child: Text('Pickup or delivery', style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.bold))), const Text('CHOOSE ONE', style: TextStyle(fontSize: 10, color: AppColors.purple, fontWeight: FontWeight.bold))]),
    const SizedBox(height: 8),
    SegmentedButton<String>(segments: const [ButtonSegment(value: 'pickup', label: Text('Pickup'), icon: Icon(Icons.storefront_outlined)), ButtonSegment(value: 'delivery', label: Text('Delivery'), icon: Icon(Icons.delivery_dining))], selected: {_fulfillment}, onSelectionChanged: (value) => setState(() { _fulfillment = value.first; _error = null; })),
    const SizedBox(height: 8),
    Text(_fulfillment == 'pickup' ? 'The shop will confirm when your laundry is ready for pickup.' : 'Enter the address where the shop should deliver your finished laundry.', style: const TextStyle(color: AppColors.muted)),
    if (_fulfillment == 'delivery') ...[
      const SizedBox(height: 10),
      TextField(controller: _deliveryAddress, textCapitalization: TextCapitalization.sentences, maxLength: 255, minLines: 2, maxLines: 3, decoration: const InputDecoration(labelText: 'Delivery address', hintText: 'House number, street, barangay, city', prefixIcon: Icon(Icons.location_on_outlined))),
    ],
    const SizedBox(height: 8),
    OutlinedButton.icon(onPressed: _chooseSchedule, icon: const Icon(Icons.calendar_month), label: Text(_scheduledAt == null ? (_fulfillment == 'delivery' ? 'Choose delivery date and time (optional)' : 'Choose pickup date and time (optional)') : prettyDate(_scheduledAt!.toIso8601String()))),
    if (_scheduledAt != null) TextButton.icon(onPressed: () => setState(() => _scheduledAt = null), icon: const Icon(Icons.close), label: const Text('Clear schedule')),
  ])));

  Widget _paymentCard() => Card(child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
    Row(children: [const Icon(Icons.payments_outlined, color: AppColors.purple), const SizedBox(width: 8), Text('Payment method', style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.bold))]),
    RadioListTile<String>(contentPadding: EdgeInsets.zero, value: 'at_shop', groupValue: _paymentMethod, onChanged: (value) => setState(() => _paymentMethod = value!), title: const Text('Pay at the shop'), subtitle: const Text('Settle the amount when the shop confirms your service.')),
    RadioListTile<String>(contentPadding: EdgeInsets.zero, value: 'gcash', groupValue: _paymentMethod, onChanged: _payment['configured'] == true ? (value) => setState(() => _paymentMethod = value!) : null, title: const Text('Pay with GCash'), subtitle: Text(_payment['configured'] == true ? 'Transfer to the shop account and submit your reference.' : 'GCash payment is not set up by the shop yet.')),
    if (_paymentMethod == 'gcash') ...[
      const Divider(),
      if (_payment['configured'] == true) ...[
        Text('GCash account: ${_payment['account_name'] ?? ''}', style: const TextStyle(fontWeight: FontWeight.bold)),
        SelectableText('Number: ${_payment['number'] ?? ''}'),
        Text('Transfer exactly ${money(_estimate)}. The shop will verify your reference before processing the order.', style: const TextStyle(color: AppColors.muted)),
        if (_qrUri() != null)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 10),
            child: Center(
              child: Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12), border: Border.all(color: AppColors.line)),
                child: Image.network(
                  _qrUri()!.toString(),
                  width: 180,
                  height: 180,
                  fit: BoxFit.contain,
                  errorBuilder: (_, __, ___) => const SizedBox(width: 180, height: 100, child: Center(child: Text('GCash QR image is unavailable.'))),
                ),
              ),
            ),
          ),
        OutlinedButton.icon(onPressed: _openGcashApp, icon: const Icon(Icons.open_in_new), label: const Text('Open GCash')),
        const SizedBox(height: 8),
        TextField(controller: _gcashReference, textCapitalization: TextCapitalization.characters, decoration: const InputDecoration(labelText: 'GCash reference number', hintText: 'Enter after you complete the transfer')),
      ] else const ErrorBanner('Ask the shop administrator to configure the GCash account first.'),
    ],
  ])));
  Widget _trackScreen() => ListView(padding: const EdgeInsets.fromLTRB(16, 8, 16, 28), children: [
    Row(children: [Expanded(child: Text('My service tickets', style: Theme.of(context).textTheme.headlineSmall?.copyWith(color: AppColors.cream, fontWeight: FontWeight.w800))), IconButton(tooltip: 'Refresh tickets', onPressed: () => _load(showLoading: false), color: AppColors.cream, icon: const Icon(Icons.refresh))]),
    const Text('Follow your laundry status, payment review, and active delivery location.', style: TextStyle(color: Color(0xffd6c9d8))),
    if (_sharingLocation) const Padding(padding: EdgeInsets.only(top: 8), child: Text('Your phone is sharing location updates for an active delivery.', style: TextStyle(color: Color(0xffe5c7eb))),),
    if (_error != null) ...[const SizedBox(height: 12), ErrorBanner(_error!)],
    const SizedBox(height: 12),
    if (_tickets.isEmpty) const Card(child: Padding(padding: EdgeInsets.all(18), child: Text('You have no service tickets yet. Book a service to get started.'))),
    ..._tickets.map(_ticketCard),
  ]);

  Widget _ticketCard(Map<String, dynamic> ticket) {
    final lines = (ticket['services'] as List? ?? []).map((line) {
      final item = Map<String, dynamic>.from(line);
      return '${item['service_name']} (${item['quantity']} ${item['unit']})';
    }).join(', ');
    final status = (ticket['status']?.toString() ?? 'pending').replaceAll('_', ' ');
    final delivery = ticket['order_type'] == 'delivery_request';
    final tracking = ticket['tracking'] is Map ? Map<String, dynamic>.from(ticket['tracking']) : <String, dynamic>{};
    final driverLocation = tracking['location'] is Map ? Map<String, dynamic>.from(tracking['location']) : null;
    final driverLat = driverLocation == null ? null : number(driverLocation['latitude'], double.nan);
    final driverLon = driverLocation == null ? null : number(driverLocation['longitude'], double.nan);
    final paymentStatus = ticket['gcash_payment_status']?.toString();
    final shared = tracking['customer_location_shared'] == true;
    final id = int.tryParse(ticket['id']?.toString() ?? '');
    return Card(margin: const EdgeInsets.only(bottom: 12), child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Row(crossAxisAlignment: CrossAxisAlignment.start, children: [Expanded(child: Text(ticket['order_number']?.toString() ?? 'Ticket', style: const TextStyle(fontWeight: FontWeight.w900, color: AppColors.plum, fontSize: 16))), Chip(label: Text(status, style: const TextStyle(fontWeight: FontWeight.bold)), backgroundColor: const Color(0xfff1e9f2))]),
      Text(prettyDate(ticket['created_at']), style: const TextStyle(color: AppColors.muted, fontSize: 12)),
      if (lines.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 8), child: Text(lines)),
      if (ticket['expected_pickup'] != null) Padding(padding: const EdgeInsets.only(top: 6), child: Text('Scheduled ${delivery ? 'delivery' : 'pickup'}: ${prettyDate(ticket['expected_pickup'])}')),
      if ((ticket['delivery_address']?.toString() ?? '').isNotEmpty) Padding(padding: const EdgeInsets.only(top: 4), child: Text('Delivery address: ${ticket['delivery_address']}')),
      const SizedBox(height: 8),
      Text('Estimated total: ${money(ticket['total'])}', style: const TextStyle(fontWeight: FontWeight.bold)),
      Text('Paid: ${money(ticket['amount_paid'])}    Balance due: ${money(ticket['balance_due'])}'),
      if (ticket['payment_method'] == 'gcash_pending') ...[
        const SizedBox(height: 10),
        Container(padding: const EdgeInsets.all(12), decoration: BoxDecoration(color: const Color(0xfff4edf5), borderRadius: BorderRadius.circular(12)), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('GCash payment: ${paymentStatus == 'verified' ? 'Verified' : paymentStatus == 'rejected' ? 'Needs attention' : 'Waiting for shop verification'}', style: const TextStyle(fontWeight: FontWeight.bold, color: AppColors.plum)),
          if (ticket['gcash_reference_number'] != null) Text('Reference: ${ticket['gcash_reference_number']}'),
          if ((ticket['gcash_review_note']?.toString() ?? '').isNotEmpty) Text(ticket['gcash_review_note'].toString(), style: const TextStyle(color: AppColors.error)),
          if (paymentStatus == 'rejected' && id != null) Align(alignment: Alignment.centerLeft, child: TextButton.icon(onPressed: _sending ? null : () => _retryGcash(ticket), icon: const Icon(Icons.refresh), label: const Text('Submit a new reference'))),
        ])),
      ],
      if (delivery && ticket['status'] == 'out_for_delivery') ...[
        const SizedBox(height: 12),
        Row(children: [const Icon(Icons.local_shipping_outlined, color: AppColors.purple), const SizedBox(width: 8), const Expanded(child: Text('Delivery tracking', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16))), if (tracking['active'] == true) const Chip(label: Text('LIVE'))]),
        if (driverLocation != null && driverLat != null && driverLon != null && driverLat.isFinite && driverLon.isFinite) ...[
          const SizedBox(height: 8),
          LocationMapCard(latitude: driverLat, longitude: driverLon, label: 'Driver'),
          const SizedBox(height: 6),
          Text(driverLocation['stale'] == true ? 'Driver location may be out of date.' : 'Last location update: ${prettyDate(driverLocation['updated_at'])}', style: const TextStyle(color: AppColors.muted, fontSize: 12)),
        ] else const Padding(padding: EdgeInsets.symmetric(vertical: 6), child: Text('The driver location will appear here after the shop assigns a driver and location sharing starts.', style: TextStyle(color: AppColors.muted))),
        if (tracking['active'] == true) ...[
          const SizedBox(height: 8),
          FilledButton.tonalIcon(onPressed: _trackingBusy ? null : () => _setLocationSharing(ticket, share: !shared), icon: Icon(shared ? Icons.location_disabled : Icons.my_location), label: Text(_trackingBusy ? 'Updating...' : shared ? 'Stop sharing my location' : 'Share my live location with driver')),
          if (shared) Padding(padding: const EdgeInsets.only(top: 6), child: Text('You are sharing your location. Last update: ${prettyDate(tracking['customer_location_updated_at'])}', style: const TextStyle(color: AppColors.muted, fontSize: 12))),
        ],
      ],
    ])));
  }
}






