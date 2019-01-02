import React from "react";
import hoistNonReactStatics from 'hoist-non-react-statics';

const GlobalCommandsContext = React.createContext({});

function setParams({ query = ""}) {
  const searchParams = new URLSearchParams();
  searchParams.set("query", query);
  return searchParams.toString();
}

const updateURL = (queryParams) => {
  debugger;
  const url = setParams(queryParams);
  window.history.push(`?${url}`);
};

const getSearchParams = () => {
  var match,
    pl = /\+/g,  // Regex for replacing addition symbol with a space
    search = /([^&=]+)=?([^&]*)/g,
    decode = function (s) {
      return decodeURIComponent(s.replace(pl, " "));
    },
    query = window.location.search.substring(1);

  const urlParams = {};
  while (match = search.exec(query))
    urlParams[decode(match[1])] = decode(match[2]);

  return urlParams;
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

  _setConfig = (config, initialParams = null) => {
    this.setState({
      config,
      initialParams
    })
  }

  render() {
    const { children } = this.props;
    const { items, config, initalParameters } = this.state;

    const context = {
      items,
      updateItems: this.updateItems,
      clearItems: () => this.updateItems([]),
      updateURL: updateURL,
      getSearchParams: getSearchParams,
      setConfig: this._setConfig,
      getInitialParameters: () => initalParameters,
      config
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
