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

  _handleCommand = (command) => {


    // TODO implement various types
    switch(command.action) {
      case "copyToClipboard":
        this._copyToClipboard(command.data);
        break;
      case "search":
        break;
      case "download":
      case "link":
        window.open(command.data, '_blank');
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
      handleCommand: this._handleCommand,
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
        <Dialog

          onClose={this._handleViewerClose}
          open={this.state.viewerCommand}
        >
          <DialogTitle id="customized-dialog-title" onClose={this._handleViewerClose}>
            { this.state.viewerCommand && this.state.viewerCommand.label}
          </DialogTitle>
          <DialogContent>
            <textarea style={{width: '400px', height: '400px'}} readonly>
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
