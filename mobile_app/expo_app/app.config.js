module.exports = ({ config }) => {
  const driver = process.env.EXPO_PUBLIC_APP_VARIANT === 'driver';
  const output = process.env.EXPO_WEB_OUTPUT || (process.env.NODE_ENV === 'development' ? 'single' : config.web?.output || 'static');
  const plugins = (config.plugins || []).filter((plugin) => {
    const name = Array.isArray(plugin) ? plugin[0] : plugin;
    return name !== 'expo-router' && (driver || name !== 'expo-location') && !(driver && name === 'expo-notifications');
  });
  plugins.push(['expo-router', { root: driver ? './src/driver-app' : './src/app' }]);
  if (!driver && !plugins.some((plugin) => (Array.isArray(plugin) ? plugin[0] : plugin) === 'expo-sharing')) plugins.push('expo-sharing');
  return {
    ...config,
    name: driver ? 'Pia Laundry Driver' : config.name,
    orientation: driver ? 'default' : config.orientation,
    // Keep both variants linked to the existing EAS project; the driver APK
    // remains a separate installable app through its own Android package ID.
    slug: config.slug,
    scheme: driver ? 'piaslaundrydriver' : config.scheme,
    ios: { ...config.ios, bundleIdentifier: driver ? 'com.piaslaundry.driver' : config.ios?.bundleIdentifier },
    android: { ...config.android, package: driver ? 'com.piaslaundry.driver' : config.android?.package, versionCode: driver ? 1 : config.android?.versionCode, ...(driver ? {} : { googleServicesFile: './google-services.json', permissions: (config.android?.permissions || []).filter((permission) => !/LOCATION|FOREGROUND_SERVICE/i.test(permission)) }) },
    plugins,
    web: { ...config.web, output },
  };
};