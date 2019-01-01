import React from "react";
import hoistNonReactStatics from 'hoist-non-react-statics';

const GlobalCommandsContext = React.createContext({});

export default class GlobalCommands extends React.Component {

  state = {
    items: [],
  }

  updateItems = (items) => {
    this.setState({
      items
    })
  }


  render() {
    const { children } = this.props;
    const { items } = this.state;

    const context = {
      items,
      updateItems: this.updateItems,
      clearItems: () => this.updateItems([])
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
