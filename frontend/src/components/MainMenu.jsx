import React from 'react';
import { withStyles } from '@material-ui/core/styles';
import IconButton from '@material-ui/core/IconButton';
import Menu from '@material-ui/core/Menu';
import MenuItem from '@material-ui/core/MenuItem';
import Badge from '@material-ui/core/Badge';
import MoreVertIcon from '@material-ui/icons/MoreVert';
import {withGlobalCommands} from "./GlobalCommands";
import { compose } from 'recompose'


const styles = {
  root: { padding: '0 4px'},
}



const ITEM_HEIGHT = 48;


class MainMenu extends React.Component {

  state = {
    anchorEl: null
  };

  handleClick = event => {
    this.setState({ anchorEl: event.currentTarget });
  };

  _copySearchLink = () => {
    this._handleCommand({
      action: 'copyToClipboard',
      data: this.props.globalCommands.getCurrentUrl()
    })
  }

  _handleCommand = (command) => {
    this.setState({ anchorEl: null });

    // TODO implement various types
    switch(command.action) {
      case "copyToClipboard":
        this.props.globalCommands.copyToClipboard(command.data);

        break;
      default:
        break;
    }
  };



  render() {
    const { anchorEl } = this.state;
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
      </div>
    );
  }
}
export default compose(
  withStyles(styles),
  withGlobalCommands
)(MainMenu);

