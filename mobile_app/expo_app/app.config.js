module.exports = ({ config }) => {
  const output =
    process.env.EXPO_WEB_OUTPUT ||
    (process.env.NODE_ENV === 'development' ? 'single' : config.web?.output || 'static');

  return {
    ...config,
    web: {
      ...config.web,
      output,
    },
  };
};
