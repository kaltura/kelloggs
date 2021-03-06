import * as React from "react";
import Menu from '@material-ui/core/Menu';
import MenuItem from '@material-ui/core/MenuItem';
import ToolTip from '@material-ui/core/Tooltip'
import PropTypes from 'prop-types';
import { withStyles } from '@material-ui/core/styles';
import {compose} from "recompose";
import {withGlobalCommands} from "../GlobalCommands";

const styles = theme => ({
  link: {
    color: 'blue',
    textDecoration: 'underline',
    cursor: 'pointer',
  },
  lightTooltip: {
    fontSize: 11,
    userSelect: "text",
    whiteSpace: "pre",
    width: "auto",
    maxWidth: "800px",
    height: "auto",
    fontFamily: 'lucida console'
  }
});


class RichTextCommandMenu extends React.Component {
  state = {
    anchorEl: null
  };

  handleChange = (event, checked) => {
    this.setState({ auth: checked });
  };

  handleMenu = event => {
    this.setState({ anchorEl: event.currentTarget });
  };

  handleClose = () => {
    this.setState({ anchorEl: null });
  };
  clickCommand(cmd) {
    this.handleClose()
    this.props.globalCommands.handleCommand(cmd);
  }

  render() {

    const { anchorEl } = this.state;
    const { classes, data, dataIndex } = this.props;

    let toolTipAction=data.commands.find(cmd=>cmd.action==="tooltip");
    //let otherActions=data.commands.filter(cmd=>cmd.action!=="tooltip")
    return  <React.Fragment key={"fragment"+dataIndex}>
      {
        toolTipAction ? <ToolTip  title={toolTipAction.data}
                                  classes={{ tooltip: classes.lightTooltip }}
          >
            <span className={classes.link} onClick={this.handleMenu}>{data.text}</span>
          </ToolTip> :
          <span className={classes.link} onClick={this.handleMenu}>{data.text}</span>
      }

      <Menu
        key={"menu"+(dataIndex)}
        anchorEl={anchorEl}
        open={Boolean(anchorEl)}
        onClose={this.handleClose}
      >
        {
          data.commands.map( (cmd,cmdIndex) => {
            return <MenuItem key={`menuitem-${dataIndex}-${cmdIndex}`} onClick={ ()=> {
              this.clickCommand(cmd)
            }}>{cmd.label}</MenuItem>
          })
        }
      </Menu>
    </React.Fragment>
  }

}


RichTextCommandMenu.propTypes = {
  classes: PropTypes.object.isRequired,
};

var RichTextCommandMenuWithStyle=compose(
  withStyles(styles),
  withGlobalCommands)(RichTextCommandMenu);

function RichTextView({globalCommands, style, indent, data}) {
    return <div  style={{...style, paddingLeft: indent*35+"px"}}>
        {
            data.map((data,dataIndex) => {
                if (data.commands) {
                    return <RichTextCommandMenuWithStyle key={"commandMenu-" + dataIndex} data={data} dataIndex={dataIndex} globalCommands={globalCommands}/>;
                }
                return data.text;
            })
        }
    </div>
}

RichTextView.propTypes = {
  classes: PropTypes.object.isRequired,
};

export default compose(
  withStyles(styles),
  withGlobalCommands
)(RichTextView);


