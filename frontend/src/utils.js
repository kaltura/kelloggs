

export function isSafari() {
  const isChrome = !!window['chrome'] && !!window['chrome'].webstore;
  return Object.prototype.toString.call(window['HTMLElement']).indexOf('Constructor') > 0 || !isChrome && window['webkitAudioContext'] !== undefined;
}

export function isIE11() {
  return !!window['MSInputMethodContext'] && !!document['documentMode'];
}

export function copyToClipboardEnabled() {
  let enabled = true;

  if (isSafari()) {
    let nAgt = navigator.userAgent;
    let verOffset = nAgt.indexOf("Version");
    let fullVersion = nAgt.substring(verOffset + 8);
    let ix;
    if ((ix = fullVersion.indexOf(";")) != -1) {
      fullVersion = fullVersion.substring(0, ix);
    }
    if ((ix = fullVersion.indexOf(" ")) != -1) {
      fullVersion = fullVersion.substring(0, ix);
    }
    let majorVersion = parseInt('' + fullVersion, 10);
    enabled = majorVersion < 10;
  }
  return enabled;
}

export function copyElementToClipboard(el) {
  if (document.body['createTextRange']) {
    // IE
    let textRange = document.body['createTextRange']();
    textRange.moveToElementText(el);
    textRange.select();
    textRange.execCommand("Copy");
  }
  else if (window.getSelection && document.createRange) {
    // non-IE
    let editable = el.contentEditable; // Record contentEditable status of element
    let readOnly = el.readOnly; // Record readOnly status of element
    el.contentEditable = true; // iOS will only select text on non-form elements if contentEditable = true;
    el.readOnly = false; // iOS will not select in a read only form element
    let range = document.createRange();
    range.selectNodeContents(el);
    let sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range); // Does not work for Firefox if a textarea or input
    if (el.nodeName == "TEXTAREA" || el.nodeName == "INPUT")
      el.select(); // Firefox will only select a form element with select()
    if (el.setSelectionRange && navigator.userAgent.match(/ipad|ipod|iphone/i))
      el.setSelectionRange(0, 999999); // iOS only selects "form" elements with SelectionRange
    el.contentEditable = editable; // Restore previous contentEditable status
    el.readOnly = readOnly; // Restore previous readOnly status
    if (document.queryCommandSupported("copy")) {
      document.execCommand('copy');
    }
  }
}

export function copyToClipboard(text) {
  let copied = false;
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


export function getQueryString() {
  var match,
    pl = /\+/g,  // Regex for replacing addition symbol with a space
    search = /([^&=]+)=?([^&]*)/g,
    decode = function (s) {
      return decodeURIComponent(s.replace(pl, " "));
    },
    query = window.location.search.substring(1);

  const urlParams = {};
  while (match = search.exec(query)) {
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

export function getCurrentUrlWithoutQuerystring() {
  return window.location.protocol + "//" + window.location.host + window.location.pathname;
}


export function getCurrentUrl() {
  return window.location.href;
}

export function updateUrlQueryParams(queryParams) {
  if (window.history.pushState) {
    const queryParamsToken = buildQuerystring(queryParams);
    var url =  `${getCurrentUrlWithoutQuerystring()}?${queryParamsToken}`;
    window.history.pushState({path:url},'',url);
  }
}

export function openUrlInNewTab(url) {
  window.open(url, '_blank');
}
export function reloadUrl(queryParams) {
  const queryParamsToken = buildQuerystring(queryParams);
  window.location.search = `?${queryParamsToken}`;
}
