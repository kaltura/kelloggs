import React from "react";
import hoistNonReactStatics from 'hoist-non-react-statics';

const GlobalCommandsContext = React.createContext({});

function buildQuerystring(data, prefix = "") {
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


const getQueryString = () => {
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

function getCurrentUrlWithoutQuerystring() {
    return window.location.protocol + "//" + window.location.host + window.location.pathname;
}


function getCurrentUrl() {
    return window.location.href;
}

function replaceUrlQueryParameters(queryParams) {
  if (window.history.pushState) {
    const queryParamsToken = buildQuerystring(queryParams);
    var newurl =  `${getCurrentUrlWithoutQuerystring()}?${queryParamsToken}`;
    window.history.pushState({path:newurl},'',newurl);
  }
}
function reloadUrlWithQueryParameters(queryParams) {
  const queryParamsToken = buildQuerystring(queryParams);
  window.location.search = `?${queryParamsToken}`;
}

export default class GlobalCommands extends React.Component {

  state = {
    items: [],
  }


  updateItems = (items) => {
    this.setState({
      items
    })
  }

  _updateURL = (queryParams) => {
    const { config } = this.state;

    if (!config.isHosted) {
      queryParams = {
        ...queryParams,
        jwt: config.jwt,
        serviceUrl: config.serviceUrl
      }
    }

    replaceUrlQueryParameters(queryParams);
  };


  _setConfig = (config, initialParameters = null) => {
    this.setState({
      config,
      initialParameters
    })
  }

  render() {
    const { children } = this.props;
    const { items, config, initialParameters } = this.state;

    const context = {
      items,
      updateItems: this.updateItems,
      clearItems: () => this.updateItems([]),
      updateURL: this._updateURL,
      getQueryString: getQueryString,
      setConfig: this._setConfig,
      getInitialParameters: () => initialParameters,
      config,
      getCurrentUrl
    }

    return (
      <GlobalCommandsContext.Provider value={context}>
        {children}
      </GlobalCommandsContext.Provider>
    )
  }
}

export function withGlobalCommands(Component) {
  const Wrapper = React.forwardRef((props, ref) => {
    return (
      <GlobalCommandsContext.Consumer>
        {
          globalCommands => (<Component {...props} globalCommands={globalCommands} ref={ref} />)}
      </GlobalCommandsContext.Consumer>
    )});
  Wrapper.displayName = `withGlobalCommands(${Component.displayName || Component.name})`;
  hoistNonReactStatics(Wrapper, Component);
  return Wrapper;
}
