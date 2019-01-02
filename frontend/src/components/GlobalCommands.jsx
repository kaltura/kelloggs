import React from "react";
import hoistNonReactStatics from 'hoist-non-react-statics';
import SnackbarContent from '@material-ui/core/SnackbarContent';
import Snackbar from '@material-ui/core/Snackbar';
import {withStyles} from "@material-ui/core";
import classNames from "classnames";
import CheckCircleIcon from "@material-ui/core/SvgIcon/SvgIcon";
import IconButton from "@material-ui/core/IconButton/IconButton";
import CloseIcon from '@material-ui/icons/Close';
import { getCurrentUrl, getQueryString, copyToClipboardEnabled, copyToClipboard, replaceUrlQueryParameters } from '../utils';

const SnackbarContentStyles = {
  root: { padding: '0 4px'},
  success: {
    backgroundColor: '#43a047',
  },
  message: {
    display: 'flex',
    alignItems: 'center',
  },
  icon: {
    fontSize: 20,
  },
  iconVariant: {
    opacity: 0.9,
    marginRight: '10px',
  },
}

const CustomSnackbarContent = withStyles(SnackbarContentStyles)(function(props) {
  const { classes, className, message, onClose, ...other } = props;

  return (
    <SnackbarContent
      className={classNames(classes.success, className)}
      aria-describedby="client-snackbar"
      message={
        <span id="client-snackbar" className={classes.message}>
          <CheckCircleIcon className={classNames(classes.icon, classes.iconVariant)} />
          {message}
        </span>
      }
      action={[
        <IconButton
          key="close"
          aria-label="Close"
          color="inherit"
          className={classes.close}
          onClick={onClose}
        >
          <CloseIcon className={classes.icon} />
        </IconButton>,
      ]}
      {...other}
    />
  );
});


const GlobalCommandsContext = React.createContext({});

export default class GlobalCommands extends React.Component {

  state = {
    items: [],
    showCopiedToClipboard: false
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

  _copyToClipboard = (text) => {
    if (!copyToClipboardEnabled()) {
      return;
    }

    const result = copyToClipboard(text);

    this.setState({
      showCopiedToClipboard: result
    });
  }
  render() {
    const { children } = this.props;
    const { items, config, initialParameters, showCopiedToClipboard } = this.state;

    const context = {
      items,
      updateItems: this.updateItems,
      clearItems: () => this.updateItems([]),
      updateURL: this._updateURL,
      getQueryString: getQueryString,
      setConfig: this._setConfig,
      copyToClipboard: this._copyToClipboard,
      getInitialParameters: () => initialParameters,
      config,
      getCurrentUrl
    }

    return (
      <GlobalCommandsContext.Provider value={context}>
        {children}
        <Snackbar
          anchorOrigin={{
            vertical: 'bottom',
            horizontal: 'left',
          }}
          open={showCopiedToClipboard}
          autoHideDuration={4000}
          onClose={() => this.setState({ showCopiedToClipboard: false})}
        >
          <CustomSnackbarContent
            onClose={() => this.setState({ showCopiedToClipboard: false})}
            message="Copied to clipbard"
          />
        </Snackbar>
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
