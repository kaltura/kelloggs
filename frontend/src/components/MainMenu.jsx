import React from 'react';
import { withStyles } from '@material-ui/core/styles';
import Badge from '@material-ui/core/Badge';
import {withGlobalCommands} from "./GlobalCommands";
import { compose } from 'recompose'
import CommandsMenu from "./CommandsMenu";

const styles = {
  menuIcon: {
    color: 'white'
  }
}

const ITEM_HEIGHT = 48;


class MainMenu extends React.Component {

  state = {
  };

  handleClick = event => {
    this.setState({ anchorEl: event.currentTarget });
  };

  _copySearchLink = () => {
    this._handleMenuCommand({
      action: 'copyToClipboard',
      data: this.props.globalCommands.getCurrentUrl()
    })
  }

  _handleCloseMenu = () => {
    this.setState({ anchorEl: null });
  }

  _handleMenuCommand = (command) => {
    this.setState({ anchorEl: null });
    this.props.globalCommands.handleCommand(command);
  };

  render() {
    const { classes, globalCommands } = this.props;
    const commands = [
      { action: 'copyToClipboard', label: 'ss', data: this.props.globalCommands.getCurrentUrl()},
      ...globalCommands.items
    ]

    const hasCommands = commands && commands.length;
    return (
      <div>
        <Badge color="secondary" badgeContent={commands.length} invisible={!hasCommands}>
          <CommandsMenu commands={commands} className={classes.menuIcon}/>
        </Badge>
      </div>
    );
  }
}
export default compose(
  withStyles(styles),
  withGlobalCommands
)(MainMenu);

