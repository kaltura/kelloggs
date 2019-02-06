import React from 'react';
import { withStyles } from '@material-ui/core/styles';
import IconButton from '@material-ui/core/IconButton';
import Menu from '@material-ui/core/Menu';
import MenuItem from '@material-ui/core/MenuItem';
import MoreVertIcon from '@material-ui/icons/MoreVert';
import { compose } from 'recompose'
import {withGlobalCommands} from "./GlobalCommands";


const styles = {
  root: { padding: '0 4px'},
  menu: {
    color: 'blue',
    fontSize: '0.6rem',
    cursor: 'pointer',
    margin: '0 0 0 4px'
  }
}

const ITEM_HEIGHT = 48;

class CommandsMenu extends React.Component {

  state = {
    anchorEl: null,
    visibleCommands: null
  };

  openMenu = event => {
    const { commands } = this.props;

    this.setState({
      anchorEl: event.currentTarget,
      visibleCommands:commands
    });
  };

  _handleCloseMenu = () => {
    this.setState({ anchorEl: null, visibleCommands: null });
  }

  _handleMenuCommand = (command) => {
    this.setState({ anchorEl: null });
    this.props.globalCommands.handleCommand(command);
  };

  render() {
    const { anchorEl, visibleCommands } = this.state;
    const { classes, commands, className : classNameProp, type } = this.props;
    const open = Boolean(anchorEl);
    const hasCommands = commands && commands.length;

    return hasCommands ?
      (<div >
        { type === 'text' ?
            <div className={classes.menu} onClick={this.openMenu}>({commands.length})</div>
           :
            <IconButton
              className={classNameProp}
              classes={{root: classes.root}}
              aria-label="More"
              aria-owns={open ? 'long-menu' : undefined}
              aria-haspopup="true"
              onClick={this.openMenu}
            >
              <MoreVertIcon/>
            </IconButton>
           }
        <Menu
          id="long-menu"
          anchorEl={anchorEl}
          open={open}
          onClose={this._handleCloseMenu}
          PaperProps={{
            style: {
              maxHeight: ITEM_HEIGHT * 4.5,
              width: 200,
            },
          }}
        >
          {visibleCommands && visibleCommands.map((command, index) => (
            <MenuItem key={index} onClick={() => this._handleMenuCommand(command)}>
              {command.label}
            </MenuItem>
          ))}
        </Menu>
      </div>)
      : null
      ;
  }
}

export default compose(
  withStyles(styles),
  withGlobalCommands
)(CommandsMenu);

