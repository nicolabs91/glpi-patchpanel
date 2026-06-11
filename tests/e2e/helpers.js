const fs = require('fs');
const { chromium, firefox, webkit } = require('playwright');

async function launchBrowser() {
  const browserName = process.env.BROWSER || 'chromium';
  const browserType = { chromium, firefox, webkit }[browserName];
  if (!browserType) {
    throw new Error(`Unsupported BROWSER value: ${browserName}`);
  }

  const options = {
    headless: process.env.HEADLESS !== 'false',
  };
  const executablePath = process.env.PLAYWRIGHT_EXECUTABLE_PATH;
  const localChrome = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
  if (executablePath) {
    options.executablePath = executablePath;
  } else if (browserName === 'chromium' && fs.existsSync(localChrome)) {
    options.executablePath = localChrome;
  }
  return browserType.launch(options);
}

module.exports = { launchBrowser };
