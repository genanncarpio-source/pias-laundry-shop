import 'dart:convert';

import 'package:flutter/foundation.dart';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;

const configuredApiBaseUrl = String.fromEnvironment('API_BASE_URL');
const emulatorApiBaseUrl = 'http://10.0.2.2/laundry-pias/api';
const localBrowserApiBaseUrl = 'http://localhost/laundry-pias/api';

class ShopApi {
  ShopApi._();
  static const _storage = FlutterSecureStorage();
  static const _customerKey = 'customer_api_token';
  static const _driverKey = 'driver_api_token';

  static String get baseUrl {
    final configured = configuredApiBaseUrl.trim();
    final value = configured.isNotEmpty ? configured : (kIsWeb ? localBrowserApiBaseUrl : emulatorApiBaseUrl);
    return value.replaceFirst(RegExp(r'/+$'), '');
  }
  static String get siteRoot => baseUrl.replaceFirst(RegExp(r'/api$'), '');

  static Future<String?> customerToken() => _storage.read(key: _customerKey);
  static Future<String?> driverToken() => _storage.read(key: _driverKey);
  static Future<void> saveCustomerToken(String value) => _storage.write(key: _customerKey, value: value);
  static Future<void> saveDriverToken(String value) => _storage.write(key: _driverKey, value: value);
  static Future<void> clearCustomerToken() => _storage.delete(key: _customerKey);
  static Future<void> clearDriverToken() => _storage.delete(key: _driverKey);

  static Future<Map<String, dynamic>> request(
    String endpoint, {
    String method = 'GET',
    Map<String, dynamic>? body,
    bool authenticated = false,
    bool driver = false,
  }) async {
    final headers = <String, String>{'Accept': 'application/json'};
    if (body != null) headers['Content-Type'] = 'application/json';
    if (authenticated) {
      final value = driver ? await driverToken() : await customerToken();
      if (value != null && value.isNotEmpty) headers['Authorization'] = 'Bearer $value';
    }
    final uri = Uri.parse('$baseUrl/$endpoint');
    late http.Response response;
    try {
      if (method == 'POST') {
        response = await http
            .post(uri, headers: headers, body: jsonEncode(body ?? const {}))
            .timeout(const Duration(seconds: 20));
      } else {
        response = await http.get(uri, headers: headers).timeout(const Duration(seconds: 20));
      }
    } catch (_) {
      throw Exception('Could not connect to the shop. Check that XAMPP is running and the API address is correct.');
    }
    Map<String, dynamic> data;
    try {
      final decoded = jsonDecode(response.body);
      if (decoded is! Map<String, dynamic>) throw const FormatException();
      data = decoded;
    } catch (_) {
      throw Exception('The shop server returned an unreadable response. Check the API URL and PHP server.');
    }
    if (response.statusCode < 200 || response.statusCode >= 300 || data['success'] != true) {
      if (response.statusCode == 401 && authenticated) {
        if (driver) {
          await clearDriverToken();
        } else {
          await clearCustomerToken();
        }
      }
      throw Exception(data['error']?.toString() ?? 'The request could not be completed.');
    }
    return data;
  }
}


