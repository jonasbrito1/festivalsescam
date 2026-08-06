import 'dotenv/config';
import type { CapacitorConfig } from '@capacitor/cli';

const appUrl = process.env.MOBILE_PWA_URL || 'http://localhost:3001/FESTIVAL_CALOUROS2/mobile_pwa/';

const config: CapacitorConfig = {
  appId: 'br.com.sesc.festivaljurados',
  appName: 'Festival Jurados',
  webDir: 'web',
  bundledWebRuntime: false,
  server: {
    url: appUrl,
    cleartext: appUrl.startsWith('http://')
  }
};

export default config;
