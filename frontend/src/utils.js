import * as pako from 'pako';

export function isSafari() {
  const isChrome = !!window['chrome'] && !!window['chrome'].webstore;
    return !(Object.prototype.toString.call(window['HTMLElement']).indexOf('Constructor') <= 0 && !(!isChrome && window['webkitAudioContext'] !== undefined));
}

export function isIE11() {
  return !!window['MSInputMethodContext'] && !!document['documentMode'];
}

export function copyToClipboardEnabled() {
  let enabled = true;

  if (isSafari()) {
    console.log('safari');
    let nAgt = navigator.userAgent;
    let verOffset = nAgt.indexOf("Version");
    let fullVersion = nAgt.substring(verOffset + 8);
    let ix;
    if ((ix = fullVersion.indexOf(";")) !== -1) {
      fullVersion = fullVersion.substring(0, ix);
    }
    if ((ix = fullVersion.indexOf(" ")) !== -1) {
      fullVersion = fullVersion.substring(0, ix);
    }
    let majorVersion = parseInt('' + fullVersion, 10);
    enabled = majorVersion < 10;
  }
  return enabled;
}

export async function copyToClipboard(text) {
  let copied = false;

  try {
      await navigator.clipboard.writeText(text);
      copied = true;
    }catch(e) {
      let textArea = document.createElement("textarea");
      textArea.style.position = 'fixed';
      textArea.style.top = -1000 + 'px';
      textArea.value = text;
      document.body.appendChild(textArea);
      textArea.select();
      try {
        copied = document.execCommand('copy');
      } catch (err) {
        console.log('Copy to clipboard operation failed');
      }
      document.body.removeChild(textArea);
    }
  return copied;
}

export function buildQuerystring(data, prefix = "") {
  let str = [], p;
  for (p in data) {
    if (data.hasOwnProperty(p)) {
      const hasContent = typeof data[p] !== 'undefined' && data[p] !== '' && data[p] !== null;
      if (hasContent) {
        let k = prefix ? prefix + "[" + p + "]" : p, v = data[p];
        str.push((v !== null && typeof v === "object") ?
          buildQuerystring(v, k) :
          encodeURIComponent(k) + "=" + encodeURIComponent(v));
      }
    }
  }
  return str.join("&");
}

export function buildSearchParamsHash(searchParams)
{
  if (!searchParams) {
    return '';
  }

  const result = pako.deflate(JSON.stringify(searchParams), { to: 'string', level: 9 });
  return btoa(result);
}

export function replaceCurrentUrl(queryString, searchParams) {
  if (window.history.replaceState) {
    const hash = buildSearchParamsHash(searchParams);
    var url =  `${getPageUrlWithoutQuerystring()}?${queryString}#${hash}`;

    // Acts like window.location.hash = newHash but doesn't clutter the history
    // See http://stackoverflow.com/a/23924886/863119
    window.history.replaceState(undefined, undefined, url);
  }
}

export function getCurrentHash() {
  return window.location.hash ? window.location.hash.substring(1) : '';
}

export function getSearchParamsFromHash() {
  let rawHash = getCurrentHash();


  if (!rawHash) {
    return {};
  }

  try {
    const decompressedHash = pako.inflate(atob(rawHash), { to: 'string', level: 9 });
    return JSON.parse(decompressedHash);
  } catch (e) {
    return {};
  }
}

export function getQueryString() {
    let pl = /\+/g,  // Regex for replacing addition symbol with a space
    search = /([^&=]+)=?([^&]*)/g,
    decode = (s) => decodeURIComponent(s.replace(pl, " ")),
    query = window.location.search.substring(1);

  const urlParams = {};

  while (search.exec(query)) {
    const match = search.exec(query);
    const key = decode(match[1]);
    const value = decode(match[2])
    const keyMatch = /^(.+?)\[(.+?)\]$/.exec(key);
    if (keyMatch) {
      urlParams[keyMatch[1]] = {
        ...(urlParams[keyMatch[1]] || {}),
        [keyMatch[2]]: value
      }
    } else {
      urlParams[key] = value;
    }
  }

  return urlParams;
}

export function getPageUrlWithoutQuerystring() {
  return window.location.protocol + "//" + window.location.host + window.location.pathname;
}


export function getPageUrl() {
  return window.location.href;
}

export function openUrlInNewTab(url) {
  window.open(url, '_blank');
}
