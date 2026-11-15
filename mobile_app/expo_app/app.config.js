module.exports = ({ config }) => {
  const driver = process.env.EXPO_PUBLIC_APP_VARIANT === 'driver';
  const output = process.env.EXPO_WEB_OUTPUT || (process.env.NODE_ENV === 'development' ? 'single' : config.web?.output || 'static');
  const plugins = (config.plugins || []).filter((plugin) => plugin !== 'expo-router' && !(Array.isArray(plugin) && plugin[0] === 'expo-router'));
  plugins.push(['expo-router', { root: driver ? './src/driver-app' : './src/app' }]);
  return {
    ...config,
    name: driver ? 'Pia Laundry Driver' : config.name,
    slug: driver ? 'pias-laundry-driver' : config.slug,
    scheme: driver ? 'piaslaundrydriver' : config.scheme,
    ios: { ...config.ios, bundleIdentifier: driver ? 'com.piaslaundry.driver' : config.ios?.bundleIdentifier },
    android: { ...config.android, package: driver ? 'com.piaslaundry.driver' : config.android?.package, versionCode: driver ? 1 : config.android?.versionCode },
    plugins,
    web: { ...config.web, output },
  };
};