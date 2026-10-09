import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';

class AppColors {
  static const ink = Color(0xff18131a);
  static const plum = Color(0xff54245f);
  static const purple = Color(0xff754583);
  static const lilac = Color(0xff8e5a95);
  static const cream = Color(0xfffffaf2);
  static const palePlum = Color(0xfff3eaf4);
  static const copper = Color(0xffbd7841);
  static const muted = Color(0xff807682);
  static const line = Color(0xffe4d8e6);
  static const error = Color(0xffa63d4d);
}

String money(dynamic value) {
  final amount = double.tryParse(value?.toString() ?? '') ?? 0;
  return '\u20B1${amount.toStringAsFixed(2)}';
}

double number(dynamic value, [double fallback = 0]) => double.tryParse(value?.toString() ?? '') ?? fallback;

String prettyDate(dynamic value) {
  if (value == null || value.toString().isEmpty) return 'Not scheduled';
  final parsed = DateTime.tryParse(value.toString());
  if (parsed == null) return value.toString();
  final local = parsed.toLocal();
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  final hour = local.hour % 12 == 0 ? 12 : local.hour % 12;
  final minute = local.minute.toString().padLeft(2, '0');
  return '${months[local.month - 1]} ${local.day}, ${local.year} at $hour:$minute ${local.hour >= 12 ? 'PM' : 'AM'}';
}

class ShopBrandHeader extends StatelessWidget {
  const ShopBrandHeader({super.key, this.compact = false});
  final bool compact;

  @override
  Widget build(BuildContext context) => Container(
        padding: EdgeInsets.all(compact ? 16 : 22),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(24),
          gradient: const LinearGradient(begin: Alignment.topLeft, end: Alignment.bottomRight, colors: [AppColors.plum, AppColors.purple, AppColors.lilac]),
        ),
        child: Row(children: [
          Container(
            width: compact ? 48 : 62,
            height: compact ? 48 : 62,
            decoration: BoxDecoration(color: AppColors.cream, borderRadius: BorderRadius.circular(18)),
            child: const Icon(Icons.local_laundry_service, color: AppColors.plum, size: 32),
          ),
          const SizedBox(width: 14),
          const Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text("Pia's Laundry Shop", style: TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.w800)),
            SizedBox(height: 5),
            Text('Blk. 2, Brgy. San Jose, Tarlac City', style: TextStyle(color: Colors.white)),
            Text('Contact: 0918-967-9623', style: TextStyle(color: Colors.white)),
          ])),
          if (!compact) const Padding(padding: EdgeInsets.only(left: 8), child: Icon(Icons.local_laundry_service, color: Colors.white, size: 38)),
        ]),
      );
}

class LocationMapCard extends StatefulWidget {
  const LocationMapCard({super.key, required this.latitude, required this.longitude, this.label = 'Location', this.height = 220});
  final double latitude;
  final double longitude;
  final String label;
  final double height;

  @override
  State<LocationMapCard> createState() => _LocationMapCardState();
}

class _LocationMapCardState extends State<LocationMapCard> {
  final _mapController = MapController();

  @override
  void didUpdateWidget(covariant LocationMapCard oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.latitude != widget.latitude || oldWidget.longitude != widget.longitude) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) _mapController.move(LatLng(widget.latitude, widget.longitude), _mapController.camera.zoom);
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final point = LatLng(widget.latitude, widget.longitude);
    return ClipRRect(
      borderRadius: BorderRadius.circular(18),
      child: SizedBox(
        height: widget.height,
        child: Stack(children: [
          FlutterMap(
            mapController: _mapController,
            options: MapOptions(initialCenter: point, initialZoom: 15),
            children: [
              TileLayer(urlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', userAgentPackageName: 'com.piaslaundry.customer'),
              MarkerLayer(markers: [Marker(point: point, width: 54, height: 54, child: const Icon(Icons.location_on, color: AppColors.plum, size: 46))]),
            ],
          ),
          Positioned(left: 8, bottom: 7, child: DecoratedBox(decoration: BoxDecoration(color: AppColors.cream.withValues(alpha: .92), borderRadius: BorderRadius.circular(6)), child: const Padding(padding: EdgeInsets.symmetric(horizontal: 7, vertical: 4), child: Text('© OpenStreetMap contributors', style: TextStyle(fontSize: 10, color: AppColors.ink))))),
          Positioned(right: 8, top: 8, child: Chip(visualDensity: VisualDensity.compact, label: Text(widget.label), backgroundColor: AppColors.cream)),
        ]),
      ),
    );
  }
}
class ErrorBanner extends StatelessWidget {
  const ErrorBanner(this.message, {super.key});
  final String message;
  @override
  Widget build(BuildContext context) => Container(
        width: double.infinity,
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(color: const Color(0xffffe8e9), borderRadius: BorderRadius.circular(14)),
        child: Text(message, style: const TextStyle(color: AppColors.error)),
      );
}

