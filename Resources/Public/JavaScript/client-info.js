/**
 * Collects environment details of the browser. Only these keys are accepted
 * by the server (ClientInfoSanitizer); cookies and storage are never read.
 *
 * @param {string[]|undefined} enabled keys the admin allows, all when undefined
 */
export function collectClientInfo(enabled) {
  const info = {
    url: window.location.href,
    title: document.title,
    userAgent: navigator.userAgent,
    language: navigator.language,
    languages: Array.from(navigator.languages || []),
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
    screen: {
      width: window.screen.width,
      height: window.screen.height,
      colorDepth: window.screen.colorDepth,
    },
    viewport: {
      width: window.innerWidth,
      height: window.innerHeight,
    },
    devicePixelRatio: window.devicePixelRatio,
    colorScheme: matchesMedia('(prefers-color-scheme: dark)') ? 'dark' : matchesMedia('(prefers-color-scheme: light)') ? 'light' : 'no-preference',
    reducedMotion: matchesMedia('(prefers-reduced-motion: reduce)'),
    online: navigator.onLine,
    timestamp: new Date().toISOString(),
  };

  if (navigator.userAgentData) {
    info.userAgentData = {
      brands: navigator.userAgentData.brands.map(({ brand, version }) => ({ brand, version })),
      platform: navigator.userAgentData.platform,
      mobile: navigator.userAgentData.mobile,
    };
  }

  if (Array.isArray(enabled)) {
    return Object.fromEntries(Object.entries(info).filter(([key]) => enabled.includes(key)));
  }
  return info;
}

function matchesMedia(query) {
  return typeof window.matchMedia === 'function' && window.matchMedia(query).matches;
}
