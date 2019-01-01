import React from 'react';
import { withStyles } from '@material-ui/core/styles';
import IconButton from '@material-ui/core/IconButton';
import Menu from '@material-ui/core/Menu';
import MenuItem from '@material-ui/core/MenuItem';
import Badge from '@material-ui/core/Badge';
import SnackbarContent from '@material-ui/core/SnackbarContent';
import Snackbar from '@material-ui/core/Snackbar';
import MoreVertIcon from '@material-ui/icons/MoreVert';
import CheckCircleIcon from '@material-ui/icons/CheckCircle';
import CloseIcon from '@material-ui/icons/Close';
import {withGlobalCommands} from "./GlobalCommands";
import { compose } from 'recompose'
import classNames from 'classnames';

// TODO use the function from KMC
function setClipboardText(text) {
  var id = "mycustom-clipboard-textarea-hidden-id";
  var existsTextarea = document.getElementById(id);

  if (!existsTextarea) {
    var textarea = document.createElement("textarea");
    textarea.id = id;
    // Place in top-left corner of screen regardless of scroll position.
    textarea.style.position = 'fixed';
    textarea.style.top = 0;
    textarea.style.left = 0;

    // Ensure it has a small width and height. Setting to 1px / 1em
    // doesn't work as this gives a negative w/h on some browsers.
    textarea.style.width = '1px';
    textarea.style.height = '1px';

    // We don't need padding, reducing the size if it does flash render.
    textarea.style.padding = 0;

    // Clean up any borders.
    textarea.style.border = 'none';
    textarea.style.outline = 'none';
    textarea.style.boxShadow = 'none';

    // Avoid flash of white box if rendered for any reason.
    textarea.style.background = 'transparent';
    document.querySelector("body").appendChild(textarea);
    existsTextarea = document.getElementById(id);
  }

  existsTextarea.value = text;
  existsTextarea.select();

  try {
    var status = document.execCommand('copy');

    if (!status) {
      throw new Error(`cannot copy with status ${status}`);
    }
  } catch (err) {
    throw err;
  }
}

const styles = {
  root: { padding: '0 4px'},
}

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

const ITEM_HEIGHT = 48;

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

class MainMenu extends React.Component {


  state = {
    anchorEl: null,
    showCopiedToClipboard: false
  };

  handleClick = event => {
    this.setState({ anchorEl: event.currentTarget });
  };

  _copySearchLink = () => {
    // TODO implement
    this.setState({ anchorEl: null });
    this.setState({ showCopiedToClipboard: true});

  }

  _handleCommand = (command) => {
    this.setState({ anchorEl: null });

    // TODO implement various types
    switch(command.action) {
      case "copyToClipboard":
        try {
          setTimeout(() => {
            setClipboardText(command.data);
            this.setState({ showCopiedToClipboard: true});
          });
        } catch (e) {
          // TODO show message
        }
        break;
      default:
        break;
    }
  };



  render() {
    const { anchorEl, showCopiedToClipboard } = this.state;
    const { classes, globalCommands } = this.props;
    const open = Boolean(anchorEl);
    const commands = globalCommands.items;
    const hasCommands = commands && commands.length;
    return (
      <div>
        <Badge color="secondary" badgeContent={commands.length} invisible={!hasCommands}>
        <IconButton
          classes={{root: classes.root}}
          aria-label="More"
          aria-owns={open ? 'long-menu' : undefined}
          aria-haspopup="true"
          onClick={this.handleClick}
        >
          <MoreVertIcon />
        </IconButton>
        </Badge>
        <Menu
          id="long-menu"
          anchorEl={anchorEl}
          open={open}
          onClose={this.handleClose}
          PaperProps={{
            style: {
              maxHeight: ITEM_HEIGHT * 4.5,
              width: 200,
            },
          }}
        >
          <MenuItem onClick={this._copySearchLink}>
              Copy Search Link
          </MenuItem>
          {commands.map((command, index) => (
            <MenuItem key={index} onClick={() => this._handleCommand(command)}>
              {command.label}
            </MenuItem>
          ))}
        </Menu>
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
      </div>
    );
  }
}
export default compose(
  withStyles(styles),
  withGlobalCommands
)(MainMenu);

