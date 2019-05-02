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
  replaceCurrentUrl,
  buildSearchParamsHash,
  buildQuerystring,
  getCurrentHash,
  getPageUrl, getPageUrlWithoutQuerystring, getSearchParamsFromHash
} from '../utils';
import Dialog from '@material-ui/core/Dialog';
import MuiDialogTitle from '@material-ui/core/DialogTitle';
import MuiDialogContent from '@material-ui/core/DialogContent';
import MuiDialogActions from '@material-ui/core/DialogActions';
import Typography from '@material-ui/core/Typography';
import Button from '@material-ui/core/Button';
import moment from 'moment-timezone';


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

const linuxRegexp = new RegExp('^\\w\\w\\w\\s+?\\w\\w\\w\\s+?\\d\\d?\\s+?\\d{2}:\\d{2}:\\d{2}\\s+?EST\\s+?\\d{4}$', 'i');
const GlobalCommandsContext = React.createContext({});

const displayDateFromat = 'YYYY-MM-DD HH:mm';
const displayDateFromatWithSeconds = 'YYYY-MM-DD HH:mm:ss';

export default class GlobalCommands extends React.Component {

  state = {
    items: [],
    timezone: 'America/New_York',
    showCopiedToClipboard: false,
    viewerCommand: null,
    initialParameters: getSearchParamsFromHash()
  }

  _changeTimezone = (value) => {
    this.setState({
      timezone: value
    })
  }

  _toAppDate = (value) => {
    const { timezone } = this.state;

    var dateRegex = /^\d+$/;
    if (dateRegex.test(value)) {
      return moment(value * 1000).tz(timezone);
    }

    if (moment.isMoment(value)) {
      return value.tz(timezone);
    }

    // special handling for unix date format Wed Feb  6 07:40:57 EST 2019
    let unixDate = null;
    if (linuxRegexp.test(value)) {
      let strippedValue = value.replace(' EST','').replace(/\s+/g,' ');
      unixDate = moment.tz(strippedValue, ['ddd MMM D HH:mm:ss YYYY'], true, 'America/New_York');
    }

    return unixDate && unixDate.isValid() ? unixDate : moment.tz(value, timezone);
  }

  _toStringDate = (value, withSeconds = false) => {
    const dateFormat = withSeconds ? displayDateFromatWithSeconds : displayDateFromat;
    return this._toAppDate(value).format(dateFormat);
  }

  _toUnixDate = (date) => {
    const {timezone} = this.state;
    const parsedDate = moment.tz(date, timezone);
    return parsedDate.isValid() ? parsedDate.format('X') : ""
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

  _buildSearchUrl = (searchParams) => {
    const queryString = this._createAppQueryString();
    const searchParamsHash = buildSearchParamsHash(searchParams);
    const host = this.state.config.hostUrl || getPageUrlWithoutQuerystring();
    return `${host}?${queryString}#${searchParamsHash}`;
  }

  _createAppQueryString = () => {
    const { config } = this.state;

    let queryParams = {};

    if (!config.isHosted) {
      queryParams = {
        ...queryParams,
        jwt: config.jwt,
        serviceUrl: config.serviceUrl
      }
    }

    return buildQuerystring(queryParams);
  }

  _updateURL = (searchParams) => {
    const queryString = this._createAppQueryString();
    replaceCurrentUrl(queryString, searchParams);
  };


  _setConfig = (config) => {
    this.setState({
      config
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
    const queryParams = getQueryString();
    const jwt = queryParams['jwt'];
    const hostUrl = queryParams['hostUrl'];
    const serviceUrl = queryParams['serviceUrl'];
    delete queryParams['serviceUrl'];
    delete queryParams['jwt'];
    delete queryParams['hostUrl'];

    return {
      jwt,
      hostUrl,
      serviceUrl
    }
  }


  _getCurrentUrl = () => {
    if (this.state.config.hostUrl) {
      const hostUrl = this.state.config.hostUrl;
      const queryString = this._createAppQueryString();
      const searchParamsHash = getCurrentHash();
      return `${hostUrl}${queryString ? `?${queryString}` : ''}${searchParamsHash ? `#${searchParamsHash}` : ''}`;
    }else {
      return getPageUrl();
    }
  }

  render() {
    const { children } = this.props;
    const { items, config, initialParameters, showCopiedToClipboard, timezone } = this.state;

    const context = {
      timezone,
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
      removeOnSearchParamsChanged: this._removeOnSearchParamsChanged,
      changeTimezone: this._changeTimezone,
      toStringDate: this._toStringDate,
      toUnixDate: this._toUnixDate,
      toAppDate: this._toAppDate,
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
          open={!!this.state.viewerCommand}
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
