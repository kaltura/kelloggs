import * as React from "react";
import Menu from '@material-ui/core/Menu';
import MenuItem from '@material-ui/core/MenuItem';
import Button from '@material-ui/core/Button';

export default class RichTextView extends React.PureComponent {
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

    render() {
        const { anchorEl } = this.state;

        return <span style={{...this.props.style}}>
            {
                this.props.data.map(data => {
                    if (data.commands) {
                        return  <React.Fragment>
                                    <a href="#"
                                        onClick={this.handleClick}
                                    >
                                        {data.text}
                                    </a>
                                    <Menu
                                        id="simple-menu"
                                        anchorEl={anchorEl}
                                        open={Boolean(anchorEl)}
                                        onClose={this.handleClose}
                                        >
                                    {
                                            data.commands.map(cmd => {
                                            return <MenuItem onClick={ ()=> { this.handleClose(); alert(cmd.data)}}>{cmd.label}</MenuItem>
                                        })
                                    }
                                    </Menu>
                        </React.Fragment>
                    }
                    return data.text;
                })
            }
        </span>
    }

}