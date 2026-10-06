import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;

// Emulator default. On a physical phone, enter the PC's LAN URL on the sign-in screen.
const defaultApiBaseUrl = 'http://10.0.2.2/laundry-pos/api';
const currencySymbol = '\u20B1';

void main() => runApp(const LaundryCustomerApp());

class LaundryCustomerApp extends StatelessWidget {
  const LaundryCustomerApp({super.key});

  @override
  Widget build(BuildContext context) => MaterialApp(
        title: "Pia's Laundry Shop",
        debugShowCheckedModeBanner: false,
        theme: ThemeData(
          colorScheme: ColorScheme.fromSeed(seedColor: const Color(0xffcf2c73)),
          useMaterial3: true,
          inputDecorationTheme: const InputDecorationTheme(
            border: OutlineInputBorder(),
          ),
        ),
        home: const SessionGate(),
      );
}

class Api {
  static const _storage = FlutterSecureStorage();
  static const _tokenKey = 'customer_api_token';
  static const _apiUrlKey = 'customer_api_url';

  static Future<String> baseUrl() async => (await _storage.read(key: _apiUrlKey)) ?? defaultApiBaseUrl;
  static Future<void> saveBaseUrl(String url) async {
    final parsed = Uri.tryParse(url.trim());
    if (parsed == null || !parsed.hasAuthority || !['http', 'https'].contains(parsed.scheme)) {
      throw Exception('Enter a valid API URL starting with http:// or https://');
    }
    await _storage.write(key: _apiUrlKey, value: url.trim().replaceFirst(RegExp(r'/+$'), ''));
  }
  static Future<String?> token() => _storage.read(key: _tokenKey);
  static Future<void> saveToken(String token) => _storage.write(key: _tokenKey, value: token);
  static Future<void> clearToken() => _storage.delete(key: _tokenKey);

  static Future<Map<String, dynamic>> request(
    String endpoint, {
    String method = 'GET',
    Map<String, dynamic>? body,
    bool authenticated = false,
  }) async {
    final headers = <String, String>{'Accept': 'application/json'};
    if (body != null) headers['Content-Type'] = 'application/json';
    if (authenticated) {
      final savedToken = await token();
      if (savedToken != null) headers['Authorization'] = 'Bearer $savedToken';
    }
    final uri = Uri.parse('${await baseUrl()}/$endpoint');
    late http.Response response;
    try {
      if (method == 'POST') {
        response = await http.post(uri, headers: headers, body: jsonEncode(body ?? {})).timeout(const Duration(seconds: 20));
      } else {
        response = await http.get(uri, headers: headers).timeout(const Duration(seconds: 20));
      }
    } catch (_) {
      throw Exception('Could not connect to the shop. Check the API address and network connection.');
    }
    Map<String, dynamic> data;
    try {
      data = jsonDecode(response.body) as Map<String, dynamic>;
    } catch (_) {
      throw Exception('The shop server returned an unreadable response.');
    }
    if (response.statusCode < 200 || response.statusCode >= 300 || data['success'] != true) {
      if (response.statusCode == 401 && authenticated) await clearToken();
      throw Exception(data['error']?.toString() ?? 'The request could not be completed.');
    }
    return data;
  }
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
  void initState() {
    super.initState();
    _restoreSession();
  }

  Future<void> _restoreSession() async {
    if (await Api.token() != null) {
      try {
        final data = await Api.request('me.php', authenticated: true);
        _customer = Map<String, dynamic>.from(data['customer']);
      } catch (_) {
        await Api.clearToken();
      }
    }
    if (mounted) setState(() => _checking = false);
  }

  void _signedIn(Map<String, dynamic> customer) => setState(() => _customer = customer);
  Future<void> _signedOut() async {
    try {
      await Api.request('logout.php', method: 'POST', body: {}, authenticated: true);
    } catch (_) {}
    await Api.clearToken();
    if (mounted) setState(() => _customer = null);
  }

  @override
  Widget build(BuildContext context) {
    if (_checking) return const Scaffold(body: Center(child: CircularProgressIndicator()));
    if (_customer == null) return AuthScreen(onSignedIn: _signedIn);
    return CustomerHome(customer: _customer!, onSignOut: _signedOut);
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
  final _apiUrl = TextEditingController(text: defaultApiBaseUrl);
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _phone = TextEditingController();
  final _password = TextEditingController();
  bool _registering = false;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    Api.baseUrl().then((url) { if (mounted) _apiUrl.text = url; });
  }

  @override
  void dispose() {
    _name.dispose(); _email.dispose(); _phone.dispose(); _password.dispose(); _apiUrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() { _busy = true; _error = null; });
    try {
      await Api.saveBaseUrl(_apiUrl.text);
      final data = await Api.request(
        _registering ? 'register.php' : 'login.php',
        method: 'POST',
        body: {
          if (_registering) 'name': _name.text.trim(),
          if (_registering) 'phone': _phone.text.trim(),
          'email': _email.text.trim(),
          'password': _password.text,
        },
      );
      await Api.saveToken(data['token'].toString());
      widget.onSignedIn(Map<String, dynamic>.from(data['customer']));
    } catch (error) {
      if (mounted) {
        setState(() => _error = error.toString().replaceFirst('Exception: ', ''));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        body: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(24),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 440),
                child: Card(
                  child: Padding(
                    padding: const EdgeInsets.all(24),
                    child: Form(
                      key: _formKey,
                      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                        const Icon(Icons.local_laundry_service, size: 52),
                        const SizedBox(height: 12),
                        Text("Pia's Laundry Shop", textAlign: TextAlign.center, style: Theme.of(context).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.bold)),
                        const SizedBox(height: 6),
                        Text(_registering ? 'Create a customer account to request services.' : 'Sign in to request services and track your laundry.', textAlign: TextAlign.center),
                        const SizedBox(height: 20),
                        TextFormField(controller: _apiUrl, decoration: const InputDecoration(labelText: 'Shop API address', helperText: 'Phone: http://COMPUTER-LAN-IP/laundry-pos/api | Emulator: http://10.0.2.2/laundry-pos/api'), keyboardType: TextInputType.url, validator: (v) {
                          final uri = Uri.tryParse((v ?? '').trim());
                          return uri != null && uri.hasAuthority && ['http', 'https'].contains(uri.scheme) ? null : 'Enter a valid URL starting with http:// or https://';
                        }),
                        const SizedBox(height: 12),
                        SegmentedButton<bool>(
                          segments: const [ButtonSegment(value: false, label: Text('Sign in')), ButtonSegment(value: true, label: Text('Register'))],
                          selected: {_registering},
                          onSelectionChanged: _busy ? null : (value) => setState(() { _registering = value.first; _error = null; }),
                        ),
                        const SizedBox(height: 20),
                        if (_error != null) ...[
                          Text(_error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
                          const SizedBox(height: 12),
                        ],
                        if (_registering) ...[
                          TextFormField(controller: _name, decoration: const InputDecoration(labelText: 'Full name'), textCapitalization: TextCapitalization.words, validator: (v) => (v == null || v.trim().isEmpty) ? 'Enter your name.' : null),
                          const SizedBox(height: 12),
                        ],
                        TextFormField(controller: _email, decoration: const InputDecoration(labelText: 'Email address'), keyboardType: TextInputType.emailAddress, autofillHints: const [AutofillHints.email], validator: (v) => (v == null || !v.contains('@')) ? 'Enter a valid email address.' : null),
                        if (_registering) ...[
                          const SizedBox(height: 12),
                          TextFormField(controller: _phone, decoration: const InputDecoration(labelText: 'Phone number'), keyboardType: TextInputType.phone, validator: (v) => (v == null || v.trim().isEmpty) ? 'Enter your phone number.' : null),
                        ],
                        const SizedBox(height: 12),
                        TextFormField(controller: _password, decoration: InputDecoration(labelText: 'Password', helperText: _registering ? 'At least 10 characters' : null), obscureText: true, validator: (v) => (v == null || v.isEmpty || (_registering && v.length < 10)) ? (_registering ? 'Use at least 10 characters.' : 'Enter your password.') : null),
                        const SizedBox(height: 20),
                        FilledButton(onPressed: _busy ? null : _submit, child: _busy ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2)) : Text(_registering ? 'Create account' : 'Sign in')),
                      ]),
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
      );
}

class CustomerHome extends StatefulWidget {
  const CustomerHome({required this.customer, required this.onSignOut, super.key});
  final Map<String, dynamic> customer;
  final Future<void> Function() onSignOut;
  @override
  State<CustomerHome> createState() => _CustomerHomeState();
}

class _CustomerHomeState extends State<CustomerHome> {
  List<Map<String, dynamic>> _services = [];
  List<Map<String, dynamic>> _tickets = [];
  final Map<int, TextEditingController> _quantities = {};
  final _notes = TextEditingController();
  DateTime? _pickup;
  bool _loading = true;
  bool _sending = false;
  String? _error;

  static const _serviceSections = ['Wash', 'Dry', 'Full Service', 'Add-ons', 'Other services'];

  String _sectionFor(Map<String, dynamic> service) {
    final name = (service['name'] ?? '').toString().toLowerCase();
    final category = (service['category'] ?? '').toString().toLowerCase();
    if (category.contains('add') || name.contains('detergent') || name.contains('fabcon')) return 'Add-ons';
    if ((name.contains('wash') && (name.contains('fold') || name.contains('full'))) || category.contains('wash & fold') || category.contains('full service')) return 'Full Service';
    if (name == 'wash' || category == 'wash') return 'Wash';
    if (name == 'dry' || name.contains('dry clean') || category == 'dry') return 'Dry';
    if (name == 'fold') return 'Add-ons';
    return 'Other services';
  }

  IconData _sectionIcon(String section) => switch (section) {
        'Wash' => Icons.local_laundry_service,
        'Dry' => Icons.air,
        'Full Service' => Icons.checkroom,
        'Add-ons' => Icons.add_circle_outline,
        _ => Icons.cleaning_services,
      };

  @override
  void initState() { super.initState(); _load(); }

  @override
  void dispose() { for (final c in _quantities.values) { c.dispose(); } _notes.dispose(); super.dispose(); }

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; });
    try {
      final result = await Future.wait([
        Api.request('services.php'),
        Api.request('my_tickets.php', authenticated: true),
      ]);
      _services = (result[0]['services'] as List).map((e) => Map<String, dynamic>.from(e)).toList();
      for (final service in _services) { _quantities.putIfAbsent(int.parse(service['id'].toString()), TextEditingController.new); }
      _tickets = (result[1]['tickets'] as List).map((e) => Map<String, dynamic>.from(e)).toList();
    } catch (error) {
      _error = error.toString().replaceFirst('Exception: ', '');
    } finally { if (mounted) setState(() => _loading = false); }
  }

  double get _estimate => _services.fold<double>(0, (sum, service) {
        final id = int.parse(service['id'].toString());
        final qty = double.tryParse(_quantities[id]?.text ?? '') ?? 0;
        final price = double.tryParse(service['price'].toString()) ?? 0;
        return sum + qty * price;
      });

  Future<void> _choosePickup() async {
    final day = await showDatePicker(context: context, initialDate: DateTime.now().add(const Duration(days: 1)), firstDate: DateTime.now(), lastDate: DateTime.now().add(const Duration(days: 365)));
    if (day == null || !mounted) return;
    final time = await showTimePicker(context: context, initialTime: const TimeOfDay(hour: 9, minute: 0));
    if (time != null && mounted) setState(() => _pickup = DateTime(day.year, day.month, day.day, time.hour, time.minute));
  }

  Future<void> _sendRequest() async {
    final chosen = <Map<String, dynamic>>[];
    for (final service in _services) {
      final id = int.parse(service['id'].toString());
      final qty = double.tryParse(_quantities[id]?.text ?? '') ?? 0;
      if (qty > 0) chosen.add({'service_id': id, 'quantity': qty});
    }
    if (chosen.isEmpty) return;
    setState(() { _sending = true; _error = null; });
    try {
      final result = await Api.request('tickets.php', method: 'POST', authenticated: true, body: {
        'services': chosen,
        'expected_pickup': _pickup?.toUtc().toIso8601String(),
        'notes': _notes.text.trim(),
      });
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Request submitted. Ticket ${result['ticket']['ticket_number']}')));
      for (final controller in _quantities.values) { controller.clear(); }
      _notes.clear();
      setState(() => _pickup = null);
      await _load();
    } catch (error) {
      if (mounted) {
        setState(() => _error = error.toString().replaceFirst('Exception: ', ''));
      }
    } finally { if (mounted) setState(() => _sending = false); }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(
          title: const Text("Pia's Laundry Shop"),
          actions: [IconButton(tooltip: 'Sign out', onPressed: widget.onSignOut, icon: const Icon(Icons.logout))],
        ),
        body: RefreshIndicator(
          onRefresh: _load,
          child: _loading
              ? ListView(children: const [SizedBox(height: 260), Center(child: CircularProgressIndicator())])
              : ListView(padding: const EdgeInsets.all(16), children: [
                  Text('Hello, ${widget.customer['name'] ?? 'Customer'}', style: Theme.of(context).textTheme.titleLarge),
                  const SizedBox(height: 12),
                  Card(
                    clipBehavior: Clip.antiAlias,
                    child: Container(
                      padding: const EdgeInsets.all(18),
                      decoration: const BoxDecoration(gradient: LinearGradient(colors: [Color(0xff54245f), Color(0xff80508c)])),
                      child: const Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text("Pia's Laundry Shop", style: TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.bold)),
                        SizedBox(height: 8),
                        Text('Blk. 2, Brgy. San Jose, Tarlac City', style: TextStyle(color: Colors.white)),
                        Text('Contact: 0918-967-9623', style: TextStyle(color: Colors.white)),
                      ]),
                    ),
                  ),
                  if (_error != null) ...[const SizedBox(height: 12), Text(_error!, style: TextStyle(color: Theme.of(context).colorScheme.error))],
                  const SizedBox(height: 16),
                  Row(children: [
                    Expanded(child: Text('Request a service', style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.bold))),
                    const Chip(avatar: Icon(Icons.shopping_bag_outlined, size: 18), label: Text('Pickup only')),
                  ]),
                  const Text('Pumili ng serbisyo at dami. Ang presyo ay mula sa shop menu.'),
                  if (_services.isEmpty)
                    const Card(child: Padding(padding: EdgeInsets.all(16), child: Text('No services are currently available.')))
                  else
                    ..._serviceSections.map((section) {
                      final items = _services.where((service) => _sectionFor(service) == section).toList();
                      if (items.isEmpty) return const SizedBox.shrink();
                      return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        const SizedBox(height: 14),
                        Row(children: [
                          Icon(_sectionIcon(section), color: const Color(0xff704080)),
                          const SizedBox(width: 8),
                          Text(section, style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.bold, color: const Color(0xff653476))),
                        ]),
                        const SizedBox(height: 6),
                        ...items.map(_serviceRow),
                      ]);
                    }),
                  const SizedBox(height: 16),
                  Card(
                    color: const Color(0xfffff8fc),
                    child: Padding(
                      padding: const EdgeInsets.all(16),
                      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                        Row(children: [
                          const Icon(Icons.storefront_outlined, color: Color(0xff704080)),
                          const SizedBox(width: 8),
                          Expanded(child: Text('Pickup schedule', style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.bold))),
                          const Text('PICKUP ONLY', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Color(0xff704080))),
                        ]),
                        const SizedBox(height: 4),
                        const Text('Walang delivery option sa ngayon. Kukumpirmahin ng shop ang pickup schedule.'),
                        const SizedBox(height: 12),
                        OutlinedButton.icon(
                          onPressed: _choosePickup,
                          icon: const Icon(Icons.calendar_month),
                          label: Text(_pickup == null
                              ? 'Pumili ng pickup date at oras (optional)'
                              : '${MaterialLocalizations.of(context).formatMediumDate(_pickup!)} • ${TimeOfDay.fromDateTime(_pickup!).format(context)}'),
                        ),
                        if (_pickup != null)
                          TextButton.icon(onPressed: () => setState(() => _pickup = null), icon: const Icon(Icons.close), label: const Text('Alisin ang schedule')),
                      ]),
                    ),
                  ),
                  const SizedBox(height: 12),
                  TextField(controller: _notes, maxLength: 255, maxLines: 2, decoration: const InputDecoration(labelText: 'Notes (optional)', hintText: 'Add any special instructions')),
                  Align(alignment: Alignment.centerRight, child: Text('Tantiyang halaga: $currencySymbol${_estimate.toStringAsFixed(2)}', style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.bold))),
                  const SizedBox(height: 8),
                  FilledButton.icon(
                    onPressed: _sending || _estimate <= 0 ? null : _sendRequest,
                    icon: _sending ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.send),
                    label: Text(_sending ? 'Ipinapadala…' : 'Mag-request ng pickup'),
                    style: FilledButton.styleFrom(backgroundColor: const Color(0xff704080), padding: const EdgeInsets.symmetric(vertical: 14)),
                  ),
                  const SizedBox(height: 6),
                  const Text('Estimate lamang ito. Kukumpirmahin ng shop ang serbisyo, presyo, at pickup schedule.', style: TextStyle(fontSize: 12)),
                  const SizedBox(height: 20),
                  Row(children: [Expanded(child: Text('My service tickets', style: Theme.of(context).textTheme.titleLarge)), IconButton(tooltip: 'Refresh tickets', onPressed: _load, icon: const Icon(Icons.refresh))]),
                  if (_tickets.isEmpty) const Card(child: Padding(padding: EdgeInsets.all(16), child: Text('You have no service tickets yet.'))),
                  ..._tickets.map(_ticketCard),
                  const SizedBox(height: 24),
                ]),
        ),
      );

  Widget _serviceRow(Map<String, dynamic> service) {
    final id = int.parse(service['id'].toString());
    final unit = service['unit']?.toString() ?? 'unit';
    final price = double.tryParse(service['price'].toString()) ?? 0;
    final description = service['description']?.toString();
    return Card(
      margin: const EdgeInsets.symmetric(vertical: 4),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
        child: Row(children: [
          const CircleAvatar(backgroundColor: Color(0xfff4eaf6), child: Icon(Icons.local_laundry_service, color: Color(0xff704080))),
          const SizedBox(width: 12),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(service['name']?.toString() ?? 'Service', style: const TextStyle(fontWeight: FontWeight.w600)),
            if (description != null && description.isNotEmpty) Text(description, style: Theme.of(context).textTheme.bodySmall),
            Text('$currencySymbol${price.toStringAsFixed(2)} / $unit', style: const TextStyle(color: Color(0xff704080), fontWeight: FontWeight.bold)),
          ])),
          SizedBox(
            width: 86,
            child: TextField(
              controller: _quantities[id],
              keyboardType: TextInputType.numberWithOptions(decimal: unit == 'kg'),
              textAlign: TextAlign.center,
              onChanged: (_) => setState(() {}),
              decoration: InputDecoration(labelText: unit, hintText: '0', isDense: true, contentPadding: const EdgeInsets.symmetric(horizontal: 8, vertical: 12)),
            ),
          ),
        ]),
      ),
    );
  }

  Widget _ticketCard(Map<String, dynamic> ticket) {
    final lines = (ticket['services'] as List? ?? []).map((line) => '${line['service_name']} (${line['quantity']} ${line['unit']})').join(', ');
    final date = ticket['created_at']?.toString() ?? '';
    final status = ticket['status']?.toString() ?? 'pending';
    return Card(child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Row(children: [Expanded(child: Text(ticket['order_number']?.toString() ?? 'Ticket', style: const TextStyle(fontWeight: FontWeight.bold))), Chip(label: Text(status.replaceAll('_', ' '))) ]),
      Text(date),
      if (lines.isNotEmpty) Padding(padding: const EdgeInsets.symmetric(vertical: 8), child: Text(lines)),
      Text('Estimated total: $currencySymbol${(double.tryParse(ticket['total'].toString()) ?? 0).toStringAsFixed(2)}'),
      Text('Balance due: $currencySymbol${(double.tryParse(ticket['balance_due'].toString()) ?? 0).toStringAsFixed(2)}'),
    ])));
  }
}
