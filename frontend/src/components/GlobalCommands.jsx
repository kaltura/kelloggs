import React from "react";
import hoistNonReactStatics from 'hoist-non-react-statics';
import SnackbarContent from '@material-ui/core/SnackbarContent';
import Snackbar from '@material-ui/core/Snackbar';
import {withStyles} from "@material-ui/core";
import classNames from "classnames";
import CheckCircleIcon from "@material-ui/core/SvgIcon/SvgIcon";
import IconButton from "@material-ui/core/IconButton/IconButton";
import CloseIcon from '@material-ui/icons/Close';
import {
  getQueryString,
  copyToClipboardEnabled,
  openUrlInNewTab,
  copyToClipboard,
  updateUrlQueryParams,
  buildQuerystring,
  getPageUrl, getPageUrlWithoutQuerystring
} from '../utils';
import Dialog from '@material-ui/core/Dialog';
import MuiDialogTitle from '@material-ui/core/DialogTitle';
import MuiDialogContent from '@material-ui/core/DialogContent';
import MuiDialogActions from '@material-ui/core/DialogActions';
import Typography from '@material-ui/core/Typography';
import Button from '@material-ui/core/Button';


const DialogTitle = withStyles(theme => ({
  root: {
    borderBottom: `1px solid ${theme.palette.divider}`,
    margin: 0,
    padding: theme.spacing.unit * 2,
  },
  closeButton: {
    position: 'absolute',
    right: theme.spacing.unit,
    top: theme.spacing.unit,
    color: theme.palette.grey[500],
  },
}))(props => {
  const { children, classes, onClose } = props;
  return (
    <MuiDialogTitle disableTypography className={classes.root}>
      <Typography variant="h6">{children}</Typography>
      {onClose ? (
        <IconButton aria-label="Close" className={classes.closeButton} onClick={onClose}>
          <CloseIcon />
        </IconButton>
      ) : null}
    </MuiDialogTitle>
  );
});

const DialogContent = withStyles(theme => ({
  root: {
    margin: 0,
    padding: theme.spacing.unit * 2,
  },
}))(MuiDialogContent);

const DialogActions = withStyles(theme => ({
  root: {
    borderTop: `1px solid ${theme.palette.divider}`,
    margin: 0,
    padding: theme.spacing.unit,
  },
}))(MuiDialogActions);


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
    showCopiedToClipboard: false,
    viewerCommand: null,
  }

  updateItems = (items) => {
    this.setState({
      items
    })
  }

  _handleViewerClose = () => {
    this.setState({
      viewerCommand: null
    })
  }

  _buildSearchUrl = (queryParams) => {
    queryParams = this._addAppParamsToQueryString(queryParams);
    const queryParamsToken = buildQuerystring(queryParams);
    const host = this.state.config.hostUrl || getPageUrlWithoutQuerystring();
    return `${host}?${queryParamsToken}`;
  }

  _addAppParamsToQueryString = (queryParams) => {
    const { config } = this.state;

    if (!config.isHosted) {
      queryParams = {
        ...queryParams,
        jwt: config.jwt,
        serviceUrl: config.serviceUrl
      }
    }

    return queryParams;
  }

  _updateURL = (queryParams) => {
    queryParams = this._addAppParamsToQueryString(queryParams);
    updateUrlQueryParams(queryParams);
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

  _handleCommand = (command) => {
    let { onSearchParamsChanged } = this.state;

    switch(command.action) {
      case "copyToClipboard":
        this._copyToClipboard(command.data);
        break;
      case "search":
        if (onSearchParamsChanged) {
          onSearchParamsChanged(command.data);
        }
        break;
      case "searchNewTab":
        const newUrl = this._buildSearchUrl(command.data);
        openUrlInNewTab(newUrl);
        break;
      case "download":
      case "link":
        openUrlInNewTab(command.data);
        break;
      case "tooltip":
          this.setState({
            viewerCommand: command
          });
        break;
      default:
        break;
    }
  };

  _addOnSearchParamsChanged = (cb) => {
    this.setState({
      onSearchParamsChanged: cb
    })
  }

  _removeOnSearchParamsChanged = () => {
    this.setState({
      onSearchParamsChanged: null
    })
  }

  _extractQueryString = () => {
    const searchParams = getQueryString();
    const jwt = searchParams['jwt'];
    const hostUrl = searchParams['hostUrl'];
    const serviceUrl = searchParams['serviceUrl'];
    delete searchParams['serviceUrl'];
    delete searchParams['jwt'];
    delete searchParams['hostUrl'];

    return {
      jwt,
      hostUrl,
      serviceUrl,
      searchParams,
    }
  }

  _getCurrentUrl = () => {
    if (this.state.config.hostUrl) {
      const hostUrl = this.state.config.hostUrl;
      const params = buildQuerystring(getQueryString());
      return `${hostUrl}?${params}`;
    }else {
      return getPageUrl();
    }
  }

  render() {
    const { children } = this.props;
    const { items, config, initialParameters, showCopiedToClipboard } = this.state;

    const context = {
      items,
      updateItems: this.updateItems,
      clearItems: () => this.updateItems([]),
      updateURL: this._updateURL,
      extractQueryString: this._extractQueryString,
      setConfig: this._setConfig,
      copyToClipboard: this._copyToClipboard,
      getInitialParameters: () => initialParameters,
      config,
      handleCommand: this._handleCommand,
      getCurrentUrl: this._getCurrentUrl,
      addOnSearchParamsChanged: this._addOnSearchParamsChanged,
      removeOnSearchParamsChanged: this._removeOnSearchParamsChanged
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
            message="Copied to clipboard"
          />
        </Snackbar>
        <Dialog

          onClose={this._handleViewerClose}
          open={this.state.viewerCommand}
        >
          <DialogTitle id="customized-dialog-title" onClose={this._handleViewerClose}>
            { this.state.viewerCommand && this.state.viewerCommand.label}
          </DialogTitle>
          <DialogContent>
            <textarea style={{ overflow: 'auto', whiteSpace: 'nowrap', fontFamily: 'lucida console', width: '500px', height: '200px'}} readonly>
              {this.state.viewerCommand && this.state.viewerCommand.data}
            </textarea>
          </DialogContent>
          <DialogActions>
            <Button onClick={() => this._copyToClipboard(this.state.viewerCommand.data)} color="primary">
              Copy to clipboard
            </Button>
          </DialogActions>
        </Dialog>
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
