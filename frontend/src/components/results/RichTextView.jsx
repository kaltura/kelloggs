import * as React from "react";
import Menu from '@material-ui/core/Menu';
import MenuItem from '@material-ui/core/MenuItem';
import ToolTip from '@material-ui/core/Tooltip'
import PropTypes from 'prop-types';
import { withStyles } from '@material-ui/core/styles';
import {compose} from "recompose";
import {withGlobalCommands} from "../GlobalCommands";

const styles = theme => ({

    lightTooltip: {
        fontSize: 11,
        userSelect: "text",
        whiteSpace: "pre",
        width: "auto",
        maxWidth: "800px",
        height: "auto"
    }
});

class RichTextView extends React.PureComponent {
    state = {
        anchorEl: null,
    };

    constructor(props) {
        super(props);
    }

    handleClick = event => {
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
        const { classes } = this.props;

        return <div  style={{...this.props.style, paddingLeft: this.props.indent*35+"px"}}>
            {
                this.props.data.map(data => {
                    if (data.commands) {

                        let toolTipAction=data.commands.find(cmd=>cmd.action==="tooltip");
                        //let otherActions=data.commands.filter(cmd=>cmd.action!=="tooltip")
                        return  <React.Fragment>
                                    {
                                        <ToolTip  title={toolTipAction.data}
                                                  classes={{ tooltip: classes.lightTooltip }}
                                        >
                                            <a href="#"
                                               onClick={this.handleClick}
                                            >
                                                {data.text}
                                            </a>
                                        </ToolTip>
                                    }


                                    <Menu
                                        id="simple-menu"
                                        anchorEl={anchorEl}
                                        open={Boolean(anchorEl)}
                                        onClose={this.handleClose}
                                        >
                                    {
                                        data.commands.map(cmd => {
                                            return <MenuItem onClick={ ()=> { this.clickCommand(cmd)}}>{cmd.label}</MenuItem>
                                        })
                                    }
                                    </Menu>
                        </React.Fragment>
                    }
                    return data.text;
                })
            }
        </div>
    }

}


RichTextView.propTypes = {
    classes: PropTypes.object.isRequired,
};

export default compose(
  withStyles(styles),
  withGlobalCommands
)(RichTextView);


